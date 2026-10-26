<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\Agency;
use App\Models\AgentArticle;
use App\Models\Branch;
use App\Models\ImpersonationLog;
use App\Models\TrainingCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * My Portal "no scrolling" rulings (Johan, 26 Oct 2026; spec my-portal-restyle.md section 9).
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT: PHPUnit has no layout engine, so a pixel height cannot be asserted here. The
 * height budget is therefore enforced two ways:
 *   1. THIS TEST - a server-side row/structure budget per tab: every tab and sub-tab exists and is reachable, no sub-tab
 *      pane is orphaned, lists are capped (rows drawn on the page never exceed the stated cap, with a "Show all" /
 *      pager to the rest), no inner scroll box is left in the page, and the Tools token + Chrome extension merge.
 *   2. scripts/my-portal-fit-check.mjs - a real Chromium opens the page at 1366x768 and 1536x735 and measures every
 *      tab and sub-tab (run against QA1 as an agent and as Johan; results recorded in the spec).
 */
final class MyPortalNoScrollTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
            'portal_show_api_token' => true,
        ]);
        $this->actingAs($this->agent);
    }

    private function page(): string
    {
        return $this->get(route('agent.portal'))->assertOk()->getContent();
    }

    /** @return array<string, list<string>> tab => sub-tab keys, read from the rendered page */
    private function subsByTab(string $html): array
    {
        $out = [];
        preg_match_all('/data-portal-pane="(\w+)"/', $html, $m, PREG_OFFSET_CAPTURE);
        $panes = $m[1];
        foreach ($panes as $i => [$tab, $offset]) {
            $end = $panes[$i + 1][1] ?? strlen($html);
            $slice = substr($html, $offset, $end - $offset);
            preg_match_all('/data-portal-sub="([^"{]+|\{\{[^"]*\}\})"/', $slice, $s);
            $out[$tab] = array_merge($out[$tab] ?? [], $s[1]);
        }

        return $out;
    }

    public function test_every_tab_and_sub_tab_renders_with_its_hooks(): void
    {
        $html = $this->page();

        foreach (['overview', 'profile', 'favourites', 'tools', 'documents', 'compliance', 'training', 'password'] as $tab) {
            $this->assertStringContainsString('data-portal-tab="' . $tab . '"', $html, "tab button {$tab}");
            $this->assertStringContainsString('data-portal-pane="' . $tab . '"', $html, "tab pane {$tab}");
        }

        $subs = $this->subsByTab($html);
        foreach (['whereami', 'targets', 'myactivity', 'earnings', 'compliance', 'activity'] as $k) {
            $this->assertContains($k, $subs['overview'], "overview sub-tab {$k}");
        }
        foreach (['signature', 'photo', 'details', 'website', 'admin', 'articles'] as $k) {
            $this->assertContains($k, $subs['profile'], "profile sub-tab {$k}");
        }
        foreach (['intro', 'mine', 'all'] as $k) {
            $this->assertContains($k, $subs['favourites'], "favourites sub-tab {$k}");
        }
        $this->assertContains('rmcp', $subs['training']);
        $this->assertSame(['update', 'delete'], $subs['password']);
    }

    public function test_no_sub_tab_pane_is_unreachable_and_no_button_opens_nothing(): void
    {
        $html = $this->page();

        preg_match_all('/data-portal-sub="([^"]+)" @click="sub\.(\w+) = \'([^\']+)\'"/', $html, $buttons, PREG_SET_ORDER);
        $this->assertNotEmpty($buttons);
        foreach ($buttons as [, , $tab, $key]) {
            if (str_contains($key, '{{')) {
                continue; // a Blade-expanded key (document types) - covered by the documents test below
            }
            $this->assertMatchesRegularExpression('/x-show="sub\.' . $tab . ' === \'' . preg_quote($key, '/') . '\'"/', $html, "button {$tab}/{$key} opens a pane");
        }

        preg_match_all('/x-show="sub\.(\w+) === \'([^\']+)\'"/', $html, $panes, PREG_SET_ORDER);
        foreach ($panes as [, $tab, $key]) {
            $this->assertStringContainsString("@click=\"sub.{$tab} = '{$key}'\"", $html, "pane {$tab}/{$key} has a button");
        }
    }

    public function test_tools_api_token_and_chrome_extension_are_one_sub_tab_in_the_right_order(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString("@click=\"sub.tools = 'api'\"", $html, 'the separate API Token sub-tab is gone');
        $this->assertStringContainsString("@click=\"sub.tools = 'chrome'\"", $html);
        $this->assertStringContainsString('>Chrome extension</button>', $html);

        $token = strpos($html, 'data-testid="chrome-step-token"');
        $install = strpos($html, 'data-testid="chrome-step-install"');
        $connect = strpos($html, 'data-testid="chrome-step-connect"');
        $this->assertNotFalse($token);
        $this->assertTrue($token < $install && $install < $connect, 'token, then install, then connect');
        $this->assertStringContainsString('Generate Token', $html);
        $this->assertStringContainsString('Regenerate', $html);
        $this->assertStringContainsString('chromewebstore.google.com', $html);
    }

    public function test_chrome_extension_sub_tab_stays_hidden_when_the_agent_has_no_token_access(): void
    {
        $this->agent->forceFill(['portal_show_api_token' => false])->save();

        $html = $this->page();

        $this->assertStringNotContainsString("@click=\"sub.tools = 'chrome'\"", $html);
        $this->assertStringNotContainsString('data-testid="chrome-extension-pane"', $html);
    }

    public function test_no_inner_scroll_box_or_fixed_tall_list_is_left_in_the_page(): void
    {
        $html = $this->page();
        $from = (int) strpos($html, 'data-portal-pane="overview"');
        $to = strpos($html, '</main>', $from);
        $content = substr($html, $from, $to === false ? null : $to - $from); // the portal's own content, not the layout's scripts

        $this->assertStringNotContainsString('max-height:460px', $content, 'the old scrolling All Pages list');
        $this->assertStringNotContainsString('corex-brand-scroll', $content);
        $this->assertDoesNotMatchRegularExpression('/overflow-y\s*:\s*(auto|scroll)/', $content);
        $this->assertStringNotContainsString('space-y-5', $content, 'the old spaced wrapper');
    }

    public function test_the_header_is_one_row_with_the_identity_in_it(): void
    {
        $html = $this->page();

        $this->assertStringContainsString($this->agent->name, $html);
        $this->assertStringNotContainsString('Your profile, documents, compliance, training and earnings in one place.', $html, 'helper line removed');
        $this->assertSame(1, substr_count($html, 'data-tour="portal-home-intro"'));
    }

    public function test_where_i_am_puts_you_vs_branch_vs_company_next_to_the_figures_not_below_them(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-testid="targets-vs-actuals"', $html);
        $this->assertStringContainsString('data-testid="you-vs-branch-company"', $html);
        $this->assertStringContainsString('data-testid="momentum"', $html);
        // The 4 figures sit in ONE four-across row and the comparison + momentum in ONE two-across row.
        $this->assertMatchesRegularExpression('/mp-grid mp-g4[^"]*"[^>]*data-testid="targets-vs-actuals"/', $html);
        $this->assertMatchesRegularExpression('/class="mp-card" data-testid="you-vs-branch-company"/', $html);
    }

    public function test_lists_are_capped_with_a_way_to_the_rest(): void
    {
        // 14 required courses: only 10 are drawn, with a Show all link to the full training page.
        foreach (range(1, 14) as $i) {
            TrainingCourse::create([
                'agency_id' => $this->agency->id, 'title' => 'Course ' . $i, 'is_required' => true, 'is_published' => true, 'sort_order' => $i,
            ]);
        }
        // 7 admin access entries: 4 drawn, the rest counted.
        foreach (range(1, 7) as $i) {
            ImpersonationLog::create(['admin_user_id' => $this->agent->id, 'target_user_id' => $this->agent->id, 'action' => 'start', 'created_at' => now()->subDays($i)]);
        }
        // 9 articles: all reachable, six to a page.
        foreach (range(1, 9) as $i) {
            AgentArticle::create(['agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'title' => 'Article ' . $i, 'body' => 'Body', 'is_published' => false]);
        }

        $html = $this->page();

        $this->assertRowsWithin($html, 'training-items', 10);
        $this->assertStringContainsString('Show all (14)', $html);
        $this->assertRowsWithin($html, 'admin-access', 4, true);
        $this->assertStringContainsString('and 3 earlier in the last 30 days', $html);
        $this->assertStringContainsString('per: 6', $html, 'articles page six at a time');
        $this->assertSame(9, substr_count($html, 'class="mp-row" x-show="'), 'every article stays reachable through the pager');
    }

    /** Rows drawn inside the element carrying data-portal-rows="name" never exceed its data-portal-cap. */
    private function assertRowsWithin(string $html, string $name, int $cap, bool $entries = false): void
    {
        $this->assertMatchesRegularExpression('/data-portal-rows="' . $name . '" data-portal-cap="' . $cap . '"/', $html, "{$name} states its cap");
        $start = strpos($html, 'data-portal-rows="' . $name . '"');
        $this->assertNotFalse($start);
        $needle = $entries ? 'justify-between text-xs">' : 'class="mp-row"';
        $slice = substr($html, $start, 12000);
        $next = strpos($slice, 'data-portal-rows="', 10);
        if ($next !== false) {
            $slice = substr($slice, 0, $next);
        }
        $this->assertLessThanOrEqual($cap, substr_count($slice, $needle), "{$name} draws at most {$cap} rows");
    }

    /** An active assignment to the fixture agent, with the one portal grant an assistant needs to open My Portal at all. */
    private function makeAssistantAssignment(User $assistant): void
    {
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        \App\Models\Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);
        $assignment = \App\Models\AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id, 'status' => \App\Models\AssistantAssignment::STATUS_ACTIVE,
        ]);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id, 'scope' => 'all']);
        \App\Models\AssistantAssignmentPermission::create([
            'assistant_assignment_id' => $assignment->id, 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id, 'granted' => true, 'scope' => null,
        ]);
        \App\Services\PermissionService::clearCache();
        \App\Models\Role::clearCache();
        User::flushAssistantsEnabledCache();
        \App\Services\PermissionService::forceProductionPosture();
    }

    public function test_my_performance_shows_only_my_own_figures_with_one_link_to_the_full_report(): void
    {
        $me = $this->agent->id;
        $this->mock(\App\Services\ReportingService::class, function ($m) use ($me) {
            // The ONLY id the figures are ever asked for is the signed-in agent's: own scope by construction.
            $m->shouldReceive('getAgentMetrics')->with($me, 30)->once()->andReturn([
                'events_completed' => 12, 'events_prior' => 10, 'viewings' => 7, 'presentations' => 3, 'feedback_rate' => 80.0,
                'active_buyers' => 5, 'high_risk_buyers' => 1, 'lost_deals' => 2, 'lost_value' => 1500000.0,
            ]);
            $m->shouldReceive('getConversionFunnel')->with(['user_id' => $me], 30)->once()->andReturn([
                ['stage' => 'Leads', 'count' => 9, 'rate' => null], ['stage' => 'First viewing', 'count' => 4, 'rate' => 44.4],
            ]);
            $m->shouldReceive('getAgentInsights')->with($me, 30)->once()->andReturn(['One', 'Two', 'Three', 'Four', 'Five']);
        });

        $html = $this->page();

        $this->assertStringContainsString("@click=\"sub.overview = 'performance'\"", $html);
        $this->assertStringContainsString('data-testid="portal-my-performance"', $html);
        $this->assertStringContainsString('data-testid="perf-viewings">7<', $html);
        $this->assertStringContainsString('+20%', $html);
        $this->assertStringContainsString('First viewing', $html);
        $this->assertStringContainsString(route('command-center.reporting.agent', ['days' => 30]), $html, 'one link to the full report');
        $this->assertSame(1, substr_count($html, 'data-testid="full-performance-link"'));
        $this->assertStringNotContainsString('>Five<', $html, 'insights are capped at four');
    }

    public function test_my_performance_opens_from_the_sub_parameter_and_honours_the_range_chips(): void
    {
        $html = $this->get(route('agent.portal', ['sub' => 'performance', 'days' => 90]))->assertOk()->getContent();

        $this->assertStringContainsString("sub: { overview: 'performance'", $html);
        $this->assertStringContainsString('last 90 days', $html);
        $this->assertStringContainsString('days=365', $html);
    }

    public function test_an_assistant_has_no_my_performance(): void
    {
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);
        $this->makeAssistantAssignment($assistant);
        $this->actingAs($assistant->fresh());

        $html = $this->get(route('agent.portal', ['sub' => 'performance']))->assertOk()->getContent();

        $this->assertStringNotContainsString('portal-my-performance', $html);
        $this->assertStringNotContainsString("sub.overview = 'performance'", $html);
    }

    public function test_an_assistant_still_gets_only_the_stripped_down_tabs(): void
    {
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agent->branch_id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);
        $this->makeAssistantAssignment($assistant);
        $this->actingAs($assistant->fresh());

        $html = $this->get(route('agent.portal'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-portal-tab="tools"', $html);
        $this->assertStringNotContainsString('data-portal-tab="payslips"', $html);
        $this->assertStringNotContainsString('data-portal-tab="leave"', $html);
        $this->assertStringNotContainsString("@click=\"sub.overview = 'earnings'\"", $html);
        $this->assertStringContainsString('data-portal-tab="documents"', $html);
    }
}
