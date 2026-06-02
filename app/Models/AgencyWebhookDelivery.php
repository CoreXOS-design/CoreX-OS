<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agency Public API — one row per webhook send attempt to an agency website.
 *
 * Enables retry-with-backoff and a per-key delivery history view, and lets the
 * UI surface a dead endpoint after repeated failures.
 *
 * Spec: .ai/specs/agency-public-api.md §3.2, §6.2
 */
class AgencyWebhookDelivery extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $table = 'agency_webhook_deliveries';

    protected $fillable = [
        'agency_id',
        'agency_api_key_id',
        'event_name',
        'payload',
        'response_status',
        'attempts',
        'delivered_at',
        'next_retry_at',
        'failed_at',
        'last_error',
    ];

    protected $casts = [
        'payload'         => 'array',
        'response_status' => 'integer',
        'attempts'        => 'integer',
        'delivered_at'    => 'datetime',
        'next_retry_at'   => 'datetime',
        'failed_at'       => 'datetime',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AgencyApiKey::class, 'agency_api_key_id');
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function isFailed(): bool
    {
        return $this->failed_at !== null;
    }
}
