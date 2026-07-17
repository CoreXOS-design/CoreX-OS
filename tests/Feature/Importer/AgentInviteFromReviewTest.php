<?php

namespace Tests\Feature\Importer;

use App\Jobs\SendAgentInviteJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24PortalEvent;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AgentInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Agent invites are the LAST step of onboarding: they are sent per-agency from
 * the Property Onboarding review page once the agency's properties are in —
 * never per-run, mid-import, from the agent run detail page.
 *
 * This covers the move itself plus the two things the move forces:
 *  - the bulk press must be safe to repeat (skip already-invited / already-active)
 *  - the page spans agencies, so it must survive an owner using the agency switcher
 */
class AgentInviteFromReviewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $otherAgency;
    private User $owner;
    private P24ImportRun $agentRun;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::firstOrCreate(['name' => 'system_owner'], ['label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent']);
        Role::clearCache();

        // A real-world agency name with an apostrophe — it is interpolated into a
        // JS confirm() dialog, so a naive build breaks the button on this name.
        $this->agency = Agency::create(['name' => "O'Brien Coastal Realty", 'slug' => 'obrien-coastal']);
        $this->otherAgency = Agency::create(['name' => 'Shelly Beach Props', 'slug' => 'shelly-beach']);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Branch::create(['agency_id' => $this->otherAgency->id, 'name' => 'Uvongo']);

        $this->owner = User::factory()->create(['role' => 'system_owner', 'agency_id' => null]);

        $this->agentRun = $this->makeRun('agents');

        // The agency only appears on the review page once its properties are in —
        // which is exactly the gate the invite button is supposed to sit behind.
        $this->makeRun('listings_images');
    }

    public function test_review_page_lists_imported_agents_with_state_and_offers_the_bulk_button(): void
    {
        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $this->importedAgent('Sarah Pillay', 'sarah@obrien.co.za', invitedAt: now()->subDay());
        $this->importedAgent('Mike van Wyk', 'mike@obrien.co.za', active: true);

        $resp = $this->actingAs($this->owner)->get(route('admin.importer.review'));

        $resp->assertOk()
            ->assertSee('Thabo Ndlovu')
            ->assertSee('Sarah Pillay')
            ->assertSee('Not invited')
            ->assertSee('Active')
            // Only the 1 never-invited agent is offered — not all 3.
            ->assertSee('Send invite links (1)');
    }

    public function test_bulk_invite_sends_only_to_uninvited_inactive_agents_and_reports_the_skips(): void
    {
        Queue::fake();

        $fresh   = $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $invited = $this->importedAgent('Sarah Pillay', 'sarah@obrien.co.za', invitedAt: now()->subDay());
        $active  = $this->importedAgent('Mike van Wyk', 'mike@obrien.co.za', active: true);

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 agent')
                && str_contains($m, '1 already invited')
                && str_contains($m, '1 already active'));

        Queue::assertPushed(SendAgentInviteJob::class, 1);
        Queue::assertPushed(SendAgentInviteJob::class, fn ($job) => $job->userId === $fresh->id);
        Queue::assertNotPushed(SendAgentInviteJob::class, fn ($job) => in_array($job->userId, [$invited->id, $active->id], true));
    }

    public function test_pressing_send_a_second_time_sends_nothing_further(): void
    {
        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        Notification::fake();

        // First press actually runs the job so invited_at is stamped for real,
        // rather than the test asserting against a state it invented itself.
        $this->actingAs($this->owner)->post(route('admin.importer.agency.invite-agents', $this->agency));
        Notification::assertSentTo(User::where('email', 'thabo@obrien.co.za')->firstOrFail(), AgentInviteNotification::class);

        Queue::fake();
        $this->actingAs($this->owner)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'already been invited'));

        Queue::assertNothingPushed();
    }

    public function test_agent_with_no_email_is_skipped_and_reported_never_a_500(): void
    {
        Queue::fake();

        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $this->importedAgent('No Email Agent', '');

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 with no email address'));

        Queue::assertPushed(SendAgentInviteJob::class, 1);
    }

    public function test_every_agent_already_handled_is_absorbed_not_an_error(): void
    {
        Queue::fake();
        $this->importedAgent('Mike van Wyk', 'mike@obrien.co.za', active: true);

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'already been invited or is already active'));

        Queue::assertNothingPushed();
    }

    public function test_archived_agent_drops_out_of_the_list_and_is_never_invited(): void
    {
        Queue::fake();

        $keep = $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $gone = $this->importedAgent('Deleted Agent', 'gone@obrien.co.za');
        $gone->delete();

        $this->actingAs($this->owner)->get(route('admin.importer.review'))
            ->assertOk()
            ->assertSee('Thabo Ndlovu')
            ->assertDontSee('Deleted Agent');

        $this->actingAs($this->owner)->post(route('admin.importer.agency.invite-agents', $this->agency));

        Queue::assertPushed(SendAgentInviteJob::class, 1);
        Queue::assertPushed(SendAgentInviteJob::class, fn ($job) => $job->userId === $keep->id);
    }

    /**
     * The importer spans agencies but AgencyScope resolves against the viewing
     * owner's OWN agency. Switch into an unrelated agency and a scoped query
     * silently returns nothing — the page would report "no agents" for an
     * agency that has three, with no error to explain it.
     */
    public function test_invites_still_work_when_the_owner_has_switched_into_another_agency(): void
    {
        Queue::fake();
        $agent = $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');

        session(['active_agency_id' => $this->otherAgency->id]);

        $this->actingAs($this->owner)->get(route('admin.importer.review'))
            ->assertOk()
            ->assertSee('Thabo Ndlovu')
            ->assertSee('Send invite links (1)');

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertRedirect();

        Queue::assertPushed(SendAgentInviteJob::class, fn ($job) => $job->userId === $agent->id);
    }

    public function test_per_agent_resend_works_for_an_already_invited_agent(): void
    {
        Queue::fake();
        $invited = $this->importedAgent('Sarah Pillay', 'sarah@obrien.co.za', invitedAt: now()->subDay());

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agent.invite', $invited->id))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($m) => str_contains($m, 're-sent'));

        Queue::assertPushed(SendAgentInviteJob::class, fn ($job) => $job->userId === $invited->id);
    }

    public function test_per_agent_invite_for_a_vanished_agent_shows_a_message_not_a_404(): void
    {
        Queue::fake();
        $agent = $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $agent->delete();

        $this->actingAs($this->owner)
            ->post(route('admin.importer.agent.invite', $agent->id))
            ->assertRedirect()
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no longer exists'));

        Queue::assertNothingPushed();
    }

    public function test_the_job_stamps_invited_at_only_after_the_notification_goes_out(): void
    {
        Notification::fake();
        $agent = $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $this->assertNull($agent->invited_at);

        (new SendAgentInviteJob($agent->id))->handle();

        Notification::assertSentTo($agent, AgentInviteNotification::class);
        $this->assertNotNull($agent->fresh()->invited_at);
    }

    public function test_bulk_invite_is_logged_to_the_agency_activity_history(): void
    {
        Queue::fake();
        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $this->importedAgent('Mike van Wyk', 'mike@obrien.co.za', active: true);

        $this->actingAs($this->owner)->post(route('admin.importer.agency.invite-agents', $this->agency));

        $event = P24PortalEvent::withoutGlobalScopes()->where('event', 'agents.invites_sent')->firstOrFail();
        $this->assertSame($this->agency->id, (int) $event->agency_id);
        $this->assertSame(1, $event->meta_json['sent']);
        $this->assertSame(1, $event->meta_json['skipped_active']);
    }

    public function test_the_agent_run_detail_page_no_longer_offers_invites(): void
    {
        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');

        $this->actingAs($this->owner)->get(route('admin.importer.show', $this->agentRun))
            ->assertOk()
            ->assertDontSee('Send All Invites')
            ->assertDontSee('Complete without sending invites')
            // ...and says where they went instead of just dropping them.
            ->assertSee('Property Onboarding');
    }

    public function test_a_non_owner_cannot_send_agency_invites(): void
    {
        Queue::fake();
        $this->importedAgent('Thabo Ndlovu', 'thabo@obrien.co.za');
        $agencyAdmin = User::factory()->create(['role' => 'admin', 'agency_id' => $this->agency->id]);

        $this->actingAs($agencyAdmin)
            ->post(route('admin.importer.agency.invite-agents', $this->agency))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    private function makeRun(string $kind): P24ImportRun
    {
        return P24ImportRun::create([
            'user_id'   => $this->owner->id,
            'agency_id' => $this->agency->id,
            'kind'      => $kind,
            'status'    => 'completed',
        ]);
    }

    /**
     * An agent as the importer leaves them: a confirmed agent row pointing at a
     * real inactive user. `active` mirrors an agent who has accepted an invite.
     *
     * Note `$email = ''` for the no-email case, not null: `users.email` is
     * NOT NULL + UNIQUE, so an empty string is the only representable "no
     * contact address" state — which is exactly what blank() has to catch.
     */
    private function importedAgent(string $name, string $email, bool $active = false, $invitedAt = null): User
    {
        $user = User::factory()->create([
            'name'       => $name,
            'email'      => $email,
            'role'       => 'agent',
            'agency_id'  => $this->agency->id,
            'is_active'  => $active,
            'invited_at' => $invitedAt,
        ]);

        P24ImportRow::create([
            'run_id'      => $this->agentRun->id,
            'row_type'    => 'agent',
            'external_id' => (string) (1000 + $user->id),
            'payload_json' => ['EmailAddress' => $email],
            'mapped_json' => ['name' => $name, 'email' => $email],
            'action'      => 'create',
            'status'      => 'confirmed',
            'target_id'   => $user->id,
        ]);

        return $user;
    }
}
