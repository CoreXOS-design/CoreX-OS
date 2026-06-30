<?php

namespace App\Services\Otp;

use App\Mail\OtpMail;
use App\Models\Otp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ══════════════════════════════════════════════════════════════════
 * CoreX OS — Canonical OTP engine
 * ══════════════════════════════════════════════════════════════════
 *
 * The ONE one-time-code engine for CoreX. Destination-agnostic and
 * consumer-agnostic: each consumer declares its own purpose, destination,
 * mailable, audit sink, and (optionally) limit overrides. Extracted from the
 * proven client-auth pattern (ClientAuthService::issueOtp/verifyOtp + the
 * client_otps store) so there is exactly one hardened OTP implementation.
 *
 *   - generate: 6-digit, hashed at rest (never stored in clear).
 *   - deliver : via the sender-only 'otp' mailer; the DESTINATION is whatever
 *               the consumer passes — never a fixed mailbox. A consumer may
 *               supply its own Mailable via the `mail` callback; otherwise the
 *               generic App\Mail\OtpMail is used. Email today; SMS later sits
 *               behind this same interface (seam noted, not built).
 *   - validate: latest unused / unexpired / matching-purpose code, Hash::check,
 *               increments attempts on miss, single-use (used_at) on hit.
 *   - throttle: resend cooldown + hourly cap per (bucket, destination).
 *   - audit   : every issue + every verify routes to a CONSUMER-PROVIDED sink
 *               (ClientAuth → ClientAccessLog; comms-gate → comms_access_audit_log).
 *               The engine hard-codes NO sink and NO role/capability check
 *               (capability is the consumer's concern).
 *
 * Sink contract:  callable(string $event, ?Otp $otp, array $context): void
 *   events: 'otp_issued' | 'otp_verified' | 'otp_failed' | 'otp_attempts_exceeded'
 *
 * Limits default to config/otp.php (carried over from client-auth); any are
 * overridable per call.
 *
 * Audit: .ai/audits/2026-06-30-at130-otp-engine-sweep.md
 *
 * RESERVED — AT-132 Wave 2 (comms-gate break-glass consumer; NOT built here):
 *   purpose='comms_break_glass', capability=communications.grant_access,
 *   destination = the requester's OWN verified email, subject = the User,
 *   success = create a thread-scoped, midnight-reset session grant,
 *   sink = comms_access_audit_log. NOTE before wiring that consumer:
 *   CommsAccessAuditLog::EVENT_TYPES must gain 'otp_issued' + 'otp_unlock' or
 *   CommsAccessAuditLog::record() throws.
 */
class OtpService
{
    /**
     * Generate, hash, store, deliver and audit a one-time code.
     *
     * @param array{
     *   subject?: ?Model,
     *   channel?: string,
     *   ip?: ?string,
     *   user_agent?: ?string,
     *   mailer?: string,
     *   deliver?: bool,
     *   mail?: callable,
     *   audit?: callable,
     *   expires_minutes?: int,
     *   attributes?: array<string,mixed>
     * } $opts
     */
    public function issue(string $purpose, string $destination, array $opts = []): Otp
    {
        $destination = trim($destination);
        $code        = $this->generateCode();
        $subject     = $opts['subject'] ?? null;
        $expiresMin  = (int) ($opts['expires_minutes'] ?? config('otp.expires_minutes', 10));

        $otp = Otp::create(array_merge([
            'subject_type' => $subject?->getMorphClass(),
            'subject_id'   => $subject?->getKey(),
            'destination'  => $destination,
            'channel'      => $opts['channel'] ?? config('otp.channel', 'email'),
            'purpose'      => $purpose,
            'code_hash'    => Hash::make($code),
            'expires_at'   => now()->addMinutes($expiresMin),
            'ip'           => $opts['ip'] ?? null,
            'user_agent'   => isset($opts['user_agent'])
                ? substr((string) $opts['user_agent'], 0, 500)
                : null,
        ], $opts['attributes'] ?? []));

        $deliver = $opts['deliver'] ?? true;
        if ($deliver) {
            $this->deliver($otp, $code, $destination, $expiresMin, $opts);
        }

        $this->audit($opts, 'otp_issued', $otp, ['delivered' => $deliver]);

        return $otp;
    }

    /**
     * Verify a submitted code for a (purpose, destination). Returns the
     * consumed Otp on success, or null on any failure. Single-use: a second
     * verify of the same code fails.
     *
     * @param array{audit?: callable} $opts
     */
    public function verify(string $purpose, string $destination, string $code, array $opts = []): ?Otp
    {
        $destination = trim($destination);

        $otp = Otp::where('destination', $destination)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (!$otp || !$otp->isValid()) {
            $this->audit($opts, 'otp_failed', $otp, [
                'reason'      => $otp ? 'invalid' : 'not_found',
                'destination' => $destination,
                'purpose'     => $purpose,
            ]);
            return null;
        }

        if (!Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $event = $otp->attempts >= (int) config('otp.max_attempts', 5)
                ? 'otp_attempts_exceeded'
                : 'otp_failed';
            $this->audit($opts, $event, $otp, ['reason' => 'mismatch', 'attempts' => $otp->attempts]);
            return null;
        }

        $otp->forceFill(['used_at' => now()])->save();
        $this->audit($opts, 'otp_verified', $otp, []);

        return $otp;
    }

    /**
     * Issuance throttle: resend cooldown + hourly cap, keyed by (bucket,
     * destination). Mirrors the proven client-auth check-then-hit ordering.
     * Returns null when issuance is allowed, or 'cooldown' / 'hourly' when
     * blocked. The CONSUMER decides WHERE to call this (e.g. before a match
     * check, for enumeration safety) and HOW to surface the block.
     *
     * @param array{cooldown_secs?: int, hourly_limit?: int, hourly_window_secs?: int} $opts
     */
    public function throttle(string $bucket, string $destination, array $opts = []): ?string
    {
        $key = $bucket . ':' . strtolower(trim($destination));

        $cooldownKey = "otp.cooldown:{$key}";
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return 'cooldown';
        }
        RateLimiter::hit($cooldownKey, (int) ($opts['cooldown_secs'] ?? config('otp.resend_cooldown_secs', 60)));

        $hourlyKey = "otp.hourly:{$key}";
        $limit     = (int) ($opts['hourly_limit'] ?? config('otp.hourly_limit', 5));
        if (RateLimiter::tooManyAttempts($hourlyKey, $limit)) {
            return 'hourly';
        }
        RateLimiter::hit($hourlyKey, (int) ($opts['hourly_window_secs'] ?? config('otp.hourly_window_secs', 3600)));

        return null;
    }

    /**
     * The hardened generation primitive — zero-padded random_int of the
     * configured length.
     */
    public function generateCode(): string
    {
        $len = (int) config('otp.length', 6);
        $max = (10 ** $len) - 1;

        return str_pad((string) random_int(0, $max), $len, '0', STR_PAD_LEFT);
    }

    private function deliver(Otp $otp, string $code, string $destination, int $expiresMin, array $opts): void
    {
        $mailer   = $opts['mailer'] ?? config('otp.mailer', 'otp');
        $mailable = isset($opts['mail']) && is_callable($opts['mail'])
            ? ($opts['mail'])($code, $otp)
            : new OtpMail($code, $expiresMin);

        try {
            Mail::mailer($mailer)->to($destination)->send($mailable);
        } catch (\Throwable $e) {
            // Delivery failure must never be fatal to issuance (matches the
            // proven client-auth behaviour) — report and carry on.
            report($e);
        }
    }

    private function audit(array $opts, string $event, ?Otp $otp, array $context = []): void
    {
        if (!isset($opts['audit']) || !is_callable($opts['audit'])) {
            return;
        }

        try {
            ($opts['audit'])($event, $otp, $context + [
                'destination' => $otp?->destination ?? ($context['destination'] ?? null),
                'purpose'     => $otp?->purpose ?? ($context['purpose'] ?? null),
            ]);
        } catch (\Throwable $e) {
            // A consumer's audit sink must never break OTP issuance/verification.
            report($e);
        }
    }
}
