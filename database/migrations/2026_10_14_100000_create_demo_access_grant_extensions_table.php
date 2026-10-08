<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo Access — the durable record of every "Add time" extension.
 *
 * Spec: .ai/specs/demo-access-control.md §4.6, §9.1
 *
 * Why a table and not just the DemoAccessExtended audit row: domain_event_log is a
 * best-effort log (RecordDomainEvent swallows every failure and can be switched
 * off), and the grant page's "Time added" list used to be read back from it. A
 * failed audit write meant the grant WAS extended but nobody could ever see that or
 * why. This row is written inside the same DB transaction as the change itself, so
 * the two cannot disagree. The event is still fired for the catalogue.
 *
 * Append-only: a row is never updated or deleted (a grant is evidence — non-
 * negotiable #1), so there is no updated_at / deleted_at.
 *
 * `event_id` is the DemoAccessExtended event's id: it lets the grant page merge the
 * pre-existing history that only lives in domain_event_log without listing an
 * extension twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_access_grant_extensions')) {
            return;
        }

        Schema::create('demo_access_grant_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_access_grant_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->char('event_id', 36)->nullable();
            $table->unsignedInteger('hours_added');
            $table->string('basis', 20);                      // before_start | from_deadline | from_now
            $table->timestamp('previous_expires_at')->nullable();
            $table->timestamp('new_expires_at')->nullable();
            $table->unsignedInteger('previous_expiry_hours')->nullable();
            $table->unsignedInteger('new_expiry_hours')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['demo_access_grant_id', 'created_at'], 'demo_access_ext_grant_idx');
            $table->index('event_id', 'demo_access_ext_event_idx');

            $table->foreign('demo_access_grant_id', 'demo_access_ext_grant_fk')
                  ->references('id')->on('demo_access_grants')
                  ->cascadeOnUpdate();
            $table->foreign('actor_user_id', 'demo_access_ext_actor_fk')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_access_grant_extensions');
    }
};
