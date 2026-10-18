<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\AgentActivityEvent;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deeds-capture pre-check — street-number correctness
 * (.ai/specs/deeds-capture.md §9.10, 2026-10-07).
 *
 * Johan's first test of extension 3.8.0 on QA1: a click on "19 Grindewald
 * Drive" (Uvongo) came back "29 Grindewald Drive — close text match", while
 * CoreX property 6113 "19 Grindewald" was never found. Both Grindewald cases
 * are reproduced here with the real QA1 row shapes:
 *   - tracked property 392: street_number NULL, street_name "29 Grindewald Drive"
 *   - property 6113: street_number 19, street_name "Grindewald" (no street type)
 *
 * The rule under test: an exact street number + street + suburb (or erf, or
 * scheme + section) always wins and is found in EVERY place CoreX holds the
 * property (agency stock AND earlier captures); a DIFFERENT street number is
 * never reported as the same property — at most as "other property on this
 * street" (`same_street`), which never counts toward the status.
 */
final class DeedsCapturePrecheckStreetNumberTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    // ── fixtures — the QA1 row shapes ──────────────────────────────────────

    /** tracked property 392 on QA1: number lives INSIDE street_name, street_number NULL. */
    private function tp29(array $over = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'     => $this->agency->id,
            'capture_kind'  => 'deeds_capture',
            'street_number' => null,
            'street_name'   => '29 Grindewald Drive',
            'suburb'        => 'UVONGO',
            'latitude'      => -30.8283550,
            'longitude'     => 30.3942790,
            'source_chain'  => [],
        ], $over));
    }

    /** property 6113 on QA1: agency stock, "19 Grindewald" — street name without the street type. */
    private function property19(array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'branch_id'     => $this->branch->id,
            'agent_id'      => $this->user->id,
            'street_number' => '19',
            'street_name'   => 'Grindewald',
            'address'       => '19 Grindewald',
            'suburb'        => 'Uvongo',
            'property_type' => 'house',
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000,
            'title' => 'Test', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    /** What the extension sends for a click on "<n> Grindewald Drive" in Uvongo. */
    private function click(string $number, array $over = []): array
    {
        return ['property' => array_merge([
            'address'       => $number . ' Grindewald Drive',
            'street_number' => $number,
            'street_name'   => 'Grindewald Drive',
            'suburb'        => 'Uvongo',
            'latitude'      => -30.8290,
            'longitude'     => 30.3950,
        ], $over)];
    }

    private function check(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.deeds-capture.check-duplicate'), $payload);
    }

    // ── the two Grindewald cases ───────────────────────────────────────────

    public function test_click_on_19_is_not_reported_as_29_when_only_29_is_on_file(): void
    {
        $this->tp29();

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('not_found', $json['status'], 'a different street number must never be a duplicate');
        $this->assertSame([], $json['matches']);
        $this->assertCount(1, $json['same_street'], '29 is reported only as another property on the street');
        $this->assertSame('tracked_property', $json['same_street'][0]['source']);
        $this->assertStringContainsString('29 Grindewald Drive', $json['same_street'][0]['address']);
    }

    public function test_click_on_19_finds_property_19_in_agency_stock_even_without_a_capture(): void
    {
        $this->tp29();
        $prop = $this->property19();

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('exists', $json['status']);
        $this->assertCount(1, $json['matches']);
        $m = $json['matches'][0];
        $this->assertSame('property', $m['source']);
        $this->assertSame($prop->id, $m['property_id']);
        $this->assertTrue($m['confident']);
        $this->assertStringContainsString('19 Grindewald', $m['address']);
        $this->assertStringContainsString('On CoreX as a property', $m['summary']);
        $this->assertSame(route('corex.properties.show', $prop->id), $m['deeplink']);

        // 29 is still only an "other property on this street"; 19 itself is not listed as "other".
        $this->assertCount(1, $json['same_street']);
        $this->assertStringContainsString('29 Grindewald Drive', $json['same_street'][0]['address']);
    }

    public function test_click_on_29_still_finds_the_capture_whose_number_is_inside_the_street_name(): void
    {
        $tp = $this->tp29();

        $json = $this->check($this->click('29'))->assertOk()->json();

        $this->assertSame('exists', $json['status']);
        $this->assertSame($tp->id, $json['matches'][0]['tracked_property_id']);
        $this->assertSame([], $json['same_street']);
    }

    // ── the class: numbers, street types, suburbs, scopes ──────────────────

    public function test_a_property_on_the_same_street_with_another_number_is_never_a_match(): void
    {
        $this->property19(['street_number' => '29', 'address' => '29 Grindewald']);

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('not_found', $json['status']);
        $this->assertSame([], $json['matches']);
        $this->assertSame('property', $json['same_street'][0]['source']);
    }

    public function test_several_other_numbers_on_the_street_are_listed_in_number_order(): void
    {
        $this->tp29();
        $this->property19(['street_number' => '8', 'address' => '8 Grindewald']);
        $this->property19(['street_number' => '31', 'address' => '31 Grindewald']);

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('not_found', $json['status']);
        $this->assertCount(3, $json['same_street']);
        $this->assertSame(['8 Grindewald, Uvongo', '29 Grindewald Drive, UVONGO', '31 Grindewald, Uvongo'],
            array_column($json['same_street'], 'address'));
    }

    public function test_number_written_in_the_property_street_name_is_read_not_ignored(): void
    {
        // an old import: street_number NULL, "19 Grindewald" in street_name
        $this->property19(['street_number' => null, 'street_name' => '19 Grindewald']);

        $this->assertSame('exists', $this->check($this->click('19'))->json('status'));
        $this->assertSame('not_found', $this->check($this->click('21'))->json('status'));
    }

    public function test_different_street_type_on_both_sides_is_only_a_possible_match(): void
    {
        $this->property19(['street_name' => 'Grindewald Road', 'address' => '19 Grindewald Road']);

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('possible_match', $json['status']);
        $this->assertFalse($json['matches'][0]['confident']);
    }

    public function test_street_type_abbreviation_is_the_same_street(): void
    {
        $this->property19(['street_name' => 'Grindewald Dr', 'address' => '19 Grindewald Dr']);

        $this->assertSame('exists', $this->check($this->click('19'))->json('status'));
    }

    public function test_same_number_and_street_in_another_suburb_is_not_a_match(): void
    {
        $this->property19(['suburb' => 'St Michaels On Sea']);

        $this->assertSame('not_found', $this->check($this->click('19'))->json('status'));
    }

    public function test_another_agencys_property_is_never_visible_to_the_check(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherUser = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $foreign = Property::create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'agent_id' => $otherUser->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'address' => '19 Grindewald', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1, 'title' => 'x',
            'status' => 'active', 'listing_type' => 'sale',
        ]);
        // the agency-owned model stamps the acting user's agency on create — force the row into the other agency
        \Illuminate\Support\Facades\DB::table('properties')->where('id', $foreign->id)->update(['agency_id' => $other->id]);

        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('not_found', $json['status']);
        $this->assertSame([], $json['matches']);
        $this->assertSame([], $json['same_street']);
    }

    public function test_unit_at_the_same_address_is_a_different_property(): void
    {
        $this->property19(['unit_number' => '3']);

        $json = $this->check($this->click('19', ['unit_number' => '4']))->assertOk()->json();

        $this->assertSame('not_found', $json['status']);
    }

    public function test_capture_with_a_unit_against_a_property_without_one_is_only_possible(): void
    {
        $this->property19();

        $json = $this->check($this->click('19', ['unit_number' => '4']))->assertOk()->json();

        $this->assertSame('possible_match', $json['status']);
    }

    public function test_freehold_erf_and_suburb_finds_a_property_with_no_street_address(): void
    {
        $prop = $this->property19(['street_number' => null, 'street_name' => null, 'address' => 'erf only', 'erf_number' => '0645']);

        $json = $this->check(['property' => ['erf_number' => '645', 'suburb' => 'Uvongo']])->assertOk()->json();

        $this->assertSame('exists', $json['status']);
        $this->assertSame($prop->id, $json['matches'][0]['property_id']);
    }

    public function test_sectional_scheme_and_section_finds_the_unit(): void
    {
        $prop = $this->property19([
            'street_number' => null, 'street_name' => null, 'address' => 'Unit 4, Villa Mei',
            'complex_name' => 'Villa Mei', 'unit_number' => '04',
        ]);

        $json = $this->check(['property' => [
            'scheme_name' => 'Villa Mei', 'complex_name' => 'Villa Mei', 'section_number' => '4', 'suburb' => 'Uvongo',
        ]])->assertOk()->json();

        $this->assertSame('exists', $json['status']);
        $this->assertSame($prop->id, $json['matches'][0]['property_id']);
    }

    public function test_a_property_the_viewer_may_not_open_is_reported_without_its_address(): void
    {
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->property19(['agent_id' => $colleague->id]);

        // an ordinary agent: with no grants seeded the test posture gives 'agent' the "own" scope,
        // so the colleague's listing is not theirs to open
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        Sanctum::actingAs($agent);
        $json = $this->check($this->click('19'))->assertOk()->json();

        $this->assertSame('exists', $json['status'], 'the duplicate is still reported');
        $this->assertSame('A property already held in your agency', $json['matches'][0]['address']);
        $this->assertNull($json['matches'][0]['deeplink']);
    }

    // ── the real capture path shares the resolver ──────────────────────────

    public function test_a_real_capture_of_19_is_not_merged_into_the_record_for_29(): void
    {
        $tp29 = $this->tp29();
        $service = app(TrackedPropertyMatchOrCreateService::class);

        $tp19 = $service->matchOrCreate($this->agency->id, [
            'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo',
            'latitude' => -30.8290, 'longitude' => 30.3950,
        ], ['type' => 'deeds_capture', 'ref' => 'cmainfo:n0et03630000132900000', 'payload' => ['source' => 'cmainfo']]);

        $this->assertNotSame($tp29->id, $tp19->id, 'capturing 19 must create its own record, not enrich 29');
        // 2026-10-07 (structured address matching): a record created from "29 Grindewald Drive" now has its number lifted into
        // the empty street_number column by the one address reader — so "untouched" means the capture of 19 changed nothing on it.
        $this->assertSame('29', $tp29->fresh()->street_number, 'the record for 29 still says 29');
        $this->assertSame('29 Grindewald Drive', $tp29->fresh()->street_name, 'and its street text is exactly as it was');
    }

    public function test_multiline_legacy_street_name_matches_on_any_number_it_states(): void
    {
        $tp = $this->tp29(['street_name' => "4 Villa-Del-Mei\n35 Grindewald Drive"]);

        $this->assertSame('exists', $this->check($this->click('35'))->json('status'));
        $this->assertSame($tp->id, $this->check($this->click('35'))->json('matches.0.tracked_property_id'));
        $this->assertSame('not_found', $this->check($this->click('19'))->json('status'));
    }

    public function test_click_without_any_street_number_keeps_the_old_loose_behaviour(): void
    {
        $this->tp29();

        // no number at all on the click — nothing to contradict, so the street-text hit is still offered
        $json = $this->check(['property' => ['street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']])->assertOk()->json();

        $this->assertContains($json['status'], ['exists', 'possible_match']);
    }

    public function test_the_check_is_logged_with_the_same_street_count(): void
    {
        $this->tp29();

        $this->check($this->click('19'))->assertOk();

        $event = AgentActivityEvent::where('event_type', 'deeds_capture.precheck.not_found')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(1, $event->payload['same_street_count']);
    }
}
