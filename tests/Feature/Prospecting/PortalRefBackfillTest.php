<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\P24Listing;
use App\Models\PortalListing;
use App\Models\Property;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use App\Services\Prospecting\PortalRefBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property identity A2 (.ai/specs/property-identity.md §4, §8): the canonical advert reference is kept in
 * step by the models, and `property-refs:normalise` fills it for existing rows — dry run writes nothing,
 * apply is idempotent, the raw columns are never touched, and the report finds the cross-source matches
 * and the same-advert-on-two-files clashes.
 */
final class PortalRefBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $otherAgencyId;
    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = $this->agency('A');
        $this->otherAgencyId = $this->agency('B');
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->agentId = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent'])->id;
    }

    private function agency(string $tag): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . $tag . Str::random(4), 'slug' => strtolower($tag) . '-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tp(?int $agencyId = null): int
    {
        return (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $agencyId ?? $this->agencyId, 'external_id' => 'TP-' . Str::random(8),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Raw insert: bypasses the model hook, i.e. exactly the shape of a row that existed before this build. */
    private function rawRef(int $tp, string $type, string $ref, ?array $payload = null, ?int $agencyId = null): int
    {
        return (int) DB::table('tracked_property_external_refs')->insertGetId([
            'agency_id' => $agencyId ?? $this->agencyId, 'tracked_property_id' => $tp,
            'source_type' => $type, 'source_ref' => $ref, 'source_payload' => $payload ? json_encode($payload) : null,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rawMic(string $source, string $ref): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $this->agencyId, 'captured_by_user_id' => $this->agentId, 'suburb' => 'Uvongo',
            'portal_source' => $source, 'portal_ref' => $ref, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rawAlert(string $number): int
    {
        return (int) DB::table('p24_listings')->insertGetId([
            'agency_id' => $this->agencyId, 'p24_listing_number' => $number, 'asking_price' => 1000000,
            'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rawCapture(string $site, string $id): int
    {
        return (int) DB::table('portal_listings')->insertGetId([
            'agency_id' => $this->agencyId, 'source_site' => $site, 'portal_listing_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rawProperty(array $attrs): int
    {
        return (int) DB::table('properties')->insertGetId(array_merge([
            'external_id' => 'P-' . Str::random(8), 'title' => 'T', 'agency_id' => $this->agencyId,
            'branch_id' => $this->agencyId, 'agent_id' => $this->agentId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function seedEverySource(): void
    {
        $tpA = $this->tp();
        $tpB = $this->tp();
        $this->rawRef($tpA, 'p24', 'P24-116123456');
        $this->rawRef($tpA, 'pp', 'PP-T1014486');
        $this->rawRef($tpA, 'property24', '116123456');                                 // same advert, other spelling, same file
        $this->rawRef($tpB, 'chrome_capture', '116123456', ['source_site' => 'www.property24.com']); // same advert on ANOTHER file: clash
        $this->rawRef($tpB, 'private_property', 'RR4719094');
        $this->rawRef($tpB, 'cmainfo', 'report:7');                                      // not an advert
        $this->rawRef($tpB, 'p24', 'CC5-LIVE-TEST-1787027314');                          // unparseable
        $this->rawMic('p24', 'P24-116123456');
        $this->rawMic('pp', 'PP-T1014486');
        $this->rawAlert('P24-116123456');
        $this->rawCapture('www.property24.com', '116123456');
        $this->rawProperty(['p24_ref' => '116123456', 'pp_ref' => 'T1014486']);
        $this->rawProperty(['p24_listing_number' => '222333444']);
        $this->rawProperty([]);
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $this->seedEverySource();

        $r = app(PortalRefBackfill::class)->run(false);

        $this->assertSame('dry-run', $r['mode']);
        $this->assertSame(0, DB::table('tracked_property_external_refs')->whereNotNull('canonical_ref')->count());
        $this->assertSame(0, DB::table('prospecting_listings')->whereNotNull('canonical_ref')->count());
        $this->assertSame(0, DB::table('properties')->whereNotNull('p24_canonical_ref')->count());

        $refs = $r['sources']['tracked_property_external_refs'];
        $this->assertSame(7, $refs['rows']);
        $this->assertSame(1, $refs['no_advert_number'], 'the cmainfo ref is not an advert');
        $this->assertSame(1, $refs['unparseable'], 'the lane test marker');
        $this->assertSame(5, $refs['normalised']);
        $this->assertSame(5, $refs['to_change']);
        $this->assertSame(0, $refs['written']);
        $this->assertSame(['CC5-LIVE-TEST-1787027314'], $refs['samples']);
    }

    public function test_apply_fills_every_source_and_never_touches_the_raw_columns(): void
    {
        $this->seedEverySource();

        $r = app(PortalRefBackfill::class)->run(true);

        $this->assertGreaterThan(0, $r['sources']['tracked_property_external_refs']['written']);
        $this->assertEqualsCanonicalizing(
            ['p24:116123456', 'pp:T1014486', 'p24:116123456', 'p24:116123456', 'pp:RR4719094'],
            DB::table('tracked_property_external_refs')->whereNotNull('canonical_ref')->pluck('canonical_ref')->all(),
        );
        $this->assertEqualsCanonicalizing(['P24-116123456', 'PP-T1014486', '116123456', '116123456', 'RR4719094', 'report:7', 'CC5-LIVE-TEST-1787027314'],
            DB::table('tracked_property_external_refs')->pluck('source_ref')->all(), 'raw refs untouched');
        $this->assertEqualsCanonicalizing(['p24:116123456', 'pp:T1014486'], DB::table('prospecting_listings')->pluck('canonical_ref')->all());
        $this->assertSame('p24:116123456', DB::table('p24_listings')->value('canonical_ref'));
        $this->assertSame('p24:116123456', DB::table('portal_listings')->value('canonical_ref'));
        $this->assertSame('P24-116123456', DB::table('p24_listings')->value('p24_listing_number'));
        $this->assertEqualsCanonicalizing(['p24:116123456', 'p24:222333444', null], DB::table('properties')->pluck('p24_canonical_ref')->all());
        $this->assertEqualsCanonicalizing(['pp:T1014486', null, null], DB::table('properties')->pluck('pp_canonical_ref')->all());
    }

    public function test_apply_twice_changes_nothing_the_second_time(): void
    {
        $this->seedEverySource();
        app(PortalRefBackfill::class)->run(true);

        $second = app(PortalRefBackfill::class)->run(true);

        foreach ($second['sources'] as $key => $s) {
            $this->assertSame(0, $s['to_change'], $key);
            $this->assertSame(0, $s['written'], $key);
        }
        $this->assertSame(5, $second['sources']['tracked_property_external_refs']['already_canonical']);
    }

    public function test_report_finds_cross_source_matches_and_clashes(): void
    {
        $this->seedEverySource();

        $c = app(PortalRefBackfill::class)->run(false)['cross'];

        // p24:116123456 is in refs, MIC, alerts, captures, own stock; pp:T1014486 is in refs, MIC, own stock.
        $this->assertSame(2, $c['in_two_or_more_sources']);
        $this->assertSame(2, $c['in_three_or_more']);
        $this->assertSame(1, $c['pair_overlaps']['prospecting_listings + p24_listings'] ?? 0);
        $this->assertSame(1, $c['pair_overlaps']['tracked_property_external_refs + portal_listings'] ?? 0);
        $this->assertSame(1, $c['clashes'], 'p24:116123456 sits on two property files of the same agency');
        $this->assertStringContainsString($this->agencyId . '|p24:116123456', $c['clash_samples'][0]);
    }

    public function test_a_clash_needs_the_same_agency(): void
    {
        $this->rawRef($this->tp(), 'p24', 'P24-116123456');
        $this->rawRef($this->tp($this->otherAgencyId), 'p24', 'P24-116123456', null, $this->otherAgencyId);

        $c = app(PortalRefBackfill::class)->run(false)['cross'];

        $this->assertSame(0, $c['clashes'], 'two agencies each holding the advert is the normal shared-pool case, not a clash');
    }

    public function test_archived_refs_are_normalised_but_never_count_as_a_clash(): void
    {
        $a = $this->rawRef($this->tp(), 'p24', 'P24-116123456');
        $b = $this->rawRef($this->tp(), 'p24', '116123456');
        DB::table('tracked_property_external_refs')->where('id', $b)->update(['deleted_at' => now()]);

        $r = app(PortalRefBackfill::class)->run(true);

        $this->assertSame(0, $r['cross']['clashes']);
        $this->assertSame(1, $r['sources']['tracked_property_external_refs']['archived']);
        $this->assertSame('p24:116123456', DB::table('tracked_property_external_refs')->where('id', $b)->value('canonical_ref'));
    }

    public function test_a_stale_canonical_value_is_recomputed(): void
    {
        $id = $this->rawMic('p24', 'P24-116123456');
        DB::table('prospecting_listings')->where('id', $id)->update(['canonical_ref' => 'p24:999999']);

        $r = app(PortalRefBackfill::class)->run(true, ['prospecting_listings']);

        $this->assertSame(1, $r['sources']['prospecting_listings']['written']);
        $this->assertSame('p24:116123456', DB::table('prospecting_listings')->where('id', $id)->value('canonical_ref'));
    }

    public function test_source_filter_runs_only_the_named_source(): void
    {
        $this->seedEverySource();

        $r = app(PortalRefBackfill::class)->run(true, ['p24_listings']);

        $this->assertSame(['p24_listings'], array_keys($r['sources']));
        $this->assertSame(0, DB::table('prospecting_listings')->whereNotNull('canonical_ref')->count());
        $this->assertSame(1, DB::table('p24_listings')->whereNotNull('canonical_ref')->count());
    }

    // ---- the model hooks keep new rows canonical on write --------------------------------------------------

    public function test_models_derive_the_canonical_reference_on_save(): void
    {
        $tp = $this->tp();
        $ref = TrackedPropertyExternalRef::create([
            'agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'source_type' => 'p24', 'source_ref' => 'P24-116123456',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $cmainfo = TrackedPropertyExternalRef::create([
            'agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'source_type' => 'cmainfo', 'source_ref' => 'report:9',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $capture = TrackedPropertyExternalRef::create([
            'agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'source_type' => 'chrome_capture', 'source_ref' => '123456789',
            'source_payload' => ['source_site' => 'www.property24.com'],
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $captureNoSite = TrackedPropertyExternalRef::create([
            'agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'source_type' => 'chrome_capture', 'source_ref' => '555666777',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->assertSame('p24:116123456', $ref->fresh()->canonical_ref);
        $this->assertNull($cmainfo->fresh()->canonical_ref);
        $this->assertSame('p24:123456789', $capture->fresh()->canonical_ref);
        $this->assertNull($captureNoSite->fresh()->canonical_ref, 'bare digits with no portal are never guessed');
        $this->assertSame('P24-116123456', $ref->fresh()->source_ref, 'raw untouched');

        $mic = ProspectingListing::create([
            'agency_id' => $this->agencyId, 'captured_by_user_id' => $this->agentId, 'suburb' => 'Uvongo',
            'portal_source' => 'pp', 'portal_ref' => 'PP-T1014486', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $this->assertSame('pp:T1014486', $mic->fresh()->canonical_ref);

        $alert = P24Listing::create([
            'agency_id' => $this->agencyId, 'p24_listing_number' => 'P24-117743851', 'asking_price' => 1,
            'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(),
        ]);
        $this->assertSame('p24:117743851', $alert->fresh()->canonical_ref);

        $pl = PortalListing::create(['agency_id' => $this->agencyId, 'source_site' => 'www.property24.com', 'portal_listing_id' => '100352744']);
        $this->assertSame('p24:100352744', $pl->fresh()->canonical_ref);

        $p = new Property();
        foreach (['external_id' => 'P-' . Str::random(6), 'title' => 'T', 'suburb' => 'Uvongo', 'price' => 1, 'property_type' => 'house', 'beds' => 3,
            'status' => 'active', 'is_demo' => false, 'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->agentId,
            'p24_ref' => '103977859', 'pp_ref' => 'RR4719094'] as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();
        $p = $p->fresh();
        $this->assertSame('p24:103977859', $p->p24_canonical_ref);
        $this->assertSame('pp:RR4719094', $p->pp_canonical_ref);

        $p->p24_ref = '103977860';
        $p->save();
        $this->assertSame('p24:103977860', $p->fresh()->p24_canonical_ref, 'follows the raw number when it changes');
    }

    // ---- the command ---------------------------------------------------------------------------------------

    public function test_command_dry_run_writes_a_report_and_apply_fills_the_columns(): void
    {
        $this->seedEverySource();
        $dir = storage_path('app/reports');

        $this->assertSame(0, Artisan::call('property-refs:normalise', ['--dry-run' => true]));
        $this->assertStringContainsString('dry-run', Artisan::output());
        $this->assertSame(0, DB::table('prospecting_listings')->whereNotNull('canonical_ref')->count());
        $this->assertNotEmpty(glob($dir . '/property-refs-normalise-dry-run-*.md'));

        $this->assertSame(0, Artisan::call('property-refs:normalise', ['--apply' => true]));
        $this->assertSame(2, DB::table('prospecting_listings')->whereNotNull('canonical_ref')->count());

        foreach (glob($dir . '/property-refs-normalise-*.md') ?: [] as $f) {
            @unlink($f);
        }
    }

    public function test_command_refuses_outside_qa_local_testing_and_on_unknown_source(): void
    {
        $this->assertSame(1, Artisan::call('property-refs:normalise', ['--source' => ['nope']]));

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('property-refs:normalise', ['--apply' => true]));
        $this->assertStringContainsString('only runs on QA', Artisan::output());
    }

    public function test_the_shared_pool_table_exists_without_an_agency_column(): void
    {
        $cols = DB::getSchemaBuilder()->getColumnListing('portal_advert_facts');

        $this->assertContains('canonical_ref', $cols);
        $this->assertNotContains('agency_id', $cols, 'the pool never records which agency contributed');
    }
}
