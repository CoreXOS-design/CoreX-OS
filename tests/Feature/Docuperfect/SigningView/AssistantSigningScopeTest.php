<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
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
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AT-267 H5 — SignatureController::authorizeDocument guards the ENTIRE signing pipeline. It used VIEW
 * scope + owner_id===$user->id, which let an assistant of a branch-manager sign ANY branch document
 * (and wrongly blocked the assistant on the agent's OWN document). It now uses the MUTATION scope
 * keyed on dataIdentityIds — an assistant may sign exactly the assigned agent's own documents.
 *
 * Lives under SigningView/ to satisfy the e-sign pipeline gate (dev-check.ps1).
 */
final class AssistantSigningScopeTest extends TestCase
{
    use RefreshDatabase;

    private function invokeGuard(User $user, Document $document): void
    {
        $controller = app(SignatureController::class);
        $method = (new ReflectionClass($controller))->getMethod('authorizeDocument');
        $method->setAccessible(true);
        $method->invoke($controller, $user, $document);
    }

    public function test_an_assistant_may_sign_the_agents_own_document_but_not_another_agents(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $agency->id]);

        $agentA    = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $agentB    = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $assistant = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $assignment = AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        // The agent (and, via the matrix, the assistant) has BRANCH documents scope — the exact case
        // that used to leak: the old branch arm let the assistant sign any branch document.
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'documents.view', 'agency_id' => $agency->id], ['scope' => 'branch']);
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $assignment->id, 'permission_key' => 'documents.view'],
            ['agency_id' => $agency->id, 'granted' => true, 'scope' => 'branch'],
        );
        PermissionService::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();

        $agentDoc = Document::create(['name' => 'Agent doc', 'owner_id' => $agentA->id, 'branch_id' => $branch->id]);
        $rivalDoc = Document::create(['name' => 'Rival doc', 'owner_id' => $agentB->id, 'branch_id' => $branch->id]);

        $assistant = User::find($assistant->id);

        // The assigned agent's own document — allowed (no exception).
        $this->invokeGuard($assistant, $agentDoc);
        $this->assertTrue(true);

        // A branch colleague's document — refused, despite the branch-scope breadth.
        $this->expectException(HttpException::class);
        $this->invokeGuard($assistant, $rivalDoc);
    }
}
