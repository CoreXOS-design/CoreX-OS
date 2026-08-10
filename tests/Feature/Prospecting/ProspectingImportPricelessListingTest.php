<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\ProspectingPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Live bug fix: POST /api/prospecting/import threw a 500 "Column 'price'
 * cannot be null" whenever a captured page contained a price-less listing
 * (vacant land / "POA" on P24 — Margate has plenty). A 500 aborts the WHOLE
 * batch, so every listing in that batch failed to land — the root cause of
 * "disappearing batches" and the false "removed" listings in the Margate
 * integrity test (siblings didn't sell; the batch died on a priceless row).
 *
 * price-less listings must import cleanly as "price on application" (NULL),
 * and must not affect listings that DO have a price.
 */
final class ProspectingImportPricelessListingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function payload(array $listings): array
    {
        return [
            'source'         => 'p24',
            'search_context' => [
                'url'            => 'https://www.property24.com/for-sale/margate/1/p1',
                'search_term'    => 'Margate for sale',
                'total_results'  => count($listings),
                'pages_captured' => 1,
            ],
            'listings' => $listings,
        ];
    }

    public function test_batch_with_priceless_listing_imports_all_rows_no_500(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/prospecting/import', $this->payload([
            [
                'portal_ref' => 'P24-1001',
                'address'    => '12 Marine Drive',
                'suburb'     => 'Margate',
                'price'      => 1850000,
                'portal_url' => 'https://www.property24.com/listing/1001',
            ],
            [
                // POA / vacant land — no price field sent by the portal at all.
                'portal_ref' => 'P24-1002',
                'address'    => 'Erf 4021 Vacant Land',
                'suburb'     => 'Margate',
                'price'      => null,
                'portal_url' => 'https://www.property24.com/listing/1002',
            ],
            [
                'portal_ref' => 'P24-1003',
                'address'    => '9 Marine Drive',
                'suburb'     => 'Margate',
                'price'      => 2100000,
                'portal_url' => 'https://www.property24.com/listing/1003',
            ],
        ]));

        $response->assertOk();
        $response->assertJson([
            'success'  => true,
            'imported' => 3,
            'total'    => 3,
        ]);

        $this->assertSame(3, ProspectingListing::where('agency_id', $this->agency->id)->count());

        $poa = ProspectingListing::where('agency_id', $this->agency->id)
            ->where('portal_ref', 'P24-1002')
            ->firstOrFail();
        $this->assertNull($poa->price);

        $priced = ProspectingListing::where('agency_id', $this->agency->id)
            ->where('portal_ref', 'P24-1001')
            ->firstOrFail();
        $this->assertSame(1850000, $priced->price);
    }

    public function test_priced_listing_price_change_still_records_history(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/prospecting/import', $this->payload([
            [
                'portal_ref' => 'P24-2001',
                'address'    => '5 Beach Road',
                'suburb'     => 'Margate',
                'price'      => 1500000,
                'portal_url' => 'https://www.property24.com/listing/2001',
            ],
        ]))->assertOk();

        $this->postJson('/api/v1/prospecting/import', $this->payload([
            [
                'portal_ref' => 'P24-2001',
                'address'    => '5 Beach Road',
                'suburb'     => 'Margate',
                'price'      => 1450000,
                'portal_url' => 'https://www.property24.com/listing/2001',
            ],
        ]))->assertOk();

        $listing = ProspectingListing::where('agency_id', $this->agency->id)
            ->where('portal_ref', 'P24-2001')
            ->firstOrFail();

        $this->assertSame(1450000, $listing->price);
        $this->assertSame(1, ProspectingPriceHistory::where('prospecting_listing_id', $listing->id)->count());

        $history = ProspectingPriceHistory::where('prospecting_listing_id', $listing->id)->firstOrFail();
        $this->assertSame(1500000, $history->old_price);
        $this->assertSame(1450000, $history->new_price);
    }

    public function test_listing_dropping_to_poa_on_recapture_records_null_history_no_crash(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/prospecting/import', $this->payload([
            [
                'portal_ref' => 'P24-3001',
                'address'    => '77 Golf Estate',
                'suburb'     => 'Margate',
                'price'      => 3200000,
                'portal_url' => 'https://www.property24.com/listing/3001',
            ],
        ]))->assertOk();

        $response = $this->postJson('/api/v1/prospecting/import', $this->payload([
            [
                'portal_ref' => 'P24-3001',
                'address'    => '77 Golf Estate',
                'suburb'     => 'Margate',
                'price'      => null,
                'portal_url' => 'https://www.property24.com/listing/3001',
            ],
        ]));

        $response->assertOk();
        $response->assertJson(['success' => true, 'updated' => 1]);

        $listing = ProspectingListing::where('agency_id', $this->agency->id)
            ->where('portal_ref', 'P24-3001')
            ->firstOrFail();
        $this->assertNull($listing->price);

        $history = ProspectingPriceHistory::where('prospecting_listing_id', $listing->id)->firstOrFail();
        $this->assertSame(3200000, $history->old_price);
        $this->assertNull($history->new_price);
    }
}
