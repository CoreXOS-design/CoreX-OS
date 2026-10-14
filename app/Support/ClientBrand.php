<?php

namespace App\Support;

use App\Models\Agency;

/**
 * Johan, 14 Oct 2026: anything a CLIENT (tenant, landlord, buyer, seller, applicant, contractor) sees carries the AGENCY's name, never
 * "CoreX" / "CoreX OS". `config('app.name')` and `config('mail.from.name')` are CoreX's own names, so they are never a client-facing
 * fallback. One rule for every client-facing page, mail and PDF: the document / property / lease agency; else the portal / public-link
 * agency; else a neutral label that names nobody. There is no "Powered by CoreX" line and no agency setting for one.
 *
 * The guard test (tests/Feature/Branding/ClientFacingNeverSaysCoreXTest.php) keeps the client-facing files honest.
 */
final class ClientBrand
{
    /** No agency known: public property / auction / generic pages. */
    public const NEUTRAL = 'Property Portal';

    /** No agency known: the rentals portal and its mail. */
    public const NEUTRAL_RENTALS = 'My Rentals';

    /** The name a client reads: the agency's own, else a neutral label - never CoreX. */
    public static function name(?Agency $agency, string $neutral = self::NEUTRAL): string
    {
        return Agency::displayNameFor($agency, $neutral);
    }

    /** Same, from an agency id (withoutGlobalScopes: a public page has no signed-in user to scope by). */
    public static function nameForId(?int $agencyId, string $neutral = self::NEUTRAL): string
    {
        return self::name($agencyId ? Agency::withoutGlobalScopes()->find($agencyId) : null, $neutral);
    }
}
