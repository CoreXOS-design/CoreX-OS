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
                return ['count' => 46, 'oldest_age_days' => 46, 'oldest_suburb' => 'SHAKAS ROCK', 'older_than_14d' => 12, 'working_suburbs' => 43, 'stale_after_days' => 14, 'never_read' => 0, 'reason' => 'x'];
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
        $cold->assertSee('data-qa="work-tiles-deferred"', false)->assertSee('data-qa="work-property-list"', false)->assertDontSee('data-qa="tile-1"', false);
        $cold->assertSee('_tiles', false); // the loader asks for the row from the same URL

        $row = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['_tiles' => 1]))->assertOk();
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
}

