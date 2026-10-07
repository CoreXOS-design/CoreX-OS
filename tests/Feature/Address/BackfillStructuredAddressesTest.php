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
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use App\Services\Address\AddressStructurer;
use App\Services\Address\SuburbResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structured address matching, step 7 — `address:backfill-structured`. Dry run first (counts, nothing
 * written); the real run fills the structured layer from text already held, fills ONLY empty columns,
 * overwrites nothing, skips admin-settled rows, flags the "several addresses in one record" pattern, and
 * refuses to run outside QA / local / testing.
 */
final class BackfillStructuredAddressesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private P24Suburb $uvongo;

    protected function setUp(): void
    {
        parent::setUp();
        SuburbResolver::flush();
        AddressStructurer::flushColumnCache();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $c = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $p = P24Province::create(['p24_id' => 2, 'p24_country_id' => $c->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 10, 'p24_province_id' => $p->id, 'name' => 'Margate']);
        $this->uvongo = P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo']);
    }

    /** A row as it was BEFORE the structured layer existed: the writer's output wiped back to null. */
    private function legacyProperty(array $over): Property
    {
        $p = Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1500000, 'title' => 'T', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));
        DB::table('properties')->where('id', $p->id)->update(['street_core' => null, 'street_type' => null, 'address_parse_status' => null, 'address_parse_note' => null,
            'address_raw' => null, 'p24_suburb_id' => null, 'street_number' => $over['street_number'] ?? null]);

        return $p->fresh();
    }

    private function legacyTracked(array $over): TrackedProperty
    {
        $t = TrackedProperty::create(array_merge(['agency_id' => $this->agency->id, 'source_chain' => []], $over));
        DB::table('tracked_properties')->where('id', $t->id)->update(['street_core' => null, 'street_type' => null, 'address_parse_status' => null,
            'address_parse_note' => null, 'address_raw' => null, 'p24_suburb_id' => null, 'p24_city_id' => null, 'lpi_code' => null, 'township' => null,
            'erf_portion' => null, 'street_number' => $over['street_number'] ?? null, 'erf_number' => $over['erf_number'] ?? null]);

        return $t->fresh();
    }

    private function row(string $table, int $id): object
    {
        return DB::table($table)->where('id', $id)->first();
    }

    public function test_a_dry_run_writes_nothing_and_reports_counts(): void
    {
        $p = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);

        $this->artisan('address:backfill-structured', ['--dry-run' => true, '--model' => 'properties'])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $r = $this->row('properties', $p->id);
        $this->assertNull($r->street_core);
        $this->assertNull($r->address_parse_status);
        $this->assertNull($r->street_number);
    }

    public function test_the_real_run_fills_the_layer_and_only_empty_columns(): void
    {
        $lifted = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);
        $kept = $this->legacyProperty(['street_name' => 'Grindewald', 'street_number' => '29']);

        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();

        $a = $this->row('properties', $lifted->id);
        $this->assertSame('grindewald', $a->street_core);
        $this->assertSame('drive', $a->street_type);
        $this->assertSame('19', $a->street_number, 'an EMPTY number is lifted out of the name');
        $this->assertSame('19 Grindewald Drive', $a->street_name, 'the raw street_name is never rewritten');
        $this->assertSame($this->uvongo->id, (int) $a->p24_suburb_id);
        $this->assertSame('parsed', $a->address_parse_status);
        $this->assertSame('19 Grindewald Drive', $a->address_raw);

        $b = $this->row('properties', $kept->id);
        $this->assertSame('29', $b->street_number, 'a number already held is never touched');
    }

    public function test_a_form_chosen_p24_suburb_is_never_overwritten(): void
    {
        $other = P24Suburb::create(['p24_id' => 777, 'p24_city_id' => $this->uvongo->p24_city_id, 'name' => 'Margate', 'slug' => 'margate']);
        $p = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);
        DB::table('properties')->where('id', $p->id)->update(['p24_suburb_id' => $other->id]);

        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();

        $this->assertSame($other->id, (int) $this->row('properties', $p->id)->p24_suburb_id);
    }

    public function test_tracked_rows_get_the_lpi_from_their_external_ref_and_the_373_pollution_is_cleaned(): void
    {
        $t = $this->legacyTracked(['street_name' => '4 Garden Place   Cadastral Extent  1 605 M²', 'suburb' => 'Uvongo', 'erf_number' => null]);
        TrackedPropertyExternalRef::create(['agency_id' => $this->agency->id, 'tracked_property_id' => $t->id, 'source_type' => 'deeds_capture',
            'source_ref' => 'cmainfo:n0et03630000132900000', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('address:backfill-structured', ['--model' => 'tracked'])->assertSuccessful();

        $r = $this->row('tracked_properties', $t->id);
        $this->assertSame('n0et03630000132900000', $r->lpi_code);
        $this->assertSame('1329', $r->erf_number, 'an EMPTY erf is read from the LPI');
        $this->assertSame('0', $r->erf_portion);
        $this->assertSame('0363', $r->township);
        $this->assertSame('4', $r->street_number);
        $this->assertSame('garden', $r->street_core);
        $this->assertSame('4 Garden Place   Cadastral Extent  1 605 M²', $r->street_name, 'raw text kept as it was');
        $this->assertSame($this->uvongo->id, (int) $r->p24_suburb_id);
    }

    public function test_a_record_whose_history_names_several_addresses_is_flagged_review_and_nothing_is_deleted(): void
    {
        $t = $this->legacyTracked(['street_number' => '1', 'street_name' => 'Como Drive', 'suburb' => 'Uvongo']);
        foreach ([['1', 'Como Drive'], ['4', 'Garden Place'], ['3', 'Meriel Road'], ['19', 'Grindewald Drive']] as [$n, $s]) {
            TrackedPropertyAddress::create(['agency_id' => $this->agency->id, 'tracked_property_id' => $t->id, 'street_number' => $n, 'street_name' => $s,
                'suburb' => 'Uvongo', 'source_type' => 'cmainfo', 'confidence' => 'high', 'is_primary' => false]);
        }
        $before = DB::table('tracked_property_addresses')->where('tracked_property_id', $t->id)->count();

        $this->artisan('address:backfill-structured', ['--model' => 'tracked'])->assertSuccessful();

        $r = $this->row('tracked_properties', $t->id);
        $this->assertSame('review', $r->address_parse_status);
        $this->assertStringContainsString('4 different addresses', (string) $r->address_parse_note);
        $this->assertSame($before, DB::table('tracked_property_addresses')->where('tracked_property_id', $t->id)->count(), 'never split, never deleted');
        $this->assertNull(DB::table('tracked_properties')->where('id', $t->id)->value('deleted_at'));
    }

    public function test_admin_settled_rows_are_skipped(): void
    {
        $p = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);
        DB::table('properties')->where('id', $p->id)->update(['address_parse_status' => 'manual']);
        $q = $this->legacyProperty(['street_name' => '29 Grindewald Drive']);
        DB::table('properties')->where('id', $q->id)->update(['address_parse_status' => 'dismissed']);

        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();

        $this->assertNull($this->row('properties', $p->id)->street_core);
        $this->assertNull($this->row('properties', $q->id)->street_core);
    }

    public function test_rerunning_changes_nothing_more_and_a_resume_point_is_honoured(): void
    {
        $a = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);
        $b = $this->legacyProperty(['street_name' => '29 Grindewald Drive']);

        $this->artisan('address:backfill-structured', ['--model' => 'properties', '--after' => $a->id])->assertSuccessful();
        $this->assertNull($this->row('properties', $a->id)->street_core, 'rows at or before --after are not touched');
        $this->assertSame('grindewald', $this->row('properties', $b->id)->street_core);

        $snapshot = (array) $this->row('properties', $b->id);
        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();
        $this->assertSame('grindewald', $this->row('properties', $a->id)->street_core);
        $this->assertEquals($snapshot, (array) $this->row('properties', $b->id), 'a second run is a no-op for an already-structured row');
    }

    public function test_an_unreadable_row_is_marked_unparseable_not_skipped_or_deleted(): void
    {
        $p = $this->legacyProperty(['street_name' => '1575', 'suburb' => 'Leisure Bay']);
        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();
        $this->assertSame('unparseable', $this->row('properties', $p->id)->address_parse_status);
    }

    public function test_a_suburb_only_row_has_nothing_to_read_so_it_never_reaches_the_review_list(): void
    {
        // The 32,000 Property24 / PrivateProperty captures on QA1 carry a suburb and nothing else.
        $p = $this->legacyProperty(['street_name' => null, 'suburb' => 'Durban North']);
        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();
        $this->assertNull($this->row('properties', $p->id)->address_parse_status);
        $this->assertSame('durban north', mb_strtolower((string) $this->row('properties', $p->id)->suburb));
    }

    public function test_an_unknown_suburb_is_review_and_the_suburb_text_is_untouched(): void
    {
        $p = $this->legacyProperty(['street_name' => '12 Marine Drive', 'suburb' => 'Nowhere Extension 9']);
        $this->artisan('address:backfill-structured', ['--model' => 'properties'])->assertSuccessful();
        $r = $this->row('properties', $p->id);
        $this->assertSame('review', $r->address_parse_status);
        $this->assertSame('Nowhere Extension 9', $r->suburb);
        $this->assertNull($r->p24_suburb_id);
    }

    public function test_one_agency_flag_limits_the_run(): void
    {
        $mine = $this->legacyProperty(['street_name' => '19 Grindewald Drive']);
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $theirs = Property::create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo', 'property_type' => 'house', 'beds' => 1, 'baths' => 1, 'garages' => 0, 'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale']);
        DB::table('properties')->where('id', $theirs->id)->update(['street_core' => null, 'address_parse_status' => null]);

        $this->artisan('address:backfill-structured', ['--model' => 'properties', '--agency' => $this->agency->id])->assertSuccessful();

        $this->assertSame('grindewald', $this->row('properties', $mine->id)->street_core);
        $this->assertNull($this->row('properties', $theirs->id)->street_core);
    }

    public function test_it_refuses_to_run_on_production_or_staging(): void
    {
        foreach (['production', 'staging'] as $env) {
            $this->app['env'] = $env;
            $this->artisan('address:backfill-structured', ['--dry-run' => true])->assertFailed();
        }
        $this->app['env'] = 'testing';
    }

    public function test_a_bad_model_option_is_refused_cleanly(): void
    {
        $this->artisan('address:backfill-structured', ['--model' => 'bogus'])->assertFailed();
    }
}
