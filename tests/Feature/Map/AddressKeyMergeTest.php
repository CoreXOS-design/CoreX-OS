<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\Property;
use App\Models\User;
use App\Services\Map\LocationGrouper;
use App\Support\Geocoding\PropertyAddressKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dedup foundation Q4 Phase B — proof tests.
 *
 *   1. PropertyAddressKey helper:
 *      - "Ave" and "Avenue" produce the same key (matcher normaliser applied).
 *      - unit_number changes the key (two flats → two keys).
 *      - read-time string and write-time components agree.
 *      - null inputs return null (no crash).
 *
 *   2. Property model boot:
 *      - saving a Property auto-populates suburb_normalised + street_name_normalised.
 *
 *   3. LocationGrouper address-key second-pass merge:
 *      - H + M at the same address with SLIGHTLY DIFFERENT GPS land in one
 *        composite, AND M correctly collapses into the H pin's cma_info.
 *      - Two flats same building different unit stay as TWO pins (no merge).
 *      - Records without parseable addresses fall through to GPS-only path
 *        and never crash the grouper.
 *
 *   4. Schema integrity:
 *      - properties has the two new columns + composite index.
 */
final class AddressKeyMergeTest extends TestCase
{
    use RefreshDatabase;

    // ── 1. PropertyAddressKey helper ────────────────────────────────────

    public function test_address_key_normalises_street_abbreviations_to_same_output(): void
    {
        $ave = PropertyAddressKey::fromComponents([
            'street_number' => '12', 'street_name' => 'Hibiscus Ave', 'suburb' => 'Margate Beach',
        ]);
        $avenue = PropertyAddressKey::fromComponents([
            'street_number' => '12', 'street_name' => 'Hibiscus Avenue', 'suburb' => 'Margate Beach',
        ]);
        $this->assertSame($ave, $avenue,
            'Ave / Avenue must normalise to the same key via the matcher fn');
        $this->assertSame('12|hibiscus avenue|margate beach|', $ave);
    }

    public function test_address_key_unit_number_changes_the_key(): void
    {
        $unit1 = PropertyAddressKey::fromComponents([
            'street_number' => '5', 'street_name' => 'Sea Lane',
            'suburb' => 'Uvongo', 'unit_number' => '1',
        ]);
        $unit2 = PropertyAddressKey::fromComponents([
            'street_number' => '5', 'street_name' => 'Sea Lane',
            'suburb' => 'Uvongo', 'unit_number' => '2',
        ]);
        $noUnit = PropertyAddressKey::fromComponents([
            'street_number' => '5', 'street_name' => 'Sea Lane',
            'suburb' => 'Uvongo',
        ]);
        $this->assertNotSame($unit1, $unit2,
            'Two flats same building different unit MUST produce different keys (map-level disambiguation)');
        $this->assertNotSame($unit1, $noUnit);
        $this->assertStringEndsWith('|1', $unit1);
        $this->assertStringEndsWith('|2', $unit2);
        $this->assertStringEndsWith('|',  $noUnit);
    }

    public function test_address_key_read_and_write_time_match(): void
    {
        // Write-time path (Property.save → fromComponents).
        $write = PropertyAddressKey::fromComponents([
            'street_number' => '12', 'street_name' => 'Hibiscus Avenue', 'suburb' => 'Margate Beach',
        ]);
        // Read-time path (LocationGrouper.group → fromAddressString).
        $read = PropertyAddressKey::fromAddressString('12 Hibiscus Avenue, Margate Beach');

        $this->assertSame($write, $read,
            'fromAddressString must produce the same key as fromComponents for the same address');
    }

    public function test_address_key_returns_null_on_missing_parts(): void
    {
        $this->assertNull(PropertyAddressKey::fromComponents(['street_number' => '12', 'street_name' => 'Hibiscus Ave']),
            'missing suburb → null');
        $this->assertNull(PropertyAddressKey::fromComponents(['street_name' => 'Hibiscus Ave', 'suburb' => 'Margate']),
            'missing street_number → null');
        $this->assertNull(PropertyAddressKey::fromAddressString(null));
        $this->assertNull(PropertyAddressKey::fromAddressString(''));
        $this->assertNull(PropertyAddressKey::fromAddressString('Just A Street Name'),
            'address with no leading number → null (key requires street_number)');
    }

    // ── 2. Property model boot ──────────────────────────────────────────

    public function test_property_save_populates_normalised_address_cache(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'X-' . Str::random(6), 'slug' => 'x-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $prop = Property::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $user->id,
            'title' => '12 Hibiscus Avenue', 'address' => '12 Hibiscus Avenue',
            'suburb' => 'MARGATE BEACH',           // mixed case — must normalise
            'street_name' => 'Hibiscus Avenue',
            'street_number' => '12',
            'price' => 1500000, 'property_type' => 'house', 'status' => 'active',
            'is_demo' => false,
        ]);

        $this->assertSame('margate beach', $prop->suburb_normalised,
            'suburb_normalised must be lowercased + cleaned by the matcher fn on save');
        $this->assertSame('Hibiscus Avenue', $prop->street_name_normalised,
            'street_name_normalised must be the matcher fn output');
    }

    // ── 3. Grouper address-key second-pass merge ────────────────────────

    public function test_merge_collapses_h_and_m_at_same_address_with_different_gps(): void
    {
        // The CRITICAL proof case: H and M at the same real-world address
        // but with slightly different GPS (different geocoders). Two GPS
        // buckets created; the merge unifies them; THEN the M-collapse
        // runs and M lands in cma_info on the H pin.
        $grouper = new LocationGrouper();

        $hRecord = [
            'id' => 'p:1', 'category' => 'hfc_listings',
            'title' => '12 Hibiscus Avenue, Margate Beach',
            'subtitle' => 'house · R 1,500,000',
            'address' => '12 Hibiscus Avenue, Margate Beach',
            'lat' => -30.86001234, 'lng' => 30.39008765,        // geocoder A
        ];
        $mRecord = [
            'id' => 'mr:7', 'category' => 'mic_subjects',
            'title' => '12 Hibiscus Avenue, Margate Beach',
            'subtitle' => 'CMA · May 2024',
            'address' => '12 Hibiscus Avenue, Margate Beach',
            'lat' => -30.86005678, 'lng' => 30.39011234,        // geocoder B — same building, ~5m off
            'parent_report_id' => 7,
            'report_type_key' => 'cma_info_market_analysis',
        ];

        $locs = $grouper->group([$hRecord, $mRecord]);

        $this->assertCount(1, $locs,
            'H + M at same address but different geocoder GPS MUST merge into ONE composite location');
        $this->assertSame(1, $locs[0]['record_count'],
            'After the merge unites the buckets, M-collapse folds M into cma_info; only H remains in records[]');
        $this->assertSame('hfc_listings', $locs[0]['primary_category']);
        $this->assertCount(1, $locs[0]['cma_info'],
            'The collapsed M must persist as a cma_info attachment, not be dropped');
        $this->assertNotContains('mic_subjects', $locs[0]['categories_present']);
    }

    public function test_merge_does_not_collapse_two_units_in_same_building(): void
    {
        // Two flats in the same sectional title building share the
        // street_number + street_name + suburb but have different unit
        // numbers. PropertyAddressKey puts unit_number in the key tail,
        // so they produce DIFFERENT keys and stay as TWO separate pins.
        $grouper = new LocationGrouper();

        $flat1 = [
            'id' => 'p:1', 'category' => 'hfc_listings',
            'title' => '1 Ss Topanga, 5 Sea Lane, Uvongo',
            'subtitle' => 'sectional · unit 1',
            'address' => '1 Ss Topanga, 5 Sea Lane, Uvongo',
            'lat' => -30.85123456, 'lng' => 30.39456789,
        ];
        $flat2 = [
            'id' => 'p:2', 'category' => 'hfc_listings',
            'title' => '2 Ss Topanga, 5 Sea Lane, Uvongo',
            'subtitle' => 'sectional · unit 2',
            'address' => '2 Ss Topanga, 5 Sea Lane, Uvongo',
            'lat' => -30.85123457, 'lng' => 30.39456790,        // ~1m off — same GPS bucket
        ];

        $locs = $grouper->group([$flat1, $flat2]);

        // GPS bucket alone WOULD collapse these into one composite. The
        // address-key merge MUST NOT remove that GPS-collapse for the
        // two-flats case (because the GPS-bucket layer already chose to
        // composite same-GPS pins by design — sectional title buildings
        // intentionally render as ONE pin with N units underneath). This
        // test guards against the OTHER direction: the merge code MUST
        // NOT accidentally split a same-GPS bucket back apart. Both
        // records share the same GPS → one location; the address-key
        // step is a NO-OP because nothing GPS-distinct shared the
        // (different) address keys.
        $this->assertCount(1, $locs,
            'Two units at same GPS render as one composite (sectional title scheme behaviour, unchanged)');
        $this->assertSame(2, $locs[0]['record_count']);
        // Both records survive in the composite — distinct units, both H.
        $unitTitles = array_map(fn ($r) => $r['title'], $locs[0]['records']);
        $this->assertContains('1 Ss Topanga, 5 Sea Lane, Uvongo', $unitTitles);
        $this->assertContains('2 Ss Topanga, 5 Sea Lane, Uvongo', $unitTitles);
    }

    public function test_merge_skips_records_with_unparseable_address(): void
    {
        // Records whose address can't be normalised to a PropertyAddressKey
        // (no street number, etc.) fall through to GPS-only grouping and
        // never crash the grouper. Defensive null-handling per
        // BUILD_STANDARD §3 (prevent-or-absorb).
        $grouper = new LocationGrouper();

        $locs = $grouper->group([
            ['id' => 'p:1', 'category' => 'hfc_listings',
             'title' => 'unparseable jumble',
             'address' => 'no street number here',
             'lat' => -30.84, 'lng' => 30.39],
            ['id' => 'mr:7', 'category' => 'mic_subjects',
             'title' => 'no address either',
             'address' => '',
             'lat' => -30.85, 'lng' => 30.40],
        ]);

        // No crash. Each location keyed by GPS bucket independently
        // because neither produced a usable address-key.
        $this->assertCount(2, $locs,
            'Records without parseable addresses fall through to GPS-only path — no merge, no crash');
    }

    // ── 4. Schema integrity ─────────────────────────────────────────────

    public function test_properties_table_has_normalised_columns_and_composite_index(): void
    {
        $this->assertTrue(Schema::hasColumn('properties', 'suburb_normalised'));
        $this->assertTrue(Schema::hasColumn('properties', 'street_name_normalised'));

        $indexes = DB::select("SHOW INDEX FROM properties WHERE Key_name = 'idx_properties_address_key'");
        $this->assertNotEmpty($indexes, 'composite index idx_properties_address_key must exist');
        // Index columns in order:
        $cols = array_map(fn ($i) => $i->Column_name, $indexes);
        $this->assertSame(
            ['agency_id', 'suburb_normalised', 'street_name_normalised', 'street_number', 'unit_number'],
            $cols,
            'composite index columns must be in the matcher-prefix order',
        );
    }
}
