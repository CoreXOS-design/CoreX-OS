<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\Matching\UnitSplitReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.5 (mic-work-tab.md section 4): a property file that holds many adverts with a wide price spread is offered for splitting into one
 * property per flat - review only, split on the agent's button, "keep together" remembered, one agency never sees another's.
 */
final class UnitSplitReviewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $admin;
    private int $n = 117000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function block(?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_number' => '1', 'street_name' => 'Ocean Club Drive', 'suburb' => 'Zimbali']);
    }

    private function advert(TrackedProperty $tp, int $beds, int $price, int $baths = 2): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'suburb' => 'Zimbali', 'price' => $price, 'bedrooms' => $beds, 'bathrooms' => $baths, 'property_type' => 'Apartment',
            'first_seen_at' => now()->subDays(100 - ($this->n % 50)), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'])->save();

        return $l->fresh();
    }

    /** two 2-bed adverts, two 3-bed adverts, one 1-bed: 3 flats (5 adverts, 3.1x spread) */
    private function threeFlats(TrackedProperty $tp): array
    {
        return [$this->advert($tp, 2, 900_000), $this->advert($tp, 2, 950_000), $this->advert($tp, 3, 2_400_000), $this->advert($tp, 3, 2_500_000), $this->advert($tp, 1, 750_000)];
    }

    private function review(?Agency $agency = null, bool $apply = true): array
    {
        return app(UnitSplitReview::class)->run(($agency ?? $this->a)->id, $apply);
    }

    private function proposals(): int
    {
        return DB::table('property_match_decisions')->where('subject_type', 'identity_possible')->whereNull('confirmed_at')->whereNull('rejected_at')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(incoming_facts, '$.kind')) = 'unit_split'")->count();
    }

    public function test_a_block_with_several_flats_is_proposed_for_splitting_and_the_biggest_flat_stays(): void
    {
        $tp = $this->block();
        [$twoA, $twoB, $threeA, $threeB, $one] = $this->threeFlats($tp);

        $r = $this->review();

        $this->assertSame(1, $r['files_proposed']);
        $this->assertSame(2, $r['proposals'], 'the 3-bed pair and the 1-bed leave; the biggest group stays');
        $this->assertSame(2, $this->proposals());
        $this->assertSame($tp->id, (int) DB::table('prospecting_listings')->where('id', $twoA->id)->value('tracked_property_id'), 'nothing moves until the agent decides');
        $this->assertSame(0, $this->review()['proposals'], 'running it again does not queue the same proposals twice');
        $this->assertNotNull($twoB);
        $this->assertNotNull($threeA);
        $this->assertNotNull($threeB);
        $this->assertNotNull($one);
    }

    public function test_price_changes_of_one_home_and_small_files_are_not_proposed(): void
    {
        $tp = $this->block();
        foreach ([1_000_000, 1_300_000, 1_700_000, 2_200_000, 2_900_000] as $p) {
            $this->advert($tp, 3, $p); // one 3-bed advertised and re-priced: each step is within the single-property gap
        }
        $small = $this->block();
        $this->advert($small, 2, 500_000);
        $this->advert($small, 3, 5_000_000);

        $r = $this->review();

        $this->assertSame(0, $r['proposals']);
        $this->assertSame(1, $r['files_checked'], 'the two-advert file is below the minimum');
    }

    public function test_the_agent_can_split_a_flat_off_and_the_original_keeps_the_rest(): void
    {
        $tp = $this->block();
        [$twoA, $twoB, $threeA, $threeB] = $this->threeFlats($tp);
        $this->review();
        $id = (int) DB::table('property_match_decisions')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(incoming_facts, '$.label')) LIKE '2 adverts: 3 bed%'")->value('id');
        $this->assertGreaterThan(0, $id);

        $this->actingAs($this->admin)->get(route('corex.possible-matches.index', ['kind' => 'unit_split']))->assertOk()->assertSee('Split off as its own property')->assertSee('Proposed flat');
        $this->actingAs($this->admin)->post(route('corex.possible-matches.confirm', $id))->assertRedirect();

        $moved = DB::table('prospecting_listings')->whereIn('id', [$threeA->id, $threeB->id])->pluck('tracked_property_id')->unique();
        $this->assertCount(1, $moved);
        $this->assertNotSame($tp->id, (int) $moved->first(), 'the 3-bed adverts sit on a new property');
        $this->assertSame($tp->id, (int) DB::table('prospecting_listings')->where('id', $twoA->id)->value('tracked_property_id'));
        $this->assertSame('active', TrackedProperty::queryWithoutAgencyScope()->find($tp->id)->status, 'the original is kept, never deleted');
        $this->assertNotNull(DB::table('property_match_decisions')->where('id', $id)->value('confirmed_at'));
        $this->assertNotNull($twoB);
    }

    public function test_keep_together_is_remembered_and_never_offered_again(): void
    {
        $tp = $this->block();
        $this->threeFlats($tp);
        $this->review();
        $id = (int) DB::table('property_match_decisions')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(incoming_facts, '$.kind')) = 'unit_split'")->value('id');

        $this->actingAs($this->admin)->post(route('corex.possible-matches.reject', $id))->assertRedirect();

        $this->assertNotNull(DB::table('property_match_decisions')->where('id', $id)->value('rejected_at'));
        $this->assertSame(0, $this->review()['proposals'], 'neither the rejected proposal nor the waiting one is queued again');
        $this->assertSame(1, $this->proposals(), 'only the other flat-group is still waiting');
    }

    public function test_the_thresholds_are_agency_settings_and_another_agency_is_never_touched(): void
    {
        $mine = $this->block();
        $this->threeFlats($mine);
        $theirs = $this->block($this->b);
        $this->threeFlats($theirs);

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['unit_split_min_adverts' => 8]);
        $this->assertSame(0, $this->review()['proposals'], 'the agency wants 8 adverts before a file is offered');

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['unit_split_min_adverts' => 5, 'unit_split_price_spread' => 5.0]);
        $this->assertSame(0, $this->review()['proposals'], 'the spread here is 3.3x, under the agency limit of 5');

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['unit_split_price_spread' => 2.0]);
        $this->assertSame(2, $this->review()['proposals']);
        $this->assertSame(0, DB::table('property_match_decisions')->where('agency_id', $this->b->id)->count(), "the other agency's file got nothing");
        $this->assertNotNull($theirs);
    }
}
