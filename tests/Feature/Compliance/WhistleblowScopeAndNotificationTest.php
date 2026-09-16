<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\OfficerAppointment;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use App\Notifications\Compliance\WhistleblowReportReturnedNotification;
use App\Notifications\Compliance\WhistleblowReportSubmittedNotification;
use App\Services\Compliance\ApprovalQueueCounts;
use App\Services\Compliance\OfficerRegistry;
use App\Services\Compliance\WhistleblowComplaintService;
use Database\Seeders\NotificationEventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Compliance reporting under the gate spec — §9. Paths proven: own / branch / all scope on the
 * model scope the list, page and badge share; the officer rule (CO always; ROs only when the
 * agency allows; legacy roles only while no CO exists); submit notifies the deciders in scope;
 * changes-requested notifies the filer with the notes; rejection likewise.
 */
final class WhistleblowScopeAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $b1;
    private Branch $b2;
    private User $agent;
    private User $bm;
    private User $admin;
    private User $ro;
    private OfficerRegistry $registry;
    private WhistleblowComplaintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(NotificationEventTypeSeeder::class);

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh']);

        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->b1->id, 'role' => 'agent', 'name' => 'Retha Botha']);
        $this->bm    = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->b1->id, 'role' => 'branch_manager', 'name' => 'Elize Nel']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->b2->id, 'role' => 'admin', 'name' => 'Johan Reichel']);
        $this->ro    = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->b2->id, 'role' => 'branch_manager', 'name' => 'Dalene du Toit']);

        $this->registry = app(OfficerRegistry::class);
        $this->service  = app(WhistleblowComplaintService::class);
    }

    private function complaint(User $filer, string $status = 'pending_approval'): WhistleblowComplaint
    {
        return WhistleblowComplaint::withoutEvents(fn () => WhistleblowComplaint::create([
            'agency_id'           => $this->agency->id,
            'branch_id'           => $filer->branch_id,
            'reported_by_user_id' => $filer->id,
            'tier'                => 'tier_1',
            'property_address'    => '12 Marine Drive, Margate',
            'seller_statement'    => 'The seller says the listing was placed without a signed mandate.',
            'agent_notes'         => 'Listing seen on Property24 on 14 September.',
            'status'              => $status,
        ]));
    }

    public function test_scope_is_agent_own_branch_manager_branch_admin_all(): void
    {
        $mine   = $this->complaint($this->agent);
        $branch = $this->complaint($this->bm);
        $other  = $this->complaint($this->ro); // Scottburgh

        $ids = fn (User $u) => WhistleblowComplaint::query()->visibleTo($u)->pluck('id')->sort()->values()->all();

        $this->actingAs($this->agent);
        $this->assertSame([$mine->id], $ids($this->agent), 'agent: own');
        $this->actingAs($this->bm);
        $this->assertSame(collect([$mine->id, $branch->id])->sort()->values()->all(), $ids($this->bm), 'branch manager: branch');
        $this->actingAs($this->admin);
        $this->assertSame(collect([$mine->id, $branch->id, $other->id])->sort()->values()->all(), $ids($this->admin), 'admin: all');
    }

    public function test_an_appointed_agent_co_sees_and_decides_every_report(): void
    {
        $other = $this->complaint($this->ro); // Scottburgh, not the agent's own
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->agent->id, $this->admin->id);

        $this->actingAs($this->agent);
        $this->assertContains($other->id, WhistleblowComplaint::query()->visibleTo($this->agent)->pluck('id')->all(), 'the appointment widens sight to the whole agency');
        $this->assertTrue(app(\App\Services\Compliance\ApprovalQueueCounts::class)->whistleblowMayDecide($this->agent, $this->agency->id));
        $this->get(route('compliance.whistleblow.show', $other))->assertOk();
    }

    public function test_officer_rule_co_always_ro_only_when_allowed_legacy_roles_only_without_a_co(): void
    {
        $counts = app(ApprovalQueueCounts::class);

        // No CO appointed → legacy: admin / branch_manager may decide, an agent may not.
        $this->assertTrue($counts->whistleblowMayDecide($this->admin, $this->agency->id));
        $this->assertTrue($counts->whistleblowMayDecide($this->bm, $this->agency->id));
        $this->assertFalse($counts->whistleblowMayDecide($this->agent, $this->agency->id));

        // A CO exists → only the CO (and allowed ROs).
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->admin->id, $this->admin->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, [$this->ro->id], $this->admin->id);
        $this->assertTrue($counts->whistleblowMayDecide($this->admin, $this->agency->id), 'CO');
        $this->assertFalse($counts->whistleblowMayDecide($this->bm, $this->agency->id), 'legacy role no longer decides once a CO exists');
        $this->assertFalse($counts->whistleblowMayDecide($this->ro, $this->agency->id), 'RO may not until the agency allows');

        $this->registry->setWhistleblowRosMaySubmit($this->agency->id, true);
        $this->assertTrue($counts->whistleblowMayDecide($this->ro, $this->agency->id), 'RO may once allowed');

        // The service enforces the same rule.
        $c = $this->complaint($this->agent);
        $this->expectException(\InvalidArgumentException::class);
        $this->service->requestChanges($c, $this->bm, 'Please add the listing link.');
    }

    public function test_submit_notifies_the_deciders_in_scope_and_not_the_filer(): void
    {
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->admin->id, $this->admin->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, [$this->bm->id, $this->ro->id], $this->admin->id);
        $this->registry->setWhistleblowRosMaySubmit($this->agency->id, true);

        $c = $this->complaint($this->agent, 'draft');
        $c->subjects()->create(['agency_name' => 'Rival Props', 'portal_url' => 'https://www.property24.com/x', 'portal_source' => 'p24']);
        $this->actingAs($this->agent);
        $this->service->submit($c, $this->agent);

        Notification::assertSentTo($this->admin, WhistleblowReportSubmittedNotification::class);
        Notification::assertSentTo($this->bm, WhistleblowReportSubmittedNotification::class, fn ($n) => true);
        Notification::assertNotSentTo($this->ro, WhistleblowReportSubmittedNotification::class); // Scottburgh RO: out of branch scope
        Notification::assertNotSentTo($this->agent, WhistleblowReportSubmittedNotification::class);
    }

    public function test_changes_requested_and_rejection_notify_the_filer_with_the_notes(): void
    {
        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_WHISTLEBLOW, $this->admin->id, $this->admin->id);
        $this->actingAs($this->admin);

        $c = $this->complaint($this->agent);
        $this->service->requestChanges($c, $this->admin, 'Add the listing link and the agent\'s FFC number.');
        $this->assertSame('changes_requested', $c->fresh()->status);
        Notification::assertSentTo($this->agent, WhistleblowReportReturnedNotification::class, function ($n) {
            $arr = $n->toArray($this->agent);
            return str_contains($arr['body'], 'FFC number') && $arr['type'] === 'whistleblow_report_changes_requested';
        });

        $c2 = $this->complaint($this->agent);
        $this->service->reject($c2, $this->admin, 'Not a breach.');
        Notification::assertSentTo($this->agent, WhistleblowReportReturnedNotification::class, function ($n) {
            return $n->toArray($this->agent)['type'] === 'whistleblow_report_rejected';
        });
    }
}
