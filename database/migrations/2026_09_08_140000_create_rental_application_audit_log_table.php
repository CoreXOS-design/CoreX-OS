<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 authoriser flow — Johan: "properly logged in audit trail - same
 * fight we had with contacts and properties history - properly tracked so
 * evidence will show who authorised who declined. what was done at what
 * step etc." Mirrors database/migrations/2026_08_10_000001_
 * create_contact_audit_log_table.php (ContactAuditLog) column-for-column —
 * same actor/event/values/metadata/summary shape — rather than inventing a
 * new audit shape. Deliberately does NOT copy that migration's nullable-
 * agency_id + unbypassable-DB-trigger backstop: that exists for a specific,
 * already-incident-hardened contact-write-path requirement that doesn't
 * apply here (every RentalApplication write already goes through this
 * module's own controllers, agency_id is always known).
 *
 * Coexists with RentalApplicationStatusHistory (cc4's own status-transition
 * log) rather than replacing it — the same way Contact has both its own
 * status fields AND ContactAuditLog. StatusHistory keeps tracking status
 * transitions; this table carries the fuller "who/what/when/why, was it an
 * override" record specifically for authorisation actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('actor_type', 24)->nullable();   // user|system|unknown
            $table->string('actor_label', 120)->nullable();
            $table->string('source', 60)->nullable();
            $table->string('event_category', 40);
            $table->string('event_type', 80);
            $table->boolean('is_override')->default(false);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->string('human_summary', 255)->nullable();
            $table->timestamp('created_at');
            $table->softDeletes();

            // Explicit short index names — the auto-generated names exceed
            // MySQL's 64-char identifier limit for this table name.
            $table->index(['rental_application_id', 'created_at'], 'ra_audit_log_app_created_idx');
            $table->index(['rental_application_id', 'event_category'], 'ra_audit_log_app_category_idx');
            $table->index(['agency_id', 'created_at'], 'ra_audit_log_agency_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_audit_log');
    }
};
