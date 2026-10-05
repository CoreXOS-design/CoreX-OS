<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-20 (Johan, reported for a meeting) — the Buyers Pipeline kanban
 * view's header count badges did not honour the Sales/Rentals (?lead_type=)
 * filter, while the kanban COLUMNS did. Symptom exactly as reported: "the
 * scroll bar sizes changed, but the counts at the top of each list stays
 * the same." Root cause: BuyerPipelineController::index()'s kanban branch
 * called stateCounts($user, $pipelineScope) — omitting the $leadType
 * argument the list view's equivalent call already passed correctly.
 *
 * Also covers a second bug found in the same investigation: the kanban
 * branch never passed 'leadType' to the view at all, so the Sales/Rentals
 * button's active-highlight always showed "All" as selected regardless of
 * the real filter.
 */
final class BuyerPipelineKanbanCountsMatchFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_kanban_header_counts_match_the_filtered_columns_for_all_sale_and_rental(): void
    {
        [$admin] = $this->scenarioWithSaleAndRentalBuyers();

        $all = $this->kanbanData($admin, null);
        $sale = $this->kanbanData($admin, 'sale');
        $rental = $this->kanbanData($admin, 'rental');

        // Unfiltered: counts and columns agree, and both are non-trivial (proves
        // the fixture actually created buyers in more than one state, so an
        // untouched/always-equal assertion couldn't pass by coincidence).
        $this->assertSame(2, array_sum($all['columnLengths']), 'fixture sanity: 2 buyers total');
        $this->assertColumnsMatchCounts($all);

        // Sale: the whole point of the regression — before the fix these counts
        // stayed at the unfiltered totals while the columns shrank.
        $this->assertColumnsMatchCounts($sale);
        $this->assertSame(1, array_sum($sale['columnLengths']), 'exactly the one sale buyer should be on the sale board');
        $this->assertNotEquals($all['counts'], $sale['counts'], 'sale header counts must differ from the unfiltered totals');

        // Rental: same check, opposite filter.
        $this->assertColumnsMatchCounts($rental);
        $this->assertSame(1, array_sum($rental['columnLengths']), 'exactly the one rental buyer should be on the rental board');
        $this->assertNotEquals($all['counts'], $rental['counts'], 'rental header counts must differ from the unfiltered totals');

        // Second bug: leadType must reach the view so the active button highlights correctly.
        $this->assertNull($all['leadType']);
        $this->assertSame('sale', $sale['leadType']);
        $this->assertSame('rental', $rental['leadType']);
    }

    private function assertColumnsMatchCounts(array $data): void
    {
        foreach (['new', 'warm', 'cold', 'lost'] as $state) {
            $this->assertSame(
                $data['columnLengths'][$state],
                $data['counts'][$state] ?? 0,
                "header count for '{$state}' must equal the actual column length"
            );
        }
    }

    /** @return array{0: User} */
    private function scenarioWithSaleAndRentalBuyers(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin', 'name' => 'The Admin',
        ]);

        $saleBuyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Sale', 'last_name' => 'Buyer',
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'sale-' . Str::random(5) . '@example.co.za',
            'agent_id' => $admin->id,
        ]);
        ContactMatch::create(['contact_id' => $saleBuyer->id, 'listing_type' => 'sale']);

        $rentalBuyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'is_buyer' => true, 'buyer_state' => 'lost',
            'first_name' => 'Rental', 'last_name' => 'Tenant',
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'rental-' . Str::random(5) . '@example.co.za',
            'agent_id' => $admin->id,
        ]);
        ContactMatch::create(['contact_id' => $rentalBuyer->id, 'listing_type' => 'rental']);

        return [$admin];
    }

    /**
     * Invoke the controller directly (kanban, default view) and return the
     * header counts, actual per-column lengths, and the leadType passed to
     * the view.
     */
    private function kanbanData(User $viewer, ?string $leadType): array
    {
        $this->actingAs($viewer);
        $params = ['view' => 'kanban', 'scope' => 'agency'];
        if ($leadType) {
            $params['lead_type'] = $leadType;
        }
        $request = \Illuminate\Http\Request::create('/corex/command-center/buyers/pipeline', 'GET', $params);
        $request->setUserResolver(fn () => $viewer);

        $view = app(\App\Http\Controllers\CommandCenter\BuyerPipelineController::class)->index($request);
        $data = $view->getData();

        $columnLengths = [];
        foreach ($data['columns'] as $state => $col) {
            $columnLengths[$state] = $col->count();
        }

        return [
            'counts' => $data['counts'],
            'columnLengths' => $columnLengths,
            'leadType' => array_key_exists('leadType', $data) ? $data['leadType'] : 'MISSING',
        ];
    }
}
