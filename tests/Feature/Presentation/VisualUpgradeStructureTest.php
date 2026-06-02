<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationField;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 6 — visual upgrade structural assertions.
 *
 * Cannot render in a browser from here, so this test verifies the
 * structural markers that the new visual layer introduces:
 *
 *   - The hero block uses the new hero-shell + hero-image elements
 *     and Page 1 carries no data tables / no chart canvases.
 *   - The CMA gauge SVG is present when bands exist.
 *   - The holding cost callout renders as a single bold number
 *     (hc-number class) — not a 10-row monthly table.
 *   - Active competition uses card markup (comp-grid + comp-card),
 *     not a text table.
 *   - The agent footer block is present.
 *   - The 3-beat dividers fire when their toggleable sections are on.
 *   - Chart.js is referenced (the suburb-trend chart canvas).
 *   - Plus Jakarta Sans is NOT used (SSOT keeps Figtree).
 *   - No 2-3px border-radius rules (SSOT standardises rounded-md 6px).
 *
 * Visual fidelity (layout at 375px, hover states, actual chart paint)
 * needs human eyes — covered separately.
 */
final class VisualUpgradeStructureTest extends TestCase
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

    public function test_hero_block_renders_with_no_data_or_charts(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk();
        // Hero shell + image present.
        $resp->assertSee('class="hero-shell"', false);
        $resp->assertSee('class="hero-image"', false);
        $resp->assertSee('class="hero-title"', false);

        // The hero-meta block has NO canvas inside it (the suburb trend
        // chart is far below in Beat 1).
        $html = $resp->getContent();
        $heroEnd = strpos($html, '</header>');
        $this->assertNotFalse($heroEnd, 'hero <header> must exist');
        $heroSlice = substr($html, 0, $heroEnd);
        $this->assertStringNotContainsString('<canvas', $heroSlice, 'Page 1 / hero must contain zero canvas elements');
        $this->assertStringNotContainsString('<table', $heroSlice, 'Page 1 / hero must contain zero tables');
    }

    public function test_cma_gauge_svg_renders_when_bands_present(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id, [
            'cma_valuation' => [
                'cma_lower'  => 1_500_000,
                'cma_middle' => 1_830_000,
                'cma_upper'  => 2_160_000,
                'asking_price' => 1_900_000,
            ],
        ]);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()
            ->assertSee('class="cma-gauge"', false)
            ->assertSee('<svg', false)
            ->assertSee('Your asking price', false);
    }

    public function test_holding_cost_renders_as_callout_not_table(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id, [
            'holding_cost' => [
                'monthly_total'  => 4267,
                'projected_12m'  => 51_204,
                'breakdown'      => ['bond_payment' => 2500, 'rates' => 800, 'levies' => 600, 'opportunity_cost' => 367],
            ],
        ]);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk()
            ->assertSee('class="hc-number"', false)
            ->assertSee('class="holding-callout"', false)
            // Disclosure is collapsed by default.
            ->assertSee('Show breakdown', false);
    }

    public function test_active_competition_renders_as_card_grid(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        // Build 8 — the comp-grid renders the legacy active_competition.rows
        // as audit display under the canonical headline. Seed BOTH the
        // scored competitor_stock (drives the headline count) and the
        // legacy rows (drive the grid). Both pipelines coexist post-B0b.
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id, [
            'active_competition' => [
                'count' => 3,
                'rows'  => [
                    ['address' => '1 Main Rd',  'list_price' => 2_100_000, 'days_on_market' => 12, 'property_type' => 'house'],
                    ['address' => '2 Beach Dr', 'list_price' => 2_400_000, 'days_on_market' => 45, 'property_type' => 'house'],
                    ['address' => '3 Sea View', 'list_price' => 2_900_000, 'days_on_market' => 78, 'property_type' => 'house'],
                ],
            ],
            'competitor_stock' => [
                'visible'         => [
                    ['listing_id' => 1, 'address' => '1 Main Rd',  'price' => 2_100_000, 'score' => 95, 'tier' => 'strong'],
                    ['listing_id' => 2, 'address' => '2 Beach Dr', 'price' => 2_400_000, 'score' => 80, 'tier' => 'approximate'],
                    ['listing_id' => 3, 'address' => '3 Sea View', 'price' => 2_900_000, 'score' => 65, 'tier' => 'approximate'],
                ],
                'competing_count' => 3,
            ],
        ]);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()
            ->assertSee('class="comp-grid"', false)
            ->assertSee('class="comp-card"', false)
            ->assertDontSee('<th>Address</th><th class="num">List price</th>', false);
    }

    public function test_agent_footer_present(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()
            ->assertSee('class="agent-footer"', false)
            ->assertSee('class="af-actions"', false);
    }

    public function test_beat_dividers_fire_when_sections_enabled(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id, [
            'cma_valuation' => ['cma_lower' => 1_000_000, 'cma_middle' => 1_500_000, 'cma_upper' => 2_000_000],
            'active_competition' => ['count' => 2, 'rows' => [['address' => 'a', 'list_price' => 1_000_000], ['address' => 'b', 'list_price' => 1_100_000]]],
            'competitor_stock' => ['visible' => [
                ['listing_id' => 1, 'address' => 'a', 'price' => 1_000_000, 'score' => 90, 'tier' => 'strong'],
                ['listing_id' => 2, 'address' => 'b', 'price' => 1_100_000, 'score' => 85, 'tier' => 'strong'],
            ], 'competing_count' => 2],
        ]);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk()
            ->assertSee("What's happening here", false)
            ->assertSee("What you're up against", false)
            ->assertSee('What it means for you', false);
    }

    public function test_chart_js_loaded_only_when_vicinity_data_exists(): void
    {
        // With vicinity rows in the snapshot, the Chart.js script tag fires.
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id, [
            'comparable_sales' => [
                'vicinity' => [
                    'count' => 2,
                    'rows'  => [
                        ['address' => '1 A St', 'sale_date' => '2025-01-15', 'sale_price' => 1_000_000],
                        ['address' => '2 B St', 'sale_date' => '2025-03-20', 'sale_price' => 1_200_000],
                    ],
                ],
            ],
        ]);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()
            ->assertSee('cdn.jsdelivr.net/npm/chart.js', false)
            ->assertSee('id="suburb-trend-chart"', false);
    }

    public function test_no_plus_jakarta_no_2px_corners(): void
    {
        // SSOT enforcement: the visual upgrade keeps Figtree + 6px corners.
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedVersionWithSnapshot($agencyId, $user->id);
        $link = $this->seedShareLink($agencyId, $user->id, $version);

        $resp = $this->get(route('presentation.public.show', $link->token));
        $resp->assertOk();
        $html = $resp->getContent();

        $this->assertStringNotContainsString('Plus Jakarta', $html, 'SSOT keeps Figtree; Plus Jakarta is a known bug.');
        $this->assertStringContainsString('figtree:', $html, 'Figtree must be loaded from Bunny.');
        // No raw 2px / 3px border-radius rules in the SSOT-token-styled CSS.
        $this->assertStringNotContainsString('border-radius: 2px', $html, 'SSOT standardises rounded-md (6px); 2px is forbidden.');
        $this->assertStringNotContainsString('border-radius: 3px', $html, 'SSOT standardises rounded-md (6px); 3px is forbidden.');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    /** Seed a published version with a pre-built snapshot_payload —
     *  bypasses the publish flow so tests can pin the rendered data
     *  exactly. */
    private function seedVersionWithSnapshot(int $agencyId, int $userId, array $payloadOverrides = []): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $userId,
            'title'              => 'Visual Test',
            'property_address'   => '1 Robberg Road, Manaba Beach',
            'suburb'             => 'Manaba Beach',
            'property_type'      => 'house',
            'bedrooms'           => 3,
            'bathrooms'          => 2,
            'erf_size_m2'        => 800,
            'asking_price_inc'   => 1_900_000,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        $defaultPayload = [
            'subject_property' => ['address' => '1 Robberg Road', 'suburb' => 'Manaba Beach'],
            'cma_valuation'    => [],
            'comparable_sales' => ['vicinity' => ['count' => 0, 'rows' => []]],
            'active_competition' => ['count' => 0, 'rows' => []],
            'competitor_stock' => ['visible' => [], 'competing_count' => 0, 'price_position_canonical' => ['has_data' => false]],
            'stock_absorption' => [],
            'holding_cost'     => null,
        ];
        $payload = array_replace_recursive($defaultPayload, $payloadOverrides);

        return PresentationVersion::create([
            'agency_id'         => $agencyId,
            'presentation_id'   => $presentation->id,
            'compiled_by'       => $userId,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'      => now(),
            'snapshot_payload'  => $payload,
            'snapshot_taken_at' => now(),
        ]);
    }

    private function seedShareLink(int $agencyId, int $userId, PresentationVersion $version): PresentationSnapshotLink
    {
        return PresentationSnapshotLink::create([
            'agency_id'              => $agencyId,
            'presentation_id'        => $version->presentation_id,
            'presentation_version_id'=> $version->id,
            'created_by_user_id'     => $userId,
            'token'                  => Str::random(48),
            'mode'                   => 'full',
            'expires_at'             => now()->addYear(),
        ]);
    }
}
