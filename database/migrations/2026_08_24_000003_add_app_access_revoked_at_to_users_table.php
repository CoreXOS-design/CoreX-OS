<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App Access — gates ONLY the mobile app's Sanctum login, never the web
 * session. NULL (the default for every existing and new row) means access
 * is ON. Set to a timestamp when an agent taps "Delete my account" in the
 * mobile app (Apple guideline 5.1.1(v)) — see .ai/specs/mobile-app-access.md.
 *
 * Deliberately a nullable timestamp, not a boolean: it doubles as an audit
 * fact of WHEN access was revoked, same convention as
 * users.first_login_at / system_updates.published_at in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('app_access_revoked_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('app_access_revoked_at');
        });
    }
};
