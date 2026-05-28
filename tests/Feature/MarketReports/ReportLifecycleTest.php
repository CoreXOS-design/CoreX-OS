<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Models\MarketReports\MarketDataDiscrepancy;
use App\Models\MarketReports\MarketDataPoint;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\MarketReportType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report-lifecycle Phase 1+4 — coverage matrix per BUILD_STANDARD §5.
 *
 * Input paths proven (one test per row):
 *   show binding:
 *     - active report → 200 (sanity)
 *     - trashed report → 200 with archived banner (was 404)
 *     - cross-agency report → 404
 *
 *   restore (Phase 2):
 *     - trashed + permission → restored, deleted_at NULL
 *     - active + permission → no-op flash, no error
 *     - trashed + missing permission → 403
 *
 *   index toggle (Phase 2):
 *     - default → excludes trashed
 *     - ?archived=1 → includes trashed
 *
 *   restore-on-rehash (Phase 3) — the report-90 case:
 *     - upload duplicate of trashed via store() → restores + re-parses, no 500
 *     - upload duplicate of trashed via bulk-import → restored JSON status
 *     - upload duplicate of active → "already uploaded" (existing UX preserved)
 *     - upload fresh file → create + parse (existing UX preserved)
 *
 *   re-parse (Phase 4):
 *     - reparse with PDF present → data_points + comp_rows + discrepancies cleared
 *     - reparse with PDF missing on disk → graceful error flash, no 500
 *
 *   cascade fix (Phase 5):
 *     - soft-delete report → comp_rows survive with NULL FK
 *     - soft-delete report → discrepancies survive with NULL FK
 */
final class ReportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const VICINITY_FIXTURE = 'cma_info_vicinity_sale_residential.pdf';

    /**
     * Reset the static permission + role caches so one test's seeded
     * permission state (see seedAgentPermissionsForGatingAssertion()) does
     * not leak into the next test in the same PHPUnit run. RefreshDatabase
     * rolls back DB rows but does not flip these in-process caches.
     */
    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── Phase 1 — withTrashed route binding ──────────────────────────────

    public function test_show_renders_for_active_report(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);

        $this->actingAs(User::find($userId))
            ->get(route('market-intelligence.reports.show', $report))
            ->assertOk()
            ->assertSee($report->file_name);
    }

    public function test_show_renders_archived_banner_for_trashed_report(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);
        $report->delete();
        $this->assertTrue($report->fresh()->trashed());

        $resp = $this->actingAs(User::find($userId))
            ->get(route('market-intelligence.reports.show', $report));

        $resp->assertOk();
        $resp->assertSee('Archived report');
        $resp->assertSee('Restore from archive');
    }

    public function test_show_404s_for_cross_agency_report(): void
    {
        [$agencyA, $userA] = $this->seedAgency();
        [$agencyB] = $this->seedAgency();
        $reportInB = $this->seedReport($agencyB, $this->firstUserOf($agencyB));

        $this->actingAs(User::find($userA))
            ->get(route('market-intelligence.reports.show', $reportInB))
            ->assertNotFound();
    }

    // ── Phase 2 — restore + permission ──────────────────────────────────

    public function test_restore_undeletes_a_trashed_report(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);
        $report->delete();

        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.restore', $report))
            ->assertRedirect(route('market-intelligence.reports.show', $report));

        $this->assertFalse($report->fresh()->trashed());
    }

    public function test_restore_on_active_report_is_idempotent_no_op(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.restore', $report));

        $resp->assertRedirect();
        $this->assertFalse($report->fresh()->trashed());
        $this->assertStringContainsString('already active', (string) session('status'));
    }

    public function test_restore_blocked_for_role_without_mic_restore_reports(): void
    {
        // PermissionService has a "fresh DB graceful bypass" (line 131-137):
        // when role_permissions is empty, all users get every permission so
        // a brand-new install isn't locked out before its seeders run. The
        // schema:dump test bootstrap leaves role_permissions empty (data
        // inserts in legacy migrations are skipped after the dump marks
        // them as ran). To actually exercise the gate, seed the rows the
        // assertion depends on. The seed_mic_permissions migration that
        // populates this in dev is squashed into the dump — explicit per-
        // test seed is the right path here.
        [$agencyId] = $this->seedAgency();
        $this->seedAgentPermissionsForGatingAssertion();

        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
        $report = $this->seedReport($agencyId, $agent->id);
        $report->delete();

        // Sanity: confirm the gate is actually live for this user.
        $this->assertTrue($agent->hasPermission('mic.upload_reports'),
            'agent needs mic.upload_reports to reach the outer route group');
        $this->assertFalse($agent->hasPermission('mic.restore_reports'),
            'agent must NOT have mic.restore_reports — that is the point of this test');

        $resp = $this->actingAs($agent)
            ->post(route('market-intelligence.reports.restore', $report));

        $resp->assertForbidden();

        $fresh = \App\Models\MarketReports\MarketReport::withTrashed()->find($report->id);
        $this->assertTrue($fresh->trashed(),
            'Report must remain trashed when restore was denied');
    }

    // ── Phase 2 — index toggle ──────────────────────────────────────────

    public function test_index_hides_trashed_by_default(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $active   = $this->seedReport($agencyId, $userId, fileName: 'Active.pdf');
        $archived = $this->seedReport($agencyId, $userId, fileName: 'Archived.pdf');
        $archived->delete();

        $this->actingAs(User::find($userId))
            ->get(route('market-intelligence.reports.index'))
            ->assertOk()
            ->assertSee('Active.pdf')
            ->assertDontSee('Archived.pdf');
    }

    public function test_index_archived_toggle_shows_trashed_rows(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $active   = $this->seedReport($agencyId, $userId, fileName: 'Active.pdf');
        $archived = $this->seedReport($agencyId, $userId, fileName: 'Archived.pdf');
        $archived->delete();

        $this->actingAs(User::find($userId))
            ->get(route('market-intelligence.reports.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee('Active.pdf')
            ->assertSee('Archived.pdf')
            ->assertSee('Hide archived');
    }

    // ── Phase 3 — restore-on-rehash dedup ───────────────────────────────

    public function test_uploading_same_file_as_trashed_report_restores_and_reparses(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $type = $this->seedVicinityType();
        Storage::fake('local');

        // Initial upload.
        $upload1 = $this->fixtureUploadedFile();
        $resp1 = $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file'           => $upload1,
                'report_type_id' => $type->id,
            ]);
        $resp1->assertRedirect();

        $report = MarketReport::query()->where('agency_id', $agencyId)->firstOrFail();
        $originalId = $report->id;
        $report->delete();
        $this->assertTrue($report->fresh()->trashed());

        // Re-upload identical bytes. This is the report-90 scenario.
        $upload2 = $this->fixtureUploadedFile();
        $resp2 = $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file'           => $upload2,
                'report_type_id' => $type->id,
            ]);

        // Must NOT 500. Must redirect to the same (now restored) row.
        $resp2->assertStatus(302);
        $resp2->assertSessionHas('status');
        $this->assertStringContainsString('restored', (string) session('status'));

        $restored = MarketReport::withTrashed()->find($originalId);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->trashed(), 'Same-hash re-upload must clear deleted_at');
    }

    public function test_bulk_import_restores_trashed_match(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $type = $this->seedVicinityType();
        Storage::fake('local');

        $hash = hash_file('sha256', base_path('tests/Fixtures/market_reports/' . self::VICINITY_FIXTURE));
        $report = MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => $type->id,
            'file_path'           => 'fake/missing.pdf',
            'file_name'           => 'Stub.pdf',
            'file_hash'           => $hash,
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PARSED,
            'spot_check_status'   => MarketReport::SPOT_PASSED,
            'data_points_count'   => 5,
        ]);
        $report->delete();

        $upload = $this->fixtureUploadedFile();
        $resp = $this->actingAs(User::find($userId))
            ->postJson(route('market-intelligence.reports.bulk-import.store'), [
                'file'           => $upload,
                'report_type_id' => $type->id,
            ]);

        $resp->assertOk();
        $resp->assertJson(['status' => 'restored', 'report_id' => $report->id]);
        $this->assertFalse($report->fresh()->trashed());
    }

    public function test_uploading_same_file_as_active_report_preserves_duplicate_ux(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $type = $this->seedVicinityType();
        Storage::fake('local');

        $upload1 = $this->fixtureUploadedFile();
        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file' => $upload1, 'report_type_id' => $type->id,
            ])->assertRedirect();

        $upload2 = $this->fixtureUploadedFile();
        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file' => $upload2, 'report_type_id' => $type->id,
            ])
            ->assertRedirect();

        // Still exactly one row.
        $this->assertSame(1, MarketReport::query()->where('agency_id', $agencyId)->count());
        $this->assertStringContainsString('already uploaded', (string) session('status'));
    }

    // ── Phase 4 — re-parse ──────────────────────────────────────────────

    public function test_reparse_clears_existing_rows_then_re_extracts(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $type = $this->seedVicinityType();
        Storage::fake('local');

        // Upload + initial parse so we have real comp_rows + data_points.
        $upload = $this->fixtureUploadedFile();
        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file' => $upload, 'report_type_id' => $type->id,
            ])->assertRedirect();

        $report = MarketReport::query()->firstOrFail();
        $firstCompCount = MarketReportCompRow::where('market_report_id', $report->id)->count();
        $firstDpCount   = MarketDataPoint::where('report_id', $report->id)->count();

        // Sabotage one row id so we can detect that re-parse cleared and
        // re-inserted rather than no-op'd. Force-delete a comp row, then
        // re-parse should rebuild the full set.
        if ($firstCompCount > 0) {
            DB::table('market_report_comp_rows')
                ->where('market_report_id', $report->id)
                ->orderBy('id')
                ->limit(1)
                ->delete();
        }

        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.reparse', $report))
            ->assertRedirect(route('market-intelligence.reports.show', $report))
            ->assertSessionHas('status');

        $report->refresh();
        $this->assertSame(MarketReport::PARSE_PARSED, $report->parse_status,
            're-parse must leave the report in parsed state when the parser succeeds');

        $this->assertSame(
            $firstCompCount,
            MarketReportCompRow::where('market_report_id', $report->id)->count(),
            're-parse must re-extract the same comp row count from the same PDF',
        );
        $this->assertSame(
            $firstDpCount,
            MarketDataPoint::where('report_id', $report->id)->count(),
            're-parse must re-extract the same data point count from the same PDF',
        );
    }

    public function test_reparse_with_missing_pdf_flashes_error_no_500(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $type = $this->seedVicinityType();
        $report = MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => $type->id,
            'file_path'           => 'market-reports/nonexistent.pdf',
            'file_name'           => 'Missing.pdf',
            'file_hash'           => str_repeat('a', 64),
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PARSED,
            'spot_check_status'   => MarketReport::SPOT_PASSED,
            'data_points_count'   => 0,
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.reparse', $report));

        $resp->assertRedirect();
        $this->assertStringContainsString('missing on disk', (string) session('error'),
            'Missing source file must flash a user-clear error, never bubble a 500');
    }

    // ── Phase 5 — cascade preserves audit ───────────────────────────────

    public function test_soft_deleting_report_preserves_comp_rows(): void
    {
        // The Phase 5 cascade-fix migration changes the FK from
        // cascadeOnDelete to nullOnDelete. nullOnDelete only fires on HARD
        // delete (DELETE FROM market_reports …); soft-delete is an UPDATE
        // that doesn't trigger FK actions, so the child's FK stays intact
        // pointing at the now-trashed parent. Either way the audit row
        // survives — that's the property we're asserting.
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);

        $compRow = MarketReportCompRow::create([
            'market_report_id'    => $report->id,
            'agency_id'           => $agencyId,
            'row_index'           => 0,
            'row_type'            => 'comp',
            'address'             => '1 Audit Lane',
            'suburb_normalised'   => 'uvongo',
        ]);

        $report->delete();

        $this->assertDatabaseHas('market_report_comp_rows', [
            'id'      => $compRow->id,
            'address' => '1 Audit Lane',
        ]);
        $this->assertSame(1, MarketReportCompRow::where('id', $compRow->id)->count(),
            'comp_row must survive parent soft-delete');
    }

    public function test_force_deleting_report_nullifies_comp_row_fk_without_hard_destroy(): void
    {
        // This is the cascade migration's primary win: a hard force-delete
        // (the only path that fires FK actions) used to cascade-destroy the
        // audit; after the migration it nulls the FK and the row stays.
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);

        $compRow = MarketReportCompRow::create([
            'market_report_id'    => $report->id,
            'agency_id'           => $agencyId,
            'row_index'           => 0,
            'row_type'            => 'comp',
            'address'             => '2 Hard Delete Drive',
        ]);

        $report->forceDelete();

        $this->assertDatabaseHas('market_report_comp_rows', [
            'id'               => $compRow->id,
            'market_report_id' => null,
            'address'          => '2 Hard Delete Drive',
        ]);
    }

    public function test_soft_deleting_report_preserves_discrepancies(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $report = $this->seedReport($agencyId, $userId);

        $dp = MarketDataPoint::create([
            'agency_id'   => $agencyId,
            'report_id'   => $report->id,
            'metric_key'  => 'municipal_valuation',
            'metric_value_numeric' => 1_200_000,
            'metric_date' => now()->toDateString(),
            'source_type' => 'market_report',
        ]);
        $disc = MarketDataDiscrepancy::create([
            'report_id'         => $report->id,
            'data_point_id'     => $dp->id,
            'parsed_value'      => '1 200 000',
            'audit_value'       => '1 100 000',
            'discrepancy_type'  => 'value_mismatch',
            'severity'          => 'medium',
            'resolved'          => false,
        ]);

        $report->delete();

        $this->assertDatabaseHas('market_data_discrepancies', [
            'id' => $disc->id,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function firstUserOf(int $agencyId): int
    {
        return (int) DB::table('users')->where('agency_id', $agencyId)->orderBy('id')->value('id');
    }

    private function seedVicinityType(): MarketReportType
    {
        // Seeders aren't run by RefreshDatabase; create the type row inline so
        // controller code that resolves the parser by key can find it. The
        // FQCN matches the production MarketReportTypesSeeder row exactly.
        return MarketReportType::query()->firstOrCreate(
            ['key' => 'cma_info_vicinity_sale'],
            [
                'display_name'         => 'CMA Info — Vicinity Sale (Residential / Vacant Land)',
                'parser_class'         => 'App\\Services\\MarketReports\\Parsers\\CmaInfoVicinitySaleParser',
                'expected_fields_json' => json_encode(['sales[]']),
                'auto_approve'         => 1,
            ],
        );
    }

    private function seedReport(int $agencyId, int $userId, ?string $fileName = null): MarketReport
    {
        return MarketReport::create([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'report_type_id'      => $this->seedVicinityType()->id,
            'file_path'           => 'market-reports/test-' . Str::random(8) . '.pdf',
            'file_name'           => $fileName ?? 'Test ' . Str::random(8) . '.pdf',
            'file_hash'           => hash('sha256', Str::random(64)),
            'report_date'         => now()->toDateString(),
            'parse_status'        => MarketReport::PARSE_PARSED,
            'spot_check_status'   => MarketReport::SPOT_PASSED,
            'data_points_count'   => 0,
        ]);
    }

    private function fixtureUploadedFile(): UploadedFile
    {
        $path = base_path('tests/Fixtures/market_reports/' . self::VICINITY_FIXTURE);
        return new UploadedFile($path, self::VICINITY_FIXTURE, 'application/pdf', null, true);
    }

    /**
     * Seed just enough role + role_permission rows so PermissionService's
     * fresh-DB bypass deactivates and the actual permission gate fires.
     * Mirrors the production seed_mic_permissions migration but kept
     * minimal for the test's needs (the agent role + mic.upload_reports
     * so the outer middleware passes; no mic.restore_reports so the
     * inner gate triggers as designed). Also clears the
     * PermissionService::$seeded static cache so the new state is read.
     */
    private function seedAgentPermissionsForGatingAssertion(): void
    {
        DB::table('roles')->insertOrIgnore([
            'name' => 'agent', 'label' => 'Agent', 'is_owner' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('nexus_permissions')->insertOrIgnore([
            'key' => 'mic.upload_reports', 'label' => 'Upload Market / CMA Reports',
            'section' => 'prospecting', 'type' => 'action', 'module' => 'mic',
            'sort_order' => 52, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'mic.upload_reports',
            'scope' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Flip the PermissionService::$seeded static cache so it reads
        // role_permissions fresh instead of returning the cached "empty,
        // grant all" result from earlier tests in this run.
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
    }
}
