<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * .ai/specs/title-deed-register.md section 14.10 - a single-use, expiring link that lets ONE named collector sign on their own
 * phone. The link only records the receipt; the gates (PIN / owner consent / ID check / override) were passed at the counter
 * BEFORE it could be generated, and are held in `snapshot` so the handover that follows cannot be steered by the browser.
 * The token is stored hashed (plus an encrypted copy so the staff screen can show the QR / link while it is live).
 */
class TitleDeedSigningLink extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_signing_links';

    public const STATUS_WAITING = 'waiting';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_USED = 'used';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agency_id', 'title_deed_id', 'token_hash', 'token_encrypted', 'status', 'collector_type', 'collector_contact_id',
        'collector_name', 'send_email', 'send_mobile', 'snapshot', 'created_by_user_id', 'expires_at', 'first_opened_at',
        'signed_at', 'signed_typed_name', 'signature_path', 'signed_ip', 'signed_user_agent', 'cancelled_at', 'cancelled_by_user_id',
    ];

    protected $hidden = ['token_encrypted', 'snapshot'];

    protected $casts = [
        'snapshot' => 'array',
        'expires_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'signed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function deed(): BelongsTo
    {
        return $this->belongsTo(TitleDeed::class, 'title_deed_id')->withTrashed();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Scope-free lookup: the holder has no session, the token is the authority. */
    public static function findByToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > 80) {
            return null;
        }

        return static::withoutGlobalScopes()->where('token_hash', self::hashToken($token))->first();
    }

    /** What the screens show: waiting / signed / used / cancelled / expired. */
    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_WAITING && $this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }

        return $this->status;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_WAITING && $this->expires_at && $this->expires_at->isFuture();
    }

    public function token(): ?string
    {
        if (! $this->token_encrypted || ! $this->isLive()) {
            return null;
        }
        try {
            return Crypt::decryptString($this->token_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function url(): ?string
    {
        $t = $this->token();

        return $t ? route('title-deeds.sign.show', ['token' => $t]) : null;
    }
}
