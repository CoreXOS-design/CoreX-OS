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
 * AT-500 (Johan, 22 Oct 2026) — a buyer whose deal falls through (declined), is cancelled (archived) or is
 * taken off the deal leaves Won and goes back to the active pipeline, unless they are on another live deal.
 * Audited as buyer_state_transitions.reason = 'deal_fell_through'. Spec: .ai/specs/buyer-pipeline.md.
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

    public function test_declining_the_deal_sends_its_won_buyer_back_to_the_active_pipeline(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);
        $this->assertSame('won', $buyer->fresh()->buyer_state);

        $deal->update(['accepted_status' => 'D']);

        $this->assertContains($buyer->fresh()->buyer_state, ['new', 'warm', 'cold'], 'back on the active board');
        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $buyer->id, 'from_state' => 'won', 'reason' => 'deal_fell_through',
        ]);
    }

    public function test_a_buyer_with_no_recent_activity_comes_back_as_cold_not_lost_and_the_nightly_job_leaves_them(): void
    {
        $buyer = $this->buyer();
        $buyer->updateQuietly(['last_activity_at' => now()->subDays(120)]); // idle far beyond the cold window
        $deal = $this->dealWithBuyer($buyer);

        $deal->update(['accepted_status' => 'D']);
        $this->assertSame('cold', $buyer->fresh()->buyer_state, 'never straight into Lost');

        Artisan::call('buyers:recompute-states');

        $this->assertSame('cold', $buyer->fresh()->buyer_state, 'the agent gets the usual window to work them before decay resumes');
    }

    public function test_a_buyer_on_another_live_deal_stays_won(): void
    {
        $buyer = $this->buyer();
        $first = $this->dealWithBuyer($buyer);
        $second = $this->dealWithBuyer($buyer);

        $first->update(['accepted_status' => 'D']);
        $this->assertSame('won', $buyer->fresh()->buyer_state, 'still on a live deal');

        $second->update(['accepted_status' => 'D']);
        $this->assertNotSame('won', $buyer->fresh()->buyer_state, 'now on no live deal');
    }

    public function test_archiving_a_deal_releases_its_buyer_and_restoring_it_makes_them_won_again(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);

        $deal->delete();
        $this->assertNotSame('won', $buyer->fresh()->buyer_state);

        $deal->restore();
        $this->assertSame('won', $buyer->fresh()->buyer_state);
    }

    public function test_reopening_a_declined_deal_makes_its_buyer_won_again(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealWithBuyer($buyer);
        $deal->update(['accepted_status' => 'D']);
        $this->assertNotSame('won', $buyer->fresh()->buyer_state);

        $deal->update(['accepted_status' => 'P']);

        $this->assertSame('won', $buyer->fresh()->buyer_state);
    }

    public function test_a_won_buyer_with_no_deal_at_all_is_not_touched_by_an_unrelated_deal_ending(): void
    {
        $viaPropertyPage = $this->buyer();
        app(BuyerStateService::class)->markWon($viaPropertyPage->fresh(), $this->agent->id); // no deal anywhere
        $other = $this->dealWithBuyer($this->buyer());

        $other->update(['accepted_status' => 'D']);

        $this->assertSame('won', $viaPropertyPage->fresh()->buyer_state);
    }

    public function test_a_declined_deal_does_not_make_anyone_won_in_the_first_place(): void
    {
        $buyer = $this->buyer();
        $deal = $this->makeDeal(['accepted_status' => 'D']);
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, app(BuyerStateService::class)->markBuyersWonForLiveDeal($deal->fresh(), [$buyer->id], $this->agent->id));
        $this->assertSame('warm', $buyer->fresh()->buyer_state);
    }

    public function test_a_buyer_returned_to_the_active_pipeline_is_matched_again_and_a_won_one_is_not(): void
    {
        $buyer = $this->buyer();
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ]);
        $inPlay = fn () => ContactMatch::withoutGlobalScopes()->buyerInPlay($this->agencyId)->where('contact_id', $buyer->id)->count();

        $this->assertSame(1, $inPlay(), 'active buyer: matched');

        $deal = $this->dealWithBuyer($buyer);
        $this->assertSame(0, $inPlay(), 'Won buyer: no Core Matches');
        $this->assertTrue(CoreMatchBuyerGate::isExcluded('won', $this->agencyId));

        $deal->update(['accepted_status' => 'D']);
        $this->assertSame(1, $inPlay(), 'fell through: matched again, saved search untouched');
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
