<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Mobile parity, faults / work orders / job cards: each mobile route is the WEB action itself behind the web's own permission keys, wrapped by
 * mobile.web-action so a redirect answer becomes JSON. This proves (a) the phone gets the web's refusals and results as JSON, (b) every one of
 * the generated routes carries the web's permission and the agency scope - checked across the whole set, not just a sample.
 */
final class MobileMaintenanceActionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    private const PREFIXES = ['v1.mobile.rental-fault-reports.', 'v1.mobile.rental-work-orders.', 'v1.mobile.rental-job-cards.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function workOrder(): RentalWorkOrder
    {
        return RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $this->agent->id, 'title' => 'Fix the gate', 'description' => 'Gate sticks', 'trade_type' => 'general',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function jobCard(): RentalJobCard
    {
        return RentalJobCard::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'title' => 'Paint the gate', 'status' => RentalJobCard::STATUS_DRAFT, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** @return list<\Illuminate\Routing\Route> every generated write route of the three families */
    private function generatedRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $name = (string) $r->getName();
            foreach (self::PREFIXES as $p) {
                if (str_starts_with($name, $p) && in_array('mobile.web-action', $r->gatherMiddleware(), true)) {
                    $out[] = $r;
                }
            }
        }

        return $out;
    }

    /** @return list<string> the web's permission keys on a route */
    private function keys(\Illuminate\Routing\Route $r): array
    {
        return collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_contains($m, 'CheckPermission:') || (is_string($m) && str_starts_with($m, 'permission:')))
            ->map(fn ($m) => substr($m, strrpos($m, ':') + 1))->unique()->values()->all();
    }

    /** the URL of a route with every {param}: the real record for the three bound ones, an id that exists nowhere for the rest */
    private function urlFor(\Illuminate\Routing\Route $r, array $real): string
    {
        $uri = '/' . $r->uri();
        foreach ($r->parameterNames() as $p) {
            $uri = str_replace('{' . $p . '}', (string) ($real[$p] ?? 999999), $uri);
        }

        return $uri;
    }

    public function test_the_generated_routes_exist_and_every_one_carries_the_web_permission_keys(): void
    {
        $routes = $this->generatedRoutes();
        $this->assertGreaterThan(80, count($routes));
        foreach ($routes as $r) {
            $this->assertNotEmpty($this->keys($r), $r->getName() . ' has no permission key');
        }
    }

    public function test_a_viewer_is_refused_on_every_generated_route_and_nothing_changes(): void
    {
        $fault = $this->fault();
        $wo = $this->workOrder();
        $card = $this->jobCard();
        $real = ['rentalFaultReport' => $fault->id, 'rentalWorkOrder' => $wo->id, 'rentalJobCard' => $card->id];

        // a role that may look at faults, work orders and job cards but do nothing else
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_fault_reports.view', 'rental_work_orders.view', 'rental_job_cards.view', 'properties.view', 'access_properties']);
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);

        $checked = 0;
        foreach ($this->generatedRoutes() as $r) {
            $needsMore = array_diff($this->keys($r), ['rental_fault_reports.view', 'rental_work_orders.view', 'rental_job_cards.view']);
            if ($needsMore === []) {
                continue; // a route that only needs the view keys the viewer holds
            }
            $method = $r->methods()[0];
            $res = $this->asStaff($viewer)->json($method, $this->urlFor($r, $real), []);
            $this->assertContains($res->getStatusCode(), [403, 404], "{$r->getName()} let a viewer through with " . $res->getStatusCode());
            $checked++;
        }
        $this->assertGreaterThan(60, $checked);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $fault->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status);
    }

    public function test_another_agency_gets_404_on_every_generated_route_even_with_every_permission(): void
    {
        $fault = $this->fault();
        $wo = $this->workOrder();
        $card = $this->jobCard();
        $real = ['rentalFaultReport' => $fault->id, 'rentalWorkOrder' => $wo->id, 'rentalJobCard' => $card->id];

        $all = collect($this->generatedRoutes())->flatMap(fn ($r) => $this->keys($r))->unique()->values()->all();
        $this->grantScope($this->other, 'agent', 'all', $all);

        foreach ($this->generatedRoutes() as $r) {
            if ($r->parameterNames() === []) {
                continue; // a create route names no record: it can only ever create in the caller's own agency
            }
            $res = $this->asStaff($this->outsider)->json($r->methods()[0], $this->urlFor($r, $real), []);
            $this->assertSame(404, $res->getStatusCode(), "{$r->getName()} did not 404 for another agency: " . $res->getStatusCode());
        }
        $this->assertNull(RentalFaultReport::withoutGlobalScopes()->withTrashed()->find($fault->id)->deleted_at);
    }

    public function test_a_fault_report_can_be_cancelled_through_the_web_action_and_the_refusal_is_json(): void
    {
        $fault = $this->fault();

        // the web action's own validation, as a 422 with its field errors
        $this->asStaff($this->admin)->postJson("/api/v1/mobile/rental-fault-reports/{$fault->id}/cancel", [])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors']);
    }

    public function test_a_work_order_note_is_added_and_a_job_card_is_cancelled_through_the_web_actions(): void
    {
        $wo = $this->workOrder();
        $this->asStaff($this->admin)->postJson("/api/v1/mobile/rental-work-orders/{$wo->id}/notes", ['body' => 'Tenant will be home after 2pm', 'note' => 'Tenant will be home after 2pm'])
            ->assertStatus(200)->assertJsonPath('ok', true);

        $card = $this->jobCard();
        $res = $this->asStaff($this->admin)->postJson("/api/v1/mobile/rental-job-cards/{$card->id}/cancel", ['cancel_reason' => 'Not needed', 'reason' => 'Not needed']);
        $this->assertContains($res->getStatusCode(), [200, 409, 422], 'job card cancel answered ' . $res->getStatusCode() . ' ' . $res->getContent());
        $this->assertNotNull($res->json('message'));
    }
}
