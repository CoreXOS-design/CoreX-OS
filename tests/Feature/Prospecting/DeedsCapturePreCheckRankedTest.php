<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CMA pre-check — every match ranked, the owner SURNAME as confirming evidence
 * (.ai/specs/deeds-capture.md §9.12, Johan 2026-10-12). Through the real route, the way the extension calls it.
 *
 * The case that failed on Staging: CMA says Unit/Section 6, Du Bantry [Holiday Flats], 77 Colin Street, Uvongo Beach,
 * owner PRETORIUS. CoreX holds property "Unit 6, Du Bantry, Colin Street, Uvongo Beach" with NO street number (seller
 * Pretorius), and a unit-less "77 Colin Street" prospecting property. The first must come back on top.
 */
final class DeedsCapturePreCheckRankedTest extends TestCase
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
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        Sanctum::actingAs($this->user);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function stock(array $over = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'property_type' => 'apartment', 'beds' => 2, 'baths' => 1, 'garages' => 0, 'price' => 1500000,
            'title' => 'Test', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
    }

    /** CoreX property 21729 */
    private function unit6(array $over = []): Property
    {
        return $this->stock(array_merge([
            'complex_name' => 'Du Bantry', 'unit_number' => '6', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach',
            'address' => 'Unit 6, Du Bantry, Colin Street', 'mandate_type' => 'Sole',
        ], $over));
    }

    /** CoreX property 15815 — the unit-less "77 Colin Street" record */
    private function noUnit77(array $over = []): Property
    {
        return $this->stock(array_merge([
            'street_number' => '77', 'street_name' => 'Colin Street', 'address' => '77 Colin Street', 'suburb' => 'UVONGO BEACH',
            'status' => 'prospecting', 'title' => '10 Du Bantry Holiday Flats, 77 Colin',
        ], $over));
    }

    private function seller(Property $p, string $last, string $first = 'Leon', array $contact = [], string $role = 'seller', ?int $agencyId = null): Contact
    {
        // withoutAgencyStamping(): BelongsToAgency would otherwise force the acting user's agency onto the contact
        $c = Contact::withoutAgencyStamping(fn () => Contact::create(array_merge([
            'agency_id' => $agencyId ?? $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->user->id,
            'first_name' => $first, 'last_name' => $last, 'phone' => '082' . random_int(1000000, 9999999),
        ], $contact)));
        DB::table('contact_property')->insert(['property_id' => $p->id, 'contact_id' => $c->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $c;
    }

    /** what the extension sends for Johan's CMA page */
    private function duBantry(array $property = [], array $owners = [['name' => 'PRETORIUS LEON']]): array
    {
        return [
            'source_ref' => 'cmainfo:47/1978-6',
            'property' => array_merge([
                'scheme_name' => 'DU BANTRY HOLIDAY FLATS', 'complex_name' => 'DU BANTRY HOLIDAY FLATS', 'scheme_number' => '47/1978',
                'section_number' => '6', 'unit_number' => '6', 'address' => '77 COLIN STREET', 'street_number' => '77', 'street_name' => 'COLIN STREET',
                'suburb' => 'Uvongo Beach', 'municipality' => 'Margate', 'province' => 'KwaZulu-Natal',
            ], $property),
            'owners' => $owners,
        ];
    }

    private function check(array $payload): array
    {
        $r = $this->postJson(route('v1.deeds-capture.check-duplicate'), $payload);
        $r->assertOk();

        return $r->json();
    }

    private function ids(array $json): array
    {
        return array_map(fn ($m) => $m['property_id'] ?? ('t' . $m['tracked_property_id']), $json['matches']);
    }

    // ── Johan's case ────────────────────────────────────────────────────

    public function test_unit_6_du_bantry_comes_back_first_and_the_unit_less_77_colin_street_below_it(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');
        $b = $this->noUnit77();
        // another unit of the same scheme, with the number on file: a DIFFERENT unit, must not appear as a match
        $this->stock(['complex_name' => 'Du Bantry', 'unit_number' => '11', 'street_number' => '77', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo Beach', 'address' => 'Unit 11, Du Bantry, 77 Colin Street']);

        $json = $this->check($this->duBantry());

        $this->assertSame('exists', $json['status']);
        $this->assertSame([$a->id, $b->id], $this->ids($json), 'unit 6 first, the unit-less record second, unit 11 never');
        $this->assertSame(2, $json['match_count']);

        [$top, $weak] = $json['matches'];
        $this->assertSame(1, $top['rank']);
        $this->assertSame('exact', $top['rank_group']);
        $this->assertTrue($top['confident']);
        $this->assertTrue($top['owner_surname_match']);
        $this->assertStringContainsString('Unit 6', $top['address']);
        $this->assertStringContainsString('Du Bantry', $top['address']);

        $this->assertSame(2, $weak['rank']);
        $this->assertSame('no_unit', $weak['rank_group']);
        $this->assertSame('Same address, no unit number - may be a different unit', $weak['rank_label']);
        $this->assertSame('Same address, no unit number - may be a different unit', $weak['reason']);
        $this->assertFalse($weak['confident']);
    }

    public function test_the_scheme_name_as_just_du_bantry_gives_the_same_answer(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');
        $this->noUnit77();

        $json = $this->check($this->duBantry(['scheme_name' => 'DU BANTRY', 'complex_name' => 'DU BANTRY']));

        $this->assertSame($a->id, $json['matches'][0]['property_id']);
        $this->assertSame('exact', $json['matches'][0]['rank_group']);
    }

    public function test_when_the_scheme_name_was_not_read_the_unit_and_the_surname_still_find_it(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');
        $b = $this->noUnit77();

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null]));

        $this->assertSame([$a->id, $b->id], $this->ids($json));
        $this->assertSame('exact', $json['matches'][0]['rank_group'], 'same unit + street + suburb, and the owner surname matches the seller');
        $this->assertSame('exists', $json['status']);
        $this->assertSame('no_unit', $json['matches'][1]['rank_group']);
    }

    public function test_without_the_surname_the_same_candidate_is_only_possible_and_still_ranks_above_the_no_unit_record(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');
        $b = $this->noUnit77();

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null], [['name' => 'SMIT ANNA']]));

        $this->assertSame('possible_match', $json['status']);
        $this->assertSame([$a->id, $b->id], $this->ids($json));
        $this->assertSame('possible', $json['matches'][0]['rank_group']);
        $this->assertFalse($json['matches'][0]['owner_surname_match']);
        $this->assertSame('no_unit', $json['matches'][1]['rank_group']);
    }

    // ── do not regress ──────────────────────────────────────────────────

    public function test_195_pretorius_drive_is_still_an_exact_match(): void
    {
        $p = $this->stock(['street_number' => '195', 'street_name' => 'Pretorius Drive', 'address' => '195 Pretorius Drive', 'suburb' => 'Melville', 'property_type' => 'house']);

        $json = $this->check(['property' => ['address' => '195 PRETORIUS DRIVE', 'street_number' => '195', 'street_name' => 'PRETORIUS DRIVE', 'suburb' => 'Melville']]);

        $this->assertSame('exists', $json['status']);
        $this->assertSame($p->id, $json['matches'][0]['property_id']);
        $this->assertSame('exact', $json['matches'][0]['rank_group']);
    }

    public function test_mont_blanc_unit_14_is_still_found_by_its_earlier_capture_and_its_property(): void
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agency->id, 'capture_kind' => 'deeds_capture', 'street_number' => '21', 'street_name' => 'Forest',
            'complex_name' => 'MONT BLANC', 'scheme_name' => 'MONT BLANC', 'scheme_number' => '358/1992', 'section_number' => '14',
            'suburb' => 'MARGATE NORTH BEACH', 'deeds_captured_at' => now()->subDays(30), 'source_chain' => [],
        ]);
        $p = $this->stock(['complex_name' => 'MONT BLANC UNIT 14', 'unit_number' => '14', 'street_number' => '21', 'street_name' => 'Forest', 'suburb' => 'MARGATE NORTH BEACH', 'address' => 'Unit 14, MONT BLANC UNIT 4, 21 Forest']);

        $json = $this->check(['source_ref' => 'cmainfo:358/1992-14', 'property' => [
            'scheme_name' => 'MONT BLANC', 'complex_name' => 'MONT BLANC', 'scheme_number' => '358/1992', 'section_number' => '14', 'unit_number' => '14',
            'address' => '21 FOREST ROAD', 'street_number' => '21', 'street_name' => 'FOREST ROAD', 'suburb' => 'Margate North Beach',
        ], 'owners' => [['name' => 'CARR JOHANNA HENDRINA']]]);

        $this->assertSame('exists', $json['status']);
        $ids = $this->ids($json);
        $this->assertContains('t' . $tp->id, $ids);
        $this->assertContains($p->id, $ids);
        $this->assertSame('exact', $json['matches'][0]['rank_group']);
    }

    // ── must not false-match ────────────────────────────────────────────

    public function test_a_different_unit_in_the_same_scheme_is_not_a_match(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');

        $json = $this->check($this->duBantry(['section_number' => '7', 'unit_number' => '7']));

        $this->assertNotContains($a->id, $this->ids($json), 'unit 7 is not unit 6, even with the same surname');
        $this->assertSame('not_found', $json['status']);
    }

    public function test_19_is_never_29_on_the_same_street_even_with_the_same_surname(): void
    {
        $p = $this->stock(['street_number' => '29', 'street_name' => 'Grindewald Drive', 'address' => '29 Grindewald Drive', 'suburb' => 'Uvongo', 'property_type' => 'house']);
        $this->seller($p, 'Pretorius');

        $json = $this->check(['property' => ['address' => '19 Grindewald Drive', 'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], 'owners' => [['name' => 'PRETORIUS LEON']]]);

        $this->assertSame([], $json['matches']);
        $this->assertSame('not_found', $json['status']);
    }

    public function test_the_same_surname_alone_with_no_address_signal_is_not_a_match(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');

        $json = $this->check(['property' => ['address' => '5 BEACH ROAD', 'street_number' => '5', 'street_name' => 'BEACH ROAD', 'suburb' => 'Margate'], 'owners' => [['name' => 'PRETORIUS LEON']]]);

        $this->assertSame([], $json['matches']);
        $this->assertSame('not_found', $json['status']);
    }

    public function test_a_surname_does_not_cross_agencies(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $a = $this->unit6();
        // the only "Pretorius" linked to the property belongs to ANOTHER agency
        $this->seller($a, 'Pretorius', 'Leon', [], 'seller', $other->id);

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null]));

        $this->assertSame($a->id, $json['matches'][0]['property_id']);
        $this->assertFalse($json['matches'][0]['owner_surname_match'], 'another agency\'s contact is never evidence');
        $this->assertSame('possible', $json['matches'][0]['rank_group']);
    }

    // ── owners: several, compound, company ──────────────────────────────

    public function test_with_several_owners_any_one_surname_confirms(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Pretorius');

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null], [['name' => 'SMIT ANNA'], ['name' => 'PRETORIUS LEON'], ['name' => 'JONES PETER']]));

        $this->assertTrue($json['matches'][0]['owner_surname_match']);
        $this->assertSame('exact', $json['matches'][0]['rank_group']);
    }

    public function test_a_compound_surname_matches_however_cma_and_corex_write_it(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Van der Merwe');

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null], [['name' => 'MERWE FRANCOIS PHILLIPUS VAN DER']]));

        $this->assertTrue($json['matches'][0]['owner_surname_match']);
    }

    public function test_a_company_owner_is_matched_by_its_name_not_by_a_surname(): void
    {
        $a = $this->unit6();
        $this->seller($a, 'Holdings', '', ['contact_kind' => 'entity', 'entity_name' => 'Du Bantry Holdings (Pty) Ltd']);

        $json = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null], [['name' => 'DU BANTRY HOLDINGS PTY LTD', 'id_type' => 'company_reg']]));

        $this->assertTrue($json['matches'][0]['owner_surname_match']);

        $json2 = $this->check($this->duBantry(['scheme_name' => null, 'complex_name' => null, 'scheme_number' => null], [['name' => 'COLIN HOLDINGS PTY LTD', 'id_type' => 'company_reg']]));
        $this->assertFalse($json2['matches'][0]['owner_surname_match'], 'a different company is not a match');
    }

    public function test_an_owner_captured_on_a_tracked_property_confirms_that_candidate(): void
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agency->id, 'capture_kind' => 'deeds_capture', 'street_name' => 'Colin Street', 'unit_number' => '6',
            'complex_name' => 'DU BANTRY', 'section_number' => '6', 'suburb' => 'UVONGO BEACH', 'source_chain' => [],
        ]);
        TrackedPropertyOwner::create(['tracked_property_id' => $tp->id, 'name' => 'Leon Pretorius', 'is_primary' => true]);

        $json = $this->check($this->duBantry(['scheme_number' => null]));

        $this->assertContains('t' . $tp->id, $this->ids($json));
        $m = collect($json['matches'])->firstWhere('tracked_property_id', $tp->id);
        $this->assertTrue($m['owner_surname_match']);
    }

    // ── shape ───────────────────────────────────────────────────────────

    public function test_every_match_carries_its_rank_and_a_plain_label_and_the_response_says_how_many(): void
    {
        $a = $this->unit6();
        $this->noUnit77();

        $json = $this->check($this->duBantry());

        $this->assertSame(count($json['matches']), $json['match_count']);
        foreach ($json['matches'] as $i => $m) {
            $this->assertSame($i + 1, $m['rank']);
            $this->assertNotEmpty($m['rank_group']);
            $this->assertNotEmpty($m['rank_label']);
            $this->assertArrayHasKey('owner_surname_match', $m);
        }
    }
}
