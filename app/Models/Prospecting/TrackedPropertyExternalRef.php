<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per (agency, source_type, source_ref) pointing at the TrackedProperty
 * that source identifies. The audit-grade index of "what every external system
 * thinks this property is".
 *
 * Created/updated by TrackedPropertyMatchOrCreateService — never written directly.
 */
final class TrackedPropertyExternalRef extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'tracked_property_id',
        'source_type', 'source_ref', 'source_payload',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }
}
