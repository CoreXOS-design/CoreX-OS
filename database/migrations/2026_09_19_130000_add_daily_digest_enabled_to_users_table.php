<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-422 — per-user switch for the single daily digest email (calendar items +
 * birthdays, corex:calendar:send-digests). An admin turns it off for ONE user from
 * Admin → Users → <user> → Actions; the digest job then skips that user entirely.
 *
 * Default TRUE: every existing user keeps receiving it exactly as today. Guarded so
 * a re-run after a partial failure is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'daily_digest_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('daily_digest_enabled')->default(true)->after('exclude_from_p24');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'daily_digest_enabled')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('daily_digest_enabled');
        });
    }
};
