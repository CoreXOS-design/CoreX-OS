<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-14 — locks in the "Custom" period fix on the Agency Performance &
 * ROI report family (company/branch/agent pages).
 *
 * Root cause: the period <select> auto-submitted on every change, including
 * "Custom", before the user could enter dates. The date inputs were gated
 * behind a server-side @if($preset === 'custom'), which was never true on
 * that first submission (no start/end -> PeriodResolver threw -> the
 * controller's catch block reset $preset back to 'this_month' before
 * rendering) — so the inputs could never appear through the UI. The
 * branch/agent drill-down pages had no custom-date markup at all.
 *
 * Fix: a shared _period-selector partial where "Custom" reveals the date
 * inputs client-side (Alpine) instead of auto-submitting, used by all three
 * report views. These tests assert the HTTP-level outcome (no error on a
 * valid custom range, the server-side safety net still fires cleanly for a
 * malformed request, and the shared partial's markup is actually present on
 * all three pages) — the client-side Alpine behavior itself isn't something
 * a Laravel HTTP test can execute, only its rendered markup.
 */
class PeriodSelectorCustomRangeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    public function test_company_report_custom_range_with_valid_dates_renders_without_error(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('performance.agency-report', ['period' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-13']));

        $response->assertOk();
        $response->assertDontSee('A custom period requires both a start and an end date');
        $response->assertSee('value="2026-08-01"', false);
        $response->assertSee('value="2026-08-13"', false);
    }

    public function test_company_report_custom_range_missing_dates_falls_back_cleanly(): void
    {
        // The server-side safety net (for a bookmarked/malformed URL) — must
        // still degrade gracefully, never a 500, and flash the expected message.
        $response = $this->actingAs($this->user)
            ->get(route('performance.agency-report', ['period' => 'custom']));

        $response->assertOk();
        $response->assertSessionHas('period_error');
        $response->assertSee('A custom period requires both a start and an end date');
    }

    public function test_all_three_report_pages_use_the_shared_period_selector_partial(): void
    {
        $companyResponse = $this->actingAs($this->user)->get(route('performance.agency-report'));
        $branchResponse = $this->actingAs($this->user)->get(route('performance.agency-report.branch', $this->branch->id));
        $agentResponse = $this->actingAs($this->user)->get(route('performance.agency-report.agent', $this->agent->id));

        foreach (['company' => $companyResponse, 'branch' => $branchResponse, 'agent' => $agentResponse] as $label => $response) {
            $response->assertOk();
            $response->assertSee('x-model="preset"', false);
            // Same guard clause every page must carry: only auto-submit for a
            // NON-custom selection, never for "Custom" itself.
            $response->assertSee("!== 'custom'", false);
        }
    }

    public function test_branch_report_custom_range_keeps_its_own_route_context(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('performance.agency-report.branch', [
                $this->branch->id, 'period' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-13',
            ]));

        $response->assertOk();
        $response->assertSee('value="2026-08-01"', false);
        $response->assertSee('value="2026-08-13"', false);
        // The form must post back to the branch drill-down route, not the
        // company report — branch.blade.php previously had no custom-date
        // support at all, so this also proves the shared partial actually
        // wired up the per-page formAction correctly.
        $response->assertSee('/branch/' . $this->branch->id, false);
    }

    public function test_agent_report_custom_range_keeps_its_own_route_context(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('performance.agency-report.agent', [
                $this->agent->id, 'period' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-13',
            ]));

        $response->assertOk();
        $response->assertSee('value="2026-08-01"', false);
        $response->assertSee('value="2026-08-13"', false);
        $response->assertSee('/agent/' . $this->agent->id, false);
    }
}
