<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the agency does — 'sales', 'rentals' or 'both' (config/agency-types.php). Seeds the agency's feature
 * switches when it is created. Every existing agency becomes 'both' and keeps every switch exactly as it is:
 * this migration writes no feature rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'agency_type')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('agency_type', 10)->default('both');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'agency_type')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('agency_type');
            });
        }
    }
};
