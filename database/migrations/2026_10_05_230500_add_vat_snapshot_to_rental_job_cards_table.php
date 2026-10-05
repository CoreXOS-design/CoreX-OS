<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document-level VAT snapshot — frozen alongside each line's own snapshot
 * (2026_10_05_230300) at "send to owner as quote" or job-card completion,
 * whichever happens first. Registration status and capture mode apply to
 * the whole document; rate varies per line, so only the line carries a rate
 * snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->boolean('vat_registered_snapshot')->nullable()->after('total_amount');
            $table->string('vat_capture_mode_snapshot', 10)->nullable()->after('vat_registered_snapshot');
            $table->timestamp('vat_snapshotted_at')->nullable()->after('vat_capture_mode_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropColumn(['vat_registered_snapshot', 'vat_capture_mode_snapshot', 'vat_snapshotted_at']);
        });
    }
};
