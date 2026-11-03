<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RolePermission;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltPreferenceService;
use App\Services\RoadToSold\BeltsService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Road to Sold page layout v2 (road-to-sold.md 23.31): three belts with one open, four-line tiles, the one toolbar row, no next-action banner, the Next marker. */
final class PageLayoutV2Test extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        // the table of the HELD migration (23.31.1): until Johan gives the go the repo has no migration file, so the test makes the table itself
        if (! Schema::hasTable('rts_user_preferences')) {
            Schema::create('rts_user_preferences', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('belt_open', 16)->nullable();
                $t->timestamps();
                $t->unique('user_id');
            });
        }
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent' => 'own'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
        // the agent already works Road to Sold, so the belts show
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Start Road'])->id, 'sales', ['stage' => S::FOUND]);
        RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => self::WED, 'track' => 'sales',
            'tracked_property_id' => $p->tracked_property_id, 'selection' => 'hot_pockets', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Test', 'prospect_id' => $p->id]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->agent)->get(route('road-to-sold.index', $q))->assertOk()->getContent();
    }

    public function test_three_belts_show_in_order_with_exactly_one_open_and_the_other_two_as_one_line_strips(): void
    {
        $html = $this->page();
        $pos = fn (string $k) => strpos($html, 'data-qa="belt-head-' . $k . '"');
        $this->assertNotFalse($pos('get'));
        $this->assertLessThan($pos('maintain'), $pos('get'));
        $this->assertLessThan($pos('sell'), $pos('maintain'));
        $this->assertSame('get', $this->heads($html)['open'], 'Get stock is the default open belt');
        $this->assertMatchesRegularExpression('/data-qa="belt-head-maintain"[^>]*aria-expanded="false"/', $html);
        $this->assertMatchesRegularExpression('/data-qa="belt-head-sell"[^>]*aria-expanded="false"/', $html);
        $this->assertSame(1, substr_count($html, 'aria-expanded="true" data-qa="belt-head-') + preg_match_all('/data-qa="belt-head-[a-z]+"[^>]*aria-expanded="true"/', $html), 'exactly one belt is open');
        $this->assertStringContainsString('3 stations', $html);
        $this->assertStringContainsString('10 stations', $html);
        $this->assertStringNotContainsString('data-qa="belt-tiles-maintain"', $html, 'a collapsed belt draws no tiles');
    }

    /** @return array{open:string} */
    private function heads(string $html): array
    {
        preg_match('/data-qa="belts" data-open="([a-z]+)"/', $html, $m);

        return ['open' => $m[1] ?? ''];
    }

    public function test_the_choice_is_remembered_per_user_and_a_click_collapses_the_previous(): void
    {
        $html = $this->page(['belt' => 'maintain']);
        $this->assertSame('maintain', $this->heads($html)['open']);
        $this->assertStringContainsString('data-qa="belt-tiles-maintain"', $html);
        $this->assertStringNotContainsString('<div class="rts-belt-row" data-qa="belt-tiles"', $html, 'the previous belt collapsed');
        $this->assertSame('maintain', DB::table('rts_user_preferences')->where('user_id', $this->agent->id)->value('belt_open'));
        $this->assertSame('maintain', $this->heads($this->page())['open'], 'a plain visit opens the belt the user left open');
        $this->assertSame('sell', $this->heads($this->page(['belt' => 'sell']))['open']);
        $this->assertSame('sell', app(BeltPreferenceService::class)->get($this->agent));
        $this->assertSame('get', $this->heads($this->page(['belt' => 'nonsense']))['open'] === 'sell' ? 'get' : 'x', 'a bad value is ignored and the remembered belt stays');
    }

    public function test_the_opening_rule_is_asked_then_remembered_then_most_late_then_get_stock(): void
    {
        $tile = fn (string $k, int $late) => ['key' => $k, 'label' => $k, 'short' => $k, 'kind' => 'backlog', 'count' => 0, 'alert' => 0, 'todo' => 0, 'moved' => 0, 'late' => $late, 'url' => [], 'lines' => []];
        $svc = app(BeltsService::class);
        $this->assertSame('get', $svc->belts($this->agent, [$tile('claimed', 0)], [], null, null)['open'], 'nothing late: Get stock');
        $this->assertSame('get', $svc->belts($this->agent, [$tile('claimed', 3)], [], null, null)['open'], 'the belt with the most late items (Get stock here)');
        $this->assertSame('sell', $svc->belts($this->agent, [$tile('claimed', 3)], [], null, 'sell')['open'], 'the remembered belt beats the default');
        $this->assertSame('maintain', $svc->belts($this->agent, [$tile('claimed', 3)], [], 'maintain', 'sell')['open'], 'the click beats the remembered belt');
    }

    public function test_every_get_stock_tile_has_four_lines_each_a_link_to_its_list_with_the_filter(): void
    {
        $html = $this->page();
        foreach (['claimed', 'owner', 'contacted', 'booked', 'prepared', 'held'] as $k) {
            $this->assertMatchesRegularExpression('/data-qa="belt-name-' . $k . '"/', $html, "{$k}: the label is a link");
            $this->assertMatchesRegularExpression('/<a href="[^"]*station=' . $k . '[^"]*" data-qa="belt-tile-' . $k . '"/', $html, "{$k}: to do");
            $this->assertMatchesRegularExpression('/<a href="[^"]*station=' . $k . '&amp;filter=moved" class="ln " data-qa="belt-line-' . $k . '-moved"/', $html, "{$k}: moved on today");
            $this->assertMatchesRegularExpression('/<a href="[^"]*station=' . $k . '&amp;filter=late" class="ln " data-qa="belt-line-' . $k . '-late">0 late</', $html, "{$k}: late");
            $this->assertStringContainsString('data-qa="belt-compare-' . $k . '"', $html, "{$k}: the compare caret stays");
        }
        foreach (['loaded', 'draft', 'active', 'advertised'] as $k) {
            $this->assertStringContainsString('data-qa="belt-line-' . $k . '-late"', $html, "{$k}: the fourth line is there too");
        }
        $this->assertStringContainsString('moved on today', $html);
    }

    public function test_maintain_and_sell_tiles_render_from_the_config_with_four_lines_and_no_caret(): void
    {
        $this->assertCount(3, BeltsService::stationsOf('maintain'));
        $this->assertCount(10, BeltsService::stationsOf('sell'));
        $m = $this->page(['belt' => 'maintain']);
        foreach (BeltsService::stationsOf('maintain') as $s) {
            $this->assertStringContainsString('data-qa="belt-name-' . $s['key'] . '"', $m);
            $this->assertStringContainsString('data-qa="belt-line-' . $s['key'] . '-late"', $m);
            $this->assertStringContainsString('data-qa="belt-line-' . $s['key'] . '-moved"', $m);
        }
        $this->assertStringNotContainsString('data-qa="belt-compare-portals"', $m, 'the compare caret belongs to Get stock');
        $s = $this->page(['belt' => 'sell']);
        foreach (BeltsService::stationsOf('sell') as $st) {
            $this->assertStringContainsString('data-qa="belt-name-' . $st['key'] . '"', $s);
        }
        $this->assertSame(10, preg_match_all('/data-qa="belt-tile-[a-z_]+"/', $s), 'ten Sell-stock tiles');
    }

    public function test_maintain_counts_come_from_the_agents_own_active_properties_and_nothing_is_invented(): void
    {
        $this->property($this->agent->id, ['address' => '1 Live Road']);   // active, not syndicated, no post, no link: to do at all three
        $this->property($this->agent->id, ['address' => '2 Portal Road', 'p24_activated_at' => now(), 'p24_last_submitted_at' => now()]);
        $this->property($this->mate->id, ['address' => '3 Colleague Road']);                    // a colleague's property never counts
        $this->property($this->agent->id, ['address' => '4 Draft Road', 'status' => 'draft']);   // not Active
        $f = app(BeltsService::class)->maintainFigures($this->agent, now()->startOfDay());
        $this->assertSame(['todo' => 1, 'moved' => 1, 'late' => 0], $f['portals'], 'one syndicated today, one still to do');
        $this->assertSame(['todo' => 2, 'moved' => 0, 'late' => 0], $f['social']);
        $this->assertSame(['todo' => 2, 'moved' => 0, 'late' => 0], $f['sellerlink']);
        $html = $this->page(['belt' => 'maintain']);
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-portals" class="ln l1"><span class="n" data-qa="belt-figure">1<\/span> to do/', $html);
    }

    public function test_maintain_and_sell_station_lists_open_and_say_plainly_when_there_is_nothing(): void
    {
        $this->property($this->agent->id, ['address' => '1 Live Road']);
        $list = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['station' => 'portals']))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="station-simple"', $list);
        $this->assertStringContainsString('1 Live Road', $list);
        $this->assertStringContainsString('data-qa="next-marker"', $list);
        $sell = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['station' => 'lead']))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="station-empty"', $sell);
        $this->assertStringContainsString('not tracked per buyer yet', $sell);
        $this->assertSame('sell', $this->heads($sell)['open'], 'a Sell-stock list opens with the Sell belt open');
        $this->assertSame('maintain', $this->heads($list)['open'], 'a Maintain-stock list opens with the Maintain belt open');
        $this->actingAs($this->agent)->get(route('road-to-sold.index', ['station' => 'nonsense']))->assertNotFound();
    }

    public function test_the_next_action_banner_is_gone_and_the_top_row_carries_the_next_marker(): void
    {
        $html = $this->page();
        $this->assertStringNotContainsString('id="rts-next-action"', $html, 'no separate next-action banner');
        $this->assertSame(1, substr_count($html, 'data-qa="next-marker"'), 'the list\'s top row carries ONE Next marker');
        $this->assertLessThan(strpos($html, 'data-qa="next-marker"'), strpos($html, 'data-qa="intake-table"'));
    }

    public function test_tabs_search_filters_and_the_tool_buttons_are_one_toolbar_row(): void
    {
        $html = $this->page();
        $bar = substr($html, strpos($html, 'data-qa="rts-blocks"'));
        $bar = substr($bar, 0, strpos($bar, 'step-modal'));
        $this->assertStringContainsString('data-block="intake"', $bar);
        $this->assertStringContainsString('data-qa="list-filters"', $bar, 'search / Status / Filters are on the toolbar row');
        $this->assertStringContainsString('data-qa="filters-toggle"', $bar);
        $this->assertStringContainsString('data-qa="more-toggle"', $bar);
        $this->assertStringContainsString('data-qa="add-to-day"', $bar, 'Add to my day moved into More');
        $this->assertSame(1, substr_count($html, 'data-qa="list-filters"'), 'the search form is not drawn twice');
        $this->assertSame(1, substr_count($html, 'class="rts-toolbar rts-tabs"'));
    }

    public function test_the_pause_agents_and_per_listing_lines_sit_in_the_open_belts_heading(): void
    {
        $html = $this->page();
        $head = substr($html, strpos($html, 'data-qa="belt-head-get"'));
        $head = substr($head, 0, strpos($head, 'data-qa="belt-tiles"'));
        $this->assertStringContainsString('rts-belt-tools', $head);
        $this->assertStringNotContainsString('class="rts-belt-line"', $html, 'the old separate line is gone');
    }
}
