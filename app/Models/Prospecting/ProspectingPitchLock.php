<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Soft, time-bounded lock that blocks other agents from pitching a prospecting
 * listing while one agent is actively composing a pitch.
 *
 * Lifecycle:
 *   created  → an agent clicks "Pitch Seller" on the prospecting tab
 *   released → the pitch composer submits successfully (release_reason='consumed_by_send')
 *              OR the lock's expires_at passes with no submit (release_reason='auto_expired')
 *              OR a BM/admin manually releases (release_reason='manual_release')
 *
 * The single-active-lock-per-listing invariant is enforced in ProspectingClaimService
 * via SELECT ... FOR UPDATE inside a transaction (MySQL does not support partial unique
 * indexes WHERE released_at IS NULL).
 */
final class ProspectingPitchLock extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'prospecting_listing_id',
        'tracked_property_id',
        'user_id',
        'locked_at',
        'expires_at',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'locked_at'   => 'datetime',
        'expires_at'  => 'datetime',
        'released_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->released_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
