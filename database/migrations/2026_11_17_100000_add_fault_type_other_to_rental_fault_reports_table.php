<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 (Johan, 19 Oct 2026) - "Other - not on the list" on the problem list. It is not a row in the agency's own catalogue (so a new agency
 * has nothing to seed and an agency cannot archive it): a report simply says its type is "Other" and has no catalogue type until the agent
 * re-classifies it. Default false - every existing report keeps meaning exactly what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_fault_reports', 'fault_type_other')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->boolean('fault_type_other')->default(false)->after('rental_fault_type_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_fault_reports', 'fault_type_other')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->dropColumn('fault_type_other');
            });
        }
    }
};
