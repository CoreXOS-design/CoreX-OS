<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Rentals\RentalInspectionNotificationMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionNotification;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §43 — schedule/reschedule/cancel,
 * notifications (tenant/landlord/inspector, settings-gated), calendar sync
 * (create/update/dismiss/complete, no duplicates), the reminder command,
 * and OWN/BRANCH/AGENCY scoping + agency isolation.
 */
final class RentalInspectionSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $inspector;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        // SignedDocumentDistributionService::sendGenericMail()'s own
        // non-production safety rail suppresses EVERY send (even under
        // Mail::fake(), since the check runs before the Mail facade is ever
        // touched) when mail.non_production_redirect is unset — true for
        // this box's dev .env. Configured here, test-local only, never
        // touching the real environment.
        config(['mail.non_production_redirect' => 'test-redirect@example.test']);

        $this->agency = Agency::create(['name' => 'RI Scheduling Agency', 'slug' => 'ri-scheduling-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->inspector = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Inspector Ivy',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'title' => '42 Scheduling Street',
            'status' => 'active',
            'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 10000,
            'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tenant', 'last_name' => 'Thabo', 'email' => 'tenant@example.test', 'phone' => '0821110000',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $this->landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Landlord', 'last_name' => 'Lerato', 'email' => 'landlord@example.test', 'phone' => '0822220000',
        ]);
        ContactProperty::create(['contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
    }

    // ── Schedule ─────────────────────────────────────────────────────

    public function test_scheduling_writes_the_fields_and_appears_in_the_scheduled_tile_and_list(): void
    {
        Mail::fake();

        $response = $this->post(route('corex.rental-inspections.store'), [
            'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN,
            'intent' => 'schedule',
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'scheduled_time' => '10:30',
            'scheduled_duration_minutes' => 45,
            'inspector_user_id' => $this->inspector->id,
            'schedule_note' => 'Bring spare keys.',
        ]);

        $inspection = RentalInspection::first();
        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));

        $this->assertNotNull($inspection);
        $this->assertSame(now()->addDays(5)->toDateString(), $inspection->scheduled_for->toDateString());
        $this->assertSame('10:30:00', $inspection->scheduled_time);
        $this->assertSame(45, $inspection->scheduled_duration_minutes);
        $this->assertSame($this->inspector->id, $inspection->inspector_user_id);
        $this->assertSame('Bring spare keys.', $inspection->schedule_note);
        // Unlike the original immediate Start, scheduling does NOT redirect
        // into the recording tab — nothing has been recorded yet.
        $this->assertSame(RentalInspection::STATUS_DRAFT, $inspection->status);

        $this->get(route('corex.rental-inspections.index'))
            ->assertOk()
            ->assertSee('42 Scheduling Street');

        $this->get(route('corex.rental-inspections.index', ['scheduled' => 1]))
            ->assertOk()
            ->assertSee('42 Scheduling Street');

        $this->get(route('corex.rental-inspections.index', ['inspector_id' => $this->inspector->id]))
            ->assertOk()
            ->assertSee('42 Scheduling Street');
        $this->get(route('corex.rental-inspections.index', ['inspector_id' => $this->agent->id]))
            ->assertOk()
            ->assertDontSee('42 Scheduling Street');
    }

    public function test_immediate_start_is_unchanged_no_intent_no_schedule_fields(): void
    {
        $response = $this->post(route('corex.rental-inspections.store'), [
            'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN,
        ]);

        $inspection = RentalInspection::first();
        $response->assertRedirect(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']));
        $this->assertNull($inspection->scheduled_for);
        $this->assertNull($inspection->inspector_user_id);
    }

    // ── Reschedule ───────────────────────────────────────────────────

    public function test_reschedule_keeps_history_and_updates_the_inspection(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'scheduled_time' => '09:00',
            'inspector_user_id' => $this->agent->id,
        ]);

        $newDate = now()->addDays(10)->toDateString();
        $response = $this->post(route('corex.rental-inspections.reschedule', $inspection), [
            'scheduled_for' => $newDate,
            'scheduled_time' => '14:00',
            'inspector_user_id' => $this->inspector->id,
            'reason' => 'Tenant requested a later date.',
        ]);
        $response->assertRedirect(route('corex.rental-inspections.show', $inspection));

        $inspection->refresh();
        $this->assertSame($newDate, $inspection->scheduled_for->toDateString());
        $this->assertSame('14:00:00', $inspection->scheduled_time);
        $this->assertSame($this->inspector->id, $inspection->inspector_user_id);

        $this->assertCount(1, $inspection->reschedules);
        $change = $inspection->reschedules->first();
        $this->assertSame('09:00:00', $change->old_scheduled_time);
        $this->assertSame($this->agent->id, $change->old_inspector_user_id);
        $this->assertSame($this->inspector->id, $change->new_inspector_user_id);
        $this->assertSame('Tenant requested a later date.', $change->reason);
        $this->assertSame($this->agent->id, $change->changed_by_user_id);
    }

    public function test_a_completed_inspection_cannot_be_rescheduled(): void
    {
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_AD_HOC, $this->agent, [
            'scheduled_for' => now()->addDays(2)->toDateString(),
        ]);
        $inspection->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->post(route('corex.rental-inspections.reschedule', $inspection), [
            'scheduled_for' => now()->addDays(20)->toDateString(),
        ])->assertRedirect(route('corex.rental-inspections.show', $inspection));

        $this->assertCount(0, $inspection->fresh()->reschedules);
    }

    // ── Cancel (soft, reason required — pre-existing, verifying the §43 hooks) ──

    public function test_cancel_requires_a_reason_and_still_works(): void
    {
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(3)->toDateString(),
        ]);

        $this->post(route('corex.rental-inspections.cancel', $inspection), [])
            ->assertSessionHasErrors('cancel_reason');

        $this->post(route('corex.rental-inspections.cancel', $inspection), ['cancel_reason' => 'Owner postponed.'])
            ->assertRedirect(route('corex.rental-inspections.show', $inspection));

        $this->assertSame(RentalInspection::STATUS_CANCELLED, $inspection->fresh()->status);
    }

    // ── Notifications ────────────────────────────────────────────────

    public function test_scheduling_notifies_tenant_landlord_and_inspector_by_mail_and_logs_each(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'inspector_user_id' => $this->inspector->id,
        ]);

        Mail::assertSent(RentalInspectionNotificationMail::class, 3);

        $logged = RentalInspectionNotification::where('rental_inspection_id', $inspection->id)
            ->where('event', RentalInspectionNotification::EVENT_SCHEDULED)
            ->get();
        $this->assertCount(3, $logged);
        $roles = $logged->pluck('party_role')->sort()->values()->all();
        $this->assertSame(['inspector', 'landlord', 'tenant'], $roles);
        $this->assertTrue($logged->every(fn ($n) => $n->status === RentalInspectionNotification::STATUS_SENT));
    }

    public function test_tenant_is_never_substituted_as_landlord_when_no_landlord_is_tagged(): void
    {
        Mail::fake();

        // A second property with a tenant but NO landlord/lessor/seller_owner
        // tag at all — Lease::landlordContacts() must return empty, never
        // guess at "the only contact on file" (its own docblock's guard).
        $bareProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'No Landlord Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $bareLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $bareProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $bareTenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Solo', 'last_name' => 'Tenant', 'email' => 'solo@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $bareLease->id, 'contact_id' => $bareTenant->id, 'is_primary' => true]);

        $inspection = RentalInspection::schedule($bareProperty, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);

        $logged = RentalInspectionNotification::where('rental_inspection_id', $inspection->id)->get();
        $this->assertFalse($logged->contains('party_role', RentalInspectionNotification::PARTY_LANDLORD), 'no landlord row should be logged at all — never the tenant standing in for one');
        $this->assertTrue($logged->contains('party_role', RentalInspectionNotification::PARTY_TENANT));
        $this->assertCount(1, $logged->where('party_role', RentalInspectionNotification::PARTY_TENANT));
    }

    public function test_notification_settings_narrow_who_and_how_is_notified(): void
    {
        Mail::fake();

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'notify_landlord_enabled' => false,
            'notify_inspector_enabled' => false,
        ]);

        RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'inspector_user_id' => $this->inspector->id,
        ]);

        Mail::assertSent(RentalInspectionNotificationMail::class, 1);
    }

    public function test_whatsapp_channel_logs_queued_never_a_fabricated_send(): void
    {
        Mail::fake();

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'notify_via_mail_enabled' => false,
            'notify_via_whatsapp_enabled' => true,
        ]);

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);

        Mail::assertNothingSent();
        $whatsapp = RentalInspectionNotification::where('rental_inspection_id', $inspection->id)
            ->where('channel', RentalInspectionNotification::CHANNEL_WHATSAPP)->get();
        // Tenant and landlord both have a phone on file (setUp) and must be
        // queued; the inspector defaults to $this->agent, a bare factory
        // user with no phone, so THEIR row is correctly logged 'skipped' —
        // never 'sent' on this channel, no matter who the recipient is.
        $this->assertCount(1, $whatsapp->where('party_role', RentalInspectionNotification::PARTY_TENANT));
        $this->assertSame(RentalInspectionNotification::STATUS_QUEUED, $whatsapp->firstWhere('party_role', RentalInspectionNotification::PARTY_TENANT)->status);
        $this->assertSame(RentalInspectionNotification::STATUS_QUEUED, $whatsapp->firstWhere('party_role', RentalInspectionNotification::PARTY_LANDLORD)->status);
        $this->assertFalse($whatsapp->contains('status', RentalInspectionNotification::STATUS_SENT), 'WhatsApp must never be logged as actually sent — there is no real send path.');
    }

    // ── Calendar sync ────────────────────────────────────────────────

    public function test_scheduling_creates_a_calendar_event_on_the_inspectors_calendar(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'scheduled_time' => '10:00',
            'inspector_user_id' => $this->inspector->id,
        ]);

        $event = CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $inspection->id)->first();
        $this->assertNotNull($event);
        $this->assertSame($this->inspector->id, $event->user_id);
        $this->assertSame('pending', $event->status);
        $this->assertStringContainsString('Tenant: Tenant Thabo', $event->description);
        $this->assertStringContainsString('Landlord: Landlord Lerato', $event->description);
    }

    public function test_reschedule_updates_the_same_event_never_a_duplicate(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);

        $inspection->reschedule(['scheduled_for' => now()->addDays(8)->toDateString()], $this->agent, 'moved');

        $events = CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $inspection->id)->get();
        $this->assertCount(1, $events, 'a reschedule must update the existing event, never create a second one');
        $this->assertSame(now()->addDays(8)->toDateString(), $events->first()->event_date->toDateString());
    }

    public function test_cancel_dismisses_the_calendar_event(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);
        $inspection->cancel($this->agent, 'No longer needed.');

        $event = CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $inspection->id)->first();
        $this->assertSame('dismissed', $event->status);
    }

    public function test_an_immediate_start_never_creates_a_calendar_event(): void
    {
        $inspection = RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->agent);

        $event = CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $inspection->id)->first();
        $this->assertNull($event);
    }

    // ── Reminder command ─────────────────────────────────────────────

    public function test_reminder_command_sends_exactly_on_the_configured_day_and_not_twice(): void
    {
        Mail::fake();

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(3)->toDateString(),
        ]);
        // The initial "scheduled" notification already sent above — reset the
        // mail fake so this test only observes the REMINDER command's own sends.
        Mail::fake();

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['reminder_days_before' => 3]);

        $this->artisan('rentals:send-inspection-reminders')->assertExitCode(0);

        Mail::assertSent(RentalInspectionNotificationMail::class, function ($mail) {
            return $mail->eventLabel === 'Reminder';
        });

        // Three recipients: tenant, landlord, and the inspector — schedule()
        // defaults inspector_user_id to the booking user ($this->agent) when
        // not explicitly set, so the agent is notified as the inspector too.
        $reminderCount = RentalInspectionNotification::where('rental_inspection_id', $inspection->id)
            ->where('event', RentalInspectionNotification::EVENT_REMINDER)->count();
        $this->assertSame(3, $reminderCount);

        // Running again the SAME day must not double-send.
        Mail::fake();
        $this->artisan('rentals:send-inspection-reminders')->assertExitCode(0);
        Mail::assertNothingSent();
    }

    public function test_reminder_off_setting_zero_sends_nothing(): void
    {
        Mail::fake();
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(1)->toDateString(),
        ]);
        Mail::fake();

        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['reminder_days_before' => 0]);

        $this->artisan('rentals:send-inspection-reminders')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->assertSame(0, RentalInspectionNotification::where('rental_inspection_id', $inspection->id)
            ->where('event', RentalInspectionNotification::EVENT_REMINDER)->count());
    }

    // ── Scoping + agency isolation ───────────────────────────────────

    public function test_own_scope_includes_inspections_where_the_viewer_is_the_inspector(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $other, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
            'inspector_user_id' => $this->agent->id,
        ]);

        // The viewer ($this->agent) didn't CREATE it, but IS the inspector —
        // 'own' scope (the fallback for a role with no higher data scope)
        // must still surface it.
        $this->get(route('corex.rental-inspections.index', ['scope' => 'own']))
            ->assertOk()->assertSee('42 Scheduling Street');
    }

    public function test_a_scheduled_inspection_is_invisible_to_another_agency(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-agency-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Other', 'agency_id' => $otherAgency->id]);
        // 'agent', not 'admin' — matches RentalInspectionScopeGuardTest's own
        // fixture convention. An 'admin' role's AgencyScope behaviour depends
        // on Role::allRoles()->is_owner, which this test has no reason to
        // seed; 'agent' sidesteps that question entirely and is still a
        // perfectly good negative case (an ordinary user of a DIFFERENT
        // agency must never see this one's inspection).
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);

        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($otherAgent);
        $this->get(route('corex.rental-inspections.show', $inspection))->assertNotFound();
    }
}
