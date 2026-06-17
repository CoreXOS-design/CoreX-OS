<?php

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Services\Presentations\AnalysisDataService;
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

    public function test_sectional_comps_split_into_own_complex_group_when_toggle_on(): void
    {
        $agencyId = $this->seedAgency(); // ss_show_complex_section defaults to true
        $presentation = $this->seedPresentation($agencyId);

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01');

        $data  = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $comps = $data['comparable_sales'];

        $this->assertCount(1, $comps['vicinity']['rows'], 'full-title sale stays in vicinity');
        $this->assertSame('10 Beach Road', $comps['vicinity']['rows'][0]['address']);

        $this->assertCount(1, $comps['complex']['rows'], 'sectional sale lands in its own complex group');
        $this->assertSame('Brock Manor (Unit 5)', $comps['complex']['rows'][0]['address']);
        $this->assertSame(1_500_000, $comps['complex']['avg_price']);
    }

    public function test_sectional_comps_fold_into_vicinity_when_toggle_off(): void
    {
        $agencyId = $this->seedAgency();
        DB::table('agencies')->where('id', $agencyId)->update(['ss_show_complex_section' => false]);
        $presentation = $this->seedPresentation($agencyId);

        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales', '10 Beach Road', 2_000_000, 100, '2025-06-01');
        $this->seedComp($agencyId, $presentation->id, 'vicinity_sales_sectional', 'Brock Manor (Unit 5)', 1_500_000, 80, '2025-05-01');

        $data  = (new AnalysisDataService())->compile($presentation->fresh(['fields', 'property']));
        $comps = $data['comparable_sales'];

        $this->assertCount(2, $comps['vicinity']['rows'], 'sectional folds back into vicinity when suppressed');
        $this->assertEmpty($comps['complex']['rows'], 'no separate complex group when suppressed');
        $addresses = array_column($comps['vicinity']['rows'], 'address');
        $this->assertContains('Brock Manor (Unit 5)', $addresses);
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

    // ── Helpers ──────────────────────────────────────────────────────────

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

    private function seedComp(int $agencyId, int $presentationId, string $source, string $address, int $price, int $sizeM2, string $date): void
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
            'raw_row_json'    => json_encode([
                'source'       => $source,
                'address'      => $address,
                'extent_m2'    => $sizeM2,
                'sale_price'   => $price,
                'price_per_m2' => (int) round($price / max(1, $sizeM2)),
            ]),
            'is_demo'         => false,
        ]);
    }
}
