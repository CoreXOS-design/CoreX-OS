<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live preview (/corex/properties/{id}/preview/{slug}) — a rental is never
 * advertised as a sale.
 *
 * The reported bug: property 6075 ("…to let in Uvongo", listing_type=rental,
 * status=active) rendered a "For Sale" badge on its live preview — the page an
 * agent sends to a client. The preview mapped status→badge with a hardcoded
 * 'active' => 'For Sale' and never looked at listing_type at all, so EVERY
 * active rental was advertised for sale. Two more sale-assumptions sat on the
 * same page: the "Asking price" label, and a bond calculator offering a tenant
 * a repayment schedule on a purchase that will never happen.
 *
 * The class of bug underneath it: "is this a rental?" was answered inline, in
 * six places, with divergent vocabularies and case-sensitive comparisons —
 * while the P24 CSV importer writes a CAPITALISED 'Rental'. Property::isRental()
 * is now the single source of truth, and these tests pin both the page and the
 * predicate.
 */
final class PropertyLivePreviewListingTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_rental_is_advertised_to_let_not_for_sale(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $rental = $this->property($agencyId, $agent, 'ZZZ-Penthouse-To-Let', [
            'listing_type'  => 'rental',
            'status'        => 'active',
            'rental_amount' => 13900,
        ]);

        $res = $this->get($this->previewUrl($rental))->assertOk();

        // The over-gallery badge — the thing the client reads first.
        $res->assertSee('To Let', false);
        $res->assertDontSee('For Sale', false);

        // The price card names the right kind of money.
        $res->assertSee('Monthly rental', false);
        $res->assertDontSee('Asking price', false);
        $res->assertSee('R 13 900', false);
        $res->assertSee('/ month', false);
    }

    /** A tenant takes out no bond. The calculator is a sales instrument. */
    public function test_the_bond_calculator_is_absent_from_a_rental(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $rental = $this->property($agencyId, $agent, 'ZZZ-Rental-No-Bond', [
            'listing_type'  => 'rental',
            'rental_amount' => 13900,
            // The sale column is populated too — as it is on the real property
            // 6075. The page must key off listing_type, not off "is price set?",
            // or the calculator returns the moment a rental carries a price.
            'price'         => 13900,
        ]);

        $res = $this->get($this->previewUrl($rental))->assertOk();

        $res->assertDontSee('Bond calculator', false);
        $res->assertDontSee('mortgageCalc(', false);
    }

    public function test_a_sale_still_reads_for_sale_and_keeps_its_bond_calculator(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $sale = $this->property($agencyId, $agent, 'ZZZ-House-For-Sale', [
            'listing_type' => 'sale',
            'status'       => 'active',
            'price'        => 2450000,
        ]);

        $res = $this->get($this->previewUrl($sale))->assertOk();

        $res->assertSee('For Sale', false);
        $res->assertDontSee('To Let', false);
        $res->assertSee('Asking price', false);
        $res->assertSee('R 2 450 000', false);
        $res->assertSee('Bond calculator', false);
        // Seeded with the sale price, not a zero.
        $res->assertSee('mortgageCalc(2450000)', false);
    }

    /**
     * The landmine that made this a class of bug rather than one typo: the P24
     * CSV importer (P24ListingsCsvParser) writes 'Rental' with a capital R,
     * while every check compared case-sensitively against 'rental'. An imported
     * rental therefore failed every rental test in the codebase and was rendered,
     * priced, and deep-linked as a sale.
     */
    public function test_an_importer_cased_rental_is_still_a_rental(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $imported = $this->property($agencyId, $agent, 'ZZZ-Imported-Rental', [
            'listing_type'  => 'Rental',   // exactly as P24ListingsCsvParser stores it
            'rental_amount' => 8500,
            'price'         => null,
        ]);

        $res = $this->get($this->previewUrl($imported))->assertOk();

        $res->assertSee('To Let', false);
        $res->assertDontSee('For Sale', false);
        $res->assertSee('R 8 500', false);
        $res->assertDontSee('Bond calculator', false);
    }

    /** Dead/interim states mean the same on both sides of the sale/rental line. */
    public function test_a_dead_status_outranks_the_listing_type_on_the_badge(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $sold = $this->property($agencyId, $agent, 'ZZZ-Sold-Rental', [
            'listing_type'  => 'rental',
            'status'        => 'sold',
            'rental_amount' => 9000,
        ]);

        $res = $this->get($this->previewUrl($sold))->assertOk();

        $res->assertSee('Sold', false);
        $res->assertDontSee('To Let', false);
        $res->assertDontSee('For Sale', false);
    }

    /**
     * `status` is mixed-case in production too — the P24 sync writes 'Active'
     * (444 rows live) while the wizard writes 'active'. The preview's badge map
     * was keyed on the RAW column, so a capitalised status missed every key and
     * the fallback printed the internal value straight back at the client.
     */
    public function test_a_capitalised_status_still_resolves_to_a_marketing_badge(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $rental = $this->property($agencyId, $agent, 'ZZZ-Caps-Active-Rental', [
            'status'        => 'Active',   // exactly as the P24 sync stores it
            'listing_type'  => 'rental',
            'rental_amount' => 11000,
        ]);

        $res = $this->get($this->previewUrl($rental))->assertOk();

        $res->assertSee('To Let', false);
        // The raw internal value must never be shown to a client.
        $res->assertDontSee('>Active<', false);
        $res->assertDontSee('For Sale', false);
    }

    /**
     * The worst instance of the casing bug: 60 live properties are stored 'Sold'
     * with a capital S, and every badge compared case-sensitively against
     * lowercase 'sold' — so they fell through to the default arm and were
     * advertised FOR SALE on the pickers and on generated ad cards.
     */
    public function test_a_capitalised_sold_is_never_advertised_for_sale(): void
    {
        $sold = new Property(['status' => 'Sold', 'listing_type' => 'sale']);

        $this->assertTrue($sold->isConcluded());
        $this->assertSame('Sold', $sold->statusBadge());          // picker
        $this->assertSame('SOLD', $sold->adData()['status_badge']); // generated ad card

        // The lowercase twin must be unaffected — this is a widening, not a swap.
        $lower = new Property(['status' => 'sold', 'listing_type' => 'sale']);
        $this->assertSame('Sold', $lower->statusBadge());
        $this->assertSame('SOLD', $lower->adData()['status_badge']);
    }

    /** A tenanted property is not "To Let". Rented/let_out are the rental "sold". */
    public function test_a_concluded_rental_is_not_advertised_as_available(): void
    {
        foreach (['rented', 'Rented', 'let_out'] as $status) {
            $p = new Property(['status' => $status, 'listing_type' => 'rental']);

            $this->assertTrue($p->isConcluded(), "'{$status}' must count as concluded.");
            $this->assertNotSame('To Let', $p->statusBadge(),
                "'{$status}' is tenanted — it must never advertise as To Let.");
        }
    }

    /** No client is ever shown a raw DB value like "Let_out" or "Under_offer". */
    public function test_the_preview_badge_never_leaks_a_raw_underscored_status(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $p = $this->property($agencyId, $agent, 'ZZZ-Underscore-Status', [
            'status'       => 'under_offer',
            'listing_type' => 'sale',
            'price'        => 990000,
        ]);

        $res = $this->get($this->previewUrl($p))->assertOk();

        $res->assertSee('Under Offer', false);
        $res->assertDontSee('under_offer', false);
        $res->assertDontSee('Under_offer', false);
    }

    /**
     * Property::isRental() is THE predicate. Pin the whole vocabulary, because
     * the column is not normalised on write and each writer picked its own.
     */
    public function test_is_rental_is_case_insensitive_across_the_whole_vocabulary(): void
    {
        foreach (['rental', 'Rental', 'RENTAL', ' rental ', 'to_let', 'to-let', 'lease'] as $value) {
            $this->assertTrue(
                (new Property(['listing_type' => $value]))->isRental(),
                "listing_type '{$value}' must be recognised as a rental.",
            );
        }

        foreach (['sale', 'Sale', 'SALE', null, ''] as $value) {
            $this->assertFalse(
                (new Property(['listing_type' => $value]))->isRental(),
                "listing_type '" . var_export($value, true) . "' must NOT be a rental.",
            );
        }
    }

    /**
     * effectivePrice() reads the rental column for rentals. Before isRental() it
     * lowercased but did not accept 'to_let', so a to-let listing quietly priced
     * itself off the (empty) sale column and advertised R 0.
     */
    public function test_effective_price_follows_the_same_predicate_as_the_badge(): void
    {
        $toLet = new Property(['listing_type' => 'to_let', 'rental_amount' => 7200, 'price' => 0]);
        $this->assertSame(7200.0, $toLet->effectivePrice());
        $this->assertSame('To Let', $toLet->statusBadge());

        $imported = new Property(['listing_type' => 'Rental', 'rental_amount' => 8500, 'price' => null]);
        $this->assertSame(8500.0, $imported->effectivePrice());
        $this->assertSame('To Let', $imported->statusBadge());

        $sale = new Property(['listing_type' => 'sale', 'price' => 2450000, 'rental_amount' => null]);
        $this->assertSame(2450000.0, $sale->effectivePrice());
        $this->assertSame('For Sale', $sale->statusBadge());
    }

    /**
     * The "Show my info" share link bakes the SHARING agent's id into the URL
     * (?agent=<id>), not the session-relative ?agent=me.
     *
     * The reported bug: a colleague (not the listing agent) opened "Show my info"
     * and copied the link. In their own browser it showed their details, but the
     * link carried ?agent=me — and the preview route is PUBLIC (no auth), so when
     * anyone else opened it, `me` resolved against their empty session and fell
     * back to the LISTING agent. The recipient saw the wrong agent's contact card.
     *
     * A baked-in numeric id must survive being opened by an unauthenticated viewer.
     */
    public function test_a_shared_link_shows_the_sharing_agent_not_the_listing_agent(): void
    {
        [$agencyId, $listingAgent] = $this->agencyWithAgent();
        $listingAgent->forceFill(['name' => 'Listing Owner'])->save();

        $sharer = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'agent',
            'name'      => 'Sharing Colleague',
        ]);

        $property = $this->property($agencyId, $listingAgent, 'ZZZ-Shared-By-Colleague', [
            'listing_type' => 'sale',
            'price'        => 1_500_000,
        ]);

        // Opened by nobody (public) — exactly the shared-link case that was broken.
        $res = $this->get($this->previewUrl($property) . '?agent=' . $sharer->id)->assertOk();

        $res->assertSee('Sharing Colleague', false);
        $res->assertDontSee('Listing Owner', false);
    }

    /**
     * A public page must never surface a cross-agency contact. An ?agent=<id>
     * pointing at an agent in ANOTHER agency is ignored — the resolver is scoped
     * to the property's agency and falls back to this property's listing agent.
     */
    public function test_a_cross_agency_agent_id_is_ignored_and_falls_back_to_the_listing_agent(): void
    {
        [$agencyId, $listingAgent] = $this->agencyWithAgent();
        $listingAgent->forceFill(['name' => 'Listing Owner'])->save();

        [$otherAgencyId, $foreign] = $this->agencyWithAgent();
        $foreign->forceFill(['name' => 'Foreign Agent'])->save();

        $property = $this->property($agencyId, $listingAgent, 'ZZZ-CrossAgency-Preview', [
            'listing_type' => 'sale',
            'price'        => 1_000_000,
        ]);

        $res = $this->get($this->previewUrl($property) . '?agent=' . $foreign->id)->assertOk();

        $res->assertSee('Listing Owner', false);
        $res->assertDontSee('Foreign Agent', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function previewUrl(Property $p): string
    {
        return route('corex.properties.preview', [$p, Str::slug((string) $p->title)]);
    }

    /** @return array{0:int,1:User} */
    private function agencyWithAgent(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'agent',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'property_type' => 'apartment',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            // The preview is gated on marketing readiness; a snapshot makes it
            // marketable without standing up the whole compliance fixture.
            'compliance_snapshot_at' => now(),
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
