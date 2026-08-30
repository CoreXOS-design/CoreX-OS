<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-387-completion (Johan 2026-08-30) — SignatureService::approveAndAdvance()
 * never called isFullyComplete() before falling through to completeDocument().
 * It only checked for a next STATUS_WAITING request to advance to and a
 * STATUS_DEFERRED request to pause on — a straggler stuck at
 * PENDING/VIEWED/PARTIALLY_SIGNED (e.g. a party who raised a condition via
 * SigningController::addCondition() and never returned, which never touches
 * the request's own status) was invisible to both checks. When that straggler
 * was LAST in the signing chain (nobody left WAITING after them),
 * approveAndAdvance() fell through to an UNCONDITIONAL completeDocument() —
 * reproduced live on Staging with plain natural persons (agent + 2 sellers),
 * producing a completed SignatureTemplate with a real signed PDF and one
 * party's signature block empty.
 *
 * The fix wires the SAME isFullyComplete() the automatic per-signer
 * completion path already trusts (handlePartyCompletion(), ~line 1920) into
 * approveAndAdvance()'s final fall-through, immediately before the
 * completeDocument() call — no new completeness check invented, and
 * isFullyComplete() itself is untouched.
 *
 * These tests both prove the fix (1, 6) and prove it does not regress the
 * four already-proven-good behaviours isFullyComplete() must keep handling
 * correctly (2 the everyday case, 3 deceased, 4 proxy-collapsed, 5 candidate
 * + authoriser).
 */
final class ApprovalCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private SignatureService $svc;
    private User $agentUser;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->svc = app(SignatureService::class);

        $agency = Agency::create(['name' => 'ZZZ Completion Gate Test Agency', 'slug' => 'zzz-completion-gate-' . Str::random(8)]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'ZZZ Test Branch']);
        $this->agentUser = User::factory()->create([
            'name' => 'ZZZ Test Agent', 'role' => 'agent',
            'branch_id' => $branch->id, 'agency_id' => $agency->id, 'is_active' => true,
        ]);
    }

    private function template(bool $candidateFlow = false): SignatureTemplate
    {
        $document = Document::create([
            'name' => 'ZZZ Completion Gate Test Doc', 'document_type' => 'agreement',
            'owner_id' => $this->agentUser->id,
            'web_template_data' => ['merged_html' => ''],
        ]);

        return SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            'created_by'    => $this->agentUser->id,
            'is_candidate_flow' => $candidateFlow,
        ]);
    }

    private function addRequest(
        SignatureTemplate $t,
        string $role,
        string $status,
        int $roleIndex = 1,
        bool $isDeceased = false,
        bool $isProxy = false,
    ): SignatureRequest {
        $r = $this->svc->createSigningRequest(
            template: $t,
            partyRole: $role,
            signerName: ucfirst($role) . ' ' . $roleIndex,
            signerEmail: $role . $roleIndex . '@zzz-completion-gate.test',
            roleIndex: $roleIndex,
            isDeceased: $isDeceased,
            isProxy: $isProxy,
        );
        $r->update([
            'status' => $status,
            'completed_at' => $status === SignatureRequest::STATUS_COMPLETED ? now() : null,
        ]);
        return $r->fresh();
    }

    // ── 1. Agent + 2 sellers, seller 2 never signs — must NOT complete, must name seller 2 ──

    public function test_never_returning_last_signer_blocks_finalisation_and_is_named(): void
    {
        $t = $this->template();
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        // Seller 2 was dispatched (their turn arrived) but never acted — stuck at PENDING,
        // exactly what SigningController::addCondition() (or simply never opening the link)
        // leaves behind. Last in the chain: nothing else WAITING.
        $seller2 = $this->addRequest($t, 'seller', SignatureRequest::STATUS_PENDING, roleIndex: 2);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('blocked', $result['action'] ?? null, 'must refuse, not silently complete');
        $this->assertNotSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
        $this->assertNull($t->completed_at);
        $this->assertStringContainsString($seller2->signer_name, $result['message'] ?? '', 'must name the outstanding party');
    }

    // ── 2. Same document, once seller 2 signs — completes normally (the everyday case) ──

    public function test_completes_normally_once_every_party_has_actually_signed(): void
    {
        $t = $this->template();
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 2);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('completed', $result['action'] ?? null);
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
        $this->assertNotNull($t->completed_at);
    }

    // ── 3. Deceased party marked not_required — still completes ──

    public function test_deceased_party_marked_not_required_still_completes(): void
    {
        $t = $this->template();
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        // Deceased co-seller — real flow sets is_deceased then sendSigningRequest()
        // transitions them to NOT_REQUIRED; we assert the end state directly.
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_NOT_REQUIRED, roleIndex: 2, isDeceased: true);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('completed', $result['action'] ?? null, 'a not_required deceased party must never block completion');
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
    }

    // ── 4. Proxy-collapsed directors — still completes ──

    public function test_proxy_collapsed_directors_still_completes(): void
    {
        $t = $this->template();
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        // The proxy signs on behalf of the whole director group.
        $this->addRequest($t, 'director', SignatureRequest::STATUS_COMPLETED, roleIndex: 1, isProxy: true);
        // The other directors in the SAME party_role group collapse to NOT_REQUIRED
        // (SignatureRequest::nonSigningReason() — groupHasProxy) and never sign individually.
        $this->addRequest($t, 'director', SignatureRequest::STATUS_NOT_REQUIRED, roleIndex: 2);
        $this->addRequest($t, 'director', SignatureRequest::STATUS_NOT_REQUIRED, roleIndex: 3);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('completed', $result['action'] ?? null, 'proxy-collapsed directors must never block completion');
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
    }

    // ── 5. Candidate + authoriser, both acted — still completes ──

    public function test_candidate_and_authoriser_both_acted_still_completes(): void
    {
        $t = $this->template(candidateFlow: true);
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'supervisor', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('completed', $result['action'] ?? null, 'candidate flow with authoriser already acted must complete');
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
    }

    // ── 6. The amendment-raiser variant cc3 found — must NOT complete while they are pending ──

    public function test_amendment_raiser_who_never_returns_blocks_finalisation(): void
    {
        $t = $this->template();
        $agentReq = $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED, roleIndex: 1);
        // Seller 2 raised a condition (SigningController::addCondition()) — that action
        // creates a DocumentAmendment but NEVER touches the raiser's own request status,
        // which stays exactly where it was: PENDING. They never call complete-web again.
        $seller2 = $this->addRequest($t, 'seller', SignatureRequest::STATUS_PENDING, roleIndex: 2);

        DocumentAmendment::create([
            'document_id' => $t->document_id,
            'signature_template_id' => $t->id,
            'amended_by_request_id' => $seller2->id,
            'amendment_type' => DocumentAmendment::TYPE_ADDITION,
            'section_reference' => 'Other Conditions',
            'original_text' => '',
            'new_text' => 'ZZZ test recipient-added condition.',
            'status' => DocumentAmendment::STATUS_ACCEPTED, // agent already accepted it
        ]);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('blocked', $result['action'] ?? null, 'the amendment being accepted does not substitute for the raiser actually signing');
        $this->assertNotSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
        $this->assertStringContainsString($seller2->signer_name, $result['message'] ?? '');
    }
}
