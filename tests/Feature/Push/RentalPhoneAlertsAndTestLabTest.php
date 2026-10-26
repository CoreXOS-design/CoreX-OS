<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Models\DeviceToken;
use App\Models\RentalInspection;
use App\Models\Role;
use App\Models\User;
use App\Services\Rentals\RentalInspectionCopiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 - agents' phones are told about rentals, tenants/owners are told when an inspection report is ready, and the System Developer
 * "Push Test Lab" sends a test to one person. .ai/specs/push-test-lab.md.
 */
final class RentalPhoneAlertsAndTestLabTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const CLIENT = '/api/v1/client/rentals';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function agentPhone(string $token): void
    {
        DeviceToken::create(['user_id' => $this->agent->id, 'platform' => 'android', 'token' => $token]);
    }

    private function owner(): User
    {
        $role = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
    }

    // ── the agent's phone ────────────────────────────────────────────────────────────────────

    public function test_a_fault_reported_by_the_tenant_buzzes_the_agents_phone_and_opens_the_fault(): void
    {
        $this->agentPhone('tok-agent');

        $res = $this->asClient($this->tenantLogin)->postJson(self::CLIENT . "/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Burst geyser', 'description' => 'Water everywhere',
        ])->assertStatus(201);

        $sent = $this->pushedTo('tok-agent');
        $this->assertCount(1, $sent, 'one phone alert for one fault');
        $data = $sent[0]['payload']['data'];
        $this->assertSame('rental_fault_report.created', $data['event_key']);
        $this->assertSame('fault', $data['target']);
        $this->assertSame((string) $res->json('fault_report.id'), $data['target_id']);
        $this->assertStringContainsString('Fault reported', $sent[0]['payload']['notification']['title']);
    }

    public function test_an_agent_who_switched_the_alert_off_for_their_phone_is_not_buzzed(): void
    {
        $this->agentPhone('tok-agent');
        $type = \App\Models\CommandCenter\NotificationEventType::where('key', 'rental_fault_report.created')->firstOrFail();
        \App\Models\CommandCenter\UserNotificationPreference::create([
            'user_id' => $this->agent->id, 'notification_event_type_id' => $type->id, 'enabled' => true,
            'channel_in_app' => true, 'channel_email' => false, 'channel_push' => false,
        ]);

        $this->asClient($this->tenantLogin)->postJson(self::CLIENT . "/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Slow drain', 'description' => 'x',
        ])->assertStatus(201);

        $this->assertSame([], $this->pushedTo('tok-agent'));
    }

    public function test_the_agent_alerts_that_need_action_are_on_for_the_phone_and_the_agents_own_actions_are_not(): void
    {
        foreach (['rental_fault_report.created', 'rental_fault_report.tenant_update', 'rental_work_order.quote_received', 'rental_work_order.tenant_not_fixed',
            'rental_work_order.disputed', 'lease_notice.received', 'rental_application.returned', 'rental_inspection.due_reminder'] as $key) {
            $this->assertSame(1, (int) DB::table('notification_event_types')->where('key', $key)->value('supports_push'), $key);
        }
        foreach (['rental_work_order.created', 'rental_work_order.go_ahead_sent', 'rental_work_order.contractor_job_sent', 'rental_work_order.contractor_photo'] as $key) {
            $this->assertSame(0, (int) DB::table('notification_event_types')->where('key', $key)->value('supports_push'), $key);
        }
    }

    public function test_a_carried_inspection_reminder_can_be_pushed_and_names_what_to_open(): void
    {
        $n = new \App\Notifications\RentalInspectionDueReminder('out', 'due', '2026-11-01', '12 Sea Road', $this->lease->id, 'due_list', 'https://x.test');
        $n->id = 'abc';
        $p = $n->toFcmPayload();
        $this->assertSame('lease', $p['data']['target']);
        $this->assertSame((string) $this->lease->id, $p['data']['target_id']);
        $this->assertSame('rental_inspection.due_reminder', $p['data']['event_key']);

        $c = new \App\Notifications\RentalInspectionCopiesNotDelivered(77, 'in', '12 Sea Road', 2, 'https://x.test');
        $this->assertSame(['target' => 'inspection', 'target_id' => '77'], array_intersect_key($c->toFcmPayload()['data'], ['target' => 1, 'target_id' => 1]));
    }

    // ── tenant and owner: the inspection report is ready ─────────────────────────────────────

    public function test_tenant_and_owner_are_told_once_when_an_inspection_report_is_ready_and_it_is_switchable(): void
    {
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $this->phoneFor($this->ownerLogin, 'tok-owner');
        $i = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'out', 'status' => 'completed',
            'created_by_user_id' => $this->agent->id, 'completed_at' => now(),
        ]);

        \App\Events\Rentals\RentalInspectionReportReady::dispatch($i);
        \App\Events\Rentals\RentalInspectionReportReady::dispatch($i);   // a manual resend of the same report

        $t = $this->pushedTo('tok-tenant');
        $o = $this->pushedTo('tok-owner');
        $this->assertCount(1, $t);
        $this->assertCount(1, $o);
        $this->assertSame('inspection_ready', $t[0]['payload']['data']['category']);
        $this->assertSame('inspection', $t[0]['payload']['data']['subject_type']);
        $this->assertSame((string) $i->id, $t[0]['payload']['data']['subject_id']);
        $this->assertSame('0', $t[0]['payload']['data']['mandatory']);
        $this->assertStringNotContainsString('Lenny', json_encode($t[0]['payload']), 'a tenant is never told anything about the owner');

        // switchable: the tenant turns it off, the next report does not reach them
        $this->asClient($this->tenantLogin)->putJson(self::CLIENT . '/push-preferences', ['preferences' => ['inspection_ready' => false]])->assertOk();
        $i2 = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'in', 'status' => 'completed',
            'created_by_user_id' => $this->agent->id, 'completed_at' => now(),
        ]);
        \App\Events\Rentals\RentalInspectionReportReady::dispatch($i2);
        $this->assertCount(1, $this->pushedTo('tok-tenant'));
        $this->assertCount(2, $this->pushedTo('tok-owner'));
    }

    // ── the Push Test Lab ────────────────────────────────────────────────────────────────────

    public function test_only_a_system_owner_can_open_or_use_the_lab(): void
    {
        $this->actingAs($this->admin)->get(route('admin.push-test-lab.index'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.push-test-lab.send'), ['who' => 'staff:' . $this->agent->id, 'what' => 'custom'])->assertForbidden();
        $this->assertSame([], $this->pushedTo('tok-x'));
    }

    public function test_the_lab_finds_a_person_by_cell_number_and_lists_every_type(): void
    {
        $this->agentPhone('tok-agent');
        $owner = $this->owner();

        $page = $this->actingAs($owner)->get(route('admin.push-test-lab.index', ['q' => '082 111 2222', 'who' => 'staff:' . $this->agent->id]))->assertOk();
        $page->assertSee($this->agent->name);
        $page->assertSee('1 phone(s) registered');
        $page->assertSee('Fault report logged');                 // an agent alert type
        $page->assertSee('Send a plain test message');

        $tenantPage = $this->actingAs($owner)->get(route('admin.push-test-lab.index', ['q' => 'Tina', 'who' => 'client:' . $this->tenantLogin->id]))->assertOk();
        $tenantPage->assertSee('An inspection report is ready');
        $tenantPage->assertSee('A decision is waiting for you');
        $tenantPage->assertSee('mandatory');
    }

    public function test_a_test_reaches_the_agents_phone_marked_test_with_the_same_open_fields_a_real_alert_has(): void
    {
        $this->agentPhone('tok-agent');
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.push-test-lab.send'), [
            'who' => 'staff:' . $this->agent->id, 'what' => 'event:rental_fault_report.created', 'record_id' => 42,
        ])->assertRedirect()->assertSessionHas('push_test_result', fn ($r) => $r['ok'] === true);

        $sent = $this->pushedTo('tok-agent');
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('[TEST]', $sent[0]['payload']['notification']['title']);
        $this->assertSame('1', $sent[0]['payload']['data']['test']);
        $this->assertSame('fault', $sent[0]['payload']['data']['target']);
        $this->assertSame('42', $sent[0]['payload']['data']['target_id']);
        $this->assertSame('rental_fault_report.created', $sent[0]['payload']['data']['event_key']);
    }

    public function test_a_test_to_a_tenant_carries_the_category_and_is_not_swallowed_as_a_repeat(): void
    {
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $owner = $this->owner();

        foreach ([1, 2] as $_) {
            $this->actingAs($owner)->post(route('admin.push-test-lab.send'), ['who' => 'client:' . $this->tenantLogin->id, 'what' => 'category:work_check_requested'])
                ->assertSessionHas('push_test_result', fn ($r) => $r['ok'] === true);
        }
        $sent = $this->pushedTo('tok-tenant');
        $this->assertCount(2, $sent, 'the same test pressed twice is two tests');
        $this->assertSame('work_check_requested', $sent[0]['payload']['data']['category']);
        $this->assertSame('1', $sent[0]['payload']['data']['mandatory']);
    }

    public function test_a_person_with_no_phone_is_told_so_in_plain_words_and_an_unknown_type_is_refused(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.push-test-lab.send'), ['who' => 'staff:' . $this->agent->id, 'what' => 'custom'])
            ->assertSessionHas('push_test_result', fn ($r) => $r['ok'] === false && str_contains($r['message'], 'no phone registered'));

        $this->agentPhone('tok-agent');
        $this->actingAs($owner)->post(route('admin.push-test-lab.send'), ['who' => 'staff:' . $this->agent->id, 'what' => 'event:not.a.real.type'])
            ->assertSessionHas('push_test_result', fn ($r) => $r['ok'] === false);
        $this->actingAs($owner)->post(route('admin.push-test-lab.send'), ['who' => 'nonsense', 'what' => 'custom'])->assertStatus(422);
        $this->assertSame([], $this->pushedTo('tok-agent'));
    }

    public function test_every_destination_the_app_may_open_is_listed(): void
    {
        $this->assertEqualsCanonicalizing(
            ['fault', 'work_order', 'job_card', 'lease_notice', 'lease', 'inspection', 'application'],
            \App\Support\Push\PushTarget::destinations(),
        );
    }
}
