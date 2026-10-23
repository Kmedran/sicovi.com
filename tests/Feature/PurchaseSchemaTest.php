<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DecimalService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * YOURGUEN-DB-001 y DB-001B: restricciones, índices y triggers de proveedores,
 * compras, detalle e idempotencia. Se prueba contra PostgreSQL (sicovi_test).
 */
class PurchaseSchemaTest extends DatabaseTestCase
{
    private const UNIQUE = '23505';

    private const CHECK = '23514';

    private const NOT_NULL = '23502';

    private User $author;

    private User $reviewer;

    private int $warehouseId;

    private int $unitId;

    private int $productA;

    private int $productB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->actor('admin', 'author');
        $this->reviewer = $this->actor('purchasing', 'reviewer');
        $this->warehouseId = (int) DB::table('warehouses')->value('id');
        $this->unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $categoryId = DB::table('categories')->insertGetId(['code' => 'T-DB001', 'name' => 'Categoría de pruebas DB001']);
        $this->productA = DB::table('products')->insertGetId(['sku' => 'T-DB001-A', 'name' => 'Producto A', 'category_id' => $categoryId, 'unit_id' => $this->unitId]);
        $this->productB = DB::table('products')->insertGetId(['sku' => 'T-DB001-B', 'name' => 'Producto B', 'category_id' => $categoryId, 'unit_id' => $this->unitId]);
    }

    public function test_suppliers_get_a_code_and_require_a_unique_normalized_tax_id(): void
    {
        $id = $this->supplier('Proveedor 1', '123-4');
        $this->assertMatchesRegularExpression('/\APRV-\d{6}\z/', (string) DB::table('suppliers')->where('id', $id)->value('code'));

        $this->assertRejected(self::UNIQUE, fn () => $this->supplier('Otro', ' 1234 '), 'NIT duplicado tras normalizar');
        $this->assertRejected(self::NOT_NULL, fn () => DB::table('suppliers')->insert(['name' => 'Sin NIT', 'tax_id' => null]), 'NIT nulo');
        $this->assertRejected(self::CHECK, fn () => $this->supplier('Solo guiones', ' - - '), 'NIT solo guiones o espacios');
        $this->assertRejected(self::CHECK, fn () => $this->supplier('  ', '555-5'), 'nombre vacío');
    }

    public function test_purchases_get_a_code_and_start_as_draft(): void
    {
        $purchase = DB::table('purchases')->where('id', $this->purchase($this->supplier('P', '1-1')))->first();

        $this->assertMatchesRegularExpression('/\ACOM-\d{6}\z/', $purchase->code);
        $this->assertSame('draft', $purchase->state);
        $this->assertSame(0, (int) $purchase->version);
        $this->assertSame('0.00', $purchase->total);
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase->id)->update(['state' => 'otro']), 'estado inválido');
    }

    public function test_supplier_reference_is_unique_per_supplier_even_after_the_purchase_is_voided(): void
    {
        $first = $this->supplier('Proveedor 1', '123-4');
        $second = $this->supplier('Proveedor 2', '999-9');
        $purchase = $this->purchase($first, 'F-001');

        $this->assertRejected(self::UNIQUE, fn () => $this->purchase($first, 'f-001'), 'referencia repetida del mismo proveedor');
        $this->purchase($second, 'F-001'); // otro proveedor: permitido
        $this->purchase($first); // sin referencia: varias permitidas
        $this->purchase($first);

        $this->addLine($purchase, $this->productA, '1.000', '10.00');
        $this->confirm($purchase);
        $this->void($purchase);

        $this->assertRejected(self::UNIQUE, fn () => $this->purchase($first, 'F-001'), 'la referencia sigue ocupada tras anular');
    }

    public function test_purchase_items_enforce_amounts_quantities_and_one_line_per_product(): void
    {
        $purchase = $this->purchase($this->supplier('P', '1-1'));

        $this->addLine($purchase, $this->productA, '0.001', '5.00'); // 0.025 -> 0.01 (half-up)
        $this->assertSame('0.01', DB::table('purchase_items')->where('purchase_id', $purchase)->where('product_id', $this->productA)->value('line_total'));
        $this->addLine($purchase, $this->productB, '1.250', '10.00');
        $this->assertSame('12.50', DB::table('purchase_items')->where('purchase_id', $purchase)->where('product_id', $this->productB)->value('line_total'));

        $this->assertRejected(self::UNIQUE, fn () => $this->rawLine($purchase, $this->productA, '1', '1.00', '1.00'), 'producto repetido en la compra');
        $this->assertRejected(self::CHECK, fn () => $this->rawLine($this->purchase($this->supplier('Q', '2-2')), $this->productA, '2', '10.00', '19.00'), 'importe de línea incorrecto');
        $other = $this->purchase($this->supplier('R', '3-3'));
        $this->assertRejected(self::CHECK, fn () => $this->rawLine($other, $this->productA, '0', '1.00', '0.00'), 'cantidad cero');
        $this->assertRejected(self::CHECK, fn () => $this->rawLine($other, $this->productA, '1', '-1.00', '-1.00'), 'costo negativo');
    }

    public function test_confirmation_requires_complete_data_and_a_unique_idempotency_key(): void
    {
        $supplier = $this->supplier('P', '1-1');
        $first = $this->purchase($supplier);
        $second = $this->purchase($supplier);
        $this->addLine($first, $this->productA, '1.000', '10.00');

        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $first)->update(['state' => 'confirmed']), 'confirmada sin responsable ni fecha');
        $this->confirm($first, 'k-1');
        $this->assertSame('confirmed', DB::table('purchases')->where('id', $first)->value('state'));
        $this->assertRejected(self::UNIQUE, fn () => $this->confirm($second, 'k-1'), 'misma clave de confirmación del mismo usuario');
    }

    public function test_a_confirmed_purchase_is_immutable(): void
    {
        $supplier = $this->supplier('P', '1-1');
        $other = $this->supplier('Q', '2-2');
        $purchase = $this->purchase($supplier);
        $this->addLine($purchase, $this->productA, '1.000', '10.00');
        $this->addLine($purchase, $this->productB, '1.250', '10.00');
        $this->confirm($purchase);

        $this->assertRejected(self::CHECK, fn () => DB::table('purchase_items')->where('purchase_id', $purchase)->where('product_id', $this->productB)->update(['quantity' => '2.000', 'line_total' => '20.00']), 'editar línea confirmada');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchase_items')->where('purchase_id', $purchase)->delete(), 'borrar líneas confirmadas');
        $this->assertRejected(self::CHECK, fn () => $this->rawLine($purchase, $this->productA, '1', '1.00', '1.00'), 'agregar línea a compra confirmada');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['total' => '1.00']), 'cambiar total');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['supplier_id' => $other]), 'cambiar proveedor');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['state' => 'draft', 'confirmed_by' => null, 'confirmed_at' => null]), 'volver a borrador');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->delete(), 'borrar compra confirmada');
    }

    public function test_void_requires_a_confirmed_purchase_and_a_reason_and_is_final(): void
    {
        $supplier = $this->supplier('P', '1-1');
        $purchase = $this->purchase($supplier);
        $this->addLine($purchase, $this->productA, '1.000', '10.00');
        $this->confirm($purchase);

        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['state' => 'voided', 'voided_by' => $this->reviewer->id, 'voided_at' => now()]), 'anular sin motivo');
        $this->void($purchase);
        $this->assertSame('voided', DB::table('purchases')->where('id', $purchase)->value('state'));
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['state' => 'confirmed', 'voided_by' => null, 'voided_at' => null, 'void_reason' => null]), 'reconfirmar una compra anulada');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $purchase)->update(['void_reason' => 'otro']), 'cambiar el motivo de anulación');
    }

    public function test_a_purchase_must_follow_draft_confirmed_voided(): void
    {
        $supplier = $this->supplier('P', '1-1');
        $draft = $this->purchase($supplier);

        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->where('id', $draft)->update([
            'state' => 'voided', 'confirmed_by' => $this->author->id, 'confirmed_at' => now(),
            'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'Intento directo',
        ]), 'borrador pasa directo a anulada');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->insert([
            'supplier_id' => $supplier, 'warehouse_id' => $this->warehouseId, 'document_date' => '2026-10-21',
            'state' => 'confirmed', 'confirmed_by' => $this->author->id, 'confirmed_at' => now(), 'created_by' => $this->author->id,
        ]), 'insertar una compra ya confirmada');
        $this->assertRejected(self::CHECK, fn () => DB::table('purchases')->insert([
            'supplier_id' => $supplier, 'warehouse_id' => $this->warehouseId, 'document_date' => '2026-10-21',
            'state' => 'voided', 'confirmed_by' => $this->author->id, 'confirmed_at' => now(),
            'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'x', 'created_by' => $this->author->id,
        ]), 'insertar una compra ya anulada');
        $this->assertSame('draft', DB::table('purchases')->where('id', $draft)->value('state'));
    }

    public function test_a_draft_can_be_edited_and_discarded_physically(): void
    {
        $purchase = $this->purchase($this->supplier('P', '1-1'));

        $this->addLine($purchase, $this->productA, '1.000', '1.00');
        $this->assertSame(1, DB::table('purchase_items')->where('purchase_id', $purchase)->count());
        DB::table('purchase_items')->where('purchase_id', $purchase)->delete();
        DB::table('purchases')->where('id', $purchase)->delete();

        $this->assertDatabaseMissing('purchases', ['id' => $purchase]);
        $this->assertDatabaseMissing('purchase_items', ['purchase_id' => $purchase]);
    }

    public function test_idempotency_records_are_unique_per_user_action_and_key(): void
    {
        $this->idempotency($this->author->id, 'purchase.confirm', 'k-1', 'a');

        $this->assertRejected(self::UNIQUE, fn () => $this->idempotency($this->author->id, 'purchase.confirm', 'k-1', 'b'), 'misma clave, usuario y acción');
        $this->idempotency($this->reviewer->id, 'purchase.confirm', 'k-1', 'b'); // otro usuario: permitido
        $this->idempotency($this->author->id, 'sale.confirm', 'k-1', 'c'); // otra acción: permitido
        $this->assertRejected(self::CHECK, fn () => DB::table('idempotency_records')->insert(['user_id' => $this->author->id, 'action' => 'x', 'idempotency_key' => 'k-2', 'request_hash' => 'ZZ']), 'hash inválido');
        $this->assertRejected(self::CHECK, fn () => DB::table('idempotency_records')->insert(['user_id' => $this->author->id, 'action' => 'x', 'idempotency_key' => 'k-3', 'request_hash' => str_repeat('a', 64), 'result_type' => 'purchase']), 'result_type sin result_id');
    }

    /** Ejecuta dentro de un savepoint para que PostgreSQL no aborte la transacción de la prueba. */
    private function assertRejected(string $sqlState, callable $operation, string $message): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode(), $message.' (se esperaba SQLSTATE '.$sqlState.'): '.$exception->getMessage());

            return;
        }
        $this->fail($message.': PostgreSQL debió rechazar la operación.');
    }

    private function supplier(string $name, string $taxId): int
    {
        return DB::table('suppliers')->insertGetId(['name' => $name, 'tax_id' => $taxId]);
    }

    private function purchase(int $supplierId, ?string $reference = null): int
    {
        return DB::table('purchases')->insertGetId([
            'supplier_id' => $supplierId, 'warehouse_id' => $this->warehouseId, 'document_date' => '2026-10-21',
            'supplier_reference' => $reference, 'created_by' => $this->author->id,
        ]);
    }

    private function addLine(int $purchaseId, int $productId, string $quantity, string $unitCost): void
    {
        $total = app(DecimalService::class)->lineTotal($quantity, $unitCost);
        $this->rawLine($purchaseId, $productId, $quantity, $unitCost, $total);
    }

    private function rawLine(int $purchaseId, int $productId, string $quantity, string $unitCost, string $lineTotal): void
    {
        DB::table('purchase_items')->insert([
            'purchase_id' => $purchaseId, 'product_id' => $productId, 'unit_id' => $this->unitId,
            'product_sku' => 'SKU-'.$productId, 'product_name' => 'Producto '.$productId,
            'quantity' => $quantity, 'unit_cost' => $unitCost, 'line_total' => $lineTotal,
        ]);
    }

    private function confirm(int $purchaseId, ?string $key = null): void
    {
        $lines = DB::table('purchase_items')->where('purchase_id', $purchaseId)->pluck('line_total')->map(fn ($value) => (string) $value)->all();
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'confirmed', 'total' => app(DecimalService::class)->total($lines),
            'confirmed_by' => $this->author->id, 'confirmed_at' => now(), 'confirmation_idempotency_key' => $key,
        ]);
    }

    private function void(int $purchaseId): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'voided', 'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'Error de captura',
        ]);
    }

    private function idempotency(int $userId, string $action, string $key, string $hashChar): void
    {
        DB::table('idempotency_records')->insert([
            'user_id' => $userId, 'action' => $action, 'idempotency_key' => $key, 'request_hash' => str_repeat($hashChar, 64),
        ]);
    }

    private function actor(string $role, string $suffix): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Usuario de pruebas '.$suffix,
            'email' => 'purchase-schema-'.$suffix.'@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $role)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }
}
