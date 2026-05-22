<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Part A2 — sticky-default fields for the Send modal.
 *
 * The send service updates these after every batch so the next open of
 * the modal pre-selects the channel + mode the agent last used. Matches
 * the existing column-on-users pattern (e.g. theme, agent_photo_path).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'last_presentation_send_channel')) {
                $table->string('last_presentation_send_channel', 20)->nullable()
                    ->after('theme');
            }
            if (!Schema::hasColumn('users', 'last_presentation_send_mode')) {
                $table->string('last_presentation_send_mode', 10)->nullable()
                    ->after('last_presentation_send_channel');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['last_presentation_send_mode', 'last_presentation_send_channel'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
