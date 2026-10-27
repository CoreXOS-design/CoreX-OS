<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk phase 2 (take-over, .ai/specs/corex-desk.md section 14):
 *  - desk_sessions carries the live state of the consent flow (none -> requested -> granted -> none) and which tab it is for
 *    ("main" = the CoreX page, "x<id>" = a shared extension tab), plus when it was asked / allowed / last used (the idle clock);
 *  - agencies.desk_control_idle_minutes - how long a granted control may sit unused before it ends by itself (default 5).
 * The audit of every request, answer and replayed action lives in desk_session_events (new event types, no schema change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('desk_sessions', 'control_state')) {
                $table->string('control_state', 12)->default('none')->after('leaving_at');   // none | requested | granted
                $table->string('control_tab', 12)->nullable()->after('control_state');
                $table->timestamp('control_requested_at')->nullable()->after('control_tab');
                $table->timestamp('control_granted_at')->nullable()->after('control_requested_at');
                $table->timestamp('control_last_action_at')->nullable()->after('control_granted_at');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'desk_control_idle_minutes')) {
                $table->unsignedSmallInteger('desk_control_idle_minutes')->default(5);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'desk_control_idle_minutes')) {
                $table->dropColumn('desk_control_idle_minutes');
            }
        });
        Schema::table('desk_sessions', function (Blueprint $table) {
            foreach (['control_last_action_at', 'control_granted_at', 'control_requested_at', 'control_tab', 'control_state'] as $c) {
                if (Schema::hasColumn('desk_sessions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
