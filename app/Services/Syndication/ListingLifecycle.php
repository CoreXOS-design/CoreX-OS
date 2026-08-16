<?php

namespace App\Services\Syndication;

/**
 * AT-68 — the ONE portal-neutral answer to "what lifecycle state is this listing in?"
 *
 * CoreX models listing status in TWO TIERS (mirroring P24/Propcon):
 *
 *   base status   — for_sale, to_let, under_offer, sold, withdrawn, …
 *   status_label  — an optional SUB-LABEL on an on-market base:
 *                   "Under Offer" / "Pending" / "Reduced Price" / "Back on Market"
 *
 * **The sub-label is authoritative when present.** A property can sit at base
 * status `for_sale` with the label "Under Offer" — that listing IS under offer,
 * and any portal mapper reading only the base status will happily keep
 * advertising it as plainly for sale.
 *
 * That is exactly the AT-68 defect: `Property24ListingMapper::getP24Status()`
 * resolves the sub-label first and gets it right; the Private Property mapper
 * read only `$property->status` and never looked at `status_label` at all, so
 * under-offer was structurally invisible to PP.
 *
 * Resolving the two tiers in each portal's mapper independently is how the two
 * portals drifted apart in the first place. The vocabulary and the precedence
 * live here, once. A portal mapper's only job is to translate a lifecycle state
 * into its own enum — never to re-derive what the state IS.
 *
 * Drift guard: tests/Feature/Syndication/ListingLifecycleParityTest.php asserts
 * this resolver and the live P24 mapper agree across the whole vocabulary.
 */
class ListingLifecycle
{
    /** Actively advertised, nothing special flagged. */
    public const ON_MARKET = 'on_market';

    /** Still advertised, but an offer is in hand (P24 'Pending' / PP 'PendingOffer'). */
    public const UNDER_OFFER = 'under_offer';

    /** Still advertised, price movement flagged. */
    public const REDUCED_PRICE = 'reduced_price';
    public const RAISED_PRICE  = 'raised_price';
    public const BACK_ON_MARKET = 'back_on_market';

    /** Terminal — the deal concluded. */
    public const SOLD   = 'sold';
    public const RENTED = 'rented';

    /** Terminal — the listing left the market without concluding. */
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    /**
     * States in which the listing is NO LONGER on the market.
     *
     * NOTE: this is about the MARKET, not about the portal. A sold listing is
     * off-market but a portal may still display it as sold stock — that is the
     * terminal-vs-removed distinction that stranded property #2142 on P24
     * (.ai/audits/p24-sold-not-delisted-2026-07-10.md). Each portal decides
     * what it DISPLAYS; this constant only says the property is not for sale.
     */
    public const OFF_MARKET = [
        self::SOLD,
        self::RENTED,
        self::WITHDRAWN,
        self::EXPIRED,
        self::CANCELLED,
    ];

    /**
     * Resolve the two-tier status into ONE canonical lifecycle state.
     *
     * Precedence: sub-label FIRST (it is the authoritative lifecycle signal when
     * present), then the base status. An on-market base with no label falls
     * through to ON_MARKET.
     */
    public static function resolve(?string $status, ?string $statusLabel = null): string
    {
        // 1) Sub-label wins when present.
        $label = self::normalise($statusLabel);

        if ($label !== '') {
            $fromLabel = match (true) {
                str_contains($label, 'under offer'),
                str_contains($label, 'pending')        => self::UNDER_OFFER,
                str_contains($label, 'back on market') => self::BACK_ON_MARKET,
                str_contains($label, 'reduced')        => self::REDUCED_PRICE,
                str_contains($label, 'raised')         => self::RAISED_PRICE,
                default                                => null,
            };

            if ($fromLabel !== null) {
                return $fromLabel;
            }
        }

        // 2) Base status.
        $base = self::normalise($status);

        return match (true) {
            str_contains($base, 'sold'),
            str_contains($base, 'transferred')      => self::SOLD,
            str_contains($base, 'rented'),
            str_contains($base, 'let out')          => self::RENTED,
            str_contains($base, 'withdrawn'),
            str_contains($base, 'unavailable'),
            str_contains($base, 'draft')            => self::WITHDRAWN,
            str_contains($base, 'expired')          => self::EXPIRED,
            str_contains($base, 'cancelled'),
            str_contains($base, 'archived')         => self::CANCELLED,
            str_contains($base, 'under offer'),
            str_contains($base, 'under_offer'),
            str_contains($base, 'pending')          => self::UNDER_OFFER,
            str_contains($base, 'back on market')   => self::BACK_ON_MARKET,
            str_contains($base, 'reduced')          => self::REDUCED_PRICE,
            str_contains($base, 'raised')           => self::RAISED_PRICE,
            default                                 => self::ON_MARKET,
        };
    }

    /** Is an offer in hand? (Still advertised — just flagged.) */
    public static function isUnderOffer(?string $status, ?string $statusLabel = null): bool
    {
        return self::resolve($status, $statusLabel) === self::UNDER_OFFER;
    }

    /** Has the property left the market? (Says nothing about portal display.) */
    public static function isOffMarket(?string $status, ?string $statusLabel = null): bool
    {
        return in_array(self::resolve($status, $statusLabel), self::OFF_MARKET, true);
    }

    /**
     * Lowercase, strip the bullet/underscore noise CoreX labels carry, collapse
     * whitespace. Same normalisation P24 applies, so "Under_Offer", "• Under
     * Offer" and "under offer" all resolve identically.
     */
    private static function normalise(?string $value): string
    {
        $value = strtolower(str_replace(['•', '_'], ['', ' '], trim((string) $value)));

        return preg_replace('/\s+/', ' ', $value);
    }
}
