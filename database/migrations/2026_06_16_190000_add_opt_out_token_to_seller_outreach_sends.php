<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-49 — per-send self-service opt-out token.
 *
 * Unguessable 48-char token (Str::random(48), mirrors SnapshotLinkService) that
 * backs the public GET/POST /outreach/opt-out/{token} route. Globally unique
 * (the public route resolves by token alone, no agency in the URL), nullable so
 * the column lands cleanly on historic rows. Soft-delete behaviour unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->string('opt_out_token', 48)->nullable()->after('tracking_short_code');
            // Single-column UNIQUE — the public route looks up by token only.
            $table->unique('opt_out_token', 'outreach_send_optout_token_uq');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->dropUnique('outreach_send_optout_token_uq');
            $table->dropColumn('opt_out_token');
        });
    }
};
