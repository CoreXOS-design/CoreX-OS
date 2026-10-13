<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Road to Sold, piece 1 — ONE "has an address" rule for MIC listings
 * (ProspectingListing::scopeWithAddress / scopeWithoutAddress / hasAddress).
 *
 * The drift this closes: an address an agent corrects through
 * TrackedPropertyController::editAddress lands on the tracked property (history row + the
 * cached street_name, via TrackedPropertyAddressObserver) but never on
 * prospecting_listings.address — so the Work tab's "with address" tick kept calling the
 * corrected listing "no address".
 */
final class ProspectingListingAddressRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_listing_text_counts_and_empty_null_or_placeholder_do_not(): void
    {
        [$agencyId] = $this->seedAgency();

        $real        = $this->listing($agencyId, '12 Marine Drive');
        $null        = $this->listing($agencyId, null);
        $empty       = $this->listing($agencyId, '');
        $placeholder = $this->listing($agencyId, ProspectingListing::ADDRESS_PLACEHOLDER);

        $with    = $this->ids(ProspectingListing::withoutGlobalScopes()->withAddress());
        $without = $this->ids(ProspectingListing::withoutGlobalScopes()->withoutAddress());

        $this->assertSame([$real->id], $with);
        $this->assertEqualsCanonicalizing([$null->id, $empty->id, $placeholder->id], $without);

        // The instance helper must agree with the scope row for row.
        foreach ([$real, $null, $empty, $placeholder] as $l) {
            $this->assertSame(in_array($l->id, $with, true), $l->fresh()->hasAddress(), "listing {$l->id}");
        }
    }

    public function test_a_street_on_the_tracked_property_counts_for_a_listing_with_no_text(): void
    {
        [$agencyId] = $this->seedAgency();
        $tp = $this->trackedProperty($agencyId, streetName: 'Beach Road');
        $l  = $this->listing($agencyId, null, $tp->id);

        $this->assertContains($l->id, $this->ids(ProspectingListing::withoutGlobalScopes()->withAddress()));
        $this->assertNotContains($l->id, $this->ids(ProspectingListing::withoutGlobalScopes()->withoutAddress()));
        $this->assertTrue($l->fresh()->hasAddress());
    }

    public function test_a_tracked_property_with_only_a_suburb_or_a_deleted_one_does_not_count(): void
    {
        [$agencyId] = $this->seedAgency();
        $suburbOnly = $this->trackedProperty($agencyId, streetName: null);
        $deletedTp  = $this->trackedProperty($agencyId, streetName: 'Gone Street');
        $deletedTp->delete();

        $a = $this->listing($agencyId, null, $suburbOnly->id);
        $b = $this->listing($agencyId, null, $deletedTp->id);
        $c = $this->listing($agencyId, null, null);

        $without = $this->ids(ProspectingListing::withoutGlobalScopes()->withoutAddress());
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $without);
        $this->assertSame([], $this->ids(ProspectingListing::withoutGlobalScopes()->withAddress()));
        foreach ([$a, $b, $c] as $l) {
            $this->assertFalse($l->fresh()->hasAddress());
        }
    }

    public function test_a_cross_portal_twin_shares_the_address_its_sibling_carries(): void
    {
        [$agencyId] = $this->seedAgency();
        $tp   = $this->trackedProperty($agencyId, streetName: 'Hill Lane');
        $p24  = $this->listing($agencyId, '17 Hill Lane', $tp->id);
        $pp   = $this->listing($agencyId, null, $tp->id, 'pp');

        $with = $this->ids(ProspectingListing::withoutGlobalScopes()->withAddress());
        $this->assertEqualsCanonicalizing([$p24->id, $pp->id], $with);
    }

    public function test_the_scope_works_with_a_table_alias(): void
    {
        [$agencyId] = $this->seedAgency();
        $tp = $this->trackedProperty($agencyId, streetName: 'Beach Road');
        $a  = $this->listing($agencyId, null, $tp->id);
        $b  = $this->listing($agencyId, null, null);

        $ids = ProspectingListing::withoutGlobalScopes()
            ->from('prospecting_listings as pl')
            ->withAddress('pl')
            ->pluck('pl.id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$a->id], $ids);
        $this->assertNotContains($b->id, $ids);
    }

    /** The SQL twin used for ORDER BY in the Road to Sold builder must agree with the scope on every row. */
    public function test_the_sql_expression_agrees_with_the_scope_and_the_instance_helper(): void
    {
        [$agencyId] = $this->seedAgency();
        $street = $this->trackedProperty($agencyId, streetName: 'Beach Road');
        $none   = $this->trackedProperty($agencyId, streetName: null);
        $this->listing($agencyId, '12 Marine Drive');
        $this->listing($agencyId, null, $street->id);
        $this->listing($agencyId, null, $none->id);
        $this->listing($agencyId, '', null);
        $this->listing($agencyId, ProspectingListing::ADDRESS_PLACEHOLDER, $none->id);

        $viaScope = $this->ids(ProspectingListing::withoutGlobalScopes()->withAddress());
        $viaSql = DB::table('prospecting_listings as pl')
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'pl.tracked_property_id')
            ->whereRaw(ProspectingListing::hasAddressSql('pl', 'tp') . ' = 1')
            ->pluck('pl.id')->map(fn ($v) => (int) $v)->all();

        $this->assertEqualsCanonicalizing($viaScope, $viaSql);
        $this->assertCount(2, $viaSql);
    }

    /**
     * The real path end to end: an agent corrects the address through the real route, and the
     * Work tab's "with address" tick then lists the listing. Before this change it did not.
     */
    public function test_an_address_corrected_through_edit_address_counts_on_the_work_tab(): void
    {
        [$agencyId, $viewer] = $this->seedAgency();
        $tp      = $this->trackedProperty($agencyId, streetName: null);
        $listing = $this->listing($agencyId, null, $tp->id);

        // Before: no address anywhere → filtered out by the tick.
        $before = $this->actingAs($viewer)->get(route('market-intelligence.work', ['address_filter' => 'with_address']));
        $before->assertOk();
        $this->assertNotContains($listing->id, $this->workIds($before));

        $this->actingAs($viewer)
            ->post(route('corex.tracked-properties.address.edit', $tp->id), [
                'street_number' => '5',
                'street_name'   => 'Corrected Avenue',
                'suburb'        => 'Margate',
                'notes'         => 'Agent confirmed the street on site',
            ])->assertRedirect();

        // The listing's own text is still empty — the rule must not need it.
        $this->assertNull(DB::table('prospecting_listings')->where('id', $listing->id)->value('address'));

        $after = $this->actingAs($viewer)->get(route('market-intelligence.work', ['address_filter' => 'with_address']));
        $after->assertOk();
        $this->assertContains($listing->id, $this->workIds($after));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $viewer = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        return [$agencyId, $viewer];
    }

    private function trackedProperty(int $agencyId, ?string $streetName): TrackedProperty
    {
        return TrackedProperty::withoutAgencyStamping(fn () => TrackedProperty::create([
            'agency_id'     => $agencyId,
            'street_number' => $streetName ? '1' : null,
            'street_name'   => $streetName,
            'suburb'        => 'Margate',
            'source_chain'  => [],
        ]));
    }

    private function listing(int $agencyId, ?string $address, ?int $trackedPropertyId = null, string $source = 'p24'): ProspectingListing
    {
        $capturedBy = (int) DB::table('users')->where('agency_id', $agencyId)->value('id');
        $id = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $agencyId,
            'tracked_property_id' => $trackedPropertyId,
            'portal_source'       => $source,
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $capturedBy,
            'is_active'           => true,
            'address'             => $address,
            'suburb'              => 'Margate',
            'price'               => 1000000,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        return ProspectingListing::withoutGlobalScopes()->findOrFail($id);
    }

    /** @return int[] */
    private function ids($query): array
    {
        return $query->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return int[] */
    private function workIds($response): array
    {
        return collect($response->viewData('listings')->items())->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
