<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-2b — a SALES document with an uploaded wet-ink copy never reached the
 * agent's approval queue.
 *
 * The wet-ink promotion was gated on a RENTAL-ONLY status whitelist
 * (`signing`, `awaiting_tenant`, `awaiting_landlord`). A sales document sits in
 * `awaiting_seller` / `awaiting_buyer`, so it never matched — the seller's
 * signed copy landed, and the document stayed under "awaiting signatures"
 * forever. Under ECTA every sale is wet-ink, so this was the PRIMARY sales path,
 * silently broken on live.
 *
 * Worse, the dashboard that actually carries sales documents (myDocuments) had
 * NO wet-ink handling at all, and several statuses — including
 * `amendment_initialing` — matched no group whatsoever, so those documents
 * vanished from the agent's dashboard entirely.
 */
final class AgentApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private SignatureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $this->agent = User::factory()->create([
            'agency_id' => $agency->id,
            'role'      => 'super_admin',
        ]);
        $this->service = app(SignatureService::class);
    }

    /**
     * Build a signing flow in a given status, optionally with a party who has
     * uploaded a wet-ink copy that nobody has reviewed.
     */
    private function flow(string $status, bool $wetInkUploaded = false): SignatureTemplate
    {
        $type = DocumentType::firstOrCreate(['slug' => 'mandate'], ['label' => 'Mandate']);

        $template = Template::create([
            'name'             => 'Shelly EATS (V10)',
            'document_type_id' => $type->id,
            'render_type'      => 'web',
            'is_esign'         => true,
            'owner_id'         => $this->agent->id,
        ]);

        $document = Document::create([
            'name'        => 'Mandate — 14 Marine Drive, Shelly Beach',
            'template_id' => $template->id,
            'owner_id'    => $this->agent->id,
        ]);

        $sigTemplate = SignatureTemplate::create([
            'document_id' => $document->id,
            'created_by'  => $this->agent->id,
            'status'      => $status,
        ]);

        SignatureRequest::create([
            'signature_template_id' => $sigTemplate->id,
            'party_role'            => 'seller',
            'signer_name'           => 'M. Naidoo',
            'signer_email'          => 'm.naidoo@example.co.za',
            'token'                 => bin2hex(random_bytes(16)),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_PENDING,
            'signing_method'        => $wetInkUploaded ? 'wet_ink' : 'electronic',
            'wet_ink_status'        => $wetInkUploaded
                ? SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW
                : null,
        ]);

        return $sigTemplate->fresh(['requests']);
    }

    // ── the sales path: THE bug ─────────────────────────────────────────────

    public function test_a_sales_document_with_an_uploaded_wet_ink_copy_awaits_the_agent(): void
    {
        $sales = $this->flow(SignatureTemplate::STATUS_AWAITING_SELLER, wetInkUploaded: true);

        $this->assertTrue(
            $this->service->isAwaitingAgentReview($sales),
            'a seller uploaded their signed copy — under ECTA every sale is wet-ink, so this '
            . 'MUST reach the agent. Pre-fix the rental-only status whitelist dropped it.'
        );
    }

    public function test_the_same_holds_for_a_buyer_side_sales_document(): void
    {
        $sales = $this->flow(SignatureTemplate::STATUS_AWAITING_BUYER, wetInkUploaded: true);

        $this->assertTrue($this->service->isAwaitingAgentReview($sales));
    }

    /** The rental path must keep working — no regression from deleting the whitelist. */
    public function test_the_rental_path_still_awaits_the_agent(): void
    {
        $rental = $this->flow(SignatureTemplate::STATUS_AWAITING_TENANT, wetInkUploaded: true);

        $this->assertTrue($this->service->isAwaitingAgentReview($rental));
    }

    /** No wet-ink upload, waiting on a party => NOT the agent's problem. */
    public function test_a_document_merely_awaiting_a_party_does_not_await_the_agent(): void
    {
        $inFlight = $this->flow(SignatureTemplate::STATUS_AWAITING_SELLER);

        $this->assertFalse($this->service->isAwaitingAgentReview($inFlight));
    }

    /** An explicit agent-approval state still lands in the queue. */
    public function test_pending_agent_approval_awaits_the_agent(): void
    {
        $this->assertTrue(
            $this->service->isAwaitingAgentReview($this->flow(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL))
        );
    }

    /** An amendment awaiting the agent's decision is the agent's problem. */
    public function test_amendment_review_awaits_the_agent(): void
    {
        $this->assertTrue(
            $this->service->isAwaitingAgentReview($this->flow(SignatureTemplate::STATUS_AMENDMENT_REVIEW))
        );
    }

    /** A dead document never sits in a live queue, even with a stale upload flag. */
    public function test_a_terminal_document_never_awaits_the_agent(): void
    {
        foreach ([
            SignatureTemplate::STATUS_COMPLETED,
            SignatureTemplate::STATUS_CANCELLED,
            SignatureTemplate::STATUS_REJECTED,
            SignatureTemplate::STATUS_DECLINED,
            SignatureTemplate::STATUS_EXPIRED,
        ] as $terminal) {
            $this->assertFalse(
                $this->service->isAwaitingAgentReview($this->flow($terminal, wetInkUploaded: true)),
                "a {$terminal} document must never appear in the agent's approval queue"
            );
        }
    }

    // ── the dashboard: documents must not vanish ────────────────────────────

    public function test_a_sales_wet_ink_document_appears_in_the_agents_approval_queue(): void
    {
        $this->flow(SignatureTemplate::STATUS_AWAITING_SELLER, wetInkUploaded: true);

        $response = $this->actingAs($this->agent)->get(route('docuperfect.esign.myDocuments'));
        $response->assertOk();

        $groups = $response->viewData('groups');

        $this->assertCount(1, $groups['pending_approval'],
            'the sales wet-ink document must be in the agent approval queue');
        $this->assertCount(0, $groups['awaiting'],
            'and must NOT also show as merely awaiting signatures — it waits on the AGENT');
    }

    public function test_a_document_mid_amendment_does_not_vanish_from_the_dashboard(): void
    {
        $this->flow(SignatureTemplate::STATUS_AMENDMENT_INITIALING);

        $response = $this->actingAs($this->agent)->get(route('docuperfect.esign.myDocuments'));
        $response->assertOk();

        $groups = $response->viewData('groups');
        $total  = collect($groups)->flatten(1)->count();

        $this->assertSame(1, $total,
            'pre-fix amendment_initialing matched NO group — the document disappeared entirely');
        $this->assertCount(1, $groups['awaiting'],
            'parties are actively initialing an approved amendment: in flight, not a draft');
    }

    public function test_no_status_can_silently_disappear_from_the_dashboard(): void
    {
        // Every status the state machine can produce, all at once.
        $statuses = [
            SignatureTemplate::STATUS_DRAFT,
            SignatureTemplate::STATUS_READY,
            SignatureTemplate::STATUS_SIGNING,
            SignatureTemplate::STATUS_AWAITING_SELLER,
            SignatureTemplate::STATUS_AWAITING_BUYER,
            SignatureTemplate::STATUS_AWAITING_TENANT,
            SignatureTemplate::STATUS_AWAITING_LANDLORD,
            SignatureTemplate::STATUS_AWAITING_DEFERRED,
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            SignatureTemplate::STATUS_AMENDMENT_INITIALING,
            SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE,
            SignatureTemplate::STATUS_PARTIAL,
            SignatureTemplate::STATUS_COMPLETED,
            SignatureTemplate::STATUS_CANCELLED,
            SignatureTemplate::STATUS_REJECTED,
            SignatureTemplate::STATUS_DECLINED,
            SignatureTemplate::STATUS_EXPIRED,
        ];

        foreach ($statuses as $status) {
            $this->flow($status);
        }

        $response = $this->actingAs($this->agent)->get(route('docuperfect.esign.myDocuments'));
        $response->assertOk();

        $groups = $response->viewData('groups');
        unset($groups['needs_authorisation']); // a separate cross-cutting queue

        $this->assertSame(
            count($statuses),
            collect($groups)->flatten(1)->count(),
            'every document must land in exactly one visible bucket — none may vanish'
        );
    }
}
