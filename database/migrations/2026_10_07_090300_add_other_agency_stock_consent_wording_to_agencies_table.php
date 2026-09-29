<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md §3a — the consent checkbox wording an
 * agency shows its agents before an Other Agency Stock import. NULL = use
 * OtherAgencyStockConsent::DEFAULT_WORDING (no hardcoded text in code paths
 * that read it — every read falls back through this column first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->text('other_agency_stock_consent_wording')->nullable()->after('other_agency_stock_visible_roles');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('other_agency_stock_consent_wording');
        });
    }
};
