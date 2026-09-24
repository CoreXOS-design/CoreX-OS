<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.6 — "The checklist does not block approval by default." Nullable
 * boolean, same null-means-default convention as every other toggle on this
 * table (RentalApplicationQualifyingSetting::requireChecklistCompleteFor()).
 * Default OFF (false) when unset — Sherry's checklist is a working aid, not
 * a gate, until an agency deliberately turns it into one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->boolean('require_checklist_complete')->nullable()->after('require_fica_before_authorisation');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_qualifying_settings', function (Blueprint $table) {
            $table->dropColumn('require_checklist_complete');
        });
    }
};
