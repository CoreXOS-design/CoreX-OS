<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\MarketReports\MarketReportCompRow;
use App\Services\MarketIntelligence\Comps\CanonicalComps;
use App\Services\MarketIntelligence\Comps\CompDuplicateFlagger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MIC step 5.3 (AT-524): CMA duplicate sales are flagged (never deleted), one canonical row per sale, presentation copies marked.
 */
final class CompDuplicateFlaggerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
    }

    /** Raw insert: bypasses the save-time observers so the batch flagger can be tested on its own. */
    private function comp(Agency $agency, array $o = []): int
    {
        return DB::table('market_report_comp_rows')->insertGetId(array_merge([
            'agency_id' => $agency->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function dupOf(int $id): ?int
    {
        $v = DB::table('market_report_comp_rows')->where('id', $id)->value('duplicate_of_id');

        return $v === null ? null : (int) $v;
    }

    private function logogo(Agency $agency, array $o = []): int
    {
        return $this->comp($agency, array_merge(['scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'ss_number' => '137', 'address' => '16 FAUX STREET', 'extent_m2' => 145, 'p24_suburb_id' => 5], $o));
    }

    public function test_repeats_point_at_the_richest_row_and_nothing_is_deleted(): void
    {
        $thin = $this->comp($this->a, ['scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'extent_m2' => 145]);
        $rich = $this->logogo($this->a);
        $other = $this->comp($this->a, ['scheme_name' => 'OTHER', 'section_number' => '9', 'extent_m2' => 80]);
        $before = DB::table('market_report_comp_rows')->count();

        $r = app(CompDuplicateFlagger::class)->run(null, true);

        $this->assertNull($this->dupOf($rich), 'the richest row is canonical');
        $this->assertSame($rich, $this->dupOf($thin));
        $this->assertNull($this->dupOf($other), 'a different sale is untouched');
        $this->assertSame(1, $r['duplicates_flagged']);
        $this->assertSame($before, DB::table('market_report_comp_rows')->count(), 'no row is ever deleted');
        $this->assertNotNull(DB::table('market_report_comp_rows')->where('id', $thin)->value('dup_checked_at'));
    }

    public function test_dry_run_writes_nothing_and_a_second_apply_changes_nothing(): void
    {
        $a = $this->logogo($this->a);
        $b = $this->logogo($this->a, ['address' => '16 FAUX STREET MARGATE']);

        $dry = app(CompDuplicateFlagger::class)->run(null, false);
        $this->assertSame(1, $dry['duplicates_flagged']);
        $this->assertNull($this->dupOf($b));
        $this->assertNull(DB::table('market_report_comp_rows')->where('id', $b)->value('dup_checked_at'));

        app(CompDuplicateFlagger::class)->run(null, true);
        $again = app(CompDuplicateFlagger::class)->run(null, true);
        $this->assertSame(0, $again['rows_checked']);
        $this->assertSame($a, $this->dupOf($b));
    }

    public function test_agencies_never_share_a_sale(): void
    {
        $a = $this->logogo($this->a);
        $b = $this->logogo($this->b);
        app(CompDuplicateFlagger::class)->run(null, true);
        $this->assertNull($this->dupOf($a));
        $this->assertNull($this->dupOf($b));
    }

    public function test_deleted_rows_and_non_sale_rows_are_ignored(): void
    {
        $live = $this->logogo($this->a);
        $gone = $this->logogo($this->a, ['deleted_at' => now()]);
        $listing = $this->logogo($this->a, ['row_type' => 'listing']);
        $unpriced = $this->logogo($this->a, ['sale_price' => null]);
        app(CompDuplicateFlagger::class)->run(null, true);
        foreach ([$live, $gone, $listing, $unpriced] as $id) {
            $this->assertNull($this->dupOf($id));
        }
        $this->assertNull(DB::table('market_report_comp_rows')->where('id', $listing)->value('dup_checked_at'));
    }

    public function test_a_row_that_arrives_later_is_placed_by_the_save_time_observer(): void
    {
        $first = MarketReportCompRow::create(['agency_id' => $this->a->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'ss_number' => '137', 'extent_m2' => 145]);
        $second = MarketReportCompRow::create(['agency_id' => $this->a->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'extent_m2' => 145]);
        $diff = MarketReportCompRow::create(['agency_id' => $this->a->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'scheme_name' => 'LOGOGO PLACE', 'section_number' => '5', 'extent_m2' => 145]);

        $this->assertNull($this->dupOf($first->id));
        $this->assertSame($first->id, $this->dupOf($second->id));
        $this->assertNull($this->dupOf($diff->id));
    }

    public function test_when_the_canonical_row_is_soft_deleted_the_next_row_takes_over(): void
    {
        $first = MarketReportCompRow::create(['agency_id' => $this->a->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'ss_number' => '137', 'extent_m2' => 145]);
        $second = MarketReportCompRow::create(['agency_id' => $this->a->id, 'row_type' => 'comp', 'sale_date' => '2026-07-08', 'sale_price' => 1_100_000,
            'scheme_name' => 'LOGOGO PLACE', 'section_number' => '4', 'extent_m2' => 145]);
        $this->assertSame($first->id, $this->dupOf($second->id));

        $first->delete();

        $this->assertNull($this->dupOf($second->id), 'the sale is not lost from the canonical set');
        $this->assertSame(1, MarketReportCompRow::canonicalSales()->count());
    }

    public function test_a_command_run_is_journalled_and_rolls_back(): void
    {
        $a = $this->logogo($this->a);
        $b = $this->logogo($this->a, ['address' => null]);
        $r = app(CompDuplicateFlagger::class)->run(null, true);
        $this->assertSame($a, $this->dupOf($b));
        $this->assertGreaterThan(0, DB::table('mic_data_journals')->where('run_id', $r['run_id'])->where('step', '5.3')->count());

        app(CompDuplicateFlagger::class)->rollback($r['run_id']);

        $this->assertNull($this->dupOf($b));
        $this->assertNull(DB::table('market_report_comp_rows')->where('id', $b)->value('dup_checked_at'));
    }

    public function test_presentation_copies_point_at_the_canonical_row(): void
    {
        Schema::disableForeignKeyConstraints();
        $a = $this->logogo($this->a);
        $b = $this->logogo($this->a, ['address' => null]);
        $copy = DB::table('presentation_sold_comps')->insertGetId(['agency_id' => $this->a->id, 'presentation_id' => 1, 'sold_date' => '2026-07-08', 'sold_price_inc' => 1_100_000,
            'raw_row_json' => json_encode(['source' => 'mic_snapshot', 'mic_comp_row_id' => $b]), 'parser_version' => 'mic_snapshot_v1']);
        $independent = DB::table('presentation_sold_comps')->insertGetId(['agency_id' => $this->a->id, 'presentation_id' => 1, 'sold_date' => '2026-07-08', 'sold_price_inc' => 1_100_000,
            'raw_row_json' => json_encode(['source' => 'upload']), 'parser_version' => 'doc_extract_v1:street_sales']);

        $r = app(CompDuplicateFlagger::class)->run(null, true);

        $this->assertSame(1, $r['psc_copies']);
        $this->assertSame($a, (int) DB::table('presentation_sold_comps')->where('id', $copy)->value('copy_of_comp_row_id'));
        $this->assertNull(DB::table('presentation_sold_comps')->where('id', $independent)->value('copy_of_comp_row_id'));
        Schema::enableForeignKeyConstraints();
    }

    public function test_canonical_comps_count_each_sale_once_and_keep_erf_and_floor_prices_apart(): void
    {
        $asOf = Carbon::parse('2026-10-31');
        // one sectional sale seen in 3 reports, one freehold sale, one sale outside the window, one capture error
        $this->logogo($this->a, ['sale_price' => 1_000_000, 'sale_date' => '2026-07-08', 'extent_m2' => 100]);
        $this->logogo($this->a, ['sale_price' => 1_000_000, 'sale_date' => '2026-07-08', 'extent_m2' => 100, 'address' => null]);
        $this->logogo($this->a, ['sale_price' => 1_000_000, 'sale_date' => '2026-07-08', 'extent_m2' => 100, 'ss_number' => null]);
        $this->comp($this->a, ['address' => '3 SEA ROAD', 'extent_m2' => 1000, 'sale_price' => 2_000_000, 'sale_date' => '2026-05-01', 'p24_suburb_id' => 5]);
        $this->comp($this->a, ['address' => '9 OLD ROAD', 'extent_m2' => 500, 'sale_price' => 900_000, 'sale_date' => '2023-01-01', 'p24_suburb_id' => 5]);
        $this->comp($this->a, ['address' => '1 TYPO ROAD', 'extent_m2' => 500, 'sale_price' => 30, 'sale_date' => '2026-05-02', 'p24_suburb_id' => 5]);
        $this->comp($this->b, ['address' => '3 SEA ROAD', 'extent_m2' => 1000, 'sale_price' => 9_000_000, 'sale_date' => '2026-05-01', 'p24_suburb_id' => 5]);
        app(CompDuplicateFlagger::class)->run(null, true);

        $s = app(CanonicalComps::class)->bySuburb($this->a->id, $asOf, 24)[5];

        $this->assertSame(2, $s['sales'], 'the sectional sale counts once; the old sale and the R30 capture error are out');
        $this->assertSame(1_500_000, $s['avg_sold']);
        $this->assertSame(10_000, $s['r_per_m2_floor']);
        $this->assertSame(2_000, $s['r_per_m2_erf']);
        $this->assertSame(1, $s['floor_sales']);
        $this->assertSame(1, $s['erf_sales']);
        $this->assertSame(2, $s['sales_12m']);
    }
}
