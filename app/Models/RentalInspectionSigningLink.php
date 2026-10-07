<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §46 — a personal signing link: one party (a tenant, the landlord, or the agent) of
 * one inspection. Opens the full report read-only; a tenant or landlord can sign (or decline / dispute) from it.
 *
 * A link is never deleted. Revoking stamps `revoked_at`; replacing a link revokes the old row and issues a new one, so
 * the history of who was sent what survives. The LIVE link for a party is the newest row that is neither revoked nor
 * expired (RentalInspectionSigningLinkService::liveLinkFor()).
 *
 * Lookup by token is scope-free on purpose (the caller has no CoreX session — the token is the authority), the same
 * pattern as RentalInspection::findByPublicToken(), and refuses an archived or cancelled inspection the same way (§44a).
 */
class RentalInspectionSigningLink extends Model
{
    use BelongsToAgency;

    public const ROLE_TENANT = 'tenant';
    public const ROLE_LANDLORD = 'landlord';
    public const ROLE_AGENT = 'agent';

    public const OUTCOME_SIGNED = 'signed';
    public const OUTCOME_DECLINED = 'declined';

    /** How the link reached the party — what the agent did with it. */
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_COPIED = 'copied';
    public const CHANNEL_QR = 'qr';
    public const CHANNEL_DEVICE = 'device';

    public const STATUS_NOT_SENT = 'not_sent';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'agency_id', 'rental_inspection_id', 'party_role', 'party_contact_id', 'party_user_id',
        'token', 'expires_at',
        'last_sent_at', 'send_count', 'last_sent_channel', 'last_sent_to', 'last_send_status', 'last_send_error',
        'first_opened_at', 'last_opened_at', 'open_count',
        'outcome', 'outcome_at', 'signature_id',
        'revoked_at', 'revoked_by_user_id', 'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'outcome_at' => 'datetime',
        'revoked_at' => 'datetime',
        'send_count' => 'integer',
        'open_count' => 'integer',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id')->withTrashed();
    }

    public function partyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'party_contact_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionSignature::class, 'signature_id');
    }

    /** The one word the agent's screen shows for this link. */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }
        if ($this->outcome === self::OUTCOME_SIGNED) {
            return self::STATUS_SIGNED;
        }
        if ($this->outcome === self::OUTCOME_DECLINED) {
            return self::STATUS_DECLINED;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }
        if ($this->first_opened_at !== null) {
            return self::STATUS_OPENED;
        }
        if ($this->last_sent_at !== null) {
            return self::STATUS_SENT;
        }

        return self::STATUS_NOT_SENT;
    }

    /** Neither revoked nor expired — the link opens. (A signed link still opens, read-only.) */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** Live, and the party has not yet signed or declined through it. */
    public function canAcceptSignature(): bool
    {
        return $this->isLive() && $this->outcome === null;
    }

    public function url(): string
    {
        return route('rental-inspections.sign.show', $this->token);
    }

    /**
     * Resolve a token to a link the holder may open: not revoked, not expired, and its inspection neither archived nor
     * cancelled. Scope-free — the token is the authority. Null for everything else, so a wrong, revoked, expired,
     * archived or cancelled link is one uniform "not available" page to a stranger (§44a).
     */
    public static function findLiveByToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > 64) {
            return null;
        }

        $link = static::withoutGlobalScopes()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if (! $link) {
            return null;
        }

        $inspection = RentalInspection::withoutGlobalScopes()
            ->whereKey($link->rental_inspection_id)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', RentalInspection::STATUS_CANCELLED);
            })
            ->first();
        if (! $inspection) {
            return null;
        }
        $link->setRelation('inspection', $inspection);

        return $link;
    }
}
