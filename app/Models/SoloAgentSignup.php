<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The CoreX team's record of one single-agent account: .ai/specs/solo-agent-accounts.md section 9.
 *
 * Platform-owned like AgencyTimeline - deliberately NOT agency-scoped. Only the owner-only Solo Agents
 * section (and the activation listener) ever reads it; no agency user has a route to it. The agent's own
 * data stays isolated by AgencyScope as for any agency.
 *
 * Status shown to people is DERIVED (see displayStatus()): archived (this row soft-deleted) beats suspended
 * (agencies.suspended_at) beats the stored contract status.
 */
class SoloAgentSignup extends Model
{
    use SoftDeletes;

    public const STATUS_AWAITING_CONTRACT = 'awaiting_contract';
    public const STATUS_ACTIVE            = 'active';
    public const STATUS_SUSPENDED         = 'suspended';
    public const STATUS_ARCHIVED          = 'archived';

    public const DISPLAY_STATUSES = [
        self::STATUS_AWAITING_CONTRACT => 'Awaiting contract',
        self::STATUS_ACTIVE            => 'Active',
        self::STATUS_SUSPENDED         => 'Suspended',
        self::STATUS_ARCHIVED          => 'Archived',
    ];

    protected $fillable = [
        'agency_id', 'user_id', 'status', 'ffc_number', 'ffc_expires_on', 'agreement_document_id',
        'contract_sent_at', 'contract_signed_at', 'suspended_before_archive', 'created_by',
    ];

    protected $casts = [
        'ffc_expires_on'           => 'date',
        'contract_sent_at'         => 'datetime',
        'contract_signed_at'       => 'datetime',
        'suspended_before_archive' => 'boolean',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class)->withoutGlobalScopes()->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes()->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SoloAgentStatusEvent::class, 'signup_id')->orderByDesc('id');
    }

    /** archived | suspended | awaiting_contract | active - what the owner sees. */
    public function displayStatus(): string
    {
        if ($this->trashed()) {
            return self::STATUS_ARCHIVED;
        }
        if ($this->agency?->isSuspended()) {
            return self::STATUS_SUSPENDED;
        }

        return $this->status === self::STATUS_ACTIVE ? self::STATUS_ACTIVE : self::STATUS_AWAITING_CONTRACT;
    }

    public function displayStatusLabel(): string
    {
        return self::DISPLAY_STATUSES[$this->displayStatus()];
    }

    /**
     * Why this person may not sign in yet / any more because of their single-agent account, or null when the
     * account is open. Used by the login gates so a forgotten-password reset can never skip the signed agreement.
     */
    public static function loginBlockedReason(?User $user): ?string
    {
        if (! $user || ! $user->agency_id || ! Agency::idIsSolo((int) $user->agency_id)) {
            return null;
        }

        $signup = static::withTrashed()->where('agency_id', $user->agency_id)->first();
        if (! $signup) {
            return null;
        }
        if ($signup->trashed()) {
            return 'This single-agent account has been closed. Please contact CoreX support.';
        }
        if ($signup->status !== self::STATUS_ACTIVE) {
            return 'Your single-agent account is waiting for your signed agreement. We will email you as soon as it is in place.';
        }

        return null;
    }
}
