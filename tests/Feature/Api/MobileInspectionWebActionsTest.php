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
 * Mobile parity, the rest of the inspection screen: planned dates, the paper site form and scan upload, checklist editing and seeding, photo
 * pairing, follow-up, comparison findings. Each is the web action behind the web's permission keys; this proves the keys and the agency
 * scope across the whole generated set, and that the read endpoints answer.
 */
final class MobileInspectionWebActionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    private const PREFIXES = ['v1.mobile.rental-inspections.', 'v1.mobile.properties.rental-inspection'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
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

    private function inspection(): \App\Models\RentalInspection
    {
        return $this->inspectionRow(\App\Models\RentalInspection::TYPE_AD_HOC);
    }

    public function test_the_generated_routes_exist_and_never_include_edit_report(): void
    {
        $routes = $this->generatedRoutes();
        $this->assertGreaterThan(25, count($routes));
        foreach ($routes as $r) {
            $this->assertNotEmpty($this->keys($r), $r->getName() . ' has no permission key');
        }
        // Edit report (the inspection's own "reopen", which clears every signature) is office-only: no mobile route may point at it
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'api/v1/mobile') && str_ends_with((string) ($r->getAction('controller') ?? ''), '@reopen')
            && str_contains((string) $r->getAction('controller'), 'RentalInspectionRecordingController')), 'Edit report must never reach the phone');
    }

    public function test_a_viewer_is_refused_on_every_generated_route(): void
    {
        $insp = $this->inspection();
        $real = ['rentalInspection' => $insp->id, 'property' => $this->property->id];
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_inspections.view', 'properties.view', 'access_properties']);
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);

        $checked = 0;
        foreach ($this->generatedRoutes() as $r) {
            if (array_diff($this->keys($r), ['rental_inspections.view']) === []) {
                continue;
            }
            $res = $this->asStaff($viewer)->json($r->methods()[0], $this->urlFor($r, $real), []);
            $this->assertContains($res->getStatusCode(), [403, 404], "{$r->getName()} let a viewer through with " . $res->getStatusCode());
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }

    public function test_another_agency_gets_404_on_every_generated_route_even_with_every_permission(): void
    {
        $insp = $this->inspection();
        $real = ['rentalInspection' => $insp->id, 'property' => $this->property->id];
        $this->grantScope($this->other, 'agent', 'all', collect($this->generatedRoutes())->flatMap(fn ($r) => $this->keys($r))->unique()->values()->all());

        foreach ($this->generatedRoutes() as $r) {
            if ($r->parameterNames() === []) {
                continue;
            }
            $res = $this->asStaff($this->outsider)->json($r->methods()[0], $this->urlFor($r, $real), []);
            $this->assertContains($res->getStatusCode(), [403, 404], "{$r->getName()} did not refuse another agency: " . $res->getStatusCode());
        }
    }

    public function test_the_read_endpoints_answer_for_a_normal_user(): void
    {
        $insp = $this->inspection();

        $this->asStaff($this->admin)->getJson("/api/v1/mobile/properties/{$this->property->id}/rental-inspection-tab")->assertOk();
        $this->asStaff($this->admin)->getJson("/api/v1/mobile/rental-inspections/{$insp->id}/attendance")->assertOk();
    }
}
