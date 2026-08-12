<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT — Agency admin email-only invite (.ai/specs/agency-admin-rule.md §R1b).
 *
 * `first_login_at` is the "has this account ever completed a successful login"
 * marker used to fire the agency-onboarding-setup email + welcome pop-up exactly
 * once, on the invited Admin's first sign-in. Every EXISTING user is backfilled to
 * their own `created_at` in the same migration — without this, every current user
 * would trip the "first login" branch on their next sign-in, which is wrong (they
 * are already operating, not newly invited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('first_login_at')->nullable()->after('invited_at');
        });

        DB::table('users')->whereNull('first_login_at')->update([
            'first_login_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('first_login_at');
        });
    }
};
