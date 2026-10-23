<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YOURGUEN-DB-001 - Proveedores, compras (confirmar = recibir en V1),
 * detalle de compra y registro común de idempotencia.
 *
 * Aditiva: no modifica ninguna tabla ni migración existente de Manuel.
 * No crea movimientos ni toca inventory_balances (eso es YOURGUEN-DB-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('SICOVI requiere PostgreSQL y el esquema sicovi previamente preparado.');
        }
        if (DB::selectOne('select current_schema() as name')->name !== 'sicovi') {
            throw new RuntimeException('El esquema activo debe ser sicovi. Revise DB_SCHEMA y la preparación de PostgreSQL.');
        }
        foreach (['users', 'warehouses', 'products', 'units'] as $required) {
            if (! Schema::hasTable($required)) {
                throw new RuntimeException("Falta la tabla {$required}: aplique primero las migraciones de Manuel (000001 a 000005).");
            }
        }

        // Números internos: secuencias de PostgreSQL, nunca MAX()+1. Pueden tener saltos.
        // MAXVALUE evita que lpad() trunque en silencio y genere códigos repetidos.
        DB::statement('CREATE SEQUENCE supplier_code_seq AS bigint START 1 MINVALUE 1 MAXVALUE 999999 NO CYCLE');
        DB::statement('CREATE SEQUENCE purchase_code_seq AS bigint START 1 MINVALUE 1 MAXVALUE 999999 NO CYCLE');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 180);
            $table->string('tax_id', 30);
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->index('name');
            $table->index(['active', 'name']);
        });
        DB::statement("ALTER TABLE suppliers ALTER COLUMN code SET DEFAULT ('PRV-' || lpad(nextval('supplier_code_seq')::text, 6, '0'))");
        DB::statement('ALTER SEQUENCE supplier_code_seq OWNED BY suppliers.code');
        $this->unicode('suppliers', ['code' => 30, 'name' => 180, 'tax_id' => 30]);
        DB::statement('CREATE UNIQUE INDEX suppliers_code_unique ON suppliers (code)');
        // NIT obligatorio y único una vez normalizado (sin espacios ni guiones, en minúsculas).
        DB::statement("CREATE UNIQUE INDEX suppliers_tax_id_normalized_unique ON suppliers (lower(regexp_replace(btrim(tax_id), '[\\s-]+', '', 'g')))");
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_tax_id_not_blank CHECK (length(regexp_replace(btrim(tax_id), '[\\s-]+', '', 'g')) > 0)");
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_name_not_blank CHECK (length(btrim(name)) > 0)');
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_code_not_blank CHECK (length(btrim(code)) > 0)');
        foreach (['contact_name', 'phone', 'email'] as $optional) {
            DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_{$optional}_not_blank CHECK ({$optional} IS NULL OR length(btrim({$optional})) > 0)");
        }

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('document_date');
            $table->string('supplier_reference', 60)->nullable();
            $table->string('state', 20)->default('draft');
            $table->unsignedInteger('version')->default(0);
            $table->text('notes')->nullable();
            $table->decimal('total', 16, 2)->default('0');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmation_idempotency_key', 80)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();
            $table->index(['supplier_id', 'document_date']);
            $table->index(['state', 'document_date']);
            $table->index('document_date');
            $table->index('created_by');
        });
        DB::statement("ALTER TABLE purchases ALTER COLUMN code SET DEFAULT ('COM-' || lpad(nextval('purchase_code_seq')::text, 6, '0'))");
        DB::statement('ALTER SEQUENCE purchase_code_seq OWNED BY purchases.code');
        $this->unicode('purchases', ['code' => 30, 'supplier_reference' => 60]);
        DB::statement('CREATE UNIQUE INDEX purchases_code_unique ON purchases (code)');
        // Referencia del proveedor: única por proveedor cuando existe. NO se libera al anular (el histórico se conserva).
        DB::statement("CREATE UNIQUE INDEX purchases_supplier_reference_unique ON purchases (supplier_id, lower(regexp_replace(btrim(supplier_reference), '\\s+', ' ', 'g'))) WHERE supplier_reference IS NOT NULL");
        DB::statement('CREATE UNIQUE INDEX purchases_confirmation_key_unique ON purchases (confirmed_by, confirmation_idempotency_key) WHERE confirmation_idempotency_key IS NOT NULL');
        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_state_check CHECK (state IN ('draft', 'confirmed', 'voided'))");
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_version_check CHECK (version >= 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_total_check CHECK (total >= 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_code_not_blank CHECK (length(btrim(code)) > 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_supplier_reference_not_blank CHECK (supplier_reference IS NULL OR length(btrim(supplier_reference)) > 0)');
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_key_not_blank CHECK (confirmation_idempotency_key IS NULL OR length(btrim(confirmation_idempotency_key)) > 0)');
        DB::statement(<<<'SQL'
ALTER TABLE purchases ADD CONSTRAINT purchases_state_consistency_check CHECK (
    (state = 'draft'
        AND confirmed_at IS NULL AND confirmed_by IS NULL AND confirmation_idempotency_key IS NULL
        AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL)
    OR (state = 'confirmed'
        AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL
        AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL)
    OR (state = 'voided'
        AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL
        AND voided_at IS NOT NULL AND voided_by IS NOT NULL
        AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0)
)
SQL);

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('product_sku', 50);
            $table->string('product_name', 180);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 16, 2);
            $table->timestampsTz();
            // Una línea por producto: el servicio normaliza repetidos antes de guardar.
            $table->unique(['purchase_id', 'product_id']);
            $table->index('product_id');
        });
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_unit_cost_check CHECK (unit_cost >= 0)');
        // Mismo redondeo half-up de DecimalService::lineTotal (cantidad x costo tiene exactamente 5 decimales).
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_line_total_check CHECK (line_total = round(quantity * unit_cost, 2))');
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_snapshot_not_blank CHECK (length(btrim(product_sku)) > 0 AND length(btrim(product_name)) > 0)');

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 60);
            $table->string('idempotency_key', 80);
            $table->char('request_hash', 64);
            $table->string('result_type', 60)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'action', 'idempotency_key']);
            $table->index(['result_type', 'result_id']);
        });
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_action_not_blank CHECK (length(btrim(action)) > 0)');
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_key_not_blank CHECK (length(btrim(idempotency_key)) > 0)');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_hash_check CHECK (request_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_result_pair_check CHECK ((result_type IS NULL) = (result_id IS NULL))');

        $this->createGuards();
    }

    public function down(): void
    {
        // ATENCION: elimina proveedores, compras e idempotencia. Solo para BD descartables
        // o con respaldo verificado; nunca como método de actualización.
        DB::statement('DROP TRIGGER IF EXISTS purchase_items_draft_only ON purchase_items');
        DB::statement('DROP TRIGGER IF EXISTS purchases_state_guard ON purchases');
        DB::statement('DROP TRIGGER IF EXISTS purchases_no_delete_confirmed ON purchases');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
        DB::statement('DROP FUNCTION IF EXISTS purchase_items_draft_only()');
        DB::statement('DROP FUNCTION IF EXISTS purchases_state_guard()');
        DB::statement('DROP FUNCTION IF EXISTS purchases_no_delete_confirmed()');
        DB::statement('DROP SEQUENCE IF EXISTS supplier_code_seq');
        DB::statement('DROP SEQUENCE IF EXISTS purchase_code_seq');
    }

    /** Mismo collation que la migración 000002 de Manuel; ALTER crudo porque Laravel cita mal el nombre calificado. */
    private function unicode(string $table, array $columns): void
    {
        foreach ($columns as $column => $length) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar({$length}) COLLATE pg_catalog.pg_c_utf8");
        }
    }

    /** Defensas en BD de RN-07, RN-14 y de los estados: el servicio no es la única barrera. */
    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchases_state_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state = 'voided' AND NEW.state <> 'voided' THEN
        RAISE EXCEPTION 'Una compra anulada no puede volver a otro estado.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'confirmed' AND NEW.state = 'draft' THEN
        RAISE EXCEPTION 'Una compra confirmada no puede volver a borrador.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state <> 'draft' AND (
        NEW.code IS DISTINCT FROM OLD.code
        OR NEW.supplier_id IS DISTINCT FROM OLD.supplier_id
        OR NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id
        OR NEW.document_date IS DISTINCT FROM OLD.document_date
        OR NEW.supplier_reference IS DISTINCT FROM OLD.supplier_reference
        OR NEW.total IS DISTINCT FROM OLD.total
        OR NEW.created_by IS DISTINCT FROM OLD.created_by
        OR NEW.confirmed_by IS DISTINCT FROM OLD.confirmed_by
        OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
        OR NEW.confirmation_idempotency_key IS DISTINCT FROM OLD.confirmation_idempotency_key
    ) THEN
        RAISE EXCEPTION 'El contenido de una compra confirmada o anulada no se modifica.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'voided' AND (
        NEW.voided_by IS DISTINCT FROM OLD.voided_by
        OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
        OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
    ) THEN
        RAISE EXCEPTION 'Los datos de anulación no se modifican.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$
SQL);
        DB::statement('CREATE TRIGGER purchases_state_guard BEFORE UPDATE ON purchases FOR EACH ROW EXECUTE FUNCTION purchases_state_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchases_no_delete_confirmed() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state <> 'draft' THEN
        RAISE EXCEPTION 'No se eliminan compras confirmadas ni anuladas.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN OLD;
END
$fn$
SQL);
        DB::statement('CREATE TRIGGER purchases_no_delete_confirmed BEFORE DELETE ON purchases FOR EACH ROW EXECUTE FUNCTION purchases_no_delete_confirmed()');

        // FOR SHARE espera si otra transacción está confirmando la compra y relee el estado definitivo.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchase_items_draft_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    parent_state text;
    parent bigint;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.purchase_id IS DISTINCT FROM OLD.purchase_id THEN
        RAISE EXCEPTION 'Una línea no puede cambiar de compra.' USING ERRCODE = 'check_violation';
    END IF;
    parent := CASE WHEN TG_OP = 'DELETE' THEN OLD.purchase_id ELSE NEW.purchase_id END;
    SELECT state INTO parent_state FROM purchases WHERE id = parent FOR SHARE;
    IF FOUND AND parent_state <> 'draft' THEN
        RAISE EXCEPTION 'Las líneas solo se modifican mientras la compra está en borrador.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END
$fn$
SQL);
        DB::statement('CREATE TRIGGER purchase_items_draft_only BEFORE INSERT OR UPDATE OR DELETE ON purchase_items FOR EACH ROW EXECUTE FUNCTION purchase_items_draft_only()');
    }
};
