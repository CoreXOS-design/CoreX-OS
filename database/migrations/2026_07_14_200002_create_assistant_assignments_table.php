<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistants, Prompt A.
 *
 * The Assistant → Assigned Agent relationship. Spec §6.3.
 *
 * Terminology: the agent is the "Assigned Agent" (`agent_user_id`), NEVER the
 * "sponsor" — `users.sponsored_by_user_id` already exists and means the
 * commission mentor / revenue-share sponsor, which is an unrelated concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // RESTRICT, not CASCADE, on both user FKs — for two reasons:
            //
            // 1. Correctness: an assignment is an audit record ("this person did the
            //    agent's work between these dates"). Cascading it away on a user delete
            //    would silently destroy the trail. CoreX never hard-deletes (NN #1), so
            //    RESTRICT costs nothing and fails loud if anything ever tries.
            // 2. MySQL requires it: `assistant_user_id` is the base column of the STORED
            //    generated column below, and MySQL forbids CASCADE / SET NULL / SET DEFAULT
            //    referential actions on a generated column's base column. It reports this as
            //    a misleading errno 1215 "cannot add foreign key constraint".
            $table->foreignId('assistant_user_id')
                ->constrained('users', 'id', 'aa_assistant_fk')->restrictOnDelete();
            $table->foreignId('agent_user_id')
                ->constrained('users', 'id', 'aa_agent_fk')->restrictOnDelete();

            $table->enum('status', ['active', 'suspended', 'revoked'])->default('active');
            $table->string('suspend_reason', 190)->nullable();
            $table->timestamp('snapshot_taken_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', 'id', 'aa_created_by_fk')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()
                ->constrained('users', 'id', 'aa_revoked_by_fk')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 190)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One active Assigned Agent per Assistant — enforced by the DATABASE, not by a
            // controller check that can lose a race. MySQL has no partial index, so this STORED
            // generated column carries the assistant id ONLY while the row is live; the unique
            // key on it therefore constrains active rows only, and lets any number of
            // revoked / suspended / soft-deleted rows coexist as the audit trail.
            //
            // It is declared inside CREATE TABLE deliberately. Adding a STORED generated column
            // by ALTER forces an ALGORITHM=COPY table rebuild, which MySQL 8 refuses on a table
            // already carrying these six FKs (it reports a misleading errno 1215 "cannot add
            // foreign key constraint" with no FK actually at fault).
            $table->unsignedBigInteger('active_assistant_user_id')
                ->nullable()
                ->storedAs("IF(status = 'active' AND deleted_at IS NULL, assistant_user_id, NULL)");

            $table->unique('active_assistant_user_id', 'assistant_one_active_agent');

            $table->index(['agency_id', 'status']);
            $table->index(['agent_user_id', 'status']);
            $table->index(['assistant_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_assignments');
    }
};
