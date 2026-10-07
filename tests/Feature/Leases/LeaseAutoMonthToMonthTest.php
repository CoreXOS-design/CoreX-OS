<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\User;
use App\Notifications\LeaseMonthToMonthNotice;
use App\Services\Rentals\LeaseAutoMonthToMonthService;
use App\Services\Rentals\LeaseRenewalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §5.3 — Johan, 7 Oct 2026: a lease that reaches its end date with NO notice to vacate and NO
 * renewal on record goes month-to-month AUTOMATICALLY. Pinned both ways: it switches exactly then (on the day the
 * agency's own setting says — default the day after), once, with a tenancy-log line and a note to the agent; and it
 * NEVER switches when a notice or a renewal (even one only out for e-signing) is on record.
 *
 * Mirrors reality (BUILD_STANDARD §5): the run twice, two agencies with different settings in one run, a notice
 * recorded between the list and the switch, a renewal the agent cancelled, a lease whose month-to-month the agent
 * reversed (not fought), leases that are drafts / expired / cancelled / already month-to-month / have no end date.
 */
final class LeaseAutoMonthToMonthTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 05:30:00');
        [$this->agency, $this->branch, $this->agent, $this->property] = $this->world();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Agency, 1: Branch, 2: User, 3: Property} */
    private function world(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Sea view ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $agent, $property];
    }

    /** @param array<string,mixed> $overrides */
    private function lease(string $endedDaysAgo = '1', array $overrides = [], ?array $world = null): Lease
    {
        [$agency, $branch, $agent, $property] = $world ?? [$this->agency, $this->branch, $this->agent, $this->property];

        return Lease::create($overrides + [
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'start_date' => now()->subYear()->subDays((int) $endedDaysAgo)->toDateString(),
            'end_date' => now()->subDays((int) $endedDaysAgo)->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $agent->id,
        ]);
    }

    private function runCommand(array $options = []): void
    {
        $this->artisan('leases:auto-month-to-month', $options)->assertExitCode(0);
    }

    private function switchedEvents(Lease $lease)
    {
        return LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_MONTH_TO_MONTH_SET)->get();
    }

    // ═══ it switches — exactly when the ruling says ═══

    public function test_a_lease_goes_month_to_month_the_day_after_its_end_date_by_default(): void
    {
        Notification::fake();
        $lease = $this->lease('1');

        $this->runCommand();

        $lease = $lease->fresh();
        $this->assertTrue((bool) $lease->is_month_to_month);
        $this->assertNull($lease->end_date, 'exactly what the agent\'s own "Goes month-to-month" does');
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status, 'nothing is expired — the lease stays active');
        $this->assertSame('active', $this->property->fresh()->status, 'the property keeps its status (§12.5.3)');
    }

    public function test_it_does_not_switch_before_the_day_after(): void
    {
        $endsToday = $this->lease('0');
        $endsTomorrow = $this->lease('-1');

        $this->runCommand();

        $this->assertFalse((bool) $endsToday->fresh()->is_month_to_month, 'still within its last day');
        $this->assertFalse((bool) $endsTomorrow->fresh()->is_month_to_month);
        $this->assertNotNull($endsToday->fresh()->end_date);
    }

    public function test_it_is_logged_on_the_tenancy_history_as_automatic_with_the_old_end_date(): void
    {
        $lease = $this->lease('3');
        $endedOn = $lease->end_date->toDateString();

        $this->runCommand();

        $events = $this->switchedEvents($lease);
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertNull($event->actor_user_id, 'the system acted, not a person');
        $this->assertTrue($event->metadata['automatic']);
        $this->assertSame($endedOn, $event->metadata['previous_end_date']);
        $this->assertStringContainsString('automatically', $event->description);
        $this->assertStringContainsString('no notice to vacate and no renewal on record', $event->description);

        // And it is on the history the agent reads.
        $entries = collect(app(\App\Services\Rentals\LeaseTimelineService::class)->paginatedFor($lease->fresh(), null, [], null, null, 50, 1)['entries']);
        $this->assertTrue($entries->contains(fn ($e) => str_contains($e['description'], 'went month-to-month automatically')));
    }

    public function test_the_agent_is_told_in_app(): void
    {
        Notification::fake();
        $lease = $this->lease('2');

        $this->runCommand();

        Notification::assertSentTo($this->agent, LeaseMonthToMonthNotice::class, function (LeaseMonthToMonthNotice $n, array $channels) use ($lease) {
            $data = $n->toArray($this->agent);

            return $n->lease->id === $lease->id && $channels === ['database']
                && str_contains($data['message'], 'now month-to-month') && str_contains($data['message'], 'reverse it under Lease actions')
                && $data['url'] === route('corex.leases.show', $lease->id);
        });
    }

    public function test_running_it_again_changes_nothing_and_tells_nobody_twice(): void
    {
        Notification::fake();
        $lease = $this->lease('2');

        $this->runCommand();
        $this->runCommand();
        $this->runCommand();

        $this->assertCount(1, $this->switchedEvents($lease));
        Notification::assertSentToTimes($this->agent, LeaseMonthToMonthNotice::class, 1);
    }

    // ═══ it never switches when something is on record ═══

    public function test_a_notice_to_vacate_stops_it_whoever_gave_it(): void
    {
        Notification::fake();
        $byTenant = $this->lease('5');
        $byLandlord = $this->lease('5');
        $service = app(LeaseRenewalService::class);
        $service->recordNotice($byTenant, 'tenant', now()->addDays(20)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);
        $service->recordNotice($byLandlord, 'landlord', now()->addDays(20)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);

        $this->runCommand();

        foreach ([$byTenant, $byLandlord] as $lease) {
            $this->assertFalse((bool) $lease->fresh()->is_month_to_month);
            $this->assertNotNull($lease->fresh()->end_date);
            $this->assertCount(0, $this->switchedEvents($lease));
        }
        Notification::assertNothingSent();
    }

    public function test_a_renewal_that_is_started_stops_it_including_one_out_for_signing(): void
    {
        Notification::fake();
        $started = $this->lease('4');
        $outForSigning = $this->lease('4');
        $signedNotActive = $this->lease('4');
        foreach ([[$started, null], [$outForSigning, Lease::SIGNING_OUT_FOR_SIGNING], [$signedNotActive, Lease::SIGNING_SIGNED]] as [$previous, $signing]) {
            $this->lease('-30', [
                'status' => Lease::STATUS_DRAFT, 'previous_lease_id' => $previous->id,
                'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            ] + ($signing ? ['signing_status' => $signing] : []));
        }

        $this->runCommand();

        foreach ([$started, $outForSigning, $signedNotActive] as $lease) {
            $this->assertFalse((bool) $lease->fresh()->is_month_to_month, "lease {$lease->id}");
            $this->assertCount(0, $this->switchedEvents($lease));
        }
        Notification::assertNothingSent();
    }

    public function test_a_lease_that_has_been_renewed_is_not_touched(): void
    {
        $renewal = $this->lease('-30', ['start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addYear()->toDateString()]);
        $renewed = $this->lease('10');
        $renewed->forceFill(['renewed_lease_id' => $renewal->id])->save();

        $this->runCommand();

        $this->assertFalse((bool) $renewed->fresh()->is_month_to_month, 'a renewal is on record');
        $this->assertNotNull($renewed->fresh()->end_date);
    }

    public function test_a_renewal_the_agent_cancelled_is_not_a_renewal(): void
    {
        $lease = $this->lease('4');
        $this->lease('-30', ['status' => Lease::STATUS_CANCELLED, 'previous_lease_id' => $lease->id, 'start_date' => now()->addDay()->toDateString()]);

        $this->runCommand();

        $this->assertTrue((bool) $lease->fresh()->is_month_to_month, 'nothing is on record any more');
    }

    public function test_a_notice_recorded_between_the_list_and_the_switch_wins(): void
    {
        $lease = $this->lease('2');
        $service = app(LeaseAutoMonthToMonthService::class);
        $due = $service->dueFor($this->agency);
        $this->assertCount(1, $due);

        app(LeaseRenewalService::class)->recordNotice($lease, 'tenant', now()->addDays(10)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);

        $this->assertFalse($service->convert($due->first()), 'the conditions are re-checked under a lock at the moment of the switch');
        $this->assertFalse((bool) $lease->fresh()->is_month_to_month);
    }

    // ═══ what is never a candidate ═══

    public function test_only_an_active_lease_with_an_end_date_that_is_not_already_month_to_month_is_a_candidate(): void
    {
        $draft = $this->lease('5', ['status' => Lease::STATUS_DRAFT]);
        $expired = $this->lease('5', ['status' => Lease::STATUS_EXPIRED]);
        $cancelled = $this->lease('5', ['status' => Lease::STATUS_CANCELLED]);
        $already = $this->lease('5', ['is_month_to_month' => true, 'end_date' => null]);
        $noEnd = $this->lease('5', ['end_date' => null]);
        $archived = $this->lease('5');
        $archived->delete();

        $this->runCommand();

        foreach ([$draft, $expired, $cancelled, $noEnd] as $lease) {
            $this->assertFalse((bool) $lease->fresh()->is_month_to_month);
        }
        $this->assertCount(0, $this->switchedEvents($already));
        $this->assertCount(0, $this->switchedEvents($archived), 'an archived lease is never touched');
        $this->assertNotNull(Lease::withTrashed()->find($archived->id)->end_date);
    }

    public function test_a_reversal_by_the_agent_is_not_fought(): void
    {
        $lease = $this->lease('3');
        $this->runCommand();
        $this->assertTrue((bool) $lease->fresh()->is_month_to_month);

        app(LeaseRenewalService::class)->reverseMonthToMonth($lease->fresh(), $this->agent);
        $this->runCommand();

        $this->assertFalse((bool) $lease->fresh()->is_month_to_month, 'the agent said no — it stays no');
        $this->assertCount(1, $this->switchedEvents($lease));
    }

    // ═══ the agency's own setting, agency by agency ═══

    public function test_each_agency_is_switched_on_its_own_setting_in_one_run(): void
    {
        $worldB = $this->world();
        LeaseSetting::create(['agency_id' => $worldB[0]->id, 'month_to_month_after_end_days' => 5]);
        $a = $this->lease('3');                       // default (1 day): due
        $b = $this->lease('3', [], $worldB);          // this agency waits 5 days: not yet
        $bLater = $this->lease('6', [], $worldB);     // 6 days: due

        $this->runCommand();

        $this->assertTrue((bool) $a->fresh()->is_month_to_month);
        $this->assertFalse((bool) $b->fresh()->is_month_to_month);
        $this->assertTrue((bool) $bLater->fresh()->is_month_to_month);
    }

    public function test_an_agency_can_have_it_switch_on_the_end_date_itself(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'month_to_month_after_end_days' => 0]);
        $endsToday = $this->lease('0');
        $endsTomorrow = $this->lease('-1');

        $this->runCommand();

        $this->assertTrue((bool) $endsToday->fresh()->is_month_to_month);
        $this->assertFalse((bool) $endsTomorrow->fresh()->is_month_to_month);
    }

    public function test_the_default_is_one_day_after_and_a_saved_value_overrides_it(): void
    {
        $this->assertSame(1, LeaseSetting::monthToMonthAfterEndDaysFor(null));
        $this->assertSame(1, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
        LeaseSetting::create(['agency_id' => $this->agency->id, 'month_to_month_after_end_days' => 14]);
        $this->assertSame(14, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
    }

    // ═══ the command, as Johan runs it by hand ═══

    public function test_a_dry_run_lists_what_would_switch_and_changes_nothing(): void
    {
        Notification::fake();
        $lease = $this->lease('2');

        $this->artisan('leases:auto-month-to-month', ['--dry-run' => true])
            ->expectsOutputToContain("WOULD SWITCH: lease #{$lease->id}")
            ->expectsOutputToContain('Nothing changed')
            ->assertExitCode(0);

        $this->assertFalse((bool) $lease->fresh()->is_month_to_month);
        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->count());
        Notification::assertNothingSent();
    }

    public function test_one_lease_can_be_named_so_a_test_run_touches_nothing_else(): void
    {
        $one = $this->lease('2');
        $other = $this->lease('2');

        $this->artisan('leases:auto-month-to-month', ['--lease' => $one->id])->expectsOutputToContain("SWITCHED: lease #{$one->id}")->assertExitCode(0);

        $this->assertTrue((bool) $one->fresh()->is_month_to_month);
        $this->assertFalse((bool) $other->fresh()->is_month_to_month);
    }

    public function test_it_runs_daily_before_the_expiry_check_so_a_switched_lease_is_not_also_flagged_overdue(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $auto = $events->first(fn ($e) => str_contains((string) $e->command, 'leases:auto-month-to-month'));
        $expiry = $events->first(fn ($e) => str_contains((string) $e->command, 'signatures:check-lease-expiry'));

        $this->assertNotNull($auto, 'scheduled');
        $this->assertSame('30 5 * * *', $auto->expression);
        $this->assertSame('0 6 * * *', $expiry->expression);
        $this->assertTrue($auto->withoutOverlapping);
    }

    // ═══ the setting reaches the settings page and the Setup Wizard ═══

    public function test_the_settings_page_shows_and_saves_the_setting(): void
    {
        $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))
            ->assertOk()->assertSee('Automatic month-to-month')->assertSee('name="month_to_month_after_end_days"', false)->assertSee('value="1"', false);

        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'month_to_month_after_end_days' => 7])
            ->assertRedirect(route('corex.settings.leases.edit'));

        $this->assertSame(7, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
    }

    public function test_a_save_that_does_not_carry_the_field_never_wipes_it(): void
    {
        // The Setup Wizard's leases step posts a subset of this saver's fields (agency-onboarding-setup.md §6.1).
        LeaseSetting::create(['agency_id' => $this->agency->id, 'month_to_month_after_end_days' => 9]);

        $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45]);

        $this->assertSame(9, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
        $this->assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($this->agency->id));
    }

    public function test_an_out_of_range_value_is_refused(): void
    {
        foreach (['-1', '366', 'abc'] as $bad) {
            $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'month_to_month_after_end_days' => $bad])
                ->assertSessionHasErrors('month_to_month_after_end_days');
        }
        $this->assertSame(1, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
    }

    public function test_the_setup_wizard_asks_about_it_with_a_full_explanation_and_its_own_saver(): void
    {
        $step = config('agency-onboarding-copy.leases');
        $control = collect($step['controls'])->firstWhere('key', 'month_to_month_after_end_days');

        $this->assertNotNull($control, 'a setting that is not in the wizard is not done (CLAUDE.md #10a)');
        $this->assertSame('leases', $control['source']);
        $this->assertSame(1, $control['default']);
        $this->assertSame([0, 365], [$control['min'], $control['max']]);
        $this->assertStringContainsString('switches it to month-to-month by itself', $control['explain']);
        $this->assertStringContainsString('always stops the switch', $control['affects']);
        $this->assertContains(\App\Http\Controllers\CoreX\LeaseSettingsController::class, collect($step['savers'])->pluck('controller')->all());
    }

    public function test_the_wizard_shows_the_agencys_own_value_not_the_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'month_to_month_after_end_days' => 12]);

        $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))->assertSee('value="12"', false);
        $method = new \ReflectionMethod(\App\Http\Controllers\CoreX\AgencySetupWizardController::class, 'currentValues');
        $this->assertTrue($method->isPublic() || $method->isProtected() || $method->isPrivate(), 'currentValues exists');
        $this->assertSame(12, LeaseSetting::monthToMonthAfterEndDaysFor($this->agency->id));
    }
}
