<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalPortalSetting;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalOwnerChaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026 (C-A1, C-A2, C-A3 fault rows) - spec rentals-faults-work-orders.md section 29: an owner who never answers is reminded twice and then the fault goes on the
 * agent's queue (every timing an agency setting); an owner-declined fault and an unsent owner copy have their own queue rows.
 */
final class AuditP1OwnerChaseTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Chase Agency', 'slug' => 'ch-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'C-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Chase Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        Carbon::setTestNow(Carbon::parse('2026-10-19 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentFault(int $hoursAgo = 0, array $over = []): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'title' => 'Geyser leaks', 'description' => 'It drips',
            'status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING,
            'reported_at' => now()->subHours($hoursAgo), 'sent_to_owner_at' => now()->subHours($hoursAgo), 'owner_version_saved_at' => now()->subHours($hoursAgo),
            'owner_title' => 'Geyser leaks', 'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    private function chase(): array
    {
        return app(RentalOwnerChaseService::class)->run();
    }

    private function history(RentalFaultReport $f): string
    {
        return $f->fresh()->history()->map(fn ($h) => $h['action'] . ' ' . $h['note'])->implode(' | ');
    }

    public function test_the_owner_is_reminded_once_after_the_default_24_hours_then_a_second_time_then_queued(): void
    {
        $f = $this->sentFault();

        Carbon::setTestNow(now()->addHours(23));
        $this->assertSame(0, $this->chase()['reminded_1'], 'not yet: the default is 24 hours');
        Mail::assertNothingQueued();

        Carbon::setTestNow(now()->addHours(2));   // 25 h
        $this->assertSame(1, $this->chase()['reminded_1']);
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, fn ($m) => $m->reminderNumber === 1 && $m->hasTo($this->landlord->email));
        $this->assertSame(0, $this->chase()['reminded_1'], 'idempotent: a second run does not send it again');
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, 1);
        $this->assertNotNull($f->fresh()->owner_reminder_1_at);
        $this->assertStringContainsString('First reminder emailed to the owner', $this->history($f));

        Carbon::setTestNow(now()->addHours(23));   // 48 h: only 23 h after the first
        $this->assertSame(0, $this->chase()['reminded_2']);
        Carbon::setTestNow(now()->addHours(2));    // 50 h: 25 h after the first
        $this->assertSame(1, $this->chase()['reminded_2']);
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, fn ($m) => $m->reminderNumber === 2);
        $this->assertStringContainsString('Second reminder emailed', $this->history($f));

        // the queue row only after the third step
        $row = fn () => app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_owner_silent');
        $this->assertCount(0, $row());
        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, $this->chase()['queued']);
        $this->assertCount(1, $row());
        $this->assertStringContainsString('phone the owner', $row()->first()['detail']);
        $this->assertSame(0, app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->where('type', 'fault_awaiting_approval')->count(), 'the silent fault is ONE row, not two');
        $this->assertStringContainsString('on your queue', strtolower($this->history($f)));
        $this->assertSame(0, $this->chase()['queued'], 'once');
    }

    public function test_one_step_per_run_so_a_catch_up_never_sends_both_reminders_at_once(): void
    {
        $this->sentFault(200);

        $this->chase();
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, 1);
    }

    public function test_every_timing_is_the_agencys_own_setting(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['owner_reminder_hours' => 2, 'owner_second_reminder_hours' => 3, 'owner_queue_after_hours' => 4]);
        $f = $this->sentFault();

        Carbon::setTestNow(now()->addHours(3));
        $this->assertSame(1, $this->chase()['reminded_1']);
        Carbon::setTestNow(now()->addHours(4));
        $this->assertSame(1, $this->chase()['reminded_2']);
        Carbon::setTestNow(now()->addHours(5));
        $this->assertSame(1, $this->chase()['queued']);
        $this->assertNotNull($f->fresh()->owner_queue_flagged_at);
    }

    public function test_the_defaults_are_24_24_24(): void
    {
        $this->assertSame(24, RentalPortalSetting::ownerReminderHoursFor($this->agency->id));
        $this->assertSame(24, RentalPortalSetting::ownerSecondReminderHoursFor($this->agency->id));
        $this->assertSame(24, RentalPortalSetting::ownerQueueAfterHoursFor($this->agency->id));
    }

    public function test_a_paused_a_decided_and_a_cancelled_fault_is_never_chased(): void
    {
        $decided = $this->sentFault(100, ['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED]);
        $cancelled = $this->sentFault(100, ['status' => RentalFaultReport::STATUS_CANCELLED]);
        $this->assertSame(['reminded_1' => 0, 'reminded_2' => 0, 'queued' => 0, 'skipped_hold' => 0], $this->chase());
        $this->assertNull($decided->fresh()->owner_reminder_1_at);
        $this->assertNull($cancelled->fresh()->owner_reminder_1_at);
        Mail::assertNothingQueued();
    }

    public function test_a_fault_the_owner_has_paused_is_not_chased_and_its_clock_does_not_advance(): void
    {
        $f = $this->sentFault(100);
        \App\Models\RentalOwnerHold::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => $f->id, 'stopped_by_contact_id' => $this->landlord->id, 'stopped_via' => 'portal',
            'reason' => 'Wait for my return', 'stage_when_stopped' => 'with_owner', 'stopped_at' => now()->subHours(90),
        ]);

        $this->assertSame(1, $this->chase()['skipped_hold']);
        $this->assertNull($f->fresh()->owner_reminder_1_at);
        Mail::assertNothingQueued();
    }

    public function test_an_owner_who_could_not_be_emailed_is_said_so_in_the_history_and_the_chase_still_moves_on(): void
    {
        $this->landlord->forceFill(['email' => null])->save();
        $f = $this->sentFault(30);

        $this->assertSame(1, $this->chase()['reminded_1']);
        Mail::assertNothingQueued();
        $this->assertStringContainsString('Owner reminder not sent - no owner has an email address on file', $this->history($f));
        $this->assertNotNull($f->fresh()->owner_reminder_1_at, 'the ledger still advances, so the queue row is not held back by a missing address');
    }

    public function test_when_the_agency_switched_the_owner_email_off_nothing_is_sent_silently_any_more(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_decision_needed' => false]);
        $f = $this->sentFault(30);

        $this->chase();
        Mail::assertNothingQueued();
        $this->assertStringContainsString('switched off the email that tells the owner a decision is needed', $this->history($f));

        // and the very first send (sent to owner) says so too
        $g = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'reported_by_type' => 'tenant',
            'reported_channel' => 'app', 'title' => 'Door', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $g->requestApproval($this->agent);
        $this->assertStringContainsString('Owner could not be told', $this->history($g));
    }

    public function test_the_dry_run_counts_and_changes_nothing(): void
    {
        $f = $this->sentFault(30);
        $r = app(RentalOwnerChaseService::class)->run(null, true);

        $this->assertSame(1, $r['reminded_1']);
        $this->assertNull($f->fresh()->owner_reminder_1_at);
        Mail::assertNothingQueued();
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->sentFault(30);
        $this->artisan('rentals:chase-owner-decisions')->assertExitCode(0);
        Mail::assertQueued(RentalLandlordDecisionNeededMail::class, 1);
    }

    // ── C-A2 / C-A3: the fault rows ──────────────────────────────────────────────────────────────────────────

    public function test_an_owner_declined_fault_has_a_queue_row_until_the_agent_sets_the_outcome(): void
    {
        $f = $this->sentFault(5, ['status' => RentalFaultReport::STATUS_DECLINED, 'owner_approval_status' => RentalFaultReport::APPROVAL_DECLINED, 'outcome' => 'owner_declined', 'outcome_set_automatically' => true]);
        $rows = fn () => app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_owner_declined');

        $this->assertCount(1, $rows());
        $this->assertSame('Owner declined', $rows()->first()['label']);
        $this->assertStringContainsString('change the decision', $rows()->first()['detail']);
        $this->assertSame('corex.rental-fault-reports.show', $rows()->first()['route']);

        $f->forceFill(['outcome_set_automatically' => false])->save();   // the agent dealt with it
        $this->assertCount(0, $rows());
    }

    public function test_an_unsent_owner_copy_stays_on_the_queue(): void
    {
        $this->sentFault(5, ['status' => RentalFaultReport::STATUS_UNDER_REVIEW, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'sent_to_owner_at' => null]);
        $rows = app(RentalCommandCentreService::class)->queueItems($this->agent, 'all')->filter(fn ($i) => $i['type'] === 'fault_under_review');

        $this->assertCount(1, $rows);
        $this->assertSame('Send to owner', $rows->first()['label']);
    }
}
