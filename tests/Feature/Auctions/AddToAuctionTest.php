<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Support\Facades\Artisan;

/** "Add to auction" on a property: choose the auction and lot number, the date fills in, type the time (.ai/specs/auctions.md §18). */
final class AddToAuctionTest extends AuctionsV2TestCase
{
    private Auction $timed;
    private Auction $live;
    private Property $p;

    protected function setUp(): void
    {
        parent::setUp();
        // a timed online auction (9:00 on the 10th day to 17:00 on the 12th) and a live one (11:00, 20 days out)
        $this->timed = $this->auction(['reference' => 'AUC-T-TIMED', 'title' => 'Online Auction', 'format' => 'timed', 'status' => 'published',
            'starts_at' => now()->addDays(10)->setTime(9, 0), 'ends_at' => now()->addDays(12)->setTime(17, 0)]);
        $this->live = $this->auction(['reference' => 'AUC-T-LIVE', 'title' => 'Town Hall Auction', 'format' => 'live', 'status' => 'draft',
            'starts_at' => now()->addDays(20)->setTime(11, 0), 'ends_at' => null]);
        $this->p = $this->property('Sunset Villa');
    }

    private function add(array $body, ?Property $property = null)
    {
        $property ??= $this->p;

        return $this->from(route('corex.properties.show', $property))->post(route('corex.properties.auction.add', $property), $body);
    }

    private function day(Auction $a, string $which = 'starts_at'): string
    {
        return $a->{$which}->format('Y-m-d');
    }

    // ── the dialog ─────────────────────────────────────────────────────────

    public function test_the_property_header_offers_add_to_auction_with_the_auctions_their_dates_and_next_lot_numbers(): void
    {
        app(AuctionLotService::class)->saveForProperty($this->property('Already in'), ['auction_id' => $this->timed->id], $this->admin);   // timed now has lot 1

        $html = $this->get(route('corex.properties.show', $this->p))->assertOk()
            ->assertSee('Add to auction')->assertSee('data-add-to-auction', false)->assertSee('Which auction?')->assertSee('Lot number')->getContent();

        // the data must sit safely inside the double-quoted x-data attribute (no bare double quote), or the dialog breaks in a browser
        $this->assertMatchesRegularExpression('/x-data="addToAuction\(JSON\.parse\(\'[^"]*\'\), JSON\.parse\(\'[^"]*\'\), (true|false)\)"/', $html);
        $this->assertStringContainsString('AUC-T-TIMED', $html);
        $this->assertStringContainsString('AUC-T-LIVE', $html);
        $this->assertStringContainsString($this->day($this->timed), $html, 'the auction\'s date is in the dialog to fill in');
        $this->assertStringContainsString($this->day($this->live), $html);
        $this->assertStringContainsString('\\u0022next_lot\\u0022:2', $html, 'the timed auction already has lot 1, so the next is 2');
        $this->assertStringContainsString('A new one-property auction', $html);
    }

    public function test_only_upcoming_unfinished_multi_property_auctions_are_offered(): void
    {
        $this->auction(['reference' => 'AUC-T-DONE', 'status' => 'completed']);
        $this->auction(['reference' => 'AUC-T-CANC', 'status' => 'cancelled']);
        $this->auction(['reference' => 'AUC-T-OLD', 'status' => 'published', 'starts_at' => now()->subDays(9), 'ends_at' => now()->subDays(7)]);
        $this->auction(['reference' => 'AUC-T-ONE', 'status' => 'draft', 'is_single_property' => true]);

        $refs = collect(app(AuctionLotService::class)->pickerOptions($this->admin))->pluck('reference')->all();

        $this->assertSame(['AUC-T-TIMED', 'AUC-T-LIVE'], $refs, 'soonest first');
    }

    public function test_an_agent_only_gets_the_auctions_they_can_see(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->auction(['reference' => 'AUC-T-MINE', 'status' => 'draft', 'created_by' => $agent->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(6)]);

        $refs = collect(app(AuctionLotService::class)->pickerOptions($agent))->pluck('reference')->all();

        $this->assertSame(['AUC-T-MINE'], $refs);
    }

    // ── adding ─────────────────────────────────────────────────────────────

    public function test_adding_to_a_timed_auction_creates_the_lot_from_the_dates_and_times(): void
    {
        $this->add([
            'auction_id' => $this->timed->id, 'lot_number' => '',
            'opens_date' => $this->day($this->timed), 'opens_time' => '10:30',
            'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '15:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('corex.properties.show', $this->p) . '?tab=auction');

        $lot = AuctionLot::where('property_id', $this->p->id)->firstOrFail();
        $this->assertSame($this->timed->id, (int) $lot->auction_id);
        $this->assertSame(1, $lot->lot_number, 'the next free number when none is typed');
        $this->assertSame('published', $lot->status, 'the auction is already published, so the lot is too');
        $this->assertSame($this->day($this->timed) . ' 10:30', $lot->opens_at->format('Y-m-d H:i'));
        $this->assertSame($this->day($this->timed, 'ends_at') . ' 15:00', $lot->closes_at->format('Y-m-d H:i'));
        $this->assertTrue($this->p->fresh()->isAuction());
    }

    public function test_the_new_lot_shows_under_lots_in_the_auction_and_on_the_property(): void
    {
        $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00']);

        $this->get(route('corex.auctions.lots.index'))->assertOk()->assertSee('Sunset Villa')->assertSee('AUC-T-TIMED');
        $this->get(route('corex.auctions.edit', $this->timed->id))->assertOk()->assertSee('Lots (1)')->assertSee('Sunset Villa');
        $this->get(route('corex.properties.show', $this->p) . '?tab=auction')->assertOk()->assertSee('Lot 1')->assertSee('data-auction-tab', false);
        $this->get(route('corex.properties.show', $this->p))->assertSee('On auction — Lot 1')->assertDontSee('data-prop-auction-entry="add"', false);
    }

    public function test_a_typed_lot_number_is_used_and_a_taken_one_is_refused(): void
    {
        $this->add(['auction_id' => $this->timed->id, 'lot_number' => 7, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(7, AuctionLot::where('property_id', $this->p->id)->value('lot_number'));

        $second = $this->property('Second Home');
        $this->add(['auction_id' => $this->timed->id, 'lot_number' => 7, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'], $second)
            ->assertSessionHasErrors('lot_number');
        $this->assertSame(0, AuctionLot::where('property_id', $second->id)->count());

        $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'], $second);
        $this->assertSame(8, AuctionLot::where('property_id', $second->id)->value('lot_number'), 'carries on after the highest number');
    }

    public function test_adding_to_a_live_auction_takes_one_date_and_time(): void
    {
        $this->add(['auction_id' => $this->live->id, 'opens_date' => $this->day($this->live), 'opens_time' => '11:45', 'closes_date' => '2030-01-01', 'closes_time' => '09:00'])
            ->assertSessionHasNoErrors();

        $lot = AuctionLot::where('property_id', $this->p->id)->firstOrFail();
        $this->assertSame($this->day($this->live) . ' 11:45', $lot->opens_at->format('Y-m-d H:i'));
        $this->assertNull($lot->closes_at, 'a live lot has no closing time');
        $this->assertSame('draft', $lot->status, 'the live auction is still a draft');
    }

    public function test_a_new_one_property_auction_is_created_from_the_dates_and_times_typed(): void
    {
        $open = now()->addDays(3)->format('Y-m-d');
        $close = now()->addDays(5)->format('Y-m-d');

        $this->add(['auction_id' => 'new', 'opens_date' => $open, 'opens_time' => '09:00', 'closes_date' => $close, 'closes_time' => '17:00'])->assertSessionHasNoErrors();

        $lot = AuctionLot::where('property_id', $this->p->id)->firstOrFail();
        $this->assertTrue($lot->auction->is_single_property);
        $this->assertSame("{$open} 09:00", $lot->opens_at->format('Y-m-d H:i'));
        $this->assertSame('Sunset Villa', $lot->auction->title);
    }

    // ── refusals ───────────────────────────────────────────────────────────

    public function test_the_time_is_required_and_must_look_like_a_time(): void
    {
        $base = ['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'];

        $this->add($base)->assertSessionHasErrors('opens_time');
        $this->add(array_merge($base, ['opens_time' => 'half past ten']))->assertSessionHasErrors('opens_time');
        $this->add(array_merge($base, ['opens_time' => '10:00', 'closes_time' => null]))->assertSessionHasErrors('closes_date');
        $this->add(['opens_date' => $this->day($this->timed), 'opens_time' => '10:00'])->assertSessionHasErrors('auction_id');
        $this->assertSame(0, AuctionLot::count());
    }

    public function test_a_time_outside_the_auctions_window_or_a_close_before_the_open_is_refused_and_the_dialog_reopens(): void
    {
        $r = $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '06:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00']);
        $r->assertSessionHasErrors('opens_at')->assertSessionHas('add_to_auction_open', true);

        $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '23:30'])->assertSessionHasErrors('closes_at');
        $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed, 'ends_at'), 'opens_time' => '16:00', 'closes_date' => $this->day($this->timed), 'closes_time' => '10:00'])->assertSessionHasErrors();

        $this->assertSame(0, AuctionLot::count());
        $this->assertFalse($this->p->fresh()->isAuction());

        // and the page shows the dialog open with the reason
        $this->followingRedirects()->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '06:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'])
            ->assertSee('cannot open before the auction starts');
    }

    public function test_a_property_already_in_a_lot_a_rental_and_a_foreign_or_finished_auction_are_refused(): void
    {
        $body = fn (Auction $a) => ['auction_id' => $a->id, 'opens_date' => $this->day($a), 'opens_time' => '10:00', 'closes_date' => $this->day($a, 'ends_at'), 'closes_time' => '16:00'];

        $this->add($body($this->timed))->assertSessionHasNoErrors();
        $this->add($body($this->timed))->assertSessionHasErrors('auction_id');   // already Lot 1

        $rental = $this->property('Flat', ['listing_type' => 'rental']);
        $this->add($body($this->timed), $rental)->assertSessionHasErrors('auction_id');

        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $foreign = Auction::withoutAgencyStamping(fn () => Auction::create(['agency_id' => $rival->id, 'reference' => 'AUC-R', 'title' => 'Rival', 'format' => 'timed', 'status' => 'published', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]));
        $other = $this->property('Other house');
        $this->add($body($foreign), $other)->assertSessionHasErrors('auction_id');

        $done = $this->auction(['reference' => 'AUC-T-FIN', 'status' => 'completed', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);
        $this->add($body($done), $other)->assertSessionHasErrors('auction_id');
        $this->assertSame(0, AuctionLot::where('property_id', $other->id)->count());
    }

    public function test_someone_without_permission_to_manage_lots_cannot_add_a_property(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer']);
        $this->actingAs($viewer);

        $this->add(['auction_id' => $this->timed->id, 'opens_date' => $this->day($this->timed), 'opens_time' => '10:00', 'closes_date' => $this->day($this->timed, 'ends_at'), 'closes_time' => '16:00'])->assertForbidden();
        $this->assertSame(0, AuctionLot::count());
    }

    public function test_the_header_offers_nothing_when_auctions_are_switched_off_and_not_for_a_rental(): void
    {
        $this->get(route('corex.properties.show', $this->property('Flat', ['listing_type' => 'rental'])))->assertOk()->assertDontSee('data-prop-auction-entry="add"', false);

        \App\Models\AgencyFeature::where('agency_id', $this->agency->id)->where('feature_key', 'auctions')->update(['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
        $this->get(route('corex.properties.show', $this->p))->assertOk()->assertDontSee('data-prop-auction-entry="add"', false);
    }
}
