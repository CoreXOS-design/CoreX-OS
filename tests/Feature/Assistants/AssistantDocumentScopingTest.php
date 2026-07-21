<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 H5 — DocuPerfect document mutators authorized on a permission KEY or a bare owner_id===self
 * check, so a documents.* holder / an assistant could mutate ANY agent's document by id. The
 * per-record guard (AuthorizesDocumentAccess) pins an assistant to the assigned agent's OWN docs.
 */
final class AssistantDocumentScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;
    private User $agentB;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true, 'feature_docuperfect' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agentA    = $this->makeUser('Sarah', 'agent');
        $this->agentB    = $this->makeUser('Pieter', 'agent');
        $this->assistant = $this->makeUser('Thandi', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_assistant_cannot_destroy_another_agents_document(): void
    {
        $this->grant('access_docuperfect');
        $this->grant('documents.archive');
        $this->grant('documents.view', 'own');
        $mine   = $this->docFor($this->agentA->id);
        $theirs = $this->docFor($this->agentB->id);
        $this->reset();

        $this->actingAs($this->assistant)
            ->delete(route('docuperfect.documents.destroy', $theirs->id))
            ->assertForbidden();

        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->delete(route('docuperfect.documents.destroy', $mine->id))->status());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function docFor(int $ownerId): Document
    {
        return Document::create([
            'name'      => 'Doc ' . uniqid(),
            'owner_id'  => $ownerId,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function grant(string $key, ?string $scope = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }
}
