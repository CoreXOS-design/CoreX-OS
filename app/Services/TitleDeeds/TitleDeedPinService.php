<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Models\Otp;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * .ai/specs/title-deed-register.md §8.2 — the collection PIN. ONE per deed (not per owner), on the existing OTP engine
 * (OtpService: code generation, hashing at rest, constant-time Hash::check, single use) with its own purpose.
 *
 * Why the rows are created here and not through OtpService::issue(): issue() generates the code internally and returns only
 * the Otp row, so a caller that must SEND the code (email / WhatsApp) can never see it once delivery is switched off. This
 * writes the same row issue() writes (same columns, same hash) from OtpService::generateCode(), and verification goes
 * through OtpService::verify().
 *
 * `pin_encrypted` (Laravel Crypt, the app key) exists only so "Resend PIN" can resend the SAME PIN. It is cleared the moment
 * the deed is collected or the PIN is replaced. The PIN is never rendered on a CoreX screen.
 */
class TitleDeedPinService
{
    public const PURPOSE = 'title_deed_collect';

    /** "Valid until collected": five years of minutes — the row is also expired explicitly on replace / collection. */
    private const VALID_MINUTES = 60 * 24 * 365 * 5;

    public function __construct(private OtpService $otp) {}

    public function destinationFor(int $deedId, int $version): string
    {
        return 'title_deed:' . $deedId . ':' . $version;
    }

    public function hasActivePin(TitleDeed $deed): bool
    {
        return $deed->pin_version > 0 && $deed->pin_encrypted !== null;
    }

    /**
     * Issue a PIN (the first, or a replacement that cancels the old one). Call inside the deed's row lock. Returns the
     * plaintext code ONCE, for the caller to send; it is not stored in the clear anywhere.
     */
    public function issue(TitleDeed $deed): string
    {
        if ($deed->pin_version > 0) {
            $this->expire($deed->id, (int) $deed->pin_version);
        }

        $version = (int) $deed->pin_version + 1;
        $code = $this->otp->generateCode();

        Otp::create([
            'subject_type' => $deed->getMorphClass(),
            'subject_id' => $deed->id,
            'destination' => $this->destinationFor($deed->id, $version),
            'channel' => 'internal',
            'purpose' => self::PURPOSE,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        $deed->forceFill([
            'pin_version' => $version,
            'pin_encrypted' => Crypt::encryptString($code),
            'pin_issued_at' => now(),
            'pin_failed_attempts' => 0,
            'pin_locked_at' => null,
        ])->save();

        return $code;
    }

    /** The PIN as issued, for resending. Null when there is none (or it can no longer be read). */
    public function currentCode(TitleDeed $deed): ?string
    {
        if ($deed->pin_encrypted === null) {
            return null;
        }
        try {
            return Crypt::decryptString($deed->pin_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isLocked(TitleDeed $deed): bool
    {
        return $deed->pin_locked_at !== null;
    }

    /**
     * Check a staff-entered PIN. Returns true and CONSUMES the code (single use) on a match; false on a miss, after
     * counting the miss on the deed and locking it at the agency's N. The caller commits the miss (it returns a result
     * instead of throwing inside its transaction, so the count and the audit row survive).
     *
     * @return array{ok:bool, locked:bool, attempts:int, limit:int}
     */
    public function check(TitleDeed $deed, string $code): array
    {
        $limit = TitleDeedSetting::pinLockAttemptsFor($deed->agency_id);

        if ($this->isLocked($deed)) {
            return ['ok' => false, 'locked' => true, 'attempts' => (int) $deed->pin_failed_attempts, 'limit' => $limit];
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';
        $destination = $this->destinationFor($deed->id, (int) $deed->pin_version);
        $otp = $deed->pin_version > 0 && $code !== '' ? $this->otp->verify(self::PURPOSE, $destination, $code) : null;

        if ($otp) {
            return ['ok' => true, 'locked' => false, 'attempts' => (int) $deed->pin_failed_attempts, 'limit' => $limit];
        }

        // The engine counts misses on the code row against its own fixed ceiling; the agency's limit is the authority here,
        // so the row's counter is held at zero and the deed carries the count.
        Otp::query()->where('destination', $destination)->where('purpose', self::PURPOSE)->whereNull('used_at')->update(['attempts' => 0]);

        $attempts = (int) $deed->pin_failed_attempts + 1;
        $locked = $attempts >= $limit;
        $deed->forceFill([
            'pin_failed_attempts' => $attempts,
            'pin_locked_at' => $locked ? now() : null,
        ])->save();

        return ['ok' => false, 'locked' => $locked, 'attempts' => $attempts, 'limit' => $limit];
    }

    /**
     * Check a staff-entered PIN WITHOUT using it up (sign-by-link: the PIN is proven at the counter when the link is generated, and
     * only spent when the handover completes). A miss is counted and can lock exactly like check().
     *
     * @return array{ok:bool, locked:bool, attempts:int, limit:int}
     */
    public function peek(TitleDeed $deed, string $code): array
    {
        $limit = TitleDeedSetting::pinLockAttemptsFor($deed->agency_id);
        if ($this->isLocked($deed)) {
            return ['ok' => false, 'locked' => true, 'attempts' => (int) $deed->pin_failed_attempts, 'limit' => $limit];
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';
        $row = $deed->pin_version > 0 && $code !== ''
            ? Otp::query()->where('destination', $this->destinationFor($deed->id, (int) $deed->pin_version))
                ->where('purpose', self::PURPOSE)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first()
            : null;

        if ($row && Hash::check($code, $row->code_hash)) {
            return ['ok' => true, 'locked' => false, 'attempts' => (int) $deed->pin_failed_attempts, 'limit' => $limit];
        }

        $attempts = (int) $deed->pin_failed_attempts + 1;
        $locked = $attempts >= $limit;
        $deed->forceFill(['pin_failed_attempts' => $attempts, 'pin_locked_at' => $locked ? now() : null])->save();

        return ['ok' => false, 'locked' => $locked, 'attempts' => $attempts, 'limit' => $limit];
    }

    /** Spend a PIN that was proven earlier (sign-by-link completion). False if it is gone, replaced or expired in the meantime. */
    public function consumeProven(TitleDeed $deed, int $provenVersion): bool
    {
        if ($deed->pin_version < 1 || (int) $deed->pin_version !== $provenVersion || $this->isLocked($deed)) {
            return false;
        }

        return (bool) Otp::query()->where('destination', $this->destinationFor($deed->id, $provenVersion))
            ->where('purpose', self::PURPOSE)->whereNull('used_at')->where('expires_at', '>', now())
            ->update(['used_at' => now()]);
    }

    /** After collection: nothing left to resend, and the code can no longer be used. */
    public function retire(TitleDeed $deed): void
    {
        if ($deed->pin_version > 0) {
            $this->expire($deed->id, (int) $deed->pin_version);
        }
        $deed->forceFill(['pin_encrypted' => null])->save();
    }

    private function expire(int $deedId, int $version): void
    {
        Otp::query()
            ->where('destination', $this->destinationFor($deedId, $version))
            ->where('purpose', self::PURPOSE)
            ->update(['expires_at' => now()->subSecond()]);
    }

    /** @throws TitleDeedException when this PIN can no longer be used */
    public function assertUsable(TitleDeed $deed): void
    {
        if ($deed->pin_version < 1) {
            throw new TitleDeedException('No collection PIN has been issued for this deed yet - notify the owner first, or use another way to hand it over.');
        }
        if ($this->isLocked($deed)) {
            throw new TitleDeedException('The PIN is locked after too many wrong attempts. Issue a new PIN, or hand the deed over another way.', TitleDeedException::LOCKED);
        }
    }
}
