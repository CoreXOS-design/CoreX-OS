<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-118 — Flow A request/grant for the Communications Access Gate.
 * Forked from AgencyAccessRequest, but within-agency (BelongsToAgency) and
 * scoped to ONE contact's threads. See .ai/specs/at118-communications-access-gate.md §3.3.
 */
class CommsAccessRequest extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_REVOKED  = 'revoked';

    // AT-132 Wave 1 — grant modes. 'session' = end-of-day cap + midnight reset +
    // logout revoke (the AT-118 behaviour). 'always' = permanent for that thread
    // (skipped by the resets — wired in Step 2). 'otp' is RESERVED for Wave 2
    // (AT-130 break-glass) and is intentionally NOT defined as usable here.
    public const MODE_SESSION = 'session';
    public const MODE_ALWAYS  = 'always';

    protected $fillable = [
        'agency_id', 'contact_id', 'thread_key', 'communication_id',
        'requester_user_id', 'status', 'grant_mode', 'reason',
        'denial_reason', 'authorized_by_user_id', 'authorized_at',
        'expires_at', 'granted_session_expires_at', 'revoked_at', 'revoked_reason',
        'session_id',
    ];

    protected $casts = [
        'authorized_at'              => 'datetime',
        'expires_at'                 => 'datetime',
        'granted_session_expires_at' => 'datetime',
        'revoked_at'                 => 'datetime',
    ];

    // ── Relationships ──

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    /**
     * AT-132 — the specific communication a NULL-thread grant is keyed on (when
     * thread_key is null/empty, the grant scopes to this single comm). Nullable.
     */
    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }

    // ── Scopes ──

    public function scopePending($q)
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeForContact($q, int $contactId)
    {
        return $q->where('contact_id', $contactId);
    }

    public function scopeByRequester($q, int $userId)
    {
        return $q->where('requester_user_id', $userId);
    }

    /** Approved grants that have not been revoked and have not hit their hard expiry. */
    public function scopeLiveGrant($q)
    {
        return $q->where('status', self::STATUS_APPROVED)
            ->whereNull('revoked_at')
            ->where('granted_session_expires_at', '>', now());
    }

    // ── State ──

    public function isPending(): bool  { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isDeclined(): bool { return $this->status === self::STATUS_DECLINED; }

    public function isLiveGrant(): bool
    {
        return $this->isApproved()
            && $this->revoked_at === null
            && $this->granted_session_expires_at
            && $this->granted_session_expires_at->isFuture();
    }

    // ── Transitions (the service orchestrates + logs; these are the raw flips) ──

    public function markApproved(int $approverId): bool
    {
        return (bool) $this->update([
            'status'                     => self::STATUS_APPROVED,
            'authorized_by_user_id'      => $approverId,
            'authorized_at'              => now(),
            // Hard cap = end of the current (agency-local) day. The 00:00 reset
            // job revokes proactively + logs; this guarantees it can't outlive
            // the day even if the job never runs.
            'granted_session_expires_at' => now()->endOfDay(),
        ]);
    }

    public function markDeclined(int $approverId, ?string $reason = null): bool
    {
        return (bool) $this->update([
            'status'                => self::STATUS_DECLINED,
            'authorized_by_user_id' => $approverId,
            'authorized_at'         => now(),
            'denial_reason'         => $reason,
        ]);
    }

    public function markRevoked(string $reason): bool
    {
        return (bool) $this->update([
            'status'         => self::STATUS_REVOKED,
            'revoked_at'     => now(),
            'revoked_reason' => $reason,
        ]);
    }

    public function markExpired(): bool
    {
        return (bool) $this->update(['status' => self::STATUS_EXPIRED]);
    }
}
