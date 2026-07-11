<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P0-6 — an amendment must NEVER void a prior party's consent.
 *
 * Doctrine (esign-ceremony-v3.md §5, refinement B; and the settled rule at
 * esign-v3-complete-spec.md §7.5.7): when a term changes mid-flow, prior
 * signatures are RETAINED and every party initials ONLY the new content. This is
 * the digital twin of wet-ink practice — you send the corrected page round for
 * initialing; you do not tear up the agreement and re-sign it.
 *
 * `handleAmendment()` did the opposite: it reverted every previously-COMPLETED
 * signer back to PENDING with a fresh token — a full re-sign cascade. And it was
 * NOT dead code: it is reachable in production from the live "Other Conditions"
 * amendment-detection path in SigningController, so a recipient typing a
 * condition could un-sign everyone who signed before them.
 */
final class AmendmentRetainsPriorMarksTest extends TestCase
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
     * Seller 1 has signed and finished. Seller 2 is signing now and proposes a
     * change — the exact live shape of the bug.
     */
    private function flowWithAPriorSigner(): array
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
            'status'      => SignatureTemplate::STATUS_SIGNING,
        ]);

        // Seller 1 — already signed, already done.
        $priorSigner = SignatureRequest::create([
            'signature_template_id' => $sigTemplate->id,
            'party_role'            => 'seller',
            'role_index'            => 1,
            'signing_order'         => 1,
            'signer_name'           => 'M. Naidoo',
            'signer_email'          => 'm.naidoo@example.co.za',
            'token'                 => bin2hex(random_bytes(16)),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_COMPLETED,
            'completed_at'          => now()->subHour(),
        ]);

        // Seller 2 — signing now, and about to propose a condition.
        $amender = SignatureRequest::create([
            'signature_template_id' => $sigTemplate->id,
            'party_role'            => 'seller',
            'role_index'            => 2,
            'signing_order'         => 2,
            'signer_name'           => 'P. van Wyk',
            'signer_email'          => 'p.vanwyk@example.co.za',
            'token'                 => bin2hex(random_bytes(16)),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_COMPLETED,
            'completed_at'          => now(),
        ]);

        $amendment = DocumentAmendment::create([
            'document_id'           => $document->id,
            'signature_template_id' => $sigTemplate->id,
            'amended_by_request_id' => $amender->id,
            'amendment_type'        => DocumentAmendment::TYPE_ADDITION,
            'new_text'              => 'Occupation date moved to 1 September 2026.',
            'status'                => DocumentAmendment::STATUS_PENDING,
        ]);

        return [$sigTemplate->fresh(['requests']), $amendment, $priorSigner, $amender];
    }

    public function test_a_prior_signers_consent_is_not_voided_by_an_amendment(): void
    {
        Mail::fake();
        [$sigTemplate, $amendment, $priorSigner, $amender] = $this->flowWithAPriorSigner();

        $originalToken = $priorSigner->token;

        $this->service->handleAmendment($sigTemplate, $amendment, $amender);

        $priorSigner->refresh();

        $this->assertSame(
            SignatureRequest::STATUS_COMPLETED,
            $priorSigner->status,
            'THE BUG: the prior signer was reverted COMPLETED -> PENDING. Their consent stands '
            . 'until the agent approves the change, and even then they only initial the new content.'
        );

        $this->assertNotNull($priorSigner->completed_at, 'their completion timestamp must survive');

        $this->assertSame(
            $originalToken,
            $priorSigner->token,
            'minting a fresh signing token for a finished party is how the re-sign cascade began'
        );
    }

    public function test_the_flow_halts_and_the_agent_becomes_the_gatekeeper(): void
    {
        Mail::fake();
        [$sigTemplate, $amendment, , $amender] = $this->flowWithAPriorSigner();

        $this->service->handleAmendment($sigTemplate, $amendment, $amender);

        $this->assertSame(
            SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            $sigTemplate->fresh()->status,
            'forward progress halts — the agent is always the gatekeeper of the document terms'
        );
    }

    public function test_prior_signers_are_not_emailed_before_the_agent_has_approved(): void
    {
        Mail::fake();
        [$sigTemplate, $amendment, , $amender] = $this->flowWithAPriorSigner();

        $this->service->handleAmendment($sigTemplate, $amendment, $amender);

        // The party is re-circulated only AFTER the agent approves — via
        // requeueAllPartiesForInitialing(), which retains their marks.
        Mail::assertNothingOutgoing();
    }

    /**
     * The doctrine path is untouched and still does the right thing: on the
     * agent's approval, everyone is re-circulated to initial the new content —
     * with their original signatures still standing.
     */
    public function test_on_agent_approval_all_parties_are_requeued_with_their_marks_intact(): void
    {
        Mail::fake();
        [$sigTemplate, $amendment, $priorSigner, $amender] = $this->flowWithAPriorSigner();

        $this->service->handleAmendment($sigTemplate, $amendment, $amender);

        $tokenBeforeRequeue = $priorSigner->fresh()->token;

        $this->service->requeueAllPartiesForInitialing($sigTemplate->fresh(), $amendment);

        $priorSigner->refresh();

        $this->assertSame(
            SignatureRequest::STATUS_COMPLETED,
            $priorSigner->status,
            'even through the re-circulation, the original signature stays in place'
        );

        $this->assertSame(
            SignatureTemplate::STATUS_AMENDMENT_INITIALING,
            $sigTemplate->fresh()->status,
            'the document moves to the focused initialing cascade, not a re-sign'
        );

        // Both parties get a fresh token to land on the initialing surface — that
        // is the intended re-circulation, and it does NOT reset their status.
        $this->assertNotSame(
            $tokenBeforeRequeue,
            $priorSigner->token,
            'a fresh token routes them to the initialing view — this is the intended '
            . 're-circulation, and unlike the void path it does NOT reset their status'
        );
    }
}
