<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24ListingMapper;
use PHPUnit\Framework\TestCase;

/**
 * AT-350 — a listing sold by ANOTHER agency must LEAVE the portal, and must never
 * be attributed to us on it.
 *
 * Required by dev-check.ps1 §7: any change to the portal sync path lands with a
 * test diff here. This one guards the `Property24ListingMapper::getP24Status()`
 * change, which is the whole of AT-350's portal surface.
 *
 * Why this needs its own test rather than trusting the status mapping: the new
 * status value CONTAINS the substring "sold", and the mapper matches terminal
 * statuses by substring. Left alone it resolved to P24 `Sold`, which is wrong
 * twice over and both failures are silent:
 *
 *   1. P24 renders `Sold` as OUR sale on OUR listing — publishing a competitor's
 *      result under the agency's own branding.
 *   2. `removesFromPortal()` correctly reports `Sold` as still-on-portal (P24
 *      keeps sold stock listed), so every delist path downstream skips the
 *      property as "already handled" and the advert stays publicly live for
 *      stock the agency no longer holds. That is the exact stranded-listing
 *      failure of .ai/audits/p24-sold-not-delisted-2026-07-10.md (property #2142).
 *
 * Pure unit-level: getP24Status/removesFromPortal are static and touch no DB, so
 * this costs milliseconds and adds nothing to the suite's bootstrap.
 */
final class ThirdPartySaleDelistTest extends TestCase
{
    public function test_third_party_sale_pushes_withdrawn_and_actually_removes_the_listing(): void
    {
        $p24Status = Property24ListingMapper::getP24Status(Property::STATUS_SOLD_BY_3RD_PARTY);

        $this->assertSame('Withdrawn', $p24Status, 'A competitor sale must never be pushed to P24 as our Sold.');
        $this->assertTrue(
            Property24ListingMapper::removesFromPortal($p24Status),
            'The pushed status must be one that actually takes the advert off P24.'
        );
    }

    /**
     * The mapper normalises underscores to SPACES before matching, and
     * properties.status is genuinely mixed-case in production (the wizard writes
     * lowercase slugs, the P24 sync writes capitalised labels). Every shape that
     * can reach the mapper must resolve to the delist.
     *
     * @dataProvider statusVariantProvider
     */
    public function test_every_stored_variant_of_the_status_delists(string $variant): void
    {
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status($variant));
    }

    public static function statusVariantProvider(): array
    {
        return [
            'canonical slug' => ['sold_by_3rd_party'],
            'title case'     => ['Sold by 3rd Party'],
            'upper case'     => ['SOLD BY 3RD PARTY'],
            'spaced slug'    => ['sold by 3rd party'],
            'third spelling' => ['sold_by_third_party'],
        ];
    }

    public function test_our_own_sale_is_unchanged(): void
    {
        // The regression guard on the guard: the new arm must not have swallowed
        // ordinary sales. P24 keeps our sold stock listed and badges it Sold —
        // that behaviour is deliberate and must survive.
        $this->assertSame('Sold', Property24ListingMapper::getP24Status('sold'));
        $this->assertSame('Sold', Property24ListingMapper::getP24Status('Sold'));
        $this->assertFalse(Property24ListingMapper::removesFromPortal('Sold'));

        // And the other terminal states still resolve as before.
        $this->assertSame('Withdrawn', Property24ListingMapper::getP24Status('withdrawn'));
        $this->assertSame('Rented', Property24ListingMapper::getP24Status('rented'));
        $this->assertSame('Expired', Property24ListingMapper::getP24Status('expired'));
    }

    public function test_a_stale_on_market_banner_cannot_resurrect_a_third_party_sale(): void
    {
        // The banner ("Reduced Price", "Back on Market") is not always cleared
        // when a listing goes terminal, and an on-market banner winning here would
        // keep a sold property advertised. The terminal base must be absolute.
        foreach (['Reduced Price', 'Back on Market', 'Pending', 'Raised Price'] as $banner) {
            $this->assertSame(
                'Withdrawn',
                Property24ListingMapper::getP24Status(Property::STATUS_SOLD_BY_3RD_PARTY, '12345', $banner),
                "A stale [{$banner}] banner must not keep a third-party-sold listing live."
            );
        }
    }
}
