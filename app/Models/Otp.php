<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical one-time-code record — the single OTP store for every CoreX
 * consumer (client-portal login today; comms-gate break-glass in AT-132
 * Wave 2; SMS later). Engine: App\Services\Otp\OtpService.
 *
 * Physical table: `client_otps`. The table keeps its original name (the
 * AT-130 generalisation was additive — new generic columns, no live-table
 * rename) and is now destination-agnostic / consumer-agnostic:
 *   - subject_type/subject_id — generic polymorphic subject (nullable;
 *     a ClientUser for client login, a User for comms-gate break-glass).
 *   - destination — the delivery target (email today; phone later). Mirrored
 *     from `email` on create for backwards-compatibility (see booted()).
 *   - channel — 'email' today.
 *   - client_user_id / email — LEGACY columns, retained so the existing
 *     ClientUser->otps() relation and client-login rows are untouched.
 *
 * Audit: .ai/audits/2026-06-30-at130-otp-engine-sweep.md
 */
class Otp extends Model
{
    use SoftDeletes;

    protected $table = 'client_otps';

    protected $fillable = [
        'client_user_id',
        'subject_type',
        'subject_id',
        'email',
        'destination',
        'channel',
        'purpose',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        // Backwards-compat: any row created with the legacy `email` column but
        // no explicit `destination` (older callers, fixtures) gets its
        // destination mirrored from email, so verify-by-destination still
        // resolves it. New consumers always pass `destination` directly.
        static::creating(function (Otp $otp) {
            if (empty($otp->destination) && !empty($otp->email)) {
                $otp->destination = $otp->email;
            }
            if (empty($otp->channel)) {
                $otp->channel = (string) config('otp.channel', 'email');
            }
        });
    }

    /**
     * Generic polymorphic subject this code was issued for (nullable).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired()
            && !$this->isUsed()
            && $this->attempts < (int) config('otp.max_attempts', 5);
    }
}
