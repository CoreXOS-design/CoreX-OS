<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §41, 2026-09-28, Johan's ruling — "auto-send on/off is an agency
 * setting, default ON." Nullable, read-time-default pattern (matching
 * every other column on this table): null means "use
 * RentalInspectionSetting::DEFAULT_AUTO_SEND_REPORT_ENABLED (true)",
 * never a hardcoded default baked into the schema itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('auto_send_report_enabled')->nullable()->after('public_link_expiry_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('auto_send_report_enabled');
        });
    }
};
