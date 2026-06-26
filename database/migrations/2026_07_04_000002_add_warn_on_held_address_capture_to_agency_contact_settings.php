<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Part 3 — contact-capture "already on our books" safety net. When ON (default),
     * capturing a property address for a prospect contact warns the agent if HFC
     * already holds that property (agency stock) or has captured intelligence on it
     * (tracked property, not yet stock) — so they don't canvass an owner we already
     * represent. Sits alongside address_match_mode (AT-60), which tunes match
     * strictness; this is the dedicated on/off for the warn behaviour. Default ON.
     */
    public function up(): void
    {
        if (Schema::hasColumn('agency_contact_settings', 'warn_on_held_address_capture')) {
            return;
        }

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->boolean('warn_on_held_address_capture')
                ->default(true)
                ->after('address_match_mode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agency_contact_settings', 'warn_on_held_address_capture')) {
            return;
        }

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('warn_on_held_address_capture');
        });
    }
};
