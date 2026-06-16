<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-50 — per-agency override for which deals_v2 statuses count as a LIVE
 * transaction (gating transactional opt-out). NULL = use the system default
 * from config/corex-outreach.php (['active']). Stored as JSON array of status
 * strings. Never hardcoded in the service.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('outreach_live_deal_statuses')->nullable()->after('marketing_unsubscribe_footer');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('outreach_live_deal_statuses');
        });
    }
};
