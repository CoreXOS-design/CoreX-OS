<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cached Ellie / AI-generated narratives.
 *
 * Cache key is composed deterministically per narrative_type + scope.
 * input_hash is a sha256 of the input data — mismatch forces regeneration
 * even if cache hasn't expired. Tokens + cost (ZAR) tracked per generation
 * for budgeting.
 *
 * `agency_id` is nullable: global narratives (cross-agency market briefs)
 * are valid in the shared-pool world.
 *
 * Spec: .ai/specs/mic-complete-spec.md §3.2.6.
 */
final class AINarrativeCache extends Model
{
    use SoftDeletes;

    protected $table = 'ai_narrative_cache';

    public const TYPE_WEEKLY_BRIEF    = 'weekly_brief';
    public const TYPE_TILE_COPY       = 'tile_copy';
    public const TYPE_LISTING_TOOLTIP = 'listing_tooltip';
    public const TYPE_SUBURB_POCKET   = 'suburb_pocket';
    public const TYPE_AUDIT_FINDING   = 'audit_finding';

    protected $fillable = [
        'agency_id',
        'narrative_type', 'cache_key', 'input_hash',
        'prompt_version', 'model',
        'input_tokens', 'output_tokens', 'cost_zar',
        'output_text', 'output_json',
        'generated_at', 'expires_at',
    ];

    protected $casts = [
        'output_json'   => 'array',
        'generated_at'  => 'datetime',
        'expires_at'    => 'datetime',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cost_zar'      => 'decimal:4',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function scopeFresh(Builder $q): Builder
    {
        return $q->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->where('expires_at', '<=', now());
    }

    public function scopeByType(Builder $q, string $type): Builder
    {
        return $q->where('narrative_type', $type);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
