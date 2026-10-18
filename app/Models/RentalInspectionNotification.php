<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspections.md §43 — "log every notification on the
 * inspection." Append-only — one row per attempted send, success or
 * failure, no updated_at (a correction is a new row), same convention as
 * SignedDocumentDistributionLog. Written only by
 * App\Services\Rentals\RentalInspectionNotificationService.
 */
class RentalInspectionNotification extends Model
{
    use BelongsToAgency;

    const UPDATED_AT = null;

    public const EVENT_SCHEDULED = 'scheduled';
    public const EVENT_RESCHEDULED = 'rescheduled';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_REMINDER = 'reminder';
    /** §45.5 (Build I-3) — an invitation given OFF the system (phone, WhatsApp typed by hand, in person), recorded by an agent. */
    public const EVENT_INVITATION_MANUAL = 'invitation_manual';

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_INSPECTOR = 'inspector';

    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_MANUAL = 'manual';

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'event',
        'party_role',
        'recipient_contact_id',
        'recipient_user_id',
        'channel',
        'recipient',
        'status',
        'error',
        'sent_by_user_id',
        'subject_snapshot',
        'occurred_at',
        'method',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    /** When it actually happened: the recorded time for a manual invitation, otherwise when the system logged it. */
    public function happenedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->occurred_at ?? $this->created_at;
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function recipientContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
