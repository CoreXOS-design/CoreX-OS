<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\PropertyMatchDecision;
use App\Models\User;
use App\Services\Prospecting\Matching\BacklogCollapse;
use App\Services\Prospecting\Matching\MatchingRules;
use App\Services\Prospecting\Matching\ReviewPairs;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\PropertyMatchDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 4.10: the backlog collapse only CLOSES or GROUPS questions - it never merges or links. Johan's principle: rather under-match than over-match.
 */
final class BacklogCollapseTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function flat(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '5',
            'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'property_type' => 'Apartment'], $attrs));
    }

    private function advert(TrackedProperty $tp, int $beds, int $size): void
    {
        static $n = 0;
        $n++;
        (new ProspectingListing())->forceFill(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $n,
            'portal_url' => 'https://www.property24.com/x/' . $n, 'suburb' => 'Uvongo', 'price' => 1_000_000, 'property_type' => 'Apartment', 'bedrooms' => $beds, 'property_size_m2' => $size,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true, 'portal_status' => 'active', 'listing_type' => 'sale'])->save();
    }

    private function property(?int $trackedId = null): int
    {
        return (int) DB::table('properties')->insertGetId(['agency_id' => $this->a->id, 'title' => 'x', 'agent_id' => $this->u->id, 'branch_id' => $this->u->branch_id, 'external_id' => uniqid('P'),
            'tracked_property_id' => $trackedId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ownPair(int $propertyId, TrackedProperty $file, ?int $score): PropertyMatchDecision
    {
        return app(PropertyMatchDecisionService::class)->record($this->a->id, PropertyIdentityResolver::DECISION_SUBJECT, 'property:' . $propertyId, 'tracked_property', (int) $file->id, 'suburb',
            'Probable match: Same address, no unit number - may be a different unit', null, [], $score);
    }

    private function pending(): int
    {
        return PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->whereNull('confirmed_at')->whereNull('rejected_at')->count();
    }

    public function test_one_question_per_listing_and_already_linked_ones_are_closed_without_linking_anything(): void
    {
        [$f1, $f2, $f3] = [$this->flat(['street_number' => '1']), $this->flat(['street_number' => '2']), $this->flat(['street_number' => '3'])];
        $linked = $this->property($f1->id);
        $this->ownPair($linked, $f1, 60);                        // already joined to f1: moot
        $this->ownPair($linked, $f2, 50);                        // the one real question left for this listing
        $multi = $this->property();
        $best = $this->ownPair($multi, $f1, 70);
        $this->ownPair($multi, $f2, 40);
        $this->ownPair($multi, $f3, 40);

        $dry = app(BacklogCollapse::class)->run($this->a->id, false);
        $this->assertSame(5, $this->pending(), 'a dry run writes nothing');
        $res = app(BacklogCollapse::class)->run($this->a->id, true, sys_get_temp_dir() . '/bc-' . uniqid() . '.jsonl');

        $this->assertSame(1, $res['moot_linked']);
        $this->assertSame(2, $res['superseded']);
        $this->assertSame($dry['superseded'], $res['superseded']);
        $this->assertSame(2, $this->pending(), 'one question per listing');
        $this->assertSame([$f2->id, $f3->id], $best->fresh()->incoming_facts['also_considered']);
        $this->assertSame($f1->id, (int) DB::table('properties')->where('id', $linked)->value('tracked_property_id'), 'nothing was linked or merged');
        $this->assertSame(0, DB::table('properties')->where('id', $multi)->whereNotNull('tracked_property_id')->count());
    }

    public function test_two_flats_with_no_unit_number_are_grouped_under_a_complex_not_merged_unless_their_adverts_agree(): void
    {
        $a = $this->flat(); $b = $this->flat();                  // different beds / size: different flats
        $this->advert($a, 2, 80); $this->advert($b, 3, 110);
        $c = $this->flat(['street_number' => '9']); $d = $this->flat(['street_number' => '9']);   // same beds and size: likely one flat advertised twice
        $this->advert($c, 2, 80); $this->advert($d, 2, 80);
        $house = $this->flat(['street_number' => '12', 'property_type' => 'House']); $house2 = $this->flat(['street_number' => '12', 'property_type' => 'House']);
        $reason = 'Same address, but a flat or complex with no unit number - may be a different unit.';
        foreach ([[$a, $b], [$c, $d], [$house, $house2]] as [$x, $y]) {
            app(ReviewPairs::class)->queue($this->a->id, $x->id, $y->id, 'same_address', $reason, null, true);
        }

        $res = app(BacklogCollapse::class)->run($this->a->id, true, sys_get_temp_dir() . '/bc-' . uniqid() . '.jsonl');

        $this->assertSame(1, $res['flats_grouped']);
        $this->assertSame(1, $res['flats_kept_open']);
        $this->assertSame(2, $this->pending(), 'the likely-same flat and the house stay questions');
        $this->assertSame($a->fresh()->complex_id, $b->fresh()->complex_id);
        $this->assertNotNull($a->fresh()->complex_id);
        $this->assertNull($c->fresh()->complex_id);
        $this->assertNull($a->fresh()->duplicate_of_tracked_property_id, 'grouped, never merged');
        $this->assertNull($b->fresh()->duplicate_of_tracked_property_id);
        $closed = PropertyMatchDecision::queryWithoutAgencyScope()->where('matched_id', $b->id)->first();
        $this->assertSame(BacklogCollapse::CODE_COMPLEX, $closed->reject_reason_code);
        $this->assertNull($closed->rejected_by_user_id);
        $this->assertSame(1, DB::table('property_complexes')->where('agency_id', $this->a->id)->count());

        // asked-and-answered pairs are not asked again
        $this->assertFalse(app(ReviewPairs::class)->queue($this->a->id, $a->id, $b->id, 'same_address', $reason, null, true) ?: false);
    }

    public function test_it_is_off_when_the_agency_turns_the_tidy_up_off_and_never_touches_another_agency(): void
    {
        $f1 = $this->flat(['street_number' => '1']); $f2 = $this->flat(['street_number' => '2']);
        $p = $this->property();
        $this->ownPair($p, $f1, 70); $this->ownPair($p, $f2, 40);
        DB::table('address_match_settings')->updateOrInsert(['agency_id' => $this->a->id], ['auto_tidy' => false, 'created_at' => now(), 'updated_at' => now()]);

        $off = app(BacklogCollapse::class)->run($this->a->id, true, sys_get_temp_dir() . '/bc-' . uniqid() . '.jsonl');
        $this->assertSame('switched off', $off['skipped']);
        $this->assertSame(2, $this->pending());

        $other = app(BacklogCollapse::class)->run($this->b->id, true, sys_get_temp_dir() . '/bc-' . uniqid() . '.jsonl');
        $this->assertSame(0, $other['superseded'] + $other['flats_grouped'] + $other['moot_linked']);
        $this->assertSame(2, $this->pending(), "another agency's tidy-up leaves this agency's questions alone");
    }

    public function test_the_automatic_rules_include_the_audit_and_the_collapse(): void
    {
        $this->assertContains('link-audit', MatchingRules::RULES);
        $this->assertContains('backlog-collapse', MatchingRules::RULES);
        $out = app(MatchingRules::class)->run($this->a->id, false, ['link-audit', 'backlog-collapse']);
        $this->assertArrayHasKey('link-audit', $out);
        $this->assertArrayHasKey('backlog-collapse', $out);
    }
}
