<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression guard for the staging 419 dead-end
 * (.ai/audits/2026-07-02-staging-419-csrf-investigation.md).
 *
 * Laravel's Handler::prepareException() rewraps TokenMismatchException into a
 * generic HttpException(419) BEFORE render callbacks run, so the old closure
 * keyed on TokenMismatchException never matched and every CSRF 419 fell through
 * to the raw default "419 Page Expired" page. bootstrap/app.php now keys the
 * render callback on HttpException status 419, so a token mismatch soft-bounces
 * (guest → login, authed → dashboard) or returns structured JSON to fetch
 * callers — never the dead-end page.
 *
 * The CSRF middleware skips its check under runningUnitTests(), so we throw the
 * exception from a test-only web route to exercise the real exception handler
 * end-to-end (with a started session, so the flash redirect resolves).
 */
final class Csrf419SoftBounceTest extends TestCase
{
    use RefreshDatabase;

    private function registerThrowingRoute(): void
    {
        Route::middleware('web')->post('_test/throw-419', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });
    }

    public function test_guest_csrf_419_redirects_to_login_not_dead_end(): void
    {
        $this->registerThrowingRoute();

        $res = $this->post('_test/throw-419');

        $res->assertRedirect(route('login'));
        $res->assertSessionHas('status');
        // Must NOT be the raw 419 page.
        $this->assertNotSame(419, $res->getStatusCode());
    }

    public function test_authenticated_csrf_419_redirects_to_dashboard(): void
    {
        $this->registerThrowingRoute();

        $res = $this->actingAs(User::factory()->create())->post('_test/throw-419');

        $res->assertRedirect(route('dashboard'));
        $res->assertSessionHas('warning');
    }

    public function test_json_csrf_419_returns_structured_419(): void
    {
        $this->registerThrowingRoute();

        $res = $this->postJson('_test/throw-419');

        $res->assertStatus(419);
        $res->assertJson(['ok' => false]);
    }
}
