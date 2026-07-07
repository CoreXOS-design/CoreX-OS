<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Services\Prospecting\ProspectingActionPresetService;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the 2026-07-07 "This Week tiles — buttons don't work" fix.
 *
 * Two invariants:
 *  1. A tile's headline count equals the number of ROWS its link lands on — the
 *     count runs the SAME preset query as the Work-tab list AND collapses
 *     cross-listed duplicates the same way (groupBy property_group_id). A raw
 *     row count over-reports, so a tile would promise N and land on fewer.
 *  2. Tile action URLs are RELATIVE (host-agnostic). The nightly warm job runs
 *     with no request host; an absolute URL there bakes APP_URL's host into the
 *     cache and sends agents on the other domain cross-site (logged out) — which
 *     is why the buttons "did nothing".
 */
final class ThisWeekTileConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $capturerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'A' . Str::random(5), 'slug' => 'a-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->capturerId = User::factory()->create([
            'agency_id' => $this->agencyId, 'role' => 'agent', 'is_active' => 1,
        ])->id;
    }

    /** groupedCount collapses cross-listed duplicates — 3 rows, 2 groups → count 2, not 3. */
    public function test_preset_count_collapses_cross_listed_duplicates(): void
    {
        $this->listing(['property_group_id' => 777]);  // ┐ one cross-listed
        $this->listing(['property_group_id' => 777]);  // ┘ property (2 portals)
        $this->listing(['property_group_id' => null]); // a standalone listing

        $thresholds = app(ProspectingConfigurationService::class)->getSuggestedActionThresholds($this->agencyId);
        $count = app(ProspectingActionPresetService::class)
            ->countForPreset($this->agencyId, null, 'new_today', $thresholds);

        $this->assertSame(2, $count, '3 listings across 2 groups must count as 2 rows, not 3');
    }

    /** Every tile the builder emits links with a RELATIVE, host-agnostic URL. */
    public function test_tile_action_urls_are_relative(): void
    {
        // Seed enough new-today canvass listings to guarantee the "new_listings" tile.
        for ($i = 0; $i < 3; $i++) {
            $this->listing(['property_group_id' => null]);
        }
        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'role' => 'agent', 'is_active' => 1,
        ]);

        $tiles = app(\App\Services\MarketIntelligence\ThisWeekTileBuilder::class)->buildFor($agent);

        $this->assertNotEmpty($tiles, 'at least the new-listings tile should surface');
        foreach ($tiles as $tile) {
            $this->assertStringStartsWith('/', $tile->actionUrl, "tile {$tile->id} URL must be relative");
            $this->assertStringNotContainsString('://', $tile->actionUrl, "tile {$tile->id} URL must not be absolute");
        }
    }

    private function listing(array $overrides = []): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id'           => $this->agencyId,
            'captured_by_user_id' => $this->capturerId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'REF-' . Str::random(8),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'address'             => Str::random(10) . ' Street',
            'suburb'              => 'Testville',
            'price'               => 1500000,
            'bedrooms'            => 2,
            'is_active'           => true,
            'matched_property_id' => null,
            'first_seen_at'       => now()->subHours(2),
            'last_seen_at'        => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));
    }
}
