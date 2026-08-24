<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Map\MapProspectStatusService;
use App\Services\Matching\MatchingService;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-350 audit regressions — a competitor's sale is NEVER treated as ours.
 *
 * Every case here is a defect found by auditing the blast radius AFTER the
 * feature was built and its own tests were green, which is the point: the
 * feature's tests proved the new status behaved correctly on the surfaces the
 * feature touched. These are the surfaces it did NOT touch, that nonetheless
 * changed meaning the moment a new value entered `properties.status` and a
 * not-ours row entered `property_sold_records`.
 *
 * The shared root cause is one pattern — an EXACT-MATCH list of status literals,
 * maintained by hand, several of them carrying "fix-the-class" comments while
 * duplicating a constant instead of reading it. A new enum value is invisible to
 * every one of them, and every failure is silent.
 */
final class ThirdPartySaleExclusionsTest extends TestCase
{
    use RefreshDatabase;

    // ── #2 — the generated marketing ad card ────────────────────────────────

    public function test_an_ad_card_never_advertises_a_third_party_sale_as_for_sale(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '14 Marine Drive, Margate');

        $property->status = Property::STATUS_SOLD_BY_3RD_PARTY;
        $property->save();

        // Property::adData()'s status badge is a match(true) whose 'sold' arm is
        // an exact in_array — so before the fix this value fell through every arm
        // to the default and printed "FOR SALE" on an agency-branded ad for a
        // house that had already changed hands. Third instance of the same
        // landmine as statusBadge() and getP24Status().
        $this->assertSame('SOLD', $property->fresh()->adData()['status_badge']);
    }

    public function test_our_own_sale_still_reads_sold_on_an_ad_card(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '2 Boyes Lane, Margate');

        $property->status = 'sold';
        $property->save();
        $this->assertSame('SOLD', $property->fresh()->adData()['status_badge']);

        // And a live listing is untouched by the new arm.
        $property->status = 'active';
        $property->save();
        $this->assertSame('FOR SALE', $property->fresh()->adData()['status_badge']);
    }

    // ── #3 — the public website API ────────────────────────────────────────

    public function test_a_third_party_sale_is_never_served_to_an_agency_website(): void
    {
        $agency = Agency::create([
            'name' => 'Coastal Realty ' . Str::random(4),
            'slug' => 'coastal-' . Str::random(8),
            'website_enabled' => true,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'agent', 'show_on_website' => true,
        ]);

        $minted = AgencyApiKey::mintSecret();
        $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'name' => 'Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);

        $ours   = $this->websiteListing($agency, $branch, $agent, $key, 'ZZZ-OurSale', 'sold');
        $theirs = $this->websiteListing($agency, $branch, $agent, $key, 'ZZZ-TheirSale', Property::STATUS_SOLD_BY_3RD_PARTY);

        $titles = collect(
            $this->withToken($minted['plaintext'])
                ->getJson('/api/v1/website/listings')
                ->assertOk()
                ->json('data')
        )->pluck('title');

        // The pivot is deliberately left ENABLED here: PropertyObserver delists a
        // third-party sale, but through a QUEUED job. NEVER_PUBLIC_STATUSES is the
        // synchronous net for exactly the case where that job never ran — a
        // stopped worker has stranded thousands of jobs on this system before.
        // A competitor's sale in the agency's own "recently sold" wall is the most
        // misleading thing the site could tell a prospective seller.
        $this->assertContains('ZZZ-OurSale', $titles->all(), 'An agency still showcases its OWN sold stock.');
        $this->assertNotContains('ZZZ-TheirSale', $titles->all(), "A competitor's sale must never reach the website.");

        // Nor by asking for it explicitly.
        $filtered = collect(
            $this->withToken($minted['plaintext'])
                ->getJson('/api/v1/website/listings?status=' . Property::STATUS_SOLD_BY_3RD_PARTY)
                ->assertOk()
                ->json('data')
        )->pluck('title');

        $this->assertNotContains('ZZZ-TheirSale', $filtered->all());
    }

    // ── #4 — buyer matching ────────────────────────────────────────────────

    public function test_a_third_party_sale_is_not_matchable_to_buyers(): void
    {
        // Otherwise agents keep receiving match emails offering their buyers a
        // house that has already changed hands — the identical leak this
        // constant's own comment records fixing for 769 'Sold' rows.
        $this->assertFalse(MatchingService::isMatchableStatus(Property::STATUS_SOLD_BY_3RD_PARTY));
        $this->assertFalse(MatchingService::isMatchableStatus('Sold by 3rd Party'));

        // Unchanged for everything else.
        $this->assertFalse(MatchingService::isMatchableStatus('sold'));
        $this->assertTrue(MatchingService::isMatchableStatus('active'));
        $this->assertTrue(MatchingService::isMatchableStatus(''));
    }

    // ── #5 — the agent conversion funnel ───────────────────────────────────

    public function test_a_lost_listing_is_not_counted_as_the_agents_closed_deal(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $ours   = $this->property($agencyId, $agent, '9 Ridge Road, Shelly Beach');
        $theirs = $this->property($agencyId, $agent, '11 Ridge Road, Shelly Beach');

        // Our sale.
        $this->soldRecord($agencyId, $agent, $ours, 2_400_000, thirdParty: false);
        // Their sale — recorded BY our agent, which is the trap: the funnel keyed
        // on captured_by_user_id, so recording a LOSS credited the agent with a
        // closed deal and inverted the number the funnel exists to measure.
        $this->soldRecord($agencyId, $agent, $theirs, 2_150_000, thirdParty: true);

        $funnel = collect(app(ReportingService::class)->getConversionFunnel(
            ['user_id' => $agent->id, 'agency_id' => $agencyId],
            365
        ));

        $closed = $funnel->firstWhere('stage', 'Deal Closed');

        $this->assertNotNull($closed, 'The funnel must expose a Deal Closed stage.');
        $this->assertSame(1, (int) $closed['count'], 'Only OUR sale may count as a closed deal.');
    }

    // ── #6 — the prospecting map ───────────────────────────────────────────

    public function test_a_house_a_competitor_just_sold_is_not_offered_as_a_prospect(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();
        $property = $this->property($agencyId, $agent, '31 Outlook Road, Ramsgate');

        // resolve() reaches a Property either through a TrackedProperty link or
        // the ~20m GPS fallback box. Give it real Ramsgate coordinates so the
        // test exercises the same path the map does.
        $property->latitude  = -30.86321;
        $property->longitude = 30.37154;
        $property->status    = Property::STATUS_SOLD_BY_3RD_PARTY;
        $property->save();

        DB::table('property_third_party_sales')->where('property_id', $property->id)->update([
            'sold_date'      => now()->subDays(21)->toDateString(),
            'sold_by_agency' => 'Seeff Margate',
        ]);

        // resolve() finds the property by GPS (the ~20m fallback box), which is
        // how the map actually reaches it — testing through the real entry point
        // rather than a method invented for the test.
        $state = app(MapProspectStatusService::class)->resolve(
            ['latitude' => -30.86321, 'longitude' => 30.37154, 'suburb' => 'Ramsgate'],
            $agencyId,
            (int) $agent->id
        );

        // Falling through to 'available' invited an agent to go door-knock a house
        // that had changed hands three weeks earlier.
        $this->assertSame('previously_sold', $state['status']);

        // A third-party sale produces NO deal by definition (that is the point —
        // we earned nothing), so the deals lookup can never date it. The loss
        // record is the only place that date exists.
        $this->assertNotNull($state['sale_date'], 'The sale date must come from the loss record.');
        $this->assertStringStartsWith(
            now()->subDays(21)->format('Y-m-d'),
            (string) $state['sale_date']
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAgent(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Home Finders ' . Str::random(6),
            'slug'       => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title): Property
    {
        return Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'suburb'        => 'Margate',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'price'         => 2_450_000,
            'beds'          => 3,
            'baths'         => 2,
        ]);
    }

    private function websiteListing(Agency $agency, Branch $branch, User $agent, AgencyApiKey $key, string $title, string $status): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1_950_000,
            'beds' => 3, 'baths' => 2, 'published_at' => now(),
        ]);

        PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'property_id' => $p->id, 'agency_api_key_id' => $key->id,
            'enabled' => true, 'status' => PropertyWebsiteSyndication::STATUS_ACTIVE,
        ]);

        return $p;
    }

    private function soldRecord(int $agencyId, User $agent, Property $property, int $price, bool $thirdParty): void
    {
        DB::table('property_sold_records')->insert([
            'property_id'         => $property->id,
            'agency_id'           => $agencyId,
            'address'             => $property->title,
            'suburb'              => $property->suburb,
            'sold_price'          => $price,
            'sold_date'           => now()->subDays(10)->toDateString(),
            'source'              => 'manual',
            'sold_by_third_party' => $thirdParty ? 1 : 0,
            'captured_by_user_id' => $agent->id,
            'captured_at'         => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }
}
