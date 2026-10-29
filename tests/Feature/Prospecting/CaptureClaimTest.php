<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Events\Prospecting\ClaimCreated;
use App\Models\Agency;
use App\Models\AddressMatchSetting;
use App\Models\Branch;
use App\Models\ProspectingClaim;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAgencyWork as Work;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\CaptureClaimService as Svc;
use App\Services\RoadToSold\ProspectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * property-identity.md section 15.2, C2: a property an agent captures (deeds capture, or added by hand) that is the same property as one already
 * in Market Intelligence claims that record for the agent. Real claim rows, the agency's work record, a Road to Sold prospect at Found; no
 * claim-taken points; only a confident match; never over another agent; never across agencies.
 */
final class CaptureClaimTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->agentB = $this->makeAgent($this->b, $branchB);
    }

    /** A Market Intelligence property (tracked property + two adverts) in the given agency. @return array{0:TrackedProperty,1:list<int>} */
    private function micProperty(?Agency $agency = null, array $attrs = []): array
    {
        $tp = $this->tp(array_merge([
            'street_number' => '12', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach', 'suburb_normalised' => 'uvongo beach', 'erf_number' => '234',
        ], $attrs), $agency);

        return [$tp, [$this->listing(['suburb' => 'Uvongo Beach'], $tp, $agency), $this->listing(['suburb' => 'Uvongo Beach', 'is_active' => 0], $tp, $agency)]];
    }

    private function capture(User $as, string $ref = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson(route('v1.deeds-capture'), [
            'source' => 'cmainfo',
            'captures' => [['source_ref' => $ref ?? 'ERF-' . Str::random(8), 'property' => [
                'street_number' => '12', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach', 'erf_number' => '234',
            ]]],
        ]);
    }

    private function claimsOn(array $listingIds, ?int $agencyId = null)
    {
        return ProspectingClaim::withoutGlobalScopes()->whereIn('prospecting_listing_id', $listingIds)->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId));
    }

    public function test_a_deeds_capture_that_matches_a_mic_property_claims_every_advert_for_the_capturing_agent(): void
    {
        [$tp, $listings] = $this->micProperty();
        Event::fake([ClaimCreated::class]);

        $this->capture($this->agent)->assertOk();

        $claims = $this->claimsOn($listings, $this->agency->id)->get();
        $this->assertCount(2, $claims, 'one claim per advert of the property');
        $this->assertSame([$this->agent->id], $claims->pluck('user_id')->unique()->values()->all());
        $this->assertTrue($claims->every(fn ($c) => $c->is_active && $c->released_at === null && $c->status === 'claimed'));
        Event::assertNotDispatched(ClaimCreated::class);   // an automatic claim is not an act the agent took: no "claim taken" points

        $work = app(AgencyWorkService::class)->find($this->agency->id, $tp->id);
        $this->assertSame($this->agent->id, (int) $work->claimed_by_user_id);
        $this->assertNotNull($work->claimed_at);
        $this->assertFalse((bool) $work->is_own_stock, 'own stock is lane B\'s derived flag and is untouched');
        $line = app(AgencyWorkService::class)->history($this->agency->id, $tp->id)->firstWhere('kind', 'claim');
        $this->assertSame('Claimed from a deeds capture', $line->reason);

        // Road to Sold: a prospect for the agent at Found
        $p = app(ProspectService::class)->find($this->agency->id, $tp->id, 'sales');
        $this->assertNotNull($p, 'Road to Sold has the property');
        $this->assertSame($this->agent->id, (int) $p->user_id);
        $this->assertSame('found', $p->stage);
        $this->assertSame('claimed_deeds', $p->selection);
    }

    public function test_a_capture_that_creates_a_new_property_claims_nothing(): void
    {
        $this->capture($this->agent)->assertOk();   // no MIC property exists

        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->count());
        $this->assertSame(0, Work::withoutGlobalScopes()->whereNotNull('claimed_by_user_id')->count());
    }

    public function test_a_second_capture_does_not_double_claim(): void
    {
        [, $listings] = $this->micProperty();
        $this->capture($this->agent)->assertOk();
        $this->capture($this->agent)->assertOk();

        $this->assertCount(2, $this->claimsOn($listings)->get());
    }

    public function test_a_property_another_agent_already_holds_is_not_taken_over(): void
    {
        [$tp, $listings] = $this->micProperty();
        ProspectingClaim::withoutEvents(fn () => ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listings[0], 'user_id' => $this->mate->id,
            'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true,
        ]));

        $this->capture($this->agent)->assertOk();

        $this->assertSame([$this->mate->id], $this->claimsOn($listings)->pluck('user_id')->all(), "the mate's claim stands and nothing was added");
        $r = app(Svc::class)->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'exact', false);
        $this->assertSame(Svc::OUTCOME_HELD_BY_OTHER, $r['outcome']);
        $this->assertSame($this->mate->id, $r['holder_user_id']);
    }

    public function test_a_live_pitch_lock_by_another_agent_also_holds_it(): void
    {
        [$tp, $listings] = $this->micProperty();
        DB::table('prospecting_pitch_locks')->insert([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $listings[0], 'tracked_property_id' => $tp->id, 'user_id' => $this->mate->id,
            'locked_at' => now(), 'expires_at' => now()->addHours(2), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = app(Svc::class)->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'exact', false);

        $this->assertSame(Svc::OUTCOME_HELD_BY_OTHER, $r['outcome']);
        $this->assertSame(0, $this->claimsOn($listings)->count());
    }

    public function test_only_a_confident_enough_match_claims_and_the_agency_can_raise_the_bar(): void
    {
        [$tp, $listings] = $this->micProperty();
        $svc = app(Svc::class);

        $this->assertSame(Svc::OUTCOME_BELOW_THRESHOLD, $svc->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'probable', false)['outcome']);
        $this->assertSame(Svc::OUTCOME_BELOW_THRESHOLD, $svc->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'weak', false)['outcome']);
        $this->assertSame(Svc::OUTCOME_BELOW_THRESHOLD, $svc->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', null, false)['outcome']);
        $this->assertSame(0, $this->claimsOn($listings)->count());

        // the standard bar is "high"; this agency asks for certain matches only
        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->agency->id], array_merge(AddressMatchSetting::DEFAULTS, ['auto_claim_min_confidence' => 'exact']));
        $this->assertSame(Svc::OUTCOME_BELOW_THRESHOLD, $svc->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'high', false)['outcome']);
        $this->assertSame(Svc::OUTCOME_CLAIMED, $svc->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'exact', false)['outcome']);
    }

    public function test_the_standard_setting_is_high(): void
    {
        $this->assertSame('high', AddressMatchSetting::forAgency($this->agency->id)['auto_claim_min_confidence']);
    }

    public function test_the_settings_page_saves_the_bar(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $base = array_intersect_key(AddressMatchSetting::forAgency($this->agency->id), array_flip(['possible_min_agreeing_columns', 'neighbour_suburb_credit', 'gps_radius_m', 'unit_missing_on_one_side', 'fuzzy_threshold', 'auto_merge_min_confidence']));

        $this->actingAs($admin)->get(route('settings.prospecting.address-matching.edit'))->assertOk()->assertSee('Claim a Market Intelligence property for the agent who captures it');
        $this->actingAs($admin)->put(route('settings.prospecting.address-matching.update'), $base + ['auto_claim_min_confidence' => 'exact'])->assertRedirect();

        $this->assertSame('exact', AddressMatchSetting::forAgency($this->agency->id)['auto_claim_min_confidence']);
        $this->assertSame('high', AddressMatchSetting::forAgency($this->b->id)['auto_claim_min_confidence'], "another agency's bar is its own");
    }

    public function test_a_property_added_by_hand_that_is_a_mic_property_claims_it_for_the_agent_who_added_it(): void
    {
        config(['property-identity.link_on_create' => true]);
        [$tp, $listings] = $this->micProperty();

        $this->actingAs($this->agent);
        $p = new Property();
        foreach (['external_id' => 'P-' . Str::random(8), 'title' => 'T', 'address' => '12 Bairn Street', 'street_number' => '12', 'street_name' => 'Bairn Street',
                  'suburb' => 'Uvongo Beach', 'erf_number' => '234', 'price' => 1_500_000, 'property_type' => 'house', 'beds' => 3, 'status' => 'active',
                  'is_demo' => false, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->mate->id] as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        $this->assertSame($tp->id, (int) $p->fresh()->tracked_property_id, 'the door linked the new property to the MIC property');
        $this->assertSame([$this->agent->id], $this->claimsOn($listings)->pluck('user_id')->unique()->values()->all(), 'claimed for the person who added it');
        $line = app(AgencyWorkService::class)->history($this->agency->id, $tp->id)->firstWhere('kind', 'claim');
        $this->assertSame('Claimed from a property added by hand', $line->reason);
    }

    public function test_a_property_added_with_nobody_signed_in_is_not_a_hand_capture(): void
    {
        config(['property-identity.link_on_create' => true]);
        [, $listings] = $this->micProperty();

        $p = new Property();
        foreach (['external_id' => 'P-' . Str::random(8), 'title' => 'T', 'address' => '12 Bairn Street', 'street_number' => '12', 'street_name' => 'Bairn Street',
                  'suburb' => 'Uvongo Beach', 'erf_number' => '234', 'price' => 1, 'property_type' => 'house', 'status' => 'active', 'is_demo' => false,
                  'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->mate->id] as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        $this->assertSame(0, $this->claimsOn($listings)->count(), 'an import or a job claims nothing');
    }

    public function test_agency_privacy_the_other_agencys_copy_of_the_property_is_never_claimed_or_changed(): void
    {
        [$tpA, $listingsA] = $this->micProperty();
        [$tpB, $listingsB] = $this->micProperty($this->b);
        $before = [
            'work' => Work::withoutGlobalScopes()->where('agency_id', $this->b->id)->count(),
            'claims' => $this->claimsOn($listingsB)->count(),
            'rts' => DB::table('rts_prospects')->where('agency_id', $this->b->id)->count(),
        ];

        $this->capture($this->agent)->assertOk();

        $this->assertCount(2, $this->claimsOn($listingsA, $this->agency->id)->get());
        $this->assertSame($before['claims'], $this->claimsOn($listingsB)->count(), "agency B's adverts: no claim");
        $this->assertSame($before['work'], Work::withoutGlobalScopes()->where('agency_id', $this->b->id)->count(), "agency B has no work record made");
        $this->assertSame($before['rts'], DB::table('rts_prospects')->where('agency_id', $this->b->id)->count());
        $this->assertSame(0, $this->claimsOn($listingsA)->where('agency_id', $this->b->id)->count());
        // and B's own agent's MIC view of A's property: nothing (the claim row is A's)
        $this->actingAs($this->agentB);
        $this->assertSame(0, ProspectingClaim::query()->count(), "B's agent sees none of A's claims");
    }

    public function test_the_property_screen_says_who_holds_it_and_where_the_claim_came_from(): void
    {
        [$tp] = $this->micProperty();
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->capture($this->agent)->assertOk();

        $this->actingAs($admin)->get(route('market-intelligence.opportunities.show', $tp->id))->assertOk()
            ->assertSee($this->agent->name)->assertSee('Claimed from a deeds capture');
    }

    public function test_road_to_sold_leaves_a_property_another_agent_already_works_alone(): void
    {
        [$tp] = $this->micProperty();
        app(ProspectService::class)->place($this->mate, $tp->id, 'sales', ['selection' => 'manual']);

        $r = app(Svc::class)->claim($this->agency->id, $tp->id, $this->agent->id, 'deeds', 'exact', false);

        $this->assertSame(Svc::OUTCOME_CLAIMED, $r['outcome'], 'the MIC claim is still made');
        $this->assertSame('owned_by_other', $r['road_to_sold']);
        $this->assertSame($this->mate->id, (int) app(ProspectService::class)->find($this->agency->id, $tp->id, 'sales')->user_id);
    }
}
