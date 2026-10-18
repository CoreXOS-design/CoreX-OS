<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Address\SuburbResolver;
use App\Services\Prospecting\PossiblePropertyMatchException;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/structured-address-matching.md §6.5 / §7 — step 5: the scorer behind the capture
 * pre-check, the tracked-property ingest, the promote link and the "same property?" question.
 * Real South Coast shapes: Grindewald Drive 19 / 29 / 21, Uvongo / Uvongo Beach (neighbouring
 * Property24 suburbs), stand 1166 Lynne Avenue (six portions), sectional San Miguel 71.
 */
final class AddressMatchingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private TrackedPropertyMatchOrCreateService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbResolver::flush();

        $this->agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->svc = app(TrackedPropertyMatchOrCreateService::class);

        // Uvongo (6359) and Uvongo Beach (33106) are two real Property24 suburbs that list each other as neighbours.
        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $kzn = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 101, 'p24_province_id' => $kzn->id, 'name' => 'Margate']);
        P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo', 'surrounding_ids' => [33106]]);
        P24Suburb::create(['p24_id' => 33106, 'p24_city_id' => $city->id, 'name' => 'Uvongo Beach', 'slug' => 'uvongo-beach', 'surrounding_ids' => [6359]]);
    }

    private function property(array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000,
            'title' => 'Test', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    private function tracked(array $over = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id' => $this->agency->id, 'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(), 'source_chain' => [],
        ], $over));
    }

    private function click(string $number, array $over = []): array
    {
        return array_merge(['street_number' => $number, 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], $over);
    }

    // ── stock pre-check: the three answers ──────────────────────────────

    public function test_19_grindewald_drive_finds_property_19_grindewald_as_exact(): void
    {
        $p = $this->property();
        $hits = $this->svc->findExistingStock($this->agency->id, $this->click('19'));

        $this->assertCount(1, $hits);
        $this->assertSame($p->id, $hits[0]['property']->id);
        $this->assertTrue($hits[0]['confident']);
        $this->assertSame('exact', $hits[0]['tier']);
        $this->assertSame(['street_number', 'street_name', 'suburb'], $hits[0]['matched_fields']);
    }

    public function test_a_different_street_type_is_a_possible_match_not_exact(): void
    {
        $this->property(['street_name' => 'Grindewald Road']);
        $hits = $this->svc->findExistingStock($this->agency->id, $this->click('19'));

        $this->assertCount(1, $hits);
        $this->assertFalse($hits[0]['confident']);
        $this->assertSame('possible', $hits[0]['tier']);
    }

    public function test_29_and_21_never_match_19_but_appear_as_other_property_on_this_street(): void
    {
        $p29 = $this->property(['street_number' => '29']);
        $p21 = $this->property(['street_number' => '21']);

        $this->assertSame([], $this->svc->findExistingStock($this->agency->id, $this->click('19')));
        $others = $this->svc->findSameStreetOthers($this->agency->id, $this->click('19'));
        $this->assertSame(['property', 'property'], array_column($others, 'source'));
        $this->assertEqualsCanonicalizing([$p29->id, $p21->id], array_column($others, 'id'));
        $this->assertSame(['21', '29'], array_map(fn ($o) => explode(' ', $o['address'])[0], $others), 'sorted by number');
    }

    public function test_uvongo_beach_property_is_possible_for_an_uvongo_capture_never_exact(): void
    {
        $p = $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $hits = $this->svc->findExistingStock($this->agency->id, ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);

        $this->assertCount(1, $hits);
        $this->assertSame($p->id, $hits[0]['property']->id);
        $this->assertFalse($hits[0]['confident']);
        $this->assertStringContainsString('neighbouring suburb Uvongo Beach', $hits[0]['reason']);
    }

    public function test_the_same_scheme_and_unit_in_another_town_is_not_found(): void
    {
        $this->property(['street_number' => null, 'street_name' => null, 'complex_name' => 'San Miguel', 'unit_number' => '71', 'suburb' => 'Glenmore']);
        $hits = $this->svc->findExistingStock($this->agency->id, ['complex_name' => 'San Miguel', 'section_number' => '71', 'suburb' => 'Sea Park']);
        $this->assertSame([], $hits);
    }

    public function test_two_properties_on_one_identity_are_each_only_possible(): void
    {
        $this->property(['street_number' => null, 'street_name' => null, 'erf_number' => '1166', 'suburb' => 'Ramsgate']);
        $this->property(['street_number' => null, 'street_name' => null, 'erf_number' => '01166', 'suburb' => 'Ramsgate']);
        $hits = $this->svc->findExistingStock($this->agency->id, ['erf_number' => '1166', 'suburb' => 'Ramsgate']);

        $this->assertCount(2, $hits);
        $this->assertSame([false, false], array_column($hits, 'confident'));
    }

    public function test_a_soft_deleted_property_never_matches(): void
    {
        $p = $this->property();
        $p->delete();
        $this->assertSame([], $this->svc->findExistingStock($this->agency->id, $this->click('19')));
    }

    public function test_another_agencys_property_never_matches(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        Property::create([
            'agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'beds' => 1, 'baths' => 1, 'garages' => 0, 'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $this->assertSame([], $this->svc->findExistingStock($this->agency->id, $this->click('19')));
    }

    // ── tracked ingest ─────────────────────────────────────────────────

    public function test_a_capture_with_the_street_type_written_finds_the_tracked_property_stored_without_it(): void
    {
        $tp = $this->svc->matchOrCreate($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald', 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'p24-a']);
        $again = $this->svc->matchOrCreate($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], ['type' => 'chrome_capture', 'ref' => 'cc-a']);

        $this->assertSame($tp->id, $again->id, 'one property, not two');
    }

    public function test_19_29_and_21_grindewald_stay_three_tracked_properties(): void
    {
        $ids = [];
        foreach (['19', '29', '21'] as $n) {
            $ids[$n] = $this->svc->matchOrCreate($this->agency->id, $this->click($n), ['type' => 'chrome_capture', 'ref' => 'cc-' . $n])->id;
        }
        $this->assertCount(3, array_unique($ids));
        // and each is found again as itself
        foreach (['19', '29', '21'] as $n) {
            $this->assertSame($ids[$n], $this->svc->matchOrCreate($this->agency->id, $this->click($n), ['type' => 'p24', 'ref' => 'again-' . $n])->id);
        }
    }

    public function test_six_portions_of_stand_1166_are_six_tracked_properties_and_the_same_portion_is_found(): void
    {
        $ids = [];
        foreach (range(0, 5) as $portion) {
            $ids[$portion] = $this->svc->matchOrCreate(
                $this->agency->id,
                ['erf_number' => '1166', 'erf_portion' => (string) $portion, 'street_name' => 'Lynne Avenue', 'suburb' => 'Ramsgate'],
                ['type' => 'deeds_capture', 'ref' => 'p' . $portion],
            )->id;
        }
        $this->assertCount(6, array_unique($ids));
        $this->assertSame($ids[3], $this->svc->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '1166', 'erf_portion' => '3', 'street_name' => 'Lynne Avenue', 'suburb' => 'Ramsgate'],
            ['type' => 'deeds_capture', 'ref' => 'p3-again'],
        )->id);
    }

    public function test_the_lpi_carries_the_portion_so_two_lpi_codes_for_one_erf_stay_apart(): void
    {
        $a = $this->svc->matchOrCreate($this->agency->id, ['erf_number' => '1329', 'lpi_code' => 'n0et03630000132900000', 'suburb' => 'Uvongo'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:n0et03630000132900000']);
        $b = $this->svc->matchOrCreate($this->agency->id, ['erf_number' => '1329', 'lpi_code' => 'n0et03630000132900001', 'suburb' => 'Uvongo'], ['type' => 'deeds_capture', 'ref' => 'cmainfo:n0et03630000132900001']);
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('0', $a->fresh()->erf_portion);
        $this->assertSame('1', $b->fresh()->erf_portion);
    }

    public function test_an_uvongo_capture_is_never_auto_linked_to_an_uvongo_beach_record_but_is_offered_as_possible(): void
    {
        $beach = $this->svc->matchOrCreate($this->agency->id, ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach'], ['type' => 'p24', 'ref' => 'b1']);
        $facts = ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo'];

        $new = $this->svc->matchOrCreate($this->agency->id, $facts, ['type' => 'chrome_capture', 'ref' => 'u1']);
        $this->assertNotSame($beach->id, $new->id, 'never automatically the same');

        $possible = $this->svc->findPossibleTrackedMatches($this->agency->id, $facts, [$new->id]);
        $this->assertSame([$beach->id], array_map(fn ($p) => $p['tracked_property']->id, $possible));
        $this->assertSame('neighbour', $possible[0]['columns']['suburb']);
    }

    public function test_sectional_unit_5_and_7_in_one_scheme_stay_apart_and_unit_5_is_found_again(): void
    {
        $facts = fn (string $unit) => ['complex_name' => 'Villa Del Sol', 'section_number' => $unit, 'suburb' => 'Margate', 'scheme_number' => 'SS 12/1995'];
        $five = $this->svc->matchOrCreate($this->agency->id, $facts('5'), ['type' => 'deeds_capture', 'ref' => 's5']);
        $seven = $this->svc->matchOrCreate($this->agency->id, $facts('7'), ['type' => 'deeds_capture', 'ref' => 's7']);
        $this->assertNotSame($five->id, $seven->id);
        $this->assertSame($five->id, $this->svc->matchOrCreate($this->agency->id, $facts('05'), ['type' => 'deeds_capture', 'ref' => 's5b'])->id);
    }

    // ── pre-check endpoint: backward compatible, extra evidence ─────────

    public function test_the_precheck_endpoint_reports_a_possible_stock_match_with_the_columns_behind_it(): void
    {
        Sanctum::actingAs($this->user);
        $p = $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);

        $json = $this->postJson(route('v1.deeds-capture.check-duplicate'), ['property' => ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']])
            ->assertOk()->json();

        $this->assertSame('possible_match', $json['status']);
        $m = $json['matches'][0];
        $this->assertSame($p->id, $m['property_id']);
        $this->assertFalse($m['confident']);
        $this->assertSame('possible', $m['tier']);
        $this->assertSame('neighbour', $m['columns']['suburb']);
        $this->assertContains('street', $m['matched_on']);
        foreach (['source', 'address', 'summary', 'reason', 'match_type', 'confident', 'matched_fields', 'deeplink'] as $legacyKey) {
            $this->assertArrayHasKey($legacyKey, $m, 'older extension builds read this');
        }
    }

    public function test_the_precheck_endpoint_reports_exact_with_matched_on(): void
    {
        Sanctum::actingAs($this->user);
        $this->property();

        $json = $this->postJson(route('v1.deeds-capture.check-duplicate'), ['property' => ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']])
            ->assertOk()->json();

        $this->assertSame('exists', $json['status']);
        $this->assertSame('exact', $json['matches'][0]['tier']);
        $this->assertSame(['number', 'street', 'suburb'], $json['matches'][0]['matched_on']);
    }

    public function test_the_precheck_endpoint_reports_a_possible_tracked_match(): void
    {
        Sanctum::actingAs($this->user);
        $beach = $this->svc->matchOrCreate($this->agency->id, ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach'], ['type' => 'deeds_capture', 'ref' => 'b1']);

        $json = $this->postJson(route('v1.deeds-capture.check-duplicate'), ['property' => ['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']])
            ->assertOk()->json();

        $this->assertSame('possible_match', $json['status']);
        $this->assertSame($beach->id, $json['matches'][0]['tracked_property_id']);
        $this->assertSame('possible', $json['matches'][0]['tier']);
    }

    // ── promote: decision 2 ────────────────────────────────────────────

    public function test_promote_exact_match_still_links_without_asking(): void
    {
        $p = $this->property();
        $tp = $this->tracked(['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertSame($p->id, $this->svc->previewPropertyMatch($tp)?->id);
        $this->assertSame($p->id, $this->svc->promoteToStock($tp->id, $this->user->id, [], false, null, true)->id);
    }

    public function test_promote_with_only_a_possible_match_asks_and_creates_nothing(): void
    {
        $p = $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);
        $before = Property::withoutGlobalScopes()->withTrashed()->count();

        $this->assertNull($this->svc->previewPropertyMatch($tp), 'a possible match is never linked');
        try {
            $this->svc->promoteToStock($tp->id, $this->user->id, [], false, null, true);
            $this->fail('promote must ask when only a possible match exists');
        } catch (PossiblePropertyMatchException $e) {
            $this->assertSame([$p->id], array_map(fn ($m) => $m['property']->id, $e->possible));
        }
        $this->assertSame($before, Property::withoutGlobalScopes()->withTrashed()->count(), 'nothing created');
        $this->assertNull($tp->fresh()->promoted_to_property_id);
    }

    public function test_promote_same_links_the_chosen_property_and_different_creates_a_new_one(): void
    {
        $p = $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $same = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);
        $this->assertSame($p->id, $this->svc->promoteToStock($same->id, $this->user->id, [], false, $p->id, true)->id);

        $diff = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo', 'erf_number' => '777']);
        $new = $this->svc->promoteToStock($diff->id, $this->user->id, [], true, null, true);
        $this->assertNotSame($p->id, $new->id);
    }

    public function test_a_programmatic_promote_without_asking_keeps_its_old_behaviour(): void
    {
        $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);

        $created = $this->svc->promoteToStock($tp->id, $this->user->id);
        $this->assertNotNull($created->id, 'imports that never asked still complete');
    }

    public function test_the_deeds_screen_promote_without_an_answer_stops_with_a_clear_message(): void
    {
        $this->actingAs($this->user);
        $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);
        $before = Property::withoutGlobalScopes()->withTrashed()->count();

        $this->post(route('corex.deeds-capture.promote', $tp->id), [])
            ->assertRedirect(route('corex.deeds-capture.index'))
            ->assertSessionHas('error');

        $this->assertSame($before, Property::withoutGlobalScopes()->withTrashed()->count());
        $this->assertNull($tp->fresh()->promoted_to_property_id);
    }

    public function test_the_deeds_screen_different_creates_a_new_property(): void
    {
        $this->actingAs($this->user);
        $old = $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);

        $this->post(route('corex.deeds-capture.promote', $tp->id), ['match_decision' => 'different'])
            ->assertRedirect(route('corex.deeds-capture.index'));

        $promoted = $tp->fresh()->promoted_to_property_id;
        $this->assertNotNull($promoted);
        $this->assertNotSame($old->id, (int) $promoted);
    }

    public function test_the_deeds_screen_shows_both_sides_and_the_two_answers_for_a_possible_match(): void
    {
        $this->actingAs($this->user);
        $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);

        $resp = $this->get(route('corex.deeds-capture.index'));

        $resp->assertOk();
        $resp->assertSee('A property that may be the same is already on file');
        $resp->assertSee('Same property — link to it');
        $resp->assertSee('Different property — add as new');
        $resp->assertSee('neighbouring suburb');
        $resp->assertDontSee('Add as a new property', false);
    }

    public function test_the_mic_promote_asks_too(): void
    {
        $this->actingAs($this->user);
        $this->property(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach']);
        $tp = $this->tracked(['street_number' => '61', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);
        $before = Property::withoutGlobalScopes()->withTrashed()->count();

        $this->post(route('corex.tracked-properties.promote', $tp))
            ->assertRedirect(route('corex.tracked-properties.show', $tp))
            ->assertSessionHas('error');
        $this->assertSame($before, Property::withoutGlobalScopes()->withTrashed()->count());

        $this->post(route('corex.tracked-properties.promote', $tp), ['match_decision' => 'different'])->assertRedirect();
        $this->assertNotNull($tp->fresh()->promoted_to_property_id);
    }
}
