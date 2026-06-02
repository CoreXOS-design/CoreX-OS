<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agency Public API — per-(property × website) syndication state.
 *
 * The website is a Syndication Portal exactly like P24/PP. Because an agency
 * can run many websites (many keys), state is a pivot keyed by
 * (property_id, agency_api_key_id) rather than columns on `properties`.
 * Mirrors the pp_ and p24_ status/tracking fields. A listing reaches a given
 * website only when its row here is enabled.
 *
 * Spec: .ai/specs/agency-public-api.md §6.5.2
 */
class PropertyWebsiteSyndication extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $table = 'property_website_syndication';

    /** Status values mirror pp_syndication_status / p24_syndication_status. */
    public const STATUS_PENDING     = 'pending';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_ERROR       = 'error';

    protected $fillable = [
        'agency_id',
        'property_id',
        'agency_api_key_id',
        'enabled',
        'status',
        'last_submitted_at',
        'activated_at',
        'last_synced_at',
        'last_error',
    ];

    protected $casts = [
        'enabled'           => 'boolean',
        'last_submitted_at' => 'datetime',
        'activated_at'      => 'datetime',
        'last_synced_at'    => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(AgencyApiKey::class, 'agency_api_key_id');
    }
}
