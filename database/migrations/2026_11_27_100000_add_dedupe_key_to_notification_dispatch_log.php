<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-492 - the cooldown ("same alert type about the same work order, within 6 hours") swallowed a REVISED quote, a changed
 * answer and a second finish report, because the work order is the subject of all of them. A caller that knows what makes
 * one occurrence different from the last (the quote id, the completion round id) now passes it as `dedupe_key`; the
 * cooldown and the "already told" check then compare it as well. Null (every existing caller) behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_dispatch_log', 'dedupe_key')) {
            return;
        }

        Schema::table('notification_dispatch_log', function (Blueprint $table) {
            $table->string('dedupe_key', 120)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_dispatch_log', 'dedupe_key')) {
            Schema::table('notification_dispatch_log', function (Blueprint $table) {
                $table->dropColumn('dedupe_key');
            });
        }
    }
};
