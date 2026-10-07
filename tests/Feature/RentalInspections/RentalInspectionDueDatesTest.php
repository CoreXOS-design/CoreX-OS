<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDueNotice;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionPlannedDateNotice;
use App\Models\RentalInspectionSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Notifications\RentalInspectionDueReminder;
use App\Services\PermissionService;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalInspectionDueReminderService;
use App\Services\Rentals\RentalInspectionDueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.7 (Build I-5) — due dates: In/Out computed from the leases, interim dates the AGENCY
 * loads, agent-only reminders, the Due tab, the three settings.
 *
 * Input paths proven (BUILD_STANDARD §5): In — overdue / due today / future / completed on the lease / completed on a PREVIOUS
 * lease of the chain / open / cancelled-only / draft or expired lease / archived property / other agency; Out — move-out date /
 * fixed term end / month-to-month no notice / completed / open / inside and outside the lead window; reminders — lead, due,
 * overdue, re-run, catch-up after missed days, loaded on the day, loaded in the past, lease ended, archived, skipped, done, no
 * active agent, no email, mail failure, toggle off, backlog, other agency; loaded dates — one / several / repeat / past /
 * create-archive-reload / other agency's lease / nothing / too many / move / clash / booked can't move / skip needs a reason /
 * reopen / archive / restore / archived read-only; scoping — own-scope user cannot reach a colleague's date by URL; booking —
 * link, mismatched lease, forged id, complete -> done, cancel -> planned, archive -> planned; interim signing guard; settings.
 */
final class RentalInspectionDueDatesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $admin;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));

        $this->agency = Agency::create(['name' => 'Due Dates Agency', 'slug' => 'due-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true, 'email' => 'agent-' . uniqid() . '@example.test']);

        $this->property = $this->makeProperty($this->agency, $this->branch, $this->agent, '14 Marine Drive, Margate');
        $this->lease = $this->makeLease($this->property, ['start_date' => '2026-09-01', 'end_date' => '2027-08-31']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function makeProperty(Agency $agency, Branch $branch, User $agent, string $title): Property
    {
        return Property::forceCreate([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeLease(Property $property, array $over = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $property->agency_id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => '2026-09-01',
            'created_by_user_id' => $this->agent->id, 'source' => 'manual',
        ], $over));

        $contact = Contact::create([
            'agency_id' => $property->agency_id, 'branch_id' => $property->branch_id, 'first_name' => 'Thandi',
            'last_name' => 'Nkosi' . uniqid(), 'email' => 'tenant-' . uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    private function inspection(Lease $lease, string $type, string $status): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'type' => $type, 'status' => $status,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function date(Lease $lease, string $on, array $over = []): RentalInspectionPlannedDate
    {
        return RentalInspectionPlannedDate::create(array_merge([
            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => 'interim', 'planned_on' => $on, 'status' => 'planned', 'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    private function loadedOn(RentalInspectionPlannedDate $d, string $when): void
    {
        RentalInspectionPlannedDate::withoutGlobalScopes()->where('id', $d->id)->update(['created_at' => $when]);
    }

    private function due(): RentalInspectionDueService
    {
        return app(RentalInspectionDueService::class);
    }

    private function items(?int $agencyId = null)
    {
        return $this->due()->inOutItemsFor($agencyId ?? $this->agency->id);
    }

    private function item(string $type, ?Lease $lease = null): ?array
    {
        return $this->items()->first(fn ($i) => $i['type'] === $type && $i['lease_id'] === ($lease ?? $this->lease)->id);
    }

    // ── In ──────────────────────────────────────────────────────────────

    public function test_in_is_due_on_the_lease_start_and_overdue_after_it(): void
    {
        $item = $this->item('in');
        $this->assertNotNull($item);
        $this->assertSame('2026-09-01', $item['due_on']->toDateString());
        $this->assertSame('overdue', $item['state']);

        $this->lease->update(['start_date' => '2026-10-07']);
        $this->assertSame('due', $this->item('in')['state'], 'due today is due, not overdue');

        $this->lease->update(['start_date' => '2026-10-20']);
        $this->assertSame('upcoming', $this->item('in')['state'], 'a future start is listed as upcoming, never as due');
        $this->assertNotContains($this->property->id, $this->due()->dueNowPropertyIds($this->agency->id), 'a future one is never counted');
    }

    public function test_a_completed_in_on_the_lease_or_anywhere_on_the_tenancy_chain_satisfies_it(): void
    {
        $this->assertNotNull($this->item('in'));

        $this->inspection($this->lease, 'in', 'cancelled');
        $this->assertNotNull($this->item('in'), 'a cancelled in-inspection never happened');

        $done = $this->inspection($this->lease, 'in', 'completed');
        $this->assertNull($this->item('in'));

        // a renewal: the NEW lease carries previous_lease_id; the move-in was done on the old term and must not re-prompt
        $done->delete();
        $this->assertNotNull($this->item('in'));
        $old = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED, 'start_date' => '2025-09-01', 'end_date' => '2026-08-31']);
        $this->lease->update(['previous_lease_id' => $old->id]);
        $this->inspection($old, 'in', 'completed');
        $this->assertNull($this->item('in'), 'a renewal never re-prompts In (the old check looked at the current lease only)');

        // two renewals back
        $older = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED, 'start_date' => '2024-09-01', 'end_date' => '2025-08-31']);
        $old->update(['previous_lease_id' => $older->id]);
        RentalInspection::query()->where('lease_id', $old->id)->forceDelete();
        $this->inspection($older, 'in', 'completed');
        $this->assertNull($this->item('in'));
    }

    public function test_an_already_open_in_satisfies_due(): void
    {
        foreach (['draft', 'in_progress', 'awaiting_signature'] as $status) {
            $i = $this->inspection($this->lease, 'in', $status);
            $this->assertNull($this->item('in'), "an open {$status} in-inspection means it is already being done");
            $i->forceDelete();
        }
        $this->assertNotNull($this->item('in'));
    }

    public function test_only_active_leases_on_live_properties_in_this_agency_are_scanned(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);
        $this->assertCount(0, $this->items());
        $this->lease->update(['status' => Lease::STATUS_DRAFT]);
        $this->assertCount(0, $this->items());
        $this->lease->update(['status' => Lease::STATUS_ACTIVE]);
        $this->assertGreaterThan(0, $this->items()->count());

        // archived behind the lease's back (the observer rightly refuses to archive a property with an active lease)
        Property::withoutGlobalScopes()->where('id', $this->property->id)->update(['deleted_at' => now()]);
        $this->assertCount(0, $this->items(), 'an archived property drops out');
        Property::withoutGlobalScopes()->where('id', $this->property->id)->update(['deleted_at' => null]);

        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $this->assertCount(0, $this->items($other->id), 'another agency sees none of this agency\'s leases');
    }

    // ── Out ─────────────────────────────────────────────────────────────

    public function test_out_follows_the_move_out_date_then_the_fixed_term_end_and_never_a_month_to_month_with_no_notice(): void
    {
        $this->lease->update(['end_date' => '2027-08-31', 'move_out_date' => '2026-10-20']);
        $this->assertSame('2026-10-20', $this->item('out')['due_on']->toDateString(), 'the recorded move-out date wins');

        $this->lease->update(['move_out_date' => null]);
        $this->assertSame('2027-08-31', $this->item('out')['due_on']->toDateString(), 'a fixed term falls back to its end date');

        $this->lease->update(['is_month_to_month' => true, 'end_date' => null]);
        $this->assertNull($this->item('out'), 'month-to-month with no notice has no out due');

        $this->lease->update(['move_out_date' => '2026-11-30']);
        $this->assertSame('2026-11-30', $this->item('out')['due_on']->toDateString(), 'month-to-month WITH a move-out date does');

        $this->lease->update(['move_out_date' => null, 'end_date' => null, 'is_month_to_month' => false]);
        $this->assertNull($this->item('out'), 'no dates at all, nothing due, never an error');
    }

    public function test_out_state_follows_the_agencys_lead_window(): void
    {
        $this->lease->update(['move_out_date' => '2026-10-12']); // 5 days away, default lead 7
        $this->assertSame('due', $this->item('out')['state']);
        $this->lease->update(['move_out_date' => '2026-11-20']);
        $this->assertSame('upcoming', $this->item('out')['state']);

        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'out_due_lead_days' => 60]);
        $this->assertSame('due', $this->item('out')['state'], 'the agency widened its own window');

        RentalInspectionSetting::where('agency_id', $this->agency->id)->update(['out_due_lead_days' => 0]);
        $this->lease->update(['move_out_date' => '2026-10-07']);
        $this->assertSame('due', $this->item('out')['state'], '0 = from the day itself');
        $this->lease->update(['move_out_date' => '2026-10-08']);
        $this->assertSame('upcoming', $this->item('out')['state']);
    }

    public function test_a_completed_or_open_out_satisfies_it(): void
    {
        $this->lease->update(['move_out_date' => '2026-10-01']);
        $this->assertNotNull($this->item('out'));
        $o = $this->inspection($this->lease, 'out', 'draft');
        $this->assertNull($this->item('out'));
        $o->forceDelete();
        $this->inspection($this->lease, 'out', 'completed');
        $this->assertNull($this->item('out'));
    }

    // ── Reminders: loaded interim dates ─────────────────────────────────

    private function runPlanned(string $on): array
    {
        return app(RentalInspectionDueReminderService::class)->runPlanned(Carbon::parse($on));
    }

    public function test_a_loaded_date_reminds_the_property_agent_at_lead_then_on_the_day_then_the_day_after_and_never_twice(): void
    {
        Notification::fake();
        $d = $this->date($this->lease, '2026-12-03');
        $this->loadedOn($d, '2026-10-01 08:00:00');

        $this->assertSame(0, $this->runPlanned('2026-11-18')['sent'], '15 days out: before the 14-day lead');
        $this->assertSame(1, $this->runPlanned('2026-11-19')['sent'], 'lead day');
        $this->assertSame(0, $this->runPlanned('2026-11-19')['sent'], 'a re-run the same day sends nothing');
        $this->assertSame(0, $this->runPlanned('2026-11-25')['sent'], 'nothing in between');
        $this->assertSame(1, $this->runPlanned('2026-12-03')['sent'], 'the day itself');
        $this->assertSame(1, $this->runPlanned('2026-12-04')['sent'], 'the day after');
        $this->assertSame(0, $this->runPlanned('2026-12-20')['sent'], 'no nagging after that');

        Notification::assertSentToTimes($this->agent, RentalInspectionDueReminder::class, 3);
        $this->assertSame(['lead', 'due', 'overdue'], RentalInspectionPlannedDateNotice::where('planned_date_id', $d->id)->orderBy('id')->pluck('milestone')->all());
        $this->assertSame(3, RentalInspectionPlannedDateNotice::where('planned_date_id', $d->id)->where('status', 'sent')->count());
    }

    public function test_reminders_go_to_the_agent_only_never_to_a_tenant_or_landlord(): void
    {
        Notification::fake();
        $d = $this->date($this->lease, '2026-10-07');
        $this->loadedOn($d, '2026-10-01 08:00:00');

        $this->runPlanned('2026-10-07');

        Notification::assertSentToTimes($this->agent, RentalInspectionDueReminder::class, 1);
        Notification::assertCount(1);
    }

    public function test_a_missed_cron_catches_up_with_only_the_latest_milestone(): void
    {
        Notification::fake();
        $d = $this->date($this->lease, '2026-12-03');
        $this->loadedOn($d, '2026-10-01 08:00:00');

        $tally = $this->runPlanned('2026-12-10'); // lead, due and overdue all reached, none ever run

        $this->assertSame(['sent' => 1, 'skipped' => 2, 'failed' => 0], $tally);
        Notification::assertSentToTimes($this->agent, RentalInspectionDueReminder::class, 1);
        $rows = RentalInspectionPlannedDateNotice::where('planned_date_id', $d->id)->get()->keyBy('milestone');
        $this->assertSame('sent', $rows['overdue']->status);
        $this->assertSame('skipped', $rows['lead']->status);
        $this->assertSame('skipped', $rows['due']->status);
        $this->assertSame(0, $this->runPlanned('2026-12-11')['sent'], 'and nothing more afterwards');
    }

    public function test_loading_a_backlog_never_floods_the_agent(): void
    {
        Notification::fake();
        foreach (['2026-09-01', '2026-09-15', '2026-10-06', '2026-10-07'] as $past) {
            $this->date($this->lease->fresh(), $past);   // all loaded "now" (2026-10-07)
        }

        $tally = $this->runPlanned('2026-10-07');

        $this->assertSame(0, $tally['sent'], 'dates loaded in the past (or today) are overdue on the list but never mailed in bulk');
        Notification::assertNothingSent();
        $this->assertSame(4, RentalInspectionPlannedDate::count());
    }

    public function test_a_date_on_a_lease_that_has_ended_is_never_mailed_or_logged(): void
    {
        Notification::fake();
        $d = $this->date($this->lease, '2026-10-07');
        $this->loadedOn($d, '2026-10-01 08:00:00');
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $this->runPlanned('2026-10-07'));
        Notification::assertNothingSent();
        $this->assertSame(0, RentalInspectionPlannedDateNotice::count());
    }

    public function test_archived_skipped_and_done_dates_are_never_reminded(): void
    {
        Notification::fake();
        foreach ([['status' => 'skipped', 'skipped_reason' => 'Tenant away'], ['status' => 'done']] as $over) {
            $d = $this->date($this->lease, '2026-10-07', $over);
            $this->loadedOn($d, '2026-10-01 08:00:00');
        }
        $archived = $this->date($this->lease, '2026-10-07');
        $this->loadedOn($archived, '2026-10-01 08:00:00');
        $archived->delete();

        $this->assertSame(0, $this->runPlanned('2026-10-07')['sent']);
        Notification::assertNothingSent();
    }

    public function test_a_booked_date_is_still_reminded(): void
    {
        Notification::fake();
        $d = $this->date($this->lease, '2026-10-07', ['status' => 'booked']);
        $this->loadedOn($d, '2026-10-01 08:00:00');

        $this->assertSame(1, $this->runPlanned('2026-10-07')['sent']);
    }

    public function test_no_active_agent_is_recorded_not_a_crash(): void
    {
        Notification::fake();
        $this->agent->update(['is_active' => false]);
        $d = $this->date($this->lease, '2026-10-07');
        $this->loadedOn($d, '2026-10-01 08:00:00');

        // lead and due both reached: lead is superseded, due finds nobody to remind
        $this->assertSame(['sent' => 0, 'skipped' => 2, 'failed' => 0], $this->runPlanned('2026-10-07'));
        $this->assertSame('no active agent to remind', RentalInspectionPlannedDateNotice::where('milestone', 'due')->value('detail'));
    }

    public function test_the_reminder_is_in_app_and_email_and_in_app_only_without_an_address(): void
    {
        $n = new RentalInspectionDueReminder('interim', 'lead', '2026-12-03', '14 Jackson St', 5, 'planned_date', 'https://x.test/due');

        $this->assertSame(['database', 'mail'], $n->via($this->agent));
        $this->agent->email = '';
        $this->assertSame(['database'], $n->via($this->agent));

        $this->assertSame('Interim inspection due 3 Dec 2026 — 14 Jackson St', $n->message());
        $this->assertSame('Interim inspection due 3 Dec 2026 — 14 Jackson St', $n->toMail($this->agent)->subject);
        $this->assertSame('Move-out inspection overdue since 3 Dec 2026 — 14 Jackson St', (new RentalInspectionDueReminder('out', 'overdue', '2026-12-03', '14 Jackson St', 5, 'due_list', 'u'))->message());
        $this->assertSame('rental_inspection_due_reminder', $n->toArray($this->agent)['type']);
    }

    public function test_a_send_that_throws_is_recorded_as_failed_and_the_rest_of_the_run_continues(): void
    {
        $bad = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $badProperty = $this->makeProperty($this->agency, $this->branch, $bad, '2 Beach Road, Uvongo');
        $badLease = $this->makeLease($badProperty);
        $okDate = $this->date($this->lease, '2026-10-07');
        $badDate = $this->date($badLease, '2026-10-07');
        $this->loadedOn($okDate, '2026-10-01 08:00:00');
        $this->loadedOn($badDate, '2026-10-01 08:00:00');

        // one agent's notification channel explodes
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function ($e) use ($bad) {
            if ($e->notifiable->id === $bad->id) {
                throw new \RuntimeException('mail server down');
            }
        });

        $tally = $this->runPlanned('2026-10-07');

        $this->assertSame(1, $tally['failed']);
        $this->assertSame('failed', RentalInspectionPlannedDateNotice::where('planned_date_id', $badDate->id)->value('status'));
        $this->assertStringContainsString('mail server down', (string) RentalInspectionPlannedDateNotice::where('planned_date_id', $badDate->id)->value('detail'));
    }

    public function test_a_second_agencys_dates_use_its_own_lead_window(): void
    {
        Notification::fake();
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $other->id, 'name' => 'CPT']);
        $agent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $prop = $this->makeProperty($other, $branch, $agent, '3 Beach Road, Sea Point');
        $lease = $this->makeLease($prop, ['created_by_user_id' => $agent->id]);
        RentalInspectionSetting::create(['agency_id' => $other->id, 'planned_date_lead_days' => 3]);

        $mine = $this->date($this->lease, '2026-10-20');   // default 14-day lead: reached on 2026-10-06
        $theirs = $this->date($lease, '2026-10-20');       // 3-day lead: reached on 2026-10-17
        $this->loadedOn($mine, '2026-10-01 08:00:00');
        $this->loadedOn($theirs, '2026-10-01 08:00:00');

        $this->runPlanned('2026-10-08');
        Notification::assertSentTo($this->agent, RentalInspectionDueReminder::class);
        Notification::assertNotSentTo($agent, RentalInspectionDueReminder::class);

        $this->runPlanned('2026-10-17');
        Notification::assertSentTo($agent, RentalInspectionDueReminder::class);
    }

    public function test_the_planned_reminder_command_runs(): void
    {
        $this->artisan('rentals:send-planned-inspection-reminders')->assertExitCode(0);
        $this->artisan('rentals:send-due-inspection-reminders')->assertExitCode(0);
    }

    // ── Reminders: computed In/Out ──────────────────────────────────────

    private function runDue(string $on): array
    {
        return app(RentalInspectionDueReminderService::class)->runDue(Carbon::parse($on));
    }

    public function test_a_move_out_reminds_at_lead_on_the_day_and_after_and_once_each(): void
    {
        Notification::fake();
        $this->lease->update(['move_out_date' => '2026-10-20']);
        // the move-in is long done, so only the out-inspection is in play
        $this->inspection($this->lease, 'in', 'completed');

        $this->assertSame(0, $this->runDue('2026-10-12')['sent'], 'before the 7-day lead (13th)');
        $this->assertSame(1, $this->runDue('2026-10-13')['sent']);
        $this->assertSame(0, $this->runDue('2026-10-13')['sent']);
        $this->assertSame(1, $this->runDue('2026-10-20')['sent']);
        $this->assertSame(1, $this->runDue('2026-10-21')['sent']);
        $this->assertSame(0, $this->runDue('2026-10-22')['sent']);

        Notification::assertSentToTimes($this->agent, RentalInspectionDueReminder::class, 3);
        $this->assertSame(3, RentalInspectionDueNotice::where('lease_id', $this->lease->id)->where('type', 'out')->count());
    }

    public function test_a_moved_move_out_date_is_reminded_afresh(): void
    {
        Notification::fake();
        $this->inspection($this->lease, 'in', 'completed');
        $this->lease->update(['move_out_date' => '2026-10-07']);
        $this->assertSame(1, $this->runDue('2026-10-07')['sent']);

        $this->lease->update(['move_out_date' => '2026-10-31']);
        $this->assertSame(1, $this->runDue('2026-10-31')['sent'], 'a new date is a new due item');
    }

    public function test_an_item_already_overdue_when_reminders_begin_is_not_mailed_in_bulk(): void
    {
        Notification::fake();
        // 40 days overdue move-in, never run before: it is on the Due tab / Command Centre, not 50 emails on day one
        $tally = $this->runDue('2026-10-07');

        $this->assertSame(0, $tally['sent']);
        $this->assertGreaterThan(0, $tally['skipped']);
        Notification::assertNothingSent();
        $notice = RentalInspectionDueNotice::where('lease_id', $this->lease->id)->where('milestone', 'overdue')->first();
        $this->assertSame('skipped', $notice->status);
        $this->assertStringContainsString('before reminders began', (string) $notice->detail);
    }

    public function test_a_move_in_that_falls_due_today_is_reminded(): void
    {
        Notification::fake();
        $this->lease->update(['start_date' => '2026-10-07']);

        $this->assertSame(1, $this->runDue('2026-10-07')['sent']);
    }

    public function test_the_due_reminder_switch_turns_it_off_but_not_the_list(): void
    {
        Notification::fake();
        $this->lease->update(['start_date' => '2026-10-07']);
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'raise_due_inspections_enabled' => false]);

        $this->assertSame(0, $this->runDue('2026-10-07')['sent']);
        Notification::assertNothingSent();
        $this->assertNotNull($this->item('in'), 'the list and the Command Centre still show it');
    }

    // ── The Due tab ─────────────────────────────────────────────────────

    public function test_the_due_tab_lists_in_out_and_loaded_dates_together(): void
    {
        $this->lease->update(['move_out_date' => '2026-10-10']);
        $this->date($this->lease, '2026-10-05', ['note' => 'Pre-winter check']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.due'))->assertOk()->getContent();

        $this->assertStringContainsString('14 Marine Drive', $html);
        $this->assertStringContainsString('Move-in', $html);
        $this->assertStringContainsString('Move-out', $html);
        $this->assertStringContainsString('Interim', $html);
        $this->assertStringContainsString('Pre-winter check', $html);
        $this->assertStringContainsString('Overdue', $html);
        $this->assertStringContainsString('Book from this date', $html);
    }

    public function test_the_due_tab_has_real_empty_states(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due'))
            ->assertOk()->assertSee('Nothing is due, and no interim dates have been loaded yet.');

        $this->lease->update(['status' => Lease::STATUS_ACTIVE]);
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due', ['q' => 'zzz-no-such-thing']))
            ->assertOk()->assertSee('Nothing matches this search or filter.');
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due', ['archived' => 1]))
            ->assertOk()->assertSee('No archived dates in this view.');
    }

    public function test_search_filter_and_sort_work(): void
    {
        $p2 = $this->makeProperty($this->agency, $this->branch, $this->agent, '9 Hibiscus Walk, Shelly Beach');
        $l2 = $this->makeLease($p2, ['start_date' => '2026-08-01']);
        $this->inspection($this->lease, 'in', 'completed');
        $this->inspection($l2, 'in', 'completed');
        $a = $this->date($this->lease, '2026-10-15', ['note' => 'alpha note']);
        $b = $this->date($l2, '2026-10-02', ['note' => 'bravo note']);

        $get = fn (array $q) => $this->actingAs($this->agent)->get(route('corex.rental-inspections.due', $q))->assertOk()->getContent();

        $this->assertStringNotContainsString('bravo note', $get(['q' => 'alpha']), 'search on note');
        $this->assertStringContainsString('Hibiscus', $get(['q' => 'Hibiscus']), 'search on property');
        $this->assertStringContainsString('Hibiscus', $get(['q' => $this->agent->name]), 'search on agent name');
        $this->assertStringNotContainsString('alpha note', $get(['status' => 'overdue']), 'status filter');
        $this->assertStringContainsString('bravo note', $get(['status' => 'overdue']));
        $this->assertStringNotContainsString('bravo note', $get(['date_from' => '2026-10-10']), 'date range');
        $this->assertStringNotContainsString('alpha note', $get(['type' => 'in']), 'type filter hides interim');

        $asc = $get(['sort' => 'due', 'direction' => 'asc']);
        $this->assertLessThan(strpos($asc, 'alpha note'), strpos($asc, 'bravo note'), 'soonest first by default');
        $desc = $get(['sort' => 'due', 'direction' => 'desc']);
        $this->assertLessThan(strpos($desc, 'bravo note'), strpos($desc, 'alpha note'));
        $this->get(route('corex.rental-inspections.due', ['sort' => 'nonsense', 'per_page' => 7]))->assertOk();
    }

    public function test_export_and_print_use_the_same_scoped_rows(): void
    {
        $this->date($this->lease, '2026-10-05', ['note' => 'for export']);

        $csv = $this->actingAs($this->agent)->get(route('corex.rental-inspections.due.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Type,Property', $csv);
        $this->assertStringContainsString('14 Marine Drive', $csv);
        $this->assertStringContainsString('for export', $csv);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due.print'))->assertOk()->assertSee('14 Marine Drive');
    }

    // ── Loaded dates: CRUD ──────────────────────────────────────────────

    private function load(array $dates, ?Lease $lease = null, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->agent)->post(route('corex.rental-inspections.planned-dates.store'), array_merge([
            'lease_id' => ($lease ?? $this->lease)->id, 'dates' => $dates,
        ], $extra));
    }

    public function test_an_agent_can_load_one_or_several_dates_at_once(): void
    {
        $this->load(['2026-12-03'], null, ['note' => 'Summer check'])->assertRedirect(route('corex.rental-inspections.due'))->assertSessionHasNoErrors();
        $this->assertSame(1, RentalInspectionPlannedDate::count());
        $d = RentalInspectionPlannedDate::first();
        $this->assertSame('interim', $d->type);
        $this->assertSame('planned', $d->status);
        $this->assertSame($this->property->id, $d->property_id);
        $this->assertSame($this->agency->id, $d->agency_id);
        $this->assertSame($this->agent->id, $d->created_by_user_id);
        $this->assertSame('Summer check', $d->note);

        $this->load(['2027-03-03', '2027-06-03', '', null, '2027-03-03'])->assertSessionHasNoErrors();
        $this->assertSame(3, RentalInspectionPlannedDate::count(), 'blanks and a repeat inside the request are absorbed');
    }

    public function test_a_past_date_is_accepted_and_shows_overdue(): void
    {
        $this->load(['2026-08-01'])->assertSessionHasNoErrors();

        $this->assertSame(1, RentalInspectionPlannedDate::count());
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due'))->assertSee('Overdue');
    }

    public function test_loading_the_same_date_twice_is_refused_with_a_plain_message(): void
    {
        $this->load(['2026-12-03']);
        $response = $this->load(['2026-12-03']);

        $response->assertSessionHasErrors('dates');
        $this->assertStringContainsString('Already loaded for this tenancy', session('errors')->first('dates'));
        $this->assertSame(1, RentalInspectionPlannedDate::count());

        // mixed: the new one is added, the repeat is reported
        $this->load(['2026-12-03', '2027-01-10'])->assertSessionHas('warning')->assertSessionHas('success');
        $this->assertSame(2, RentalInspectionPlannedDate::count());
    }

    public function test_create_archive_load_again_restores_instead_of_colliding(): void
    {
        $this->load(['2026-12-03']);
        $d = RentalInspectionPlannedDate::first();
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.archive', $d->id))->assertRedirect();
        $this->assertSame(0, RentalInspectionPlannedDate::count());

        $this->load(['2026-12-03'])->assertSessionHasNoErrors();

        $this->assertSame(1, RentalInspectionPlannedDate::count());
        $this->assertSame($d->id, RentalInspectionPlannedDate::first()->id, 'the archived row came back (no duplicate-key error)');
        $this->assertSame(1, RentalInspectionPlannedDate::withTrashed()->count());
    }

    public function test_bad_input_is_refused_with_a_message_never_a_500(): void
    {
        $this->load([])->assertSessionHasErrors('dates');
        $this->load(['', ''])->assertSessionHasErrors('dates');
        $this->load(['not-a-date'])->assertSessionHasErrors('dates.0');
        $this->load(array_fill(0, 25, '2026-12-03'))->assertSessionHasErrors('dates');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.store'), ['dates' => ['2026-12-03']])->assertSessionHasErrors('lease_id');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.store'), ['lease_id' => 99999999, 'dates' => ['2026-12-03']])->assertSessionHasErrors('lease_id');
        $this->load(['2026-12-03'], null, ['note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
        $this->assertSame(0, RentalInspectionPlannedDate::count());
    }

    public function test_a_lease_that_is_not_active_or_belongs_to_another_agency_cannot_be_loaded_against(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);
        $this->load(['2026-12-03'])->assertSessionHasErrors('lease_id');

        $this->lease->update(['status' => Lease::STATUS_ACTIVE]);
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $other->id, 'name' => 'CPT']);
        $theirAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        \Illuminate\Support\Facades\Auth::logout();
        $theirLease = $this->makeLease($this->makeProperty($other, $branch, $theirAgent, '3 Beach Road, Sea Point'));

        $this->load(['2026-12-03'], $theirLease)->assertSessionHasErrors('lease_id');
        $this->assertSame(0, RentalInspectionPlannedDate::withoutGlobalScopes()->count());
    }

    public function test_move_edit_note_and_clash(): void
    {
        $a = $this->date($this->lease, '2026-12-03');
        $b = $this->date($this->lease, '2027-01-10');

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $a->id), ['planned_on' => '2026-12-10', 'note' => 'moved'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-12-10', $a->fresh()->planned_on->toDateString());
        $this->assertSame('moved', $a->fresh()->note);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $a->id), ['planned_on' => '2027-01-10'])
            ->assertSessionHasErrors('planned_on');
        $this->assertSame('2026-12-10', $a->fresh()->planned_on->toDateString(), 'a clash with another loaded date is refused');

        // note only, no date posted
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $b->id), ['note' => ''])->assertSessionHasNoErrors();
        $this->assertSame('2027-01-10', $b->fresh()->planned_on->toDateString());
    }

    public function test_a_booked_date_cannot_be_moved_or_skipped_only_its_note_edited(): void
    {
        $a = $this->date($this->lease, '2026-12-03', ['status' => 'booked']);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $a->id), ['planned_on' => '2026-12-10'])->assertSessionHasErrors('planned_on');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.skip', $a->id), ['skipped_reason' => 'x'])->assertSessionHasErrors('skipped_reason');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $a->id), ['note' => 'tenant prefers morning'])->assertSessionHasNoErrors();

        $a = $a->fresh();
        $this->assertSame('2026-12-03', $a->planned_on->toDateString());
        $this->assertSame('booked', $a->status);
        $this->assertSame('tenant prefers morning', $a->note);
    }

    public function test_skip_needs_a_reason_and_can_be_reopened(): void
    {
        $a = $this->date($this->lease, '2026-12-03');

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.skip', $a->id), ['skipped_reason' => ''])->assertSessionHasErrors('skipped_reason');
        $this->assertSame('planned', $a->fresh()->status);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.skip', $a->id), ['skipped_reason' => '  Tenant overseas  '])->assertSessionHasNoErrors();
        $this->assertSame('skipped', $a->fresh()->status);
        $this->assertSame('Tenant overseas', $a->fresh()->skipped_reason);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.reopen', $a->id))->assertRedirect();
        $this->assertSame('planned', $a->fresh()->status);
        $this->assertNull($a->fresh()->skipped_reason);
    }

    public function test_archive_and_restore_with_who_and_when_and_an_archived_date_is_read_only(): void
    {
        $a = $this->date($this->lease, '2026-12-03');

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.archive', $a->id))->assertRedirect();
        $a = RentalInspectionPlannedDate::withTrashed()->find($a->id);
        $this->assertTrue($a->trashed());
        $this->assertSame($this->agent->id, $a->archived_by_user_id);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.update', $a->id), ['note' => 'x'])->assertStatus(422);
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.skip', $a->id), ['skipped_reason' => 'x'])->assertStatus(422);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.due', ['archived' => 1]))->assertSee('Archived by')->assertSee('Restore');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.restore', $a->id))->assertRedirect();
        $this->assertFalse(RentalInspectionPlannedDate::withTrashed()->find($a->id)->trashed());
    }

    public function test_restore_is_refused_when_a_live_twin_has_been_loaded_since(): void
    {
        $a = $this->date($this->lease, '2026-12-03');
        $a->delete();
        $this->date($this->lease, '2026-12-03');

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.planned-dates.restore', $a->id))->assertSessionHasErrors('dates');
        $this->assertTrue(RentalInspectionPlannedDate::withTrashed()->find($a->id)->trashed());
    }

    // ── Scoping ─────────────────────────────────────────────────────────

    private function ownScopeAgent(): User
    {
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach (['rental_inspections.view', 'rental_inspections.create', 'rental_inspections.manage_planned_dates'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    public function test_an_own_scope_user_cannot_see_or_reach_a_colleagues_date_by_url(): void
    {
        $mine = $this->ownScopeAgent();
        $theirs = $this->date($this->lease, '2026-12-03', ['note' => 'colleague only']);

        $html = $this->actingAs($mine)->get(route('corex.rental-inspections.due'))->assertOk()->getContent();
        $this->assertStringNotContainsString('colleague only', $html);
        $this->assertStringNotContainsString('14 Marine Drive', $html, 'nor the colleague\'s In/Out items');

        foreach (['update', 'skip', 'reopen', 'archive', 'restore'] as $action) {
            $this->actingAs($mine)->post(route("corex.rental-inspections.planned-dates.{$action}", $theirs->id), ['note' => 'x', 'skipped_reason' => 'x'])
                ->assertNotFound();
        }
        $this->assertSame('planned', $theirs->fresh()->status);
        $this->assertNull($theirs->fresh()->deleted_at);

        // and cannot load a date against a colleague's tenancy
        $this->actingAs($mine)->post(route('corex.rental-inspections.planned-dates.store'), ['lease_id' => $this->lease->id, 'dates' => ['2027-02-02']])
            ->assertSessionHasErrors('lease_id');
    }

    public function test_an_own_scope_user_sees_dates_on_their_own_property_and_ones_they_loaded(): void
    {
        $mine = $this->ownScopeAgent();
        $myProp = $this->makeProperty($this->agency, $this->branch, $mine, '7 Palm Close, Ramsgate');
        $myLease = $this->makeLease($myProp, ['created_by_user_id' => $mine->id]);
        $this->date($myLease, '2026-12-03', ['note' => 'on my property', 'created_by_user_id' => $this->agent->id]);
        $this->date($this->lease, '2026-12-04', ['note' => 'loaded by me', 'created_by_user_id' => $mine->id]);

        $html = $this->actingAs($mine)->get(route('corex.rental-inspections.due'))->assertOk()->getContent();

        $this->assertStringContainsString('on my property', $html);
        $this->assertStringContainsString('loaded by me', $html);
    }

    public function test_another_agencys_date_is_a_404_by_direct_url(): void
    {
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $other->id, 'name' => 'CPT']);
        $theirAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        \Illuminate\Support\Facades\Auth::logout();
        $theirLease = $this->makeLease($this->makeProperty($other, $branch, $theirAgent, '3 Beach Road, Sea Point'));
        $theirs = RentalInspectionPlannedDate::create([
            'agency_id' => $other->id, 'lease_id' => $theirLease->id, 'property_id' => $theirLease->property_id,
            'type' => 'interim', 'planned_on' => '2026-12-03', 'status' => 'planned',
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-inspections.planned-dates.archive', $theirs->id))->assertNotFound();
        $this->assertNull(RentalInspectionPlannedDate::withoutGlobalScopes()->find($theirs->id)->deleted_at);
        $this->actingAs($this->admin)->get(route('corex.rental-inspections.due'))->assertOk()->assertDontSee('Sea Point');
    }

    public function test_the_new_routes_sit_behind_the_right_permission_keys(): void
    {
        $mw = fn (string $name) => \Illuminate\Support\Facades\Route::getRoutes()->getByName($name)->gatherMiddleware();

        $this->assertContains('permission:rental_inspections.view', $mw('corex.rental-inspections.due'));
        foreach (['store', 'update', 'skip', 'reopen', 'archive', 'restore'] as $a) {
            $this->assertContains('permission:rental_inspections.manage_planned_dates', $mw("corex.rental-inspections.planned-dates.{$a}"));
        }
        $this->assertContains('permission:rental_inspections.manage_settings', $mw('corex.settings.rental-inspections.due-dates'));

        $keys = collect(config('corex-permissions.permissions'))->pluck('key');
        $this->assertTrue($keys->contains('rental_inspections.manage_planned_dates'));
        foreach (['branch_manager', 'agent'] as $role) {
            $this->assertContains('rental_inspections.manage_planned_dates', config("corex-permissions.role_defaults.{$role}.include"), "{$role} gets it wherever .create is");
            $this->assertContains('rental_inspections.create', config("corex-permissions.role_defaults.{$role}.include"));
        }
    }

    // ── Booking an inspection from a loaded date ────────────────────────

    public function test_the_create_form_opens_prefilled_from_a_loaded_date(): void
    {
        $d = $this->date($this->lease, '2026-12-03');
        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.create', [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'type' => 'interim',
            'scheduled_for' => '2026-12-03', 'planned_date_id' => $d->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('name="planned_date_id" value="' . $d->id . '"', $html);
        $this->assertStringContainsString('value="2026-12-03"', $html);
        $this->assertMatchesRegularExpression('/<option value="interim"\s+selected/', $html);
    }

    private function book(RentalInspectionPlannedDate $d, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.rental-inspections.store'), array_merge([
            'property_id' => $this->property->id, 'type' => 'interim', 'intent' => 'schedule',
            'scheduled_for' => '2026-12-03', 'planned_date_id' => $d->id,
        ], $over));
    }

    public function test_booking_from_a_loaded_date_creates_an_interim_inspection_and_links_it(): void
    {
        $d = $this->date($this->lease, '2026-12-03');

        $this->book($d)->assertSessionHasNoErrors();

        $i = RentalInspection::where('type', 'interim')->first();
        $this->assertNotNull($i);
        $this->assertSame($this->lease->id, $i->lease_id);
        $d = $d->fresh();
        $this->assertSame('booked', $d->status);
        $this->assertSame($i->id, $d->rental_inspection_id);
    }

    public function test_a_forged_or_mismatched_planned_date_id_is_ignored_the_inspection_still_books(): void
    {
        $other = $this->makeLease($this->makeProperty($this->agency, $this->branch, $this->agent, '5 Other Street'));
        $wrongLease = $this->date($other, '2026-12-03');
        $wrongType = $this->date($this->lease, '2026-12-05');

        $this->book($wrongLease)->assertSessionHasNoErrors();
        $this->assertSame('planned', $wrongLease->fresh()->status, 'a date on another lease is not linked');

        RentalInspection::query()->forceDelete();
        $this->book($wrongType, ['type' => 'ad_hoc'])->assertSessionHasNoErrors();
        $this->assertSame('planned', $wrongType->fresh()->status, 'a type that does not match is not linked');

        RentalInspection::query()->forceDelete();
        $this->book($wrongType, ['planned_date_id' => 99999999])->assertSessionHasNoErrors();
        RentalInspection::query()->forceDelete();
        $this->book($wrongType, ['planned_date_id' => 'abc'])->assertSessionHasNoErrors();
        $this->assertSame(1, RentalInspection::count(), 'each forged id still booked the inspection itself');
        $this->assertSame('planned', $wrongType->fresh()->status);
    }

    public function test_the_date_follows_the_inspection_complete_cancel_archive(): void
    {
        $a = $this->date($this->lease, '2026-12-03', ['status' => 'booked']);
        $b = $this->date($this->lease, '2027-01-03', ['status' => 'booked']);
        $c = $this->date($this->lease, '2027-02-03', ['status' => 'booked']);
        $ia = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'interim', 'created_by_user_id' => $this->agent->id]);
        $ib = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'interim', 'created_by_user_id' => $this->agent->id]);
        $ic = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'interim', 'created_by_user_id' => $this->agent->id]);
        $a->update(['rental_inspection_id' => $ia->id]);
        $b->update(['rental_inspection_id' => $ib->id]);
        $c->update(['rental_inspection_id' => $ic->id]);

        $ia->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $ib->forceFill(['status' => RentalInspection::STATUS_CANCELLED])->save();
        $ic->delete();

        $this->assertSame('done', $a->fresh()->status);
        $this->assertSame('planned', $b->fresh()->status);
        $this->assertNull($b->fresh()->rental_inspection_id, 'a cancelled booking releases the date, to be booked or reminded again');
        $this->assertSame('planned', $c->fresh()->status, 'an archived inspection no longer stands');
        $this->assertNull($c->fresh()->rental_inspection_id);
    }

    // ── The interim type itself ─────────────────────────────────────────

    public function test_an_interim_inspection_is_signed_by_all_parties_like_in_and_out_but_ad_hoc_is_not(): void
    {
        $interim = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'interim', 'created_by_user_id' => $this->agent->id]);
        try {
            $interim->markCompleted();
            $this->fail('an interim inspection must not complete without the parties\' signatures');
        } catch (\LogicException $e) {
            $this->assertNotSame(RentalInspection::STATUS_COMPLETED, $interim->fresh()->status);
        }
        $interim->startAwaitingSignature();
        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $interim->fresh()->status, 'interim has the signing window');

        $adHoc = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'created_by_user_id' => $this->agent->id]);
        $adHoc->markCompleted();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $adHoc->fresh()->status, 'ad-hoc stays as it was (not covered by the ruling)');
    }

    public function test_an_interim_can_follow_another_inspection_in_the_chain(): void
    {
        $in = $this->inspection($this->lease, 'in', 'completed');

        $next = RentalInspection::startNext($in, RentalInspection::TYPE_INTERIM, $this->agent);

        $this->assertSame('interim', $next->type);
        $this->assertSame($in->id, $next->previous_inspection_id);
        $this->expectException(\LogicException::class);
        RentalInspection::startNext($next, 'nonsense', $this->agent);
    }

    public function test_the_start_form_and_list_filter_offer_interim_and_the_old_types_still_work(): void
    {
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.create'))->assertOk()->assertSee('Interim — a planned mid-tenancy inspection')->assertSee('Ad-hoc');
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.index'))->assertOk()->assertSee('Interim')->assertSee(route('corex.rental-inspections.due'));

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'in', 'intent' => 'schedule', 'scheduled_for' => '2026-12-03'])->assertSessionHasNoErrors();
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'bogus'])->assertSessionHasErrors('type');
    }

    // ── Settings + wizard ───────────────────────────────────────────────

    public function test_the_three_settings_default_save_and_validate(): void
    {
        $this->assertSame(14, RentalInspectionSetting::plannedDateLeadDaysFor($this->agency->id));
        $this->assertSame(7, RentalInspectionSetting::outDueLeadDaysFor($this->agency->id));
        $this->assertTrue(RentalInspectionSetting::raiseDueInspectionsEnabledFor($this->agency->id));
        $this->assertSame(14, RentalInspectionSetting::plannedDateLeadDaysFor(null));

        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.due-dates'), [
            'planned_date_lead_days' => '21', 'out_due_lead_days' => '0', 'raise_due_inspections_enabled' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertSame(21, RentalInspectionSetting::plannedDateLeadDaysFor($this->agency->id));
        $this->assertSame(0, RentalInspectionSetting::outDueLeadDaysFor($this->agency->id), '0 is a real value, not "unset"');
        $this->assertFalse(RentalInspectionSetting::raiseDueInspectionsEnabledFor($this->agency->id));

        foreach (['planned_date_lead_days' => '91', 'out_due_lead_days' => '-1'] as $field => $bad) {
            $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.due-dates'), [$field => $bad])->assertSessionHasErrors($field);
        }
        $this->assertSame(21, RentalInspectionSetting::plannedDateLeadDaysFor($this->agency->id));

        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.due-dates'), [])->assertSessionHasErrors('due_dates');
    }

    public function test_a_wizard_post_of_a_subset_never_resets_the_other_settings(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'planned_date_lead_days' => 30, 'out_due_lead_days' => 3, 'raise_due_inspections_enabled' => false]);

        // the wizard step renders only the toggle for this saver's call
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.due-dates'), ['raise_due_inspections_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(RentalInspectionSetting::raiseDueInspectionsEnabledFor($this->agency->id));
        $this->assertSame(30, RentalInspectionSetting::plannedDateLeadDaysFor($this->agency->id));
        $this->assertSame(3, RentalInspectionSetting::outDueLeadDaysFor($this->agency->id));

        // and the reverse: only a number posted never flips the toggle
        $this->actingAs($this->admin)->post(route('corex.settings.rental-inspections.due-dates'), ['out_due_lead_days' => '5'])->assertSessionHasNoErrors();
        $this->assertTrue(RentalInspectionSetting::raiseDueInspectionsEnabledFor($this->agency->id));
        $this->assertSame(5, RentalInspectionSetting::outDueLeadDaysFor($this->agency->id));
    }

    public function test_the_settings_page_renders_the_new_section(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'planned_date_lead_days' => 21]);

        $html = $this->actingAs($this->admin)->get(route('corex.settings.rental-inspections.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Due inspections and reminders', $html);
        $this->assertStringContainsString('name="planned_date_lead_days"', $html);
        $this->assertStringContainsString('value="21"', $html);
        $form = substr($html, strpos($html, 'data-qa="due-dates-form"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertStringNotContainsString('interval', strtolower($form), 'no interim-interval setting exists');
        $this->assertStringNotContainsString('every', strtolower($form), 'nothing here schedules an interim automatically');
    }

    public function test_every_new_setting_reaches_the_wizard_with_explain_and_affects_and_a_saver_and_a_current_value(): void
    {
        $controls = collect(config('agency-onboarding-copy.leases.controls'))->keyBy('key');
        foreach (['raise_due_inspections_enabled', 'planned_date_lead_days', 'out_due_lead_days'] as $key) {
            $this->assertTrue($controls->has($key), "{$key} must be a wizard control");
            $c = $controls[$key];
            $this->assertSame('rental_inspections', $c['source']);
            $this->assertNotEmpty($c['explain']);
            $this->assertNotEmpty($c['affects']);
        }
        $this->assertTrue(collect(config('agency-onboarding-copy.leases.savers'))->contains(
            fn ($s) => $s['controller'] === \App\Http\Controllers\CoreX\RentalInspectionSettingsController::class && $s['method'] === 'updateDueDates'
        ));
        $this->assertSame(14, $controls['planned_date_lead_days']['default']);
        $this->assertSame(7, $controls['out_due_lead_days']['default']);
        $this->assertSame(1, $controls['raise_due_inspections_enabled']['default']);
    }

    // ── Command Centre ──────────────────────────────────────────────────

    private function queue(?User $as = null, string $scope = 'all')
    {
        $user = $as ?? $this->admin;
        $this->actingAs($user);

        return app(RentalCommandCentreService::class)->queueItems($user, $scope);
    }

    public function test_the_command_centre_queue_carries_in_out_and_loaded_interim_dates_but_never_a_future_one(): void
    {
        $this->lease->update(['start_date' => '2026-09-01', 'move_out_date' => '2026-10-10']); // move-in overdue; move-out due (lead 7)
        $this->date($this->lease, '2026-10-12');                                                  // within the 14-day lead
        $far = $this->makeLease($this->makeProperty($this->agency, $this->branch, $this->agent, '8 Far Away Road'), ['start_date' => '2026-11-30', 'end_date' => '2027-11-29', 'move_out_date' => '2027-11-29']);
        $this->date($far, '2027-03-03');                                                          // far future

        $items = $this->queue();

        $this->assertTrue($items->contains(fn ($i) => $i['type'] === 'start_inspection' && $i['lease']->id === $this->lease->id));
        $this->assertTrue($items->contains(fn ($i) => $i['type'] === 'start_out_inspection' && $i['lease']->id === $this->lease->id));
        $this->assertTrue($items->contains(fn ($i) => $i['type'] === 'interim_inspection_due' && $i['lease']->id === $this->lease->id));
        $this->assertFalse($items->contains(fn ($i) => in_array($i['type'], ['start_inspection', 'start_out_inspection', 'interim_inspection_due'], true) && ($i['lease']->id ?? null) === $far->id),
            'a future in/out/interim never reaches the queue');
    }

    public function test_a_skipped_archived_or_ended_date_is_not_in_the_queue(): void
    {
        $this->inspection($this->lease, 'in', 'completed');
        $this->date($this->lease, '2026-10-07', ['status' => 'skipped', 'skipped_reason' => 'x']);
        $this->date($this->lease, '2026-10-06')->delete();
        $ended = $this->makeLease($this->makeProperty($this->agency, $this->branch, $this->agent, '1 Ended Lane'), ['status' => Lease::STATUS_EXPIRED]);
        $this->date($ended, '2026-10-07');

        $this->assertFalse($this->queue()->contains(fn ($i) => $i['type'] === 'interim_inspection_due'));
    }

    public function test_the_queue_respects_the_viewers_scope_at_the_query_layer(): void
    {
        $mine = $this->ownScopeAgent();
        $this->date($this->lease, '2026-10-07');   // a colleague's property

        // the controller clamps the requested scope to the user's ceiling and hands the service 'own'
        $items = $this->queue($mine, 'own');

        $this->assertFalse($items->contains(fn ($i) => in_array($i['type'], ['start_inspection', 'start_out_inspection', 'interim_inspection_due'], true)), 'a colleague\'s inspections are not in an own-scope queue');
        $this->assertTrue($this->queue($this->admin, 'all')->contains(fn ($i) => $i['type'] === 'interim_inspection_due'), 'and they ARE there for an agency-wide viewer');
    }

    public function test_a_renewal_does_not_re_prompt_the_move_in_in_the_queue(): void
    {
        $old = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED, 'start_date' => '2025-09-01', 'end_date' => '2026-08-31']);
        $this->inspection($old, 'in', 'completed');
        $this->lease->update(['previous_lease_id' => $old->id]);

        $this->assertFalse($this->queue()->contains(fn ($i) => $i['type'] === 'start_inspection' && $i['lease']->id === $this->lease->id));
    }

    public function test_the_inspections_due_tile_counts_a_property_with_a_loaded_date_in_its_window_only(): void
    {
        $this->inspection($this->lease, 'in', 'completed');
        $this->lease->update(['end_date' => null, 'is_month_to_month' => true]);
        $service = app(RentalCommandCentreService::class);
        $this->actingAs($this->admin);

        $before = $service->tileCounts($this->admin, 'all')['inspections_due'];

        $this->date($this->lease, '2027-05-05');  // far away: not counted
        $this->assertSame($before, $service->tileCounts($this->admin, 'all')['inspections_due']);

        $this->date($this->lease, '2026-10-15');  // inside the 14-day lead: counted
        $after = $service->tileCounts($this->admin, 'all')['inspections_due'];
        $this->assertSame($before + 1, $after);

        // and clicking the tile lists exactly those properties
        $listed = $service->applyTile($service->derivedPropertyQuery($this->admin, 'all'), $this->admin, 'all', 'inspections_due')->get();
        $this->assertSame($after, $listed->count());
        $this->assertTrue($listed->contains('id', $this->property->id));
    }
}
