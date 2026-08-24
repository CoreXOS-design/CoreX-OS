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
 * AUDIT 2026-07-26 — the ratchet for the post-ship assistant audit
 * (.ai/audits/2026-07-26-assistant-feature-postship-audit.md).
 *
 * Written as repro tests FIRST (both red), then turned green by the fixes. They stay as the guard:
 *
 *   F1 — the control-page toggle `can_manage_my_records` ("{assistant} can edit & delete my
 *        records, not just add them") shipped on the page with NO enforcement behind it. An agent
 *        who switched it off had restricted nothing. A visible switch that does nothing is worse
 *        than no switch, because it stops the agent looking for the real control.
 *
 *   F2 — DocumentController::edit() was the one method left on the pre-H5 bare
 *        `owner_id === $user->id` test, while store() files an assistant's document under the
 *        AGENT. The assistant was 403'd on the redirect that follows their own create.
 *
 *   F5 — there was no update path for an assistant at all (list/view/create/reassign/revoke/
 *        restore only), and assistants are excluded from the User Management directory — so a
 *        typo in a name or Title was permanent through the UI.
 */
final class AssistantAuditReproTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true, 'feature_docuperfect' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);

        $this->agentA    = $this->makeUser('Sarah', 'agent');
        $this->assistant = $this->makeUser('Thandi', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
    }

    // ── F2 ───────────────────────────────────────────────────────────────────

    public function test_assistant_can_open_a_document_filed_under_their_agent(): void
    {
        $this->grantDocuments();
        $doc = $this->agentOwnedDocument();
        $this->reset();

        // Asserts AUTHORIZATION, not the DocuPerfect editor's rendering — a bare Document has no
        // template and that view wants one. 403-or-not is precisely what this finding was about,
        // and it is the same idiom AssistantDocumentScopingTest uses.
        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->get(route('docuperfect.documents.edit', $doc->id))->status());
    }

    // ── F1 ───────────────────────────────────────────────────────────────────

    public function test_can_manage_my_records_off_blocks_the_assistant_from_editing(): void
    {
        $this->grantDocuments();
        $this->assignment->forceFill(['can_manage_my_records' => false])->save();
        $doc = $this->agentOwnedDocument();
        $this->reset();

        $this->actingAs($this->assistant)
            ->post(route('docuperfect.documents.rename', $doc->id), ['name' => 'Renamed by assistant'])
            ->assertForbidden();
    }

    public function test_can_manage_my_records_off_blocks_delete(): void
    {
        $this->grant('documents.archive');
        $this->grantDocuments();
        $this->assignment->forceFill(['can_manage_my_records' => false])->save();
        $doc = $this->agentOwnedDocument();
        $this->reset();

        $this->actingAs($this->assistant)
            ->delete(route('docuperfect.documents.destroy', $doc->id))
            ->assertForbidden();
    }

    /**
     * The toggle restricts EDIT and DELETE — not sight. An assistant who cannot change the
     * agent's book must still be able to work in it, or the setting is a lockout rather than
     * the "add and view, don't change" it says on the page.
     */
    public function test_can_manage_my_records_off_still_allows_viewing(): void
    {
        $this->grantDocuments();
        $this->assignment->forceFill(['can_manage_my_records' => false])->save();
        $doc = $this->agentOwnedDocument();
        $this->reset();

        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->get(route('docuperfect.documents.edit', $doc->id))->status());
    }

    /** The default is ON — an assistant nobody has restricted still works normally. */
    public function test_editing_is_allowed_by_default(): void
    {
        $this->grantDocuments();
        $doc = $this->agentOwnedDocument();
        $this->reset();

        $this->assertTrue($this->assignment->refresh()->can_manage_my_records, 'edit/delete is ON by default');

        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->post(route('docuperfect.documents.rename', $doc->id), ['name' => 'Renamed by assistant'])
            ->status());
    }

    // ── F5 ───────────────────────────────────────────────────────────────────

    public function test_an_admin_can_correct_an_assistants_details_and_title(): void
    {
        $admin = $this->makeUser('Johan', 'admin');
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'assistants.create', 'agency_id' => $this->agency->id],
            ['scope' => null],
        );
        $this->reset();

        $this->actingAs($admin)
            ->put(route('admin.assistants.update', $this->assignment), [
                'name'    => 'Thandiwe',
                'surname' => 'Mkhize',
                'email'   => 'thandiwe@hfcoastal.co.za',
                'cell'    => '083 555 0142',
                'title'   => 'Receptionist',
            ])
            ->assertRedirect(route('admin.assistants.show', $this->assignment));

        $this->assistant->refresh();
        $this->assertSame('Thandiwe Mkhize', $this->assistant->name);
        $this->assertSame('thandiwe@hfcoastal.co.za', $this->assistant->email);
        $this->assertSame('Receptionist', $this->assistant->assistant_title);
        // The identity pin still holds through any write path (AT-267 audit 2026-07-21).
        $this->assertSame('assistant', $this->assistant->role);
        $this->assertFalse((bool) $this->assistant->is_admin);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    /** A document exactly as DocumentController::store() files one for an assistant. */
    private function agentOwnedDocument(): Document
    {
        return Document::create([
            'name'      => 'Mandate ' . uniqid(),
            'owner_id'  => $this->agentA->id,   // = $assistant->ownershipUserId()
            'branch_id' => $this->branch->id,
        ]);
    }

    private function grantDocuments(): void
    {
        $this->grant('access_docuperfect');
        $this->grant('documents.edit');
        $this->grant('documents.view', 'own');
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
