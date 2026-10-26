<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportType;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Caprivi LOOM import audit (2026-10-26):
 * The sale price / date / plot size of every imported sale never reached its tracked property.
 */
final class ReportSaleCarriedToPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_imported_sale_reaches_its_tracked_property_with_price_date_and_plot_size(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        Storage::fake('local');

        $this->actingAs(User::find($userId))
            ->post(route('market-intelligence.reports.store'), [
                'file' => $this->fixtureFile(), 'report_type_id' => $this->vicinityType()->id,
            ])->assertRedirect();

        $withSale = TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNotNull('last_known_sold_price')->count();
        $this->assertGreaterThan(0, $withSale, 'sale price must travel from the report to the tracked property');
    }

    public function test_ai_read_comparable_carries_plot_size_by_title_type(): void
    {
        $mapper = new CanonicalReadMapper();
        $report = new MarketReport(['source_suburb' => 'Blue Valley Golf Estate', 'source_town' => 'Centurion']);
        $clean  = ['comps' => [
            ['kind' => 'comp', 'address' => '8 Carlyke Street', 'property_type' => 'Full Title', 'extent_m2' => 880, 'sale_price' => 4200000, 'sale_date' => '2025-11-18'],
            ['kind' => 'comp', 'address' => '2 Sectional Court', 'property_type' => 'Sectional Title', 'extent_m2' => 95, 'sale_price' => 1500000, 'sale_date' => '2025-01-01'],
            ['kind' => 'comp', 'address' => '3 Unknown Road', 'property_type' => null, 'extent_m2' => 500, 'sale_price' => 1000000, 'sale_date' => '2025-01-01'],
        ]];
        $addr = $mapper->toParseResult($clean, $report)->extractedAddresses;

        $this->assertSame(880, $addr[0]['erf_size_m2']);
        $this->assertArrayNotHasKey('section_extent_m2', $addr[0]);
        $this->assertSame(95, $addr[1]['section_extent_m2']);
        $this->assertArrayNotHasKey('erf_size_m2', $addr[1]);
        $this->assertArrayNotHasKey('erf_size_m2', $addr[2]);
        $this->assertArrayNotHasKey('section_extent_m2', $addr[2]);
    }

    public function test_an_older_imported_sale_never_replaces_a_newer_sale_already_on_file(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $svc   = app(TrackedPropertyMatchOrCreateService::class);
        $facts = ['street_number' => '8', 'street_name' => 'Carlyke Street', 'suburb' => 'Blue Valley Golf Estate', 'town' => 'Centurion'];

        $tp = $svc->matchOrCreate($agencyId, $facts + ['last_known_sold_price' => 4200000, 'last_known_sold_date' => '2025-11-18'], ['type' => 'deeds_capture', 'ref' => 'd1'], $userId);

        $older = $svc->matchOrCreate($agencyId, $facts + ['last_known_sold_price' => 1210000, 'last_known_sold_date' => '2020-02-04'], ['type' => 'cmainfo', 'ref' => 'report:1'], $userId);
        $this->assertSame($tp->id, $older->id);
        $this->assertSame(4200000.0, (float) $older->last_known_sold_price);
        $this->assertSame('2025-11-18', $older->last_known_sold_date->toDateString());

        $undated = $svc->matchOrCreate($agencyId, $facts + ['last_known_sold_price' => 999], ['type' => 'cmainfo', 'ref' => 'report:2'], $userId);
        $this->assertSame(4200000.0, (float) $undated->last_known_sold_price);

        $newer = $svc->matchOrCreate($agencyId, $facts + ['last_known_sold_price' => 5000000, 'last_known_sold_date' => '2026-03-01'], ['type' => 'cmainfo', 'ref' => 'report:3'], $userId);
        $this->assertSame(5000000.0, (float) $newer->last_known_sold_price);
        $this->assertSame('2026-03-01', $newer->last_known_sold_date->toDateString());
    }

    private function fixtureFile(): UploadedFile
    {
        $name = 'cma_info_vicinity_sale_residential.pdf';

        return new UploadedFile(base_path('tests/Fixtures/market_reports/' . $name), $name, 'application/pdf', null, true);
    }

    private function vicinityType(): MarketReportType
    {
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

    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);

        return [$agencyId, $user->id];
    }
}
