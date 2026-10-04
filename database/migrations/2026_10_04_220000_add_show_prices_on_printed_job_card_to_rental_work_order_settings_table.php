<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 follow-up (conductor's ruling, 2026-10-04) — the worker's printed
 * copy and the owner's quote PDF are not the same audience and do not show
 * the same thing by default. Default OFF: the printed job card shows
 * tasks/parts/quantities, no price column, unless an agency switches this
 * on. The owner quote PDF is NEVER gated by this setting — it always shows
 * prices when capture_prices_on_job_cards is on (see
 * RentalDocumentPdfService::jobCardQuotePdf(), unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->boolean('show_prices_on_printed_job_card')->default(false)->after('capture_prices_on_job_cards');
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->dropColumn('show_prices_on_printed_job_card');
        });
    }
};
