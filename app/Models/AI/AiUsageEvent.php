<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only AI cost ledger row — one per Anthropic call, every surface.
 *
 * The single source of truth for AI spend. `AICostAggregator`, the
 * /admin/ai-usage dashboard, and `Agency::aiBudgetUsedZar()` all read this
 * table, so spend visibility and budget enforcement cover every AI surface
 * in CoreX, not just the MIC narrative gateway.
 *
 * Append-only: no SoftDeletes, no `updated_at`. Retention is enforced by
 * PurgeOldAiUsageEventsJob (13-month window), not user-facing deletes — so
 * non-negotiable #1 (no hard deletes of business/audit data) is honoured by
 * the absence of any delete affordance, not by SoftDeletes.
 *
 * Tenancy (documented deviation from non-negotiable #7 — see spec §5):
 * this ledger intentionally does NOT use BelongsToAgency/AgencyScope, exactly
 * like the `ai_narrative_cache` table it supersedes as the cost source. The
 * global scope would (a) filter out NULL-agency global narrative rows as
 * orphans, hiding genuine spend, and (b) scope the admin dashboard to one
 * agency, breaking its cross-agency "top consumers" view. Tenancy is instead
 * enforced at WRITE time: AiUsageRecorder always stamps the correct agency_id.
 * The only reader is the `mic.view_ai_costs`-gated admin surface (cross-agency
 * by design) and `Agency::aiBudgetUsedZar()` (explicit agency filter).
 *
 * Spec: .ai/specs/ai-cost-ledger.md §3.2.8.
 */
final class AiUsageEvent extends Model
{
    protected $table = 'ai_usage_events';

    /** Append-only: created_at only, no updated_at. */
    public const UPDATED_AT = null;

    // ── Source registry (spec §3.1) ──────────────────────────────────────
    public const SOURCE_MIC_NARRATIVE         = 'mic_narrative';
    public const SOURCE_MOBILE_VOICE          = 'mobile_voice';
    public const SOURCE_IMAGE_ANALYSIS        = 'image_analysis';
    public const SOURCE_DOCUPERFECT_IMPORT    = 'docuperfect_import';
    public const SOURCE_DOCUPERFECT_FIELD_MAP = 'docuperfect_field_map';
    public const SOURCE_DOCUPERFECT_VISION    = 'docuperfect_vision';
    public const SOURCE_MARKETING_COPY        = 'marketing_copy';
    public const SOURCE_PRESENTATION_EVIDENCE = 'presentation_evidence';

    protected $fillable = [
        'agency_id',
        'user_id',
        'source',
        'surface_ref',
        'model',
        'input_tokens',
        'output_tokens',
        'cost_zar',
        'cache_hit',
        'fallback',
        'occurred_at',
    ];

    protected $casts = [
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cost_zar'      => 'decimal:4',
        'cache_hit'     => 'boolean',
        'fallback'      => 'boolean',
        'occurred_at'   => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
