<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Models\User;
use App\Services\BuyerStateService;
use App\Services\Matching\CoreMatchBuyerGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 (Johan, 22 Oct 2026) — what happens to a Won buyer when their deal ends (unless they are on another live deal):
 *   Declined (fell through)          -> Lost, audited 'deal_fell_through'; re-opened -> Won again.
 *   Removed from the deal / archived -> an agent correction: back to New/Warm/Cold, audited 'deal_corrected'.
 *   A Lost buyer named on a live deal -> Won (Lost never blocks linking).
 * Spec: .ai/specs/buyer-pipeline.md.
 */
final class BuyerWonFallsThroughTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($this->agent);
    }

    // Johan, 22 Oct 2026: DECLINED (fell through) -> Lost; REMOVED / ARCHIVED (an agent correction) -> back to the board.

    public function test_declining_the_deal_sends_its_won_buyer_to_lost_audited_as_deal_fell_through(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);
        $this->assertSame('won', $buyer->fresh()->buyer_state);

        $deal->update(['accepted_status' => 'D']);

        $this->assertSame('lost', $buyer->fresh()->buyer_state, 'the purchase did not happen: Lost, not back on the board');
        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $buyer->id, 'from_state' => 'won', 'to_state' => 'lost', 'reason' => 'deal_fell_through',
        ]);
    }

    public function test_a_buyer_on_another_live_deal_stays_won_when_one_deal_is_declined(): void
    {
        $buyer = $this->buyer();
        $first = $this->dealWithBuyer($buyer);
        $second = $this->dealWithBuyer($buyer);

        $first->update(['accepted_status' => 'D']);
        $this->assertSame('won', $buyer->fresh()->buyer_state, 'still on a live deal');

        $second->update(['accepted_status' => 'D']);
        $this->assertSame('lost', $buyer->fresh()->buyer_state, 'now on no live deal and the last one fell through');
    }

    public function test_reopening_the_declined_deal_makes_the_lost_buyer_won_again(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);
        $deal->update(['accepted_status' => 'D']);
        $this->assertSame('lost', $buyer->fresh()->buyer_state);

        $deal->update(['accepted_status' => 'P']);

        $this->assertSame('won', $buyer->fresh()->buyer_state);
    }

    public function test_a_lost_buyer_named_on_a_live_deal_becomes_won_lost_never_blocks_linking(): void
    {
        $buyer = $this->buyer();
        $buyer->updateQuietly(['buyer_state' => 'lost']);                       // already Lost (manual or decayed)
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(1, app(BuyerStateService::class)->markBuyersWonForLiveDeal($deal, [$buyer->id], $this->agent->id));

        $this->assertSame('won', $buyer->fresh()->buyer_state);
    }

    public function test_archiving_a_deal_is_a_correction_the_buyer_goes_back_to_the_board_not_lost(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);

        $deal->delete();

        $this->assertContains($buyer->fresh()->buyer_state, ['new', 'warm', 'cold'], 'back on the board');
        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $buyer->id, 'from_state' => 'won', 'reason' => 'deal_corrected',
        ]);

        $deal->restore();
        $this->assertSame('won', $buyer->fresh()->buyer_state, 'restoring the deal makes them Won again');
    }

    public function test_a_corrected_buyer_with_no_recent_activity_comes_back_as_cold_not_lost_and_the_nightly_job_leaves_them(): void
    {
        $buyer = $this->buyer();
        $buyer->updateQuietly(['last_activity_at' => now()->subDays(120)]); // idle far beyond the cold window
        $deal = $this->dealWithBuyer($buyer);

        $deal->delete();
        $this->assertSame('cold', $buyer->fresh()->buyer_state, 'never straight into Lost on a correction');

        Artisan::call('buyers:recompute-states');

        $this->assertSame('cold', $buyer->fresh()->buyer_state, 'the agent gets the usual window to work them before decay resumes');
    }

    public function test_a_won_buyer_with_no_deal_at_all_is_not_touched_by_an_unrelated_deal_ending(): void
    {
        $handMade = $this->buyer();
        app(BuyerStateService::class)->markWon($handMade->fresh(), $this->agent->id); // no deal anywhere
        $other = $this->dealWithBuyer($this->buyer());

        $other->update(['accepted_status' => 'D']);

        $this->assertSame('won', $handMade->fresh()->buyer_state);
    }

    public function test_a_declined_deal_does_not_make_anyone_won_in_the_first_place(): void
    {
        $buyer = $this->buyer();
        $deal = $this->makeDeal(['accepted_status' => 'D']);
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, app(BuyerStateService::class)->markBuyersWonForLiveDeal($deal->fresh(), [$buyer->id], $this->agent->id));
        $this->assertSame('warm', $buyer->fresh()->buyer_state);
    }

    public function test_core_matches_follow_won_lost_and_the_board(): void
    {
        $buyer = $this->buyer();
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ]);
        $inPlay = fn () => ContactMatch::withoutGlobalScopes()->buyerInPlay($this->agencyId)->where('contact_id', $buyer->id)->count();

        $this->assertSame(1, $inPlay(), 'active buyer: matched');

        $deal = $this->dealWithBuyer($buyer);
        $this->assertSame(0, $inPlay(), 'Won: no Core Matches');
        $this->assertTrue(CoreMatchBuyerGate::isExcluded('won', $this->agencyId));

        $deal->update(['accepted_status' => 'D']);
        $this->assertSame(0, $inPlay(), 'Lost (deal fell through): still no Core Matches');

        $deal->update(['accepted_status' => 'P']);
        $this->assertSame(0, $inPlay(), 'Won again');
        $deal->delete();
        $this->assertSame(1, $inPlay(), 'corrected back to the board: matched again, saved search untouched');
        $this->assertSame(ContactMatch::STATUS_ACTIVE, ContactMatch::withoutGlobalScopes()->where('contact_id', $buyer->id)->value('status'));
    }

    // ── fixtures ──

    private function buyer(): Contact
    {
        $c = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'is_buyer' => true, 'buyer_state' => 'warm', 'last_activity_at' => now()->subDays(2),
            'first_name' => 'Elsa', 'last_name' => 'Buyer ' . Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'elsa-' . Str::random(5) . '@example.co.za',
        ]);

        return $c;
    }

    private function makeDeal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'deal_no' => (string) random_int(8000, 8999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $over));
    }

    /** A live deal with $buyer as its buyer party, marked Won the way the DR2 save does it. */
    private function dealWithBuyer(Contact $buyer): Deal
    {
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        app(BuyerStateService::class)->markBuyersWonForLiveDeal($deal, [$buyer->id], $this->agent->id);

        return $deal;
    }
}
