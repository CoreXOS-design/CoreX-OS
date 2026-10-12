<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\NotificationDropLog;
use App\Models\User;
use App\Models\UserAlertDecision;
use App\Services\CommandCenter\AlertSwitchService;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-451 — "all alerts switched off" is allowed but never unnoticed.
 *
 * Input paths proven here: all-off user gets the bar (and an on-user does not); "Keep them off" writes an accepted_off
 * record and hides the bar; the bar returns once the settings are saved again; "Turn alerts on" switches in-app on,
 * writes turned_on, and alerts then arrive; an agency-locked user is refused (and not offered the button); a dropped
 * alert writes ONE ledger row (not one per retry) while delivery is otherwise unchanged; the endpoint tells the popup
 * whether in-app is on; the agency list is agency / branch / own scoped in the query and a direct URL outside scope is
 * 404; the decision record cannot be edited; the poll interval is clamped (blank, too low, too high, malformed).
 */
final class AlertSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake(); // no test run ever mails a real address

        $this->agency = Agency::create(['name' => 'Alert Agency', 'slug' => 'alert-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate', 'code' => 'MG-' . $this->agency->id, 'is_active' => true]);
        $this->otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach', 'code' => 'SB-' . $this->agency->id, 'is_active' => true]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function user(string $role = 'agent', ?Branch $branch = null, ?Agency $agency = null, array $switches = ['in_app' => true, 'email' => true, 'push' => true]): User
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
        $this->setSwitches($user, $switches);

        return $user;
    }

    private function setSwitches(User $user, array $switches): void
    {
        $row = UserDashboardSetting::firstOrCreate(['user_id' => $user->id], UserDashboardSetting::defaults());
        $row->forceFill([
            'notify_in_app' => $switches['in_app'],
            'notify_email' => $switches['email'],
            'notify_push' => $switches['push'],
        ])->save();
    }

    private function allOff(string $role = 'agent', ?Branch $branch = null, ?Agency $agency = null): User
    {
        return $this->user($role, $branch, $agency, ['in_app' => false, 'email' => false, 'push' => false]);
    }

    private function eventType(): NotificationEventType
    {
        return NotificationEventType::updateOrCreate(['key' => 'at451.test_alert'], [
            'pillar' => 'property', 'group_label' => 'Test', 'label' => 'AT-451 test alert', 'description' => 'Test',
            'default_enabled' => true, 'threshold_unit' => 'none', 'supports_in_app' => true, 'supports_email' => true,
            'supports_push' => false, 'is_adapter' => false, 'sort_order' => 1,
        ]);
    }

    private function fire(User $user, ?\DateTimeInterface $threshold = null): bool
    {
        $this->eventType();

        return app(NotificationDispatcher::class)->fire($user, 'at451.test_alert', $this->agency, [
            'title' => 'Fault reported - Unit 33', 'body' => 'Roof leak', 'action_url' => url('/corex/rental-fault-reports/90'),
            'threshold_hit_at' => $threshold ?? now(),
        ]);
    }

    private function bar(User $user): ?array
    {
        return app(AlertSwitchService::class)->barStateFor($user);
    }

    // ── B1: the warning bar ────────────────────────────────────────────────────────────────

    public function test_a_user_with_every_alert_off_gets_the_bar_and_a_user_with_one_on_does_not(): void
    {
        $this->assertNotNull($this->bar($this->allOff()));
        $this->assertNull($this->bar($this->user('agent', null, null, ['in_app' => false, 'email' => false, 'push' => true])), 'push alone is still an alert channel');
        $this->assertNull($this->bar($this->user('agent', null, null, ['in_app' => true, 'email' => false, 'push' => false])));
    }

    public function test_the_bar_is_in_the_staff_layout_as_one_line_with_both_buttons(): void
    {
        $user = $this->allOff('admin');

        $html = $this->actingAs($user)->get(route('corex.alerts-off.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-alerts-off-bar', $html);
        $this->assertStringContainsString('Your alerts are switched off - you will not be told about new faults, leads or approvals.', $html);
        $this->assertStringContainsString('Turn alerts on', $html);
        $this->assertStringContainsString('Keep them off', $html);
    }

    public function test_no_bar_for_a_user_with_alerts_on(): void
    {
        $html = $this->actingAs($this->user('admin'))->get(route('corex.alerts-off.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-alerts-off-bar', $html);
    }

    public function test_keep_them_off_records_the_decision_and_hides_the_bar(): void
    {
        $user = $this->allOff();

        $this->actingAs($user)->post(route('corex.alerts.bar.keep-off'))->assertRedirect();

        $row = UserAlertDecision::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('accepted_off', $row->decision);
        $this->assertSame($user->id, (int) $row->decided_by_user_id, 'the user decided for themselves');
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertFalse($row->switch_in_app);
        $this->assertFalse($row->switch_email);
        $this->assertFalse($row->switch_push);
        $this->assertNull($this->bar($user->fresh()), 'accepted: the bar is hidden');
    }

    public function test_the_bar_returns_once_the_settings_are_saved_again_and_everything_is_still_off(): void
    {
        $user = $this->allOff();
        $this->actingAs($user)->post(route('corex.alerts.bar.keep-off'));
        $this->assertNull($this->bar($user));

        // Turned on, then off again later: a settings save that changes the version the acceptance was made against.
        $this->travel(5)->seconds();
        $this->setSwitches($user, ['in_app' => true, 'email' => false, 'push' => false]);
        $this->assertNull($this->bar($user), 'on again: nothing to warn about');
        $this->travel(5)->seconds();
        $this->setSwitches($user, ['in_app' => false, 'email' => false, 'push' => false]);

        $this->assertNotNull($this->bar($user), 'off again after being on: the old acceptance no longer covers it');
    }

    public function test_turn_alerts_on_switches_in_app_on_records_it_and_alerts_then_arrive(): void
    {
        $user = $this->allOff();
        $this->assertFalse($this->fire($user), 'off stays off: the dispatcher keeps dropping');

        $this->actingAs($user)->post(route('corex.alerts.bar.turn-on'))->assertRedirect();

        $fresh = $user->fresh();
        $state = app(NotificationPreferenceService::class)->masterSwitchState($fresh);
        $this->assertTrue($state['in_app']);
        $this->assertFalse($state['email'], 'only in-app is switched on — email and push stay as they were');
        $this->assertFalse($state['push']);

        $row = UserAlertDecision::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('turned_on', $row->decision);
        $this->assertTrue($row->switch_in_app, 'the switch state as it stood once the decision took effect');
        $this->assertNull($this->bar($fresh));

        $this->assertTrue($this->fire($fresh, now()->addMinute()), 'an alert now reaches them');
        $this->assertSame(1, $fresh->notifications()->count());
    }

    public function test_when_the_agency_sets_the_switches_the_bar_offers_only_keep_off_and_turn_on_is_refused(): void
    {
        $this->agency->update(['dashboard_settings_mode' => 'agency']);
        AgencyDashboardSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], array_merge(
            UserDashboardSetting::defaults(),
            ['notify_in_app' => false, 'notify_email' => false]
        ));
        $user = $this->allOff();

        $bar = $this->bar($user);
        $this->assertNotNull($bar);
        $this->assertTrue($bar['locked']);

        $html = $this->actingAs($user)->get(route('corex.alerts-off.index'))->getContent();
        $this->assertStringNotContainsString('Turn alerts on', $html);
        $this->assertStringContainsString('Keep them off', $html);

        $this->actingAs($user)->post(route('corex.alerts.bar.turn-on'))->assertSessionHas('error');
        $this->assertSame(0, UserAlertDecision::withoutGlobalScopes()->count(), 'a refused turn-on records nothing');
    }

    public function test_keep_off_when_alerts_are_already_on_is_a_plain_message_not_a_record(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('corex.alerts.bar.keep-off'))->assertSessionHas('error');

        $this->assertSame(0, UserAlertDecision::withoutGlobalScopes()->count());
    }

    // ── B4: a dropped alert is never silent ────────────────────────────────────────────────

    public function test_a_dropped_alert_writes_one_ledger_row_and_delivery_is_unchanged(): void
    {
        $user = $this->allOff();
        $type = $this->eventType();
        $threshold = now();

        $this->assertFalse($this->fire($user, $threshold));
        $this->assertFalse($this->fire($user, $threshold), 'the same fact offered again');

        $this->assertSame(0, $user->notifications()->count(), 'nothing delivered');
        $row = NotificationDropLog::where('user_id', $user->id)->sole();
        $this->assertSame('at451.test_alert', $row->event_key);
        $this->assertSame($type->id, (int) $row->notification_event_type_id);
        $this->assertSame('all channels off', $row->reason);
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame($this->agency->getKey(), (int) $row->subject_id);
        $this->assertSame(0, \App\Models\CommandCenter\NotificationDispatchLog::where('user_id', $user->id)->count(), 'the "already told them" ledger is untouched, so the alert can still arrive later');
    }

    public function test_a_different_alert_for_the_same_user_is_its_own_row_and_a_user_with_alerts_on_writes_none(): void
    {
        $off = $this->allOff();
        $on = $this->user();

        $this->fire($off, now());
        $this->fire($off, now()->addHour());
        $this->assertTrue($this->fire($on));

        $this->assertSame(2, NotificationDropLog::where('user_id', $off->id)->count(), 'a new threshold is a new fact');
        $this->assertSame(0, NotificationDropLog::where('user_id', $on->id)->count());
    }

    // ── A: the popup component (the feed itself is tested in AlertFeedTest) ──────────────────

    public function test_the_popup_component_carries_the_agency_interval_clamped_and_the_users_own_storage_key(): void
    {
        $user = $this->user();
        $render = fn () => view('components.alert-popups')->render();

        $this->actingAs($user);
        $this->assertStringContainsString('Math.max(15, 30)', $render(), 'default 30 seconds');
        $this->assertStringContainsString('corex_alerts_seen_' . $user->id, $render());

        AgencyDashboardSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['alert_poll_seconds' => 45]);
        $this->assertStringContainsString('Math.max(15, 45)', $render());

        AgencyDashboardSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->update(['alert_poll_seconds' => 3]);
        $this->assertStringContainsString('Math.max(15, 15)', $render(), 'a stored value below 15 is read as 15');
    }

    public function test_the_popup_is_in_the_staff_layout_and_not_in_a_public_page(): void
    {
        $staff = $this->actingAs($this->user('admin'))->get(route('corex.alerts-off.index'))->getContent();
        $this->assertStringContainsString('__corexAlertPopups', $staff);

        auth()->logout();
        $public = view('layouts.public')->render();
        $this->assertStringNotContainsString('__corexAlertPopups', $public);
    }

    // ── The poll-interval setting (saved through the existing dashboard-settings saver) ─────

    public function test_poll_seconds_are_clamped_and_saved_through_the_dashboard_saver(): void
    {
        $this->assertSame(30, AlertSwitchService::clampPollSeconds(null));
        $this->assertSame(30, AlertSwitchService::clampPollSeconds(''));
        $this->assertSame(30, AlertSwitchService::clampPollSeconds('abc'));
        $this->assertSame(15, AlertSwitchService::clampPollSeconds(1));
        $this->assertSame(600, AlertSwitchService::clampPollSeconds(99999));
        $this->assertSame(45, AlertSwitchService::clampPollSeconds('45'));

        $admin = $this->user('admin');
        $save = fn (array $body) => $this->actingAs($admin)->put(route('corex.settings.dashboard.agency'), $body);
        $stored = fn () => AgencyDashboardSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('alert_poll_seconds');

        $save(['alert_poll_seconds' => '45'])->assertRedirect();
        $this->assertSame(45, $stored());

        $save(['alert_poll_seconds' => '10'])->assertSessionHasErrors('alert_poll_seconds');
        $save(['alert_poll_seconds' => 'often'])->assertSessionHasErrors('alert_poll_seconds');
        $this->assertSame(45, $stored(), 'a rejected value changes nothing');

        $save(['digest_time' => '09:00'])->assertRedirect();
        $this->assertSame(45, $stored(), 'a form that never showed the field leaves it alone');

        $save(['alert_poll_seconds' => ''])->assertRedirect();
        $this->assertNull($stored(), 'blank = back to the default');
        $this->assertSame(30, app(AlertSwitchService::class)->pollSecondsFor($this->agency->id));
    }

    // ── B2: the agency list — scoping, decisions, history ──────────────────────────────────

    public function test_the_list_counts_and_shows_only_this_agencys_all_off_users(): void
    {
        $admin = $this->user('admin');
        $mine = $this->allOff('agent', $this->branch);
        $sheBranch = $this->allOff('agent', $this->otherBranch);
        $this->user(); // alerts on — not listed
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Cape Town', 'code' => 'CT-' . $otherAgency->id, 'is_active' => true]);
        $foreign = $this->allOff('agent', $otherBranch, $otherAgency);

        $rows = app(AlertSwitchService::class)->allOffRows($admin);

        $this->assertEqualsCanonicalizing([$mine->id, $sheBranch->id], $rows->map(fn ($r) => $r['user']->id)->all());
        $this->assertNotContains($foreign->id, $rows->map(fn ($r) => $r['user']->id)->all(), 'another agency is never visible');
        $this->assertSame(2, app(AlertSwitchService::class)->allOffCount($admin));

        $html = $this->actingAs($admin)->get(route('corex.alerts-off.index'))->assertOk()->getContent();
        $this->assertStringContainsString($mine->email, $html);
        $this->assertStringContainsString('Margate', $html);
        $this->assertStringNotContainsString($foreign->email, $html);
    }

    public function test_a_branch_manager_sees_only_their_branch_and_a_direct_url_outside_it_is_404(): void
    {
        $manager = $this->user('branch_manager', $this->branch);
        $inBranch = $this->allOff('agent', $this->branch);
        $elsewhere = $this->allOff('agent', $this->otherBranch);

        $ids = app(AlertSwitchService::class)->allOffRows($manager)->map(fn ($r) => $r['user']->id)->all();
        $this->assertSame([$inBranch->id], $ids);

        $this->actingAs($manager)->post(route('corex.alerts-off.accept', $elsewhere->id))->assertNotFound();
        $this->actingAs($manager)->post(route('corex.alerts-off.turn-on', $elsewhere->id))->assertNotFound();
        $this->assertSame(0, UserAlertDecision::withoutGlobalScopes()->count());
        $this->assertFalse(app(NotificationPreferenceService::class)->masterSwitchState($elsewhere->fresh())['in_app'], 'nothing changed for the out-of-scope user');
    }

    public function test_a_user_in_another_agency_cannot_be_reached_by_id(): void
    {
        $admin = $this->user('admin');
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Cape Town', 'code' => 'CT-' . $otherAgency->id, 'is_active' => true]);
        $foreign = $this->allOff('agent', $otherBranch, $otherAgency);

        $this->actingAs($admin)->post(route('corex.alerts-off.turn-on', $foreign->id))->assertNotFound();
        $this->assertSame(0, UserAlertDecision::withoutGlobalScopes()->count());
    }

    public function test_the_list_needs_the_manage_users_permission(): void
    {
        $this->seedLockedDownRoles();

        $this->actingAs($this->allOff('agent'))->get(route('corex.alerts-off.index'))->assertForbidden();
    }

    public function test_an_admin_accepts_then_turns_on_for_a_user_and_the_history_shows_both(): void
    {
        $admin = $this->user('admin');
        $target = $this->allOff('agent');

        $this->actingAs($admin)->post(route('corex.alerts-off.accept', $target->id))->assertRedirect();

        $row = app(AlertSwitchService::class)->allOffRows($admin)->sole();
        $this->assertTrue($row['accepted']);
        $this->assertFalse($row['accepted_by_self'], 'an administrator accepted it');
        $this->assertSame($admin->name, $row['accepted_by_name']);

        $html = $this->actingAs($admin)->get(route('corex.alerts-off.index'))->getContent();
        $this->assertStringContainsString('Accepted', $html);
        $this->assertStringContainsString('an administrator', $html);

        $this->actingAs($admin)->post(route('corex.alerts-off.turn-on', $target->id))->assertRedirect();

        $this->assertSame(0, app(AlertSwitchService::class)->allOffCount($admin), 'in-app is on, so they are no longer listed');
        $history = UserAlertDecision::withoutGlobalScopes()->where('user_id', $target->id)->orderBy('id')->get();
        $this->assertSame(['accepted_off', 'turned_on'], $history->pluck('decision')->all());
        $this->assertSame([$admin->id, $admin->id], $history->pluck('decided_by_user_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_the_list_filters_search_and_empty_states(): void
    {
        $admin = $this->user('admin');
        $a = $this->allOff('agent');
        $b = $this->allOff('agent');
        $service = app(AlertSwitchService::class);

        $this->actingAs($admin)->post(route('corex.alerts-off.accept', $a->id));

        $this->assertSame([$b->id], $service->allOffRows($admin, ['status' => 'open'])->map(fn ($r) => $r['user']->id)->all());
        $this->assertSame([$a->id], $service->allOffRows($admin, ['status' => 'accepted'])->map(fn ($r) => $r['user']->id)->all());
        $this->assertSame([$b->id], $service->allOffRows($admin, ['q' => substr($b->email, 0, 8)])->map(fn ($r) => $r['user']->id)->all());
        $this->assertCount(0, $service->allOffRows($admin, ['q' => 'no-such-person-xyz']));
        $this->assertCount(0, $service->allOffRows($admin, ['from' => now()->addDays(2)->toDateString()]), 'date range on "off since"');

        $this->actingAs($admin)->get(route('corex.alerts-off.index', ['q' => 'no-such-person-xyz']))
            ->assertOk()->assertSee('No users match these filters');

        $empty = $this->user('admin');
        $this->setSwitches($a, ['in_app' => true, 'email' => true, 'push' => true]);
        $this->setSwitches($b, ['in_app' => true, 'email' => true, 'push' => true]);
        $this->actingAs($empty)->get(route('corex.alerts-off.index'))->assertOk()->assertSee('Everyone you can see has at least one alert switched on.');
    }

    public function test_the_compact_line_shows_the_count_to_a_user_manager_and_nothing_when_none(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin);
        $this->assertStringNotContainsString('data-alerts-off-line', view('corex.alerts-off._summary-line')->render());

        $this->allOff('agent');
        $this->allOff('agent');
        $html = view('corex.alerts-off._summary-line')->render();
        $this->assertStringContainsString('2 users have all alerts off', $html);
        $this->assertStringContainsString(route('corex.alerts-off.index'), $html);
    }

    // ── B3: the record is append-only ──────────────────────────────────────────────────────

    public function test_a_recorded_decision_cannot_be_edited(): void
    {
        $user = $this->allOff();
        $this->actingAs($user)->post(route('corex.alerts.bar.keep-off'));
        $row = UserAlertDecision::withoutGlobalScopes()->where('user_id', $user->id)->sole();

        $this->expectException(\LogicException::class);
        $row->update(['decision' => 'turned_on']);
    }

    // ── C: the review-step button label ────────────────────────────────────────────────────

    public function test_the_fault_review_step_button_says_save_draft(): void
    {
        $admin = $this->user('admin');
        $property = \App\Models\Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $admin->id, 'branch_id' => $this->branch->id,
            'title' => 'Unit 33, 401 Margate Boulevard', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $fault = app(\App\Services\Rentals\RentalFaultReportService::class)->report($property, [
            'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'title' => 'Roof leak', 'description' => 'Water in the passage',
        ]);

        $html = $this->actingAs($admin)->get(route('corex.rental-fault-reports.show', $fault->id))->assertOk()->getContent();

        $this->assertStringContainsString('Save draft', $html);
        $this->assertStringNotContainsString('Save owner version', $html);
    }

    // ── Parity: the bulk (list) rule and the per-user (dispatcher) rule agree ──────────────

    public function test_the_list_rule_and_the_dispatcher_rule_agree_including_for_a_locked_agency(): void
    {
        $service = app(NotificationPreferenceService::class);
        $combos = [
            [true, true, true], [false, false, false], [false, true, false], [false, false, true], [true, false, false],
        ];
        $users = collect($combos)->map(fn ($c) => $this->user('agent', null, null, ['in_app' => $c[0], 'email' => $c[1], 'push' => $c[2]]));

        foreach ([false, true] as $locked) {
            if ($locked) {
                $this->agency->update(['dashboard_settings_mode' => 'agency']);
                AgencyDashboardSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], array_merge(
                    UserDashboardSetting::defaults(),
                    ['notify_in_app' => false, 'notify_email' => true]
                ));
            }
            $bulk = $service->masterSwitchStatesForAgency($users, $this->agency->id);
            foreach ($users as $u) {
                $one = $service->masterSwitchState($u->fresh());
                foreach (['in_app', 'email', 'push', 'locked'] as $k) {
                    $this->assertSame($one[$k], $bulk[$u->id][$k], "{$k} (locked=" . ($locked ? 'yes' : 'no') . ")");
                }
            }
        }
    }

    private function seedLockedDownRoles(): void
    {
        // A grants table that exists and gives the agent role nothing: permission checks now bite, as on a real agency.
        \Illuminate\Support\Facades\DB::table('role_permissions')->insert([
            'agency_id' => $this->agency->id, 'role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
