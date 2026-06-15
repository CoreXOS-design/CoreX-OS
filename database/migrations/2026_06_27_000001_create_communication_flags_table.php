<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication flags (AT-36, triage addendum §4.2) — the per-agent, per-
 * identifier decision register for pending triage. Attributable record of every
 * discard/classification decision. NO message body/subject/headings are stored
 * here — only the identifier, optional name, attribution, AI verdict (Phase B),
 * and timestamps. POPIA-correct: retain the decision + accountability, not the
 * discarded content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'cf_agency_fk')->cascadeOnDelete();
            $table->string('identifier', 255);             // normalised number / lowercased email
            $table->string('identifier_name', 255)->nullable();
            $table->foreignId('user_id')->constrained('users', 'id', 'cf_user_fk')->cascadeOnDelete();
            $table->enum('flag', ['not_real_estate', 'real_estate']);

            // AI verdict at flag time — neutral reference for contradiction
            // detection. Populated by Phase B; nullable/unused in Phase A.
            $table->boolean('ai_is_real_estate')->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();

            $table->string('message_external_id', 255)->nullable(); // reference only; body not retained
            $table->timestamp('flagged_at');

            $table->timestamp('contradicted_at')->nullable();
            $table->foreignId('contradicted_by_user_id')->nullable()->constrained('users', 'id', 'cf_contradby_fk')->nullOnDelete();

            $table->enum('review_status', ['open', 'reviewed', 'actioned'])->default('open');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'identifier'], 'cf_agency_identifier_idx');
            $table->index(['agency_id', 'user_id', 'flag'], 'cf_agency_user_flag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_flags');
    }
};
