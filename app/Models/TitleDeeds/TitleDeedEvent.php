<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The notification ledger: one row per owner per channel per send — who, when, destination (masked), result.
 * WhatsApp is honest: `prepared` until the staff member confirms they sent it. (Rows are never deleted.)
 */
class TitleDeedEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_events';

    public const KIND_OWNER_NOTICE = 'owner_notice';
    public const KIND_PIN_RESEND = 'pin_resend';
    public const KIND_PIN_NEW = 'pin_new';
    public const KIND_CONSENT_REQUEST = 'consent_request';
    public const KIND_REMINDER = 'reminder';
    public const KIND_COLLECTED_NOTICE = 'collected_notice';

    public const KIND_LABELS = [
        self::KIND_OWNER_NOTICE => 'Owner notice',
        self::KIND_PIN_RESEND => 'PIN resent',
        self::KIND_PIN_NEW => 'New PIN',
        self::KIND_CONSENT_REQUEST => 'Consent request',
        self::KIND_REMINDER => 'Reminder',
        self::KIND_COLLECTED_NOTICE => 'Collected notice',
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_PREPARED = 'prepared';

    protected $fillable = [
        'agency_id', 'title_deed_id', 'contact_id', 'owner_name', 'kind', 'channel', 'destination_masked',
        'status', 'error', 'user_id', 'confirmed_at', 'consent_id', 'payload_encrypted',
    ];

    protected $hidden = ['payload_encrypted'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deed(): BelongsTo
    {
        return $this->belongsTo(TitleDeed::class, 'title_deed_id')->withTrashed();
    }
}
