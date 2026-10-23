<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * YOURGUEN-DB-001B - Correccion de transiciones de estado de compras (revision de Manuel, PR #1).
 *
 * 1) Una compra en borrador no puede pasar directo a anulada: draft -> confirmed -> voided.
 * 2) Toda compra nace como borrador: se rechaza insertar compras ya confirmadas o anuladas.
 *
 * No se edita la migracion 2026_10_21_100000 (ya aplicada en otras bases): esta migracion
 * correctiva reemplaza la funcion y agrega un trigger. Solo funciones y triggers; no toca datos ni tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchases_state_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state = 'voided' AND NEW.state <> 'voided' THEN
        RAISE EXCEPTION 'Una compra anulada no puede volver a otro estado.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'draft' AND NEW.state = 'voided' THEN
        RAISE EXCEPTION 'Una compra en borrador no se anula: primero se confirma o se descarta.' USING ERRCODE = 'check_violation';
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

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchases_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF NEW.state <> 'draft' THEN
        RAISE EXCEPTION 'Toda compra se crea en borrador.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$
SQL);
        DB::statement('DROP TRIGGER IF EXISTS purchases_insert_guard ON purchases');
        DB::statement('CREATE TRIGGER purchases_insert_guard BEFORE INSERT ON purchases FOR EACH ROW EXECUTE FUNCTION purchases_insert_guard()');
    }

    public function down(): void
    {
        // Restaura la funcion tal como la dejo 2026_10_21_100000 y elimina el trigger de insercion.
        DB::statement('DROP TRIGGER IF EXISTS purchases_insert_guard ON purchases');
        DB::statement('DROP FUNCTION IF EXISTS purchases_insert_guard()');
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
    }
};
