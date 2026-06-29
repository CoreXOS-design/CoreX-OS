<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-117 §8 — agency-configurable Outreach Queue settings (closeout).
 *
 * Both nullable → NULL means "use the sensible default", resolved in code:
 *   - outreach_queue_expiry_hours: how long a SURFACED-but-unsent row lives
 *     before status=expired. NULL = end of the surfaced day (the §5 default).
 *   - outreach_queue_daily_cap_per_agent: max rows an agent may queue per day
 *     (volume guard). NULL = no cap.
 * Agency owns the values — never hardcoded law.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('outreach_queue_expiry_hours')->nullable()->after('outreach_send_window');
            $table->unsignedSmallInteger('outreach_queue_daily_cap_per_agent')->nullable()->after('outreach_queue_expiry_hours');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['outreach_queue_expiry_hours', 'outreach_queue_daily_cap_per_agent']);
        });
    }
};
