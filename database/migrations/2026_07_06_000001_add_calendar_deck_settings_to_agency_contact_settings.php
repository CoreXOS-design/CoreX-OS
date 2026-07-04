<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-164 (Calendar noise redesign) §15.9 — agency-configurable calendar surface
 * knobs. All nullable with code defaults on the model so an agency with no row
 * (or NULL columns) behaves correctly; nothing hardcoded (§15.8 doctrine).
 *
 *  - calendar_deck_slots            : Deck slot count below the grid (default 4).
 *  - calendar_grid_max_rows         : rows a grid cell shows before "+N" (default 4).
 *  - calendar_poll_seconds          : live-RAG light-poll interval (default 60).
 *  - calendar_category_groups (json): class → display-group map for aggregate chips.
 *  - calendar_default_layers  (json): which layer toggles start ON for new users.
 *  - calendar_default_deck_layouts (json): role → default ordered tile-id list.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('calendar_deck_slots')->nullable()->after('calendar_max_expansion_days');
            $table->unsignedTinyInteger('calendar_grid_max_rows')->nullable()->after('calendar_deck_slots');
            $table->unsignedSmallInteger('calendar_poll_seconds')->nullable()->after('calendar_grid_max_rows');
            $table->json('calendar_category_groups')->nullable()->after('calendar_poll_seconds');
            $table->json('calendar_default_layers')->nullable()->after('calendar_category_groups');
            $table->json('calendar_default_deck_layouts')->nullable()->after('calendar_default_layers');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn([
                'calendar_deck_slots',
                'calendar_grid_max_rows',
                'calendar_poll_seconds',
                'calendar_category_groups',
                'calendar_default_layers',
                'calendar_default_deck_layouts',
            ]);
        });
    }
};
