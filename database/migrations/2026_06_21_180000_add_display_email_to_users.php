<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-79 — optional outward-facing email override.
 *
 * When set, seller/client/public-facing surfaces render `display_email`
 * instead of the login `email`; auth/login always use the real `email`.
 * Null for everyone by default (no behaviour change). Interim mechanism for
 * a user operating multiple branch logins until multi-branch user support
 * lands (AT-80).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_email')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('display_email');
        });
    }
};
