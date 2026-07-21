<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-321-C — the unbypassable backstop for contacts. Mirror of the property audit
 * trigger (2026_07_20_201500).
 *
 * A MySQL trigger writes a contact_audit_log row for ANY change to a meaningful
 * contact column, regardless of write path (Eloquent, quiet write, raw DB::table,
 * or manual SQL). It fires ONLY when the application audit layer did not already
 * handle the write (@corex_audit_handled) — so normal Eloquent edits and the
 * explicit raw-site calls are NOT double-logged.
 *
 * BULLETPROOF-INSERT contract (spec §3.2/§3.5): the INSERT can never fail a contact
 * save — user_id is always NULL (no FK on a possibly-stale actor id; attribution is
 * via actor_label + metadata.actor_user_id), agency_id/branch_id are nullable, and
 * every NOT-NULL target column is a literal. Nothing here can raise a constraint
 * error, so a contact UPDATE/INSERT can never be rolled back by this trigger.
 *
 * Actor: stamped from the per-connection session vars @corex_actor_id /
 * @corex_actor_label set by AuditContext at the request/job/console edge.
 *
 * OPERATIONAL: creating a trigger while binary logging is on requires SUPER (or
 * log_bin_trust_function_creators). On restricted app-DB users this throws 1419.
 * We ABSORB that (log loudly) rather than fail the whole migration batch: the
 * trigger is an unbypassable BACKSTOP, and its absence degrades gracefully to the
 * app-layer audit (which still logs every Eloquent path). On QA1 the trigger is
 * created out-of-band (root, corex_qa1 schema only). For Staging/live a DBA enables
 * the privilege / creates it out-of-band — this lane does NOT attempt the grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $this->createTriggers();
        } catch (\Throwable $e) {
            \Log::warning('AT-321-C contact audit trigger NOT created — needs SUPER or '
                . 'log_bin_trust_function_creators. App-layer audit still active. Error: ' . $e->getMessage());
        }
    }

    private function createTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS corex_contact_audit_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS corex_contact_audit_after_insert');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER corex_contact_audit_after_update
AFTER UPDATE ON contacts
FOR EACH ROW
BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
       AND (
            NOT (NEW.agent_id          <=> OLD.agent_id)
         OR NOT (NEW.second_agent_id   <=> OLD.second_agent_id)
         OR NOT (NEW.first_name        <=> OLD.first_name)
         OR NOT (NEW.last_name         <=> OLD.last_name)
         OR NOT (NEW.phone             <=> OLD.phone)
         OR NOT (NEW.email             <=> OLD.email)
         OR NOT (NEW.id_number         <=> OLD.id_number)
         OR NOT (NEW.address           <=> OLD.address)
         OR NOT (NEW.contact_type_id   <=> OLD.contact_type_id)
         OR NOT (NEW.contact_source_id <=> OLD.contact_source_id)
         OR NOT (NEW.is_buyer          <=> OLD.is_buyer)
         OR NOT (NEW.buyer_state       <=> OLD.buyer_state)
       )
    THEN
        INSERT INTO contact_audit_log
            (contact_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'contact_updated',
             JSON_OBJECT('agent_id', OLD.agent_id, 'first_name', OLD.first_name, 'last_name', OLD.last_name,
                         'phone', OLD.phone, 'email', OLD.email, 'id_number', OLD.id_number),
             JSON_OBJECT('agent_id', NEW.agent_id, 'first_name', NEW.first_name, 'last_name', NEW.last_name,
                         'phone', NEW.phone, 'email', NEW.email, 'id_number', NEW.id_number),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Change recorded by database backstop (write bypassed the app audit layer)',
             NOW());
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER corex_contact_audit_after_insert
AFTER INSERT ON contacts
FOR EACH ROW
BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
    THEN
        INSERT INTO contact_audit_log
            (contact_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'contact_created',
             NULL,
             JSON_OBJECT('agent_id', NEW.agent_id, 'first_name', NEW.first_name, 'last_name', NEW.last_name,
                         'phone', NEW.phone, 'email', NEW.email),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Contact created (recorded by database backstop)',
             NOW());
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS corex_contact_audit_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS corex_contact_audit_after_insert');
    }
};
