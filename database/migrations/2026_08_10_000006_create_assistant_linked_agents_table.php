<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 multi-agent addendum, Prompt A.
 *
 * A Sub-Agent link: additional agents whose records an assistant may see and edit,
 * alongside (never instead of) the singular Main Agent on `assistant_assignments`.
 * A Sub-Agent contributes ZERO permissions and has NO matrix row — this table only
 * widens data breadth (User::dataIdentityIds()), never the permission ceiling.
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §2.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_linked_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_assignment_id')
                ->constrained('assistant_assignments', 'id', 'ala_assignment_fk')
                ->cascadeOnDelete();

            // RESTRICT — same reasoning as assistant_assignments.agent_user_id: this is an
            // audit-relevant link, CoreX never hard-deletes (NN #1), and `agent_user_id` is the
            // base column of the STORED generated column below, so MySQL forbids CASCADE/SET
            // NULL/SET DEFAULT on it (errno 1215).
            $table->foreignId('agent_user_id')
                ->constrained('users', 'id', 'ala_agent_fk')->restrictOnDelete();

            $table->foreignId('added_by_user_id')->nullable()
                ->constrained('users', 'id', 'ala_added_by_fk')->nullOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()
                ->constrained('users', 'id', 'ala_removed_by_fk')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Restorable one-active-link-per-agent — mirrors assistant_assignments'
            // active_assistant_user_id (spec §6.3 of the base spec). Declared inside
            // CREATE TABLE: adding a STORED generated column by ALTER later would force an
            // ALGORITHM=COPY rebuild MySQL 8 refuses on a table already carrying these FKs.
            $table->unsignedBigInteger('active_agent_user_id')
                ->nullable()
                ->storedAs('IF(deleted_at IS NULL, agent_user_id, NULL)');

            $table->unique(
                ['assistant_assignment_id', 'active_agent_user_id'],
                'ala_assignment_agent_unique'
            );

            $table->index('assistant_assignment_id');
            $table->index('agent_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_linked_agents');
    }
};
