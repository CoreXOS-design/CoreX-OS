<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — assistant activity log.
 *
 * An append-only record of what an assistant DID: which property/contact/deal
 * they OPENED, EDITED or DELETED, and when. It exists so the agent can see, on
 * their My Assistants → Activity tab, exactly what the person acting on their
 * behalf has been doing — a plain "are they doing anything they shouldn't?"
 * audit trail.
 *
 * Written by App\Http\Middleware\LogAssistantActivity for assistant requests
 * only (is_assistant = 1), so volume stays low. Immutable — no updates, no
 * user-facing delete (append-only audit, like property_audit_log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_assignment_id')
                ->constrained('assistant_assignments', 'id', 'aal_assignment_fk')
                ->cascadeOnDelete();
            $table->foreignId('assistant_user_id')
                ->constrained('users', 'id', 'aal_assistant_fk')->cascadeOnDelete();
            // The Assigned Agent this work was done on behalf of. Nullable + nullOnDelete,
            // mirroring the on_behalf_of_user_id columns (spec §11).
            $table->foreignId('agent_user_id')
                ->nullable()->constrained('users', 'id', 'aal_agent_fk')->nullOnDelete();

            $table->string('action', 20);          // opened | edited | created | deleted
            $table->string('subject_type', 40)->nullable(); // property | contact | deal
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 190)->nullable(); // denormalised human label
            $table->string('route_name', 120)->nullable();
            $table->string('url', 300)->nullable();
            $table->string('method', 10)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['assistant_assignment_id', 'created_at'], 'aal_assignment_time_idx');
            $table->index(['assistant_user_id', 'created_at'], 'aal_assistant_time_idx');
            $table->index(['subject_type', 'subject_id'], 'aal_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_activity_log');
    }
};
