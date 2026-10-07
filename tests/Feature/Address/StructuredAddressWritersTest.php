<?php

namespace Tests\Feature\Address;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Address\AddressStructurer;
use App\Services\Address\SuburbResolver;
use App\Services\MarketReports\Parsers\CmaInfoVicinitySaleParser;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/structured-address-matching.md §5 — step 4: the writers. Every place an address is
 * saved (Property, TrackedProperty via the match-or-create hub, the address history row, the CMA
 * report parsers) now goes through the one parser — and never overwrites anything already held.
 */
class StructuredAddressWritersTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private P24Suburb $uvongo;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbResolver::flush();
        AddressStructurer::flushColumnCache();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $kzn = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 101, 'p24_province_id' => $kzn->id, 'name' => 'Margate']);
        $this->uvongo = P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo']);
    }

    private function property(array $over = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'T ' . Str::random(4), 'property_type' => 'house', 'status' => 'active',
            'price' => 1500000, 'beds' => 2, 'baths' => 1, 'garages' => 0, 'city' => 'Margate', 'province' => 'KwaZulu Natal',
        ], $over));
    }

    // ── Property ────────────────────────────────────────────────────────

    public function test_a_new_property_gets_the_structured_layer(): void
    {
        $p = $this->property(['street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);
        $p->refresh();

        $this->assertSame('grindewald', $p->street_core);
        $this->assertSame('drive', $p->street_type);
        $this->assertSame('19', $p->street_number);
        $this->assertSame('19 Grindewald Drive', $p->street_name);
        $this->assertSame($this->uvongo->id, (int) $p->p24_suburb_id);
        $this->assertSame('parsed', $p->address_parse_status);
    }

    public function test_an_existing_property_is_structured_on_its_next_save_and_nothing_else_changes(): void
    {
        $p = $this->property(['street_name' => 'Grindewald', 'street_number' => '19', 'suburb' => 'Uvongo']);
        // simulate a row from before the layer existed
        \DB::table('properties')->where('id', $p->id)->update(['street_core' => null, 'street_type' => null, 'address_parse_status' => null, 'p24_suburb_id' => null]);

        $p = Property::withoutGlobalScopes()->find($p->id);
        $p->price = 1600000;
        $p->save();
        $p->refresh();

        $this->assertSame(1600000, (int) $p->price);
        $this->assertSame('Grindewald', $p->street_name);
        $this->assertSame('grindewald', $p->street_core);
        $this->assertSame('parsed', $p->address_parse_status);
    }

    public function test_editing_the_street_recomputes_the_derived_columns(): void
    {
        $p = $this->property(['street_name' => 'Grindewald Drive', 'street_number' => '19', 'suburb' => 'Uvongo']);
        $p->street_name = 'Beach Road';
        $p->save();
        $p->refresh();

        $this->assertSame('beach', $p->street_core);
        $this->assertSame('road', $p->street_type);
    }

    public function test_a_manual_row_is_not_re_derived_by_an_unrelated_save(): void
    {
        $p = $this->property(['street_name' => 'Grindewald Drive', 'street_number' => '19', 'suburb' => 'Uvongo']);
        \DB::table('properties')->where('id', $p->id)->update(['address_parse_status' => 'manual', 'street_core' => 'hand fixed']);

        $p = Property::withoutGlobalScopes()->find($p->id);
        $p->price = 1700000;
        $p->save();

        $this->assertSame('hand fixed', $p->fresh()->street_core);
        $this->assertSame('manual', $p->fresh()->address_parse_status);
    }

    public function test_a_property_with_no_address_at_all_still_saves(): void
    {
        $p = $this->property();
        $this->assertNotNull($p->id);
        $this->assertSame('unparseable', $p->fresh()->address_parse_status);
    }

    public function test_an_unresolvable_suburb_never_blocks_the_save_and_is_marked_review(): void
    {
        $p = $this->property(['street_name' => '12 Marine Drive', 'suburb' => 'Nowhere Extension 9']);
        $p->refresh();

        $this->assertSame('review', $p->address_parse_status);
        $this->assertNull($p->p24_suburb_id);
        $this->assertSame('Nowhere Extension 9', $p->suburb);
    }

    // ── Tracked property via the match-or-create hub ────────────────────

    public function test_a_capture_with_a_polluted_street_and_an_lpi_is_structured_on_create(): void
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id,
            [
                'street_name' => "4 Garden Place   Cadastral Extent  1 605 M²", 'suburb' => 'Uvongo', 'province' => 'KwaZulu Natal',
                'erf_number' => '1329', 'lpi_code' => 'n0et03630000132900000',
            ],
            ['type' => 'deeds_capture', 'ref' => 'cmainfo:n0et03630000132900000'],
            $this->agent->id,
        );
        $tp->refresh();

        $this->assertSame('4', $tp->street_number);
        $this->assertSame('garden', $tp->street_core);
        $this->assertSame('place', $tp->street_type);
        $this->assertSame($this->uvongo->id, (int) $tp->p24_suburb_id);
        $this->assertSame('n0et03630000132900000', $tp->lpi_code);
        $this->assertSame('0', $tp->erf_portion);
        $this->assertSame('0363', $tp->township);
        $this->assertSame('parsed', $tp->address_parse_status);
    }

    public function test_an_enrichment_never_overwrites_a_street_number_already_held(): void
    {
        $svc = app(TrackedPropertyMatchOrCreateService::class);
        $tp = $svc->matchOrCreate($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'p24-1'], $this->agent->id);

        $again = $svc->matchOrCreate($this->agency->id, ['street_number' => '19', 'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo', 'erf_number' => '777'], ['type' => 'p24', 'ref' => 'p24-1'], $this->agent->id);

        $this->assertSame($tp->id, $again->id);
        $this->assertSame('19', $again->fresh()->street_number);
    }

    public function test_the_address_history_row_carries_core_type_and_p24_suburb(): void
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id,
            ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo'],
            ['type' => 'chrome_capture', 'ref' => 'cc-9'],
            $this->agent->id,
        );

        $row = TrackedPropertyAddress::withoutGlobalScopes()->where('tracked_property_id', $tp->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('grindewald', $row->street_core);
        $this->assertSame('drive', $row->street_type);
        $this->assertSame($this->uvongo->id, (int) $row->p24_suburb_id);
    }

    // ── CMA report parsers ─────────────────────────────────────────────

    public function test_the_report_parsers_no_longer_hand_over_the_whole_printed_line(): void
    {
        $m = new \ReflectionMethod(CmaInfoVicinitySaleParser::class, 'makeAddress');
        $m->setAccessible(true);
        $parser = (new \ReflectionClass(CmaInfoVicinitySaleParser::class))->newInstanceWithoutConstructor();

        $out = $m->invoke($parser, ['street_name' => "4 Garden Place   Cadastral Extent  1 605 M²", 'suburb' => 'Uvongo']);

        $this->assertSame('Garden Place', $out['street_name']);
        $this->assertSame('4', $out['street_number']);
    }

    public function test_a_line_the_reader_cannot_read_cleanly_is_passed_through_untouched(): void
    {
        $m = new \ReflectionMethod(CmaInfoVicinitySaleParser::class, 'makeAddress');
        $m->setAccessible(true);
        $parser = (new \ReflectionClass(CmaInfoVicinitySaleParser::class))->newInstanceWithoutConstructor();

        $out = $m->invoke($parser, ['street_number' => '29', 'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertSame('19 Grindewald Drive', $out['street_name']);
        $this->assertSame('29', $out['street_number']);
    }
}
