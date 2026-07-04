<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-164 §15.9 — per-user calendar layout memory (cross-device source of truth;
 * localStorage mirrors it for instant paint). Both nullable → a user with no row
 * falls back to the agency/role default, then the code default.
 *
 *  - calendar_deck_layout (json): ordered array of tile-ids the user pinned to
 *    their Deck slots (Gate 4). NULL = use the role/agency default layout.
 *  - calendar_layers      (json): active layer-toggle keys (Gate 6). NULL = use
 *    the agency default layers.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_user_preferences', function (Blueprint $table) {
            $table->json('calendar_deck_layout')->nullable()->after('digest_email');
            $table->json('calendar_layers')->nullable()->after('calendar_deck_layout');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_user_preferences', function (Blueprint $table) {
            $table->dropColumn(['calendar_deck_layout', 'calendar_layers']);
        });
    }
};
