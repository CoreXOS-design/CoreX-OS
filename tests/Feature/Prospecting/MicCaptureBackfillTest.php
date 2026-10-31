<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Services\Prospecting\MicCaptureBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MIC capture lane step 3.5 (.ai/specs/mic-work-tab.md §11.2): the three back-fills - dry run counts only, apply fills
 * only what is empty and journals every row, a second apply changes nothing, and the excluded test agency is left alone.
 */
final class MicCaptureBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $branch = \App\Models\Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->userId = \App\Models\User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id])->id;
        Storage::fake('local');
    }

    private function listing(Agency $agency, string $ref, array $over = []): int
    {
        $now = now();

        return (int) DB::table('prospecting_listings')->insertGetId($over + [
            'agency_id' => $agency->id, 'captured_by_user_id' => $this->userId, 'portal_source' => 'p24', 'portal_ref' => $ref, 'suburb' => 'Margate',
            'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function journal(): string
    {
        return sys_get_temp_dir() . '/mic-backfill-' . uniqid() . '.jsonl';
    }

    // ---- p24-groups ----------------------------------------------------------------------------------------------

    public function test_groups_are_parsed_from_the_real_results_page_fixture(): void
    {
        $html = file_get_contents(base_path('public/chrome-extension/portal-capture/tests/fixtures/p24-search/margate-search-owner-groups-reduced.html'));
        $groups = MicCaptureBackfill::parseGroups($html);
        $this->assertCount(2, $groups);
        foreach ($groups as $id => $g) {
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $id);
            $this->assertGreaterThanOrEqual(2, count($g['numbers']));
            $this->assertSame(count($g['numbers']), $g['size']);
        }
        $this->assertContains('116369778', collect($groups)->flatMap(fn ($g) => $g['numbers'])->all());
    }

    private function capture(string $html, string $when): void
    {
        $path = 'portal_captures/' . uniqid() . '.html';
        Storage::disk('local')->put($path, $html);
        DB::table('portal_captures')->insert([
            'agency_id' => $this->a->id, 'user_id' => $this->userId, 'source_site' => 'www.property24.com', 'page_type' => 'search', 'source_url' => 'https://x', 'final_url' => 'https://x',
            'captured_at' => $when, 'extractor_version' => 't', 'dom_hash_sha256' => str_repeat('a', 64), 'html_bytes' => strlen($html), 'raw_html_path' => $path,
            'parse_status' => 'ok', 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    public function test_p24_groups_dry_run_counts_apply_writes_and_journals_and_rerun_is_a_no_op(): void
    {
        $span = fn (string $gid, string $nums) => '<span data-href="/Results/GroupedListings/' . $gid . '?x=1&amp;listingNumbers=' . rawurlencode('[' . $nums . ']')
            . '" class="js_groupedListingsLink" data-listing-group-text="2 Estate Agencies" data-group-listing-id="' . $gid . '">Listed by</span>';
        $this->capture('<html>' . $span('7001', '116000001,116000002') . $span('7002', '116000002,116000003') . '</html>', '2026-06-12 10:00:00');
        $this->capture('<html>' . $span('7001', '116000001,116000002') . '</html>', '2026-06-13 10:00:00');
        $mine = $this->listing($this->a, 'P24-116000001');
        $hasGroup = $this->listing($this->a, 'P24-116000003', ['p24_group_id' => '999']);
        $otherAgency = $this->listing($this->b, 'P24-116000001');
        $testAgency = Agency::create(['name' => 'Test copy', 'slug' => 'test-copy']);
        $excluded = $this->listing($testAgency, 'P24-116000002');
        $svc = app(MicCaptureBackfill::class);

        $dry = $svc->p24Groups(false, [$testAgency->id]);
        $this->assertSame(2, $dry['captures_read']);
        $this->assertSame(2, $dry['groups']);
        $this->assertSame(4, $dry['member_numbers']);
        $this->assertSame(1, $dry['numbers_in_two_groups']);
        $this->assertSame(4, $dry['member_rows_new']);
        $this->assertSame(2, $dry['adverts_getting_group_id'], 'agency A and B adverts for 116000001; not the one that has a group; not the excluded agency');
        $this->assertSame(0, DB::table('p24_group_members')->count(), 'a dry run writes nothing');

        $j = $this->journal();
        $svc->journalTo($j);
        $svc->p24Groups(true, [$testAgency->id]);
        $svc->journalTo(null);

        $this->assertSame(4, DB::table('p24_group_members')->count());
        $this->assertSame('2026-06-12', substr((string) DB::table('p24_group_members')->where('p24_group_id', '7001')->where('listing_number', '116000001')->value('first_seen_at'), 0, 10));
        $this->assertSame('2026-06-13', substr((string) DB::table('p24_group_members')->where('p24_group_id', '7001')->where('listing_number', '116000001')->value('seen_at'), 0, 10));
        $this->assertSame('7001', DB::table('prospecting_listings')->where('id', $mine)->value('p24_group_id'));
        $this->assertSame('7001', DB::table('prospecting_listings')->where('id', $otherAgency)->value('p24_group_id'));
        $this->assertSame('999', DB::table('prospecting_listings')->where('id', $hasGroup)->value('p24_group_id'), 'an existing group id is never overwritten');
        $this->assertNull(DB::table('prospecting_listings')->where('id', $excluded)->value('p24_group_id'), 'the excluded agency is untouched');
        $this->assertGreaterThanOrEqual(6, count(file($j)), 'every written row is journalled');

        $again = $svc->p24Groups(true, [$testAgency->id]);
        $this->assertSame(0, $again['member_rows_new']);
        $this->assertSame(0, $again['adverts_getting_group_id']);
    }

    // ---- floor-sizes ---------------------------------------------------------------------------------------------

    private function portalListing(Agency $agency, string $id, ?float $size): void
    {
        DB::table('portal_listings')->insert([
            'agency_id' => $agency->id, 'source_site' => 'www.property24.com', 'portal_listing_id' => $id,
            'current_fields_json' => json_encode($size === null ? ['beds' => 3] : ['beds' => 3, 'size_m2' => $size]),
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_floor_sizes_fill_only_empty_values_inside_the_same_agency(): void
    {
        $empty = $this->listing($this->a, 'P24-117000001', ['erf_size_m2' => 82]);
        $has = $this->listing($this->a, 'P24-117000002', ['property_size_m2' => 100]);
        $noSize = $this->listing($this->a, 'P24-117000003');
        $other = $this->listing($this->b, 'P24-117000001');
        $silly = $this->listing($this->a, 'P24-117000004');
        $this->portalListing($this->a, '117000001', 82);
        $this->portalListing($this->a, '117000002', 150);
        $this->portalListing($this->a, '117000003', null);
        $this->portalListing($this->a, '117000004', 3);
        $svc = app(MicCaptureBackfill::class);

        $dry = $svc->floorSizes(false, []);
        $this->assertSame(3, $dry['portal_listings_with_floor_size']);
        $this->assertSame(3, $dry['matched_to_a_mic_advert']);
        $this->assertSame(1, $dry['mic_advert_already_has_floor_size']);
        $this->assertSame(1, $dry['floor_sizes_to_fill']);
        $this->assertSame(1, $dry['skipped_implausible']);
        $this->assertSame(1, $dry['also_equal_to_erf_size']);
        $this->assertNull(DB::table('prospecting_listings')->where('id', $empty)->value('property_size_m2'));

        $j = $this->journal();
        $svc->journalTo($j);
        $svc->floorSizes(true, []);
        $svc->journalTo(null);

        $this->assertEquals(82, DB::table('prospecting_listings')->where('id', $empty)->value('property_size_m2'));
        $this->assertEquals(100, DB::table('prospecting_listings')->where('id', $has)->value('property_size_m2'), 'a size already there is kept');
        $this->assertNull(DB::table('prospecting_listings')->where('id', $noSize)->value('property_size_m2'));
        $this->assertNull(DB::table('prospecting_listings')->where('id', $other)->value('property_size_m2'), "another agency's advert with the same number is not touched");
        $this->assertNull(DB::table('prospecting_listings')->where('id', $silly)->value('property_size_m2'));
        $this->assertCount(1, file($j));
        $this->assertSame(0, $svc->floorSizes(true, [])['floor_sizes_to_fill']);
    }

    // ---- sale-agency ---------------------------------------------------------------------------------------------

    public function test_adverts_stored_with_agency_sale_become_owner_adverts_with_no_agency(): void
    {
        $a = $this->listing($this->a, 'P24-118000001', ['agency_name' => 'Sale']);
        $b = $this->listing($this->a, 'P24-118000002', ['agency_name' => ' sale ']);
        $real = $this->listing($this->a, 'P24-118000003', ['agency_name' => 'Sale & Rent Properties']);
        $pp = $this->listing($this->a, 'PP-T1', ['portal_source' => 'pp', 'agency_name' => 'Sale']);
        DB::table('portal_advert_facts')->insert(['canonical_ref' => 'p24:118000001', 'portal' => 'p24', 'listing_id' => '118000001', 'listing_agency_name' => 'Sale', 'status' => 'active', 'seen_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $svc = app(MicCaptureBackfill::class);

        $dry = $svc->saleAgency(false, []);
        $this->assertSame(2, $dry['adverts_stored_with_agency_sale']);
        $this->assertSame(1, $dry['pool_rows_with_agency_sale']);
        $this->assertSame('Sale', DB::table('prospecting_listings')->where('id', $a)->value('agency_name'));

        $j = $this->journal();
        $svc->journalTo($j);
        $svc->saleAgency(true, []);
        $svc->journalTo(null);

        foreach ([$a, $b] as $id) {
            $row = DB::table('prospecting_listings')->where('id', $id)->first();
            $this->assertNull($row->agency_name);
            $this->assertSame('owner', $row->advertiser_type);
        }
        $this->assertSame('Sale & Rent Properties', DB::table('prospecting_listings')->where('id', $real)->value('agency_name'));
        $this->assertSame('Sale', DB::table('prospecting_listings')->where('id', $pp)->value('agency_name'), 'only P24 adverts carry the owner icon alt text');
        $pool = DB::table('portal_advert_facts')->where('canonical_ref', 'p24:118000001')->first();
        $this->assertNull($pool->listing_agency_name);
        $this->assertSame('owner', $pool->advertiser_type);
        $this->assertCount(6, file($j));
        $this->assertSame(0, $svc->saleAgency(true, [])['adverts_stored_with_agency_sale']);
    }

    // ---- erf-floor -----------------------------------------------------------------------------------------------

    private function tp(Agency $agency, ?float $erf, array $over = []): int
    {
        return (int) DB::table('tracked_properties')->insertGetId($over + [
            'agency_id' => $agency->id, 'external_id' => (string) \Illuminate\Support\Str::uuid(), 'erf_size_m2' => $erf,
            'source_chain' => json_encode([['type' => 'p24', 'ref' => 'P24-1', 'fields_contributed' => ['erf_size_m2']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_erf_that_is_really_the_floor_size_is_cleared_on_the_advert_and_the_file_unless_another_source_gives_it(): void
    {
        $svc = app(MicCaptureBackfill::class);
        $t1 = $this->tp($this->a, 100);
        $a1 = $this->listing($this->a, 'P24-119000001', ['canonical_ref' => 'p24:119000001', 'tracked_property_id' => $t1, 'property_size_m2' => 100, 'erf_size_m2' => 100]);
        $real = $this->listing($this->a, 'P24-119000002', ['property_size_m2' => 90, 'erf_size_m2' => 600]);
        $pageRead = $this->listing($this->a, 'P24-119000003', ['property_size_m2' => 120, 'erf_size_m2' => 120, 'details_read_at' => now()]);
        $t2 = $this->tp($this->a, 110, ['deeds_captured_at' => now()]);
        $a4 = $this->listing($this->a, 'P24-119000004', ['tracked_property_id' => $t2, 'property_size_m2' => 110, 'erf_size_m2' => 110]);
        $t3 = $this->tp($this->a, 130, ['source_chain' => json_encode([['type' => 'p24', 'fields_contributed' => ['erf_size_m2']], ['type' => 'cmainfo', 'fields_contributed' => ['erf_size_m2']]])]);
        $a5 = $this->listing($this->a, 'P24-119000005', ['tracked_property_id' => $t3, 'property_size_m2' => 130, 'erf_size_m2' => 130]);
        $t4 = $this->tp($this->a, 140);
        $a6 = $this->listing($this->a, 'P24-119000006', ['tracked_property_id' => $t4, 'property_size_m2' => 140, 'erf_size_m2' => 140]);
        $a7 = $this->listing($this->a, 'P24-119000007', ['tracked_property_id' => $t4, 'property_size_m2' => 80, 'erf_size_m2' => 140]);
        $testAgency = Agency::create(['name' => 'Test copy', 'slug' => 'test-copy']);
        $ex = $this->listing($testAgency, 'P24-119000008', ['property_size_m2' => 70, 'erf_size_m2' => 70]);
        DB::table('portal_advert_facts')->insert(['canonical_ref' => 'p24:119000001', 'portal' => 'p24', 'listing_id' => '119000001', 'erf_size_m2' => 100, 'status' => 'active', 'seen_count' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $dry = $svc->erfFloor(false, [$testAgency->id]);
        $this->assertSame(5, $dry['before']['adverts_erf_equals_floor']);
        $this->assertSame(1, $dry['adverts_kept_listing_page_read']);
        $this->assertSame(4, $dry['adverts_to_clear']);
        $this->assertSame(4, $dry['files_whose_erf_matches_a_cleared_advert']);
        $this->assertSame([1, 1, 1, 1], [$dry['files_kept_deeds'], $dry['files_kept_other_source'], $dry['files_kept_real_erf_on_another_advert'], $dry['files_to_clear']]);
        $this->assertSame(1, $dry['pool_rows_carrying_the_same_wrong_erf_not_changed']);
        $this->assertEquals(100, DB::table('prospecting_listings')->where('id', $a1)->value('erf_size_m2'), 'a dry run writes nothing');

        $j = $this->journal();
        $svc->journalTo($j);
        $done = $svc->erfFloor(true, [$testAgency->id]);
        $svc->journalTo(null);

        foreach ([$a1, $a4, $a5, $a6] as $id) {
            $this->assertNull(DB::table('prospecting_listings')->where('id', $id)->value('erf_size_m2'), "advert $id");
        }
        $this->assertEquals(600, DB::table('prospecting_listings')->where('id', $real)->value('erf_size_m2'));
        $this->assertEquals(120, DB::table('prospecting_listings')->where('id', $pageRead)->value('erf_size_m2'), 'a listing-page erf is real');
        $this->assertEquals(140, DB::table('prospecting_listings')->where('id', $a7)->value('erf_size_m2'));
        $this->assertEquals(70, DB::table('prospecting_listings')->where('id', $ex)->value('erf_size_m2'));
        $this->assertNull(DB::table('tracked_properties')->where('id', $t1)->value('erf_size_m2'));
        $this->assertEquals(110, DB::table('tracked_properties')->where('id', $t2)->value('erf_size_m2'), 'deeds win');
        $this->assertEquals(130, DB::table('tracked_properties')->where('id', $t3)->value('erf_size_m2'), 'another source wins');
        $this->assertEquals(140, DB::table('tracked_properties')->where('id', $t4)->value('erf_size_m2'), 'a real erf on another advert wins');
        $this->assertEquals(100, DB::table('portal_advert_facts')->where('canonical_ref', 'p24:119000001')->value('erf_size_m2'), 'the pool is counted, not changed');
        $this->assertCount(5, file($j));
        $this->assertSame(1, $done['after']['adverts_erf_equals_floor'], 'only the page-read advert is left');
        $this->assertSame(0, $svc->erfFloor(true, [$testAgency->id])['adverts_to_clear']);
    }

    // ---- the command ---------------------------------------------------------------------------------------------

    public function test_command_defaults_to_a_dry_run_and_rejects_unknown_steps_and_both_flags(): void
    {
        $this->listing($this->a, 'P24-118000009', ['agency_name' => 'Sale']);
        $dir = sys_get_temp_dir() . '/mic-bf-' . uniqid();

        $this->artisan('mic:capture-backfill', ['step' => 'sale-agency', '--journal-dir' => $dir])->assertSuccessful();
        $this->assertSame('Sale', DB::table('prospecting_listings')->where('portal_ref', 'P24-118000009')->value('agency_name'));
        $this->artisan('mic:capture-backfill', ['step' => 'nonsense'])->assertFailed();
        $this->artisan('mic:capture-backfill', ['step' => 'sale-agency', '--apply' => true, '--dry-run' => true])->assertFailed();
        $this->artisan('mic:capture-backfill', ['step' => 'sale-agency', '--apply' => true, '--journal-dir' => $dir])->assertSuccessful();
        $this->assertNull(DB::table('prospecting_listings')->where('portal_ref', 'P24-118000009')->value('agency_name'));
    }
}
