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
        $this->assertSame(0, count(array_filter(MicWorkTiles::fromContainer()->row($this->agency->id, $this->admin), fn ($t) => $t['count'] !== null)), 'no provider = no number');
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

    public function test_panels_say_coming_without_a_service_and_render_rows_with_one(): void
    {
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['panel' => 10]))->assertOk()->assertSee('data-qa="panel-coming"', false);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['panel' => 11]))->assertOk()->assertSee('data-qa="panel-coming"', false);

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
}
