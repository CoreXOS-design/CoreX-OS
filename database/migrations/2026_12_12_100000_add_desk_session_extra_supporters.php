<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk (.ai/specs/corex-desk.md section 4b): a live session can be re-opened by the support person who joined it (a reload, a closed
 * window) and joined by a second support person of the same scope. The first to join stays `support_user_id`; anyone else who attached is in
 * `support_user_ids`. `control_support_user_id` is who asked for the current take-over (so the audit names the right person).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('desk_sessions', 'support_user_ids')) {
                $table->json('support_user_ids')->nullable()->after('support_user_id');
                $table->unsignedBigInteger('control_support_user_id')->nullable()->after('control_tab');
            }
        });
    }

    public function down(): void
    {
        Schema::table('desk_sessions', function (Blueprint $table) {
            foreach (['control_support_user_id', 'support_user_ids'] as $c) {
                if (Schema::hasColumn('desk_sessions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
