<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * client_users.email carried a plain global-unique index. Soft-deleting a
 * ClientUser (e.g. the Apple 5.1.1(v) account-deletion endpoint) does not
 * free that email at the DB level — the deleted row still occupies the
 * unique slot, so a genuine re-signup (or a fresh agent-created login) with
 * the same email hits a 1062 duplicate-entry error.
 *
 * Fix: replace the blanket unique(email) with a unique index on a generated
 * column that is NULL whenever the row is soft-deleted. MySQL treats every
 * NULL in a unique index as distinct from every other NULL, so any number
 * of soft-deleted rows may share an email while at most one ACTIVE row may
 * hold it — which is the actual invariant every call site already assumes
 * (all lookups go through Eloquent's SoftDeletes scope).
 *
 * Spec: .ai/specs/client-auth.md — "Account deletion".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });

        DB::statement(
            "ALTER TABLE client_users ADD COLUMN active_email VARCHAR(255) " .
            "GENERATED ALWAYS AS (IF(deleted_at IS NULL, email, NULL)) VIRTUAL"
        );

        Schema::table('client_users', function (Blueprint $table) {
            $table->unique('active_email', 'client_users_active_email_unique');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('client_users', function (Blueprint $table) {
            $table->dropUnique('client_users_active_email_unique');
            $table->dropIndex(['email']);
            $table->dropColumn('active_email');
            $table->unique('email');
        });
    }
};
