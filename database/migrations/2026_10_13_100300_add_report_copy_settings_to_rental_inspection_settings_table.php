<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.6 (Build I-4) — who gets a copy of a completed inspection report besides
 * the tenant(s) and landlord(s): the agency's own copy address(es), the inspector, the agent who created the
 * inspection. Nullable, read-time-default pattern like every other column on this table — null means "use the
 * RentalInspectionSetting::DEFAULT_* constant" (no agency address; inspector and creator both copied), never a
 * default baked into the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->text('report_agency_copy_emails')->nullable()->after('auto_send_report_enabled');
            $table->boolean('report_copy_inspector')->nullable()->after('report_agency_copy_emails');
            $table->boolean('report_copy_creator')->nullable()->after('report_copy_inspector');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn(['report_agency_copy_emails', 'report_copy_inspector', 'report_copy_creator']);
        });
    }
};
