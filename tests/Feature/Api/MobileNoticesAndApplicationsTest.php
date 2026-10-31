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
 * Mobile parity, notices / breaches / rental applications / the remaining lease actions. Johan's scope for applications: the phone creates
 * the contact and sends the application out; reviewing and authorising stay on the web, so no review or authorise route may exist here.
 * Reject-a-notice is not exposed either. The rest is the web action behind the web's own permission keys.
 */
final class MobileNoticesAndApplicationsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    private const PREFIXES = ['v1.mobile.rental-applications.', 'v1.mobile.breaches.', 'v1.mobile.rental-notices.', 'v1.mobile.lease-notices.',
        'v1.mobile.leases.portal-access.', 'v1.mobile.leases.deposit-deductions.', 'v1.mobile.leases.application-co-applicant'];

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

    public function test_the_generated_routes_carry_the_web_permission_keys(): void
    {
        $routes = $this->generatedRoutes();
        $this->assertGreaterThan(15, count($routes));
        foreach ($routes as $r) {
            $this->assertNotEmpty($this->keys($r), $r->getName() . ' has no permission key');
        }
    }

    public function test_rental_applications_stop_at_create_the_contact_and_send_it_out(): void
    {
        $names = collect(Route::getRoutes())->map(fn ($r) => (string) $r->getName())->filter(fn ($n) => str_starts_with($n, 'v1.mobile.rental-applications.'))->values()->all();
        sort($names);
        $this->assertSame([
            'v1.mobile.rental-applications.contacts.quick-create', 'v1.mobile.rental-applications.index', 'v1.mobile.rental-applications.reissue-link', 'v1.mobile.rental-applications.send',
            'v1.mobile.rental-applications.share-link', 'v1.mobile.rental-applications.store',
        ], $names, 'the list (to find an application), create the contact, create, send, share / re-issue the link; review / authorise / decline stay on the web');
    }

    public function test_reject_a_notice_is_not_on_the_phone(): void
    {
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/v1/mobile')) {
                $this->assertStringNotContainsString('LeaseNoticeController@reject', (string) $r->getAction('controller'), 'an agency can only act on a notice, never reject it');
            }
        }
    }

    public function test_a_viewer_is_refused_and_another_agency_is_not_found_on_every_generated_route(): void
    {
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_applications.view', 'rental_breaches.view', 'rental_notices.view', 'leases.view', 'properties.view', 'access_properties']);
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
        $this->grantScope($this->other, 'agent', 'all', collect($this->generatedRoutes())->flatMap(fn ($r) => $this->keys($r))->unique()->values()->all());
        $real = ['lease' => $this->lease->id];

        foreach ($this->generatedRoutes() as $r) {
            $method = $r->methods()[0];
            $this->assertContains($this->asStaff($viewer)->json($method, $this->urlFor($r, $real), [])->getStatusCode(), [403, 404, 405], $r->getName() . ' let a viewer through');
            if (isset($real['lease']) && in_array('lease', $r->parameterNames(), true)) {
                $this->asStaff($this->outsider)->json($method, $this->urlFor($r, $real), [])->assertStatus(404);
            }
        }
    }

    public function test_the_web_validation_comes_back_as_json_for_the_phone(): void
    {
        $this->asStaff($this->admin)->postJson('/api/v1/mobile/rental-applications/contacts/quick-create', [])->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->asStaff($this->admin)->postJson('/api/v1/mobile/breaches/bulk/send', [])->assertStatus(422);
    }
}
