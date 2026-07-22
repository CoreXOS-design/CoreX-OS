<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Mail\UserInviteMail;
use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Assistants\AssistantMatrixSnapshotService;
use App\Services\PermissionService;
use Database\Seeders\AssistantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-267 — Prompts F + G: the whole life of an assistant, end to end.
 *
 * Create → invited on the EXISTING flow → matrix arrives as a COPY of the agent's permissions →
 * the agent trims it → the agent gains something new and it lands switched OFF → reassign →
 * revoke → restore. Full CRUD is the floor (BUILD_STANDARD §1), and every step below is a place
 * the feature could quietly do the wrong thing:
 *
 *  - Create the user without an explicit role and CoreX makes them a full AGENT (users.role is
 *    NOT NULL DEFAULT 'agent').
 *  - Seed an empty matrix and every new assistant lands unable to do anything, so the agent has
 *    to tick forty boxes before the person they hired can work — which is how a feature ships
 *    and is never used.
 *  - Seed the locked keys as granted and the property lock is dead on arrival.
 *  - Let an agent grant something they don't have and the intersection is a lie.
 *  - Auto-grant a newly-gained permission and the assistant silently widens.
 *  - Hard-delete on revoke and the audit trail is gone.
 *  - Carry the old matrix across a reassignment and the assistant keeps powers the NEW agent
 *    never chose to give.
 */
final class AssistantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;
    private User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => AssistantRoleSeeder::class, '--force' => true]);

        // The permission DEFINITIONS (nexus_permissions). The snapshot service walks this table
        // to work out which keys the agent holds, so without it the matrix would seed empty and
        // every assertion below would pass or fail for the wrong reason. No flags: this syncs
        // the catalogue only, never role grants — those are set by hand below so the agent's
        // permission set is exactly what this test says it is.
        Artisan::call('corex:sync-permissions');

        Mail::fake();

        $this->agency = Agency::create([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(),
            'assistants_enabled' => true, 'assistant_fica_required_default' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        foreach (['admin', 'agent'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }

        $this->admin      = $this->user('Johan Reichel', 'admin');
        $this->agent      = $this->user('Sarah Nkosi', 'agent');
        $this->otherAgent = $this->user('Pieter van Wyk', 'agent');

        // The admin can manage assistants. The agent has a real, ordinary permission set.
        foreach (['assistants.view', 'assistants.create', 'assistants.reassign', 'assistants.revoke'] as $k) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $k, 'agency_id' => $this->agency->id]);
        }
        foreach (['access_properties', 'properties.create', 'properties.edit', 'contacts.create'] as $k) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $k, 'agency_id' => $this->agency->id]);
        }
        RolePermission::create(['role' => 'agent', 'permission_key' => 'contacts.view', 'agency_id' => $this->agency->id, 'scope' => 'own']);

        $this->reset();
    }

    private function user(string $name, string $role): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    private function createAssistant(?int $agentId = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.assistants.store'), [
            'name'          => 'Thandi',
            'surname'       => 'Mokoena',
            'email'         => 'thandi.mokoena@hfcoastal.co.za',
            'cell'          => '083 555 0142',
            'agent_user_id' => $agentId ?? $this->agent->id,
        ]);
    }

    // ── Create ─────────────────────────────────────────────────────

    public function test_creating_an_assistant_makes_a_zero_grant_user_not_an_agent(): void
    {
        $this->createAssistant()->assertRedirect();

        $assistant = User::where('email', 'thandi.mokoena@hfcoastal.co.za')->firstOrFail();

        // `users.name` is the full name — there is no surname column; the form's two fields are
        // joined on save, exactly as User Management does it.
        $this->assertSame('Thandi Mokoena', $assistant->name);

        // THE trap. users.role is NOT NULL DEFAULT 'agent' — a create that forgets the role
        // silently produces a full agent.
        $this->assertSame('assistant', $assistant->role);
        $this->assertTrue($assistant->is_assistant);

        // Invite state: NO usable password until they set their own via the signed setup link.
        //
        // Deliberately NOT the 'INVITE_PENDING' sentinel UserManagementController uses. That
        // string is run through the `hashed` cast, so the stored hash is a bcrypt of a PUBLICLY
        // KNOWN CONSTANT — Auth::attempt() succeeds for any pending user who types
        // "INVITE_PENDING". Asserted here so nobody "helpfully" makes this consistent with the
        // old flow. (The underlying issue is reported separately; it is not ours to fix inside
        // this ticket, but it is certainly not ours to copy.)
        $this->assertFalse(
            Hash::check('INVITE_PENDING', $assistant->password),
            'An assistant must never be loggable-into with the publicly-known invite sentinel.'
        );
        $this->assertNull($assistant->email_verified_at);
        $this->assertTrue($assistant->is_active);

        // They live in their agent's branch.
        $this->assertSame($this->branch->id, $assistant->branch_id);

        // FICA default came from the agency setting.
        $this->assertTrue($assistant->fica_required);
    }

    public function test_a_custom_title_is_stored_and_falls_back_to_assistant_when_blank(): void
    {
        // A title given at creation is stored as a LABEL — the role stays 'assistant'.
        $this->actingAs($this->admin)->post(route('admin.assistants.store'), [
            'name'          => 'Precious',
            'surname'       => 'Ndlovu',
            'email'         => 'precious.ndlovu@hfcoastal.co.za',
            'cell'          => '083 555 0199',
            'agent_user_id' => $this->agent->id,
            'title'         => 'Receptionist',
        ])->assertRedirect();

        $withTitle = User::where('email', 'precious.ndlovu@hfcoastal.co.za')->firstOrFail();
        $this->assertSame('Receptionist', $withTitle->assistant_title);
        $this->assertSame('Receptionist', $withTitle->assistantTitle());
        $this->assertSame('assistant', $withTitle->role, 'The title is a label — it must never change the role.');

        // No title given → the column is null and the display falls back to "Assistant".
        $this->createAssistant();
        $noTitle = User::where('email', 'thandi.mokoena@hfcoastal.co.za')->firstOrFail();
        $this->assertNull($noTitle->assistant_title);
        $this->assertSame('Assistant', $noTitle->assistantTitle());
    }

    public function test_creating_an_assistant_sends_the_existing_invite_email(): void
    {
        $this->createAssistant();

        // The EXISTING mailable — an assistant sets their password on the same screen as every
        // other new user in CoreX. One flow to keep working, not two.
        Mail::assertSent(UserInviteMail::class, fn ($mail) => $mail->hasTo('thandi.mokoena@hfcoastal.co.za'));
    }

    public function test_the_matrix_arrives_as_a_copy_of_the_agents_permissions(): void
    {
        $this->createAssistant();
        $this->reset();

        $assignment = AssistantAssignment::firstOrFail();
        $assistant  = $assignment->assistant;

        // D6: a COPY, all on. The assistant is useful on day one; the agent trims from there.
        $this->assertTrue($assistant->hasPermission('access_properties'));
        $this->assertTrue($assistant->hasPermission('properties.edit'));
        $this->assertTrue($assistant->hasPermission('contacts.create'));
        $this->assertTrue($assistant->hasPermission('contacts.view'));

        // ...except the locked set, which is seeded PRESENT (so the UI can show and explain the
        // lock) but never granted.
        $locked = AssistantAssignmentPermission::where('assistant_assignment_id', $assignment->id)
            ->where('permission_key', 'properties.create')
            ->firstOrFail();

        $this->assertTrue($locked->is_locked);
        $this->assertFalse($locked->granted);
        $this->assertTrue($this->agent->fresh()->hasPermission('properties.create')); // the agent CAN
        $this->assertFalse($assistant->hasPermission('properties.create'));           // the assistant never
    }

    public function test_an_owner_or_another_assistant_cannot_be_the_assigned_agent(): void
    {
        $ownerRole = new Role(['name' => 'super_admin', 'label' => 'Owner', 'agency_id' => $this->agency->id]);
        $ownerRole->is_owner = true;
        $ownerRole->save();

        $owner = $this->user('Platform Owner', 'super_admin');
        $this->reset();

        // E6 — an owner bypasses every permission check, so an owner agent would make the matrix
        // the ONLY limit: one mis-ticked box would hand out super-admin.
        $this->actingAs($this->admin)
            ->post(route('admin.assistants.store'), [
                'name' => 'Thandi', 'surname' => 'Mokoena',
                'email' => 'thandi@hfcoastal.co.za', 'cell' => '083 555 0142',
                'agent_user_id' => $owner->id,
            ])
            ->assertSessionHasErrors('agent_user_id');

        $this->assertDatabaseMissing('users', ['email' => 'thandi@hfcoastal.co.za']);
    }

    // ── The agent trims the matrix ─────────────────────────────────

    public function test_the_agent_can_switch_a_permission_off(): void
    {
        $this->createAssistant();
        $this->reset();

        $assignment = AssistantAssignment::firstOrFail();

        $this->assertTrue($assignment->assistant->hasPermission('contacts.create'));

        // The agent hands over everything EXCEPT contacts.create.
        $this->actingAs($this->agent)->post(route('agent.assistants.matrix.save', $assignment), [
            'permissions' => [
                'access_properties' => '1',
                'properties.edit'   => '1',
                'contacts.create'   => '0',
            ],
        ])->assertRedirect();

        $this->reset();

        $this->assertFalse($assignment->assistant->fresh()->hasPermission('contacts.create'));
        $this->assertTrue($assignment->assistant->fresh()->hasPermission('properties.edit'));
    }

    public function test_an_agent_cannot_grant_a_permission_they_do_not_have(): void
    {
        $this->createAssistant();
        $this->reset();

        $assignment = AssistantAssignment::firstOrFail();

        // A crafted POST asking for something the agent does not hold. The resolver would deny it
        // at read time anyway — but a matrix row CLAIMING to grant it is a lie in the database,
        // and one day someone will read it and believe it.
        $this->actingAs($this->agent)->post(route('agent.assistants.matrix.save', $assignment), [
            'permissions' => ['manage_users' => '1', 'properties.create' => '1'],
        ]);

        $this->reset();

        $this->assertFalse($assignment->assistant->fresh()->hasPermission('manage_users'));
        $this->assertDatabaseMissing('assistant_assignment_permissions', [
            'assistant_assignment_id' => $assignment->id,
            'permission_key'          => 'manage_users',
            'granted'                 => true,
        ]);

        // And the locked key is still locked, still off.
        $this->assertDatabaseHas('assistant_assignment_permissions', [
            'assistant_assignment_id' => $assignment->id,
            'permission_key'          => 'properties.create',
            'granted'                 => false,
            'is_locked'               => true,
        ]);
    }

    public function test_an_agent_cannot_touch_someone_elses_assistant(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();

        // Ownership, not a permission key: you may configure YOUR assistant.
        $this->actingAs($this->otherAgent)
            ->get(route('agent.assistants.matrix', $assignment))
            ->assertForbidden();

        $this->actingAs($this->otherAgent)
            ->post(route('agent.assistants.matrix.save', $assignment), ['permissions' => []])
            ->assertForbidden();
    }

    // ── Drift (D6) ─────────────────────────────────────────────────

    public function test_a_permission_the_agent_gains_later_arrives_switched_off(): void
    {
        $this->createAssistant();
        $this->reset();

        $assignment = AssistantAssignment::firstOrFail();

        // Johan's Ad Manager example: the agent is granted something new, months later.
        RolePermission::create([
            'role' => 'agent', 'permission_key' => 'access_presentations', 'agency_id' => $this->agency->id,
        ]);
        $this->reset();

        $this->assertTrue($this->agent->fresh()->hasPermission('access_presentations'));

        // The assistant does NOT get it automatically...
        app(AssistantMatrixSnapshotService::class)->syncDrift($assignment);
        $this->reset();

        $this->assertFalse($assignment->assistant->fresh()->hasPermission('access_presentations'));

        // ...it is sitting there, off, waiting for the agent to decide. That is the chip on
        // their Assistants page.
        $this->assertDatabaseHas('assistant_assignment_permissions', [
            'assistant_assignment_id' => $assignment->id,
            'permission_key'          => 'access_presentations',
            'granted'                 => false,
            'is_locked'               => false,
        ]);

        // The agent turns it on, and now they have it.
        $this->actingAs($this->agent)->post(route('agent.assistants.matrix.save', $assignment), [
            'permissions' => ['access_presentations' => '1'],
        ]);
        $this->reset();

        $this->assertTrue($assignment->assistant->fresh()->hasPermission('access_presentations'));
    }

    public function test_the_new_permissions_banner_shows_once_then_clears_on_visit(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();
        $snapshots  = app(AssistantMatrixSnapshotService::class);

        // The INITIAL snapshot is never "new" — admin-default-off / trimmed rows are a
        // settled baseline, not a pending notification. (This is the 81-count bug.)
        $this->assertSame(0, $snapshots->pendingDriftCount($assignment),
            'A freshly-seeded matrix must not report pending "new" permissions.');

        // The agent gains something new later → it arrives flagged NEW.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_presentations', 'agency_id' => $this->agency->id]);
        $this->reset();
        $snapshots->syncDrift($assignment);
        $this->assertSame(1, $snapshots->pendingDriftCount($assignment),
            'A newly-gained permission must show as one pending "new" item.');

        // First visit: the banner is shown (pendingDrift > 0 reaches the view)...
        $this->reset();
        $this->actingAs($this->agent)->get(route('agent.assistants.matrix', $assignment))->assertSuccessful();

        // ...and is acknowledged, so the next visit reports zero — show once, then gone.
        $this->assertSame(0, $snapshots->pendingDriftCount($assignment->fresh()),
            'The "new" notice must clear once the agent has visited the matrix.');
    }

    // ── Reassign / revoke / restore ────────────────────────────────

    public function test_reassignment_archives_the_old_matrix_and_copies_the_new_agents(): void
    {
        $this->createAssistant();
        $this->reset();

        $old = AssistantAssignment::firstOrFail();

        // Pieter has a DIFFERENT permission set — no properties at all.
        RolePermission::where('role', 'agent')->where('agency_id', $this->agency->id)->forceDelete();
        RolePermission::create(['role' => 'agent', 'permission_key' => 'contacts.create', 'agency_id' => $this->agency->id]);
        $this->reset();

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.reassign', $old), ['agent_user_id' => $this->otherAgent->id])
            ->assertRedirect();

        $this->reset();

        // Old assignment archived, not destroyed — no hard deletes, ever.
        $this->assertSoftDeleted('assistant_assignments', ['id' => $old->id]);
        $this->assertDatabaseHas('assistant_assignments', [
            'id'     => $old->id,
            'status' => AssistantAssignment::STATUS_REVOKED,
        ]);

        // A new live assignment, with a fresh copy of the NEW agent's permissions. The assistant
        // must not keep powers the new agent never chose to give.
        $new = AssistantAssignment::active()->firstOrFail();
        $this->assertSame($this->otherAgent->id, $new->agent_user_id);
        $this->assertTrue($new->assistant->hasPermission('contacts.create'));
        $this->assertFalse($new->assistant->hasPermission('access_properties'));
    }

    public function test_revoke_is_a_soft_delete_and_restore_brings_the_matrix_back(): void
    {
        $this->createAssistant();
        $this->reset();

        $assignment = AssistantAssignment::firstOrFail();
        $assistantId = $assignment->assistant_user_id;

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.revoke', $assignment), ['reason' => 'Left the agency'])
            ->assertRedirect(route('admin.assistants.index'));

        $this->reset();

        $this->assertSoftDeleted('assistant_assignments', ['id' => $assignment->id]);
        $this->assertFalse(User::find($assistantId)->hasPermission('contacts.create'));

        // The user record survives — revoking an assignment is not deleting a person.
        $this->assertDatabaseHas('users', ['id' => $assistantId, 'deleted_at' => null]);

        // Restore.
        $this->actingAs($this->admin)
            ->post(route('admin.assistants.restore', $assignment->id))
            ->assertRedirect();

        $this->reset();

        $this->assertDatabaseHas('assistant_assignments', [
            'id'         => $assignment->id,
            'status'     => AssistantAssignment::STATUS_ACTIVE,
            'deleted_at' => null,
        ]);

        // ...with their permissions exactly as the agent left them.
        $this->assertTrue(User::find($assistantId)->hasPermission('contacts.create'));
    }

    public function test_the_admin_pages_are_permission_gated(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();
        $this->reset();

        // The agent has no assistants.* keys — the admin surface is not theirs.
        $this->actingAs($this->agent)->get(route('admin.assistants.index'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('admin.assistants.create'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('admin.assistants.revoke', $assignment))->assertForbidden();
    }

    public function test_the_agent_sees_their_assistants_page_and_the_admin_list_renders(): void
    {
        $this->createAssistant();
        $this->reset();

        $this->actingAs($this->agent)->get(route('agent.assistants.index'))->assertSuccessful();
        $this->actingAs($this->admin)->get(route('admin.assistants.index'))->assertSuccessful();

        // The create form (with the sidebar it renders inside) must render for an admin.
        $this->actingAs($this->admin)->get(route('admin.assistants.create'))
            ->assertSuccessful()
            ->assertSee('Add Assistant');

        $assignment = AssistantAssignment::firstOrFail();
        $this->actingAs($this->agent)->get(route('agent.assistants.matrix', $assignment))->assertSuccessful();
        $this->actingAs($this->admin)->get(route('admin.assistants.show', $assignment))->assertSuccessful();
    }

    public function test_an_agent_with_no_assistant_has_no_page(): void
    {
        // The sidebar entry is conditional on having one; the page must agree.
        $this->actingAs($this->otherAgent)->get(route('agent.assistants.index'))->assertNotFound();
    }

    // ── Activity tracking (AT-267) ─────────────────────────────────────────

    public function test_the_matrix_activity_tab_shows_what_the_assistant_did(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();

        \App\Models\AssistantActivityLog::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $assignment->id,
            'assistant_user_id'       => $assignment->assistant_user_id,
            'agent_user_id'           => $this->agent->id,
            'action'                  => 'opened',
            'subject_type'            => 'property',
            'subject_id'              => 4242,
            'subject_label'           => '12 Beach Road, Margate',
            'created_at'              => now(),
        ]);

        $this->reset();
        $this->actingAs($this->agent)->get(route('agent.assistants.matrix', $assignment))
            ->assertSuccessful()
            ->assertSee('Activity')
            ->assertSee('12 Beach Road, Margate');
    }

    public function test_the_activity_middleware_records_an_assistant_opening_a_record(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();
        $assistant  = $assignment->assistant->fresh();

        $request = \Illuminate\Http\Request::create('/corex/properties/4242', 'GET');
        $route   = (new \Illuminate\Routing\Route(['GET'], 'corex/properties/{property}', ['as' => 'corex.properties.show', 'uses' => fn () => '']))->bind($request);
        $route->setParameter('property', '4242');   // raw id — binding didn't hydrate a model
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $assistant);

        (new \App\Http\Middleware\LogAssistantActivity())
            ->handle($request, fn ($r) => new \Illuminate\Http\Response('ok', 200));

        $this->assertDatabaseHas('assistant_activity_log', [
            'assistant_user_id' => $assistant->id,
            'agent_user_id'     => $this->agent->id,
            'action'            => 'opened',
            'subject_type'      => 'property',
            'subject_id'        => 4242,
        ]);
    }

    // ── Ads (AT-267): all listings, always the listing agent's info ────────

    public function test_an_assistant_can_open_the_ad_for_any_agency_listing_and_it_shows_the_listing_agent(): void
    {
        $this->createAssistant();
        $assignment = AssistantAssignment::firstOrFail();
        $assistant  = $assignment->assistant;

        // A listing owned by a DIFFERENT agent — NOT the assistant's assigned agent.
        $property = \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id'  => $this->otherAgent->id,
            'title'     => 'Sea-facing Apartment', 'status' => 'active',
            'listing_type' => 'sale', 'property_type' => 'apartment',
            'price' => 1850000, 'beds' => 2, 'baths' => 2, 'garages' => 1,
            'suburb' => 'Margate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);

        $this->reset();

        // Before the ad broadening this was a 403 (mutation scope 'own'). Now the
        // assistant reaches it, and the ad carries the LISTING agent's name.
        $this->actingAs($assistant)->get(route('corex.properties.ad', $property))
            ->assertSuccessful()
            ->assertSee(strtoupper($this->otherAgent->name));

        // The broadening is assistant-only: an ordinary agent with no scope over
        // this listing is still blocked.
        $this->actingAs($this->agent)->get(route('corex.properties.ad', $property))
            ->assertForbidden();
    }

    public function test_the_activity_middleware_ignores_a_normal_user(): void
    {
        // A non-assistant hitting the same route writes nothing.
        $request = \Illuminate\Http\Request::create('/corex/properties/99', 'GET');
        $route   = (new \Illuminate\Routing\Route(['GET'], 'corex/properties/{property}', ['as' => 'corex.properties.show', 'uses' => fn () => '']))->bind($request);
        $route->setParameter('property', '99');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->agent);   // an ordinary agent, not an assistant

        (new \App\Http\Middleware\LogAssistantActivity())
            ->handle($request, fn ($r) => new \Illuminate\Http\Response('ok', 200));

        $this->assertDatabaseCount('assistant_activity_log', 0);
    }
}
