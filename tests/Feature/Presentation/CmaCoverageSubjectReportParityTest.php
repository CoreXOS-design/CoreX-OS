<?php

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\CmaCoverageService;
use App\Services\Presentations\PresentationImportSummaryService;
use App\Support\Presentations\SubjectReportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DECISION 2 (badge/hydrator parity) + DECISION 1 (import-confirmation summary).
 *
 * The coverage badge (CmaCoverageService::countComps) must mirror the
 * hydrator's same-subject exemption: comps from the subject's OWN
 * analyst-vetted market reports count regardless of the period window. An
 * agent who uploads a CMA full of 2019–2024 sectional sales must NOT see
 * "0 strong comps" while the engine silently hydrates them.
 *
 * Input paths proven:
 *   - same-subject comp OUTSIDE the date window → counted (the bug)
 *   - non-subject comp OUTSIDE the date window + wrong suburb → NOT counted
 *   - recent in-window suburb comp → still counted (regression guard)
 *   - SubjectReportResolver matches by address fragment AND by suburb
 *   - import summary reports real hydrated + mapped counts
 */
class CmaCoverageSubjectReportParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_subject_report_comps_count_even_outside_date_window(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject();

        // A market report whose subject IS this property (matched by suburb).
        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');

        // Three sectional sales from 2020 — far outside the 12-month window —
        // attached to the subject's own report.
        $this->seedCompRow($agencyId, $reportId, 'Brock Manor Unit 5', '2020-03-01', 1_400_000, 'testville');
        $this->seedCompRow($agencyId, $reportId, 'Brock Manor Unit 9', '2020-06-01', 1_500_000, 'testville');
        $this->seedCompRow($agencyId, $reportId, 'Brock Manor Unit 12', '2021-01-01', 1_600_000, 'testville');

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertGreaterThanOrEqual(3, $score['comp_count'],
            'subject-report comps must count regardless of the date window');
        $this->assertNotSame('none', $score['state'],
            'badge must not read "none" when the subject has analyst-vetted comps');
    }

    public function test_non_subject_old_comp_in_other_suburb_is_not_counted(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject();

        // A report for a DIFFERENT property in a DIFFERENT suburb.
        $otherReportId = $this->seedReport($agencyId, $userId, '99 Faraway Road', 'Elsewhere');
        $this->seedCompRow($agencyId, $otherReportId, 'Faraway Unit 1', '2020-03-01', 1_400_000, 'elsewhere');

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertSame(0, $score['comp_count'],
            'a stale comp from another suburb / non-subject report must not count');
    }

    public function test_recent_in_window_suburb_comp_still_counts(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject();

        // No subject report at all — just a recent suburb comp.
        $this->seedCompRow($agencyId, null, 'Recent Sale 1', now()->subMonths(2)->toDateString(), 1_350_000, 'testville');

        $score = (new CmaCoverageService())->scoreForProperty($property->fresh());

        $this->assertGreaterThanOrEqual(1, $score['comp_count'],
            'recent in-window suburb comps still count (regression guard)');
    }

    public function test_subject_report_resolver_matches_by_address_and_suburb(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject();

        $byAddress = $this->seedReport($agencyId, $userId, '1 SUBJECT WAY', null);
        $bySuburb  = $this->seedReport($agencyId, $userId, null, 'Testville');
        $unrelated = $this->seedReport($agencyId, $userId, '5 Nowhere Lane', 'Otherplace');

        $ids = SubjectReportResolver::resolveReportIds($agencyId, '1 Subject Way', 'Testville');

        $this->assertContains($byAddress, $ids);
        $this->assertContains($bySuburb, $ids);
        $this->assertNotContains($unrelated, $ids);
    }

    public function test_import_summary_reports_real_counts(): void
    {
        [$property, $agencyId, $userId] = $this->seedSubject();
        $presentation = $this->seedPresentation($agencyId, $userId, $property->id);

        $reportId = $this->seedReport($agencyId, $userId, '1 Subject Way', 'Testville');
        $this->seedCompRow($agencyId, $reportId, 'Brock Manor Unit 5', '2020-03-01', 1_400_000, 'testville');
        $this->seedCompRow($agencyId, $reportId, 'Brock Manor Unit 9', '2020-06-01', 1_500_000, 'testville');

        // Two hydrated sold comps — one with coords (mapped), one without.
        $this->seedSoldComp($agencyId, $presentation->id, 1_400_000, '2020-03-01', ['latitude' => -30.85, 'longitude' => 30.39]);
        $this->seedSoldComp($agencyId, $presentation->id, 1_500_000, '2020-06-01', []);
        // One active listing, mapped.
        $this->seedActiveListing($agencyId, $presentation->id, 1_700_000, ['latitude' => -30.84, 'longitude' => 30.40]);

        $summary = (new PresentationImportSummaryService())->build($presentation->fresh(['property']));

        $this->assertSame(1, $summary['reports_imported']);
        $this->assertSame(2, $summary['comps_parsed']);
        $this->assertSame(2, $summary['sold_hydrated']);
        $this->assertSame(1, $summary['active_hydrated']);
        $this->assertSame(2, $summary['mapped'], '2 of 3 hydrated rows carry coords');
        $this->assertSame(1, $summary['unmapped']);
    }

    // ── seed helpers ────────────────────────────────────────────────────

    /** @return array{0:Property,1:int,2:int} */
    private function seedSubject(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Parity ' . Str::random(6),
            'slug' => 'par-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        $property = Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'title'         => 'Subject',
            'property_type' => 'Sectional Title',
            'category'      => 'Residential',
            'suburb'        => 'Testville',
            'price'         => 1_900_000,
            'address'       => '1 Subject Way',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
        ]);

        return [$property, $agencyId, $user->id];
    }

    private function seedPresentation(int $agencyId, int $userId, int $propertyId): Presentation
    {
        return Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $propertyId,
            'created_by_user_id' => $userId,
            'title'              => 'Parity test',
            'property_address'   => '1 Subject Way',
            'suburb'             => 'Testville',
            'property_type'      => 'sectional',
            'asking_price_inc'   => 1_900_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function seedReport(int $agencyId, int $userId, ?string $subjectAddress, ?string $suburb): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $agencyId,
            'uploaded_by_user_id' => $userId,
            'file_path'           => 'reports/' . Str::random(10) . '.pdf',
            'file_name'           => Str::random(8) . '.pdf',
            'file_hash'           => Str::random(40),
            'report_date'         => now()->toDateString(),
            'subject_address'     => $subjectAddress,
            'source_suburb'       => $suburb,
            'parse_status'        => 'parsed',
            'is_demo'             => 0,
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }

    private function seedCompRow(int $agencyId, ?int $reportId, string $address, string $saleDate, int $salePrice, string $suburbNorm): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId([
            'agency_id'         => $agencyId,
            'market_report_id'  => $reportId,
            'row_index'         => 1,
            'row_type'          => 'comp',
            'address'           => $address,
            'suburb_normalised' => $suburbNorm,
            'property_type'     => 'Sectional Title',
            'sale_date'         => $saleDate,
            'sale_price'        => $salePrice,
            'is_demo'           => 0,
            'created_at'        => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSoldComp(int $agencyId, int $presentationId, int $price, string $soldDate, array $rawExtra): void
    {
        DB::table('presentation_sold_comps')->insert([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentationId,
            'sold_price_inc'  => $price,
            'sold_date'       => $soldDate,
            'property_type'   => 'sectional',
            'raw_row_json'    => json_encode($rawExtra ?: ['note' => 'no-geo']),
            'parser_version'  => 'test',
            'is_demo'         => 0,
            'created_at'      => now(),
        ]);
    }

    private function seedActiveListing(int $agencyId, int $presentationId, int $price, array $rawExtra): void
    {
        DB::table('presentation_active_listings')->insert([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentationId,
            'list_price_inc'  => $price,
            'status'          => 'active',
            'is_active'       => 1,
            'raw_row_json'    => json_encode($rawExtra ?: ['note' => 'no-geo']),
            'parser_version'  => 'test',
            'created_at'      => now(),
        ]);
    }
}
