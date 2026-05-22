<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — per-view event row for engagement analytics.
 *
 * Note: timestamps disabled (we use only created_at + manual viewed_at).
 * The tracking beacon updates the most-recent row for the link+fingerprint
 * pair (UPSERT semantics); the initial GET creates the row.
 */
final class PresentationSnapshotView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_link_id',
        'viewed_at',
        'ip_address',
        'user_agent',
        'fingerprint',
        'referrer_url',
        'duration_seconds',
        'scroll_depth_pct',
        'sections_viewed_json',
        'is_first_view',
        'flagged_fingerprint_mismatch',
        'created_at',
    ];

    protected $casts = [
        'viewed_at'                    => 'datetime',
        'created_at'                   => 'datetime',
        'duration_seconds'             => 'integer',
        'scroll_depth_pct'             => 'integer',
        'sections_viewed_json'         => 'array',
        'is_first_view'                => 'boolean',
        'flagged_fingerprint_mismatch' => 'boolean',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(PresentationSnapshotLink::class, 'snapshot_link_id');
    }
}
