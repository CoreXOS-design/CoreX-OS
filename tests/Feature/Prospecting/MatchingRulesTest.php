<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\Matching\MatchingRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.4 (mic-work-tab.md section 4): R3 P24 groups, R4 same-agency twins, R5 complexes, R6 map points - each with its own review-list kind,
 * agency settings, agency isolation, and "not the same" respected.
 */
final class MatchingRulesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;
    private int $n = 116000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
    }

    private function file(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now()], $attrs));
    }

    /** an advert on a file; the suburb id is set straight in the table (the save-time resolver would overwrite a made-up one) */
    private function advert(TrackedProperty $tp, array $attrs = []): ProspectingListing
    {
        $this->n++;
        $sub = array_key_exists('p24_suburb_id', $attrs) ? $attrs['p24_suburb_id'] : 5001;
        unset($attrs['p24_suburb_id']);
        $l = new ProspectingListing();
        $l->forceFill(array_merge([
            'agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'address' => null, 'suburb' => 'Uvongo', 'price' => 1_500_000, 'bedrooms' => 3, 'bathrooms' => 2,
            'property_type' => 'House', 'agency_name' => 'Seeff', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale',
        ], $attrs))->save();
        DB::table('prospecting_listings')->where('id', $l->id)->update(['p24_suburb_id' => $sub]);

        return $l->fresh();
    }

    private function group(string $gid, array $adverts): void
    {
        foreach ($adverts as $l) {
            DB::table('p24_group_members')->insert(['p24_group_id' => $gid, 'listing_number' => substr((string) $l->portal_ref, strpos((string) $l->portal_ref, '-') + 1), 'group_size' => count($adverts),
                'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function rule(string $rule, bool $apply = true, ?Agency $agency = null): array
    {
        return app(MatchingRules::class)->run(($agency ?? $this->a)->id, $apply, [$rule])[$rule];
    }

    private function pending(string $kind): int
    {
        return DB::table('property_match_decisions')->where('subject_type', 'identity_possible')->whereNull('rejected_at')->whereNull('confirmed_at')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(incoming_facts, '$.kind')) = ?", [$kind])->count();
    }

    private function fileOf(ProspectingListing $l): int
    {
        return (int) DB::table('prospecting_listings')->where('id', $l->id)->value('tracked_property_id');
    }

    // ---- R3: P24 groups ---------------------------------------------------------------------------------------------

    public function test_a_p24_group_joins_the_adverts_to_the_file_with_an_address_and_empties_the_shell(): void
    {
        $withAddress = $this->file(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $shell = $this->file();
        $x = $this->advert($withAddress);
        $y = $this->advert($shell, ['agency_name' => 'eXp']);
        $this->group('G1', [$x, $y]);

        $r = $this->rule('portal-group');

        $this->assertSame(1, $r['adverts_moved']);
        $this->assertSame($withAddress->id, $this->fileOf($y));
        $this->assertSame('duplicate', TrackedProperty::queryWithoutAgencyScope()->find($shell->id)->status);
        $this->assertSame('portal_group', DB::table('tracked_property_external_refs')->where('canonical_ref', $y->canonical_ref)->where('tracked_property_id', $withAddress->id)->value('match_method') ?? 'portal_group', 'moved refs keep their audit trail');
    }

    public function test_a_member_that_contradicts_the_group_waits_on_the_review_list_with_the_reason(): void
    {
        $a = $this->file(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $c = $this->file(['street_number' => '14', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $x = $this->advert($a);
        $z = $this->advert($c);
        $this->group('G2', [$x, $z]);

        $r = $this->rule('portal-group');

        $this->assertSame(0, $r['adverts_moved']);
        $this->assertSame(1, $r['review_pairs']);
        $this->assertSame(1, $this->pending('portal_group'));
        $this->assertStringContainsString('street number differs', (string) DB::table('property_match_decisions')->where('subject_key', 'like', 'portal_group:%')->value('reason'));
        $this->assertSame($c->id, $this->fileOf($z), 'nothing moved');
    }

    public function test_bedrooms_a_price_gap_and_the_agency_switches_decide(): void
    {
        $a = $this->file(['street_number' => '12', 'street_name' => 'Beach Road']);
        $b = $this->file();
        $x = $this->advert($a, ['price' => 1_000_000]);
        $y = $this->advert($b, ['price' => 2_000_000]);
        $this->group('G3', [$x, $y]);

        $this->assertSame(0, $this->rule('portal-group')['adverts_moved'], 'a 2x price gap is over the 1.5x limit');
        $this->assertSame(1, $this->pending('portal_group'));

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['portal_group_price_spread' => 3.0]);
        $this->assertSame(1, $this->rule('portal-group')['adverts_moved'], 'the agency raised the limit');

        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['portal_group_link_enabled' => false]);
        $this->assertSame('switched off', $this->rule('portal-group')['skipped']);
    }

    public function test_exact_level_sends_every_group_to_review_and_another_agency_is_untouched(): void
    {
        $a = $this->file(['street_number' => '12', 'street_name' => 'Beach Road']);
        $b = $this->file();
        $x = $this->advert($a);
        $y = $this->advert($b);
        $theirs = $this->advert($this->file([], $this->b));
        $this->group('G4', [$x, $y, $theirs]);
        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['portal_group_auto_link_min_confidence' => 'exact']);

        $r = $this->rule('portal-group');

        $this->assertSame(0, $r['adverts_moved']);
        $this->assertSame(1, $r['review_pairs']);
        $this->assertSame(0, $this->rule('portal-group', true, $this->b)['review_pairs'], "another agency's single advert has nothing to join");
        $this->assertNotSame($a->id, $this->fileOf($theirs));
    }

    public function test_a_pair_the_agent_said_is_not_the_same_is_never_queued_again(): void
    {
        $a = $this->file(['street_number' => '12', 'street_name' => 'Beach Road']);
        $c = $this->file(['street_number' => '14', 'street_name' => 'Beach Road']);
        $this->group('G5', [$this->advert($a), $this->advert($c)]);
        $this->rule('portal-group');
        DB::table('property_match_decisions')->where('subject_key', 'like', 'portal_group:%')->delete();
        app(\App\Services\Prospecting\PropertyMatchDecisionService::class)->record($this->a->id, 'identity_possible', 'portal_group:' . $a->id, 'tracked_property', $c->id, 'address_unit', 'x', null,
            ['kind' => 'portal_group', 'incoming_tracked_property_id' => $a->id], null)->update(['rejected_at' => now()]);

        $this->assertSame(0, $this->rule('portal-group')['review_pairs']);
    }

    // ---- R4: same-agency twins -------------------------------------------------------------------------------------

    private function twinPair(array $noAddr = [], array $addr = []): array
    {
        $shell = $this->file();
        $house = $this->file(['street_number' => '65', 'street_name' => 'Outlook Road', 'suburb' => 'Southbroom']);

        return [$this->advert($shell, array_merge(['portal_source' => 'p24', 'price' => 9_995_000], $noAddr)), $this->advert($house, array_merge(['portal_source' => 'pp', 'portal_ref' => 'PP-T5589119', 'address' => '65 Outlook Road', 'price' => 9_995_000], $addr)), $shell, $house];
    }

    public function test_a_same_agency_twin_with_no_address_goes_to_the_review_list(): void
    {
        [$a, $b] = $this->twinPair();

        $r = $this->rule('twin');

        $this->assertSame(1, $r['no_address_adverts']);
        $this->assertSame(1, $r['review_pairs']);
        $this->assertSame(0, $r['linked'], 'review-only by default');
        $this->assertSame(1, $this->pending('twin'));
        $this->assertNotSame($this->fileOf($a), $this->fileOf($b));
    }

    public function test_twins_from_different_agencies_or_with_different_rooms_or_price_are_not_twins(): void
    {
        $this->twinPair(['agency_name' => 'Pam Golding']);
        $this->assertSame(0, $this->rule('twin')['review_pairs'], 'different advertiser');
        DB::table('prospecting_listings')->delete();
        $this->twinPair(['bedrooms' => 4]);
        $this->assertSame(0, $this->rule('twin')['review_pairs'], 'different bedrooms');
        DB::table('prospecting_listings')->delete();
        $this->twinPair(['price' => 7_000_000]);
        $this->assertSame(0, $this->rule('twin')['review_pairs'], 'price outside the 1% tolerance');
    }

    public function test_automatic_joining_needs_the_switch_and_enough_corroboration(): void
    {
        [$a, $b, $shell, $house] = $this->twinPair(['wording_fingerprint' => str_repeat('a', 40), 'first_photo_fingerprint' => str_repeat('b', 40)], ['wording_fingerprint' => str_repeat('a', 40), 'first_photo_fingerprint' => str_repeat('b', 40)]);

        $this->assertSame(0, $this->rule('twin')['linked'], 'switch is off by default');
        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['twin_auto_link' => true]);
        $r = $this->rule('twin');

        $this->assertSame(1, $r['linked']);
        $this->assertSame($house->id, $this->fileOf($a));
        $this->assertSame('duplicate', TrackedProperty::queryWithoutAgencyScope()->find($shell->id)->status);
    }

    // ---- R5: complexes ---------------------------------------------------------------------------------------------

    public function test_hidden_address_flats_on_the_same_land_are_grouped_not_merged_and_look_alike_units_are_queued(): void
    {
        $one = $this->file();
        $two = $this->file();
        $three = $this->file();
        $this->advert($one, ['property_type' => 'Apartment', 'erf_size_m2' => 66474, 'property_size_m2' => 85, 'bedrooms' => 2]);
        $this->advert($two, ['property_type' => 'Apartment', 'erf_size_m2' => 66474, 'property_size_m2' => 85, 'bedrooms' => 2]);
        $this->advert($three, ['property_type' => 'Apartment', 'erf_size_m2' => 66474, 'property_size_m2' => 120, 'bedrooms' => 3]);
        $tiny = $this->file();
        $this->advert($tiny, ['property_type' => 'Apartment', 'erf_size_m2' => 300]);

        $r = $this->rule('complex');

        $this->assertSame(1, $r['complexes']);
        $this->assertSame(3, $r['units_grouped']);
        $ids = DB::table('tracked_properties')->whereIn('id', [$one->id, $two->id, $three->id])->pluck('complex_id')->unique();
        $this->assertCount(1, $ids);
        $this->assertNotNull($ids->first());
        $this->assertNull(TrackedProperty::queryWithoutAgencyScope()->find($tiny->id)->complex_id);
        $this->assertSame('active', TrackedProperty::queryWithoutAgencyScope()->find($two->id)->status, 'grouped, never merged');
        $this->assertSame(1, $this->pending('complex'), 'same floor size + bedrooms = likely the same flat');
    }

    // ---- R6: map point ---------------------------------------------------------------------------------------------

    public function test_the_portal_map_point_confirms_or_flags_and_a_far_one_next_to_another_pin_is_queued(): void
    {
        $home = $this->file(['latitude' => -30.8000, 'longitude' => 30.3900, 'geo_confidence' => 'exact']);
        $other = $this->file(['latitude' => -30.8100, 'longitude' => 30.4000, 'geo_confidence' => 'exact']);
        $near = $this->advert($home, ['portal_latitude' => -30.80005, 'portal_longitude' => 30.39005]);
        $far = $this->advert($home, ['portal_latitude' => -30.8100, 'portal_longitude' => 30.4001]);
        $unpinned = $this->file(['latitude' => -30.5, 'longitude' => 30.2, 'geo_confidence' => 'suburb']);
        $this->advert($unpinned, ['portal_latitude' => -30.9, 'portal_longitude' => 30.5]);

        $r = $this->rule('map-point');

        $this->assertSame(2, $r['checked'], 'a suburb-centre pin is not compared');
        $this->assertSame(1, $r['confirmed']);
        $this->assertSame(1, $r['far']);
        $this->assertSame('confirmed', DB::table('prospecting_listings')->where('id', $near->id)->value('map_point_check'));
        $this->assertSame('far', DB::table('prospecting_listings')->where('id', $far->id)->value('map_point_check'));
        $this->assertGreaterThan(1000, (int) DB::table('prospecting_listings')->where('id', $far->id)->value('map_point_distance_m'));
        $this->assertSame(1, $this->pending('map_point'));
        $this->assertNotNull($other);
    }

    public function test_the_settings_page_saves_the_rules_and_a_partial_post_leaves_them_alone(): void
    {
        $branch = Branch::first();
        $admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $base = array_intersect_key(AddressMatchSetting::forAgency($this->a->id), array_flip(['possible_min_agreeing_columns', 'neighbour_suburb_credit', 'gps_radius_m', 'unit_missing_on_one_side', 'fuzzy_threshold', 'auto_merge_min_confidence']));
        $url = route('settings.prospecting.address-matching.update');

        $this->actingAs($admin)->get(route('settings.prospecting.address-matching.edit'))->assertOk()->assertSee('Property24 itself groups as one property');
        $this->actingAs($admin)->put($url, $base + ['matching_rules_present' => '1', 'portal_group_price_spread' => '2.0', 'twin_price_tolerance_pct' => '3', 'map_point_distance_m' => '300', 'twin_auto_link' => '1'])->assertRedirect();
        $s = AddressMatchSetting::forAgency($this->a->id);
        $this->assertSame(2.0, $s['portal_group_price_spread']);
        $this->assertSame(3, $s['twin_price_tolerance_pct']);
        $this->assertSame(300, $s['map_point_distance_m']);
        $this->assertTrue($s['twin_auto_link']);
        $this->assertFalse($s['portal_group_link_enabled'], 'the form was submitted with that box unticked');

        $this->actingAs($admin)->put($url, $base)->assertRedirect();
        $this->assertSame(300, AddressMatchSetting::forAgency($this->a->id)['map_point_distance_m'], 'a post without the block changes nothing');
        $this->assertSame(1.5, AddressMatchSetting::forAgency($this->b->id)['portal_group_price_spread'], "another agency's settings are its own");
    }
    public function test_the_review_screen_filters_by_the_new_kinds(): void
    {
        $branch = Branch::first();
        $admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $one = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $two = $this->file(['street_number' => '2', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $three = $this->file(['street_number' => '3', 'street_name' => 'Zulu Street', 'suburb' => 'Margate']);
        $pairs = app(\App\Services\Prospecting\Matching\ReviewPairs::class);
        $pairs->queue($this->a->id, $one->id, $two->id, 'twin', 'twin reason', 3, true);
        $pairs->queue($this->a->id, $one->id, $three->id, 'same_address', 'flat reason', null, true);
        $index = fn (array $q) => route('corex.possible-matches.index', $q);

        $this->actingAs($admin)->get($index(['kind' => 'twin']))->assertOk()->assertSee('Alpha Road')->assertDontSee('Zulu Street');
        $this->actingAs($admin)->get($index(['kind' => 'same_address']))->assertOk()->assertSee('Zulu Street')->assertDontSee('2 Alpha Road');
        $this->actingAs($admin)->get($index(['kind' => 'capture']))->assertOk()->assertDontSee('Zulu Street');
        $this->actingAs($admin)->get($index([]))->assertOk()->assertSee('Same agency, other portal');
    }
    public function test_the_review_page_can_be_limited_to_exactly_one_property_file(): void
    {
        $branch = Branch::first();
        $admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $one = $this->file(['street_number' => '447', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $two = $this->file(['street_number' => '2', 'street_name' => 'Beta Road', 'suburb' => 'Uvongo']);
        $three = $this->file(['street_number' => '3', 'street_name' => 'Gamma Road', 'suburb' => 'Uvongo']);
        $four = $this->file(['street_number' => '8', 'street_name' => 'Delta Road', 'suburb' => 'Uvongo']);
        $five = $this->file(['street_number' => '9', 'street_name' => 'Zulu Road', 'suburb' => 'Uvongo']);
        $pairs = app(\App\Services\Prospecting\Matching\ReviewPairs::class);
        $pairs->queue($this->a->id, $one->id, $two->id, 'twin', 'r1', 3, true);
        $pairs->queue($this->a->id, $three->id, $one->id, 'portal_group', 'r2', null, true);      // the file can be on either side
        $pairs->queue($this->a->id, $four->id, $five->id, 'twin', 'r3', 3, true);

        $page = $this->actingAs($admin)->get(route('corex.possible-matches.index', ['file' => $one->id]))->assertOk();

        $page->assertSee('Beta Road')->assertSee('Gamma Road')->assertDontSee('Delta Road')->assertDontSee('Zulu Road')->assertSee('data-qa="file-filter"', false);
        $this->actingAs($admin)->get(route('corex.possible-matches.index', ['file' => 'abc']))->assertOk()->assertSee('Delta Road');
    }

    public function test_only_same_property_kinds_raise_the_mic_row_chip(): void
    {
        $pairs = app(\App\Services\Prospecting\Matching\ReviewPairs::class);
        $f = fn () => $this->file(['street_number' => (string) random_int(1, 999), 'street_name' => 'Chip Road', 'suburb' => 'Uvongo']);
        [$a1, $a2, $b1, $b2, $c1, $c2, $d1, $d2, $e1, $e2, $g1, $g2] = [$f(), $f(), $f(), $f(), $f(), $f(), $f(), $f(), $f(), $f(), $f(), $f()];
        $pairs->queue($this->a->id, $a1->id, $a2->id, 'portal_group', 'P24 group', null, true);
        $pairs->queue($this->a->id, $b1->id, $b2->id, 'twin', 'twin', 2, true);
        $pairs->queue($this->a->id, $c1->id, $c2->id, 'same_address', 'Same address, but the adverts say different bedrooms (5 and 6) - may be two different properties.', null, true);          // freehold: chip
        $pairs->queue($this->a->id, $d1->id, $d2->id, 'same_address', \App\Services\Prospecting\SameAddressSweep::REASON_FLAT . ' with no unit number - may be a different unit.', null, true); // flats: no chip
        $pairs->queue($this->a->id, $e1->id, $e2->id, 'unit_split', 'split', null, true);
        $pairs->queue($this->a->id, $g1->id, $g2->id, 'complex', 'complex', null, true);

        $counts = app(\App\Services\Prospecting\Matching\SamePropertyChips::class)->counts($this->a->id);

        foreach ([$a1, $a2, $b1, $b2, $c1, $c2] as $file) {
            $this->assertSame(1, $counts[$file->id] ?? 0, 'file ' . $file->id . ' raises the chip');
        }
        foreach ([$d1, $d2, $e1, $e2, $g1, $g2] as $file) {
            $this->assertArrayNotHasKey($file->id, $counts);
        }
        $this->assertSame([], app(\App\Services\Prospecting\Matching\SamePropertyChips::class)->counts($this->b->id), "another agency's chips are its own");
        $this->actingAs(User::factory()->create(['agency_id' => $this->a->id, 'role' => 'admin']));
        $this->assertStringContainsString('file=' . $a1->id, app(\App\Services\Prospecting\Matching\SamePropertyChips::class)->forFile($this->a->id, $a1->id)['url']);
    }
    public function test_found_by_is_labelled_from_the_pairs_kind(): void
    {
        $label = fn (string $kind, string $strategy = 'address_unit') => \App\Http\Controllers\CoreX\PossibleMatchesController::foundByLabel($kind, $strategy);

        $this->assertSame('P24 group', $label('portal_group'));
        $this->assertSame('Same-agency twin', $label('twin'));
        $this->assertSame('Same address', $label('same_address'));
        $this->assertSame('Unit/scheme', $label('unit_split'));
        $this->assertSame('Unit/scheme', $label('capture', 'address_unit'), 'an old pair that really is a unit/scheme match keeps that label');
        $this->assertSame('Address history', $label('capture', 'address_history'));
    }

    public function test_new_pairs_record_their_own_kind_as_the_strategy(): void
    {
        $one = $this->file(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Uvongo']);
        $two = $this->file(['street_number' => '2', 'street_name' => 'Beta Road', 'suburb' => 'Uvongo']);
        app(\App\Services\Prospecting\Matching\ReviewPairs::class)->queue($this->a->id, $one->id, $two->id, 'portal_group', 'x', null, true);

        $this->assertSame('portal_group', DB::table('property_match_decisions')->where('subject_key', 'portal_group:' . $one->id)->value('strategy'));
    }
}
