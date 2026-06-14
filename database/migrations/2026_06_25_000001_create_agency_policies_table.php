<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Policy registry (AT-29).
 *
 * The generic anchor that makes staff policy sign-off a framework rather
 * than a per-policy clone. Each row is one governing document for an
 * agency, identified by a stable machine `policy_key`. The Communication
 * & Marketing Compliance Policy is instance #1 (policy_key =
 * 'communication_marketing'). See .ai/specs/claude_policy_acknowledgement_spec.md §3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('policy_key', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'policy_key']);
            $table->index(['agency_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_policies');
    }
};
