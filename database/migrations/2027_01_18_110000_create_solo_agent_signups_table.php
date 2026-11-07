<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solo Agent Accounts, build step 4 - the sign-up record (one per single-agent agency) and its status timeline.
 * Spec: .ai/specs/solo-agent-accounts.md section 9. Platform-owned (CoreX team only) like agency_timelines:
 * no agency scoping; only the owner-only Solo Agents section reads it.
 *
 * Suspension reuses agencies.suspended_at; archive is this row's own soft delete (no hard deletes, NN #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('solo_agent_signups')) {
            Schema::create('solo_agent_signups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->unique()->constrained('agencies');
                $table->foreignId('user_id')->constrained('users');
                $table->string('status', 20)->default('awaiting_contract')->index(); // awaiting_contract | active
                $table->string('ffc_number', 50)->nullable()->index();
                $table->date('ffc_expires_on')->nullable()->index();
                $table->unsignedBigInteger('agreement_document_id')->nullable()->index(); // platform_esign_documents.id
                $table->timestamp('contract_sent_at')->nullable();
                $table->timestamp('contract_signed_at')->nullable();
                // True when the account was already suspended at the moment it was archived, so Restore does not reopen it.
                $table->boolean('suspended_before_archive')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('solo_agent_status_events')) {
            Schema::create('solo_agent_status_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('signup_id')->constrained('solo_agent_signups');
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);   // awaiting_contract | active | suspended | archived
                $table->string('event', 30);       // created | contract_sent | contract_signed | suspended | unsuspended | archived | restored | edited | setup_email_sent
                $table->foreignId('actor_user_id')->nullable()->constrained('users');
                $table->string('reason', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['signup_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('solo_agent_status_events');
        Schema::dropIfExists('solo_agent_signups');
    }
};
