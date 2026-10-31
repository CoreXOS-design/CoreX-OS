<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk inactivity rule (.ai/specs/corex-desk.md section 4i): last_activity_at = the last moment anybody really did something in a live session
 * (input by the agent in CoreX or a shared tab, or anything recorded server-side). A heartbeat ping alone does not move it. NULL = never touched; the
 * sweep then falls back to joined_at / started_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desk_sessions') || Schema::hasColumn('desk_sessions', 'last_activity_at')) {
            return;
        }
        Schema::table('desk_sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('desk_sessions') || ! Schema::hasColumn('desk_sessions', 'last_activity_at')) {
            return;
        }
        Schema::table('desk_sessions', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
