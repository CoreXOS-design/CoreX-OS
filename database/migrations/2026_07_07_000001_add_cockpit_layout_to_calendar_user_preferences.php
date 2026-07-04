<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-164 cockpit v2 (adjustable) — per-user cockpit arrangement: the vertical
 * calendar/strip split height, per-tile column ratios, and the collapsed states of
 * the tile strip + right panel. Nullable JSON with code defaults (a user with no row,
 * or a null column, gets the role/agency default arrangement). DB-backed so the
 * arrangement follows the user across devices (not localStorage).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_user_preferences', function (Blueprint $table) {
            $table->json('calendar_cockpit')->nullable()->after('calendar_layers');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_user_preferences', function (Blueprint $table) {
            $table->dropColumn('calendar_cockpit');
        });
    }
};
