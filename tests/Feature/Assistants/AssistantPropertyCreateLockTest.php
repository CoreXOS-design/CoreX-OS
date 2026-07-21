<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AT-267 C2 — the MODEL-LEVEL backstop of the property-upload lock. An assistant may NEVER bring a
 * listing onto the books on ANY path. Route middleware + the resolver locked-set cover the known
 * entry points, but promote-to-stock / outreach-compose / legacy mobile create kept slipping the
 * hand-maintained route list. Property::creating refuses any create fired while an assistant is the
 * acting user — closing the whole class regardless of which controller reached Property::create().
 */
final class AssistantPropertyCreateLockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = $this->makeUser('Sarah Nkosi', 'agent');
        $this->assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        PermissionService::clearCache();
        User::flushAssistantsEnabledCache();
    }

    public function test_an_assistant_cannot_create_a_property_by_any_path(): void
    {
        $this->actingAs($this->assistant);

        $this->expectException(HttpException::class);
        $this->makeProperty();
    }

    public function test_a_normal_agent_can_still_create_a_property(): void
    {
        $this->actingAs($this->agent);

        $property = $this->makeProperty();
        $this->assertTrue($property->exists);
    }

    public function test_non_authenticated_ingress_can_still_create(): void
    {
        // Imports / webhooks / seeders / queued jobs have no auth user — never blocked.
        $property = $this->makeProperty();
        $this->assertTrue($property->exists);
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id' => $this->agent->id, 'title' => 'Marine Drive', 'street_name' => 'Marine Drive',
            'street_number' => '14', 'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);
    }

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }
}
