<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — Johan's rule, tested as an outsider would attack it: AN ASSISTANT MAY NEVER
 * CREATE A LISTING. Not by the matrix, not by URL, not by the mobile app, not by a portal
 * pull, not by an import.
 *
 * WHY A PERMISSION-KEY LOCK IS NOT ENOUGH, and why this file exists.
 *
 * The obvious implementation — deny `properties.create` — closes exactly ONE door. The audit
 * for this ticket walked every property-creation path in CoreX and found that the live create
 * gate is checked in a single place (the wizard), that `create_properties` / `publish_properties`
 * / `listings.create` are defined in config and checked NOWHERE, and that five creation paths
 * carry no permission key at all:
 *
 *   - POST /corex/properties                    (classic store — group only checks access_properties)
 *   - the wizard mutation routes                (photos / step / finalize)
 *   - POST /api/v1/mobile/properties + /images  (NO permission middleware whatsoever)
 *   - POST /api/v1/properties/pull-from-portal  (NO permission middleware)
 *   - POST /api/v1/prospecting/import           (NO permission middleware)
 *
 * An agent will quite reasonably grant their assistant `access_properties` — the assistant is
 * supposed to work the agent's listings. That single grant would have opened every one of the
 * above. So the lock is four layers, and this file walks the doors.
 *
 * Paths proven: the resolver denies every locked key · the classic store · the wizard start ·
 * the wizard draft · wizard photo upload · the sold-CSV import · the mobile API create · the
 * mobile image upload · the portal pull · the prospecting import · AND the mirror image — the
 * assistant CAN still read and edit the agent's listings, because a lock that breaks the job
 * is not a lock, it is a bug.
 */
final class AssistantCannotCreateListingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name'               => 'Home Finders Coastal',
            'slug'               => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent = User::factory()->create([
            'name' => 'Sarah Nkosi', 'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->assistant = User::factory()->create([
            'name' => 'Thandi Mokoena', 'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id, 'role' => 'assistant',
            'is_active' => true, 'is_assistant' => true,
        ]);

        $this->assignment = AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $this->assistant->id,
            'agent_user_id'     => $this->agent->id,
        ]);

        // The agent is a full agent — they CAN create listings, and they hand their assistant
        // the widest property access an agent can reasonably give. This is the realistic
        // configuration, and it is the one that must still hold the line.
        foreach (['access_properties', 'properties.create', 'properties.edit', 'import_listings', 'manage_p24'] as $key) {
            RolePermission::create([
                'role' => 'agent', 'permission_key' => $key,
                'agency_id' => $this->agency->id,
                'scope' => str_ends_with($key, '.view') ? 'own' : null,
            ]);
        }
        RolePermission::create([
            'role' => 'agent', 'permission_key' => 'properties.view',
            'agency_id' => $this->agency->id, 'scope' => 'own',
        ]);

        // The agent switches EVERYTHING on for the assistant — including, if the UI let them,
        // the create keys. The lock must not care.
        foreach (['access_properties', 'properties.create', 'properties.edit', 'properties.view', 'import_listings', 'manage_p24'] as $key) {
            AssistantAssignmentPermission::withoutEvents(fn () => AssistantAssignmentPermission::create([
                'agency_id'               => $this->agency->id,
                'assistant_assignment_id' => $this->assignment->id,
                'permission_key'          => $key,
                'granted'                 => true,
                'scope'                   => $key === 'properties.view' ? 'own' : null,
            ]));
        }

        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    private function asAssistant(): self
    {
        $this->actingAs(User::find($this->assistant->id));

        return $this;
    }

    private function agentsListing(): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id'  => $this->agent->id,  'title' => 'Marine Drive',
            'street_name' => 'Marine Drive', 'street_number' => '14',
            'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);
    }

    // ── Layer 1: the resolver ───────────────────────────────────────

    public function test_the_resolver_denies_every_locked_key_despite_a_fully_granted_matrix(): void
    {
        $assistant = User::find($this->assistant->id);

        // Both halves of the intersection say yes for these. The lock says no.
        $this->assertTrue($this->agent->fresh()->hasPermission('properties.create'));
        $this->assertFalse($assistant->hasPermission('properties.create'));
        $this->assertFalse($assistant->hasPermission('import_listings'));
        $this->assertFalse($assistant->hasPermission('manage_p24'));

        // ...but the things they need to do the job survive.
        $this->assertTrue($assistant->hasPermission('access_properties'));
        $this->assertTrue($assistant->hasPermission('properties.edit'));
    }

    // ── Layer 2: the routes with no permission key ──────────────────

    public function test_the_classic_property_store_is_blocked(): void
    {
        $before = Property::count();

        $this->asAssistant()
            ->post(route('corex.properties.store'), [
                'title' => 'Smuggled listing', 'street_name' => 'Panorama Parade',
                'street_number' => '9', 'suburb' => 'Uvongo', 'price' => 2450000,
            ])
            ->assertForbidden();

        $this->assertSame($before, Property::count(), 'No property may reach the books.');
    }

    public function test_the_wizard_is_blocked(): void
    {
        $this->asAssistant()->get(route('corex.properties.wizard'))->assertForbidden();
        $this->asAssistant()->post(route('corex.properties.wizard.draft'))->assertForbidden();
    }

    public function test_the_wizard_photo_upload_is_blocked(): void
    {
        $property = $this->agentsListing();

        // Even against the AGENT'S OWN listing — because the wizard's photo/step/finalize path
        // is how a draft becomes a live listing.
        $this->asAssistant()
            ->post(route('corex.properties.wizard.photos', $property))
            ->assertForbidden();
    }

    public function test_the_sold_csv_import_is_blocked(): void
    {
        $this->asAssistant()
            ->post(route('corex.properties.import-sold.preview'))
            ->assertForbidden();
    }

    public function test_the_mobile_api_create_is_blocked(): void
    {
        $before = Property::count();

        $this->asAssistant()
            ->postJson(route('v1.mobile.properties.store'), [
                'title' => 'Smuggled via mobile', 'suburb' => 'Uvongo', 'price' => 1950000,
            ])
            ->assertForbidden();

        $this->assertSame($before, Property::count());
    }

    public function test_the_mobile_api_image_upload_is_blocked(): void
    {
        $property = $this->agentsListing();

        $this->asAssistant()
            ->postJson(route('v1.mobile.properties.images.upload', $property))
            ->assertForbidden();
    }

    public function test_the_portal_pull_is_blocked(): void
    {
        $this->asAssistant()
            ->postJson(route('v1.properties.pull-from-portal'), ['url' => 'https://www.property24.com/listing/123456'])
            ->assertForbidden();
    }

    public function test_the_prospecting_import_is_blocked(): void
    {
        $this->asAssistant()
            ->postJson(route('v1.prospecting.import'), ['listings' => []])
            ->assertForbidden();
    }

    // ── The mirror image: the lock must not break the job ───────────

    public function test_an_assistant_can_still_read_the_agents_listings(): void
    {
        $property = $this->agentsListing();

        // A lock that stops the assistant working the agent's book is not a lock, it is a bug.
        // The whole point of the feature is that they CAN do this.
        $this->asAssistant()
            ->get(route('corex.properties.show', $property))
            ->assertSuccessful();
    }

    public function test_an_assistant_can_still_edit_the_agents_listing(): void
    {
        $property = $this->agentsListing();

        $response = $this->asAssistant()->get(route('corex.properties.edit', $property));

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirect(),
            'An assistant must be able to open the edit screen for their agent\'s listing — that is the job.'
        );

        // `corex.properties.update` is on the middleware's explicit ASSISTANT_MAY list.
        $this->asAssistant()
            ->put(route('corex.properties.update', $property), [
                'title'       => 'Marine Drive — price reduced',
                'street_name' => 'Marine Drive',
                'suburb'      => 'Margate',
            ])
            ->assertStatus(302); // a normal redirect, NOT a 403
    }

    // ── A normal agent is untouched ─────────────────────────────────

    public function test_the_agent_themselves_can_still_create_a_listing(): void
    {
        // The middleware must be a no-op for every non-assistant in the system.
        $this->actingAs($this->agent->fresh());

        $response = $this->get(route('corex.properties.wizard'));

        $this->assertNotSame(403, $response->status(), 'The lock must never touch a real agent.');
    }
}
