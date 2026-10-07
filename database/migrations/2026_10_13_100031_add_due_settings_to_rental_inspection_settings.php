<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.7 item 6 (Build I-5) — three agency settings, all nullable (a null reads as the
 * model's own default; nothing is backfilled):
 *   planned_date_lead_days         default 14 — how many days before a LOADED interim date the first reminder goes out
 *   out_due_lead_days              default 7  — how many days before a lease's out-inspection is due it shows as due
 *   raise_due_inspections_enabled  default on — remind the agent about In/Out inspections that are due (In/Out only;
 *                                               there is deliberately no interim switch here: nothing computes an interim date)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_inspection_settings', 'planned_date_lead_days')) {
                $table->unsignedSmallInteger('planned_date_lead_days')->nullable();
            }
            if (! Schema::hasColumn('rental_inspection_settings', 'out_due_lead_days')) {
                $table->unsignedSmallInteger('out_due_lead_days')->nullable();
            }
            if (! Schema::hasColumn('rental_inspection_settings', 'raise_due_inspections_enabled')) {
                $table->boolean('raise_due_inspections_enabled')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            foreach (['planned_date_lead_days', 'out_due_lead_days', 'raise_due_inspections_enabled'] as $col) {
                if (Schema::hasColumn('rental_inspection_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
