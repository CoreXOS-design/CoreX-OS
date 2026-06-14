<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy versions (AT-29). Mirrors rmcp_versions, re-anchored from
 * agency-wide to per-policy via policy_id. One `active` version per
 * policy_id at a time. Governance block (board approval — not delegable)
 * + lifecycle. See spec §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('agency_policies')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title', 255)->default('Agency Policy');
            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft');

            // Governance (board approval cannot be delegated)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approver_title', 100)->nullable();
            $table->string('board_approval_document_path', 500)->nullable();
            $table->ipAddress('approval_ip')->nullable();
            $table->text('approval_notes')->nullable();

            // Lifecycle
            $table->date('effective_from')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_version_id')->nullable();
            $table->date('next_review_due')->nullable();

            $table->text('change_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['policy_id', 'version_number']);
            $table->index(['agency_id', 'status']);
            $table->index(['policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
    }
};
