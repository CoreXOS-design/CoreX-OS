<?php

namespace App\Services\Compliance;

use App\Models\Agency;

/**
 * How an agency refers to the trust account rent and deposits are paid into, for the wording a client reads on the FICA form
 * ("...paid via EFT to the Reos trust account"). It is the agency's own setting (Settings -> Profile & Account, and the Setup
 * Wizard's Compliance step); an agency that has not set one reads the neutral "the agency's trust account" - never another
 * agency's name.
 */
class FicaTrustAccount
{
    public const NAME_MAX = 100;
    public const NEUTRAL_PHRASE = "the agency's trust account";

    /** The saved name, or '' when the agency has not set one. */
    public static function nameFor(?int $agencyId): string
    {
        if (! $agencyId) {
            return '';
        }

        return trim((string) Agency::withoutGlobalScopes()->whereKey($agencyId)->value('rental_trust_account_name'));
    }

    /** The phrase dropped into a sentence: "the Reos trust account", or the neutral wording. A leading "the" typed by the agency is not doubled. */
    public static function phraseFor(?int $agencyId): string
    {
        $name = preg_replace('/^the\s+/i', '', self::nameFor($agencyId));

        return $name !== '' ? 'the ' . $name : self::NEUTRAL_PHRASE;
    }
}
