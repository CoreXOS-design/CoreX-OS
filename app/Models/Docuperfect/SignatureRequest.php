<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureRequest extends Model
{
    use SoftDeletes;

    protected $table = 'signature_requests';

    protected $fillable = [
        'signature_template_id',
        'party_role',
        'signing_order',
        'signer_name',
        'signer_email',
        'signer_id_number',
        'token',
        'token_expires_at',
        'status',
        'sent_at',
        'viewed_at',
        'completed_at',
        'reminder_sent_at',
        'reminder_count',
        'ip_address',
        'user_agent',
        'sent_by',
        'message',
        'signing_method',
        'wet_ink_upload_path',
        'wet_ink_status',
        'wet_ink_rejection_note',
        'reviewed_by',
        'reviewed_at',
        'team_alerted_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'team_alerted_at' => 'datetime',
    ];

    // Status constants
    const STATUS_WAITING = 'waiting';
    const STATUS_PENDING = 'pending';
    const STATUS_VIEWED = 'viewed';
    const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DECLINED = 'declined';

    // Wet ink status constants
    const WET_INK_PENDING_UPLOAD = 'pending_upload';
    const WET_INK_UPLOADED_PENDING_REVIEW = 'uploaded_pending_review';
    const WET_INK_APPROVED = 'approved';
    const WET_INK_REJECTED = 'rejected';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function inspections()
    {
        return $this->hasMany(WetInkInspection::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_DECLINED, self::STATUS_COMPLETED]);
    }

    public function scopeNeedsReminder($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->whereNotNull('sent_at');
    }

    public function scopeExpirable($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->where('token_expires_at', '<', now());
    }

    // --- Helpers ---

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isWetInk(): bool
    {
        return $this->signing_method === 'wet_ink';
    }

    public function daysUntilExpiry(): int
    {
        if (!$this->token_expires_at) {
            return 0;
        }
        return max(0, (int) now()->diffInDays($this->token_expires_at, false));
    }

    public function daysSinceSent(): int
    {
        if (!$this->sent_at) {
            return 0;
        }
        return (int) $this->sent_at->diffInDays(now());
    }
}
