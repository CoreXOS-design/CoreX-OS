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
        $this->actingAs(User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']));
    }

    public function test_default_run_moves_only_buyers_on_live_deals_and_any_deal_also_counts_declined(): void
    {
        $live     = $this->buyerOnDeal('P');
        $granted  = $this->buyerOnDeal('G');
        $declined = $this->buyerOnDeal('D');
        $archived = $this->buyerOnDeal('P', archive: true);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--agency' => $this->agencyId]);
        $liveOut = Artisan::output();
        $this->assertStringContainsString('2 buyer(s) WOULD move', $liveOut);

        Artisan::call('buyers:backfill-won', ['--dry-run' => true, '--any-deal' => true, '--agency' => $this->agencyId]);
        $this->assertStringContainsString('3 buyer(s) WOULD move', Artisan::output(), 'declined counts only in the comparison run');

        foreach ([$live, $granted, $declined, $archived] as $b) {
            $this->assertSame('warm', $b->fresh()->buyer_state, 'a dry run writes nothing');
        }

        Artisan::call('buyers:backfill-won', ['--agency' => $this->agencyId]);

        $this->assertSame('won', $live->fresh()->buyer_state);
        $this->assertSame('won', $granted->fresh()->buyer_state);
        $this->assertSame('warm', $declined->fresh()->buyer_state, 'fell through: not Won');
        $this->assertSame('warm', $archived->fresh()->buyer_state, 'archived deal: not Won');
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
