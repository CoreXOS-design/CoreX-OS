<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact-details Phase 3 — primary WhatsApp designation, per-tel.
 *
 * `is_whatsapp` — this number is reachable on WhatsApp (an agent may hold
 * several numbers for a contact but only one or two are actually on WhatsApp).
 * `is_primary_whatsapp` — of the numbers flagged is_whatsapp, exactly one is
 * THE number outreach uses — independent of `is_primary` (the primary contact
 * number). A contact's main office line can be primary-contact while a
 * personal cell is primary-WhatsApp; that's the whole point of the split.
 *
 * Neither flag is retroactively set on existing rows (both default false) —
 * no existing number is assumed to be on WhatsApp until an agent says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_phones', function (Blueprint $table) {
            $table->boolean('is_whatsapp')->default(false)->after('is_primary');
            $table->boolean('is_primary_whatsapp')->default(false)->after('is_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('contact_phones', function (Blueprint $table) {
            $table->dropColumn(['is_whatsapp', 'is_primary_whatsapp']);
        });
    }
};
