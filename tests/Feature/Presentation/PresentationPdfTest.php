<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance tests for P18 — PDF (HTML) pack generation and download.
 */
class PresentationPdfTest extends TestCase
{
    use RefreshDatabase;

    private User                $user;
    private Branch              $branch;
    private Presentation        $presentation;
    private PresentationVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PresentationPdfService::STORAGE_DISK);

        $this->branch = Branch::create([
            'name'      => 'Test Branch',
            'code'      => 'TEST',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'PDF Test Presentation',
            'property_address'   => '12 Ocean View Drive',
            'suburb'             => 'Sea Point',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
            'seller_name'        => 'John Seller',
        ]);

        $this->version = PresentationVersion::create([
            'presentation_id'  => $this->presentation->id,
            'compiled_by'      => $this->user->id,
            'blueprint_version' => 'v1',
            'compiled_at'      => now(),
            'data_snapshot_json' => json_encode([
                'presentation' => [
                    'title'            => 'PDF Test Presentation',
                    'property_address' => '12 Ocean View Drive',
                    'suburb'           => 'Sea Point',
                    'property_type'    => 'house',
                    'seller_name'      => 'John Seller',
                    'currency'         => 'ZAR',
                ],
                'analytics' => [
                    'p60'                      => 0.72,
                    'p30'                      => 0.45,
                    'p90'                      => 0.88,
                    'expected_days'            => 38,
                    'months_of_inventory'      => 2.1,
                    'demand_supply_ratio'      => 0.85,
                    'price_per_sqm_deviation_pct' => -3.2,
                    'dom_p25'                  => 22,
                    'dom_p50'                  => 35,
                    'dom_p75'                  => 58,
                ],
                'evidence' => [
                    'sold_comps_count'      => 5,
                    'active_listings_count' => 3,
                    'upload_count'          => 2,
                    'links_count'           => 1,
                ],
                'holding_cost' => [
                    'monthly_total'       => 15000.0,
                    'six_month_total'     => 90000.0,
                    'twelve_month_total'  => 180000.0,
                ],
                'sections' => [
                    ['key' => 'market_overview', 'title' => 'Market Overview'],
                    ['key' => 'recommendations', 'title' => 'Recommendations'],
                ],
                'articles' => [],
            ]),
        ]);
    }

    // ── Feature flag guard ────────────────────────────────────────────────────

    public function test_download_returns_404_when_flag_off(): void
    {
        Config::set('features.presentation_pdf_v1', false);
        $this->actingAs($this->user);

        $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]))
            ->assertNotFound();
    }

    // ── Authentication ────────────────────────────────────────────────────────

    public function test_download_requires_auth(): void
    {
        Config::set('features.presentation_pdf_v1', true);

        $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]))
            ->assertRedirect('/login');
    }

    // ── Successful generation ─────────────────────────────────────────────────

    public function test_download_returns_200_when_flag_on(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_html_contains_executive_summary(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('Executive Summary', $content);
    }

    public function test_html_contains_evidence_appendix(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $content  = $response->streamedContent();

        $this->assertStringContainsString('Evidence', $content);
        $this->assertStringContainsString('Sold comparables', $content);
    }

    public function test_html_contains_presentation_address(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $content  = $response->streamedContent();

        $this->assertStringContainsString('12 Ocean View Drive', $content);
    }

    public function test_html_contains_holding_cost_section(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $content  = $response->streamedContent();

        $this->assertStringContainsString('Holding Cost', $content);
        $this->assertStringContainsString('15,000.00', $content);
    }

    public function test_html_contains_market_analytics_section(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $content  = $response->streamedContent();

        $this->assertStringContainsString('Market Analytics', $content);
    }

    // ── File persisted to storage ─────────────────────────────────────────────

    public function test_pack_file_is_stored_after_download(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]))
            ->assertOk();

        $service = new PresentationPdfService();
        Storage::disk(PresentationPdfService::STORAGE_DISK)
            ->assertExists($service->storagePath($this->version));
    }

    // ── Version / presentation mismatch ──────────────────────────────────────

    public function test_download_404_when_version_belongs_to_different_presentation(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $other = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Other Presentation',
            'property_type'      => 'house',
            'status'             => 'draft',
        ]);

        // Use $other (different presentation) but $this->version (belongs to $this->presentation)
        $this->get(route('presentations.versions.pdf', [$other, $this->version]))
            ->assertNotFound();
    }

    // ── File served from cache when already exists ────────────────────────────

    public function test_existing_pack_file_served_without_regenerating(): void
    {
        Config::set('features.presentation_pdf_v1', true);
        $this->actingAs($this->user);

        $service = new PresentationPdfService();
        $path    = $service->storagePath($this->version);

        // Pre-populate the file with known sentinel content
        Storage::disk(PresentationPdfService::STORAGE_DISK)->put($path, '<html><body>CACHED</body></html>');

        $response = $this->get(route('presentations.versions.pdf', [$this->presentation, $this->version]));
        $content  = $response->streamedContent();

        // Should serve the cached file — not regenerate
        $this->assertStringContainsString('CACHED', $content);
    }
}
