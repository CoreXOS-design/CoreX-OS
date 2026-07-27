<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-details Phase 1 — country dialing prefix per phone number.
 *
 * Every existing row predates this feature and was captured as a South African
 * number, so the ZA/+27 default backfills them correctly (not a placeholder —
 * an accurate statement of what those numbers are). New rows default to ZA and
 * the agent picks a different country from the repeater's dial-code select.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_phones', function (Blueprint $table) {
            $table->char('country_iso', 2)->default('ZA')->after('phone');
            $table->string('dial_code', 8)->default('+27')->after('country_iso');
        });
    }

    public function down(): void
    {
        Schema::table('contact_phones', function (Blueprint $table) {
            $table->dropColumn(['country_iso', 'dial_code']);
        });
    }
};
