<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-500 — buyers:backfill-won counts and moves only buyers on a LIVE deal (Johan, 22 Oct 2026);
 * --any-deal widens the count to declined deals for comparison.
 */
final class BuyersBackfillWonLiveDealsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;

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
        $user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->userId = $user->id;
        $this->actingAs($user);
    }

    public function test_default_run_moves_only_buyers_on_live_deals_and_any_deal_also_counts_declined(): void
    {
        $live     = $this->buyerOnDeal('P');
        $granted  = $this->buyerOnDeal('G');
        $declined = $this->buyerOnDeal('D');
        $archived = $this->buyerOnDeal('P', archive: true);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--agency' => $this->agencyId]);
        $liveOut = Artisan::output();
        $this->assertStringContainsString('2 buyer(s) WOULD move to Won;', $liveOut);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--any-deal' => true, '--agency' => $this->agencyId]);
        $this->assertStringContainsString('3 buyer(s) WOULD move to Won;', Artisan::output(), 'declined counts only in the comparison run');

        foreach ([$live, $granted, $declined, $archived] as $b) {
            $this->assertSame('warm', $b->fresh()->buyer_state, 'a dry run writes nothing');
        }

        Artisan::call('buyers:backfill-won', ['--agency' => $this->agencyId]);

        $this->assertSame('won', $live->fresh()->buyer_state);
        $this->assertSame('won', $granted->fresh()->buyer_state);
        $this->assertSame('warm', $declined->fresh()->buyer_state, 'fell through: not Won');
        $this->assertSame('warm', $archived->fresh()->buyer_state, 'archived deal: not Won');
    }

    /** PART 2 — Won with no live deal and not moved by hand goes back on the board; manual Won and live-deal Won stay. */
    public function test_part_two_returns_won_buyers_with_no_basis_and_leaves_manual_and_live_deal_won_alone(): void
    {
        $states = app(\App\Services\BuyerStateService::class);
        $onLiveDeal = $this->buyerOnDeal('P');
        $states->markWon($onLiveDeal->fresh(), null);                                   // Won, live deal

        $oldRule = $this->plainBuyer();
        $states->markWon($oldRule->fresh(), null);                                      // Won via the old property-link rule, no deal

        $manual = $this->plainBuyer();
        // The UI has no "move to Won" yet; the back-fill's contract is about the audit reason a manual move writes.
        $states->transitionTo($manual->fresh(), 'won', 'manual_override', $this->userId);           // Won by hand, no deal

        $noTrail = $this->plainBuyer();
        $noTrail->updateQuietly(['buyer_state' => 'won']);                              // Won with no transition row at all

        $endedDeal = $this->buyerOnDeal('P');
        $states->markWon($endedDeal->fresh(), null);
        DB::table('deals')->whereIn('id', DB::table('deal_contacts')->where('contact_id', $endedDeal->id)->pluck('deal_id'))->update(['accepted_status' => 'D']);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--agency' => $this->agencyId]);
        $out = Artisan::output();
        $this->assertStringContainsString('0 buyer(s) WOULD move to Won; 2 Won buyer(s) WOULD go back to the board; 1 WOULD go to Lost', $out, 'dry-run shows BOTH counts');
        foreach ([$oldRule, $noTrail, $endedDeal] as $b) {
            $this->assertSame('won', $b->fresh()->buyer_state, 'a dry run writes nothing');
        }

        Artisan::call('buyers:backfill-won', ['--agency' => $this->agencyId]);

        $this->assertSame('won', $onLiveDeal->fresh()->buyer_state, 'live deal: stays Won');
        $this->assertSame('won', $manual->fresh()->buyer_state, 'moved by hand: stays Won');
        foreach ([$oldRule, $noTrail] as $b) {
            $this->assertContains($b->fresh()->buyer_state, ['new', 'warm', 'cold'], 'back on the board, never Lost');
        }
        $this->assertSame('lost', $endedDeal->fresh()->buyer_state, 'the deal was declined: it fell through, so Lost');
        $this->assertDatabaseHas('buyer_state_transitions', ['contact_id' => $endedDeal->id, 'from_state' => 'won', 'to_state' => 'lost', 'reason' => 'deal_fell_through']);
        $this->assertDatabaseHas('buyer_state_transitions', ['contact_id' => $oldRule->id, 'from_state' => 'won', 'reason' => 'won_without_live_deal']);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--agency' => $this->agencyId]);
        $this->assertStringContainsString('0 buyer(s) WOULD move to Won; 0 Won buyer(s) WOULD go back to the board; 0 WOULD go to Lost', Artisan::output(), 'second run changes nothing');
    }

    private function plainBuyer(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'is_buyer' => true, 'buyer_state' => 'warm',
            'last_activity_at' => now()->subDays(2), 'first_name' => 'Pat', 'last_name' => 'Plain ' . Str::random(4),
        ]);
    }

    private function buyerOnDeal(string $status, bool $archive = false): Contact
    {
        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'is_buyer' => true, 'buyer_state' => 'warm',
            'first_name' => 'Bo', 'last_name' => 'Deal ' . Str::random(4),
        ]);
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'deal_no' => (string) random_int(8000, 8999999),
            'period' => '2026-06', 'deal_date' => '2026-06-10', 'accepted_status' => $status, 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500, 'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
        DB::table('deal_contacts')->insert(['deal_id' => $deal->id, 'contact_id' => $buyer->id, 'role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        if ($archive) {
            $deal->delete();
        }

        return $buyer;
    }
}
