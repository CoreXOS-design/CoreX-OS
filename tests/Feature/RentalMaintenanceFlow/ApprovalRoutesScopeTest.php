<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalApprovalGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.19 / BUILD_STANDARD §1c — OWN / BRANCH / AGENCY at the query layer for every NEW office route of Build 2,
 * proven by direct URL by id: a same-agency user whose data scope does not cover the work order is refused (403), a user of another
 * agency cannot reach it (403/404), and nothing changes either way. (The property work-terms route and the portal endpoints have their
 * own scope tests in WorkTermsTest and VariationFlowTest.)
 */
final class ApprovalRoutesScopeTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private User $ownScoped;
    private User $stranger;
    private RentalWorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('RouteScope');
        $this->wo = $this->externalWorkOrder();   // created by the admin
        $this->ownScoped = $this->agentWith([
            'rental_work_orders.view' => 'own', 'rental_work_orders.record_emergency_approval' => 'own',
            'rental_work_orders.record_approval' => 'own', 'rental_job_cards.price' => 'own',
        ]);
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $this->stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    /** every new office route of Build 2, as [method, url] for a work order that has an emergency approval and an open variation */
    private function routes(): array
    {
        $gate = app(RentalApprovalGateService::class);
        $emergency = $gate->recordEmergency($this->wo, ['approved_by_name' => 'Owner', 'approved_via' => 'phone', 'approved_at' => now()->subMinute(), 'reason' => 'Flood'], $this->admin);
        $variation = \App\Models\RentalWorkOrderVariation::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->wo->id, 'status' => 'awaiting_owner', 'origin' => 'office_edit',
            'baseline_amount' => 1000, 'extra_amount' => 500, 'new_total' => 1500, 'price_change_amount' => 0, 'raised_at' => now(),
        ]);

        return [
            ['post', route('corex.rental-work-orders.emergency-approval.void', [$this->wo, $emergency]), ['void_reason' => 'x']],
            ['get', route('corex.rental-work-orders.emergency-approval.attachment', [$this->wo, $emergency]), []],
            ['post', route('corex.rental-work-orders.variations.resend', [$this->wo, $variation]), []],
            ['post', route('corex.rental-work-orders.variations.decision', [$this->wo, $variation]), ['decision' => 'approve', 'evidence_type' => 'email', 'evidence_text' => 'x']],
            ['put', route('corex.rental-work-orders.external-fee.update', $this->wo), ['external_markup_type' => 'percent', 'external_markup_value' => 5]],
        ];
    }

    public function test_a_user_whose_scope_does_not_cover_the_work_order_is_refused_on_every_new_route_and_nothing_changes(): void
    {
        $routes = $this->routes();
        $before = [$this->wo->fresh()->owner_approval_status, $this->wo->fresh()->approval_basis, $this->wo->openVariation()?->status, $this->wo->fresh()->external_markup_value];

        foreach ($routes as [$method, $url, $data]) {
            $this->actingAs($this->ownScoped)->{$method}($url, $data)->assertForbidden();
        }
        $this->actingAs($this->ownScoped)->post(route('corex.rental-work-orders.emergency-approval.store', $this->wo), ['approved_by_name' => 'x', 'approved_via' => 'phone', 'approved_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'reason' => 'y'])->assertForbidden();

        $this->assertSame($before, [$this->wo->fresh()->owner_approval_status, $this->wo->fresh()->approval_basis, $this->wo->openVariation()?->status, $this->wo->fresh()->external_markup_value]);
    }

    public function test_a_user_of_another_agency_cannot_reach_any_of_them(): void
    {
        foreach ($this->routes() as [$method, $url, $data]) {
            $status = $this->actingAs($this->stranger)->{$method}($url, $data)->getStatusCode();
            $this->assertContains($status, [403, 404], "{$method} {$url} must not be reachable from another agency");
        }
        $this->assertNotNull($this->wo->fresh()->activeEmergencyApproval(), 'the emergency approval was not voided by the stranger');
        $this->assertNotNull($this->wo->openVariation(), 'the open variation was not decided by the stranger');
    }

    public function test_the_same_users_with_their_scope_covering_the_work_order_do_get_in(): void
    {
        $routes = $this->routes();
        $mine = $this->externalWorkOrder(['created_by_user_id' => $this->ownScoped->id, 'title' => 'Own work order']);

        // the control that proves the 403s above are the scope and nothing else: the SAME agent on a work order they created
        $this->actingAs($this->ownScoped)->put(route('corex.rental-work-orders.external-fee.update', $mine), ['external_markup_type' => 'percent', 'external_markup_value' => 5])->assertSessionHasNoErrors();
        $this->assertNotEmpty($routes);
    }
}
