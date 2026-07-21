<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-321 — the unbypassable backstop.
 *
 * A MySQL trigger that writes a property_audit_log row for ANY change to a
 * meaningful property column, regardless of the write path (Eloquent, quiet
 * write, raw DB::table, or manual SQL). It fires ONLY when the application audit
 * layer did not already handle the write (@corex_audit_handled) — so normal
 * Eloquent edits and the explicit raw-site calls are NOT double-logged.
 *
 * BULLETPROOF-INSERT contract (spec §3.2/§3.5): the INSERT can never fail a
 * property save — user_id is always NULL (no FK on a possibly-stale actor id;
 * attribution is via actor_label + metadata.actor_user_id), agency_id/branch_id
 * are nullable, and every NOT-NULL target column is a literal. Nothing here can
 * raise a constraint error, so a property UPDATE/INSERT can never be rolled back
 * by this trigger.
 *
 * Actor: stamped from the per-connection session vars @corex_actor_id /
 * @corex_actor_label set by PropertyAuditContext at the request/job/console edge.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Creating a trigger while binary logging is on requires SUPER (or
        // log_bin_trust_function_creators). On restricted app-DB users this throws
        // 1419. We ABSORB that (log loudly) rather than fail the whole migration
        // batch: the trigger is an unbypassable BACKSTOP, and its absence degrades
        // gracefully to the app-layer audit (which still logs every Eloquent path).
        // Where privilege allows, the trigger is created here; where it does not
        // (test bootstrap, restricted prod user), a DBA enables the privilege and
        // re-runs this migration, or creates the trigger out-of-band. Either way,
        // the batch never breaks. (AT-321 spec §3.2/§3.5.)
        try {
            $this->createTriggers();
        } catch (\Throwable $e) {
            \Log::warning('AT-321 property audit trigger NOT created — needs SUPER or '
                . 'log_bin_trust_function_creators. App-layer audit still active. Error: ' . $e->getMessage());
        }
    }

    private function createTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS corex_property_audit_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS corex_property_audit_after_insert');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER corex_property_audit_after_update
AFTER UPDATE ON properties
FOR EACH ROW
BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
       AND (
            NOT (NEW.agent_id      <=> OLD.agent_id)
         OR NOT (NEW.status        <=> OLD.status)
         OR NOT (NEW.price         <=> OLD.price)
         OR NOT (NEW.mandate_type  <=> OLD.mandate_type)
         OR NOT (NEW.listing_type  <=> OLD.listing_type)
         OR NOT (NEW.address       <=> OLD.address)
         OR NOT (NEW.street_number <=> OLD.street_number)
         OR NOT (NEW.street_name   <=> OLD.street_name)
         OR NOT (NEW.suburb        <=> OLD.suburb)
         OR NOT (NEW.complex_name  <=> OLD.complex_name)
         OR NOT (NEW.unit_number   <=> OLD.unit_number)
         OR NOT (NEW.title         <=> OLD.title)
         OR NOT (NEW.description   <=> OLD.description)
         OR NOT (NEW.beds          <=> OLD.beds)
         OR NOT (NEW.baths         <=> OLD.baths)
       )
    THEN
        INSERT INTO property_audit_log
            (property_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'property_updated',
             JSON_OBJECT('agent_id', OLD.agent_id, 'status', OLD.status, 'price', OLD.price,
                         'address', OLD.address, 'mandate_type', OLD.mandate_type),
             JSON_OBJECT('agent_id', NEW.agent_id, 'status', NEW.status, 'price', NEW.price,
                         'address', NEW.address, 'mandate_type', NEW.mandate_type),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Change recorded by database backstop (write bypassed the app audit layer)',
             NOW());
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER corex_property_audit_after_insert
AFTER INSERT ON properties
FOR EACH ROW
BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
    THEN
        INSERT INTO property_audit_log
            (property_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'property_created',
             NULL,
             JSON_OBJECT('agent_id', NEW.agent_id, 'status', NEW.status, 'price', NEW.price),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Property created (recorded by database backstop)',
             NOW());
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS corex_property_audit_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS corex_property_audit_after_insert');
    }
};
