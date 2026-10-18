<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseAgentService;
use App\Services\Rentals\LeaseRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §17 — Johan, 8 Oct 2026: "retha is the listing agent, maggie is the tenant agent. but I would still on
 * lease show the agents as such and it can be changed if need be." Every lease carries an OWNER'S agent and a TENANT'S agent.
 *
 * Mirrors reality (BUILD_STANDARD §5): the two agents are different people; the property has no agent; an agent has left; an
 * agent of ANOTHER agency is posted; a lease created before the columns existed; a user who may only see their own leases.
 */
final class LeaseAgentsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $rival;
    private Branch $branch;
    private Branch $otherBranch;
    private User $admin;       // captures the lease
    private User $retha;       // the property's listing agent
    private User $maggie;      // sent out the rental application
    private User $zane;        // approved the application
    private User $stranger;    // an agent of another agency
    private User $gone;        // an agent who has left
    private Property $property;
    private Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Stellenbosch']);

        $this->admin = $this->person('Adam Admin', 'admin');
        $this->retha = $this->person('Retha Listing');
        $this->maggie = $this->person('Maggie Tenant');
        $this->zane = $this->person('Zane Approver');
        $this->gone = $this->person('Gone Gary', 'agent', false);
        $this->stranger = User::factory()->create(['agency_id' => $this->rival->id, 'role' => 'agent', 'name' => 'Stranger Danger', 'is_active' => true]);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->retha->id,
            'title' => 'Flat to let', 'status' => 'active', 'listing_type' => 'rental', 'address' => '401 Margate Boulevard',
            'suburb' => 'Margate', 'city' => 'Margate', 'rental_amount' => 11400, 'deposit_amount' => 11400,
        ]);
        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Ayanda', 'last_name' => 'Tenant',
            'email' => 'ayanda-' . uniqid() . '@example.test',
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function person(string $name, string $role = 'agent', bool $active = true, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => ($branch ?? $this->branch)->id, 'role' => $role, 'name' => $name, 'is_active' => $active,
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create($attrs + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->tenant->id,
            'created_by_user_id' => $this->maggie->id, 'status' => 'approved', 'approved_rental_amount' => 11400,
            'approved_deposit_amount' => 11400, 'current_generation' => 1, 'token' => 'tok-' . uniqid(),
        ]);
    }

    private function lease(array $attrs = []): Lease
    {
        $lease = Lease::create($attrs + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 11400, 'start_date' => '2026-06-01', 'end_date' => '2027-05-31',
            'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    /** @return array<string,mixed> */
    private function captureInput(array $extra = []): array
    {
        return array_merge([
            'intent' => 'lease_only', 'property_id' => $this->property->id, 'tenant_contact_ids' => [$this->tenant->id],
            'rental_amount' => 11400, 'deposit_amount' => 11400, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
        ], $extra);
    }

    private function capture(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('corex.leases.store'), $this->captureInput($extra));
    }

    private function captured(): Lease
    {
        return Lease::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
    }

    private function userWith(array $permissions, string $scope, string $roleName): User
    {
        Role::create(['name' => $roleName, 'label' => $roleName, 'agency_id' => $this->agency->id]);
        foreach ($permissions as $key) {
            RolePermission::updateOrCreate(
                ['role' => $roleName, 'permission_key' => $key, 'agency_id' => $this->agency->id],
                ['scope' => $scope],
            );
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $roleName, 'is_active' => true, 'name' => ucfirst($roleName) . ' User']);
    }

    // ═══ 1. the defaults at creation ═════════════════════════════════════════════════════════

    public function test_a_new_lease_without_an_application_has_the_property_agent_as_owners_agent_and_its_creator_as_tenants_agent(): void
    {
        $this->capture()->assertSessionDoesntHaveErrors();

        $lease = $this->captured();
        $this->assertSame($this->retha->id, $lease->owner_agent_user_id, "owner's agent = the agent who lists the property");
        $this->assertSame($this->admin->id, $lease->tenant_agent_user_id, "tenant's agent = whoever created the lease when no application led to it");
    }

    public function test_the_tenants_agent_is_the_agent_who_sent_out_the_application_not_the_listing_agent(): void
    {
        $application = $this->application(['created_by_user_id' => $this->maggie->id]);
        $this->capture(['rental_application_id' => $application->id, 'rent_above_approved_reason' => 'agreed'])->assertSessionDoesntHaveErrors();

        $lease = $this->captured();
        $this->assertSame($this->retha->id, $lease->owner_agent_user_id, 'Retha advertises the property — she is the owner\'s agent');
        $this->assertSame($this->maggie->id, $lease->tenant_agent_user_id, 'Retha is not automatically the tenant\'s agent — Maggie sent the application');

        $created = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->firstOrFail();
        $this->assertSame('property_agent', $created->metadata['owner_agent_rule']);
        $this->assertSame('application_sender', $created->metadata['tenant_agent_rule']);
    }

    public function test_when_the_sender_has_left_the_agent_who_approved_the_application_is_the_tenants_agent(): void
    {
        $application = $this->application(['created_by_user_id' => $this->gone->id]);
        RentalApplicationStatusHistory::record($application, 'under_assessment', 'approved', $this->zane);
        RentalApplicationStatusHistory::record($application, 'sent', 'in_progress', $this->maggie);

        $defaults = app(LeaseAgentService::class)->defaultsForNewLease($this->property, $application, $this->admin->id);

        $this->assertSame($this->zane->id, $defaults['tenant']['id'], 'the approval wins over a later, lesser status change');
        $this->assertSame('application_approver', $defaults['tenant']['rule']);
    }

    public function test_with_no_approval_on_record_the_agent_who_last_processed_the_application_is_used(): void
    {
        $application = $this->application(['created_by_user_id' => $this->gone->id, 'status' => 'under_assessment']);
        RentalApplicationStatusHistory::record($application, 'in_progress', 'under_assessment', $this->zane);

        $defaults = app(LeaseAgentService::class)->defaultsForNewLease($this->property, $application, $this->admin->id);

        $this->assertSame($this->zane->id, $defaults['tenant']['id']);
        $this->assertSame('application_approver', $defaults['tenant']['rule']);
    }

    public function test_when_nobody_on_the_application_qualifies_the_lease_creator_is_the_tenants_agent(): void
    {
        $application = $this->application(['created_by_user_id' => $this->gone->id]);

        $defaults = app(LeaseAgentService::class)->defaultsForNewLease($this->property, $application, $this->admin->id);

        $this->assertSame($this->admin->id, $defaults['tenant']['id']);
        $this->assertSame('lease_creator', $defaults['tenant']['rule']);
    }

    public function test_a_property_whose_agent_has_left_gives_the_creator_as_owners_agent_and_both_may_be_the_same_person(): void
    {
        // properties.agent_id is NOT NULL, so "no agent" is a property whose agent has since left.
        $this->property->forceFill(['agent_id' => $this->gone->id])->saveQuietly();
        $defaults = app(LeaseAgentService::class)->defaultsForNewLease($this->property->fresh(), null, $this->admin->id);

        $this->assertSame($this->admin->id, $defaults['owner']['id']);
        $this->assertSame('lease_creator', $defaults['owner']['rule']);
        $this->assertSame($this->admin->id, $defaults['tenant']['id'], 'they may be the same person');
    }

    public function test_an_agent_who_left_or_belongs_to_another_agency_is_never_a_default(): void
    {
        $this->property->forceFill(['agent_id' => $this->stranger->id])->saveQuietly();
        $application = $this->application(['created_by_user_id' => $this->stranger->id]);

        $defaults = app(LeaseAgentService::class)->defaultsForNewLease($this->property->fresh(), $application, $this->admin->id);

        $this->assertSame($this->admin->id, $defaults['owner']['id']);
        $this->assertSame($this->admin->id, $defaults['tenant']['id']);
        $this->assertNotContains($this->stranger->id, [$defaults['owner']['id'], $defaults['tenant']['id']]);
    }

    public function test_the_capture_screen_offers_both_agents_preselected_from_the_defaults(): void
    {
        $application = $this->application(['created_by_user_id' => $this->maggie->id]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create', [
            'property_id' => $this->property->id, 'rental_application_id' => $application->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="lease-agents-fields"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->retha->id . '"\s+selected>Retha Listing/', $html, "owner's agent preselected");
        $this->assertMatchesRegularExpression('/<option value="' . $this->maggie->id . '"\s+selected>Maggie Tenant/', $html, "tenant's agent preselected");
        $this->assertStringNotContainsString('Stranger Danger', $html, 'another agency\'s people are never offered');
        $this->assertStringNotContainsString('Gone Gary', $html, 'someone who has left is never offered');
    }

    public function test_the_property_picker_hands_the_screen_the_properties_agent(): void
    {
        $json = $this->actingAs($this->admin)->getJson(route('corex.leases.search-rental-properties', ['q' => 'Margate']))->assertOk()->json();

        $this->assertSame($this->retha->id, $json[0]['agent_id']);
    }

    // ═══ 2. choosing them on the capture screen ═════════════════════════════════════════════

    public function test_agents_chosen_on_the_screen_are_used_and_the_same_person_may_hold_both_sides(): void
    {
        $this->capture(['owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->zane->id])->assertSessionDoesntHaveErrors();

        $lease = $this->captured();
        $this->assertSame($this->zane->id, $lease->owner_agent_user_id);
        $this->assertSame($this->zane->id, $lease->tenant_agent_user_id);
        $this->assertSame('chosen_on_screen', LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->firstOrFail()->metadata['owner_agent_rule']);
    }

    public function test_a_blank_side_takes_the_default_and_the_other_side_keeps_what_was_chosen(): void
    {
        $this->capture(['owner_agent_user_id' => '', 'tenant_agent_user_id' => $this->zane->id])->assertSessionDoesntHaveErrors();

        $lease = $this->captured();
        $this->assertSame($this->retha->id, $lease->owner_agent_user_id);
        $this->assertSame($this->zane->id, $lease->tenant_agent_user_id);
    }

    public function test_another_agencys_agent_a_deactivated_one_and_a_made_up_id_are_refused_and_nothing_is_created(): void
    {
        foreach ([$this->stranger->id, $this->gone->id, 99999999, 'abc'] as $bad) {
            $this->capture(['owner_agent_user_id' => $bad])->assertSessionHasErrors('owner_agent_user_id');
            $this->capture(['tenant_agent_user_id' => $bad])->assertSessionHasErrors('tenant_agent_user_id');
        }
        $this->assertSame(0, Lease::withoutGlobalScopes()->count());
    }

    // ═══ 3. changing them — and the history ═════════════════════════════════════════════════

    public function test_changing_an_agent_updates_the_lease_and_logs_who_from_to_and_when(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id,
        ])->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success', 'Agents updated.');

        $lease->refresh();
        $this->assertSame($this->retha->id, $lease->owner_agent_user_id);
        $this->assertSame($this->maggie->id, $lease->tenant_agent_user_id);

        $events = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENT_CHANGED)->get();
        $this->assertCount(1, $events, 'only the side that changed is logged');
        $event = $events->first();
        $this->assertSame($this->admin->id, $event->actor_user_id, 'who');
        $this->assertSame('tenant', $event->metadata['side']);
        $this->assertSame($this->admin->id, $event->metadata['from_user_id'], 'from');
        $this->assertSame('Adam Admin', $event->metadata['from_name']);
        $this->assertSame($this->maggie->id, $event->metadata['to_user_id'], 'to');
        $this->assertSame('Maggie Tenant', $event->metadata['to_name']);
        $this->assertNotNull($event->occurred_at, 'when');
        $this->assertSame("Tenant's agent changed: Adam Admin → Maggie Tenant", $event->description);
    }

    public function test_changing_both_agents_logs_two_rows_and_the_change_shows_in_the_tenancy_log(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->maggie->id,
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENT_CHANGED)->count());

        $log = $this->actingAs($this->admin)->getJson(route('v1.leases.tenancy-log', $lease))->assertOk()->json('data');
        $this->assertStringContainsString("Owner's agent changed: Retha Listing → Zane Approver", json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_saving_the_same_agents_again_changes_and_logs_nothing(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id,
        ])->assertSessionHas('success', 'The agents were already set that way.');

        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENT_CHANGED)->count());
    }

    public function test_a_change_to_someone_outside_the_agency_or_to_no_one_is_refused_and_leaves_the_lease_alone(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);

        foreach ([$this->stranger->id, $this->gone->id, 99999999] as $bad) {
            $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $lease), [
                'owner_agent_user_id' => $bad, 'tenant_agent_user_id' => $this->maggie->id,
            ])->assertSessionHasErrors('owner_agent_user_id');
        }
        $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $lease), ['owner_agent_user_id' => $this->retha->id])
            ->assertSessionHasErrors('tenant_agent_user_id');

        $lease->refresh();
        $this->assertSame($this->retha->id, $lease->owner_agent_user_id);
        $this->assertSame($this->maggie->id, $lease->tenant_agent_user_id);
        $this->assertSame(0, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENT_CHANGED)->count());
    }

    public function test_only_someone_who_may_edit_leases_can_change_the_agents(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);
        $viewer = $this->userWith(['leases.view'], 'all', 'lease_viewer');

        $this->actingAs($viewer)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->zane->id,
        ])->assertForbidden();

        $this->assertSame($this->retha->id, $lease->fresh()->owner_agent_user_id);

        // …and the viewer sees the agents but no Change button or form.
        $html = $this->actingAs($viewer)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('Retha Listing', $html);
        $this->assertStringNotContainsString('data-qa="lease-agents-change"', $html);
        $this->assertStringNotContainsString('data-qa="lease-agents-form"', $html);
    }

    public function test_another_agencys_lease_cannot_be_changed_by_id(): void
    {
        $rivalBranch = Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id]);

        $this->actingAs($rivalAdmin)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $rivalAdmin->id, 'tenant_agent_user_id' => $rivalAdmin->id,
        ])->assertNotFound();

        $this->assertSame($this->retha->id, $lease->fresh()->owner_agent_user_id);
    }

    public function test_the_lease_screen_shows_both_agents_and_the_change_form_lists_this_agencys_active_people_only(): void
    {
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="lease-agents-card"', $html);
        $this->assertMatchesRegularExpression('/data-qa="lease-agent-owner">\s*<span[^>]*>Owner&#039;s agent:<\/span>\s*Retha Listing/', $html);
        $this->assertMatchesRegularExpression('/data-qa="lease-agent-tenant">\s*<span[^>]*>Tenant&#039;s agent:<\/span>\s*Maggie Tenant/', $html);
        $this->assertStringContainsString('data-qa="lease-agents-form"', $html);
        $this->assertStringContainsString('Zane Approver', $html, 'any active user of the agency can be chosen');
        $this->assertStringNotContainsString('Stranger Danger', $html);
        $this->assertStringNotContainsString('Gone Gary', $html);
    }

    public function test_the_change_form_lists_the_leases_own_branch_first(): void
    {
        $elsewhere = $this->person('Ellen Elsewhere', 'agent', true, $this->otherBranch);
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $options = app(LeaseAgentService::class)->selectableAgents($this->agency->id, $lease->branch_id);

        $this->assertTrue($options->first()['in_branch']);
        $this->assertSame($elsewhere->id, $options->last()['id'], 'other branches come after the lease\'s own');
        $this->assertFalse($options->last()['in_branch']);
    }

    public function test_a_lease_never_given_agents_shows_the_default_rules_answer_marked_as_default(): void
    {
        $lease = $this->lease(); // created before leases carried agents: both columns empty

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Owner&#039;s agent:<\/span>\s*Retha Listing\s*<span[^>]*>\(default\)/', $html);
        $this->assertMatchesRegularExpression('/Tenant&#039;s agent:<\/span>\s*Adam Admin\s*<span[^>]*>\(default\)/', $html);
    }

    // ═══ 4. the lists ═══════════════════════════════════════════════════════════════════════

    public function test_the_leases_list_has_an_agents_column_and_the_export_carries_both(): void
    {
        $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Agents</th>', $html);
        $this->assertStringContainsString('Retha Listing', $html);
        $this->assertStringContainsString('Maggie Tenant', $html);

        $csv = $this->actingAs($this->admin)->get(route('corex.leases.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString("Owner's agent", $csv);
        $this->assertStringContainsString('Retha Listing', $csv);
        $this->assertStringContainsString('Maggie Tenant', $csv);
    }

    // ═══ 5. "own" — creator OR either agent ═════════════════════════════════════════════════

    public function test_own_scope_now_means_created_by_me_or_i_am_the_owners_agent_or_the_tenants_agent(): void
    {
        $viewer = $this->userWith(['leases.view'], 'own', 'own_lease_viewer');
        $other = $this->person('Someone Else');

        $created = $this->lease(['created_by_user_id' => $viewer->id, 'owner_agent_user_id' => $other->id, 'tenant_agent_user_id' => $other->id]);
        $asOwnerAgent = $this->lease(['owner_agent_user_id' => $viewer->id, 'tenant_agent_user_id' => $other->id]);
        $asTenantAgent = $this->lease(['owner_agent_user_id' => $other->id, 'tenant_agent_user_id' => $viewer->id]);
        $unrelated = $this->lease(['owner_agent_user_id' => $other->id, 'tenant_agent_user_id' => $other->id]);

        $this->actingAs($viewer);
        $visible = Lease::query()->visibleTo($viewer)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$created->id, $asOwnerAgent->id, $asTenantAgent->id], $visible);
        $this->assertNotContains($unrelated->id, $visible);

        // The list screen and the per-record guard agree with the query (a direct URL grants no more, no less).
        $this->get(route('corex.leases.show', $asOwnerAgent))->assertOk();
        $this->get(route('corex.leases.show', $asTenantAgent))->assertOk();
        $this->get(route('corex.leases.show', $unrelated))->assertForbidden();
    }

    public function test_changing_an_agent_moves_the_lease_in_and_out_of_someones_own_list(): void
    {
        $viewer = $this->userWith(['leases.view'], 'own', 'own_lease_viewer');
        $editor = $this->userWith(['leases.view', 'leases.create'], 'all', 'lease_editor_all');
        $lease = $this->lease(['owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id, 'created_by_user_id' => $this->admin->id]);

        $this->actingAs($viewer)->get(route('corex.leases.show', $lease))->assertForbidden();

        // One PHP process serves many requests in a test; real requests are separate processes, so drop the in-process permission cache.
        PermissionService::clearCache();
        $this->actingAs($editor)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $viewer->id,
        ])->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success', 'Agents updated.');
        $this->assertSame($viewer->id, $lease->fresh()->tenant_agent_user_id);

        PermissionService::clearCache();
        $this->actingAs($viewer)->get(route('corex.leases.show', $lease))->assertOk();

        // …and out again.
        PermissionService::clearCache();
        $this->actingAs($editor)->put(route('corex.leases.agents.update', $lease), [
            'owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id,
        ])->assertSessionDoesntHaveErrors();
        PermissionService::clearCache();
        $this->actingAs($viewer)->get(route('corex.leases.show', $lease))->assertForbidden();
    }

    // ═══ 6. renewal ═════════════════════════════════════════════════════════════════════════

    public function test_a_renewal_carries_both_agents_forward_not_the_default_rules_answer(): void
    {
        $current = $this->lease(['owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_only', 'rental_amount' => 12000, 'start_date' => '2027-06-01', 'end_date' => '2028-05-31',
        ])->assertSessionDoesntHaveErrors();

        $renewal = Lease::withoutGlobalScopes()->where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame($this->zane->id, $renewal->owner_agent_user_id, 'not reset to the property\'s agent');
        $this->assertSame($this->maggie->id, $renewal->tenant_agent_user_id, 'not reset to whoever clicked renew');
    }

    public function test_a_renewal_may_change_an_agent_on_the_capture_screen(): void
    {
        $current = $this->lease(['owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $this->zane->id . '"\s+selected>Zane Approver/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->maggie->id . '"\s+selected>Maggie Tenant/', $html);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_only', 'rental_amount' => 12000, 'start_date' => '2027-06-01', 'end_date' => '2028-05-31',
            'tenant_agent_user_id' => $this->retha->id,
        ])->assertSessionDoesntHaveErrors();

        $renewal = Lease::withoutGlobalScopes()->where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame($this->zane->id, $renewal->owner_agent_user_id);
        $this->assertSame($this->retha->id, $renewal->tenant_agent_user_id);
    }

    public function test_every_renewal_path_carries_the_agents_including_the_service_called_directly(): void
    {
        $current = $this->lease(['owner_agent_user_id' => $this->zane->id, 'tenant_agent_user_id' => $this->maggie->id]);

        $term = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => '2027-06-01', 'end_date' => '2028-05-31', 'rental_amount' => 12000,
        ], $this->admin);

        $this->assertSame($this->zane->id, $term->owner_agent_user_id);
        $this->assertSame($this->maggie->id, $term->tenant_agent_user_id);
    }

    public function test_renewing_a_lease_that_never_had_agents_gives_the_renewal_the_default_rules_answer(): void
    {
        $current = $this->lease(); // columns empty

        $term = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => '2027-06-01', 'end_date' => '2028-05-31', 'rental_amount' => 12000,
        ], $this->zane);

        $this->assertSame($this->retha->id, $term->owner_agent_user_id);
        $this->assertSame($this->admin->id, $term->tenant_agent_user_id, 'the old lease\'s creator — not the person who happened to click renew');
    }

    // ═══ 7. the back-fill ═══════════════════════════════════════════════════════════════════

    public function test_the_backfill_dry_run_writes_nothing_and_reports_the_rules(): void
    {
        $lease = $this->lease();

        Artisan::call('leases:backfill-agents', ['--dry-run' => true, '--agency' => $this->agency->id]);
        $out = Artisan::output();

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('property_agent', $out);
        $this->assertStringContainsString('lease_creator', $out);
        $this->assertNull($lease->fresh()->owner_agent_user_id);
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED)->count());
    }

    public function test_the_backfill_fills_only_empty_sides_by_the_same_rules_logs_it_and_does_not_touch_updated_at(): void
    {
        $application = $this->application(['created_by_user_id' => $this->maggie->id]);
        $fromApplication = $this->lease(['rental_application_id' => $application->id, 'source' => 'rental_application']);
        $plain = $this->lease();
        $chosen = $this->lease(['owner_agent_user_id' => $this->zane->id]); // owner chosen, tenant empty
        $before = DB::table('leases')->where('id', $plain->id)->value('updated_at');

        $this->travel(2)->days();
        Artisan::call('leases:backfill-agents', ['--agency' => $this->agency->id]);
        $out = Artisan::output();

        $this->assertSame($this->retha->id, $fromApplication->fresh()->owner_agent_user_id);
        $this->assertSame($this->maggie->id, $fromApplication->fresh()->tenant_agent_user_id, 'the application\'s sender');
        $this->assertSame($this->retha->id, $plain->fresh()->owner_agent_user_id);
        $this->assertSame($this->admin->id, $plain->fresh()->tenant_agent_user_id, 'no application → the lease creator');
        $this->assertSame($this->zane->id, $chosen->fresh()->owner_agent_user_id, 'a chosen side is never overwritten');
        $this->assertSame($this->admin->id, $chosen->fresh()->tenant_agent_user_id, 'its empty side is filled');
        $this->assertEquals($before, DB::table('leases')->where('id', $plain->id)->value('updated_at'), 'soft: updated_at untouched');

        $event = LeaseEvent::where('lease_id', $plain->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED)->firstOrFail();
        $this->assertSame('property_agent', $event->metadata['owner']['rule']);
        $this->assertSame('lease_creator', $event->metadata['tenant']['rule']);
        $this->assertStringContainsString('application_sender', $out);

        // Re-running finds nothing left to do.
        Artisan::call('leases:backfill-agents', ['--agency' => $this->agency->id]);
        $this->assertSame(1, LeaseEvent::where('lease_id', $plain->id)->where('event_type', LeaseEvent::TYPE_LEASE_AGENTS_BACKFILLED)->count());
    }

    public function test_the_backfill_never_touches_another_agencys_leases_when_limited_to_one_agency(): void
    {
        $rivalBranch = Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $rivalUser = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'agent', 'is_active' => true]);
        $rivalProperty = Property::create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'agent_id' => $rivalUser->id, 'title' => 'Rival flat', 'status' => 'active', 'listing_type' => 'rental']);
        $rivalLease = Lease::create([
            'agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'property_id' => $rivalProperty->id, 'status' => 'active',
            'rental_amount' => 5000, 'start_date' => '2026-01-01', 'source' => 'manual', 'created_by_user_id' => $rivalUser->id,
        ]);
        $this->lease();

        Artisan::call('leases:backfill-agents', ['--agency' => $this->agency->id]);

        $this->assertNull($rivalLease->fresh()->owner_agent_user_id);

        // …and without the filter it fills the rival's from the rival's own people only.
        Artisan::call('leases:backfill-agents');
        $this->assertSame($rivalUser->id, $rivalLease->fresh()->owner_agent_user_id);
        $this->assertSame($rivalUser->id, $rivalLease->fresh()->tenant_agent_user_id);
    }

    public function test_the_backfill_can_be_reverted_but_only_for_what_nobody_has_changed_since(): void
    {
        $untouched = $this->lease();
        $changed = $this->lease();
        Artisan::call('leases:backfill-agents', ['--agency' => $this->agency->id]);

        $this->actingAs($this->admin)->put(route('corex.leases.agents.update', $changed), [
            'owner_agent_user_id' => $this->retha->id, 'tenant_agent_user_id' => $this->maggie->id,
        ])->assertSessionDoesntHaveErrors();

        Artisan::call('leases:backfill-agents', ['--revert' => true, '--agency' => $this->agency->id]);

        $this->assertNull($untouched->fresh()->owner_agent_user_id);
        $this->assertNull($untouched->fresh()->tenant_agent_user_id);
        $this->assertSame($this->maggie->id, $changed->fresh()->tenant_agent_user_id, 'a side someone changed after the back-fill stays as chosen');
        $this->assertNull($changed->fresh()->owner_agent_user_id, 'the owner side nobody changed goes back to empty');
    }
}
