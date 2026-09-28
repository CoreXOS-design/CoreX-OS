<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §41-follow-up (Job 3, 2026-09-28) — Johan's ruling: "auto-send is an
 * agency setting, default ON." Nullable, read-time-default (null means
 * "use RentalInventorySetting::DEFAULT_AUTO_SEND_REPORT_ENABLED (true)"),
 * exactly the same convention `rental_inspection_settings.
 * auto_send_report_enabled` already established for the same idea on the
 * Inspections side — mirrored, not shared (see this settings model's own
 * docblock for why the two stay separate). The public-link EXPIRY, unlike
 * Inspections, is deliberately NOT made agency-configurable here — nobody
 * asked for it, and a second nullable column just to carry a fixed 90-day
 * default nobody can currently change would be a setting with no control
 * to reach the Setup Wizard (non-negotiable #10a) attached to it for no
 * reason. RentalInventory::generatePublicLink() uses a plain constant
 * instead; see that method's own comment if this ever needs to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->boolean('auto_send_report_enabled')->nullable()->after('condition_states');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->dropColumn('auto_send_report_enabled');
        });
    }
};
