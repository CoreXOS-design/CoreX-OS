<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\CommissionLedger;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deal completion → CommissionLedger. Closes the gap documented in
 * .ai/atlas/deals-commission.md §8.1 ("System C commission engine is orphaned") —
 * before GenerateCommissionLedgerEntries, nothing ever created a CommissionLedger row,
 * so every agent's cap-progress / revenue-share dashboard read empty forever.
 *
 * total_commission = 115,000 (incl 15% VAT) → 100,000 ex VAT, split 50/50 listing/selling
 * by default → each side pool = 50,000 ex VAT. Chosen so the VAT math is exact and the
 * expected numbers below aren't fighting floating-point rounding.
 */
final class DealCommissionFinalisedGeneratesLedgerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'CL Co', 'slug' => 'cl-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent',
        ]);
    }

    private function makeDeal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 9999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 2_000_000, 'total_commission' => 115_000,
        ], $over));
    }

    private function markPaid(Deal $deal): void
    {
        $deal->update(['commission_status' => 'Paid']);
    }

    private function ledgerFor(Deal $deal, int $userId): ?CommissionLedger
    {
        return CommissionLedger::where('deal_id', $deal->id)->where('user_id', $userId)->first();
    }

    public function test_listing_and_selling_agent_each_get_their_own_side(): void
    {
        $listingAgent = $this->agent();
        $sellingAgent = $this->agent();
        $deal = $this->makeDeal();
        $deal->agents()->attach($listingAgent->id, ['side' => 'listing', 'agent_split_percent' => 100]);
        $deal->agents()->attach($sellingAgent->id, ['side' => 'selling', 'agent_split_percent' => 100]);

        $this->markPaid($deal);

        $listingEntry = $this->ledgerFor($deal, $listingAgent->id);
        $sellingEntry = $this->ledgerFor($deal, $sellingAgent->id);

        $this->assertNotNull($listingEntry);
        $this->assertNotNull($sellingEntry);
        $this->assertSame('50000.00', $listingEntry->commission_excl_vat);
        $this->assertSame('50000.00', $sellingEntry->commission_excl_vat);
        $this->assertSame('57500.00', $listingEntry->gross_commission);
        $this->assertSame('7500.00', $listingEntry->vat_amount);
        $this->assertSame($this->agencyId, (int) $listingEntry->agency_id);
        $this->assertSame('sale', $listingEntry->transaction_type);
    }

    public function test_co_agents_on_one_side_split_without_double_counting(): void
    {
        $sellerA = $this->agent();
        $sellerB = $this->agent();
        $deal = $this->makeDeal();
        $deal->agents()->attach($sellerA->id, ['side' => 'selling', 'agent_split_percent' => 50]);
        $deal->agents()->attach($sellerB->id, ['side' => 'selling', 'agent_split_percent' => 50]);

        $this->markPaid($deal);

        $entryA = $this->ledgerFor($deal, $sellerA->id);
        $entryB = $this->ledgerFor($deal, $sellerB->id);

        $this->assertSame('25000.00', $entryA->commission_excl_vat);
        $this->assertSame('25000.00', $entryB->commission_excl_vat);
        // Together they must equal the full side pool, never more — the double-count risk
        // this listener specifically guards against.
        $this->assertSame(50000.0, (float) $entryA->commission_excl_vat + (float) $entryB->commission_excl_vat);
    }

    public function test_dual_mandate_agent_gets_one_summed_entry_not_two(): void
    {
        $agent = $this->agent();
        $deal = $this->makeDeal();
        $deal->agents()->attach($agent->id, ['side' => 'listing', 'agent_split_percent' => 100]);
        $deal->agents()->attach($agent->id, ['side' => 'selling', 'agent_split_percent' => 100]);

        $this->markPaid($deal);

        $entries = CommissionLedger::where('deal_id', $deal->id)->where('user_id', $agent->id)->get();
        $this->assertCount(1, $entries, 'one agent on both sides of one deal must produce ONE ledger row');
        $this->assertSame('100000.00', $entries->first()->commission_excl_vat);
    }

    public function test_reopening_and_repaying_does_not_double_post(): void
    {
        $agent = $this->agent();
        $deal = $this->makeDeal();
        $deal->agents()->attach($agent->id, ['side' => 'listing', 'agent_split_percent' => 100]);

        $this->markPaid($deal);
        $this->assertCount(1, CommissionLedger::where('deal_id', $deal->id)->get());

        $deal->update(['commission_status' => 'Not Paid']);
        $this->markPaid($deal);

        $this->assertCount(1, CommissionLedger::where('deal_id', $deal->id)->get(), 're-firing must be idempotent');
    }

    public function test_external_side_produces_no_entry_for_that_side(): void
    {
        $listingAgent = $this->agent();
        $sellingAgent = $this->agent();
        $deal = $this->makeDeal(['listing_external' => 1]);
        $deal->agents()->attach($listingAgent->id, ['side' => 'listing', 'agent_split_percent' => 100]);
        $deal->agents()->attach($sellingAgent->id, ['side' => 'selling', 'agent_split_percent' => 100]);

        $this->markPaid($deal);

        $this->assertNull($this->ledgerFor($deal, $listingAgent->id), 'external listing side earns us nothing');
        $this->assertNotNull($this->ledgerFor($deal, $sellingAgent->id));
    }

    public function test_no_agents_on_deal_is_a_noop(): void
    {
        $deal = $this->makeDeal();
        $this->markPaid($deal);
        $this->assertCount(0, CommissionLedger::where('deal_id', $deal->id)->get());
    }
}
