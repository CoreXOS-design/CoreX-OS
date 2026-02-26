<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocumentRecipient extends Model
{
    protected $fillable = [
        'sales_document_send_id',
        'signing_order',
        'recipient_name',
        'recipient_email',
        'recipient_role',
        'token',
        'token_expires_at',
        'status',
        'sent_at',
        'downloaded_at',
        'returned_at',
        'returned_file_path',
        'return_method',
        'reminder_count',
        'last_reminder_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'returned_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    // ── Relationships ──

    public function documentSend(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentSend::class, 'sales_document_send_id');
    }

    // ── Status helpers ──

    public function isExpired(): bool
    {
        return $this->token_expires_at?->isPast() ?? false;
    }

    public function isReturned(): bool
    {
        return in_array($this->status, ['returned_pending_approval', 'approved']);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function needsApproval(): bool
    {
        return $this->status === 'returned_pending_approval';
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function daysSinceSent(): int
    {
        return $this->sent_at ? (int) $this->sent_at->diffInDays(now()) : 0;
    }

    public function urgencyColor(): string
    {
        $days = $this->daysSinceSent();

        return match (true) {
            $days > 7  => 'red',
            $days >= 3 => 'yellow',
            default    => 'green',
        };
    }

    // ── Scopes ──

    public function scopeAwaitingReturn($query)
    {
        return $query->where('status', 'sent')
            ->where('token_expires_at', '>', now());
    }
}
