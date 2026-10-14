<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\RentalInspection;
use App\Models\User;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseTermAdvanceService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * rental-renewals.md §22.1 - QA / Staging have no scheduler, so `leases:advance-terms` never runs there. An administrator gets a small
 * "Run today's lease date checks now" control on the lease screen that runs the job's logic for THAT lease only, and - where the agency
 * switched it on and the install is not production - with an "as if today were" date.
 *
 * Real service, real routes, real lease screens; the dates are set well ahead of the real clock (2027 / 2028).
 */
final class LeaseDateChecksNowTest extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    private User $ordinaryAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgreementFixture();
        Mail::fake();
        // $this->agent is an admin (an owner-type role). An ordinary agent in the same agency:
        $this->ordinaryAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    /** The install is "production" for the lease-date aids (the app's own environment is left alone: switching it breaks the session / CSRF middleware under test). */
    private function pretendProduction(): void
    {
        $this->partialMock(LeaseTermAdvanceService::class, fn ($m) => $m->shouldReceive('isProductionInstall')->andReturn(true));
    }

    private function allowAsOf(bool $on = true): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['allow_advance_as_of_date' => $on]);
    }

    /** The running lease (active, ends 31 Oct 2027) and its signed renewal (starts 1 Nov 2027) waiting for that date. */
    private function signedRenewalWaiting(): array
    {
        $previous = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $previous->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        app(LeaseActivationService::class)->activate($previous);

        $renewal = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9200, 'start_date' => '2027-11-01', 'end_date' => '2028-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'previous_lease_id' => $previous->id,
            'signing_status' => Lease::SIGNING_SIGNED, 'accepted_at' => now(),
            'owner_agent_user_id' => $this->agent->id, 'tenant_agent_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $renewal->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return [$previous->fresh(), $renewal->fresh()];
    }

    /** A running lease whose tenant gave notice (move-out 31 Jan 2028) and whose out-inspection is done. */
    private function leaseWaitingToEnd(string $moveOut = '2028-01-31'): Lease
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        app(LeaseActivationService::class)->activate($lease);
        $lease->forceFill(['notice_date' => now()->toDateString(), 'notice_given_by' => 'tenant', 'move_out_date' => $moveOut])->save();
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'status' => 'scheduled',
        ]);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        return $lease->fresh();
    }

    /** A second tenancy, due on the same day, on another property - the single-lease run must not touch it. */
    private function anotherTenancyDueThatDay(): Lease
    {
        $property = \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'title' => 'Garden flat', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 6000, 'start_date' => '2026-11-01', 'end_date' => '2028-10-31',
            'source' => 'manual',
        ]);
        app(LeaseActivationService::class)->activate($lease);
        $lease->forceFill(['notice_date' => now()->toDateString(), 'notice_given_by' => 'tenant', 'move_out_date' => '2028-01-31'])->save();
        $inspection = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id, 'type' => RentalInspection::TYPE_OUT, 'status' => 'scheduled']);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        return $lease->fresh();
    }

    private function pressRun(Lease $onScreenOf, ?string $asOf = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->post(route('corex.leases.run-date-checks', $onScreenOf), $asOf ? ['as_of' => $asOf] : []);
    }

    // ═══ 1. the control does only what the date allows ═══

    public function test_before_the_start_date_the_button_says_nothing_is_due_and_changes_nothing(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();

        $this->pressRun($renewal)->assertRedirect(route('corex.leases.show', $renewal));

        $this->assertSame('Nothing due until 1 Nov 2027.', session('info'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
        $this->assertNull($previous->fresh()->renewed_lease_id);
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_DATE_CHECKS_RUN)->count());
    }

    public function test_the_same_answer_from_the_running_leases_own_screen(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();

        $this->pressRun($previous);

        $this->assertSame('Nothing due until 1 Nov 2027.', session('info'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
    }

    public function test_once_the_date_has_come_the_button_does_what_the_nightly_job_would(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        // the renewal's start date is today: the scheduler-less box never activated it
        $renewal->forceFill(['start_date' => now()->toDateString()])->save();
        $previous->forceFill(['end_date' => now()->subDay()->toDateString()])->save();

        $this->pressRun($renewal);

        $this->assertStringStartsWith('Done - the renewal that starts on', (string) session('success'));
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
        $this->assertSame($renewal->id, $previous->fresh()->renewed_lease_id);
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_DATE_CHECKS_RUN)->count(), 'a real-date run is not a simulated one');
    }

    public function test_a_lease_waiting_on_nothing_says_so(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2026-01-01', 'end_date' => '2028-12-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->pressRun($lease);

        $this->assertSame('Nothing is waiting on a date for this lease.', session('info'));
    }

    public function test_a_tenancy_waiting_to_end_is_not_ended_before_its_move_out_date(): void
    {
        $lease = $this->leaseWaitingToEnd();

        $this->pressRun($lease);

        $this->assertSame('Nothing due until 31 Jan 2028.', session('info'));
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status);
        $this->assertSame('let_out', $this->property->fresh()->status);
    }

    // ═══ 2. the as-of date: only where the agency switched it on, never on production ═══

    public function test_the_as_of_date_is_refused_unless_the_agency_switched_it_on(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();

        $this->pressRun($renewal, '2027-11-01');

        $this->assertStringContainsString('not switched on', (string) session('warning'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
    }

    public function test_the_as_of_date_is_refused_on_production_even_with_the_setting_on(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();
        $this->pretendProduction();

        $this->pressRun($renewal, '2027-11-01');

        $this->assertStringContainsString('not switched on', (string) session('warning'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
    }

    public function test_the_environment_rule_itself_production_is_never_allowed_whatever_the_row_holds(): void
    {
        $this->allowAsOf();
        $service = app(LeaseTermAdvanceService::class);
        $this->assertFalse($service->isProductionInstall());
        $this->assertTrue($service->asOfDateAllowed($this->agency->id), 'a test install with the switch on');

        $this->app->detectEnvironment(fn () => 'production');      // no HTTP here: the service reads the real environment

        $this->assertTrue(app(LeaseTermAdvanceService::class)->isProductionInstall());
        $this->assertFalse(app(LeaseTermAdvanceService::class)->asOfDateAllowed($this->agency->id));
        $this->assertTrue(LeaseSetting::allowAdvanceAsOfDateFor($this->agency->id), 'the row is untouched; the environment still wins');
    }

    public function test_with_the_setting_on_an_as_of_date_makes_the_renewal_active_and_expires_the_old_lease(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();

        $this->pressRun($renewal, '2027-11-01');

        $this->assertStringContainsString('now active', (string) session('success'));
        $this->assertStringContainsString('as if today were 1 Nov 2027', (string) session('success'));
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $previous->fresh()->status);
        $this->assertSame($renewal->id, $previous->fresh()->renewed_lease_id);
        $this->assertSame($previous->id, $renewal->fresh()->previous_lease_id);
        $this->assertSame('let_out', $this->property->fresh()->status);
        $event = LeaseEvent::where('lease_id', $renewal->id)->where('event_type', LeaseEvent::TYPE_DATE_CHECKS_RUN)->first();
        $this->assertNotNull($event, 'the tenancy log shows the date was simulated');
        $this->assertStringContainsString('1 Nov 2027', $event->description);
        $this->assertStringContainsString('(testing)', $event->description);
    }

    public function test_an_as_of_date_before_the_start_date_still_changes_nothing(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();

        $this->pressRun($renewal, '2027-10-31');

        $this->assertStringContainsString('Nothing due until 1 Nov 2027.', (string) session('info'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
    }

    public function test_with_the_setting_on_an_as_of_date_ends_a_tenancy_and_puts_the_property_back(): void
    {
        $lease = $this->leaseWaitingToEnd();
        $this->allowAsOf();

        $this->pressRun($lease, '2028-01-31');

        $this->assertStringStartsWith('Done - the tenancy has ended', (string) session('success'));
        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
        $this->assertSame('active', $this->property->fresh()->status);
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_TENANCY_ENDED)->exists());
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_DATE_CHECKS_RUN)->exists());
    }

    public function test_only_the_lease_it_was_run_for_is_touched(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();
        // another agency's tenancy that is also due on that date
        $other = $this->anotherTenancyDueThatDay();
        $this->pressRun($renewal, '2028-01-31');

        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $other->fresh()->status, 'a different lease that is also due is left alone');
    }

    public function test_a_renewal_with_an_unconfirmed_difference_is_not_activated_and_nothing_is_left_half_done(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();
        // signed, but the agreement differs from the lease and nobody confirmed it
        $this->mock(\App\Services\Rentals\LeaseHubService::class, fn ($m) => $m->shouldReceive('awaitingConfirmation')->andReturn(true));

        $this->pressRun($renewal, '2027-11-05');

        $this->assertStringContainsString('Nothing was changed', (string) session('warning'));
        $this->assertStringContainsString('difference nobody has confirmed', (string) session('warning'));
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_DATE_CHECKS_RUN)->count());
    }

    // ═══ who may press it ═══

    public function test_an_ordinary_agent_cannot_run_it(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();

        $this->pressRun($renewal, '2027-11-01', $this->ordinaryAgent)->assertForbidden();

        $this->assertSame(Lease::STATUS_DRAFT, $renewal->fresh()->status);
        $this->assertSame(Lease::STATUS_ACTIVE, $previous->fresh()->status);
    }

    public function test_the_lease_screen_shows_the_date_to_everyone_and_the_control_to_an_admin_only(): void
    {
        [$previous, $renewal] = $this->signedRenewalWaiting();

        $admin = $this->actingAs($this->agent)->get(route('corex.leases.show', $renewal))->assertOk();
        $admin->assertSee('data-qa="date-pending-note"', false)->assertSee('This renewal goes active on 1 Nov 2027')
            ->assertSee("Run today's lease date checks now", false)
            ->assertSee('data-qa="run-date-checks"', false)
            ->assertSee('data-confirm="Run today\'s lease date checks for this lease now?', false)
            ->assertDontSee('data-qa="run-date-checks-as-of"', false);

        // the running lease says the same, from its side
        $this->actingAs($this->agent)->get(route('corex.leases.show', $previous))->assertOk()
            ->assertSee('The signed renewal takes over on 1 Nov 2027')->assertSee('data-qa="run-date-checks"', false);

        // an ordinary agent who owns the lease sees the date, but never the button
        $renewal->forceFill(['owner_agent_user_id' => $this->ordinaryAgent->id])->save();
        $agent = $this->actingAs($this->ordinaryAgent)->get(route('corex.leases.show', $renewal))->assertOk();
        $agent->assertSee('This renewal goes active on 1 Nov 2027')->assertDontSee('data-qa="run-date-checks"', false);
    }

    public function test_the_as_of_input_appears_only_when_the_agency_allows_it_and_not_on_production(): void
    {
        [, $renewal] = $this->signedRenewalWaiting();
        $this->allowAsOf();

        $this->actingAs($this->agent)->get(route('corex.leases.show', $renewal))->assertOk()
            ->assertSee('data-qa="run-date-checks-as-of"', false)->assertSee('As if today were');

        $this->pretendProduction();
        $this->actingAs($this->agent)->get(route('corex.leases.show', $renewal))->assertOk()
            ->assertDontSee('data-qa="run-date-checks-as-of"', false)->assertSee('data-qa="run-date-checks"', false);
    }

    public function test_a_lease_waiting_on_nothing_shows_no_button(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => '2026-01-01', 'end_date' => '2028-12-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertDontSee('data-qa="run-date-checks"', false)->assertDontSee('data-qa="date-pending-note"', false);
    }

    public function test_a_tenancy_waiting_to_end_shows_its_date(): void
    {
        $lease = $this->leaseWaitingToEnd();

        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertSee('The tenancy ends on 31 Jan 2028 (move-out inspection done)')->assertSee('data-qa="run-date-checks"', false);
    }

    // ═══ the setting itself ═══

    public function test_the_setting_is_off_by_default_and_can_be_saved_on_a_test_install(): void
    {
        $this->assertFalse(LeaseSetting::allowAdvanceAsOfDateFor($this->agency->id));
        $this->assertFalse(app(LeaseTermAdvanceService::class)->asOfDateAllowed($this->agency->id));

        $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))->assertOk()->assertSee('data-qa="lease-advance-as-of-card"', false);
        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'allow_advance_as_of_date' => '1']);

        $this->assertTrue(LeaseSetting::allowAdvanceAsOfDateFor($this->agency->id));
        $this->assertTrue(app(LeaseTermAdvanceService::class)->asOfDateAllowed($this->agency->id));
    }

    public function test_the_setting_is_hidden_and_never_saved_on_production(): void
    {
        $this->pretendProduction();

        $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))->assertOk()->assertDontSee('data-qa="lease-advance-as-of-card"', false);
        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'allow_advance_as_of_date' => '1']);

        $this->assertFalse(LeaseSetting::allowAdvanceAsOfDateFor($this->agency->id));
    }

    public function test_saving_the_other_lease_settings_does_not_switch_it_off(): void
    {
        $this->allowAsOf();

        // the wizard step posts a subset: no allow_advance_as_of_date in it
        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45]);

        $this->assertTrue(LeaseSetting::allowAdvanceAsOfDateFor($this->agency->id));
    }

    // ═══ the real scheduler entry ═══

    public function test_the_nightly_job_is_scheduled_for_00_35(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leases:advance-terms'));

        $this->assertNotNull($event, 'leases:advance-terms is registered with the scheduler');
        $this->assertSame('35 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
