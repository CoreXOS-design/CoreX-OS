<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency VAT set-up — `vat_registered`/`vat_no` already exist
 * (2026_07_25_120005). This adds the missing capture-mode flag (are prices
 * typed in excl or incl VAT?) and an audit trail for who last changed this
 * agency's VAT configuration and when — Johan: "an agency VAT set-up... the
 * finance stage will also depend on, so it must be done once, properly."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('vat_capture_mode', 10)->default('excl')->after('vat_registered');
            // excl | incl — which way an agent types prices on job card
            // lines/catalogue defaults for this agency.
            $table->timestamp('vat_settings_updated_at')->nullable()->after('vat_capture_mode');
            $table->foreignId('vat_settings_updated_by_user_id')->nullable()
                ->after('vat_settings_updated_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vat_settings_updated_by_user_id');
            $table->dropColumn(['vat_capture_mode', 'vat_settings_updated_at']);
        });
    }
};
