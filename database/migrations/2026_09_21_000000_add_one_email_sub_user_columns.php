<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-423 — One email (sub-users). Spec: .ai/specs/one-email-sub-users.md §4.1.
 *
 * - agencies.one_email_enabled — the agency switch, on its own card in Company Settings
 *   (deliberately NOT on the Settings → Features page — Andre, 2026-09-21: it is a big change).
 * - agencies.one_email_user_id — the agency's ONE main account: an existing
 *   normal user whose real email is the shared inbox every sub-user's mail goes to.
 * - users.is_sub_user — the person signs in with a username (stored in the
 *   unique users.email sign-in column) and their mail goes to the shared inbox.
 * - users.must_change_password — set when an admin gives a sub-user a
 *   temporary password; the next sign-in must choose a new one.
 *
 * Every existing row gets false / NULL, so nothing changes for anyone today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('one_email_enabled')->default(false);
            $table->unsignedBigInteger('one_email_user_id')->nullable();
            $table->foreign('one_email_user_id')->references('id')->on('users');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sub_user')->default(false);
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_sub_user', 'must_change_password']);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropForeign(['one_email_user_id']);
            $table->dropColumn(['one_email_user_id', 'one_email_enabled']);
        });
    }
};
