<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\CommandCenter\Calendar\Sources\AuctionCalendarSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 — .ai/specs/auctions.md §16. AuctionCalendarSource lights up the
 * 3 event classes computable from Phase 1 data. Verified manually via
 * Tinker while building this (including against the real
 * corex:calendar:reconcile command, which picked it up as the 9th
 * registered source and materialised a real calendar_events row) — this
 * test captures the same scenarios.
 */
final class AuctionCalendarSourceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Calendar Test Agency', 'slug' => 'cal-test-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        $this->property = Property::create([
            'title' => 'Calendar test lot', 'agency_id' => $this->agency->id, 'agent_id' => $user->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
    }

    private function makeAuction(array $attrs = []): Auction
    {
        return Auction::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-'.uniqid(),
            'title' => 'Test Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(5), 'status' => 'scheduled',
        ], $attrs));
    }

    public function test_a_draft_auction_emits_no_events(): void
    {
        $this->makeAuction(['status' => 'draft']);

        $this->assertCount(0, (new AuctionCalendarSource())->syncAll());
    }

    public function test_a_scheduled_auction_emits_an_auction_date_event(): void
    {
        $auction = $this->makeAuction();

        $events = (new AuctionCalendarSource())->syncAll();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('auction_date', $event['category']);
        $this->assertSame('auction', $event['event_type']);
        $this->assertSame($auction->id, $event['source_id']);
        $this->assertSame($this->agency->id, $event['agency_id']);
        $this->assertSame($this->branch->id, $event['branch_id']);
    }

    public function test_registration_closes_emits_its_own_event_alongside_the_auction_date(): void
    {
        $this->makeAuction(['registration_closes_at' => now()->addDays(2)]);

        $categories = (new AuctionCalendarSource())->syncAll()->pluck('category')->all();

        $this->assertContains('auction_date', $categories);
        $this->assertContains('auction_registration_closes', $categories);
    }

    public function test_a_lot_awaiting_seller_confirmation_emits_a_confirmation_deadline_event(): void
    {
        $auction = $this->makeAuction();
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $this->property->id,
            'lot_number' => 1, 'status' => AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION,
            'confirmation_deadline' => now()->addDays(3),
        ]);

        $events = (new AuctionCalendarSource())->syncAll();
        $confirmationEvent = $events->firstWhere('category', 'auction_confirmation_deadline');

        $this->assertNotNull($confirmationEvent);
        $this->assertSame($this->branch->id, $confirmationEvent['branch_id']);
        $this->assertSame($this->property->id, $confirmationEvent['property_id']);
    }

    public function test_a_confirmed_lot_no_longer_emits_a_confirmation_deadline_event(): void
    {
        $auction = $this->makeAuction();
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $auction->id, 'property_id' => $this->property->id,
            'lot_number' => 1, 'status' => AuctionLot::STATUS_SOLD,
            'confirmation_deadline' => now()->addDays(3), 'confirmed_at' => now(),
        ]);

        $categories = (new AuctionCalendarSource())->syncAll()->pluck('category')->all();

        $this->assertNotContains('auction_confirmation_deadline', $categories);
    }
}
