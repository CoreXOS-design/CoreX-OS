<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Services\BuyerStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 (Johan, 22 Oct 2026: "update the nightly as well so we don't have the stuff-up again") - the 04:00
 * buyers:recompute-states follows the full Won / Lost rule set through BuyerStateService: it never moves a Won
 * or Lost buyer into New/Warm/Cold, it self-heals against live DR2 deals every night, audits every change with
 * a reason, prints a one-line summary, and a second run changes nothing.
 */
final class BuyerNightlyFollowsTheRulesTest extends TestCase
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

    public function test_a_won_buyer_and_a_deal_declined_lost_buyer_survive_the_nightly(): void
    {
        $won = $this->buyer('warm');
        $wonDeal = $this->dealWithBuyer($won);                       // Won on a live deal
        $lost = $this->buyer('warm');
        $declined = $this->dealWithBuyer($lost);
        $declined->update(['accepted_status' => 'D']);               // fell through -> Lost
        $this->assertSame('lost', $lost->fresh()->buyer_state);
        $lost->updateQuietly(['last_activity_at' => now()->subDay()]); // recent activity: the OLD nightly would have revived them to Warm

        Artisan::call('buyers:recompute-states');

        $this->assertSame('won', $won->fresh()->buyer_state);
        $this->assertSame('lost', $lost->fresh()->buyer_state, 'a Lost buyer is never moved into New/Warm/Cold by the nightly');
        $this->assertSame(0, DB::table('buyer_state_transitions')->whereIn('contact_id', [$won->id, $lost->id])->where('reason', 'auto_recompute')->count());
    }

    public function test_a_manually_lost_buyer_with_recent_activity_is_not_revived(): void
    {
        $manual = $this->buyer('warm');
        app(BuyerStateService::class)->transitionTo($manual->fresh(), 'lost', 'manual_override', $this->agent->id);
        $manual->updateQuietly(['last_activity_at' => now()]);

        Artisan::call('buyers:recompute-states');

        $this->assertSame('lost', $manual->fresh()->buyer_state);
    }

    public function test_a_buyer_on_a_live_deal_who_missed_the_event_is_healed_to_won_and_audited(): void
    {
        $missed = $this->buyer('cold');
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $missed->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        $lostOnLive = $this->buyer('lost');                           // Lost never blocks a live deal
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $lostOnLive->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('buyers:recompute-states');

        $this->assertSame('won', $missed->fresh()->buyer_state);
        $this->assertSame('won', $lostOnLive->fresh()->buyer_state);
        $this->assertDatabaseHas('buyer_state_transitions', ['contact_id' => $missed->id, 'from_state' => 'cold', 'to_state' => 'won']);
        $this->assertStringContainsString('moved_to_won=2', Artisan::output());
    }

    public function test_won_buyers_with_no_basis_are_healed_lost_if_the_deal_fell_through_else_back_to_the_board_and_a_manual_won_stays(): void
    {
        $states = app(BuyerStateService::class);

        $missedDecline = $this->buyer('warm');
        $deal = $this->dealWithBuyer($missedDecline);
        DB::table('deals')->where('id', $deal->id)->update(['accepted_status' => 'D']); // declined WITHOUT the observer (missed event)

        $oldRule = $this->buyer('warm');
        $states->markWon($oldRule->fresh(), null);                                       // Won by the old link rule, no deal

        $manual = $this->buyer('warm');
        $states->transitionTo($manual->fresh(), 'won', 'manual_override', $this->agent->id); // Won by hand

        Artisan::call('buyers:recompute-states');
        $out = Artisan::output();

        $this->assertSame('lost', $missedDecline->fresh()->buyer_state);
        $this->assertDatabaseHas('buyer_state_transitions', ['contact_id' => $missedDecline->id, 'from_state' => 'won', 'to_state' => 'lost', 'reason' => 'deal_fell_through']);
        $this->assertContains($oldRule->fresh()->buyer_state, ['new', 'warm', 'cold']);
        $this->assertDatabaseHas('buyer_state_transitions', ['contact_id' => $oldRule->id, 'from_state' => 'won', 'reason' => 'won_without_live_deal']);
        $this->assertSame('won', $manual->fresh()->buyer_state, 'a hand-made Won is never undone');
        $this->assertStringContainsString('moved_to_lost=1 back_to_board=1', $out);
    }

    public function test_running_the_nightly_twice_the_second_run_changes_nothing(): void
    {
        $w = $this->buyer('warm');
        $this->dealWithBuyer($w);
        $missed = $this->buyer('cold');
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $missed->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        $stale = $this->buyer('warm');
        $stale->updateQuietly(['last_activity_at' => now()->subDays(90)]);
        $old = $this->buyer('warm');
        app(BuyerStateService::class)->markWon($old->fresh(), null);

        Artisan::call('buyers:recompute-states');
        $firstRunTransitions = DB::table('buyer_state_transitions')->count();
        $states = Contact::withoutGlobalScopes()->orderBy('id')->pluck('buyer_state', 'id')->all();

        Artisan::call('buyers:recompute-states');
        $second = Artisan::output();

        $this->assertSame($firstRunTransitions, DB::table('buyer_state_transitions')->count(), 'no new audit rows on the second run');
        $this->assertSame($states, Contact::withoutGlobalScopes()->orderBy('id')->pluck('buyer_state', 'id')->all(), 'no buyer moved');
        $this->assertStringContainsString('moved_to_won=0 moved_to_lost=0 back_to_board=0 recomputed=0', $second);
    }

    public function test_the_dry_run_writes_nothing_and_prints_the_summary(): void
    {
        $missed = $this->buyer('cold');
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $missed->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('buyers:recompute-states', ['--dry-run' => true]);

        $this->assertSame('cold', $missed->fresh()->buyer_state);
        $this->assertStringContainsString('(DRY RUN) summary: moved_to_won=1', Artisan::output());
    }

    // ── fixtures ──

    private function buyer(string $state): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => $state, 'last_activity_at' => now()->subDays(2),
            'first_name' => 'Nina', 'last_name' => 'Nightly ' . Str::random(4), 'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    private function makeDeal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'deal_no' => (string) random_int(8000, 8999999),
            'period' => '2026-06', 'deal_date' => '2026-06-10', 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500, 'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $over));
    }

    private function dealWithBuyer(Contact $buyer): Deal
    {
        $deal = $this->makeDeal();
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        app(BuyerStateService::class)->markBuyersWonForLiveDeal($deal, [$buyer->id], $this->agent->id);

        return $deal;
    }
}
