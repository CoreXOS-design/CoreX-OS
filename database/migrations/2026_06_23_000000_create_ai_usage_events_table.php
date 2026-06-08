<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified AI cost ledger — `ai_usage_events` (spec ai-cost-ledger.md §3.2.8).
 *
 * Append-only ledger: every successful or degraded Anthropic call across CoreX
 * writes exactly one row here. Replaces `ai_narrative_cache` as the single
 * source of truth for AI spend, so the /admin/ai-usage dashboard and the
 * per-agency budget cap see EVERY surface — MIC narratives, mobile voice,
 * image analysis, DocuPerfect, marketing copy, presentation evidence — not
 * just the MIC narrative gateway.
 *
 * Append-only by design: no `updated_at`, no soft delete (mirrors
 * `agent_activity_events`). Retention is handled by PurgeOldAiUsageEventsJob
 * (13-month rolling window), not user-facing deletes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->comment('Append-only AI cost ledger — one row per Anthropic call, every surface. Source of truth for spend + budget.');

            $table->id();

            // Nullable: global/system calls (e.g. cross-agency market brief)
            // carry agency_id = null. Tenant-scoped via AgencyScope on the model.
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            // Nullable: queued/system jobs have no request user.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('source', 40)
                  ->comment('Stable surface key — mic_narrative | mobile_voice | image_analysis | docuperfect_* | marketing_copy | presentation_evidence');

            $table->string('surface_ref', 120)->nullable()
                  ->comment('Correlation handle for drill-down — cache_key, analysis_id, template_id, etc.');

            $table->string('model', 60)
                  ->comment('Resolved model id, e.g. claude-haiku-4-5-20251001. " (fallback)" suffix mirrors the cache convention.');

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_zar', 10, 4)->default(0);

            $table->boolean('cache_hit')->default(false)
                  ->comment('True when served from cache (no API spend). Drives the exact cache-hit-rate metric.');
            $table->boolean('fallback')->default(false)
                  ->comment('True when the call degraded to fallback text (cost 0).');

            $table->timestamp('occurred_at')->comment('When the call happened.');
            // Append-only log: created_at only, no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'occurred_at'], 'idx_aue_agency_occurred');
            $table->index(['source', 'occurred_at'], 'idx_aue_source_occurred');
            $table->index('occurred_at', 'idx_aue_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
