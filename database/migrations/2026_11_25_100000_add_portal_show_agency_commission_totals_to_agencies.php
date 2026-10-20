<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency setting: may My Portal show agents their branch's and the agency's commission totals?
 * Default OFF (agency-tracker-rehome.md section 4.2): agents see only their own commission unless the agency
 * decides otherwise. A new column on agencies with a default, so no data backfill and no agency id in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'portal_show_agency_commission_totals')) {
            return;
        }
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('portal_show_agency_commission_totals')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'portal_show_agency_commission_totals')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('portal_show_agency_commission_totals');
            });
        }
    }
};
