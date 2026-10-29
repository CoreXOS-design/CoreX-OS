<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\AddressMatchSetting;
use App\Models\Branch;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\PropertyMatchDecision;
use App\Models\User;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\SharedPortalPool;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §5 and §11: the door. Each key and the confidence it returns, the unit rule,
 * the precise-pin rule for GPS, possible matches recorded (never merged), the auto-merge setting, and agency privacy.
 */
final class PropertyIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private PropertyIdentityResolver $door;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        foreach ([$this->a, $this->b] as $ag) {
            $br = Branch::create(['agency_id' => $ag->id, 'name' => 'Main']);
            User::factory()->create(['agency_id' => $ag->id, 'branch_id' => $br->id, 'role' => 'admin']);
        }
        $this->door = app(PropertyIdentityResolver::class);
    }

    private function resolve(array $facts, array $source, ?Agency $agency = null): \App\Services\Prospecting\IdentityResult
    {
        return $this->door->resolve(($agency ?? $this->a)->id, $facts, $source);
    }

    private function mic(string $ref, array $facts = [], ?Agency $agency = null)
    {
        return $this->resolve($facts, ['type' => 'p24', 'ref' => $ref], $agency);
    }

    private function tpCount(?Agency $agency = null): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->where('agency_id', ($agency ?? $this->a)->id)->count();
    }

    // ---- key 1: the portal reference, whatever the spelling ------------------------------------------------

    public function test_the_same_advert_in_three_spellings_is_one_property_file_with_certain_confidence(): void
    {
        $first = $this->mic('P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $this->assertTrue($first->created);
        $this->assertSame('p24:116123456', $first->canonicalRef);

        // own-listing spelling: type property24, bare digits — no address at all
        $second = $this->resolve([], ['type' => 'property24', 'ref' => '116123456']);
        // portal-capture spelling: type chrome_capture, bare digits, the portal in the payload
        $third = $this->resolve([], ['type' => 'chrome_capture', 'ref' => '116123456', 'payload' => ['source_site' => 'www.property24.com']]);

        $this->assertFalse($second->created);
        $this->assertFalse($third->created);
        $this->assertSame($first->trackedProperty->id, $second->trackedProperty->id);
        $this->assertSame($first->trackedProperty->id, $third->trackedProperty->id);
        $this->assertSame('exact', $second->confidence);
        $this->assertSame('portal_ref', $second->method);
        $this->assertSame(1, $this->tpCount());

        $refs = TrackedPropertyExternalRef::queryWithoutAgencyScope()->where('tracked_property_id', $first->trackedProperty->id)->orderBy('id')->get();
        $this->assertCount(3, $refs);
        $this->assertSame(['p24:116123456'], $refs->pluck('canonical_ref')->unique()->values()->all());
        $this->assertSame(['exact'], $refs->pluck('match_confidence')->unique()->values()->all(), 'the confidence is SAVED on each link');
        $this->assertSame(['portal_ref'], $refs->pluck('match_method')->unique()->values()->all());
        $this->assertNotNull($refs[1]->matched_at);
    }

    public function test_a_different_advert_number_is_a_different_property_unless_the_address_says_otherwise(): void
    {
        $this->mic('P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $other = $this->mic('P24-116999999', ['street_number' => '40', 'street_name' => 'Hill Street', 'suburb' => 'Uvongo']);

        $this->assertTrue($other->created);
        $this->assertSame(2, $this->tpCount());
    }

    public function test_a_relisted_advert_with_a_new_number_links_by_address_and_keeps_both_numbers(): void
    {
        $facts = ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'];
        $first = $this->mic('P24-116123456', $facts);
        $relist = $this->mic('P24-117000001', $facts);

        $this->assertFalse($relist->created);
        $this->assertSame($first->trackedProperty->id, $relist->trackedProperty->id);
        $this->assertSame('high', $relist->confidence);
        $this->assertContains($relist->method, ['address_unit', 'address_history'], 'the address was already held in this property\'s history');
        $this->assertEqualsCanonicalizing(['p24:116123456', 'p24:117000001'],
            TrackedPropertyExternalRef::queryWithoutAgencyScope()->where('tracked_property_id', $first->trackedProperty->id)->pluck('canonical_ref')->all());
    }

    // ---- key 2: SG code / erf / scheme (private layer, same agency only) ----------------------------------

    public function test_the_sg_code_is_certain_erf_and_scheme_are_confident(): void
    {
        $a = $this->resolve(['lpi_code' => 'n0et03630000132900000', 'suburb' => 'Margate'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:LPI1']);
        $b = $this->resolve(['lpi_code' => 'n0et03630000132900000', 'suburb' => 'Margate'], ['type' => 'cmainfo', 'ref' => 'report:1']);
        $this->assertSame($a->trackedProperty->id, $b->trackedProperty->id);
        $this->assertSame('exact', $b->confidence);
        $this->assertSame('lpi', $b->method);

        $c = $this->resolve(['erf_number' => '1234', 'suburb' => 'Ramsgate'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:E1']);
        $d = $this->resolve(['erf_number' => '1234', 'suburb' => 'Ramsgate'], ['type' => 'cmainfo', 'ref' => 'report:2']);
        $this->assertSame($c->trackedProperty->id, $d->trackedProperty->id);
        $this->assertSame('high', $d->confidence);
        $this->assertSame('erf_suburb', $d->method);

        $e = $this->resolve(['scheme_name' => 'Sea Spray', 'section_number' => '5', 'suburb' => 'Margate'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:S5']);
        $f = $this->resolve(['scheme_name' => 'Sea Spray', 'section_number' => '5', 'suburb' => 'Margate'], ['type' => 'cmainfo', 'ref' => 'report:3']);
        $this->assertSame($e->trackedProperty->id, $f->trackedProperty->id);
        $this->assertSame('scheme_unit', $f->method);
    }

    public function test_a_title_deed_number_alone_never_links(): void
    {
        $a = $this->resolve(['title_deed_number' => 'T12345/2019', 'suburb' => 'Margate', 'street_name' => 'Alpha Road', 'street_number' => '1'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:D1']);
        $b = $this->resolve(['title_deed_number' => 'T12345/2019', 'suburb' => 'Shelly Beach', 'street_name' => 'Beta Road', 'street_number' => '9'], ['type' => 'cmainfo', 'ref' => 'report:9']);

        $this->assertNotSame($a->trackedProperty->id, $b->trackedProperty->id, 'the deed number changes at each transfer — it corroborates, it is not a key');
    }

    // ---- key 3: address + suburb + the unit rule ------------------------------------------------------------

    public function test_a_different_unit_at_the_same_address_never_merges(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'complex_name' => 'Pebble Beach', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'];
        $u303 = $this->mic('P24-116000303', $base + ['unit_number' => '303']);
        $u304 = $this->mic('P24-116000304', $base + ['unit_number' => '304']);

        $this->assertTrue($u304->created);
        $this->assertNotSame($u303->trackedProperty->id, $u304->trackedProperty->id);
        $this->assertSame([], $u304->possible, 'a different unit is a veto, not even a possible match');
    }

    public function test_the_same_unit_links_confidently(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'complex_name' => 'Pebble Beach', 'suburb' => 'Umhlanga', 'property_type' => 'apartment', 'unit_number' => '303'];
        $a = $this->mic('P24-116000303', $base);
        $b = $this->mic('P24-117000303', $base);

        $this->assertFalse($b->created);
        $this->assertSame($a->trackedProperty->id, $b->trackedProperty->id);
        $this->assertSame('high', $b->confidence);
    }

    public function test_a_unit_on_one_side_only_is_a_possible_match_never_a_merge(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'];
        $block = $this->mic('P24-116000001', $base);
        $unit = $this->mic('P24-116000303', $base + ['unit_number' => '303']);

        $this->assertTrue($unit->created, 'must not be swallowed by the block');
        $this->assertNotSame($block->trackedProperty->id, $unit->trackedProperty->id);
        $this->assertCount(1, $unit->possible);
        $this->assertSame($block->trackedProperty->id, $unit->possible[0]['id']);
        $this->assertSame('probable', $unit->possible[0]['confidence']);
    }

    public function test_a_unit_type_property_with_no_unit_on_either_side_is_only_a_possible_match(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'];
        $a = $this->mic('P24-116000001', $base);
        $b = $this->mic('P24-116000002', $base);

        $this->assertTrue($b->created);
        $this->assertSame('probable', $b->possible[0]['confidence']);
        $this->assertContains($b->possible[0]['method'], ['address_unit', 'address_history']);
        $this->assertSame(2, $this->tpCount());
        $this->assertNotSame($a->trackedProperty->id, $b->trackedProperty->id);
    }

    public function test_a_door_number_that_differs_from_the_deeds_section_number_is_a_possible_match_only(): void
    {
        // a deeds file: section 5 of the scheme, no door number
        $deeds = $this->resolve(['street_number' => '18', 'street_name' => 'Orange Rocks Road', 'suburb' => 'Margate', 'scheme_name' => 'Ishumi', 'section_number' => '5', 'property_type' => 'apartment'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:ISHUMI/5']);
        // our stock says "Unit 7": section 5 is not necessarily door 7
        $stock = $this->mic('P24-116000007', ['street_number' => '18', 'street_name' => 'Orange Rocks Road', 'suburb' => 'Margate', 'unit_number' => '7', 'property_type' => 'apartment']);

        $this->assertTrue($stock->created);
        $this->assertSame($deeds->trackedProperty->id, $stock->possible[0]['id']);
    }

    public function test_a_freehold_house_with_no_unit_links_on_address(): void
    {
        $base = ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'property_type' => 'house'];
        $a = $this->mic('P24-116000001', $base);
        $b = $this->mic('P24-116000002', $base);

        $this->assertFalse($b->created);
        $this->assertSame($a->trackedProperty->id, $b->trackedProperty->id);
    }

    // ---- key 4: GPS -----------------------------------------------------------------------------------------

    public function test_a_suburb_centre_pin_never_counts_as_gps(): void
    {
        $centre = ['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'suburb'];
        $a = $this->resolve($centre, ['type' => 'manual', 'ref' => 'M-1']);
        $b = $this->resolve($centre, ['type' => 'manual', 'ref' => 'M-2']);

        $this->assertTrue($b->created, 'two different houses can share a suburb-centre pin');
        $this->assertNotSame($a->trackedProperty->id, $b->trackedProperty->id);
    }

    public function test_a_precise_pin_within_5_metres_with_the_suburb_agreeing_links(): void
    {
        $a = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-1']);
        $b = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625010, 'longitude' => 30.3700010, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-2']);

        $this->assertFalse($b->created);
        $this->assertSame($a->trackedProperty->id, $b->trackedProperty->id);
        $this->assertSame('gps', $b->method);
    }

    public function test_a_precise_pin_15_metres_away_is_only_a_possible_match(): void
    {
        $a = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-1']);
        $b = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8626350, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-2']); // ~15 m

        $this->assertTrue($b->created);
        $this->assertSame($a->trackedProperty->id, $b->possible[0]['id']);
        $this->assertSame('probable', $b->possible[0]['confidence']);
        $this->assertSame('gps', $b->possible[0]['method']);
    }

    public function test_an_unlabelled_imported_pin_next_to_a_precise_one_is_only_a_possible_match(): void
    {
        $a = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-1']);
        // 3 m away, but the incoming pin says nothing about how precise it is (an imported spreadsheet pin)
        $b = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625270, 'longitude' => 30.3700000], ['type' => 'manual', 'ref' => 'M-2']);

        $this->assertTrue($b->created);
        $this->assertSame($a->trackedProperty->id, $b->possible[0]['id']);
    }

    public function test_gps_never_joins_a_unit_or_a_road_to_a_house(): void
    {
        $house = $this->resolve(['street_number' => '145', 'street_name' => 'Marine Drive', 'suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'H-1']);

        // a unit in a block: the pin is the block's
        $unit = $this->resolve(['unit_number' => '8', 'street_number' => '145', 'street_name' => 'Marine Drive', 'suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'H-2']);
        $this->assertTrue($unit->created, 'the incoming data names a unit; the file does not');

        // a road with no house number at all
        $road = $this->resolve(['street_name' => 'Marine Drive', 'suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'H-3']);
        $this->assertTrue($road->created, 'a street name without a number is not a house');
        $this->assertNotSame($house->trackedProperty->id, $road->trackedProperty->id);
    }

    public function test_the_gps_radius_is_an_agency_setting(): void
    {
        AddressMatchSetting::query()->create(['agency_id' => $this->a->id, 'gps_radius_m' => 5]);
        $a = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-1']);
        $b = $this->resolve(['suburb' => 'Margate', 'latitude' => -30.8626350, 'longitude' => 30.3700000, 'geo_confidence' => 'exact'], ['type' => 'manual', 'ref' => 'M-2']);

        $this->assertSame([], $b->possible, '15 m is outside a 5 m radius — not even a candidate');
    }

    // ---- key 5: fuzzy -----------------------------------------------------------------------------------------

    public function test_several_houses_on_a_street_are_never_picked_between(): void
    {
        $this->mic('P24-116000001', ['street_number' => '417', 'street_name' => 'Von Baumbach Avenue', 'suburb' => 'Margate']);
        $this->mic('P24-116000002', ['street_number' => '381', 'street_name' => 'Von Baumbach Avenue', 'suburb' => 'Margate']);
        $r = $this->mic('P24-116000003', ['address' => 'Von Baumbach Avenue', 'street_name' => 'Von Baumbach Avenue', 'suburb' => 'Margate']);

        $this->assertTrue($r->created, 'no number given: both fit, so neither is picked');
        $this->assertSame(3, $this->tpCount());
        $this->assertContains('weak', array_column($r->possible, 'confidence'));
    }

    // ---- possible matches are recorded for an agent, once, and a "no" is remembered ----------------------------

    public function test_possible_matches_are_recorded_pending_and_not_repeated(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'];
        $block = $this->mic('P24-116000001', $base);
        $this->mic('P24-116000303', $base + ['unit_number' => '303']);
        $this->mic('P24-116000303', $base + ['unit_number' => '303']); // the same advert seen again

        $rows = PropertyMatchDecision::queryWithoutAgencyScope()->where('subject_type', 'identity_possible')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('p24:116000303', $rows[0]->subject_key);
        $this->assertSame((int) $block->trackedProperty->id, (int) $rows[0]->matched_id);
        $this->assertSame('pending', $rows[0]->outcome);
        $this->assertSame('probable', $rows[0]->candidates[0]['confidence']);
        $this->assertSame($this->a->id, (int) $rows[0]->agency_id);
    }

    public function test_a_rejected_pair_is_never_offered_again(): void
    {
        $base = ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'];
        $block = $this->mic('P24-116000001', $base);
        $this->mic('P24-116000303', $base + ['unit_number' => '303']);
        $decision = PropertyMatchDecision::queryWithoutAgencyScope()->where('subject_type', 'identity_possible')->first();
        $decision->update(['rejected_at' => now()]);

        $again = $this->mic('P24-116000303', $base + ['unit_number' => '303']);

        $this->assertSame([], $again->possible);
        $this->assertSame(1, PropertyMatchDecision::queryWithoutAgencyScope()->where('subject_type', 'identity_possible')->count());
    }

    // ---- the auto-merge setting ----------------------------------------------------------------------------

    public function test_auto_merge_minimum_confidence_exact_turns_confident_matches_into_possible_matches(): void
    {
        AddressMatchSetting::query()->create(['agency_id' => $this->a->id, 'auto_merge_min_confidence' => 'exact']);
        $a = $this->resolve(['erf_number' => '1234', 'suburb' => 'Ramsgate'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:E1']);
        $b = $this->resolve(['erf_number' => '1234', 'suburb' => 'Ramsgate'], ['type' => 'cmainfo', 'ref' => 'report:2']);

        $this->assertTrue($b->created);
        $this->assertSame($a->trackedProperty->id, $b->possible[0]['id']);
        $this->assertSame('high', $b->possible[0]['confidence']);

        // a portal reference is still certain, whatever the setting
        $c = $this->mic('P24-116555555', ['street_name' => 'Other Road', 'street_number' => '1', 'suburb' => 'Ramsgate']);
        $d = $this->resolve([], ['type' => 'property24', 'ref' => '116555555']);
        $this->assertSame($c->trackedProperty->id, $d->trackedProperty->id);
    }

    // ---- find only ---------------------------------------------------------------------------------------------

    public function test_find_never_creates(): void
    {
        $r = $this->door->find($this->a->id, ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], ['type' => 'cma_comp', 'ref' => 'comp:1']);
        $this->assertNull($r->trackedProperty);
        $this->assertSame('none', $r->confidence);
        $this->assertSame(0, $this->tpCount());

        $made = $this->mic('P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $found = $this->door->find($this->a->id, ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], ['type' => 'cma_comp', 'ref' => 'comp:1']);
        $this->assertSame($made->trackedProperty->id, $found->trackedProperty->id);
        $this->assertSame('high', $found->confidence);
        $this->assertSame(1, $this->tpCount());
    }

    // ---- agency privacy -----------------------------------------------------------------------------------------

    public function test_another_agency_seeing_the_same_advert_gets_its_own_property_file_and_cannot_reach_the_first(): void
    {
        $private = ['erf_number' => '777', 'title_deed_number' => 'T999/2020', 'bond_holder' => 'Big Bank', 'lpi_code' => 'n0et03630000132900000'];
        $one = $this->mic('P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'bedrooms' => 3, 'last_known_asking_price' => 1500000] + $private);
        $before = TrackedProperty::queryWithoutAgencyScope()->find($one->trackedProperty->id)->getAttributes();

        $two = $this->mic('P24-116123456', ['bathrooms' => 2], $this->b);

        $this->assertTrue($two->created, 'agency B has not seen it — its own file is created');
        $this->assertSame($this->b->id, (int) $two->trackedProperty->agency_id);
        $this->assertNotSame($one->trackedProperty->id, $two->trackedProperty->id);

        // public facts from the pool pre-fill agency B's own record ...
        $this->assertSame('Beach Road', $two->trackedProperty->street_name);
        $this->assertEquals(3, $two->trackedProperty->bedrooms);
        // ... and nothing private does
        $this->assertNull($two->trackedProperty->erf_number);
        $this->assertNull($two->trackedProperty->title_deed_number);
        $this->assertNull($two->trackedProperty->lpi_code);
        $this->assertNull($two->trackedProperty->bond_holder);

        // agency A's record is exactly as it was
        $this->assertSame($before, TrackedProperty::queryWithoutAgencyScope()->find($one->trackedProperty->id)->getAttributes());
    }

    public function test_the_pool_never_holds_or_returns_private_fields(): void
    {
        $this->mic('P24-116123456', [
            'street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'bedrooms' => 3,
            'erf_number' => '777', 'title_deed_number' => 'T999/2020', 'bond_holder' => 'Big Bank', 'lpi_code' => 'x', 'owner_contact_id' => 5,
        ]);

        $row = (array) DB::table('portal_advert_facts')->where('canonical_ref', 'p24:116123456')->first();
        $this->assertSame('Beach Road', $row['street_name']);
        $this->assertSame(3, (int) $row['bedrooms']);
        foreach (['erf_number', 'title_deed_number', 'bond_holder', 'lpi_code', 'owner_contact_id', 'agency_id', 'tracked_property_id'] as $private) {
            $this->assertArrayNotHasKey($private, $row, $private . ' must not exist in the pool');
        }

        $found = app(SharedPortalPool::class)->find('p24:116123456');
        $this->assertArrayNotHasKey('erf_number', $found);
        $this->assertArrayNotHasKey('tracked_property_id', $found);
    }

    public function test_the_pool_keeps_what_it_knows_and_logs_price_changes(): void
    {
        $this->mic('P24-116123456', ['street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'last_known_asking_price' => 1500000]);
        $this->mic('P24-116123456', ['last_known_asking_price' => 1400000], $this->b);

        $row = DB::table('portal_advert_facts')->where('canonical_ref', 'p24:116123456')->first();
        $this->assertSame('Beach Road', $row->street_name, 'a later scrape with less information never blanks a fact');
        $this->assertSame(1400000, (int) $row->price);
        $this->assertSame(2, (int) $row->seen_count);
        $this->assertCount(2, json_decode($row->price_history, true));
    }

    public function test_a_refs_with_no_advert_number_never_reach_the_pool(): void
    {
        $this->resolve(['street_name' => 'Beach Road', 'street_number' => '1', 'suburb' => 'Uvongo'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:X']);

        $this->assertSame(0, DB::table('portal_advert_facts')->count());
    }

    // ---- the door is the only way in --------------------------------------------------------------------------------

    public function test_matchorcreate_is_the_door_and_the_engine_is_called_nowhere_else(): void
    {
        $svc = new TrackedPropertyMatchOrCreateService();
        $tp = $svc->matchOrCreate($this->a->id, ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'P24-116123456']);
        $this->assertSame('p24:116123456', TrackedPropertyExternalRef::queryWithoutAgencyScope()->where('tracked_property_id', $tp->id)->value('canonical_ref'));
        $this->assertSame('exact', TrackedPropertyExternalRef::queryWithoutAgencyScope()->where('tracked_property_id', $tp->id)->value('match_confidence'));

        $callers = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && str_contains((string) file_get_contents($f->getPathname()), 'matchOrCreateEngine(')) {
                $callers[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $f->getPathname());
            }
        }
        sort($callers);
        $this->assertSame(['Services/Prospecting/PropertyIdentityResolver.php', 'Services/Prospecting/TrackedPropertyMatchOrCreateService.php'], array_map(fn ($c) => str_replace('\\', '/', $c), $callers));
    }
}
