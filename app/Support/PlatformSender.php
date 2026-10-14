<?php

namespace App\Support;

use App\Models\Agency;

/**
 * The platform's own neutral sender: the From address CoreX uses when neither the sending agent nor their agency has a mailbox of its own
 * to send through (mail goes out as "<agent> via <agency>" from this address, replies go to the agent / agency).
 *
 * It is NEVER an agency's domain. `MAIL_FROM_ADDRESS` is a per-install environment value, and one install (QA1) had it set to
 * system@hfcoastal.co.za - so a brand-new second agency's mail left as "<agent> via <agency> <system@hfcoastal.co.za>". Any candidate whose
 * domain is the contact-email domain of a registered agency is skipped, so a misconfigured .env can no longer put one agency's domain on
 * another agency's mail (and an agency's domain is not one CoreX's shared server is SPF/DKIM-authorised to send as anyway).
 *
 * Chain: MAIL_PLATFORM_FROM_ADDRESS -> MAIL_FROM_ADDRESS -> the `corex` mailer's from address -> CoreX's own address.
 * Spec: .ai/specs/at395-outgoing-mail-per-mailbox-smtp.md §3.4a.
 */
final class PlatformSender
{
    /** Last resort: CoreX's own domain, never an agency's. */
    public const LAST_RESORT = 'mail@corexos.co.za';

    /** @var array<string,bool> domain => owned by an agency (request memo; flush() in tests) */
    private static array $ownedMemo = [];

    public static function address(): string
    {
        foreach ([
            config('mail.platform_from.address'),
            config('mail.from.address'),
            config('mail.mailers.corex.from_address'),
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) && ! self::domainBelongsToAnAgency(self::domainOf($candidate))) {
                return $candidate;
            }
        }

        return self::LAST_RESORT;
    }

    public static function domain(): string
    {
        return self::domainOf(self::address());
    }

    public static function flush(): void
    {
        self::$ownedMemo = [];
    }

    private static function domainOf(string $address): string
    {
        return strtolower(trim((string) substr(strrchr($address, '@') ?: '', 1)));
    }

    /** True when any agency (archived included) lists a contact email on this domain. */
    private static function domainBelongsToAnAgency(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        return self::$ownedMemo[$domain] ??= Agency::withoutGlobalScopes()
            ->where('email', 'like', '%@' . addcslashes($domain, '%_\\'))
            ->exists();
    }
}
