<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tier-2 fix: market-reports:backfill-suburb recovers historic comp rows
 * where the parser bind-once-at-entry bug left suburb_normalised NULL.
 *
 * Resolution priority:
 *   1. parent report.source_suburb
 *   2. parent report.subject_address trailing comma + p24_suburbs match
 *   3. comp row's own address text vs p24_suburbs longest-match
 *   4. leave NULL (unrecoverable)
 */
final class SuburbBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed minimum p24_suburbs reference list so the longest-match
        // pass has something to compare against. Real seed has ~19; this
        // covers the test cases.
        foreach (['Uvongo', 'Uvongo Beach', 'Margate', 'Marina Beach', 'Shelly Beach', 'Ramsgate'] as $name) {
            DB::table('p24_suburbs')->insert([
                'name'    => $name,
                'slug'    => Str::slug($name),
                'p24_id'  => crc32($name),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_resolves_from_parent_source_suburb(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: 'Uvongo Beach', subjectAddress: null);
        $row      = $this->seedCompRow($report->id, $agencyId, address: '4 TUCKER AVENUE');

        Artisan::call('market-reports:backfill-suburb');

        $this->assertSame('uvongo beach', $row->fresh()->suburb_normalised);
    }

    public function test_resolves_from_parent_subject_address_trailing_token(): void
    {
        $agencyId = $this->seedAgency();
        // Parent has NULL source_suburb but subject_address ends with a known suburb.
        $report   = $this->seedReport($agencyId, sourceSuburb: null, subjectAddress: 'MADEIRA GARDENS, 4 TUCKER AVENUE, UVONGO');
        $row      = $this->seedCompRow($report->id, $agencyId, address: '4 TUCKER AVENUE');

        Artisan::call('market-reports:backfill-suburb');

        $this->assertSame('uvongo', $row->fresh()->suburb_normalised);
    }

    public function test_resolves_from_comp_address_text_longest_match(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: null, subjectAddress: '4 TUCKER AVENUE');
        // Comp row address contains the suburb as trailing words (no comma).
        $row      = $this->seedCompRow($report->id, $agencyId, address: '1 ADAR ROAD MARINA BEACH');

        Artisan::call('market-reports:backfill-suburb');

        $this->assertSame('marina beach', $row->fresh()->suburb_normalised);
    }

    public function test_longest_match_wins_over_shorter_prefix(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: null, subjectAddress: null);
        // "Uvongo Beach" must win over "Uvongo" when both are in the reference list.
        $row      = $this->seedCompRow($report->id, $agencyId, address: '5 SUNSET STREET UVONGO BEACH');

        Artisan::call('market-reports:backfill-suburb');

        $this->assertSame('uvongo beach', $row->fresh()->suburb_normalised);
    }

    public function test_unrecoverable_row_left_null(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: null, subjectAddress: null);
        // Pure street address, no suburb hint anywhere — must stay NULL.
        $row      = $this->seedCompRow($report->id, $agencyId, address: '4 TUCKER AVENUE');

        Artisan::call('market-reports:backfill-suburb');

        $this->assertNull($row->fresh()->suburb_normalised);
    }

    public function test_idempotent_skips_already_resolved_rows(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: 'Uvongo Beach', subjectAddress: null);
        $row      = $this->seedCompRow($report->id, $agencyId, address: '4 TUCKER AVENUE', suburbNormalised: 'shelly beach');

        Artisan::call('market-reports:backfill-suburb');

        // Already-populated row must not be touched.
        $this->assertSame('shelly beach', $row->fresh()->suburb_normalised);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $agencyId = $this->seedAgency();
        $report   = $this->seedReport($agencyId, sourceSuburb: 'Margate', subjectAddress: null);
        $row      = $this->seedCompRow($report->id, $agencyId, address: '12 KING STREET');

        Artisan::call('market-reports:backfill-suburb', ['--dry-run' => true]);

        $this->assertNull($row->fresh()->suburb_normalised);
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function seedAgency(): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name'       => 'Backfill Test ' . Str::random(4),
            'slug'       => 'bft-' . Str::random(6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReport(int $agencyId, ?string $sourceSuburb, ?string $subjectAddress): MarketReport
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'     => 'Tester',
            'email'    => 'tester-' . Str::random(8) . '@test.local',
            'password' => 'x',
            'agency_id' => $agencyId,
            'role'     => 'agent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'file_path'           => 'test/' . Str::random(8) . '.pdf',
            'file_name'           => 'test.pdf',
            'file_hash'           => hash('sha256', Str::random(16)),
            'source_suburb'       => $sourceSuburb,
            'subject_address'     => $subjectAddress,
            'report_date'         => today()->toDateString(),
        ]);
    }

    private function seedCompRow(int $reportId, int $agencyId, string $address, ?string $suburbNormalised = null): MarketReportCompRow
    {
        return MarketReportCompRow::create([
            'market_report_id'  => $reportId,
            'agency_id'         => $agencyId,
            'row_index'         => 1,
            'row_type'          => 'comp',
            'address'           => $address,
            'suburb_normalised' => $suburbNormalised,
            'sale_date'         => today()->toDateString(),
            'sale_price'        => 1_000_000,
            'raw_row_json'      => '{}',
        ]);
    }
}
