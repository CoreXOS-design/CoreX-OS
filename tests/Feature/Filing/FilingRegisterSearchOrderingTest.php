<?php

declare(strict_types=1);

namespace Tests\Feature\Filing;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Filing\FilingPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-404 (Johan, live-testing, 2026-09-10) — "34 Marine Drive" (property 5274, active,
 * open mandate) did not come up when an agent typed "34 marine" into new-filing property
 * search, while withdrawn 2017/2018 mandates and prospecting-status junk for the same
 * street did. Root cause: FilingPropertyLinker::candidates() had no ORDER BY at all — with
 * more matches than the 15-row limit, MySQL returned them in effectively ascending-id
 * order, and the agency's decade of dead stock has lower ids than its current listings.
 *
 * Fix: order on-market stock (Property::OFF_MARKET_STATUSES — the existing canonical
 * definition, not a new status list) ahead of everything else, most-recent-id first within
 * each tier. These tests pin that a real active listing surfaces even when heavily
 * outnumbered by matching dead stock, and that dead-stock-only searches still work.
 */
final class FilingRegisterSearchOrderingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->admin  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->admin->id,
            'branch_id'     => $this->branch->id,
            'title'         => 'Test listing',
            'address'       => '34 Marine Drive',
            'suburb'        => 'St Michaels On Sea',
            'status'        => 'active',
            'property_type' => 'House',
            'price'         => 1_290_000,
        ], $overrides));
    }

    /**
     * The exact repro: a real active listing must outrank a pile of withdrawn/expired/
     * prospecting matches for the same street, not merely be present somewhere past the
     * limit. Sixteen dead-stock rows (one more than the 15-row page size) are created with
     * LOWER ids than the real listing, mirroring the live-testing shape (thousands of old
     * imported rows, a few hundred current ones) — before the fix this alone was enough to
     * push the active listing off the end of the result set.
     */
    public function test_an_active_listing_outranks_a_pile_of_dead_stock_for_the_same_street(): void
    {
        foreach (range(1, 16) as $i) {
            $this->property([
                'title'   => "Dead stock {$i}",
                'address' => "{$i} Marine Drive",
                'status'  => $i % 2 === 0 ? 'withdrawn' : 'expired',
            ]);
        }
        $active = $this->property(['title' => 'The real listing']);

        $results = app(FilingPropertyLinker::class)->candidates('34 marine', $this->admin);

        $this->assertTrue($results->pluck('id')->contains($active->id), 'the active listing must be in the result set at all');
        $this->assertSame($active->id, $results->first()->id, 'the active listing must rank FIRST, not merely survive the limit');
    }

    /** A search that only matches dead stock must still return it — this is ranking, not a new filter. */
    public function test_a_search_matching_only_dead_stock_still_returns_it(): void
    {
        $withdrawn = $this->property(['address' => '99 Lagoon Drive', 'status' => 'withdrawn']);

        $results = app(FilingPropertyLinker::class)->candidates('99 lagoon', $this->admin);

        $this->assertTrue($results->pluck('id')->contains($withdrawn->id));
    }

    /** Two active listings on the same street: the more recently created one leads. */
    public function test_among_equally_ranked_matches_the_most_recent_leads(): void
    {
        $older = $this->property(['address' => '5 Beacon Road']);
        $newer = $this->property(['address' => '7 Beacon Road']);

        $results = app(FilingPropertyLinker::class)->candidates('beacon road', $this->admin);

        $this->assertSame($newer->id, $results->first()->id, 'higher id (more recently created) ranks first within the same tier');
        $this->assertSame($older->id, $results->get(1)->id);
    }
}
