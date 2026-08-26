<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One accepted POST /api/v1/website/listings/stats.
 *
 * The row exists FIRST and FOR the idempotency guard — its unique
 * (agency_id, site, batch_id) is what makes a replayed batch a no-op. The
 * counters on it are also the reply the replay gets, so the website sees the
 * same {accepted, skipped} it saw the first time.
 *
 * Spec: .ai/specs/website-listing-stats.md §4.1
 */
class WebsiteStatBatch extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'agency_api_key_id',
        'site',
        'batch_id',
        'source',
        'listing_count',
        'accepted_count',
        'skipped_count',
        'skipped_listing_ids',
        'metric_row_count',
        'generated_at',
        'received_at',
    ];

    protected $casts = [
        'skipped_listing_ids' => 'array',
        'listing_count'       => 'integer',
        'accepted_count'      => 'integer',
        'skipped_count'       => 'integer',
        'metric_row_count'    => 'integer',
        'generated_at'        => 'datetime',
        'received_at'         => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AgencyApiKey::class, 'agency_api_key_id');
    }
}
