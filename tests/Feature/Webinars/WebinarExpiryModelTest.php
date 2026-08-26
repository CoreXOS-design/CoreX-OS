<?php

namespace Tests\Feature\Webinars;

use App\Models\DemoAccessGrant;
use App\Models\User;
use App\Services\Demo\DemoAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The two-clock expiry model.
 *
 * Spec: .ai/specs/webinar-registration.md §5.2
 *
 * This file exists because the change it covers REVERSES a rule the demo grant
 * lifecycle was built on ("the clock starts at first login"), inside code that is
 * already live and already issuing credentials. The two halves that matter:
 *
 *   - a FIXED-deadline grant expires on its date even if nobody ever used it, and
 *     logging in does not move that date;
 *   - a ROLLING grant behaves exactly as it did before — which is the half that a
 *     regression would break silently, because nothing in the webinar feature
 *     touches it.
 */
class WebinarExpiryModelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private DemoAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner   = User::factory()->create(['role' => 'super_admin']);
        $this->service = app(DemoAccessService::class);
    }

    // ---- Fixed deadline ----------------------------------------------------

    /** The deadline is written AT ISSUE, and expiry_hours is NULL to say so. */
    public function test_a_fixed_deadline_grant_carries_its_expiry_from_the_moment_it_is_issued(): void
    {
        $deadline = Carbon::now()->addDays(10)->endOfDay();

        [$grant] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => $deadline,
        ], $this->owner->id);

        $this->assertNull($grant->expiry_hours, 'expiry_hours NULL is what marks a fixed-deadline grant.');
        $this->assertTrue($grant->hasFixedDeadline());
        $this->assertSame($deadline->toDateTimeString(), $grant->expires_at->toDateTimeString());
        $this->assertNull($grant->first_login_at);
    }

    /**
     * THE POINT OF THE WHOLE CHANGE (Johan: "anyone that doesn't use the login just
     * loses access"). Under the old ordering this grant reported "pending" forever.
     */
    public function test_a_fixed_deadline_grant_that_was_never_used_expires_on_the_date(): void
    {
        [$grant] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => Carbon::now()->addDays(2)->endOfDay(),
        ], $this->owner->id);

        $this->assertSame(DemoAccessGrant::STATUS_PENDING, $grant->status());
        $this->assertTrue($grant->isUsable());

        $this->travelTo(Carbon::now()->addDays(3));

        $this->assertSame(DemoAccessGrant::STATUS_EXPIRED, $grant->fresh()->status());
        $this->assertFalse($grant->fresh()->isUsable(), 'An unused credential must die on the date like everyone else.');
    }

    /** Signing in must not convert an absolute deadline back into a rolling one. */
    public function test_first_login_does_not_move_a_fixed_deadline(): void
    {
        $deadline = Carbon::now()->addDays(5)->endOfDay();

        [$grant] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => $deadline,
        ], $this->owner->id);

        $this->assertTrue($grant->stampFirstLogin());

        $grant->refresh();

        $this->assertNotNull($grant->first_login_at);
        $this->assertSame(
            $deadline->toDateTimeString(),
            $grant->expires_at->toDateTimeString(),
            'stampFirstLogin must COALESCE, not overwrite — otherwise the cohort deadline silently becomes per-person.'
        );
        $this->assertSame(DemoAccessGrant::STATUS_ACTIVE, $grant->status());
    }

    /** A second sign-in cannot extend it either. */
    public function test_a_second_login_attempt_cannot_extend_a_fixed_deadline(): void
    {
        $deadline = Carbon::now()->addDays(5)->endOfDay();

        [$grant] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => $deadline,
        ], $this->owner->id);

        $grant->stampFirstLogin();

        $this->travelTo(Carbon::now()->addHours(6));

        $this->assertFalse($grant->fresh()->stampFirstLogin(), 'Only the first caller wins the race.');
        $this->assertSame($deadline->toDateTimeString(), $grant->fresh()->expires_at->toDateTimeString());
    }

    // ---- Rolling (the pre-existing behaviour, which must not move) ----------

    /** A rolling grant is still NULL until first login, then login + expiry_hours. */
    public function test_rolling_grants_are_completely_unchanged(): void
    {
        [$grant] = $this->service->issue([
            'company_name'  => 'Seaside Realty',
            'contact_email' => 'thabo@seaside.co.za',
            'expiry_hours'  => 72,
        ], $this->owner->id);

        $this->assertSame(72, $grant->expiry_hours);
        $this->assertFalse($grant->hasFixedDeadline());
        $this->assertNull($grant->expires_at, 'The clock must not start at issue for a rolling grant.');
        $this->assertSame(DemoAccessGrant::STATUS_PENDING, $grant->status());

        // Still pending a fortnight later — an unopened invitation loses them nothing.
        $this->travelTo(Carbon::now()->addDays(14));
        $this->assertSame(DemoAccessGrant::STATUS_PENDING, $grant->fresh()->status());

        $loginAt = Carbon::now();
        $grant->fresh()->stampFirstLogin($loginAt);

        $this->assertSame(
            $loginAt->copy()->addHours(72)->toDateTimeString(),
            $grant->fresh()->expires_at->toDateTimeString()
        );
        $this->assertSame(DemoAccessGrant::STATUS_ACTIVE, $grant->fresh()->status());
    }

    /** And it still expires the normal way once the trial runs out. */
    public function test_a_rolling_grant_still_expires_after_its_trial(): void
    {
        [$grant] = $this->service->issue([
            'company_name'  => 'Seaside Realty',
            'contact_email' => 'thabo@seaside.co.za',
            'expiry_hours'  => 1,
        ], $this->owner->id);

        $grant->stampFirstLogin();

        $this->travelTo(Carbon::now()->addHours(2));

        $this->assertSame(DemoAccessGrant::STATUS_EXPIRED, $grant->fresh()->status());
    }

    /** scopeUsable stays NULL-safe — the query half of the same rule. */
    public function test_the_usable_scope_admits_pending_rolling_grants_and_excludes_passed_deadlines(): void
    {
        [$rolling] = $this->service->issue([
            'company_name'  => 'Seaside Realty',
            'contact_email' => 'thabo@seaside.co.za',
            'expiry_hours'  => 72,
        ], $this->owner->id);

        [$dead] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => Carbon::now()->subDay(),
        ], $this->owner->id);

        $usable = DemoAccessGrant::usable()->pluck('id')->all();

        $this->assertContains($rolling->id, $usable);
        $this->assertNotContains($dead->id, $usable);
    }

    // ---- The guard ---------------------------------------------------------

    /** Two clocks on one grant has no defined meaning, so it fails at the call site. */
    public function test_passing_both_clocks_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expiry_hours'  => 72,
            'expires_at'    => Carbon::now()->addDays(3),
        ], $this->owner->id);
    }

    // ---- Revoke/archive still win over a live deadline ---------------------

    public function test_revoked_beats_an_unexpired_deadline(): void
    {
        [$grant] = $this->service->issue([
            'company_name'  => 'Acme Properties',
            'contact_email' => 'jane@acme.co.za',
            'expires_at'    => Carbon::now()->addDays(5),
        ], $this->owner->id);

        $this->service->revoke($grant, $this->owner->id);

        $this->assertSame(DemoAccessGrant::STATUS_REVOKED, $grant->fresh()->status());
    }
}
