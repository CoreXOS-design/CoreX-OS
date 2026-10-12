<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/title-deed-register.md §8.3 (c)/(d) — the owners' consent to ONE named representative collecting. `link` =
 * every owner was sent a tokenised link and the first answer wins; `phone` = staff recorded a call to a stored number.
 */
class TitleDeedConsent extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'title_deed_consents';

    public const CHANNEL_LINK = 'link';
    public const CHANNEL_PHONE = 'phone';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_USED = 'used';

    protected $fillable = [
        'agency_id', 'title_deed_id', 'channel', 'status', 'rep_name', 'rep_id_number', 'rep_relationship',
        'requested_by_user_id', 'expires_at', 'responded_at', 'responded_contact_id', 'responded_name',
        'responded_ip', 'responded_user_agent', 'approved_until', 'called_contact_id', 'called_number_masked',
        'spoke_to', 'called_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'approved_until' => 'datetime',
        'called_at' => 'datetime',
    ];

    public function deed(): BelongsTo
    {
        return $this->belongsTo(TitleDeed::class, 'title_deed_id')->withTrashed();
    }

    public function links(): HasMany
    {
        return $this->hasMany(TitleDeedConsentLink::class, 'consent_id');
    }

    /** What the screen shows: waiting / approved / declined / expired / cancelled / used. */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_WAITING && $this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->status === self::STATUS_APPROVED && $this->approved_until && $this->approved_until->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    /** Approved and still inside its limited unlock window. */
    public function isUsableForHandover(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->approved_until && $this->approved_until->isFuture();
    }

    public function repIdLast4(): string
    {
        $digits = preg_replace('/\s+/', '', (string) $this->rep_id_number);

        return mb_substr($digits, -4);
    }
}
