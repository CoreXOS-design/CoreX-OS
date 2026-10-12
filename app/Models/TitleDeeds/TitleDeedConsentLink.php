<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tokenised consent link for ONE owner. The token itself is never stored — only its SHA-256 — and is shown exactly
 * once, in the email / prepared WhatsApp that carries it. Single use, expiring, unguessable (48 random characters).
 */
class TitleDeedConsentLink extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_consent_links';

    protected $fillable = ['agency_id', 'consent_id', 'contact_id', 'token_hash', 'expires_at', 'used_at', 'first_opened_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'first_opened_at' => 'datetime'];

    public function consent(): BelongsTo
    {
        return $this->belongsTo(TitleDeedConsent::class, 'consent_id')->withTrashed();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Scope-free lookup (the holder has no session — the token is the authority). Null for anything not live. */
    public static function findLiveByToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > 80) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('token_hash', self::hashToken($token))
            ->first();
    }
}
