<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20, finding C3: SignatureRequest carries no
 * agency_id, so its route-model binding is unscoped. sendReminder/wetInkReview/
 * wetInkFile/uploadOnBehalf/wetInkDecision checked {document} against the caller's
 * agency but never checked that the route-bound {signatureRequest} actually belongs
 * to that {document} — letting an Agency A user pair a legitimate document of their
 * own with a guessed/enumerated Agency B signatureRequest id to trigger a reminder/
 * resend email exposing Agency B's signer name, or read/upload Agency B's wet-ink
 * files. Fixed by SignatureController::authorizeSignatureRequestForDocument().
 *
 * Lives under SigningView/ to satisfy the e-sign pipeline gate (dev-check.ps1).
 */
final class CrossAgencySignatureRequestBindingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyWithSigningAgent(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'access_docuperfect', 'agency_id' => $agency->id],
            []
        );
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'documents.view', 'agency_id' => $agency->id],
            ['scope' => 'all']
        );
        PermissionService::clearCache();

        $agent = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'agent',
            'is_active' => true,
        ]);

        $document = Document::create([
            'name' => $label . ' document',
            'owner_id' => $agent->id,
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
        ]);

        // agency_id stamped explicitly: SignatureTemplate uses BelongsToAgency
        // and this row is created outside a request/auth context, so the
        // trait's auto-stamp-from-Auth::user() hook has nothing to stamp
        // from -- a real controller-created row would auto-stamp correctly.
        $template = SignatureTemplate::create([
            'document_id' => $document->id,
            'status' => SignatureTemplate::STATUS_SIGNING,
            'agency_id' => $agency->id,
        ]);

        $signatureRequest = SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => 'buyer',
            'signer_name' => $label . ' Signer',
            'signer_email' => strtolower($label) . '.signer@example.com',
            'token' => bin2hex(random_bytes(32)),
            'token_expires_at' => now()->addDays(7),
            'status' => 'waiting',
        ]);

        return compact('agency', 'branch', 'agent', 'document', 'template', 'signatureRequest');
    }

    public function test_agent_cannot_send_reminder_on_another_agencys_signature_request(): void
    {
        PermissionService::forceProductionPosture();

        $ownAgency = $this->makeAgencyWithSigningAgent('Own');
        $foreignAgency = $this->makeAgencyWithSigningAgent('Foreign');

        // postJson (not post) so a 404 renders as a JSON error response instead of
        // the HTML error view — the test env has no built Vite manifest, unrelated
        // to the isolation behaviour under test.
        $response = $this->actingAs($ownAgency['agent'])->postJson(route('docuperfect.signatures.sendReminder', [
            'document' => $ownAgency['document']->id,
            'signatureRequest' => $foreignAgency['signatureRequest']->id,
        ]));

        // Before the fix this returned a 302 redirect with the foreign agency's
        // signer_name in the flash message. It must now 404 — no reminder sent,
        // no signer identity disclosed.
        $response->assertNotFound();

        $this->assertDatabaseHas('signature_requests', [
            'id' => $foreignAgency['signatureRequest']->id,
            'reminder_count' => 0,
            'reminder_sent_at' => null,
        ]);
    }

    public function test_agent_can_still_send_reminder_on_their_own_documents_signature_request(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyWithSigningAgent('Own');

        $response = $this->actingAs($own['agent'])->postJson(route('docuperfect.signatures.sendReminder', [
            'document' => $own['document']->id,
            'signatureRequest' => $own['signatureRequest']->id,
        ]));

        $response->assertStatus(302);
    }
}
