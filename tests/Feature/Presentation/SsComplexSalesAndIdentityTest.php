<?php

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SS presentation enhancements:
 *
 *   A. Subject identity — show complex + unit ("Unit 17, Brock Manor, Margate")
 *      instead of the street name, triggered by data presence (complex_name AND
 *      unit_number both populated), with a flat-address fallback.
 *
 *   B. Comparable sales — sectional ("complex") sales split into their own
 *      group when the agency toggle ss_show_complex_section is on (default),
 *      and folded back into the vicinity group when it is off so they are never
 *      lost.
 *
 * Input paths proven here: complex+unit present (A happy), neither present
 * (A fallback), toggle ON (B separate), toggle OFF (B fold-back), and the
 * no-sectional-comps path (no empty complex group consumer).
 */
class SsComplexSalesAndIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_scheme_comps_split_into_own_complex_group_when_toggle_on(): void
    {
        // Complex membership is SAME-SCHEME ONLY: the comp's scheme_name must
        // match the subject's scheme (Property.complex_name). A sectional comp
        // is NOT enough — it must be the SUBJECT'S scheme.
        $agencyId   = $this->seedAgency(); // ss_show_complex_section defaults to true
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => 'Brock Manor', 'unit_number' => '17']);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01', [
            'scheme_name' => 'Brock Manor',
        ]);

        $data  = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $comps = $data['comparable_sales'];

        $this->assertCount(1, $comps['vicinity']['rows'], 'full-title sale stays in vicinity');
        $this->assertSame('10 Beach Road', $comps['vicinity']['rows'][0]['address']);

        $this->assertCount(1, $comps['complex']['rows'], 'same-scheme sale lands in its own complex group');
        $this->assertSame('Brock Manor (Unit 5)', $comps['complex']['rows'][0]['address']);
        $this->assertSame(1_500_000, $comps['complex']['avg_price']);
    }

    public function test_different_scheme_sectional_comp_stays_in_vicinity(): void
    {
        // The pres-98 bug: a sectional comp from a DIFFERENT scheme (Loscona)
        // must NOT appear in the subject's (Pumula) complex section. Only the
        // same-scheme comp belongs there; everything else is vicinity.
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => 'Pumula', 'unit_number' => '12']);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        // Same scheme as the subject → complex.
        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Pumula, Section 9', 1_500_000, 80, '2025-05-01', [
            'scheme_name' => 'Pumula', 'section_number' => '9',
        ]);
        // DIFFERENT sectional schemes → vicinity, NOT complex.
        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Loscona, Section 12', 1_200_000, 70, '2025-04-01', [
            'scheme_name' => 'Loscona', 'section_number' => '12',
        ]);
        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Suntide Cabanas, Section 13', 1_300_000, 75, '2025-03-01', [
            'scheme_name' => 'Suntide Cabanas', 'section_number' => '13',
        ]);
        // Freehold area sale → vicinity.
        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', '10 Beach Road', 2_000_000, 100, '2025-06-01');

        $comps = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']))['comparable_sales'];

        $this->assertCount(1, $comps['complex']['rows'], 'ONLY the Pumula-scheme comp is in the complex group');
        $this->assertSame('Unit 9, Pumula', $comps['complex']['rows'][0]['address']);

        $this->assertCount(3, $comps['vicinity']['rows'], 'Loscona + Suntide + freehold are all vicinity');
        $vicAddresses = array_column($comps['vicinity']['rows'], 'address');
        $this->assertContains('Unit 12, Loscona', $vicAddresses);
        $this->assertContains('Unit 13, Suntide Cabanas', $vicAddresses);
        $this->assertContains('10 Beach Road', $vicAddresses);
    }

    public function test_scheme_match_is_case_insensitive_and_trimmed(): void
    {
        // Subject scheme "PUMULA" must match a comp scheme " pumula " regardless
        // of case / surrounding whitespace.
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => 'PUMULA', 'unit_number' => '12']);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Pumula, Section 9', 1_500_000, 80, '2025-05-01', [
            'scheme_name' => '  pumula  ', 'section_number' => '9',
        ]);

        $comps = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']))['comparable_sales'];

        $this->assertCount(1, $comps['complex']['rows'], 'case/whitespace-insensitive scheme match → complex');
        $this->assertEmpty($comps['vicinity']['rows']);
    }

    public function test_same_scheme_comps_fold_into_vicinity_when_toggle_off(): void
    {
        $agencyId   = $this->seedAgency();
        DB::table('agencies')->where('id', $agencyId)->update(['ss_show_complex_section' => false]);
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => 'Brock Manor', 'unit_number' => '17']);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01', [
            'scheme_name' => 'Brock Manor',
        ]);

        $data  = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $comps = $data['comparable_sales'];

        $this->assertCount(2, $comps['vicinity']['rows'], 'same-scheme folds back into vicinity when suppressed');
        $this->assertEmpty($comps['complex']['rows'], 'no separate complex group when suppressed');
        $addresses = array_column($comps['vicinity']['rows'], 'address');
        $this->assertContains('Brock Manor (Unit 5)', $addresses);
    }

    public function test_schemeless_comp_stays_in_vicinity_even_when_subject_has_scheme(): void
    {
        // The known parser gap: a real same-complex sale whose scheme_name was
        // lost at capture (NULL) can NOT match — it stays an honest vicinity
        // sale until re-imported with the scheme populated. Proves the complex
        // group never guesses membership from anything but a real scheme match.
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => 'Pumula', 'unit_number' => '12']);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        // Section present but scheme_name NULL (the capture gap) → vicinity.
        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Section 976, Margate', 1_400_000, 78, '2025-05-01', [
            'section_number' => '976',
        ]);

        $comps = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']))['comparable_sales'];

        $this->assertEmpty($comps['complex']['rows'], 'schemeless comp can NOT enter the complex group');
        $this->assertCount(1, $comps['vicinity']['rows'], 'it remains an honest vicinity sale');
    }

    public function test_subject_without_scheme_yields_no_complex_group(): void
    {
        // Freehold subject (no complex_name) → nothing can match → complex empty.
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, ['complex_name' => null, 'unit_number' => null]);
        $presentation = $this->seedPresentation($agencyId, $propertyId);

        $this->seedComp($agencyId, $presentation->id, 'mic_snapshot', 'Pumula, Section 9', 1_500_000, 80, '2025-05-01', [
            'scheme_name' => 'Pumula', 'section_number' => '9',
        ]);

        $comps = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']))['comparable_sales'];

        $this->assertEmpty($comps['complex']['rows'], 'schemeless subject → no complex group');
        $this->assertCount(1, $comps['vicinity']['rows']);
    }

    public function test_subject_display_address_uses_complex_and_unit_when_both_present(): void
    {
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, [
            'complex_name' => 'Brock Manor',
            'unit_number'  => '17',
            'suburb'       => 'Margate',
            'address'      => '17 Some Street', // street is present but must be overridden
        ]);
        $presentation = $this->seedPresentation($agencyId, $propertyId, '17 Some Street, Margate');

        $subject = (new AnalysisDataService())
            ->compile($presentation->fresh(['fields', 'property']))['subject_property'];

        $this->assertSame('Unit 17, Brock Manor, Margate', $subject['display_address']);
        $this->assertSame('Brock Manor', $subject['complex_name']);
        // Raw address key is preserved for comp-exclusion matching.
        $this->assertSame('17 Some Street, Margate', $subject['address']);
    }

    public function test_subject_display_address_falls_back_to_street_when_no_complex_unit(): void
    {
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, [
            'complex_name' => null,
            'unit_number'  => null,
            'suburb'       => 'Margate',
            'address'      => '12 Marine Drive',
        ]);
        $presentation = $this->seedPresentation($agencyId, $propertyId, '12 Marine Drive, Margate');

        $subject = (new AnalysisDataService())
            ->compile($presentation->fresh(['fields', 'property']))['subject_property'];

        $this->assertSame('12 Marine Drive, Margate', $subject['display_address'],
            'no complex+unit → display address is the flat street address');
    }

    public function test_unit_without_complex_does_not_trigger_complex_display(): void
    {
        // Data-presence trigger requires BOTH columns — one alone falls back.
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, [
            'complex_name' => null,
            'unit_number'  => '17',
            'suburb'       => 'Margate',
            'address'      => '17 Marine Drive',
        ]);
        $presentation = $this->seedPresentation($agencyId, $propertyId, '17 Marine Drive, Margate');

        $subject = (new AnalysisDataService())
            ->compile($presentation->fresh(['fields', 'property']))['subject_property'];

        $this->assertSame('17 Marine Drive, Margate', $subject['display_address']);
    }

    // ── C. PDF render gate — empty vicinity surface suppression ───────────

    /**
     * (b) Vicinity EMPTY + complex populated → the top-level "Recent Sales Near
     * Your Property" header + empty vicinity table + empty-state callout are all
     * suppressed; the complex section ("Recent sales in {complex}") stands alone
     * as the sales section. Render-layer gate keyed on row counts, not title_type.
     */
    public function test_pdf_hides_empty_vicinity_surface_when_only_complex_sales_exist(): void
    {
        $agencyId   = $this->seedAgency(); // ss_show_complex_section defaults on
        $propertyId = $this->seedProperty($agencyId, [
            'complex_name' => 'Brock Manor', 'unit_number' => '17',
            'suburb' => 'Margate', 'address' => '17 Marine Drive',
        ]);
        $presentation = $this->seedPresentation($agencyId, $propertyId, '17 Marine Drive, Margate');

        // ONLY same-scheme comps → vicinity group empty, complex group populated.
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01', ['scheme_name' => 'Brock Manor']);
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 8)', 1_650_000, 85, '2025-04-01', ['scheme_name' => 'Brock Manor']);

        $html = $this->renderPdfFor($presentation);

        $this->assertStringNotContainsString('Recent Sales Near Your Property', $html,
            'orphan top-level vicinity header must be suppressed for a pure sectional subject');
        $this->assertStringNotContainsString('No vicinity sales data available', $html,
            'empty-state callout must NOT appear when the complex section carries the sales');
        $this->assertStringContainsString('Recent sales in Brock Manor', $html,
            'complex section stands alone as the sales section');
    }

    /**
     * (a) Vicinity populated + complex populated → both sections render (unchanged).
     */
    public function test_pdf_shows_both_sections_when_vicinity_and_complex_populated(): void
    {
        $agencyId   = $this->seedAgency();
        $propertyId = $this->seedProperty($agencyId, [
            'complex_name' => 'Brock Manor', 'unit_number' => '17',
            'suburb' => 'Margate', 'address' => '17 Marine Drive',
        ]);
        $presentation = $this->seedPresentation($agencyId, $propertyId, '17 Marine Drive, Margate');

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01', ['scheme_name' => 'Brock Manor']);

        $html = $this->renderPdfFor($presentation);

        $this->assertStringContainsString('Recent Sales Near Your Property', $html, 'vicinity header shows when vicinity populated');
        $this->assertStringContainsString('Recent sales in Brock Manor', $html, 'complex section shows when complex populated');
        $this->assertStringNotContainsString('No vicinity sales data available', $html);
    }

    /**
     * (c) Vicinity populated + complex empty → only the vicinity section renders
     * (unchanged); no complex block.
     */
    public function test_pdf_shows_only_vicinity_when_complex_empty(): void
    {
        $agencyId     = $this->seedAgency();
        $presentation = $this->seedPresentation($agencyId);

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');

        $html = $this->renderPdfFor($presentation);

        $this->assertStringContainsString('Recent Sales Near Your Property', $html, 'vicinity header shows when vicinity populated');
        $this->assertStringNotContainsString('Recent sales in the complex', $html, 'no complex block when complex group empty');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Compile the presentation into the frozen blob and render the PDF HTML the
     * same way buildHtml() does in production (reads $version->snapshot_payload).
     */
    private function renderPdfFor(Presentation $presentation): string
    {
        $data    = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $version = PresentationVersion::create([
            'agency_id'          => $presentation->agency_id,
            'presentation_id'    => $presentation->id,
            'compiled_by'        => $presentation->created_by_user_id,
            'blueprint_version'  => 'v1',
            'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at'        => now(),
            'review_status'      => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'       => now(),
            'snapshot_payload'   => $data,
            'snapshot_taken_at'  => now(),
        ]);

        return (new PresentationPdfService())->buildHtml($version->fresh());
    }

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'SS-Test ' . Str::random(6),
            'slug' => 'ss-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }

    private function seedProperty(int $agencyId, array $overrides = []): int
    {
        $agentId = \App\Models\User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
        ])->id;

        return (int) DB::table('properties')->insertGetId(array_merge([
            'external_id'   => 'TEST-' . Str::random(8),
            'title'         => 'Test',
            'address'       => '1 Test',
            'suburb'        => 'Test',
            'latitude'      => -30.84,
            'longitude'     => 30.39,
            'price'         => 1_200_000,
            'property_type' => 'sectional',
            'status'        => 'active',
            'is_demo'       => false,
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agentId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ], $overrides));
    }

    private function seedPresentation(int $agencyId, ?int $propertyId = null, string $address = '1 Test'): Presentation
    {
        return Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => \App\Models\User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId])->id,
            'property_id'        => $propertyId,
            'title'              => 'T',
            'property_address'   => $address,
            'suburb'             => 'Margate',
            'property_type'      => 'sectional',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    /** @param  array<string,mixed>  $rawExtra  extra raw_row_json keys (e.g. scheme_name/section_number) */
    private function seedComp(int $agencyId, int $presentationId, string $source, string $address, int $price, int $sizeM2, string $date, array $rawExtra = []): void
    {
        PresentationSoldComp::create([
            'agency_id'       => $agencyId,
            'presentation_id' => $presentationId,
            'sold_date'       => $date,
            'sold_price_inc'  => $price,
            'suburb'          => 'Margate',
            'property_type'   => $source === 'vicinity_sales_sectional' ? 'Sectional' : 'House',
            'size_m2'         => $sizeM2,
            'parser_version'  => 'test_v1',
            'raw_row_json'    => json_encode(array_merge([
                'source'       => $source,
                'address'      => $address,
                'extent_m2'    => $sizeM2,
                'sale_price'   => $price,
                'price_per_m2' => (int) round($price / max(1, $sizeM2)),
            ], $rawExtra)),
            'is_demo'         => false,
        ]);
    }
}
