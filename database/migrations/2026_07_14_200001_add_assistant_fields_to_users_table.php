<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistants, Prompt A.
 *
 * `is_assistant` is the resolver hook (see AssistantPermissionResolver, Prompt C).
 * It is NOT a role: an assistant's permissions come entirely from their
 * assignment matrix. The flag exists because `users.role` defaults to 'agent' —
 * a user created without an explicit role IS a full agent — so the resolver must
 * be able to identify an assistant without trusting the role column.
 *
 * `fica_required` gates the existing Compliance tab on My Portal for assistants
 * (spec §10). Note: `signature_requests.fica_required` already exists and means
 * something unrelated (the per-recipient e-sign gate). Different table, no conflict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_assistant')->default(false)->after('role');
            $table->boolean('fica_required')->default(true)->after('is_assistant');
            $table->index('is_assistant');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_assistant']);
            $table->dropColumn(['is_assistant', 'fica_required']);
        });
    }
};
