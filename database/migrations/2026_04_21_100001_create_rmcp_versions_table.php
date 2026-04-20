<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rmcp_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title', 255)->default('Risk Management and Compliance Programme');
            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft');

            // Governance (GN 7A Sept 2025 — board approval cannot be delegated)
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

            $table->unique(['agency_id', 'version_number']);
            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rmcp_versions');
    }
};
