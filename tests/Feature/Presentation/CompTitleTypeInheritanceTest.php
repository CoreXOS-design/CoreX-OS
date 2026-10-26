<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\Presentations\MicSnapshotHydrator;
use App\Services\Presentations\PresentationImportSummaryService;
use App\Services\TitleTypeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The LOOM report on Blue Valley Golf Estate (Staging presentation 540, 26 Oct 2026): 25 Full Title sales were
 * read, none reached the presentation. The report prints "Unit Number 00000" for every freehold property; that
 * filler was stored as the section number, a section number reads as "sectional unit", and the pool threw all 25
 * out of a Full Title presentation. Also covered: a comp with no title type at all is kept and flagged, the
 * "excluded as nearby" count only counts nearby sales, the importer hands a blank row its report's type, the
 * backfill repairs rows already stored, and "0 hydrated" says why.
 */
final class CompTitleTypeInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── The classifier: a filler is not a sectional signal ───────────────

    public function test_filler_section_numbers_are_not_a_sectional_signal(): void
    {
        $c = new TitleTypeClassifier();

        foreach (['00000', '0', '000', '-', '—', 'n/a', 'N/A', 'none', '', null, '  '] as $filler) {
            $this->assertTrue(TitleTypeClassifier::isPlaceholderSignal($filler), var_export($filler, true) . ' is a filler');
            $this->assertSame(TitleTypeClassifier::TITLE_FULL, $c->categoryForComp('Full Title', null, $filler));
        }
        $this->assertNull($c->categoryForComp(null, null, '00000'), 'a filler and no property type says nothing');
    }

    public function test_a_real_section_number_or_scheme_is_still_sectional(): void
    {
        $c = new TitleTypeClassifier();

        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $c->categoryForComp('Full Title', null, '12'));
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $c->categoryForComp('Residence', null, 'Section 4'));
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $c->categoryForComp('Residence', 'Seeskulp', null));
        $this->assertSame(TitleTypeClassifier::TITLE_SECTIONAL, $c->categoryForComp('Full Title', 'Seeskulp', '00000'));
    }

    // ── Importer: the one choke point every read passes ──────────────────

    public function test_importer_drops_a_filler_section_number_and_inherits_the_subject_type(): void
    {
        $clean = app(CanonicalReadMapper::class)->sanitise([
            'subject' => ['address' => '11 Manikie Street', 'section_number' => '00000', 'property_type' => 'Full Title'],
            'comps'   => [
                ['kind' => 'comp', 'address' => '8 Carlyke Street', 'section_number' => '00000', 'sale_price' => 4_200_000, 'sale_date' => '2025-11-18'],
                ['kind' => 'comp', 'address' => '14 Manikie Street', 'section_number' => '00000', 'property_type' => 'Sectional Title', 'sale_price' => 900_000, 'sale_date' => '2025-10-10'],
            ],
        ])['clean'];

        $this->assertArrayNotHasKey('section_number', $clean['subject']);
        $this->assertSame('Full Title', $clean['subject']['property_type']);

        $this->assertArrayNotHasKey('section_number', $clean['comps'][0]);
        $this->assertSame('Full Title', $clean['comps'][0]['property_type'], 'a blank row takes the report\'s type');
        $this->assertSame('report', $clean['comps'][0]['title_type_source']);

        $this->assertSame('Sectional Title', $clean['comps'][1]['property_type'], 'a type the row states is never overwritten');
        $this->assertArrayNotHasKey('title_type_source', $clean['comps'][1]);
    }

    public function test_importer_inherits_the_one_type_every_named_row_agrees_on(): void
    {
        $comps = app(CanonicalReadMapper::class)->inheritTitleType([
            ['address' => 'a', 'property_type' => 'Full Title'],
            ['address' => 'b'],
            ['address' => 'c', 'property_type' => 'full title'],
        ], null);

        $this->assertSame('Full Title', $comps[1]['property_type']);
        $this->assertSame('report', $comps[1]['title_type_source']);
    }

    public function test_importer_leaves_a_blank_row_alone_when_the_report_names_two_types_or_none(): void
    {
        $mapper = app(CanonicalReadMapper::class);

        $mixed = $mapper->inheritTitleType([
            ['address' => 'a', 'property_type' => 'Full Title'],
            ['address' => 'b', 'property_type' => 'Sectional Title'],
            ['address' => 'c'],
        ], null);
        $this->assertArrayNotHasKey('property_type', $mixed[2]);

        $none = $mapper->inheritTitleType([['address' => 'a'], ['address' => 'b']], null);
        $this->assertArrayNotHasKey('property_type', $none[0]);
    }

    // ── Hydration, end to end ────────────────────────────────────────────

    public function test_full_title_comps_with_a_filler_section_number_now_hydrate(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $presentation = $this->makePresentation($agencyId, $userId);
        $reportId = $this->seedReport($agencyId, $userId, $presentation->property_address);
        for ($i = 1; $i <= 5; $i++) {
            $this->seedCompRow($agencyId, $reportId, $i, ['property_type' => 'Full Title', 'section_number' => '00000']);
        }

        $summary = (new MicSnapshotHydrator())->hydrateForPresentation($presentation->fresh(['property']));

        $this->assertSame(5, $summary['sold_comps_inserted'], 'all five Full Title sales reach a Full Title presentation');
        $this->assertSame(0, $summary['excluded_by_type']);
        $this->assertSame(5, $summary['pool_diagnostics']['n_selected']);
    }

    public function test_comps_with_no_title_type_at_all_are_kept_and_flagged_unknown(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $presentation = $this->makePresentation($agencyId, $userId);
        // Another report (not the subject's own) whose rows are in the subject's suburb: the title gate really applies to these.
        $otherReport = $this->seedReport($agencyId, $userId, '99 Elsewhere Road, Margate');
        for ($i = 1; $i <= 4; $i++) {
            $this->seedCompRow($agencyId, $otherReport, $i, ['property_type' => null, 'section_number' => '00000']);
        }

        $summary = (new MicSnapshotHydrator())->hydrateForPresentation($presentation->fresh(['property']));

        $this->assertSame(4, $summary['sold_comps_inserted'], 'no title type is never a reason to leave a comp out');
        $this->assertSame(0, $summary['excluded_by_type']);
        $raw = json_decode((string) PresentationSoldComp::where('presentation_id', $presentation->id)->first()->raw_row_json, true);
        $this->assertTrue($raw['title_type_unknown']);
    }

    public function test_a_genuinely_sectional_comp_is_still_excluded_from_a_full_title_presentation(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $presentation = $this->makePresentation($agencyId, $userId);
        $otherReport = $this->seedReport($agencyId, $userId, '99 Elsewhere Road, Margate');
        $this->seedCompRow($agencyId, $otherReport, 1, ['property_type' => 'Sectional Title', 'scheme_name' => 'Seeskulp', 'section_number' => '8']);
        $this->seedCompRow($agencyId, $otherReport, 2, ['property_type' => 'Commercial']);

        $summary = (new MicSnapshotHydrator())->hydrateForPresentation($presentation->fresh(['property']));

        $this->assertSame(0, $summary['sold_comps_inserted']);
        $this->assertSame(2, $summary['excluded_by_type'], 'a sectional sale and a commercial sale in the same suburb are still set aside');
    }

    public function test_the_excluded_count_only_counts_sales_that_were_nearby(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $presentation = $this->makePresentation($agencyId, $userId);
        $otherReport = $this->seedReport($agencyId, $userId, '5 Beach Road, Margate');
        for ($i = 1; $i <= 6; $i++) {
            // A sectional sale in another town, no coordinates: nowhere near Blue Valley.
            $this->seedCompRow($agencyId, $otherReport, $i, [
                'property_type' => 'Sectional Title', 'scheme_name' => 'Seeskulp', 'section_number' => '8',
                'suburb_normalised' => 'margate', 'latitude' => null, 'longitude' => null,
            ]);
        }

        $summary = (new MicSnapshotHydrator())->hydrateForPresentation($presentation->fresh(['property']));

        $this->assertSame(0, $summary['excluded_by_type'], 'six sales in another town are not "found nearby"');
    }

    // ── Backfill ─────────────────────────────────────────────────────────

    public function test_backfill_repairs_stored_rows_and_is_idempotent(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $reportId = $this->seedReport($agencyId, $userId, '11 Manikie Street, Blue Valley Golf Estate');
        $this->seedCompRow($agencyId, $reportId, 1, ['property_type' => 'Full Title', 'section_number' => '00000']);
        $blank1 = $this->seedCompRow($agencyId, $reportId, 2, ['property_type' => null, 'section_number' => '00000']);
        $this->seedCompRow($agencyId, $reportId, 3, ['property_type' => null, 'section_number' => '7']);
        // A report that names no type anywhere: nothing to inherit.
        $emptyReport = $this->seedReport($agencyId, $userId, 'Nowhere');
        $untouched = $this->seedCompRow($agencyId, $emptyReport, 1, ['property_type' => null, 'section_number' => '00000']);

        Artisan::call('market-reports:backfill-comp-title-types', ['--dry-run' => true]);
        $this->assertStringContainsString('3 placeholder section numbers would be cleared', Artisan::output());
        $this->assertSame('00000', DB::table('market_report_comp_rows')->where('id', $blank1)->value('section_number'), 'a dry run writes nothing');

        Artisan::call('market-reports:backfill-comp-title-types');
        $out = Artisan::output();
        $this->assertStringContainsString('3 placeholder section numbers were cleared', $out);
        $this->assertStringContainsString('2 comp rows were given', $out);

        $row = DB::table('market_report_comp_rows')->where('id', $blank1)->first();
        $this->assertNull($row->section_number);
        $this->assertSame('Full Title', $row->property_type);
        $this->assertSame('report', json_decode((string) $row->raw_row_json, true)['title_type_source']);
        $this->assertNull(DB::table('market_report_comp_rows')->where('id', $untouched)->value('property_type'));

        Artisan::call('market-reports:backfill-comp-title-types');
        $again = Artisan::output();
        $this->assertStringContainsString('0 placeholder section numbers were cleared', $again);
        $this->assertStringContainsString('0 comp rows were given', $again);
    }

    // ── "0 of 0 mapped" says why ─────────────────────────────────────────

    public function test_empty_reason_names_the_title_type_cause_and_links_the_report(): void
    {
        $text = app(PresentationImportSummaryService::class)->emptyReason(
            ['sold_hydrated' => 0, 'active_hydrated' => 0, 'comps_parsed' => 25, 'report_ids' => [438]],
            ['pool_diagnostics' => ['n_candidates' => 25, 'n_after_type' => 0, 'n_selected' => 0]],
        );

        $this->assertStringContainsString('25 comparable sales read from the report', $text['text']);
        $this->assertStringContainsString('title type', $text['text']);
        $this->assertStringEndsWith('/reports/438', $text['url']);
    }

    public function test_empty_reason_is_silent_when_something_hydrated_or_nothing_was_read(): void
    {
        $svc = app(PresentationImportSummaryService::class);

        $this->assertNull($svc->emptyReason(['sold_hydrated' => 3, 'active_hydrated' => 0, 'comps_parsed' => 25, 'report_ids' => [1]], []));
        $this->assertNull($svc->emptyReason(['sold_hydrated' => 0, 'active_hydrated' => 0, 'comps_parsed' => 0, 'report_ids' => []], []));
    }

    public function test_review_screen_shows_the_reason_line_and_flags_unknown_title_types(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $presentation = $this->makePresentation($agencyId, $userId);
        $reportId = $this->seedReport($agencyId, $userId, $presentation->property_address);
        $this->seedCompRow($agencyId, $reportId, 1, ['property_type' => 'Full Title']);

        $version = PresentationVersion::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id, 'compiled_by' => $userId,
            'blueprint_version' => 'v1', 'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at' => now(), 'review_status' => PresentationVersion::REVIEW_AWAITING, 'awaiting_review_at' => now(),
            'hydration_summary_json' => ['excluded_by_type' => 0, 'pool_diagnostics' => ['n_candidates' => 1, 'n_after_type' => 0, 'n_selected' => 0]],
        ]);
        // Mark the comps as hydrated after the property last changed, or the screen re-hydrates and redirects.
        \App\Models\PresentationSnapshot::create([
            'presentation_id' => $presentation->id, 'generated_by_user_id' => $userId, 'created_by_user_id' => $userId,
            'computed_json' => '{}', 'snapshot_json' => '{}', 'generated_at' => now()->addHour(),
        ]);
        $user = User::find($userId);

        $html = $this->actingAs($user)->get(route('presentations.review.show', $version->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-empty-reason', $html);
        $this->assertStringContainsString('did not match this property', $html);
        $this->assertStringContainsString('/reports/' . $reportId, $html);

        PresentationSoldComp::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id, 'sold_date' => now()->subMonth()->toDateString(),
            'sold_price_inc' => 1_400_000, 'suburb' => 'Blue Valley Golf Estate', 'property_type' => null, 'size_m2' => 800,
            'raw_row_json' => json_encode(['address' => '8 Carlyke Street', 'title_type_unknown' => true, 'subject_match_used' => false]),
            'parser_version' => 'mic_snapshot',
        ]);
        $html = $this->actingAs($user)->get(route('presentations.review.show', $version->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-title-type-unknown', $html);
        $this->assertStringContainsString('data-cross-type="0"', $html, 'an unknown title type is not shown as a cross-title warning');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'TT-Test ' . Str::random(6), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);

        return [$agencyId, $user->id];
    }

    /** A Full Title subject in Blue Valley Golf Estate, asking R5m. */
    private function makePresentation(int $agencyId, int $userId): Presentation
    {
        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => '11 Manikie Street', 'address' => '11 Manikie Street', 'suburb' => 'Blue Valley Golf Estate',
            'latitude' => -25.88, 'longitude' => 28.12,
            'price' => 5_000_000, 'property_type' => 'House', 'category' => 'Residential', 'title_type' => 'full_title',
            'status' => 'active', 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $userId,
            'property_id' => $propertyId, 'title' => 'T',
            'property_address' => '11 Manikie Street, Blue Valley Golf Estate', 'suburb' => 'Blue Valley Golf Estate',
            'property_type' => 'House', 'status' => 'draft', 'currency' => 'ZAR',
        ]);

        return $presentation->load('property');
    }

    private function seedReport(int $agencyId, int $userId, string $subjectAddress): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id' => $agencyId, 'uploaded_by_user_id' => $userId,
            'file_path' => 'reports/' . Str::random(10) . '.pdf', 'file_name' => Str::random(8) . '.pdf',
            'file_hash' => Str::random(40), 'report_date' => now()->toDateString(),
            'subject_address' => $subjectAddress, 'source_suburb' => 'Blue Valley Golf Estate',
            'parse_status' => 'parsed', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $over */
    private function seedCompRow(int $agencyId, int $reportId, int $i, array $over = []): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId(array_merge([
            'agency_id' => $agencyId, 'market_report_id' => $reportId, 'row_index' => $i, 'row_type' => 'comp',
            'address' => "{$i} Carlyke Street", 'suburb_normalised' => 'blue valley golf estate',
            'property_type' => 'Full Title', 'scheme_name' => null, 'section_number' => null,
            'extent_m2' => 800, 'sale_date' => now()->subMonths($i)->toDateString(), 'sale_price' => 4_800_000 + ($i * 50_000),
            // Spread out: a comp sitting on the subject's own coordinates is treated as the subject itself.
            'latitude' => -25.88 - ($i * 0.0008), 'longitude' => 28.12 + ($i * 0.0008),
            'raw_row_json' => json_encode(['kind' => 'comp']),
            'is_demo' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }
}
