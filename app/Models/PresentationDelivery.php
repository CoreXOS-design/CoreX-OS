<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 6 — per-recipient delivery record for a presentation send.
 *
 * Lifecycle:
 *   queued    → just inserted
 *   sent      → email dispatched OR copy URL returned OR WhatsApp clicked through
 *   delivered → email provider confirmed (out of scope, future webhook)
 *   opened    → email pixel hit (out of scope, future)
 *   failed    → exception during send
 *   bounced   → provider bounce (out of scope, future)
 */
final class PresentationDelivery extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const CHANNEL_EMAIL    = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_COPY     = 'copy';
    public const CHANNEL_SMS      = 'sms';

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_OPENED    = 'opened';
    public const STATUS_BOUNCED   = 'bounced';

    protected $fillable = [
        'snapshot_link_id',
        'presentation_id',
        'agency_id',
        'sent_by_user_id',
        'channel',
        'recipient_contact_id',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'mode',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
        'opened_at',
        'whatsapp_url',
        'whatsapp_click_through_at',
        'subject_line',
        'message_body',
    ];

    protected $casts = [
        'sent_at'                  => 'datetime',
        'delivered_at'             => 'datetime',
        'opened_at'                => 'datetime',
        'whatsapp_click_through_at'=> 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(PresentationSnapshotLink::class, 'snapshot_link_id');
    }

    public function presentation(): BelongsTo
    {
        return $this->belongsTo(Presentation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
