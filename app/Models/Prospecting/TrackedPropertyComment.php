<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Agency-wide comment on a TrackedProperty. Keyed to tracked_property_id (the
 * enduring spine, CLAUDE.md Non-negotiable #10) so it survives relisting,
 * claim churn, and portal-ref rotation — unlike the claim-scoped free-text
 * log on ProspectingClaim::notes.
 *
 * Spec: .ai/specs/mic-property-row-comments.md
 */
class TrackedPropertyComment extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'tracked_property_id',
        'user_id',
        'body',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
