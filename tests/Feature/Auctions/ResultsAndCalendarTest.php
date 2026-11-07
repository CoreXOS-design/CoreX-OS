<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionLotService;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\LotResultService;
use App\Services\CommandCenter\Calendar\Sources\AuctionCalendarSource;

/** Screen S7 Results (+ CSV export) and the auction calendar entries (.ai/specs/auctions.md §5, §10). */
final class ResultsAndCalendarTest extends BiddingTestCase
{
    /** Three finished lots: one sold above the reserve, one sold below it, one passed in. */
    private function finishedLots(): array
    {
        $svc = app(AuctionLotService::class);
        $mk = function (string $title, array $extra) use ($svc) {
            $l = $svc->saveForProperty($this->property($title), ['auction_id' => $this->auc->id, 'opens_at' => now()->subMinutes(30)->toDateTimeString(), 'closes_at' => now()->addDay()->toDateTimeString()] + $extra, $this->admin);
            $l->forceFill(['status' => 'published'])->save();

            return $l;
        };
        $sold = $this->lot;                                                      // reserve 150 000
        $below = $mk('Below Reserve House', ['opening_bid' => 80000, 'reserve_price' => 300000]);
        $pass = $mk('Passed In House', ['opening_bid' => 80000, 'reserve_price' => 90000]);

        $a = $this->registration($this->buyer('a@example.com'));
        $b = $this->registration($this->buyer('b@example.com'));
        $engine = app(BidEngine::class);
        $engine->place($sold, $a, 100000);
        $engine->place($sold, $b, 175000);
        $engine->place($below, $a, 120000);
        foreach ([$sold, $below, $pass] as $l) {
            $l->forceFill(['closes_at' => now()->subMinute()])->save();
            app(LotResultService::class)->conclude($l->id);
        }

        return [$sold->fresh(), $below->fresh(), $pass->fresh()];
    }

    public function test_the_results_list_shows_each_outcome_with_price_winner_and_bids(): void
    {
        $this->get(route('corex.auctions.results'))->assertOk()->assertSee('No lot has finished yet');

        $this->finishedLots();

        $this->get(route('corex.auctions.results'))->assertOk()
            ->assertSee('Sold')->assertSee('Sold subject to confirmation')->assertSee('Passed in')
            ->assertSee('R 175 000')->assertSee('R 120 000')->assertSee('#2');
    }

    public function test_the_stats_summarise_what_is_listed(): void
    {
        $this->finishedLots();

        $html = $this->get(route('corex.auctions.results'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Lots sold<\/div><div class="font-bold"[^>]*>1</', $html);
        $this->assertMatchesRegularExpression('/Awaiting seller<\/div><div class="font-bold"[^>]*>1</', $html);
        $this->assertMatchesRegularExpression('/Passed in<\/div><div class="font-bold"[^>]*>1</', $html);
        $this->assertStringContainsString('R 175 000', $html);    // turnover = the one outright sale
        $this->assertMatchesRegularExpression('/Bids per lot<\/div><div class="font-bold"[^>]*>1</', $html);   // 3 valid bids over 3 lots
    }

    public function test_results_search_filter_and_sort(): void
    {
        [$sold, $below, $pass] = $this->finishedLots();

        $this->get(route('corex.auctions.results', ['outcome' => 'passed_in']))->assertSee('Passed In House')->assertDontSee('Below Reserve House');
        $this->get(route('corex.auctions.results', ['search' => 'Below Reserve']))->assertSee('Below Reserve House')->assertDontSee('Passed In House');
        $this->get(route('corex.auctions.results', ['search' => 'zzzz']))->assertSee('No results match these filters');
        $this->get(route('corex.auctions.results', ['sort' => 'hammer_price', 'dir' => 'desc']))->assertOk();
        $this->get(route('corex.auctions.results', ['date_from' => now()->addDay()->toDateString()]))->assertSee('No results match these filters');
    }

    public function test_the_csv_export_has_the_same_rows_and_the_reserve_only_for_those_who_may_see_it(): void
    {
        $this->finishedLots();

        $csv = $this->get(route('corex.auctions.results.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertContains('Reserve', $rows[0]);
        $this->assertCount(4, $rows, 'a header and three lots');
        $this->assertStringContainsString('175000.00', $csv);
        $this->assertStringContainsString('Passed in', $csv);
    }

    public function test_another_agencys_results_are_never_listed(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rr-'.uniqid()]);
        AuctionLot::withoutAgencyStamping(fn () => AuctionLot::create([
            'agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'property_id' => $this->property('Rival Result House')->id, 'lot_number' => 77,
            'status' => 'sold', 'hammer_price' => 999999, 'result_at' => now(),
        ]));

        $this->get(route('corex.auctions.results'))->assertDontSee('Rival Result House');
        $this->assertStringNotContainsString('999999', $this->get(route('corex.auctions.results.export'))->streamedContent());
    }

    // ── calendar ───────────────────────────────────────────────────────────

    private function rows(): \Illuminate\Support\Collection
    {
        return (new AuctionCalendarSource())->syncAll();
    }

    public function test_a_published_auction_puts_its_date_and_registration_deadline_on_the_calendar(): void
    {
        $rows = $this->rows();

        $date = $rows->firstWhere('category', 'auction_date');
        $this->assertSame($this->auc->id, $date['source_id']);
        $this->assertSame($this->agency->id, $date['agency_id']);
        $this->assertStringContainsString($this->auc->reference, $date['title']);
        $this->assertNotNull($rows->firstWhere('category', 'auction_registration_closes'));
    }

    public function test_draft_and_cancelled_auctions_are_not_on_the_calendar(): void
    {
        $this->auc->forceFill(['status' => 'cancelled'])->save();
        $this->auction(['reference' => 'AUC-T-DR', 'status' => 'draft']);

        $this->assertSame(0, $this->rows()->count());
    }

    public function test_a_lot_waiting_for_the_seller_is_on_the_calendar_until_decided(): void
    {
        $reg = $this->registration($this->buyer('a@example.com'));
        app(BidEngine::class)->place($this->lot, $reg, 120000);
        $this->lot->forceFill(['closes_at' => now()->subMinute()])->save();
        app(LotResultService::class)->conclude($this->lot->id);

        $row = $this->rows()->firstWhere('category', 'auction_confirmation_deadline');
        $this->assertNotNull($row);
        $this->assertSame($this->lot->id, $row['source_id']);
        $this->assertSame($this->admin->id, $row['user_id'], 'the listing agent');
        $this->assertStringContainsString('Lot 1', $row['title']);

        app(LotResultService::class)->declineSale($this->lot->fresh(), 'Too low');
        $this->assertNull($this->rows()->firstWhere('category', 'auction_confirmation_deadline'));
    }

    public function test_the_calendar_event_classes_exist_for_the_three_entries(): void
    {
        \Illuminate\Support\Facades\Auth::guard('web')->forgetUser();   // reference data is seeded from the console, not as a staff member
        $this->seed(\Database\Seeders\CalendarEventClassSeeder::class);

        foreach (['auction_date', 'auction_registration_closes', 'auction_confirmation_deadline'] as $class) {
            $this->assertDatabaseHas('calendar_event_class_settings', ['event_class' => $class, 'agency_id' => null, 'is_active' => 1]);
        }
    }
}
