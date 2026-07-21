<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Http\Middleware\DenyAssistantDownload;
use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AT-267 — the "download documents" toggle (assistant_assignments.can_download_documents).
 *
 * The DenyAssistantDownload middleware is applied to every authenticated document-download route.
 * When the toggle is off, an assistant is 403'd on any download; when on (the default), they pass.
 * A non-assistant is never affected.
 */
final class AssistantDocumentDownloadGateTest extends TestCase
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
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = $this->makeUser('Sarah Nkosi', 'agent');
        $this->assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_the_gate_blocks_an_assistant_when_the_toggle_is_off(): void
    {
        $this->assignment->forceFill(['can_download_documents' => false])->save();

        $this->expectException(HttpException::class);
        $this->runGate($this->freshAssistant());
    }

    public function test_the_gate_lets_an_assistant_through_when_the_toggle_is_on(): void
    {
        $this->assignment->forceFill(['can_download_documents' => true])->save();

        $response = $this->runGate($this->freshAssistant());

        $this->assertSame(200, $response->getStatusCode(), 'Toggle on → the download must proceed.');
    }

    public function test_the_gate_fails_closed_with_no_active_assignment(): void
    {
        // A raw is_assistant flag with no live assignment must deny — never fall open.
        $orphan = $this->makeUser('Lindiwe Dube', 'assistant', isAssistant: true);

        $this->expectException(HttpException::class);
        $this->runGate(User::find($orphan->id));
    }

    public function test_a_normal_user_is_never_affected_by_the_gate(): void
    {
        $response = $this->runGate($this->agent);

        $this->assertSame(200, $response->getStatusCode(), 'A non-assistant download must be untouched.');
    }

    /**
     * The view-layer helper (used to hide download affordances the middleware can't gate, e.g.
     * direct public-disk URLs) mirrors the gate exactly.
     */
    public function test_can_download_documents_helper_mirrors_the_toggle(): void
    {
        $this->assertTrue($this->agent->canDownloadDocuments(), 'A non-assistant may always download.');

        $this->assignment->forceFill(['can_download_documents' => true])->save();
        $this->assertTrue($this->freshAssistant()->canDownloadDocuments(), 'Toggle on → helper true.');

        $this->assignment->forceFill(['can_download_documents' => false])->save();
        $this->assertFalse($this->freshAssistant()->canDownloadDocuments(), 'Toggle off → helper false.');
    }

    /**
     * The wiring half: the middleware alias is actually attached to the real download routes, not
     * merely defined. If someone adds a new download route without the alias, that is a separate
     * gap — this proves the ones we gated stayed gated.
     */
    public function test_representative_download_routes_carry_the_gate(): void
    {
        $names = [
            'documents.library.download',
            'documents.shared-drive.files.download',
            'docuperfect.signatures.download',
            'corex.contacts.documents.download',
            // AT-267 H7 — newly gated download surfaces.
            'presentations.versions.complete-pack',
            'corex.viewing-packs.buyer-pack',
            'corex.viewing-packs.agent-sheet',
            'compliance.comm-archive.attachment',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Route {$name} should exist.");
            $this->assertContains(
                'deny_assistant_download',
                $route->gatherMiddleware(),
                "Route {$name} must carry the deny_assistant_download gate."
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function runGate(User $user)
    {
        $request = Request::create('/download', 'GET');
        $request->setUserResolver(fn () => $user);

        return (new DenyAssistantDownload())->handle($request, fn ($req) => response('ok', 200));
    }

    private function freshAssistant(): User
    {
        User::flushAssistantsEnabledCache();

        return User::find($this->assistant->id); // clean instance: activeAssistantAssignment() is memoised
    }

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }
}
