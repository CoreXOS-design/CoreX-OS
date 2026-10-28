<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk take-over hardening (.ai/specs/corex-desk.md section 14b):
 *  - control_request_id: a random id for each take-over request. The agent's Allow / Decline must quote it (so an Allow can only answer the question that
 *    was actually shown), and it is one input of the per-grant signing key.
 *  - control_ended_at / control_ended_tab / control_ended_support_user_id: when, for which tab and for which support person the last control ended, so the
 *    agent's last batch of "what I replayed" (flushed a moment AFTER control ended) is still recorded - marked late - inside a short grace window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desk_sessions')) {
            return;
        }
        Schema::table('desk_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('desk_sessions', 'control_request_id')) {
                $table->string('control_request_id', 32)->nullable();
            }
            if (! Schema::hasColumn('desk_sessions', 'control_ended_at')) {
                $table->timestamp('control_ended_at')->nullable();
            }
            if (! Schema::hasColumn('desk_sessions', 'control_ended_tab')) {
                $table->string('control_ended_tab', 12)->nullable();
            }
            if (! Schema::hasColumn('desk_sessions', 'control_ended_support_user_id')) {
                $table->unsignedBigInteger('control_ended_support_user_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('desk_sessions')) {
            return;
        }
        Schema::table('desk_sessions', function (Blueprint $table) {
            foreach (['control_ended_support_user_id', 'control_ended_tab', 'control_ended_at', 'control_request_id'] as $c) {
                if (Schema::hasColumn('desk_sessions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
