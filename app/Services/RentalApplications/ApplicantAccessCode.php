<?php

namespace App\Services\RentalApplications;

use App\Models\Otp;
use App\Models\RentalApplication;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Hash;

/**
 * Audit fix A2 (2026-10-19) - the way back in for an applicant who cannot pass the return gate.
 *
 * Once an application is submitted its link asks for the applicant's ID number (or an emailed code). An applicant with
 * no ID number on file and no email address had no way in at all, and the page offered nothing but "contact your agent".
 * The agent now re-issues the link from the application: a fresh link plus a one-time code that the AGENT gives the
 * applicant (by phone call or WhatsApp, to the number on file - CoreX has no SMS channel). The applicant types the code
 * on the gate page instead of an ID. Single use, hashed at rest, and it dies with the link (reopen_link_expiry_days).
 *
 * Built on the canonical OTP engine and store (OtpService / client_otps) - no second code table. The attempt budget is the
 * gate's own (named limiter `rental-application-gate`, keyed by the link) plus the engine's per-code attempt cap.
 */
final class ApplicantAccessCode
{
    public const PURPOSE = 'rental_application_agent_code';

    private static function destinationFor(RentalApplication $application): string
    {
        return 'rental-application:' . $application->id;
    }

    /**
     * Mint a code, replacing any earlier unused one for this application. Returns the PLAIN code - shown once to the agent,
     * never stored in clear.
     */
    public function issue(RentalApplication $application, \DateTimeInterface $expiresAt): string
    {
        $destination = self::destinationFor($application);

        Otp::where('destination', $destination)->where('purpose', self::PURPOSE)->whereNull('used_at')->delete();

        $code = app(OtpService::class)->generateCode();

        Otp::create([
            'subject_type' => $application->getMorphClass(),
            'subject_id' => $application->getKey(),
            'destination' => $destination,
            'channel' => 'agent',
            'purpose' => self::PURPOSE,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
        ]);

        return $code;
    }

    /** Consumes the code on success (single use). */
    public function verify(RentalApplication $application, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '') {
            return false;
        }

        return app(OtpService::class)->verify(self::PURPOSE, self::destinationFor($application), $code) !== null;
    }

    /** Is there a live code waiting? (the gate page shows its "I have a code from my agent" box either way) */
    public function hasLiveCode(RentalApplication $application): bool
    {
        return Otp::where('destination', self::destinationFor($application))
            ->where('purpose', self::PURPOSE)->whereNull('used_at')->where('expires_at', '>', now())->exists();
    }
}
