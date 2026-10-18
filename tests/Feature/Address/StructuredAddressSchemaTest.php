<?php

namespace Tests\Feature\Address;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\SuburbAlias;
use Database\Seeders\SuburbAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * .ai/specs/structured-address-matching.md §3 / §9 — step 2: the structured address columns,
 * the global suburb_aliases reference table (and its seeder) and the per-agency settings.
 */
class StructuredAddressSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbAlias::flushCache();
    }

    public function test_every_structured_column_exists_on_the_three_address_tables(): void
    {
        foreach (['street_core', 'street_type', 'scheme_number', 'township', 'lpi_code', 'address_raw', 'address_parse_status', 'address_parse_note', 'p24_suburb_id', 'erf_portion'] as $c) {
            $this->assertTrue(Schema::hasColumn('properties', $c), "properties.$c");
        }
        foreach (['p24_suburb_id', 'p24_city_id', 'street_core', 'street_type', 'erf_portion', 'township', 'lpi_code', 'address_raw', 'address_parse_status', 'address_parse_note', 'scheme_number'] as $c) {
            $this->assertTrue(Schema::hasColumn('tracked_properties', $c), "tracked_properties.$c");
        }
        foreach (['p24_suburb_id', 'street_core', 'street_type', 'address_raw'] as $c) {
            $this->assertTrue(Schema::hasColumn('tracked_property_addresses', $c), "tracked_property_addresses.$c");
        }
    }

    public function test_suburb_aliases_is_global_and_seeded_from_the_evidence_config(): void
    {
        $this->assertFalse(Schema::hasColumn('suburb_aliases', 'agency_id'), 'reference data carries no agency_id');
        $map = SuburbAlias::lookupMap();
        $this->assertSame('leisure bay', $map['three hills'] ?? null);
        $this->assertSame('leisure bay', $map['leisure bay'] ?? null);
    }

    public function test_the_alias_seeder_is_idempotent_and_never_overwrites_a_hand_added_alias(): void
    {
        (new SuburbAliasSeeder())->run();
        (new SuburbAliasSeeder())->run();
        $this->assertSame(1, SuburbAlias::where('alias_normalised', 'three hills')->count());

        SuburbAlias::create(['alias_normalised' => 'ramsgate beach', 'canonical_normalised' => 'ramsgate', 'source' => 'admin']);
        (new SuburbAliasSeeder())->run();
        $this->assertSame('ramsgate', SuburbAlias::where('alias_normalised', 'ramsgate beach')->value('canonical_normalised'));
    }

    public function test_the_seeder_is_discovered_by_the_deploy_reference_data_command(): void
    {
        $this->artisan('deploy:sync-reference-data', ['--dry-run' => true])
            ->expectsOutputToContain('SuburbAliasSeeder')
            ->assertSuccessful();
    }

    public function test_an_alias_row_in_the_table_widens_the_suburb_spelling_keys_and_canonicalises(): void
    {
        SuburbAlias::create(['alias_normalised' => 'windsor on sea', 'canonical_normalised' => 'windsor on sea', 'source' => 'admin']);
        SuburbAlias::create(['alias_normalised' => 'windsor', 'canonical_normalised' => 'windsor on sea', 'source' => 'admin']);
        SuburbAlias::flushCache();

        $this->assertSame('windsor on sea', TrackedPropertyAddress::normaliseSuburb('Windsor'));
        $keys = TrackedPropertyAddress::suburbSpellingKeys('Windsor-on-Sea');
        $this->assertContains('windsor', $keys);
        $this->assertContains('windsor on sea', $keys);
    }

    public function test_settings_default_when_the_agency_has_no_row(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);

        $s = AddressMatchSetting::forAgency($agency->id);

        $this->assertSame(AddressMatchSetting::DEFAULTS, $s);
        $this->assertSame(AddressMatchSetting::DEFAULTS, AddressMatchSetting::forAgency(null));
    }

    public function test_saved_settings_override_defaults_and_out_of_range_values_are_clamped(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        AddressMatchSetting::create([
            'agency_id' => $agency->id, 'rule_scheme_exact' => false, 'possible_min_agreeing_columns' => 9,
            'gps_radius_m' => 1, 'neighbour_suburb_credit' => 'ignore', 'unit_missing_on_one_side' => 'garbage',
        ]);

        $s = AddressMatchSetting::forAgency($agency->id);

        $this->assertFalse($s['rule_scheme_exact']);
        $this->assertTrue($s['rule_erf_exact']);
        $this->assertSame(4, $s['possible_min_agreeing_columns'], 'clamped to the page maximum');
        $this->assertSame(5, $s['gps_radius_m'], 'clamped to the page minimum');
        $this->assertSame('ignore', $s['neighbour_suburb_credit']);
        $this->assertSame('possible', $s['unit_missing_on_one_side'], 'an invalid value falls back to the default');
    }

    public function test_one_settings_row_per_agency(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        AddressMatchSetting::create(['agency_id' => $agency->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AddressMatchSetting::create(['agency_id' => $agency->id]);
    }
}
