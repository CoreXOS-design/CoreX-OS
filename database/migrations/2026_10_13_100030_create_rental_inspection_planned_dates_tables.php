<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.7 (Build I-5) — Johan's 6 Oct ruling (Q6): interim inspections are NOT scheduled
 * automatically; the agency LOADS its own interim dates and CoreX reminds from them.
 *
 *  - rental_inspection_planned_dates         — the loaded dates (full CRUD: archive/restore via deleted_at, never a hard delete).
 *  - rental_inspection_planned_date_notices  — append-only: one row per date + milestone (lead|due|overdue); the unique
 *                                              key is what makes the daily reminder command idempotent.
 *  - rental_inspection_due_notices           — append-only: the same, for the computed In/Out due list (lease + type + due_on + milestone).
 *
 * `channel` on a notice is the list of channels actually used ("in_app,mail"), one row per milestone — the unique key is per milestone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_inspection_planned_dates')) {
            Schema::create('rental_inspection_planned_dates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
                $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
                // Denormalised, exactly as rental_inspections does it — the list scopes on the property.
                $table->unsignedBigInteger('property_id');
                $table->string('type', 10)->default('interim');
                $table->date('planned_on');
                $table->string('note', 500)->nullable();
                $table->string('status', 10)->default('planned'); // planned | booked | done | skipped
                $table->string('skipped_reason', 500)->nullable();
                $table->unsignedBigInteger('rental_inspection_id')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('archived_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['agency_id', 'planned_on'], 'ripd_agency_planned_on_idx');
                $table->index(['lease_id', 'type', 'planned_on'], 'ripd_lease_type_date_idx');
                $table->index('property_id', 'ripd_property_idx');
                $table->index('rental_inspection_id', 'ripd_inspection_idx');
            });
        }

        if (! Schema::hasTable('rental_inspection_planned_date_notices')) {
            Schema::create('rental_inspection_planned_date_notices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
                $table->foreignId('planned_date_id')->constrained('rental_inspection_planned_dates')->cascadeOnDelete();
                $table->string('milestone', 10); // lead | due | overdue
                $table->unsignedBigInteger('recipient_user_id')->nullable();
                $table->string('channel', 30)->nullable();
                $table->string('status', 12); // sent | skipped | failed
                $table->string('detail', 255)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['planned_date_id', 'milestone'], 'ripdn_date_milestone_unique');
            });
        }

        if (! Schema::hasTable('rental_inspection_due_notices')) {
            Schema::create('rental_inspection_due_notices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
                $table->foreignId('lease_id')->constrained('leases')->cascadeOnDelete();
                $table->string('type', 10); // in | out
                $table->date('due_on');
                $table->string('milestone', 10); // lead | due | overdue
                $table->unsignedBigInteger('recipient_user_id')->nullable();
                $table->string('channel', 30)->nullable();
                $table->string('status', 12); // sent | skipped | failed
                $table->string('detail', 255)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique(['lease_id', 'type', 'due_on', 'milestone'], 'ridn_lease_type_due_milestone_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_due_notices');
        Schema::dropIfExists('rental_inspection_planned_date_notices');
        Schema::dropIfExists('rental_inspection_planned_dates');
    }
};
