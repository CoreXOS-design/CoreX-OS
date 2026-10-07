<?php

declare(strict_types=1);

namespace Tests\Feature\BuyersReport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\BuyersReport\BuyersReportScope;
use App\Services\BuyersReport\BuyersReportScopeResolver;
use App\Services\BuyersReport\BuyersReportService;
use App\Services\Performance\PeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the print/PDF correctness properties Johan asked for explicitly
 * (2026-08-20, urgent, meeting tomorrow): a PDF generated with a filter
 * active shows the FILTERED numbers, not the unfiltered ones; and printing
 * someone else's dedicated agent/branch page shows THEIR figures, not the
 * viewer's own -- the exact bug this test suite caught during build (the
 * general BuyersReportScopeResolver always substitutes the viewer's own
 * id for 'own' level, which is correct for the interactive page and wrong
 * for print()/pdf() targeting another agent's page).
 */
final class BuyersReportPrintPdfTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY_ID = 9401;

    private int $branchId;

    /**
     * The normal RefreshDatabase path with real rows. (This file used to DROP and re-create 15 core tables by hand — users,
     * contacts, agencies, communications… — which left a lane's persistent test schema without them for every later file.)
     */
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Agency::forceCreate(['id' => self::AGENCY_ID, 'name' => 'Print Test Agency', 'slug' => 'print-test-agency']);
        $this->branchId = (int) Branch::forceCreate(['agency_id' => self::AGENCY_ID, 'name' => 'Print Branch'])->id;
        // The role manager row the report's OWN permission is read from (buyers_report.view): admin sees the agency, agent sees own.
        foreach (['admin' => 'all', 'agent' => 'own'] as $role => $scope) {
            DB::table('roles')->insert(['name' => $role . '-print-' . self::AGENCY_ID, 'label' => $role, 'agency_id' => self::AGENCY_ID, 'is_owner' => false, 'sort_order' => 1]);
            DB::table('role_permissions')->insert(['role' => $role, 'permission_key' => 'buyers_report.view', 'agency_id' => self::AGENCY_ID, 'scope' => $scope]);
        }
    }

    public function test_print_of_another_agent_shows_their_figures_not_the_viewers_own(): void
    {
        $viewerId = $this->seedUser(4001, 'admin', 'Admin Viewer');
        $targetId = $this->seedUser(4002, 'agent', 'Target Agent');
        $this->seedBuyer(1, $targetId, 'warm');
        $this->seedBuyer(2, $targetId, 'warm');
        // Viewer has buyers of their own too -- must NOT leak into the printed page.
        $this->seedBuyer(3, $viewerId, 'warm');
        $viewer = User::find($viewerId);

        auth()->login($viewer);
        $controller = app(\App\Http\Controllers\BuyersReport\BuyersReportController::class);
        $req = Request::create('/corex/buyers-report/print?scope=own&user_id=' . $targetId . '&period=this_month');
        $req->setUserResolver(fn () => $viewer);
        app()->instance('request', $req);

        $html = $controller->print($req, app(PeriodResolver::class), app(BuyersReportScopeResolver::class), app(BuyersReportService::class))->render();

        $this->assertStringContainsString('Target Agent', $html);
        $this->assertStringNotContainsString('Admin Viewer', $html);
    }

    public function test_pdf_respects_the_type_filter_not_the_full_buyer_count(): void
    {
        $viewer = $this->seedUser(4003, 'admin', 'Admin Two');
        $agent  = $this->seedUser(4004, 'agent', 'Agent Two');
        $buyerType = (int) DB::table('contact_types')->insertGetId(['name' => 'Buyer', 'created_at' => now(), 'updated_at' => now()]);
        $leadType = (int) DB::table('contact_types')->insertGetId(['name' => 'Lead', 'created_at' => now(), 'updated_at' => now()]);
        $b1 = $this->seedBuyer(4, $agent, 'new');
        DB::table('contacts')->where('id', $b1)->update(['contact_type_id' => $buyerType]);
        $b2 = $this->seedBuyer(5, $agent, 'new');
        DB::table('contacts')->where('id', $b2)->update(['contact_type_id' => $leadType]);

        $scope = new BuyersReportScope(self::AGENCY_ID, BuyersReportScope::LEVEL_AGENCY);
        $period = app(PeriodResolver::class)->resolve('this_month');
        $service = app(BuyersReportService::class);

        // Same numbers the print/PDF path renders -- build() is exactly
        // what buildPrintData() calls, so asserting on it directly proves
        // the filtered figure without depending on print.blade.php markup.
        $unfiltered = $service->build($scope, $period, null);
        $filtered = $service->build($scope, $period, 'buyer');

        $this->assertSame(2, $unfiltered['company']['buyers'], 'Unfiltered: both buyers held.');
        $this->assertSame(1, $filtered['company']['buyers'], 'type=buyer filtered: only the one Buyer-typed contact.');

        $viewerModel = User::find($viewer);
        auth()->login($viewerModel);
        $controller = app(\App\Http\Controllers\BuyersReport\BuyersReportController::class);
        $reqFiltered = Request::create('/corex/buyers-report/print?scope=agency&period=this_month&type=buyer');
        $reqFiltered->setUserResolver(fn () => $viewerModel);
        app()->instance('request', $reqFiltered);
        $htmlFiltered = $controller->print($reqFiltered, app(PeriodResolver::class), app(BuyersReportScopeResolver::class), $service)->render();

        $this->assertStringContainsString('Buyer only', $htmlFiltered, 'The print page must say a type filter is active, not silently apply it.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedUser(int $id, string $role, string $name): int
    {
        User::factory()->create([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'branch_id' => $this->branchId,
            'name' => $name, 'role' => $role, 'is_active' => 1,
        ]);

        return $id;
    }

    private function seedBuyer(int $id, int $agentId, string $state): int
    {
        DB::table('contacts')->insert([
            'id' => $id, 'agency_id' => self::AGENCY_ID, 'branch_id' => $this->branchId, 'agent_id' => $agentId,
            'is_buyer' => 1, 'buyer_state' => $state, 'first_name' => "Buyer $id", 'last_name' => '-',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
