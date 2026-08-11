<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Models\ProspectingPriceAnomaly;
use App\Models\ProspectingPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MIC price guard (data-quality, 2026-08-10).
 *
 * A misparsed portal price (dropped zero / wrong figure grabbed) must NEVER
 * silently overwrite a good MIC price. An order-of-magnitude jump (factor >= 4)
 * vs the stored price is quarantined for review; the stored price is kept.
 * Normal market moves (±20%, credible cuts) still import.
 */
final class MicPriceGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role'      => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function payload(string $ref, int $price): array
    {
        return [
            'source'         => 'p24',
            'search_context' => [
                'url'            => 'https://www.property24.com/for-sale/uvongo/123',
                'search_term'    => 'Uvongo houses',
                'total_results'  => 1,
                'pages_captured' => 1,
            ],
            'listings' => [[
                'portal_ref' => $ref,
                'portal_url' => 'https://www.property24.com/for-sale/uvongo/' . ltrim($ref, 'P24-'),
                'address'    => '12 Marine Drive, Uvongo',
                'suburb'     => 'Uvongo',
                'price'      => $price,
            ]],
        ];
    }

    private function ref(): string
    {
        return 'P24-' . fake()->unique()->numberBetween(100000000, 199999999);
    }

    public function test_dropped_zero_overwrite_is_quarantined_and_good_price_kept(): void
    {
        $ref = $this->ref();
        $this->postJson('/api/prospecting/import', $this->payload($ref, 1_800_000))->assertOk();

        // Misparse: dropped a zero (1,800,000 -> 180,000) = 10x jump.
        $this->postJson('/api/prospecting/import', $this->payload($ref, 180_000))->assertOk();

        $listing = ProspectingListing::where('portal_ref', $ref)->firstOrFail();
        $this->assertSame(1_800_000, (int) $listing->price, 'Good price must be preserved');

        // Quarantined for review, not applied.
        $this->assertSame(1, ProspectingPriceAnomaly::where('prospecting_listing_id', $listing->id)->count());
        // No price-history row for the rejected change.
        $this->assertSame(0, ProspectingPriceHistory::where('prospecting_listing_id', $listing->id)->count());
    }

    public function test_wrong_larger_figure_is_also_quarantined(): void
    {
        $ref = $this->ref();
        $this->postJson('/api/prospecting/import', $this->payload($ref, 1_995_000))->assertOk();

        // Misparse: grabbed a larger figure (1,995,000 -> 10,800,000) = 5.4x jump.
        $this->postJson('/api/prospecting/import', $this->payload($ref, 10_800_000))->assertOk();

        $listing = ProspectingListing::where('portal_ref', $ref)->firstOrFail();
        $this->assertSame(1_995_000, (int) $listing->price);
        $this->assertSame(1, ProspectingPriceAnomaly::where('prospecting_listing_id', $listing->id)->count());
    }

    public function test_normal_price_move_still_applies(): void
    {
        $ref = $this->ref();
        $this->postJson('/api/prospecting/import', $this->payload($ref, 1_800_000))->assertOk();

        // Credible -12% reduction — must import normally.
        $this->postJson('/api/prospecting/import', $this->payload($ref, 1_584_000))->assertOk();

        $listing = ProspectingListing::where('portal_ref', $ref)->firstOrFail();
        $this->assertSame(1_584_000, (int) $listing->price, 'Real move must apply');
        $this->assertSame(0, ProspectingPriceAnomaly::where('prospecting_listing_id', $listing->id)->count());
        $this->assertSame(1, ProspectingPriceHistory::where('prospecting_listing_id', $listing->id)->count());
    }
}
