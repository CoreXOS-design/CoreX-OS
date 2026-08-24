<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). The approval tier: created when a deeds capture
 * matches an existing off-market property whose age falls in the admin/BM-approval
 * band. Nothing is promoted or reassigned until an admin/BM decides.
 */
class PropertyTakeRequest extends Model
{
    use BelongsToAgency;

    protected $table = 'property_take_requests';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id', 'tracked_property_id', 'property_id', 'requested_by_user_id',
        'status', 'age_days', 'date_field_used', 'date_is_fallback', 'matched_property_status',
        'decided_by_user_id', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'age_days' => 'integer',
        'date_is_fallback' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
