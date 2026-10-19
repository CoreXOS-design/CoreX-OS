<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Models\Branch;
use App\Models\LeaseBreach;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-462 - the breach register's status tiles: one per real state, a click filters, a second click clears, and each tile's number is exactly the
 * number of rows its filter shows (same query, same own / branch / agency scope, with search / sort / pagination alive).
 */
final class BreachRegisterTilesTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function breach(array $over = []): LeaseBreach
    {
        return LeaseBreach::withoutGlobalScopes()->create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'lease_id' => $this->lease->id, 'kind' => 'payment', 'breach_party' => 'tenant',
            'amount' => 1200, 'status' => 'issued', 'issued_on' => Carbon::today()->subDays(3)->toDateString(), 'remedy_business_days' => 7, 'remedy_day_basis' => 'calendar',
            'remedy_deadline' => Carbon::today()->addDays(4)->toDateString(), 'issued_by_user_id' => $this->agent->id,
        ]);
    }

    private function seedOneOfEach(): array
    {
        return [
            'open' => $this->breach(['reason' => 'open one']),
            'reached' => $this->breach(['reason' => 'reached one', 'remedy_deadline' => Carbon::today()->toDateString()]),
            'reached2' => $this->breach(['reason' => 'reached two', 'remedy_deadline' => Carbon::today()->subDays(2)->toDateString()]),
            'remedied' => $this->breach(['reason' => 'remedied one', 'status' => 'remedied', 'remedy_deadline' => Carbon::today()->subDays(2)->toDateString()]),
            'vacate' => $this->breach(['reason' => 'vacate one', 'status' => 'escalated', 'remedy_deadline' => Carbon::today()->subDays(9)->toDateString()]),
        ];
    }

    private function page(array $query = [], ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->agent)->get(route('corex.breaches.index', $query))->assertOk()->getContent();
    }

    private function rows(string $html): int
    {
        return substr_count($html, 'data-breach-row="');
    }

    private const LABELS = ['total' => 'Total', 'open' => 'Open', 'reached' => 'Remedy date reached', 'remedied' => 'Remedied', 'vacate_sent' => 'Notice to vacate sent'];

    /** The shared rental list tile (<x-rental-list-controls>): <a href style class="pstat-v2"> <div>COUNT</div> <div>LABEL</div> </a>. @return array{href:string,count:int,active:bool}|null */
    private function tile(string $html, string $key): ?array
    {
        $re = '/<a href="([^"]*)"\s+class="pstat-v2[^"]*"\s+style="([^"]*)">\s*<div class="min-w-0">\s*<div[^>]*>([\d,]+)<\/div>\s*<div[^>]*>\s*' . preg_quote(self::LABELS[$key], '/') . '\s*<\/div>/';

        return preg_match($re, $html, $m) ? ['href' => html_entity_decode($m[1]), 'count' => (int) str_replace(',', '', $m[3]), 'active' => str_contains($m[2], 'border-color')] : null;
    }

    private function tileCount(string $html, string $key): int
    {
        return $this->tile($html, $key)['count'] ?? -1;
    }

    public function test_one_tile_per_real_state_with_its_count(): void
    {
        $this->seedOneOfEach();

        $html = $this->page();

        $this->assertStringContainsString('data-qa="breach-tiles"', $html);
        // the shared component, not a hand-made strip: its own grid, its own tile classes, a Total tile first
        $this->assertStringContainsString('class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"', $html);
        $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr))', $html);
        $this->assertSame(5, $this->tileCount($html, 'total'));
        $this->assertTrue($this->tile($html, 'total')['active'], 'Total is the lit tile when no filter is on');
        $this->assertSame(1, $this->tileCount($html, 'open'));
        $this->assertSame(2, $this->tileCount($html, 'reached'));
        $this->assertSame(1, $this->tileCount($html, 'remedied'));
        $this->assertSame(1, $this->tileCount($html, 'vacate_sent'));
        foreach (['Open', 'Remedy date reached', 'Remedied', 'Notice to vacate sent'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertSame(5, $this->rows($html), 'with no tile every breach shows');
    }

    public function test_clicking_a_tile_filters_to_exactly_its_count_and_clicking_it_again_clears(): void
    {
        $this->seedOneOfEach();

        foreach (['open' => 1, 'reached' => 2, 'remedied' => 1, 'vacate_sent' => 1] as $key => $expected) {
            $html = $this->page(['tile' => $key]);
            $this->assertSame($expected, $this->rows($html), "tile {$key} shows its count");
            $this->assertSame($expected, $this->tileCount($html, $key), "tile {$key} says its count");
            $this->assertTrue($this->tile($html, $key)['active'], "tile {$key} is lit");
            $this->assertFalse($this->tile($html, 'total')['active'], 'and Total is not');
            // the active tile's link clears the filter
            $this->assertStringNotContainsString('tile=', $this->tile($html, $key)['href'], 'clicking the active tile again clears it');
        }
        $this->assertStringContainsString('reached one', $this->page(['tile' => 'reached']));
        $this->assertStringNotContainsString('open one', $this->page(['tile' => 'reached']));
    }

    public function test_the_tile_that_is_not_active_links_to_itself_and_keeps_the_other_filters(): void
    {
        $this->seedOneOfEach();

        $html = $this->page(['q' => 'one', 'kind' => 'payment', 'sort' => 'amount', 'dir' => 'asc']);

        $href = $this->tile($html, 'reached')['href'];
        foreach (['tile=reached', 'q=one', 'kind=payment', 'sort=amount', 'dir=asc'] as $part) {
            $this->assertStringContainsString($part, $href);
        }
        $this->assertStringNotContainsString('page=', $href, 'a new filter starts on page 1');
    }

    public function test_the_counts_follow_the_search_and_filters_and_still_equal_the_rows(): void
    {
        $this->seedOneOfEach();

        // a search that only matches "reached two" and "remedied one"
        $html = $this->page(['q' => 'two']);
        $this->assertSame(1, $this->tileCount($html, 'reached'));
        $this->assertSame(0, $this->tileCount($html, 'open'));
        $this->assertSame(1, $this->rows($html));
        $this->assertSame($this->tileCount($this->page(['q' => 'two']), 'reached'), $this->rows($this->page(['q' => 'two', 'tile' => 'reached'])));

        // every tile, under a filter, equals its rows
        foreach (['open', 'reached', 'remedied', 'vacate_sent'] as $key) {
            $base = ['kind' => 'payment', 'party' => 'tenant', 'date_from' => Carbon::today()->subDays(10)->toDateString()];
            $this->assertSame($this->tileCount($this->page($base), $key), $this->rows($this->page($base + ['tile' => $key])), "tile {$key} under filters");
        }

        // a landlord breach is out of a "tenant" filter
        $this->breach(['breach_party' => 'landlord', 'kind' => 'other', 'amount' => null, 'reason' => 'landlord one']);
        $this->assertSame(1, $this->tileCount($this->page(['party' => 'landlord']), 'open'));
        $this->assertSame(1, $this->tileCount($this->page(['party' => 'tenant']), 'open'));
    }

    public function test_sort_and_pagination_work_with_a_tile_active(): void
    {
        foreach (range(1, 27) as $i) {
            $this->breach(['reason' => 'bulk ' . $i, 'amount' => 100 + $i]);
        }
        $this->breach(['reason' => 'remedied x', 'status' => 'remedied']);

        $one = $this->page(['tile' => 'open']);
        $this->assertSame(25, $this->rows($one));
        $this->assertSame(27, $this->tileCount($one, 'open'));
        $this->assertStringContainsString('tile=open', html_entity_decode($one), 'the pager keeps the tile');
        $two = $this->page(['tile' => 'open', 'page' => 2]);
        $this->assertSame(2, $this->rows($two));
        $this->assertStringNotContainsString('remedied x', $two);

        $asc = $this->page(['tile' => 'open', 'sort' => 'amount', 'dir' => 'asc']);
        $this->assertLessThan(strpos($asc, 'bulk 2<'), strpos($asc, 'bulk 1<'), 'sorted by amount ascending within the tile');
    }

    public function test_the_tiles_obey_own_branch_agency_scope_like_the_list(): void
    {
        $this->seedOneOfEach();
        $branch2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Second', 'code' => 'S2-' . $this->agency->id, 'is_active' => true]);
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch2->id, 'role' => 'branch_two']);
        foreach (['rental_breaches.view', 'leases.view'] as $key) {
            RolePermission::create(['role' => 'branch_two', 'permission_key' => $key, 'scope' => 'branch']);
        }
        PermissionService::clearCache();

        $html = $this->page([], $other);

        $this->assertSame(0, $this->rows($html), 'another branch sees none of this branch\'s breaches');
        foreach (['open', 'reached', 'remedied', 'vacate_sent'] as $key) {
            $this->assertSame(0, $this->tileCount($html, $key), "tile {$key} counts only what this person may see");
        }
        $this->assertSame(0, $this->rows($this->page(['tile' => 'reached'], $other)));
    }

    public function test_archived_breaches_are_counted_only_when_the_archived_filter_is_on(): void
    {
        $this->seedOneOfEach()['open']->delete();

        $this->assertSame(0, $this->tileCount($this->page(), 'open'));
        $archived = $this->page(['archived' => 1]);
        $this->assertSame(1, $this->tileCount($archived, 'open'));
        $this->assertSame(1, $this->rows($this->page(['archived' => 1, 'tile' => 'open'])));
    }

    public function test_days_left_reads_left_due_today_or_overdue_and_only_an_open_breach_has_one(): void
    {
        $at = fn (int $offset, array $over = []) => $this->breach($over + ['remedy_deadline' => Carbon::today()->addDays($offset)->toDateString()]);

        $this->assertSame('7 days left', $at(7)->daysLeftText());
        $this->assertSame('1 day left', $at(1)->daysLeftText());
        $this->assertSame('due today', $at(0)->daysLeftText());
        $this->assertSame('1 day overdue', $at(-1)->daysLeftText());
        $this->assertSame('3 days overdue', $at(-3)->daysLeftText());
        $this->assertTrue($at(-1)->isOverdue());
        $this->assertFalse($at(0)->isOverdue(), 'due today is not yet overdue');
        $this->assertFalse($at(2)->isOverdue());
        $this->assertNull($at(-3, ['status' => 'remedied'])->daysLeftText(), 'a remedied breach has nothing to count');
        $this->assertFalse($at(-3, ['status' => 'remedied'])->isOverdue());
        $this->assertNull($at(-3, ['status' => 'escalated'])->daysLeftText());
    }

    public function test_the_register_row_and_the_lease_panel_turn_red_once_the_date_has_passed_with_no_remedy(): void
    {
        $open = $this->breach(['reason' => 'a open', 'remedy_deadline' => Carbon::today()->addDays(7)->toDateString()]);
        $today = $this->breach(['reason' => 'b today', 'remedy_deadline' => Carbon::today()->toDateString()]);
        $late = $this->breach(['reason' => 'c late', 'remedy_deadline' => Carbon::today()->subDay()->toDateString()]);
        $fixed = $this->breach(['reason' => 'd fixed', 'status' => 'remedied', 'remedy_deadline' => Carbon::today()->subDays(5)->toDateString()]);

        $html = $this->page();
        $row = fn (LeaseBreach $b) => (string) preg_replace('/^.*?(<tr[^>]*data-breach-row="' . $b->id . '".*?<\/tr>).*$/s', '$1', $html);

        $this->assertStringContainsString('7 days left', $row($open));
        $this->assertStringNotContainsString('data-overdue', $row($open));
        $this->assertStringContainsString('due today', $row($today));
        $this->assertStringNotContainsString('data-overdue', $row($today));
        $this->assertStringContainsString('1 day overdue', $row($late));
        $this->assertStringContainsString('data-overdue="1"', $row($late));
        $this->assertStringContainsString('#b91c1c', $row($late), 'the figure is red');
        $this->assertStringNotContainsString('overdue', $row($fixed));
        $this->assertStringNotContainsString('data-qa="days-left"', $row($fixed));

        // the same figure and the same red on the lease's breach panel
        $panel = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $lateRow = (string) preg_replace('/^.*?(<div[^>]*data-breach-row="' . $late->id . '".*?data-qa="days-left".*?<\/strong>).*$/s', '$1', $panel);
        $this->assertStringContainsString('data-overdue="1"', $lateRow);
        $this->assertStringContainsString('(1 day overdue)', $lateRow);
        $this->assertStringContainsString('(7 days left)', $panel);

        // and on the breach screen itself
        $this->assertStringContainsString('1 day overdue', $this->actingAs($this->agent)->get(route('corex.breaches.show', $late->id))->getContent());
    }

    public function test_the_register_uses_the_same_tile_markup_as_the_leases_list_and_its_per_page_and_archived_controls(): void
    {
        foreach (range(1, 12) as $i) {
            $this->breach(['reason' => 'row ' . $i]);
        }

        $register = $this->page(['tile' => 'open', 'per_page' => 10]);
        $leases = $this->actingAs($this->agent)->get(route('corex.leases.index'))->assertOk()->getContent();

        // identical tile classes in both screens: one component, not a copy
        $tileClass = 'class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"';
        $this->assertStringContainsString($tileClass, $register);
        $this->assertStringContainsString($tileClass, $leases);
        $this->assertStringContainsString('text-lg font-bold leading-none tabular-nums', $register);
        $this->assertStringNotContainsString('text-base font-bold leading-none tabular-nums flex-shrink-0', $register, 'no hand-made tile left');

        // per page (the shared control) and the shared Show archived toggle
        $this->assertSame(10, $this->rows($register));
        $this->assertStringContainsString('Per page', $register);
        $this->assertStringContainsString('Show archived', $register);
        $this->assertStringContainsString('per_page=10', html_entity_decode($register), 'the pager keeps the page size');
        $this->assertSame(12, $this->tileCount($register, 'open'));
        $this->assertSame(12, $this->rows($this->page(['tile' => 'open', 'per_page' => 50])));
        $this->assertSame(12, $this->rows($this->page(['per_page' => 7])), 'an unknown page size falls back to 25, which shows all 12');
    }

    public function test_an_empty_tile_says_so_with_a_real_empty_state_and_an_unknown_tile_is_ignored(): void
    {
        $this->breach();

        $html = $this->page(['tile' => 'remedied']);
        $this->assertSame(0, $this->rows($html));
        $this->assertStringContainsString('data-qa="breach-empty"', $html);
        $this->assertStringContainsString('No breach notices match these filters.', $html);

        $this->assertSame(1, $this->rows($this->page(['tile' => 'nonsense'])), 'an unknown tile is just no tile');
    }
}
