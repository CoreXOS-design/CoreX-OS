<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ES-1 — Legal Block Audit Log.
 *
 * Insert-only forensic trail of every Template::isEsignBlocked() trigger.
 * No updated_at, no soft delete — log rows are immutable once written.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §5.5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_block_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('template_id');
            $table->string('template_name');
            $table->string('document_type_slug')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('block_reason', ['document_type_match', 'name_pattern_match']);
            $table->string('matched_pattern')->nullable();
            $table->json('request_context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_id', 'created_at']);
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_block_audit_log');
    }
};
