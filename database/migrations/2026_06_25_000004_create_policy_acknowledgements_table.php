<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy acknowledgements (AT-29) — the per-staff signed record. Mirrors
 * rmcp_acknowledgements, bound to a specific policy_version_id (the
 * mechanism that auto-re-fires sign-off when a new version is published).
 * branch_id is included from creation because the model uses
 * BelongsToBranch (RMCP got branch_id via a later migration). See spec §3.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('policy_id')->constrained('agency_policies')->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('policy_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('status', ['in_progress', 'completed', 'expired', 'superseded'])
                  ->default('in_progress');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->date('valid_until')->nullable();

            $table->string('signature_path', 500)->nullable();
            $table->string('signature_type', 50)->nullable();
            $table->string('typed_signature_name', 200)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_fingerprint', 100)->nullable();

            $table->text('declaration_text')->nullable();
            $table->unsignedInteger('sections_acknowledged_count')->default(0);
            $table->unsignedInteger('sections_total_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['policy_version_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['agency_id', 'status']);
            $table->index(['policy_id', 'status']);
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
    }
};
