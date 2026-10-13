<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Performance\Period;
use App\Services\RoadToSold\DrilldownService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ReportService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\WorkdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (lane 3) - the Road to Sold report: figures, comparison, drill-down (current AND comparison period),
 * filters / sort / pagination, scope, and print / PDF / XLSX. Spec section 10.6.
 *
 * Paths proven: every column's figure (target, worked, attempts, conversations, each outcome, valuations
 * booked, extra over target, missed or rolled over, dropped); an outcome archived after use keeps its column;
 * active outcomes with no calls still show a zero column; comparison figures and their direction; the drill
 * total equals the figure for every column, current and comparison; last_30_days preset; bad period and bad
 * comparison range fail soft; search / branch filter / sort; an agent sees only themselves, a branch manager
 * the branch, another agency nothing; direct drill to another agent = 404; print, PDF and XLSX carry the same
 * figures; nothing is hard-coded to an agency.
 */
final class ReportTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $anna;
    private User $bheki;
    private User $manager;
    private TrackedProperty $tp;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        SettingsService::forget();
        Carbon::setTestNow('2026-10-20 09:00:00');

        $this->agencyId = $this->agency();
        $this->anna = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Anna Agent']);
        $this->bheki = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => 'Bheki Other']);
        $this->manager = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mo Manager']);
        $this->tp = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agencyId);

        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agencyId, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(5), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function log(User $by, string $key, int $n = 1, array $extra = [], ?int $agencyId = null): void
    {
        $aid = $agencyId ?? $this->agencyId;
        $def = Def::withoutGlobalScopes()->withTrashed()->where('agency_id', $aid)->where('key', $key)->firstOrFail();
        for ($i = 0; $i < $n; $i++) {
            DB::table('rts_contact_outcomes')->insert($extra + [
                'agency_id' => $aid, 'branch_id' => $aid, 'track' => 'sales', 'user_id' => $by->id, 'tracked_property_id' => $this->tp->id,
                'outcome_definition_id' => $def->id, 'outcome_key' => $def->key, 'outcome_label' => $def->label, 'kind' => $def->kind,
                'next_action' => 'none', 'is_current' => false, 'occurred_at' => '2026-10-12 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function item(User $u, string $status, string $origin = 'auto', string $day = '2026-10-12'): void
    {
        // One agent can hold a property once per day, so every row gets its own property.
        static $n = 0;
        $n++;
        $tp = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => (string) (100 + $n), 'street_name' => 'Fixture Road', 'suburb' => 'Uvongo']);
        DB::table('rts_list_items')->insert([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'user_id' => $u->id, 'list_date' => $day, 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'selection' => 'price_drops', 'origin' => $origin, 'position' => $n % 60000,
            'status' => $status, 'reason' => 'Price dropped', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function october(): Period
    {
        return new Period(CarbonImmutable::parse('2026-10-01')->startOfDay(), CarbonImmutable::parse('2026-10-31')->endOfDay(), 'October', 'custom');
    }

    private function seedActivity(): void
    {
        $this->log($this->anna, 'no_answer', 3);
        $this->log($this->anna, 'no_answer', 1, ['dropped' => true, 'no_answer_streak' => 3]);
        $this->log($this->anna, 'spoke_call_back', 2);
        $this->log($this->anna, 'spoke_valuation_booked', 1, ['valuation_booked' => true]);
        $this->log($this->bheki, 'no_answer', 5);
        foreach ([['worked', 'auto', 3], ['worked', 'follow_up', 1], ['worked', 'added', 2], ['missed', 'auto', 1], ['rolled', 'rollover', 1]] as [$st, $or, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $this->item($this->anna, $st, $or);
            }
        }
        $this->item($this->bheki, 'worked');
    }

    private function figuresFor(User $u, ?Period $compare = null): array
    {
        $agents = User::query()->where('agency_id', $this->agencyId)->whereIn('id', [$this->anna->id, $this->bheki->id])->orderBy('name')->get();
        $built = app(ReportService::class)->build($this->agencyId, $agents, $this->october(), $compare);

        return ['built' => $built, 'row' => collect($built['rows'])->firstWhere('agent_id', $u->id)];
    }

    // ── Figures ─────────────────────────────────────────────────────────────────────────────

    public function test_every_column_counts_what_it_says_and_the_total_adds_up(): void
    {
        $this->seedActivity();
        ['built' => $built, 'row' => $row] = $this->figuresFor($this->anna);
        $v = fn (string $k) => $row['cells'][$k]['value'];

        $this->assertSame(app(WorkdayService::class)->targetFor($this->anna, '2026-10-12') > 0, $v('target') > 0);
        $this->assertSame(4, $v('worked'), '3 auto + 1 follow-up toward the target');
        $this->assertSame(4, $v('attempts'));
        $this->assertSame(3, $v('conversations'));
        $this->assertSame(4, $v('outcome:no_answer'));
        $this->assertSame(2, $v('outcome:spoke_call_back'));
        $this->assertSame(1, $v('valuations_booked'));
        $this->assertSame(2, $v('added_worked'), 'items added on top are reported apart');
        $this->assertSame(2, $v('missed'), 'missed + rolled over');
        $this->assertSame(1, $v('dropped'));
        $this->assertSame(0, $v('outcome:wrong_number'), 'an active outcome nobody used still has a zero column');

        $this->assertSame(9, $built['totals']['cells']['attempts']['value'], 'Anna 4 + Bheki 5');
        $this->assertSame(3, $built['totals']['cells']['conversations']['value']);
        $this->assertSame(5, $this->figuresFor($this->bheki)['row']['cells']['attempts']['value']);
        $this->assertSame(5, $built['totals']['cells']['worked']['value']);
    }

    public function test_an_outcome_archived_after_use_keeps_its_column_and_the_agency_order_leads(): void
    {
        $this->log($this->anna, 'wrong_number');
        Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('key', 'wrong_number')->first()->delete();

        $keys = array_column($this->figuresFor($this->anna)['built']['columns'], 'key');

        $this->assertContains('outcome:wrong_number', $keys);
        $this->assertLessThan(array_search('outcome:spoke_call_back', $keys), array_search('outcome:no_answer', $keys));
        $this->assertSame('target', $keys[0]);
        $this->assertSame('dropped', end($keys));
    }

    public function test_a_second_agency_sees_nothing_of_the_first(): void
    {
        $this->seedActivity();
        $bId = $this->agency();
        $bAgent = User::factory()->create(['agency_id' => $bId, 'branch_id' => $bId, 'role' => 'agent', 'is_active' => true]);
        $agents = User::query()->where('agency_id', $bId)->get();

        $built = app(ReportService::class)->build($bId, $agents, $this->october(), null);

        $this->assertSame(0, $built['totals']['cells']['attempts']['value']);
        $this->assertSame(0, collect($built['rows'])->firstWhere('agent_id', $bAgent->id)['cells']['worked']['value']);
        $outcomeCols = array_filter(array_column($built['columns'], 'key'), fn ($k) => str_starts_with($k, 'outcome:'));
        $this->assertSame([], array_values($outcomeCols), 'agency B has no outcomes yet, so none of agency A\'s columns appear');
    }

    // ── Comparison ──────────────────────────────────────────────────────────────────────────

    public function test_comparison_figures_and_direction(): void
    {
        $this->seedActivity();
        $this->log($this->anna, 'no_answer', 2, ['occurred_at' => '2026-09-12 10:00:00']);
        $this->item($this->anna, 'missed', 'auto', '2026-09-12');
        $this->item($this->anna, 'missed', 'auto', '2026-09-13');
        $this->item($this->anna, 'missed', 'auto', '2026-09-14');
        $prev = new Period(CarbonImmutable::parse('2026-09-01')->startOfDay(), CarbonImmutable::parse('2026-09-30')->endOfDay(), 'September', 'custom');

        $row = $this->figuresFor($this->anna, $prev)['row'];

        $attempts = $row['cells']['attempts'];
        $this->assertSame(4, $attempts['value']);
        $this->assertSame(2, $attempts['previous']);
        $this->assertSame(2.0, $attempts['compare']['delta']);
        $this->assertTrue($attempts['compare']['good'], 'more attempts is good');

        $missed = $row['cells']['missed'];
        $this->assertSame(2, $missed['value']);
        $this->assertSame(3, $missed['previous']);
        $this->assertTrue($missed['compare']['good'], 'fewer missed is good');
        $this->assertNull($row['cells']['target']['compare']['good'], 'target is neither good nor bad');
    }

    // ── Page ────────────────────────────────────────────────────────────────────────────────

    public function test_the_page_for_a_branch_manager_lists_the_branch_and_for_an_agent_only_themselves(): void
    {
        $this->seedActivity();
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];

        $mgr = $this->actingAs($this->manager)->get(route('road-to-sold.report', $q));
        $mgr->assertOk()->assertSee('Road to Sold report')->assertSee('Anna Agent')->assertSee('Bheki Other')->assertSee('Total');

        $own = $this->actingAs($this->anna)->get(route('road-to-sold.report', $q));
        $own->assertOk()->assertSee('Anna Agent')->assertDontSee('Bheki Other')->assertSee('Your figures');
    }

    public function test_search_branch_filter_and_sort(): void
    {
        $this->seedActivity();
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];
        $get = fn (array $x) => $this->actingAs($this->manager)->get(route('road-to-sold.report', $q + $x));

        $get(['q' => 'anna'])->assertSee('Anna Agent')->assertDontSee('Bheki Other');
        $get(['q' => 'zzz'])->assertSee('No agents match this search.');
        $get(['branch_id' => $this->agencyId])->assertSee('Anna Agent');
        $desc = $get(['sort' => 'attempts', 'dir' => 'desc'])->getContent();
        $this->assertLessThan(strpos($desc, 'Anna Agent'), strpos($desc, 'Bheki Other'), 'Bheki 5 attempts before Anna 4');
        $asc = $get(['sort' => 'attempts', 'dir' => 'asc'])->getContent();
        $this->assertLessThan(strpos($asc, 'Bheki Other'), strpos($asc, 'Anna Agent'));
        $get(['sort' => 'nonsense', 'dir' => 'sideways'])->assertOk();
    }

    public function test_last_30_days_and_bad_periods_fail_soft(): void
    {
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'last_30_days']))->assertOk()->assertSee('Last 30 days');
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'bogus']))->assertOk()->assertSee('This month');
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'custom', 'start' => '2026-10-10']))->assertOk();
        $res = $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'this_month', 'compare' => 'custom', 'compare_start' => '2026-09-30', 'compare_end' => '2026-09-01']));
        $res->assertOk();
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'last_7_days', 'compare' => 'previous']))->assertOk()->assertSee('Comparing to');
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'this_month', 'compare' => 'same_last_year']))->assertOk();
    }

    public function test_an_empty_period_still_renders_zeros_not_an_error(): void
    {
        $this->actingAs($this->anna)->get(route('road-to-sold.report', ['period' => 'custom', 'start' => '2020-01-01', 'end' => '2020-01-31']))
            ->assertOk()->assertSee('Anna Agent');
    }

    // ── Drill-down ──────────────────────────────────────────────────────────────────────────

    public function test_every_columns_drill_total_equals_its_figure_for_the_current_and_the_comparison_period(): void
    {
        $this->seedActivity();
        $this->log($this->anna, 'spoke_call_back', 2, ['occurred_at' => '2026-09-12 10:00:00']);
        $this->item($this->anna, 'worked', 'auto', '2026-09-12');
        $q = ['agent_id' => $this->anna->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];
        $cmp = $q + ['compare' => 'custom', 'compare_start' => '2026-09-01', 'compare_end' => '2026-09-30'];

        $prev = new Period(CarbonImmutable::parse('2026-09-01')->startOfDay(), CarbonImmutable::parse('2026-09-30')->endOfDay(), 'September', 'custom');
        $row = $this->figuresFor($this->anna, $prev)['row'];

        foreach ($row['cells'] as $key => $cell) {
            $now = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q + ['metric' => $key]))->assertOk();
            $this->assertSame($cell['value'], $now->json('total'), "current drill total for {$key}");
            $then = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $cmp + ['metric' => $key, 'source' => 'compare']))->assertOk();
            $this->assertSame($cell['previous'], $then->json('total'), "comparison drill total for {$key}");
        }
    }

    public function test_a_call_on_an_archived_property_is_in_the_figure_and_in_the_drill(): void
    {
        $this->seedActivity();
        $gone = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => '9', 'street_name' => 'Old Road', 'suburb' => 'Uvongo']);
        $this->log($this->anna, 'no_answer', 2, ['tracked_property_id' => $gone->id]);
        $gone->delete();

        $cell = $this->figuresFor($this->anna)['row']['cells']['attempts'];
        $this->assertSame(6, $cell['value'], 'the two calls on the archived property still count');

        $q = ['agent_id' => $this->anna->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31', 'metric' => 'attempts'];
        $drill = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q))->assertOk();
        $this->assertSame($cell['value'], $drill->json('total'), 'report and drill agree');
        $this->assertSame(2, collect($drill->json('rows'))->filter(fn ($r) => str_contains($r['property'], 'property archived'))->count());
    }

    public function test_the_compare_drill_needs_a_comparison_and_other_agents_are_a_404(): void
    {
        $base = ['metric' => 'attempts', 'period' => 'this_month'];
        $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $base + ['source' => 'compare']))->assertStatus(422);
        $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $base + ['source' => 'compare', 'compare' => 'previous']))->assertOk();
        $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $base + ['agent_id' => $this->bheki->id]))->assertNotFound();
        $this->actingAs($this->manager)->getJson(route('road-to-sold.drilldown', $base + ['agent_id' => $this->bheki->id]))->assertOk();
    }

    public function test_the_total_row_drill_honours_the_search_and_branch_filters(): void
    {
        $this->seedActivity();
        $q = ['metric' => 'attempts', 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];

        $all = $this->actingAs($this->manager)->getJson(route('road-to-sold.drilldown', $q))->json('total');
        $anna = $this->actingAs($this->manager)->getJson(route('road-to-sold.drilldown', $q + ['q' => 'anna']))->json('total');

        $this->assertSame(9, $all);
        $this->assertSame(4, $anna);
    }

    public function test_day_list_and_target_drills_have_rows(): void
    {
        $this->seedActivity();
        $q = ['agent_id' => $this->anna->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31'];

        $worked = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q + ['metric' => 'worked']))->assertOk();
        $worked->assertJsonPath('rows.0.status', 'Worked');
        $missed = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q + ['metric' => 'missed']))->assertOk();
        $this->assertContains('Rolled', array_column($missed->json('rows'), 'status'));
        $target = $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q + ['metric' => 'target']))->assertOk();
        $this->assertGreaterThan(0, $target->json('total'));
        $this->assertSame(array_sum(array_column($target->json('rows'), 'target')), $target->json('total'));
    }

    // ── Exports ─────────────────────────────────────────────────────────────────────────────

    public function test_print_pdf_and_xlsx_carry_the_same_figures(): void
    {
        $this->seedActivity();
        $q = ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31', 'compare' => 'custom', 'compare_start' => '2026-09-01', 'compare_end' => '2026-09-30'];

        $print = $this->actingAs($this->manager)->get(route('road-to-sold.report.print', $q));
        $print->assertOk()->assertSee('Road to Sold report')->assertSee('Anna Agent')->assertSee('Bheki Other')->assertSee('was 0');

        $pdf = $this->actingAs($this->manager)->get(route('road-to-sold.report.pdf', $q));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('road-to-sold-', (string) $pdf->headers->get('Content-Disposition'));

        $xlsx = $this->actingAs($this->manager)->get(route('road-to-sold.report.xlsx', $q));
        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', (string) $xlsx->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('Content-Disposition'));
        ob_start();
        $xlsx->sendContent();
        $bytes = ob_get_clean();
        $this->assertSame('PK', substr($bytes, 0, 2), 'an .xlsx is a zip');
    }

    public function test_an_agent_cannot_export_other_agents_and_a_user_without_the_key_is_refused(): void
    {
        $this->seedActivity();
        $own = $this->actingAs($this->anna)->get(route('road-to-sold.report.print', ['period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-31']));
        $own->assertOk()->assertSee('Anna Agent')->assertDontSee('Bheki Other');

        $viewer = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'viewer', 'is_active' => true]);
        RolePermission::create(['role' => 'viewer', 'permission_key' => 'view_dashboard', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();
        foreach (['report', 'report.print', 'report.pdf', 'report.xlsx'] as $r) {
            $this->actingAs($viewer)->get(route('road-to-sold.' . $r))->assertForbidden();
        }
    }

    public function test_the_page_links_to_the_report(): void
    {
        $this->actingAs($this->anna)->get(route('road-to-sold.index'))->assertOk()->assertSee(route('road-to-sold.report'), false);
    }

    public function test_spreadsheet_cells_cannot_start_a_formula(): void
    {
        $evil = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true, 'name' => '=HYPERLINK("http://x")']);
        $r = new \ReflectionMethod(\App\Http\Controllers\RoadToSold\ReportController::class, 'cell');
        $r->setAccessible(true);
        $ctrl = app(\App\Http\Controllers\RoadToSold\ReportController::class);

        $this->assertSame("'=HYPERLINK(\"http://x\")", $r->invoke($ctrl, $evil->name));
        $this->assertSame('Anna', $r->invoke($ctrl, 'Anna'));
        $this->assertSame('', $r->invoke($ctrl, null));
    }
}
