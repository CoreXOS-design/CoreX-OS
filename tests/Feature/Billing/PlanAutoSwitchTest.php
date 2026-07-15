<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Events\Billing\AgencyPlanChanged;
use App\Mail\Billing\AgencyPlanChangedMail;
use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\User;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The plan auto-switches with headcount, and CoreX is told exactly once.
 *
 * Spec: .ai/specs/agency-billing.md §7  (AT-11, decisions D2 + D3)
 *
 * The "exactly once" is the part worth defending: a page render and the nightly
 * sweep can reconcile the same agency at the same moment, and without the
 * compare-and-set in SubscriptionReconciler that would put two identical emails
 * in Johan's inbox for one hire.
 */
class PlanAutoSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $name = 'Margate Properties'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    private function hire(Agency $agency, int $count = 1): \Illuminate\Support\Collection
    {
        return User::factory()->count($count)->create([
            'agency_id' => $agency->id,
            'is_active' => 1,
        ]);
    }

    private function plan(Agency $agency): string
    {
        return (string) DB::table('agency_subscriptions')->where('agency_id', $agency->id)->value('plan');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The switch itself
    // ─────────────────────────────────────────────────────────────────────────

    public function test_hiring_the_eleventh_user_switches_team_to_agency_and_emails_corex(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $this->hire($agency, 10);

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency), '10 users is still Team.');
        Mail::assertNothingSent();

        // The 11th hire.
        $this->hire($agency, 1);

        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->plan($agency));

        Mail::assertSent(AgencyPlanChangedMail::class, 1);
        Mail::assertSent(AgencyPlanChangedMail::class, function (AgencyPlanChangedMail $mail) {
            return $mail->hasTo('andre@corexos.co.za')
                && $mail->hasTo('johan@corexos.co.za')
                && $mail->fromPlan === AgencySubscription::PLAN_TEAM
                && $mail->toPlan === AgencySubscription::PLAN_AGENCY
                && $mail->seats === 11
                && $mail->previousMonthlyZar === 4950.00   // 11 × R450 under the old Team shape
                && $mail->newMonthlyZar === 4695.00;       // R1 495 + 10×295 + 1×250
        });
    }

    public function test_deactivating_back_to_ten_switches_agency_to_team(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $users  = $this->hire($agency, 11);

        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->plan($agency));

        // Someone resigns and is deactivated — the seat is freed.
        $users->first()->update(['is_active' => 0]);

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));
        Mail::assertSent(AgencyPlanChangedMail::class, 2);   // up, then back down
    }

    public function test_archiving_a_user_frees_their_seat(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $users  = $this->hire($agency, 11);

        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->plan($agency));

        $users->first()->delete();   // soft delete

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));
    }

    public function test_restoring_an_archived_user_takes_the_seat_back(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $users  = $this->hire($agency, 11);
        $victim = $users->first();

        $victim->delete();
        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));

        $victim->restore();
        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->plan($agency));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exactly-once — the compare-and-set
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reconciling_twice_emits_only_one_event(): void
    {
        Event::fake([AgencyPlanChanged::class]);

        $agency = $this->makeAgency();
        User::withoutEvents(fn () => $this->hire($agency, 11));   // no observer → plan left stale at 'team'

        $reconciler = app(SubscriptionReconciler::class);

        $first  = $reconciler->reconcile($agency);
        $second = $reconciler->reconcile($agency);
        $third  = $reconciler->reconcile($agency);

        $this->assertNotNull($first, 'The first reconcile owns the switch.');
        $this->assertNull($second, 'A second reconcile finds nothing to do and must stay silent.');
        $this->assertNull($third);

        Event::assertDispatchedTimes(AgencyPlanChanged::class, 1);
    }

    /**
     * The nightly sweep is the safety net for paths that bypass the User model
     * entirely — a bulk import, a raw UPDATE. Without it, an agency could sit on
     * the wrong plan indefinitely with nobody told.
     */
    public function test_the_nightly_sweep_catches_a_raw_sql_hire_that_bypassed_the_observer(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $this->hire($agency, 10);
        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));

        // An 11th user appears without ever touching the Eloquent model.
        User::withoutEvents(fn () => $this->hire($agency, 1));

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency), 'The observer never fired, so the plan is stale.');
        Mail::assertNothingSent();

        $this->artisan('corex:billing-reconcile')->assertExitCode(0);

        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->plan($agency), 'The sweep caught it.');
        Mail::assertSent(AgencyPlanChangedMail::class, 1);
    }

    public function test_the_sweep_is_a_no_op_when_nothing_changed(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $this->hire($agency, 5);

        $this->artisan('corex:billing-reconcile')->assertExitCode(0);
        $this->artisan('corex:billing-reconcile')->assertExitCode(0);

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));
        Mail::assertNothingSent();
    }

    public function test_dry_run_reports_the_drift_without_switching_or_emailing(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        User::withoutEvents(fn () => $this->hire($agency, 11));

        $this->artisan('corex:billing-reconcile', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency), 'A dry run must not write.');
        Mail::assertNothingSent();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Robustness
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A billing failure must NEVER stop an admin deactivating a resigning agent.
     * If our table is unhappy, that is our problem, not theirs.
     */
    public function test_a_billing_failure_does_not_break_the_user_save(): void
    {
        $agency = $this->makeAgency();
        $user   = $this->hire($agency, 1)->first();

        // Rip the billing table out from under the observer.
        DB::statement('DROP TABLE agency_subscriptions');

        $user->update(['is_active' => 0]);   // must not throw

        $this->assertFalse((bool) $user->fresh()->is_active, 'The user save must survive a billing failure.');
    }

    /** A System Owner (agency_id NULL) is not a seat and must not trigger a switch. */
    public function test_creating_a_system_owner_does_not_touch_any_agency_plan(): void
    {
        Mail::fake();

        $agency = $this->makeAgency();
        $this->hire($agency, 10);

        User::factory()->create(['agency_id' => null, 'role' => 'super_admin', 'is_active' => 1]);

        $this->assertSame(AgencySubscription::PLAN_TEAM, $this->plan($agency));
        Mail::assertNothingSent();
    }
}
