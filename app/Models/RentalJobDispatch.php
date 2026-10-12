<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md §19 - one honest attempt to tell a contractor or a crew about a job. Append-only ledger
 * (no deletes): the work order's supplier card and history are read from it. See the migration for the status meanings.
 */
class RentalJobDispatch extends Model
{
    use BelongsToAgency;

    public const KIND_CONTRACTOR = 'contractor';
    public const KIND_CREW = 'crew';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_NONE = 'none';

    public const STATUS_SENT = 'sent';                 // email handed to the agency mail path
    public const STATUS_FAILED = 'failed';             // the mail path refused / errored
    public const STATUS_PREPARED = 'prepared';         // WhatsApp ready; nothing opened yet
    public const STATUS_OPENED = 'opened';             // the agent clicked "Open WhatsApp and send"
    public const STATUS_MARKED_SENT = 'marked_sent';   // ...and then confirmed it went
    public const STATUS_NOT_SENT = 'not_sent';         // no email and no mobile on file

    public const REASON_APPOINTED = 'appointed';
    public const REASON_REASSIGNED = 'reassigned';
    public const REASON_RESEND = 'resend';
    public const REASON_CREW_AUTO = 'crew_auto';

    protected $fillable = [
        'agency_id', 'rental_work_order_id', 'rental_job_card_id', 'recipient_kind', 'agency_service_provider_id', 'rental_crew_id',
        'recipient_name', 'channel', 'destination', 'status', 'reason', 'rental_secure_access_token_id', 'idempotency_key',
        'sent_by_user_id', 'sent_at', 'opened_at', 'error',
    ];

    protected $casts = ['sent_at' => 'datetime', 'opened_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /** Did the job actually reach (or leave for) the recipient? WhatsApp only counts once the agent confirmed it after opening. */
    public function counted(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_MARKED_SENT], true);
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_EMAIL => 'email',
            self::CHANNEL_WHATSAPP => 'WhatsApp',
            default => 'no channel',
        };
    }
}
