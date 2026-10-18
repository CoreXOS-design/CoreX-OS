<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — agency setting: whether prices are used at all on internal job
 * cards. Default ON — with it off, no price columns or totals appear
 * anywhere on a job card (lines still record quantity, just no unit_price/
 * line_total). Setup Wizard entry added in the same prompt (non-negotiable
 * #10a) — see config/agency-onboarding-copy.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->boolean('capture_prices_on_job_cards')->default(true)->after('no_approval_spend_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->dropColumn('capture_prices_on_job_cards');
        });
    }
};
