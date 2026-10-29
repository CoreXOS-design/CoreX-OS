<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\PropertyIdentityReconciler;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §8 (A4): the dry-run reconcile report. Read-only, and right about the three
 * questions: our stock joining property files, the same advert held twice, over-merged property files.
 */
final class PropertyIdentityReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function door(array $facts, string $ref, string $type = 'p24'): \App\Models\Prospecting\TrackedProperty
    {
        return app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, $facts, ['type' => $type, 'ref' => $ref]);
    }

    /** a property saved with the identity hook off, i.e. exactly the shape of the 8,051 unlinked ones */
    private function property(array $attrs = []): int
    {
        $p = new Property();
        foreach (array_merge([
            'external_id' => 'P-' . Str::random(8), 'title' => 'T', 'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'house',
            'beds' => 3, 'status' => 'active', 'is_demo' => false, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id' => $this->agent->id,
        ], $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return (int) $p->id;
    }

    private function mic(int $tp, string $ref, ?string $address): void
    {
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->agent->id, 'tracked_property_id' => $tp, 'suburb' => 'Umhlanga',
            'portal_source' => 'p24', 'portal_ref' => $ref, 'canonical_ref' => 'p24:' . substr($ref, 4), 'address' => $address,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_own_stock_report_counts_joins_possibles_new_files_and_what_is_already_linked(): void
    {
        $existing = $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'property_type' => 'house'], 'P24-116123456');
        $this->door(['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga', 'property_type' => 'apartment'], 'P24-116000001');

        $joins = $this->property(['address' => '12 Beach Road']);                       // same house  -> would join
        $viaRef = $this->property(['address' => 'Somewhere else 99', 'p24_ref' => '116123456']); // same advert -> would join (exact)
        $possible = $this->property(['address' => '10 Heleza Boulevard', 'unit_number' => '303', 'property_type' => 'apartment', 'suburb' => 'Umhlanga']);
        $fresh = $this->property(['address' => '77 Nowhere Lane', 'suburb' => 'Margate']);
        $linked = $this->property(['address' => '5 Linked Road']);
        DB::table('properties')->where('id', $linked)->update(['tracked_property_id' => $existing->id]);
        $this->property([]);                                                              // no usable address

        $r = app(PropertyIdentityReconciler::class)->run($this->agency->id)['own_stock'];

        $this->assertSame(6, $r['properties']);
        $this->assertSame(1, $r['already_on_spine']);
        $this->assertSame(1, $r['no_usable_address']);
        $this->assertSame(2, $r['would_join_total']);
        $this->assertArrayHasKey('exact / portal_ref', $r['would_join']);
        $this->assertSame(1, $r['possible']);
        $this->assertSame(1, $r['would_need_new_file']);
        $this->assertSame($possible, $r['samples']['possible'][0]['property']);
        $this->assertSame($fresh, $r['samples']['new'][0]['property']);
    }

    public function test_the_same_advert_on_two_files_is_split_into_same_address_and_different_address(): void
    {
        $a = $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], 'P24-116000001');
        $b = $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], '116000001', 'property24'); // links to $a by canonical
        $this->assertSame($a->id, $b->id);

        // forge the clashes the old per-spelling matcher left behind
        [$s1, $s2] = [$this->door(['street_number' => '5', 'street_name' => 'Alpha Road', 'suburb' => 'Margate'], 'M-1', 'manual'), null];
        $s2 = TrackedPropertyForgery::copy($s1);                                          // a second file at the same address
        [$d1, $d2] = [$this->door(['street_number' => '7', 'street_name' => 'Beta Road', 'suburb' => 'Margate'], 'M-2', 'manual'), $this->door(['street_number' => '70', 'street_name' => 'Gamma Road', 'suburb' => 'Ramsgate'], 'M-3', 'manual')];
        foreach ([[$s1, 'p24:111111111'], [$s2, 'p24:111111111'], [$d1, 'p24:222222222'], [$d2, 'p24:222222222']] as [$tp, $canon]) {
            DB::table('tracked_property_external_refs')->insert([
                'agency_id' => $this->agency->id, 'tracked_property_id' => $tp->id, 'source_type' => 'p24', 'source_ref' => 'P24-' . Str::random(8),
                'canonical_ref' => $canon, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $r = app(PropertyIdentityReconciler::class)->run($this->agency->id)['ref_clashes'];

        $this->assertSame(2, $r['groups']);
        $this->assertSame(1, $r['same_address']);
        $this->assertSame(1, $r['different_address']);
        $this->assertSame('p24:222222222', $r['samples']['different_address'][0]['advert']);
    }

    public function test_duplicate_own_stock_by_advert_and_by_address(): void
    {
        $this->property(['address' => '1 Same Road', 'street_number' => '1', 'street_name' => 'Same Road', 'p24_ref' => '116123456']);
        $this->property(['address' => '1 Same Road', 'street_number' => '1', 'street_name' => 'Same Road', 'p24_ref' => '116123456']);
        $this->property(['address' => '2 Other Road', 'street_number' => '2', 'street_name' => 'Other Road', 'p24_ref' => '116999999']);

        $d = app(PropertyIdentityReconciler::class)->run($this->agency->id)['own_duplicates'];

        $this->assertSame(1, $d['same_advert']['p24_canonical_ref']['groups']);
        $this->assertSame(2, $d['same_advert']['p24_canonical_ref']['rows']);
        $this->assertSame(1, $d['same_address']['groups']);
        $this->assertSame(2, $d['same_address']['rows']);
    }

    public function test_an_over_merged_file_is_found_and_a_relist_history_is_not(): void
    {
        $block = $this->door(['street_number' => '10', 'street_name' => 'Heleza Boulevard', 'suburb' => 'Umhlanga'], 'P24-100000000');
        foreach ([303, 304, 305, 306, 307, 308] as $i => $unit) {
            $this->mic($block->id, 'P24-1000010' . $i, $unit . ' / Pebble Beach, 10 Heleza Boulevard');
        }
        $house = $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], 'P24-100000099');
        foreach ([0, 1, 2, 3, 4, 5] as $i) {
            $this->mic($house->id, 'P24-1000020' . $i, '12 Beach Road');
        }

        $m = app(PropertyIdentityReconciler::class)->run($this->agency->id)['over_merged'];

        $this->assertSame(2, $m['files_with_many_adverts']);
        $this->assertSame(1, $m['over_merged']);
        $this->assertSame(1, $m['looks_like_relist_history']);
        $this->assertSame($block->id, $m['samples'][0]['file']);
        $this->assertGreaterThanOrEqual(5, $m['samples'][0]['distinct_units']);
        $this->assertGreaterThanOrEqual(5, $m['proposed_new_files']);
    }

    public function test_the_report_never_writes(): void
    {
        $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], 'P24-116123456');
        $this->property(['address' => '12 Beach Road', 'p24_ref' => '116123456']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PropertyIdentityReconciler::class)->run($this->agency->id);
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $q))->values();
        DB::disableQueryLog();

        $this->assertSame([], $writes->all(), 'the reconcile report must not write');
    }

    public function test_the_command_writes_a_report_refuses_apply_and_refuses_production(): void
    {
        $this->door(['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], 'P24-116123456');
        $dir = storage_path('app/reports');

        $this->assertSame(0, Artisan::call('property-identity:reconcile', ['--dry-run' => true, '--agency' => $this->agency->id]));
        $this->assertStringContainsString('Our own properties', Artisan::output());
        $this->assertNotEmpty(glob($dir . '/property-identity-reconcile-dry-run-*.md'));
        $this->assertNotEmpty(glob($dir . '/property-identity-reconcile-dry-run-*.json'));

        $this->assertSame(1, Artisan::call('property-identity:reconcile', ['--apply' => true]));
        $this->assertStringContainsString('no --apply', Artisan::output());

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('property-identity:reconcile', ['--dry-run' => true]));

        foreach (glob($dir . '/property-identity-reconcile-dry-run-*') ?: [] as $f) {
            @unlink($f);
        }
    }
}

/** test helper: a second property file with the same address columns, as the old matcher used to leave behind */
final class TrackedPropertyForgery
{
    public static function copy(\App\Models\Prospecting\TrackedProperty $tp): \App\Models\Prospecting\TrackedProperty
    {
        $attrs = $tp->only(['agency_id', 'street_number', 'street_name', 'suburb', 'suburb_normalised', 'street_core', 'p24_suburb_id', 'status']);
        $attrs['external_id'] = 'TP-' . Str::random(10);

        return \App\Models\Prospecting\TrackedProperty::create($attrs);
    }
}
