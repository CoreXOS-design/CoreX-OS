<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-495 - one attempt to email a sent notice to one person (the first send, or a resend). An append-only log: a row is never edited or
 * deleted. `status` says what really happened - `sent`, `not_sent_no_email` (the contact has no email address on file, so nothing went) or
 * `failed` (the mail server refused it) - so a notice is never reported as sent to someone it did not reach.
 */
class RentalNoticeDelivery extends Model
{
    use BelongsToAgency;

    public const SENT = 'sent';
    public const NOT_SENT_NO_EMAIL = 'not_sent_no_email';
    public const FAILED = 'failed';

    public const STATUS_LABELS = [
        self::SENT => 'Emailed',
        self::NOT_SENT_NO_EMAIL => 'NOT sent - no email address on file',
        self::FAILED => 'NOT sent - the email failed',
    ];

    protected $fillable = [
        'agency_id', 'rental_notice_id', 'contact_id', 'recipient_role', 'recipient_name', 'recipient_email',
        'status', 'error', 'attempted_at', 'attempted_by_user_id',
    ];

    protected $casts = ['attempted_at' => 'datetime'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(RentalNotice::class, 'rental_notice_id')->withTrashed();
    }

    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by_user_id');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    public function wasSent(): bool
    {
        return $this->status === self::SENT;
    }
}
