<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosPropertyCandidates;
use App\Services\Rentals\TakeOnImport\Reos\ReosPropertyFacts;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyConfirmService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-479 (Johan, 21 Oct) — "step 1 is: check if the property exists", with NO suburb. Looks across every suburb of the agency's
 * own properties on street number + street name (+ unit / complex when the reOS address has them). One match = "In CoreX" (no
 * suburb picker); several = pick one or "none of these"; none = "Not in CoreX" + suburb picker. Import links the chosen
 * property and never creates a duplicate. Synthetic data only ("ZZ").
 *
 * Paths proven: exactly one / several / none / the pick (a candidate, "none of these", a stranger id refused) / "504/3" read as
 * unit 504 no. 3 whether CoreX stores it split or as "504/3" / street type "Ave" = "Avenue" / unit needed when the reOS address
 * has one / a CoreX unit is not a match for a bare street address / a different complex rules a property out / another agency's
 * and archived properties never match / import links the property and leaves the property count and its suburb alone.
 */
final class ReosPropertyMatchTest extends TestCase
{
    use RefreshDatabase;
    use ReosFixtures;

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    public function test_the_address_reader_turns_504_slash_3_into_unit_504_number_3(): void
    {
        $f = ReosPropertyFacts::build('504/3 ZZ Robin Road')['facts'];
        self::assertSame(['3', '504'], [$f['street_number'], $f['unit_number']]);
        self::assertSame(['5', '12'], ReosPropertyFacts::splitNumberAndUnit('12/5', null));
        self::assertSame(['12', '3'], ReosPropertyFacts::splitNumberAndUnit('12', '3'), 'a unit that is already given is kept');
        self::assertSame(['3', null], ReosPropertyFacts::splitNumberAndUnit('3', null));
    }

    public function test_exactly_one_match_is_in_corex_with_no_suburb_needed_and_import_links_it_without_a_duplicate(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $known = $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Ave'])]);   // "Ave", no suburb
        $row = $run->rows()->first();

        self::assertSame(RentalTakeOnImportRow::ACTION_MATCH, $row->property_match_action);
        self::assertSame($known->id, $row->decisions_json['property']['link_id']);
        self::assertStringContainsString('ZZ Uvongo', $row->property_match_label, 'the suburb is read from the matched property');
        self::assertNotContains('suburb_required', collect($row->blockers_json)->pluck('code')->all());
        self::assertNotContains('property_choice_required', collect($row->blockers_json)->pluck('code')->all());

        $properties = Property::withoutGlobalScopes()->count();
        $this->actingAs($admin);
        app(ReosDecisionService::class)->apply($row, $run, ['selected' => true]);
        $r = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);

        self::assertSame(1, $r['created'], (string) $run->rows()->value('import_error'));
        $lease = Lease::withoutGlobalScopes()->first();
        self::assertSame($known->id, (int) $lease->property_id, 'the lease is on the property CoreX already had');
        self::assertSame($properties, Property::withoutGlobalScopes()->count(), 'no duplicate property');
        self::assertSame('ZZ Uvongo', $known->fresh()->suburb, 'the matched property is not changed');
        self::assertSame(RentalTakeOnImportRow::ACTION_MATCH, $run->rows()->first()->property_match_action);
    }

    public function test_unit_and_number_are_read_whichever_way_corex_holds_them(): void
    {
        [$agency, $branch, , $agent] = $this->reosAgency();
        $split = $this->property($agency, $branch, $agent, ['street_number' => '3', 'unit_number' => '504', 'street_name' => 'ZZ Robin Road', 'suburb' => 'ZZ Margate']);

        self::assertSame([$split->id], array_column(ReosPropertyCandidates::find($agency->id, '504/3 ZZ Robin Road'), 'id'), 'unit 504 / no. 3');

        // Another agency's property written the old way, as "504/3" in the number alone.
        $joined = $this->property($agency, $branch, $agent, ['street_number' => '504/3', 'unit_number' => null, 'street_name' => 'ZZ Kingfisher Road', 'suburb' => 'ZZ Margate']);
        self::assertSame([$joined->id], array_column(ReosPropertyCandidates::find($agency->id, 'Unit 504, 3 ZZ Kingfisher Road'), 'id'), '"504/3" held in the number matches unit 504 no. 3');
    }

    public function test_a_unit_is_needed_when_the_reos_address_has_one_and_a_corex_unit_is_not_a_bare_street_address(): void
    {
        [$agency, $branch, , $agent] = $this->reosAgency();
        $block = $this->property($agency, $branch, $agent, ['street_number' => '13', 'unit_number' => '3', 'complex_name' => 'ZZ Aqua Breeze', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $house = $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo', 'unit_number' => null, 'complex_name' => null]);

        $withUnit = ReosPropertyCandidates::find($agency->id, '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue');
        self::assertSame([$block->id], array_column($withUnit, 'id'), 'unit 3 in Aqua Breeze is the flat, not the house');

        $bare = ReosPropertyCandidates::find($agency->id, '13 ZZ Stafford Avenue');
        self::assertSame([$house->id], array_column($bare, 'id'), 'a bare street address is the house, not a flat in a block');

        self::assertSame([], ReosPropertyCandidates::find($agency->id, '5 ZZ Aqua Breeze, 13 ZZ Stafford Avenue'), 'a different unit is a different dwelling');
        self::assertSame([], ReosPropertyCandidates::find($agency->id, '3 ZZ Other Court, 13 ZZ Stafford Avenue'), 'a different complex rules a property out');
        self::assertSame([], ReosPropertyCandidates::find($agency->id, 'ZZ Stafford Avenue'), 'a street alone is not a property');
    }

    public function test_other_agencies_and_archived_properties_never_match(): void
    {
        [$agency, $branch, , $agent] = $this->reosAgency();
        [$other, $otherBranch, , $otherAgent] = $this->reosAgency('ZZ Other Agency');
        $this->property($other, $otherBranch, $otherAgent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $mine = $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);

        self::assertSame([$mine->id], array_column(ReosPropertyCandidates::find($agency->id, '13 ZZ Stafford Avenue'), 'id'));

        $mine->delete();
        self::assertSame([], ReosPropertyCandidates::find($agency->id, '13 ZZ Stafford Avenue'));
    }

    public function test_several_matches_ask_which_one_and_the_pick_is_what_import_links(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $a = $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $b = $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Margate']);
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Avenue'])]);
        $row = $run->rows()->first();
        $svc = app(ReosDecisionService::class);

        self::assertSame(RentalTakeOnImportRow::ACTION_CHOOSE, $row->property_match_action);
        self::assertEqualsCanonicalizing([$a->id, $b->id], array_column($row->decisions_json['property']['candidates'], 'id'));
        self::assertContains('property_choice_required', collect($row->blockers_json)->pluck('code')->all());
        self::assertSame('property-choice', collect($row->blockers_json)->firstWhere('code', 'property_choice_required')['field']);
        self::assertNotContains('suburb_required', collect($row->blockers_json)->pluck('code')->all(), 'one question at a time');

        // The screen shows both, with their suburbs.
        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->getContent();
        self::assertStringContainsString('ZZ Uvongo', $html);
        self::assertStringContainsString('ZZ Margate', $html);
        self::assertStringContainsString('None — create new', html_entity_decode($html));

        // A property that is not one of the listed candidates is refused.
        $stranger = $this->property($agency, $branch, $agent, ['street_number' => '99', 'street_name' => 'ZZ Elsewhere Road', 'suburb' => 'ZZ Elsewhere']);
        try {
            $svc->apply($row, $run, ['property_choice' => $stranger->id]);
            self::fail('a property outside the candidates must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            self::assertStringContainsString('Pick one of the properties listed', $e->getMessage() . json_encode($e->errors()));
        }
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['property_choice' => $stranger->id])->assertStatus(422);

        $row = $svc->apply($run->rows()->first(), $run, ['property_choice' => $b->id, 'selected' => true]);
        self::assertSame(RentalTakeOnImportRow::ACTION_MATCH, $row->property_match_action);
        self::assertSame($b->id, $row->decisions_json['property']['link_id']);
        self::assertNotContains('property_choice_required', collect($row->blockers_json)->pluck('code')->all());
        self::assertStringContainsString('ZZ Margate', $row->property_match_label);

        $count = Property::withoutGlobalScopes()->count();
        $this->actingAs($admin);
        $r = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        self::assertSame(1, $r['created'], (string) $run->rows()->value('import_error'));
        self::assertSame($b->id, (int) Lease::withoutGlobalScopes()->first()->property_id, 'the lease is on the property the user picked');
        self::assertSame($count, Property::withoutGlobalScopes()->count());
    }

    public function test_none_of_these_goes_to_a_new_property_with_the_suburb_picker(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Margate']);
        $suburb = $this->p24Suburb('ZZ Shelly Beach');
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Avenue'])]);
        $svc = app(ReosDecisionService::class);

        $row = $svc->apply($run->rows()->first(), $run, ['property_choice' => 'new']);

        self::assertSame(RentalTakeOnImportRow::ACTION_CREATE, $row->property_match_action);
        self::assertNull($row->decisions_json['property']['link_id']);
        $codes = collect($row->blockers_json)->pluck('code')->all();
        self::assertContains('suburb_required', $codes);
        self::assertNotContains('property_choice_required', $codes);

        $row = $svc->apply($row, $run, ['suburb_id' => $suburb->id, 'selected' => true]);
        self::assertSame(RentalTakeOnImportRow::ACTION_CREATE, $row->property_match_action, 'a suburb pick does not undo the user\'s "none of these"');
        self::assertFalse($row->is_blocked, json_encode($row->blockers_json));

        $count = Property::withoutGlobalScopes()->count();
        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        self::assertSame($count + 1, Property::withoutGlobalScopes()->count(), 'a new property, as chosen');
        $lease = Lease::withoutGlobalScopes()->first();
        self::assertSame('ZZ Shelly Beach', Property::withoutGlobalScopes()->find($lease->property_id)->suburb);
    }

    public function test_no_match_is_not_in_corex_with_the_suburb_picker_as_before(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '77 ZZ Nowhere Lane'])]);
        $row = $run->rows()->first();

        self::assertSame(RentalTakeOnImportRow::ACTION_CREATE, $row->property_match_action);
        self::assertSame([], $row->decisions_json['property']['candidates']);
        self::assertNull($row->decisions_json['property']['link_id']);
        self::assertContains('suburb_required', collect($row->blockers_json)->pluck('code')->all());
        self::assertSame(1, $run->fresh()->counts_json['property_not_in_corex']);
        $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->assertSee('Which suburb is the new property');
    }

    public function test_run_counts_separate_matched_to_pick_and_not_in_corex(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->property($agency, $branch, $agent, ['street_number' => '13', 'street_name' => 'ZZ Stafford Avenue', 'suburb' => 'ZZ Uvongo']);
        $this->property($agency, $branch, $agent, ['street_number' => '8', 'street_name' => 'ZZ Beach Road', 'suburb' => 'ZZ Uvongo']);
        $this->property($agency, $branch, $agent, ['street_number' => '8', 'street_name' => 'ZZ Beach Road', 'suburb' => 'ZZ Margate']);
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '13 ZZ Stafford Avenue']),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '8 ZZ Beach Road', 'Owner' => 'ZZ TEST Owner Two', 'Owner Email' => 'zz.o2@example.test', 'Owner Phone' => '+27815550202', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Email' => 'zz.t2@example.test', 'Tenant Phone' => '+27635550203']),
            $this->reosRow(['uuid' => self::UUID_C, 'Property Address' => '77 ZZ Nowhere Lane', 'Owner' => 'ZZ TEST Owner Three', 'Owner Email' => 'zz.o3@example.test', 'Owner Phone' => '+27815550303', 'Tenant' => 'ZZ TEST Tenant Three', 'Tenant Email' => 'zz.t3@example.test', 'Tenant Phone' => '+27635550304']),
        ]);

        $c = $run->fresh()->counts_json;
        self::assertSame([1, 1, 1], [$c['property_matched'], $c['property_to_choose'], $c['property_not_in_corex']]);
        $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run) . '?q=Stafford')->assertOk()->assertSee('13 ZZ Stafford Avenue')->assertDontSee('77 ZZ Nowhere Lane');
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function makeRun(User $user, array $rows): RentalTakeOnImportRun
    {
        $this->actingAs($user);

        return app(ReosTenancyRunService::class)->createRun($user, (int) $user->agency_id, $user->branch_id, $this->upload($this->scheduleFile($rows)));
    }

    /** A property CoreX already holds, with exactly the address fields given. */
    private function property(Agency $agency, Branch $branch, User $agent, array $f): Property
    {
        $p = Property::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => 'ZZ property ' . uniqid(), 'address' => 'ZZ', 'price' => 0, 'status' => 'let_out', 'listing_type' => 'rental',
            'property_type' => 'house', 'beds' => 0, 'baths' => 0, 'garages' => 0, 'suburb' => 'ZZ Somewhere',
        ], $f));

        // Written as given (the address reader on save may fill street_core / type, never overwrites a held number or unit).
        Property::withoutGlobalScopes()->whereKey($p->id)->update(array_intersect_key($f, array_flip(['street_number', 'unit_number', 'complex_name'])));

        return $p->fresh();
    }
}
