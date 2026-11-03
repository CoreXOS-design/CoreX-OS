<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\User;
use App\Services\Prospecting\MicWork\Contracts\PanelProvider;
use App\Services\Prospecting\MicWork\Contracts\TileProvider;
use App\Services\Prospecting\MicWork\MicWorkTiles;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 2.1 A+B (step 6.2): greeting gone, 11 tiles, "Coming" without a service, filter + panels through the provider contract. */
final class MicWorkTilesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    public function test_greeting_is_gone_and_eleven_tiles_say_coming_without_inventing_numbers(): void
    {
        $r = $this->actingAs($this->admin)->get(route('market-intelligence.work'));
        $r->assertOk()->assertDontSee("here's your week", false)->assertDontSee('Here&#039;s your week', false)->assertSee('data-qa="work-tiles"', false);
        for ($n = 1; $n <= 11; $n++) {
            $r->assertSee('data-qa="tile-' . $n . '"', false);
        }
        $this->assertSame(11, count(MicWorkTiles::fromContainer()->row($this->agency->id, $this->admin)));
        $byNo = collect((new MicWorkTiles([], []))->row($this->agency->id, $this->admin))->keyBy('number');
        foreach ([2, 3, 10, 11] as $n) {
            $this->assertNull($byNo[$n]['count'], 'a tile with no tagged service has no number');
            $this->assertFalse($byNo[$n]['ready']);
        }
    }

    public function test_tile_one_is_long_on_market_with_the_agency_window_under_it_and_the_word_stuck_is_never_shown(): void
    {
        $r = $this->actingAs($this->admin)->get(route('market-intelligence.work'));
        $r->assertOk()->assertDontSee('Stuck on the market', false)->assertDontSee('>Stuck<', false);
        $tile = collect(MicWorkTiles::fromContainer()->row($this->agency->id, $this->admin))->firstWhere('number', 1);
        $this->assertSame('stuck', $tile['key'], 'the internal key (tile=stuck in URLs) stays');
        $this->assertSame('Long on market', $tile['short']);
        $this->assertSame('+90 DOM', $tile['threshold']);
        $this->assertSame('Long on market (+90 DOM)', $tile['title']);
        $this->assertSame('Long on market (+90 DOM)', MicWorkTiles::titleFor(1, $this->agency->id));
        // ONE setting, owned by Road to Sold (Settings > General > Long on market: more than N days); MIC has no window of its own
        \App\Models\RoadToSold\RtsSetting::updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['long_on_market_days' => 30]);
        \App\Services\RoadToSold\SettingsService::forget();
        $this->assertSame('+30 DOM', MicWorkTiles::longOnMarketLabel($this->agency->id), 'the agency\'s own days setting');
        \App\Models\RoadToSold\RtsSetting::updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['long_on_market_days' => 90]);
        \App\Services\RoadToSold\SettingsService::forget();
        $this->assertSame('Owner selling privately', MicWorkTiles::titleFor(2, $this->agency->id), 'only tile 1 carries a window');
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'stuck']))->assertOk()->assertSee('Tile · +90 DOM', false)->assertDontSee('Tile · Stuck', false);
        $this->assertStringContainsString('data-qa="tile-threshold"', file_get_contents(resource_path('views/corex/market-intelligence/partials/_work-tiles.blade.php')));
    }

    public function test_a_tagged_provider_feeds_its_tile_and_a_click_filters_the_list(): void
    {
        $agencyId = $this->agency->id;
        $provider = new class($agencyId) implements TileProvider {
            public function __construct(private int $a) {}
            public function tile(): int { return 8; }
            public function summary(int $agencyId, User $user): array { return ['count' => 7, 'reason' => 'alert adverts this week']; }
            public function propertyIds(int $agencyId, User $user): ?Builder { return DB::table('tracked_properties')->select('id')->whereRaw('1 = 0'); }
        };
        $this->app->instance('test.tile8', $provider);
        $this->app->tag('test.tile8', 'mic.work.tiles');

        $this->actingAs($this->admin)->get(route('market-intelligence.work'))
            ->assertOk()->assertSee('alert adverts this week', false);
        // the tile filter reaches the list query without error and narrows it to the provider's (empty) set
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'p24_alerts']))->assertOk();
    }

    public function test_a_panel_without_a_service_is_not_ready_and_with_one_renders_rows(): void
    {
        $this->assertFalse((new MicWorkTiles([], []))->row($this->agency->id, $this->admin)[9]['ready'], 'no panel provider = the panel tile is not ready');

        $panel = new class implements PanelProvider {
            public function tile(): int { return 11; }
            public function rows(int $agencyId, User $user, array $filters): array
            {
                return [['suburb' => 'Margate', 'p24_read_at' => now()->subDays(12)->toIso8601String(), 'pp_read_at' => null, 'age_days' => 12, 'overdue' => true, 'p24_url' => 'https://www.property24.com/x', 'pp_url' => null]];
            }
            public function around(int $agencyId, User $user, string $triggerId): array { return []; }
        };
        $this->app->instance('test.panel11', $panel);
        $this->app->tag('test.panel11', 'mic.work.panels');
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['panel' => 11]))
            ->assertOk()->assertSee('data-qa="refresh-row"', false)->assertSee('Margate', false)->assertSee('Open in P24', false);
    }

    public function test_tiles_10_and_11_show_numbers_read_from_the_panel_providers(): void
    {
        $p10 = new class implements PanelProvider {
            public function tile(): int { return 10; }
            public function rows(int $agencyId, User $user, array $filters): array
            {
                return [['id' => 'sold:1', 'type' => 'sold', 'address' => '1 A Road', 'suburb' => 'MARGATE', 'has_address' => true], ['id' => 'sold:2', 'type' => 'sold', 'address' => 'x', 'suburb' => 'MARGATE', 'has_address' => false]];
            }
            public function around(int $agencyId, User $user, string $triggerId): array { return []; }
            public function summary(int $agencyId, User $user): array { return ['count' => 57, 'triggers_this_month' => 57, 'neighbours_open' => 134, 'reason' => 'x']; }
        };
        $p11 = new class implements PanelProvider {
            public function tile(): int { return 11; }
            public function rows(int $agencyId, User $user, array $filters): array { return []; }
            public function around(int $agencyId, User $user, string $triggerId): array { return []; }
            public function summary(int $agencyId, User $user): array
            {
                return ['count' => 46, 'oldest_age_days' => 46, 'oldest_suburb' => 'SHAKAS ROCK', 'older_than_days' => 12, 'working_suburbs' => 43, 'interval_days' => 14, 'never_read' => 0, 'reason' => 'x'];
            }
        };
        $row = collect((new MicWorkTiles([], [$p10, $p11]))->row($this->agency->id, $this->admin))->keyBy('number');
        $this->assertSame(57, $row[10]['count']);
        $this->assertStringContainsString('134 neighbours to call', $row[10]['note']);
        $this->assertSame('Shakas Rock 46 d', $row[11]['display'], 'cc4 summary() is the single source, suburb in title case');
        $this->assertSame('12 of 43 working suburbs older than 14 d', $row[11]['note']);
    }

    public function test_a_cold_page_does_not_wait_for_the_tiles_it_loads_them_after_itself(): void
    {
        $cold = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['_defer_tiles' => 1]))->assertOk();
        $cold->assertSee('data-qa="work-tiles-deferred"', false)->assertSee('mic-slot-listings', false)->assertDontSee('data-qa="tile-1"', false);
        $cold->assertSee('_tiles', false); // the loader asks for the row from the same URL

        $row = $this->actingAs($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('market-intelligence.work', ['_tiles' => 1]))->assertOk();
        for ($n = 1; $n <= 11; $n++) {
            $row->assertSee('data-qa="tile-' . $n . '"', false);
        }
        $row->assertDontSee('data-qa="work-property-list"', false);
    }

    public function test_panel_10_has_column_headers_and_prints_the_date_label_not_a_bare_date(): void
    {
        $p10 = new class implements PanelProvider {
            public function tile(): int { return 10; }
            public function rows(int $agencyId, User $user, array $filters): array
            {
                return [['id' => 'under_offer:5', 'type' => 'under_offer', 'address' => '1 A Road, SHELLY BEACH', 'suburb' => 'SHELLY BEACH', 'property_type' => 'House', 'price' => 900000,
                    'date' => '2026-10-31', 'date_kind' => 'seen', 'date_label' => 'seen 31 Oct', 'has_address' => true]];
            }
            public function around(int $agencyId, User $user, string $triggerId): array { return []; }
        };
        $this->app->instance('test.panel10b', $p10);
        $this->app->tag('test.panel10b', 'mic.work.panels');

        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['panel' => 10]))->assertOk()
            ->assertSee('data-qa="trigger-head"', false)->assertSee('What happened', false)->assertSee('seen 31 Oct', false)
            ->assertDontSee('>2026-10-31<', false)->assertSee('Shelly Beach', false)->assertDontSee('SHELLY BEACH', false);
    }

    public function test_the_tile_fragment_never_builds_links_with_tiles(): void
    {
        // the page's own fetch: tile links come from the PARENT page URL, never carry _tiles
        $frag = $this->actingAs($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('market-intelligence.work', ['_tiles' => 1, 'search' => 'x']))->assertOk()->getContent();
        $this->assertSame(0, preg_match('/href="[^"]*_tiles/', $frag), 'a tile link must not carry _tiles');
        $this->assertSame(1, preg_match('/href="[^"]*search=x/', $frag), 'but it keeps the parent page filters');
    }

    public function test_a_plain_visit_to_the_tile_fragment_url_is_the_full_page(): void
    {
        // Johan's case: landing on ?_tiles=1&tile=owner by a link / bookmark (no X-Requested-With) - the FULL page, filtered, no bare tile row
        $html = $this->actingAs($this->admin)->get('/corex/market-intelligence?_tiles=1&tile=owner')->assertOk()->getContent();
        $this->assertStringContainsString('Properties matched', $html, 'the stats bar is there: this is the full page');
        $this->assertStringContainsString('mic-slot-listings', $html);
        $this->assertStringContainsString('mi-filter-rail', $html, 'the left column too');
        $this->assertSame(0, preg_match('/href="[^"]*_tiles=1/', $html), 'no link on the full page carries _tiles forward');

        // _card is the same: a person who opens it directly gets the page
        $this->actingAs($this->admin)->get('/corex/market-intelligence?_card=1')->assertOk()->assertSee('Properties matched', false);
    }

    private function fileTile(int $number, ?\Closure $ids, int $count = 1, array $extra = []): void
    {
        $p = new class($number, $ids, $count) implements TileProvider {
            public function __construct(private int $n, private ?\Closure $ids, private int $count) {}
            public function tile(): int { return $this->n; }
            public function summary(int $agencyId, User $user): array { return ['count' => $this->count, 'reason' => 'x']; }
            public function propertyIds(int $agencyId, User $user): ?Builder { return $this->ids ? ($this->ids)() : null; }
        };
        $this->app->instance('test.tile' . $number, $p);
        $this->app->tag('test.tile' . $number, 'mic.work.tiles');
    }

    private function advert(\App\Models\Prospecting\TrackedProperty $tp, string $ref, array $extra = []): \App\Models\ProspectingListing
    {
        return \App\Models\ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://p.test/' . $ref, 'address' => '1 Test Road', 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => 900000, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    public function test_the_offer_sold_gone_tile_shows_the_inactive_adverts_it_counted_and_a_tile_without_a_list_is_not_a_link(): void
    {
        $tp = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => '1', 'street_name' => 'Test Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => 'P24-140000001']);
        $this->advert($tp, 'P24-140000001', ['is_active' => false, 'portal_status' => 'sold']);
        $this->fileTile(3, fn () => DB::table('tracked_properties')->select('id')->where('id', $tp->id), 1);
        $this->fileTile(9, null, 23); // market prices: no list of properties

        $page = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'gone']))->assertOk();
        $page->assertSee('P24-140000001', false); // the default "active adverts only" would have emptied exactly the list the tile counted
        $html = $page->getContent();
        $this->assertSame(1, preg_match('/<div data-qa="tile-9"/', $html), 'a tile with no list is a plain block, not a link');
        $this->assertSame(1, preg_match('/<a href="[^"]*tile=gone"[^>]*data-qa="tile-3"|data-qa="tile-3"[^>]*>/', $html));
    }

    public function test_an_active_tile_says_what_it_counted_and_what_the_list_counts_and_how_fresh_it_is(): void
    {
        $tp = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => '2', 'street_name' => 'Owner Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => 'P24-140000002']);
        $this->advert($tp, 'P24-140000002', ['advertiser_type' => 'owner']);
        $this->fileTile(2, fn () => DB::table('tracked_properties')->select('id')->where('id', $tp->id), 1);
        // the cached tile row must exist for the note to carry the tile's own number (a cold page defers the tiles)
        $note = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'owner']))->assertOk();
        $note->assertSee('data-qa="tile-note"', false)->assertSee('Owner selling', false)->assertSee('rows below', false)->assertSee('Clear this tile', false);
        $this->assertSame(1, preg_match('/1\s+properties\s+→\s+1\s+rows below/u', strip_tags($note->getContent())), 'properties -> rows, both numbers');

        // a tile whose own number counts something else says what: 5 on the tile, 1 property in the list
        $this->fileTile(6, fn () => DB::table('tracked_properties')->select('id')->where('id', $tp->id), 5);
        $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk(); // warms the tile row (it now holds tile 6)
        $other = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'lead_sources']))->assertOk();
        $this->assertStringContainsString('the tile\'s 5 counts "x", not this list', preg_replace('/\s+/', ' ', strip_tags($other->getContent())));
    }
}
