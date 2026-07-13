<?php

namespace App\Models\DealV2;

use App\Models\Communications\Communication;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-158 DR2 · WS4 (§4.6, §8) — one document → party send record.
 *
 * Carries the delivery mode, the secure-link token (secure_link mode), the
 * lifecycle status, and the archived outbound email (communication_id). The
 * recipient is EITHER a CoreX contact OR a directory provider — never freeform.
 */
class DealDocumentDistribution extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'deal_id',
        'document_id',
        'party_role',
        'recipient_contact_id',
        'recipient_provider_id',
        'recipient_email',
        'delivery_mode',
        'channel',
        'group_key',
        'part_no',
        'part_of',
        'secure_token',
        'otp_required',
        'status',
        'communication_id',
        'sent_by_id',
        'sent_at',
        'first_opened_at',
    ];

    protected $casts = [
        'otp_required' => 'boolean',
        'sent_at' => 'datetime',
        'first_opened_at' => 'datetime',
    ];

    public const MODE_SECURE_LINK = 'secure_link';
    public const MODE_DIRECT_ATTACHMENT = 'direct_attachment';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED_FAILED = 'delivered_failed';
    public const STATUS_OPENED = 'opened';
    public const STATUS_DOWNLOADED = 'downloaded';
    public const STATUS_REVOKED = 'revoked';

    // ── Relationships ──

    public function deal(): BelongsTo
    {
        return $this->belongsTo(DealV2::class, 'deal_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function recipientContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id');
    }

    public function recipientProvider(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'recipient_provider_id');
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class, 'communication_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    public function accessLog(): HasMany
    {
        return $this->hasMany(DealDocumentAccessLog::class, 'distribution_id');
    }

    // ── Helpers ──

    public function isSecureLink(): bool
    {
        return $this->delivery_mode === self::MODE_SECURE_LINK;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /** The recipient's display name (contact or provider), for UI + audit. */
    public function recipientName(): string
    {
        if ($this->recipient_contact_id && $this->recipientContact) {
            return $this->recipientContact->full_name ?? $this->recipient_email;
        }
        if ($this->recipient_provider_id && $this->recipientProvider) {
            return $this->recipientProvider->name ?? $this->recipient_email;
        }
        return $this->recipient_email;
    }
}
