<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-117 §4a — agency-configurable outreach send-window.
 *
 * A single nullable JSON column on agencies holding the permitted send days +
 * per-day open/close times + public-holiday flag. NULL means "use the legal
 * defaults" (resolved in code via Agency::outreachSendWindow()), so existing
 * agencies inherit the correct SA CPA defaults without a data backfill and the
 * window remains agency-editable — never hardcoded law.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('outreach_send_window')->nullable()->after('whatsapp_launch_mode_seller');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('outreach_send_window');
        });
    }
};
