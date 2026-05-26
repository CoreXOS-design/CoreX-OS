<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cross-agency access request — the ONLY model in CoreX that intentionally
 * crosses agency boundaries. Do NOT add BelongsToAgency / AgencyScope.
 * Queries must always be bounded by target_agency_id or requester_user_id.
 *
 * See .ai/specs/agency-access-authorization-spec.md.
 */
class AgencyAccessRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DENIED    = 'denied';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const PENDING_TTL_MINUTES = 5;
    public const GRANT_HOURS         = 24;

    protected $fillable = [
        'target_agency_id',
        'requester_user_id',
        'requester_role',
        'status',
        'reason',
        'denial_reason',
        'authorized_by_user_id',
        'authorized_at',
        'expires_at',
        'granted_session_expires_at',
    ];

    protected $casts = [
        'authorized_at'              => 'datetime',
        'expires_at'                 => 'datetime',
        'granted_session_expires_at' => 'datetime',
    ];

    // ── Relationships ──

    public function targetAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'target_agency_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function targetedAdmins(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'agency_access_request_admins',
            'request_id',
            'admin_user_id'
        )->withTimestamps();
    }

    // ── Scopes ──

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeNotExpired($q)
    {
        return $q->where('expires_at', '>', now());
    }

    public function scopeForAgency($q, int $agencyId)
    {
        return $q->where('target_agency_id', $agencyId);
    }

    public function scopeByRequester($q, int $userId)
    {
        return $q->where('requester_user_id', $userId);
    }

    // ── State checks ──

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool  { return $this->status === self::STATUS_APPROVED; }
    public function isDenied(): bool    { return $this->status === self::STATUS_DENIED; }
    public function isExpired(): bool   { return $this->status === self::STATUS_EXPIRED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    public function isLive(): bool
    {
        return $this->isApproved()
            && $this->granted_session_expires_at
            && $this->granted_session_expires_at->isFuture();
    }

    // ── State transitions ──

    public function markApproved(int $adminId): bool
    {
        return (bool) $this->update([
            'status'                     => self::STATUS_APPROVED,
            'authorized_by_user_id'      => $adminId,
            'authorized_at'              => now(),
            'granted_session_expires_at' => now()->addHours(self::GRANT_HOURS),
        ]);
    }

    public function markDenied(int $adminId, ?string $reason = null): bool
    {
        return (bool) $this->update([
            'status'                => self::STATUS_DENIED,
            'authorized_by_user_id' => $adminId,
            'authorized_at'         => now(),
            'denial_reason'         => $reason,
        ]);
    }

    public function markExpired(): bool
    {
        return (bool) $this->update(['status' => self::STATUS_EXPIRED]);
    }

    public function markCancelled(): bool
    {
        return (bool) $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
