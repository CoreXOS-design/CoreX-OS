<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-366 frontend (cc1) — the report's own presentation layer:
 *   #7 client-side sort + filter over cc6's rollup rows, and
 *   #8 print surfaces (whole-company + single-agent printables).
 * HTTP tests assert the rendered markup/data + agency scoping; the Alpine
 * sort/filter behaviour itself isn't executable in a Laravel HTTP test, only
 * its rendered component + controls.
 */
class AgencyReportFrontendTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
            'name'      => 'Thabo Mokoena',
        ]);
    }

    public function test_index_renders_sort_and_filter_component(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report'));

        $res->assertOk()
            ->assertSee('agencyReport(', false)        // the Alpine report component is wired
            ->assertSee('sortAgent(', false)           // clickable column-header sort
            ->assertSee('branchDisplay()', false)      // branch table is component-driven too
            ->assertSee('Filter agent', false)         // agent name filter control
            ->assertSee('All branches', false)         // branch filter control
            ->assertSee('Min activity', false)         // min-activity filter control
            ->assertSee('Print report', false)         // #8 whole-company print entry point
            ->assertSee('Thabo Mokoena');              // the agent row data is present (embedded rollup JSON)
    }

    public function test_index_wires_status_toggles_and_drilldown(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report'));

        $res->assertOk()
            // #6 deal-status toggles (recompute logic present; bar reveals when cc6 ships deal_status)
            ->assertSee('toggleAll(', false)
            ->assertSee('statusKeys', false)
            ->assertSee('deals (selected)', false)
            ->assertSee('value (selected)', false)
            ->assertSee('commission (selected)', false)   // Commission tile reacts to the ticks
            ->assertSee('statusCommission(', false)       // commission recompute wired like qty/value
            ->assertSee('hasDealStatus', false)
            // #9 drilldown modal + click wiring + the contract endpoint path
            ->assertSee('drill(', false)
            ->assertSee('drillOpen', false)
            // @js() escapes slashes in the embedded URL string (agency-report\/drilldown) — match that
            ->assertSee('agency-report\/drilldown', false);
    }

    /**
     * The 422 regression: every metric key cc1's tiles AND columns send must be
     * accepted by the drilldown endpoint and return the {title,total,columns,rows}
     * envelope — never a 422 "Unknown metric". Covers all 13 providers + the short
     * aliases + the deals_created/deals_registered/commission_gross_ex_vat tile keys.
     */
    public function test_every_tile_and_column_metric_drilldown_loads_without_422(): void
    {
        $metrics = [
            'mic_claims', 'contacts', 'contacts_created', 'properties', 'properties_created',
            'presentations_created', 'portal_views', 'buyers', 'buyers_added', 'viewings',
            'appointments', 'outreach_messages', 'fica', 'fica_submissions',
            'deals', 'deals_created', 'deals_registered', 'commission_gross_ex_vat',
        ];
        foreach ($metrics as $m) {
            $res = $this->actingAs($this->admin)->getJson(route('performance.agency-report.drilldown', [
                'metric' => $m, 'level' => 'company', 'period' => 'this_month',
            ]));
            $res->assertOk();                                     // <- never 422
            $this->assertIsArray($res->json('columns'), "columns[] missing for metric {$m}");
            $this->assertArrayHasKey('total', $res->json(), "total missing for metric {$m}");
            $this->assertArrayHasKey('rows', $res->json(), "rows missing for metric {$m}");
        }
    }

    public function test_drilldown_rejects_a_genuinely_unknown_metric(): void
    {
        $this->actingAs($this->admin)->getJson(route('performance.agency-report.drilldown', [
            'metric' => 'not_a_real_metric', 'level' => 'company', 'period' => 'this_month',
        ]))->assertStatus(422);
    }

    public function test_index_has_sticky_header_and_reset_view(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report'));

        $res->assertOk()
            ->assertSee('sticky top-0', false)          // #2 pinned top block (unchanged)
            ->assertSee('Reset view', false)            // #3 reset button (unchanged)
            ->assertSee('resetView(', false)            // reset handler wired (unchanged)
            ->assertSee('x-ref="topBlock"', false);     // top block measured for the sticky offset
    }

    public function test_by_agent_column_headers_are_sticky_below_the_top_block(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report'));

        $res->assertOk()
            // the by-agent grid is a sticky bounded box, offset by the measured top-block height
            ->assertSee('report-agent-scroll', false)
            ->assertSee('top:var(--report-top-h', false)          // pins flush below the top block (no magic number)
            ->assertSee('--report-top-h', false)                   // measured CSS var is set/consumed
            ->assertSee('.report-agent-scroll thead th { position: sticky; top: 0;', false); // headers stay while rows scroll
    }

    public function test_metric_columns_fit_rotated_qty_headers_money_stays_wide(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report'));

        $res->assertOk()
            ->assertSee('report-metric-table', false)                       // both tables opt into the fit styles
            ->assertSee('metric-qty', false)                                // narrow qty columns
            ->assertSee("isMoney(m.key) ? '' : 'th-rot'", false)            // qty headers rotate, money stays flat
            ->assertSee('writing-mode: vertical-rl', false)                 // vertical rotation
            ->assertSee('metric-money', false)                              // money column kept wide
            ->assertSee('isMoney(', false)                                  // money detector wired
            ->assertSee('fmtMoney(', false)                                 // money values formatted (R …)
            // rotation must not break sort or drilldown: handlers still present on the metric cells
            ->assertSee("sortAgent(m.key)", false)
            ->assertSee("drill(m.key, 'agent'", false)
            ->assertSee("drill(m.key, 'branch'", false);
    }

    public function test_whole_company_print_renders_with_company_header(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report.print'));

        $res->assertOk()
            ->assertSee('Coastal Realty')                       // company header
            ->assertSee('Agency Performance &amp; ROI', false)  // report title
            ->assertSee('By branch')
            ->assertSee('By agent')
            ->assertSee('Thabo Mokoena')                        // agent figures on the printable
            ->assertSee('window.print()', false);               // print action present
    }

    public function test_per_agent_print_renders_that_agents_figures(): void
    {
        $res = $this->actingAs($this->admin)->get(route('performance.agency-report.agent.print', ['user' => $this->agent->id]));

        $res->assertOk()
            ->assertSee('Thabo Mokoena')
            ->assertSee('Coastal Realty')                                  // company header
            ->assertSee('this period vs prior', false)                     // per-agent metric table heading
            ->assertSee('window.print()', false);
    }

    public function test_print_surfaces_are_agency_scoped(): void
    {
        // An agent belonging to a DIFFERENT agency must not be printable from this agency.
        $otherAgency = Agency::create(['name' => 'Rival Homes', 'slug' => 'rival']);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'agent']);

        $this->actingAs($this->admin)
            ->get(route('performance.agency-report.agent.print', ['user' => $otherAgent->id]))
            ->assertNotFound();
    }

    public function test_print_routes_require_auth(): void
    {
        // Guests are redirected to login on every report surface (incl. the new print routes).
        $this->get(route('performance.agency-report.print'))->assertRedirect();
        $this->get(route('performance.agency-report.agent.print', ['user' => $this->agent->id]))->assertRedirect();
    }
}
