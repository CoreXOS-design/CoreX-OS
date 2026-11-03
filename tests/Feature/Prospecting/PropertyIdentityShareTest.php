<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PortalAdvertContribution;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\PropertyIdentityShareReport;
use App\Services\Prospecting\SharedPortalPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §14 (A5): who contributed an advert is recorded INTERNALLY and is agency-scoped at the
 * query layer; the shared tables carry no agency; the dry-run report counts what sharing would do and writes nothing.
 */
final class PropertyIdentityShareTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $brA = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $brB = Branch::create(['agency_id' => $this->b->id, 'name' => 'Main']);
        $this->userA = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $brA->id, 'role' => 'admin']);
        $this->userB = User::factory()->create(['agency_id' => $this->b->id, 'branch_id' => $brB->id, 'role' => 'admin']);
    }

    private function resolve(Agency $agency, string $ref, array $facts = []): void
    {
        app(PropertyIdentityResolver::class)->resolve($agency->id, $facts, ['type' => 'p24', 'ref' => $ref]);
    }

    private function tpCount(): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->count();
    }

    // ---- the contribution record -----------------------------------------------------------------------------------

    public function test_each_agency_that_sees_an_advert_is_recorded_internally_with_the_fields_it_supplied(): void
    {
        $this->resolve($this->a, 'P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'bedrooms' => 3]);
        $this->resolve($this->b, 'P24-116123456', ['last_known_asking_price' => 1500000]);
        $this->resolve($this->a, 'P24-116123456', ['bathrooms' => 2]);

        $this->assertSame(1, DB::table('portal_advert_facts')->count(), 'one shared row for the advert');
        $rows = DB::table('portal_advert_contributions')->orderBy('agency_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $rows[0]->seen_count);
        $this->assertContains('street_name', json_decode($rows[0]->fields_contributed, true));
        $this->assertContains('bathrooms', json_decode($rows[0]->fields_contributed, true));
        $this->assertSame(['price'], array_values(array_intersect(['price', 'street_name'], json_decode($rows[1]->fields_contributed, true))));
    }

    public function test_an_agency_can_only_ever_read_its_own_contributions(): void
    {
        $this->resolve($this->a, 'P24-116123456', ['street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $this->resolve($this->b, 'P24-116123456', ['bedrooms' => 3]);

        Auth::login($this->userB);
        $seen = PortalAdvertContribution::query()->get();
        $this->assertCount(1, $seen);
        $this->assertSame($this->b->id, (int) $seen[0]->agency_id);
        $this->assertNull(PortalAdvertContribution::query()->where('agency_id', $this->a->id)->first(), 'agency A\'s contribution is invisible to B at the query layer');

        Auth::login($this->userA);
        $this->assertSame([$this->a->id], PortalAdvertContribution::query()->pluck('agency_id')->all());
    }

    public function test_a_pool_read_never_reveals_who_contributed(): void
    {
        $this->resolve($this->a, 'P24-116123456', ['street_name' => 'Beach Road', 'suburb' => 'Uvongo']);

        $found = app(SharedPortalPool::class)->find('p24:116123456');
        $json = json_encode($found);
        $this->assertStringNotContainsString('agency', strtolower($json));
        $this->assertArrayNotHasKey('contributions', $found);
        foreach (['portal_advert_facts', 'shared_properties'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'agency_id'), $table . ' is shared: it has no agency column');
        }
    }

    public function test_the_second_agency_sees_the_first_agencys_public_facts_on_its_own_new_file_but_nothing_private(): void
    {
        $this->resolve($this->a, 'P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'erf_number' => '777', 'title_deed_number' => 'T1/2020']);
        $before = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->first()->getAttributes();

        $this->resolve($this->b, 'P24-116123456');
        $bFile = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->b->id)->first();

        $this->assertSame('Beach Road', $bFile->street_name);
        $this->assertNull($bFile->erf_number);
        $this->assertNull($bFile->title_deed_number);
        $this->assertSame($before, TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->first()->getAttributes(), 'agency A\'s file is unchanged');
    }

    // ---- the dry-run report ---------------------------------------------------------------------------------------------

    private function seedForReport(): void
    {
        // same advert, two agencies, one file each -> one shared property spanning agencies
        $this->resolve($this->a, 'P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        $this->resolve($this->b, 'P24-116123456', ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
        // a plain single-agency file
        $this->resolve($this->a, 'P24-116222222', ['street_number' => '40', 'street_name' => 'Hill Street', 'suburb' => 'Uvongo']);
        // one agency, two files joined by one advert (the 4,714 situation)
        $x = app(PropertyIdentityResolver::class)->resolve($this->a->id, ['street_number' => '5', 'street_name' => 'Alpha Road', 'suburb' => 'Margate'], ['type' => 'manual', 'ref' => 'M-1'])->trackedProperty;
        $y = app(PropertyIdentityResolver::class)->resolve($this->a->id, ['street_number' => '70', 'street_name' => 'Gamma Road', 'suburb' => 'Ramsgate'], ['type' => 'manual', 'ref' => 'M-2'])->trackedProperty;
        foreach ([$x, $y] as $tp) {
            DB::table('tracked_property_external_refs')->insert([
                'agency_id' => $this->a->id, 'tracked_property_id' => $tp->id, 'source_type' => 'p24', 'source_ref' => 'P24-' . Str::random(8), 'canonical_ref' => 'p24:333333333',
                'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_the_report_counts_adverts_shared_properties_and_contributions(): void
    {
        $this->seedForReport();

        $r = app(PropertyIdentityShareReport::class)->run();

        $this->assertSame(3, $r['adverts']['distinct']);                    // 116123456, 116222222, 333333333
        $this->assertSame(2, $r['adverts']['already_in_pool']);             // the two real scrapes wrote the pool; the forged 333… ref did not
        $this->assertSame(1, $r['adverts']['would_be_created']);
        $this->assertSame(1, $r['adverts']['seen_by_several_agencies']);
        $this->assertSame(4, $r['adverts']['contribution_records']);        // (116123456,A) (116123456,B) (116222222,A) (333333333,A)
        $this->assertSame(3, $r['shared_properties']['total']);
        $this->assertSame(1, $r['shared_properties']['span_agencies']);
        $this->assertSame(1, $r['shared_properties']['single_agency_single_file']);
        $this->assertSame(1, $r['shared_properties']['single_agency_several_files']);
        $this->assertSame(0, $r['shared_properties']['blocked_by_over_merge']);
        $this->assertSame(5, $r['shared_properties']['agency_files_that_would_link']);
    }

    public function test_a_group_that_holds_an_over_merged_file_is_blocked_until_it_is_split(): void
    {
        $block = app(PropertyIdentityResolver::class)->resolve($this->a->id, ['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga'], ['type' => 'p24', 'ref' => 'P24-100000000'])->trackedProperty;
        foreach ([303, 304, 305, 306, 307, 308] as $i => $unit) {
            DB::table('prospecting_listings')->insert([
                'agency_id' => $this->a->id, 'captured_by_user_id' => $this->userA->id, 'tracked_property_id' => $block->id, 'suburb' => 'Umhlanga',
                'portal_source' => 'p24', 'portal_ref' => 'P24-1000011' . $i, 'canonical_ref' => 'p24:1000011' . $i, 'address' => $unit . ' / Pebble Beach, 10 Heleza Boulevard',
                'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $r = app(PropertyIdentityShareReport::class)->run();

        $this->assertSame(1, $r['shared_properties']['blocked_by_over_merge']);
        $this->assertSame(0, $r['shared_properties']['agency_files_that_would_link']);
    }

    public function test_the_privacy_self_check_finds_no_private_column_on_the_shared_side(): void
    {
        $r = app(PropertyIdentityShareReport::class)->run();

        $this->assertSame([], $r['privacy']['private_columns_among_them']);
        $this->assertFalse($r['privacy']['shared_tables_have_agency_column']);
        foreach (['erf_number', 'title_deed_number', 'lpi_code', 'owner_contact_id', 'bond_holder', 'agency_id'] as $private) {
            $this->assertNotContains($private, $r['privacy']['columns_that_would_be_written']);
        }
    }

    public function test_the_report_never_writes(): void
    {
        $this->seedForReport();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PropertyIdentityShareReport::class)->run();
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $q))->values();
        DB::disableQueryLog();

        $this->assertSame([], $writes->all());
        $this->assertSame(0, DB::table('shared_properties')->count());
        $this->assertSame(0, DB::table('tracked_properties')->whereNotNull('shared_property_id')->count());
    }

    public function test_the_command_writes_a_report_and_refuses_apply_and_production(): void
    {
        $this->seedForReport();
        $dir = storage_path('app/reports');

        $this->assertSame(0, Artisan::call('property-identity:share', ['--dry-run' => true]));
        $this->assertStringContainsString('Shared properties that would exist', Artisan::output());
        $this->assertNotEmpty(glob($dir . '/property-identity-share-dry-run-*.md'));

        $this->assertSame(1, Artisan::call('property-identity:share', ['--apply' => true]));
        $this->assertStringContainsString('no --apply', Artisan::output());

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('property-identity:share', ['--dry-run' => true]));

        foreach (glob($dir . '/property-identity-share-dry-run-*') ?: [] as $f) {
            @unlink($f);
        }
    }
}
