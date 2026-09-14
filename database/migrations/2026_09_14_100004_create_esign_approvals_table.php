<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign compliance approval gate — spec §5.5. One row per hold; the decision ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('signature_template_id')->constrained('signature_templates')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('docuperfect_documents')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('pending'); // pending | approved | declined
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('is_override')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status']);
            $table->index(['signature_template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_approvals');
    }
};
