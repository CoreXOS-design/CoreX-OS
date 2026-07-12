<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\Syndication\ListingLifecycle;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Tests\TestCase;

/**
 * AT-68 — Private Property status parity.
 *
 * PP's PropertyStatus enum (live WSDL): ForSale · ToLet · PendingOffer · Sold ·
 * Inactive · Archived. We only ever sent three of the six, so a property under
 * offer kept advertising on PP as plainly FOR SALE while P24 correctly showed it
 * as Pending.
 *
 * Scope shipped (Johan, 2026-07-11): the under-offer half. `sold` deliberately
 * stays `Inactive` until PP's sandbox is back and we can establish whether PP
 * keeps a Sold listing on the portal — getting that wrong re-opens the
 * terminal-vs-removed stranding bug from property #2142.
 */
final class PpStatusParityTest extends TestCase
{
    private function property(?string $status, ?string $label = null, string $listingType = 'sale'): Property
    {
        return new Property([
            'status'       => $status,
            'status_label' => $label,
            'listing_type' => $listingType,
        ]);
    }

    private function ppStatus(?string $status, ?string $label = null, string $type = 'Sale'): string
    {
        return PrivatePropertyListingMapper::statusFor(
            $this->property($status, $label, strtolower($type)),
            $type
        );
    }

    // ── the headline gap: under offer ───────────────────────────────────────

    public function test_under_offer_as_a_base_status_sends_pending_offer(): void
    {
        $this->assertSame('PendingOffer', $this->ppStatus('under_offer'));
    }

    /**
     * THE case that was structurally invisible to PP. Under-offer normally lives
     * in the SUB-LABEL on a for-sale base — and the old mapper read only
     * $property->status, so it happily kept advertising the property as ForSale.
     */
    public function test_under_offer_as_a_sub_label_on_a_for_sale_base_sends_pending_offer(): void
    {
        $this->assertSame('PendingOffer', $this->ppStatus('for_sale', 'Under Offer'));
        $this->assertSame('PendingOffer', $this->ppStatus('for_sale', 'Pending'));
    }

    public function test_a_rental_under_offer_also_sends_pending_offer(): void
    {
        $this->assertSame('PendingOffer', $this->ppStatus('to_let', 'Under Offer', 'Rental'));
    }

    // ── no false positives: normal listings still advertise ─────────────────

    public function test_a_plain_for_sale_listing_still_sends_for_sale(): void
    {
        $this->assertSame('ForSale', $this->ppStatus('for_sale'));
    }

    public function test_a_plain_rental_still_sends_to_let(): void
    {
        $this->assertSame('ToLet', $this->ppStatus('to_let', null, 'Rental'));
    }

    /** A price-movement label is NOT an offer — it must stay on-market, not flip to PendingOffer. */
    public function test_a_reduced_price_label_does_not_flag_an_offer(): void
    {
        $this->assertSame('ForSale', $this->ppStatus('for_sale', 'Reduced Price'));
        $this->assertSame('ForSale', $this->ppStatus('for_sale', 'Back on Market'));
    }

    // ── the deliberate non-change: sold stays Inactive ───────────────────────

    public function test_sold_still_maps_to_inactive_by_decision(): void
    {
        $this->assertSame(
            'Inactive',
            $this->ppStatus('sold'),
            'DELIBERATE: sold stays Inactive until PP sandbox settles whether a Sold listing '
            . 'stays on the portal. Changing this re-opens the property #2142 stranding class.'
        );
    }

    public function test_off_market_statuses_still_map_to_inactive(): void
    {
        foreach (['withdrawn', 'expired', 'cancelled', 'archived', 'unavailable', 'let_out'] as $offMarket) {
            $this->assertSame('Inactive', $this->ppStatus($offMarket), "{$offMarket} must be Inactive");
        }
    }

    // ── the messy real world ────────────────────────────────────────────────

    public function test_label_formatting_noise_does_not_defeat_the_match(): void
    {
        // Agents and seeders produce all of these.
        foreach (['Under Offer', 'under offer', 'UNDER OFFER', '• Under Offer', 'Under_Offer', '  Under Offer  '] as $label) {
            $this->assertSame(
                'PendingOffer',
                $this->ppStatus('for_sale', $label),
                "label '{$label}' must resolve to PendingOffer"
            );
        }
    }

    public function test_a_null_status_does_not_crash_and_stays_on_market(): void
    {
        $this->assertSame('ForSale', $this->ppStatus(null));
        $this->assertSame('ToLet', $this->ppStatus(null, null, 'Rental'));
    }

    // ── THE DRIFT GUARD ─────────────────────────────────────────────────────

    /**
     * The two portals must never disagree about whether a listing is under offer.
     *
     * That is exactly how AT-68 happened: each mapper resolved the two-tier status
     * independently, P24 got it right, PP never looked at the sub-label at all. The
     * canonical resolver (ListingLifecycle) is now the single source of truth — this
     * test proves P24's live mapper still agrees with it across the whole vocabulary,
     * WITHOUT refactoring P24's proven mapping.
     *
     * If someone changes one side and not the other, this fails.
     */
    public function test_pp_and_p24_never_disagree_about_under_offer(): void
    {
        $statuses = [
            'for_sale', 'to_let', 'under_offer', 'sold', 'transferred', 'rented', 'let_out',
            'withdrawn', 'expired', 'cancelled', 'archived', 'unavailable', 'draft', null,
        ];
        $labels = [null, 'Under Offer', 'Pending', 'Reduced Price', 'Raised Price', 'Back on Market'];

        foreach ($statuses as $status) {
            foreach ($labels as $label) {
                $p24 = Property24ListingMapper::getP24Status($status, 'ref-1', $label);
                $pp  = PrivatePropertyListingMapper::statusFor($this->property($status, $label), 'Sale');

                $p24SaysUnderOffer = ($p24 === 'Pending');
                $ppSaysUnderOffer  = ($pp === 'PendingOffer');

                $this->assertSame(
                    $p24SaysUnderOffer,
                    $ppSaysUnderOffer,
                    sprintf(
                        'PORTAL DRIFT for status=%s label=%s — P24 says "%s", PP says "%s". '
                        . 'Both must agree on whether an offer is in hand.',
                        var_export($status, true),
                        var_export($label, true),
                        $p24,
                        $pp
                    )
                );

                // And the canonical resolver must agree with both.
                $this->assertSame(
                    $p24SaysUnderOffer,
                    ListingLifecycle::isUnderOffer($status, $label),
                    'ListingLifecycle is the source of truth and must match the portals'
                );
            }
        }
    }

    /** Off-market classification must not drift either. */
    public function test_pp_and_p24_agree_on_what_is_off_market(): void
    {
        foreach (['sold', 'withdrawn', 'expired', 'cancelled', 'let_out'] as $status) {
            $this->assertTrue(ListingLifecycle::isOffMarket($status), "{$status} is off-market");
            $this->assertTrue(
                Property24ListingMapper::isTerminalStatus(Property24ListingMapper::getP24Status($status, 'ref-1')),
                "P24 must also treat {$status} as terminal"
            );
            $this->assertSame('Inactive', PrivatePropertyListingMapper::statusFor($this->property($status), 'Sale'));
        }
    }

    public function test_on_market_listings_are_not_off_market_on_either_portal(): void
    {
        foreach ([['for_sale', null], ['for_sale', 'Under Offer'], ['to_let', null]] as [$status, $label]) {
            $this->assertFalse(
                ListingLifecycle::isOffMarket($status, $label),
                "{$status} is still on the market — an offer in hand is not a concluded sale"
            );
        }
    }
}
