<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\RoadToSold\FeedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\LeadResponse\LeadResponseTestCase;

/**
 * AT-454 (lane 3) - the three blocks under the call list. Written with correct queries rather than
 * the Today-page ones (buyers matched on the buyer's AGENT not created_by_user_id; the agency's own
 * warm days not a hard-coded 14; newest risk score only). Spec section 10.5.
 *
 * Paths proven: buyer owned by the agent but created by someone else (shown) vs created by the agent
 * but owned by another (not shown); second agent; stale vs fresh; never contacted; lost / won left
 * out; the agency's warm-days setting moves the line; newest risk score only; lead waiting / overdue
 * first / responded left out / other agent's left out; mandates inside the window only, own listings
 * only, off-market left out; a user with no agency gets nothing.
 */
final class FeedServiceTest extends LeadResponseTestCase
{
    private function svc(): FeedService
    {
        return app(FeedService::class);
    }

    private function buyer(string $first, array $o = []): Contact
    {
        return Contact::withoutGlobalScopes()->create($o + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id,
            'created_by_user_id' => $this->agentA->id, 'agent_id' => $this->agentA->id,
            'is_buyer' => true, 'buyer_state' => 'warm', 'first_name' => $first, 'last_name' => 'Buyer',
            'phone' => '082' . random_int(1000000, 9999999),
            'last_activity_at' => now()->subDays(40), 'last_contacted_at' => null,
        ]);
    }

    public function test_buyer_follow_ups_use_the_buyers_agent_the_agencys_warm_days_and_the_newest_risk_score(): void
    {
        $stale = $this->buyer('Stale');
        $createdByAOwnedByB = $this->buyer('OwnedByBen', ['agent_id' => $this->agentB->id]);
        $createdByBOwnedByA = $this->buyer('OwnedByAnna', ['created_by_user_id' => $this->agentB->id]);
        $secondAgent = $this->buyer('SecondAgent', ['agent_id' => $this->agentB->id, 'second_agent_id' => $this->agentA->id]);
        $fresh = $this->buyer('Fresh', ['last_activity_at' => now()->subDays(2)]);
        $lost = $this->buyer('Lost', ['buyer_state' => 'lost']);
        $won = $this->buyer('Won', ['buyer_state' => 'won']);
        $never = $this->buyer('Never', ['buyer_state' => 'new', 'last_activity_at' => null]);

        DB::table('buyer_lost_risk_scores')->insert([
            ['contact_id' => $stale->id, 'agency_id' => $this->agency->id, 'score' => 95, 'computed_at' => now()->subDays(5)],
            ['contact_id' => $stale->id, 'agency_id' => $this->agency->id, 'score' => 20, 'computed_at' => now()->subDay()],
            ['contact_id' => $secondAgent->id, 'agency_id' => $this->agency->id, 'score' => 75, 'computed_at' => now()],
        ]);

        $feed = $this->svc()->buyerFollowUps($this->agentA);
        $titles = collect($feed['items'])->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Stale Buyer', 'OwnedByAnna Buyer', 'SecondAgent Buyer', 'Never Buyer'], $titles);
        $this->assertSame(4, $feed['count']);
        $byTitle = collect($feed['items'])->keyBy('title');
        $this->assertSame('No contact for 40 days', $byTitle['Stale Buyer']['sub'], 'the newer low score hides the older high one');
        $this->assertStringContainsString('risk of losing them 75%', $byTitle['SecondAgent Buyer']['sub']);
        $this->assertSame('Not contacted yet', $byTitle['Never Buyer']['sub']);
        $this->assertSame(route('corex.contacts.show', $stale->id), $byTitle['Stale Buyer']['url']);

        // the agency's own warm window, not a constant
        AgencyContactSettings::forAgency($this->agency->id)->update(['buyer_warm_days' => 1]);
        $titles = collect($this->svc()->buyerFollowUps($this->agentA)['items'])->pluck('title')->all();
        $this->assertContains('Fresh Buyer', $titles);

        // recently contacted by phone moves a buyer out even when activity is old
        $stale->update(['last_contacted_at' => now()->subHours(3)]);
        $this->assertNotContains('Stale Buyer', collect($this->svc()->buyerFollowUps($this->agentA)['items'])->pluck('title')->all());

        // another agent's feed is their own
        $this->assertContains('OwnedByBen Buyer', collect($this->svc()->buyerFollowUps($this->agentB)['items'])->pluck('title')->all());
        $this->assertSame(0, count(array_filter($this->svc()->buyerFollowUps($this->agentC)['items'])));
    }

    public function test_the_list_is_capped_but_the_count_is_the_whole_number(): void
    {
        foreach (range(1, 11) as $i) {
            $this->buyer('B' . $i);
        }
        $feed = $this->svc()->buyerFollowUps($this->agentA);

        $this->assertSame(11, $feed['count']);
        $this->assertCount(FeedService::LIMIT, $feed['items']);
    }

    public function test_lead_follow_ups_are_the_waiting_enquiries_overdue_first(): void
    {
        $this->lead(['received' => '2026-10-07 11:30:00', 'name' => 'Recent']);                       // waiting, not yet late
        $this->lead(['received' => '2026-10-06 09:00:00', 'name' => 'Old']);                          // waiting, overdue
        $this->lead(['received' => '2026-10-07 09:00:00', 'name' => 'Done', 'responded' => '2026-10-07 09:10:00']);
        $this->lead(['received' => '2026-10-07 09:00:00', 'name' => 'Bens', 'by' => $this->agentB]);

        $feed = $this->svc()->leadFollowUps($this->agentA);

        $this->assertSame(2, $feed['count']);
        $this->assertStringStartsWith('Overdue', $feed['items'][0]['sub']);
        $this->assertStringContainsString('12 Beach Rd', $feed['items'][0]['sub']);
        $this->assertStringNotContainsString('Overdue', $feed['items'][1]['sub']);
        $this->assertNotNull($feed['items'][0]['url']);
    }

    public function test_expiring_mandates_are_the_agents_own_listings_inside_the_window(): void
    {
        $make = fn (string $title, $agent, $expiry, string $status = 'active') => Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch1->id,
            'external_id' => (string) Str::uuid(), 'title' => $title, 'suburb' => 'Uvongo', 'property_type' => 'house',
            'listing_type' => 'sale', 'status' => $status, 'price' => 1_000_000, 'published_at' => now(),
            'expiry_date' => $expiry, 'mandate_type' => 'sole',
        ]);
        $make('Soon', $this->agentA, now()->addDays(3)->toDateString());
        $make('Today', $this->agentA, now()->toDateString());
        $make('Far', $this->agentA, now()->addDays(60)->toDateString());
        $make('Bens', $this->agentB, now()->addDays(3)->toDateString());
        $make('Sold', $this->agentA, now()->addDays(3)->toDateString(), 'sold');
        $make('No date', $this->agentA, null);

        $feed = $this->svc()->expiringMandates($this->agentA);

        $this->assertSame(['Today', 'Soon'], collect($feed['items'])->pluck('title')->all());
        $this->assertSame(2, $feed['count']);
        $this->assertStringContainsString('(today)', $feed['items'][0]['sub']);
        $this->assertStringContainsString('(3 days)', $feed['items'][1]['sub']);
        $this->assertStringContainsString('Sole', $feed['items'][1]['sub']);
    }

    public function test_a_user_with_no_agency_gets_empty_feeds(): void
    {
        $nobody = \App\Models\User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'agent']);

        foreach (['buyerFollowUps', 'leadFollowUps', 'expiringMandates'] as $m) {
            $this->assertSame(['count' => 0, 'items' => []], $this->svc()->{$m}($nobody));
        }
    }
}
