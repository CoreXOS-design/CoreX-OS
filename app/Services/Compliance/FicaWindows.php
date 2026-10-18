<?php

namespace App\Services\Compliance;

use App\Models\Agency;

/**
 * The FICA time windows, per agency. They used to be literals scattered through the code (a 14-day link, an 11-month
 * "still current", a 24-month validity stamp, a 60-day "expiring soon"); each is now an agency setting whose DEFAULT is
 * exactly the old literal, so an agency that has never opened the setting behaves as before. A NULL column means "the default".
 * Settings → Profile & Account → FICA time windows, and the Setup Wizard's Compliance step.
 */
class FicaWindows
{
    public const DEFAULT_LINK_EXPIRY_DAYS = 14;
    public const DEFAULT_CURRENT_MONTHS = 11;
    public const DEFAULT_VALIDITY_MONTHS = 24;
    public const DEFAULT_EXPIRING_SOON_DAYS = 60;

    /** key => [default, min, max] — the single list the saver, the screens and the tests read. */
    public const WINDOWS = [
        'fica_link_expiry_days' => [self::DEFAULT_LINK_EXPIRY_DAYS, 1, 90],
        'fica_current_months' => [self::DEFAULT_CURRENT_MONTHS, 1, 60],
        'fica_validity_months' => [self::DEFAULT_VALIDITY_MONTHS, 1, 120],
        'fica_expiring_soon_days' => [self::DEFAULT_EXPIRING_SOON_DAYS, 1, 365],
    ];

    /** Days a FICA link emailed to a client stays usable. */
    public static function linkExpiryDays(?int $agencyId): int
    {
        return self::value('fica_link_expiry_days', $agencyId);
    }

    /** Months an approved FICA counts as current; from then on it shows "expiring" and a repeat applicant is asked again. */
    public static function currentMonths(?int $agencyId): int
    {
        return self::value('fica_current_months', $agencyId);
    }

    /** Months an approval is valid for — the expiry date stamped when the compliance officer approves. */
    public static function validityMonths(?int $agencyId): int
    {
        return self::value('fica_validity_months', $agencyId);
    }

    /** Days ahead the "expiring soon" lists look. */
    public static function expiringSoonDays(?int $agencyId): int
    {
        return self::value('fica_expiring_soon_days', $agencyId);
    }

    private static function value(string $key, ?int $agencyId): int
    {
        [$default, $min, $max] = self::WINDOWS[$key];
        if (! $agencyId) {
            return $default;
        }

        $saved = Agency::withoutGlobalScopes()->whereKey($agencyId)->value($key);

        return is_numeric($saved) && (int) $saved >= $min && (int) $saved <= $max ? (int) $saved : $default;
    }
}
