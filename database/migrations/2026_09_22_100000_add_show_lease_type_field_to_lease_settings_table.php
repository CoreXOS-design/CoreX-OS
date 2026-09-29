<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-22 — "the freaking lease type is showing here again... hide
 * it, dont remove it." The Lease Type control (lease screen + property
 * Rental tab) is agency-configurable, sensible default HIDDEN, per his
 * standing rule that anything like this is a setting, not a hardcoded
 * show/hide. The underlying column, model, agency-editable list, and P24
 * mapping (.ai/specs/rental-property-tab.md §5.3) are all untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->boolean('show_lease_type_field')->default(false)->after('expiry_notice_window_days');
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            $table->dropColumn('show_lease_type_field');
        });
    }
};
