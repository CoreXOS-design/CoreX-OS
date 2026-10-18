<?php

namespace Tests\Feature\Address;

use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\SuburbAlias;
use App\Services\Address\AddressStructurer;
use App\Services\Address\SuburbResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/structured-address-matching.md §4.4 / §5 — suburb -> Property24 suburb resolution and
 * the fill-only-empty application rules (step 3). Uses the real South Coast shapes: Uvongo and
 * Uvongo Beach are two different Property24 suburbs; "Saint"/"St" Michaels On Sea are one; "Three Hills"
 * is Leisure Bay; "Shakas Rock" is "Shaka's Rock".
 */
class AddressStructurerTest extends TestCase
{
    use RefreshDatabase;

    private P24Suburb $uvongo;
    private P24Suburb $uvongoBeach;
    private P24Suburb $stMichaels;
    private P24Suburb $leisureBay;
    private P24Suburb $shakasRock;
    private P24Suburb $melvilleKzn;
    private P24Suburb $melvilleGp;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbResolver::flush();
        SuburbAlias::flushCache();
        AddressStructurer::flushColumnCache();

        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $kzn = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $gp = P24Province::create(['p24_id' => 3, 'p24_country_id' => $country->id, 'name' => 'Gauteng']);
        $margate = P24City::create(['p24_id' => 101, 'p24_province_id' => $kzn->id, 'name' => 'Margate']);
        $jhb = P24City::create(['p24_id' => 102, 'p24_province_id' => $gp->id, 'name' => 'Johannesburg']);

        $mk = fn (int $pid, string $name, P24City $city) => P24Suburb::create([
            'p24_id' => $pid, 'p24_city_id' => $city->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', str_replace("'", '', $name))) . '-' . $pid,
        ]);
        $this->uvongo = $mk(6359, 'Uvongo', $margate);
        $this->uvongoBeach = $mk(33106, 'Uvongo Beach', $margate);
        $this->stMichaels = $mk(7001, 'St Michaels On Sea', $margate);
        $this->leisureBay = $mk(7002, 'Leisure Bay', $margate);
        $this->shakasRock = $mk(7003, "Shaka's Rock", $margate);
        $this->melvilleKzn = $mk(7004, 'Melville', $margate);
        $this->melvilleGp = $mk(7005, 'Melville', $jhb);
        // slugs for the lookups that go by slug
        $this->shakasRock->update(['slug' => 'shakas-rock']);
        $this->stMichaels->update(['slug' => 'st-michaels-on-sea']);
        $this->uvongo->update(['slug' => 'uvongo']);
        $this->uvongoBeach->update(['slug' => 'uvongo-beach']);
        $this->leisureBay->update(['slug' => 'leisure-bay']);
    }

    private function s(): AddressStructurer
    {
        return new AddressStructurer();
    }

    // ── suburb resolution ───────────────────────────────────────────────

    public function test_uvongo_and_uvongo_beach_are_two_different_property24_suburbs(): void
    {
        $a = SuburbResolver::resolve('Uvongo');
        $b = SuburbResolver::resolve('Uvongo Beach');

        $this->assertSame($this->uvongo->id, $a['p24_suburb_id']);
        $this->assertSame($this->uvongoBeach->id, $b['p24_suburb_id']);
        $this->assertNotSame($a['p24_suburb_id'], $b['p24_suburb_id']);
    }

    public function test_saint_and_st_michaels_on_sea_resolve_to_the_same_suburb(): void
    {
        $this->assertSame($this->stMichaels->id, SuburbResolver::resolve('Saint Michaels On Sea')['p24_suburb_id']);
        $this->assertSame($this->stMichaels->id, SuburbResolver::resolve("St Michael's-on-Sea")['p24_suburb_id']);
        $this->assertSame($this->stMichaels->id, SuburbResolver::resolve('ST MICHAELS ON SEA')['p24_suburb_id']);
    }

    public function test_an_apostrophe_difference_is_the_same_suburb(): void
    {
        $this->assertSame($this->shakasRock->id, SuburbResolver::resolve('Shakas Rock')['p24_suburb_id']);
        $this->assertSame($this->shakasRock->id, SuburbResolver::resolve("Shaka's Rock")['p24_suburb_id']);
    }

    public function test_an_alias_from_the_reference_table_resolves(): void
    {
        $this->assertSame($this->leisureBay->id, SuburbResolver::resolve('Three Hills')['p24_suburb_id']);
    }

    public function test_a_suburb_that_exists_in_two_provinces_uses_the_province(): void
    {
        $this->assertSame($this->melvilleKzn->id, SuburbResolver::resolve('Melville', 'KwaZulu-Natal')['p24_suburb_id']);
        $this->assertSame($this->melvilleGp->id, SuburbResolver::resolve('Melville', 'Gauteng')['p24_suburb_id']);
        $this->assertNull(SuburbResolver::resolve('Melville')['p24_suburb_id'], 'ambiguous with nothing to tell them apart — never a guess');
    }

    public function test_an_unknown_suburb_is_unresolved_and_makes_the_address_review(): void
    {
        $this->assertNull(SuburbResolver::resolve('Ramsgate Beach Extension 9')['p24_suburb_id']);
        $r = $this->s()->structure(['street_name' => '12 Marine Drive', 'suburb' => 'Ramsgate Beach Extension 9']);
        $this->assertSame('review', $r['status']);
        $this->assertContains('suburb_unresolved', $r['conflicts']);
        $this->assertFalse($r['suburb_resolved']);
    }

    public function test_blank_suburb_is_not_an_error_and_not_a_review_reason(): void
    {
        $r = $this->s()->structure(['street_name' => '12 Marine Drive']);
        $this->assertSame('parsed', $r['status']);
        $this->assertNull($r['p24_suburb_id']);
    }

    // ── fill-only-empty application ─────────────────────────────────────

    public function test_a_property_keeps_its_own_street_number_and_p24_suburb_and_gets_only_derived_columns(): void
    {
        $p = new Property([
            'street_number' => '19', 'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->uvongoBeach->id,
        ]);

        $this->s()->apply($p);

        $this->assertSame('19', $p->street_number);
        $this->assertSame('19 Grindewald Drive', $p->street_name, 'the raw street_name is never rewritten');
        $this->assertSame($this->uvongoBeach->id, (int) $p->p24_suburb_id, 'a form-chosen P24 suburb stands');
        $this->assertSame('grindewald', $p->street_core);
        $this->assertSame('drive', $p->street_type);
        $this->assertSame('parsed', $p->address_parse_status);
        $this->assertSame('19 Grindewald Drive', $p->address_raw);
    }

    public function test_a_property_with_no_street_number_gets_it_lifted_from_the_name_and_a_p24_suburb(): void
    {
        $p = new Property(['street_name' => '29 Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->s()->apply($p);

        $this->assertSame('29', $p->street_number);
        $this->assertSame('29 Grindewald Drive', $p->street_name);
        $this->assertSame($this->uvongo->id, (int) $p->p24_suburb_id);
    }

    public function test_a_disagreeing_number_is_review_and_the_existing_column_is_not_overwritten(): void
    {
        $p = new Property(['street_number' => '29', 'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->s()->apply($p);

        $this->assertSame('29', $p->street_number);
        $this->assertSame('review', $p->address_parse_status);
        $this->assertStringContainsString('29', (string) $p->address_parse_note);
    }

    public function test_a_tracked_property_gets_the_derived_columns_including_the_p24_suburb(): void
    {
        $tp = new TrackedProperty([
            'street_name' => "4 Garden Place   Cadastral Extent  1 605 M²", 'suburb' => 'Uvongo', 'erf_number' => '1329',
            'lpi_code' => 'n0et03630000132900000',
        ]);

        $this->s()->apply($tp);

        $this->assertSame('4', $tp->street_number);
        $this->assertSame('garden', $tp->street_core);
        $this->assertSame('place', $tp->street_type);
        $this->assertSame($this->uvongo->id, (int) $tp->p24_suburb_id);
        $this->assertSame($this->uvongo->p24_city_id, (int) $tp->p24_city_id);
        $this->assertSame('0', $tp->erf_portion);
        $this->assertSame('0363', $tp->township);
    }

    public function test_the_address_history_row_gets_core_type_and_suburb_only(): void
    {
        $row = new TrackedPropertyAddress(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo Beach']);
        $row->forceFill(['address_parse_status' => null]);

        $updates = $this->s()->updatesFor($row);

        $this->assertSame('grindewald', $updates['street_core']);
        $this->assertSame($this->uvongoBeach->id, $updates['p24_suburb_id']);
        $this->assertArrayNotHasKey('address_parse_status', $updates, 'the history table keeps no status column');
    }

    public function test_an_admin_fixed_row_is_left_alone_until_an_address_column_changes(): void
    {
        $tp = new TrackedProperty();
        $tp->exists = true;
        $tp->setRawAttributes([
            'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo',
            'address_parse_status' => 'manual', 'street_core' => 'hand fixed',
        ], true);

        $this->s()->apply($tp);
        $this->assertSame('hand fixed', $tp->street_core, 'manual rows are not re-derived on an unrelated save');
        $this->assertSame('manual', $tp->address_parse_status);

        $tp->street_name = 'Beach Road';
        $this->s()->apply($tp);
        $this->assertSame('beach', $tp->street_core, 'an actual address edit re-derives');
        $this->assertSame('parsed', $tp->address_parse_status);
    }

    public function test_applying_twice_changes_nothing(): void
    {
        $p = new Property(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);
        $this->s()->apply($p);
        $first = $p->getAttributes();
        $this->s()->apply($p);
        $this->assertEquals($first, $p->getAttributes());
    }

    public function test_a_parser_failure_is_absorbed_and_never_blocks_a_save(): void
    {
        $p = new Property(['street_name' => str_repeat("\xC3\x28", 10), 'suburb' => "\xFF"]);
        $this->s()->apply($p); // must not throw
        $this->assertTrue(true);
    }
}
