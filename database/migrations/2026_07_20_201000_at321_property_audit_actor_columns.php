<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-321 — attribution columns for the property audit trail.
 *
 * Adds actor_type / actor_label / source so every row is attributable even when
 * there is no authenticated user (jobs, imports, console, raw writes). Relaxes
 * agency_id to NULL so the unbypassable DB trigger (next migration) can ALWAYS
 * insert a backstop row without the FK/NOT-NULL constraint ever rolling back a
 * property save — a bulletproof bare INSERT (spec §3.2/§3.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_audit_log', function (Blueprint $table) {
            $table->string('actor_type', 24)->nullable()->after('user_id');   // user|system|import|console|sync|portal|db-trigger|unknown
            $table->string('actor_label', 120)->nullable()->after('actor_type');
            $table->string('source', 60)->nullable()->after('actor_label');
        });

        // Relax agency_id to NULL (FK preserved) — the trigger must never fail a save.
        DB::statement('ALTER TABLE property_audit_log MODIFY agency_id BIGINT UNSIGNED NULL');

        // Backfill existing rows so nothing reads as a contextless blank.
        DB::statement("UPDATE property_audit_log SET actor_type = 'user' WHERE user_id IS NOT NULL AND actor_type IS NULL");
        DB::statement("UPDATE property_audit_log SET actor_type = 'system', actor_label = 'System (pre-AT-321)' WHERE user_id IS NULL AND actor_type IS NULL");
    }

    public function down(): void
    {
        Schema::table('property_audit_log', function (Blueprint $table) {
            $table->dropColumn(['actor_type', 'actor_label', 'source']);
        });
        // Leave agency_id nullable on rollback — narrowing it back could fail on
        // trigger-written rows; harmless to keep nullable.
    }
};
