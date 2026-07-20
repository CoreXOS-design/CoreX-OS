<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * ESIGN-WETINK Ruling #1 (Elize flow optimisation) — a recipient who ACCEPTS
 * with NO flag and NO strikeout/amendment flows STRAIGHT to the next recipient.
 * The agent is a checkpoint ONLY when a flag/strikeout has raised a PENDING
 * amendment. Pins the handlePartyCompletion routing that SigningController's
 * completeWeb now delegates to.
 */
final class CleanAcceptRoutingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_clean_accept_advances_to_next_recipient_without_agent_checkpoint(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $template = $session['signatureTemplate'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $seller2  = $this->recipient($session['recipients'], 'seller', 2);

        // seller_1 completes cleanly (no pending amendment on the template).
        $seller1->update(['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now()]);
        app(SignatureService::class)->handlePartyCompletion($template, 'seller', $seller1);

        $template->refresh();
        $seller2->refresh();

        $this->assertNotSame(
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            $template->status,
            'a clean accept must NOT park at the agent checkpoint',
        );
        $this->assertNotSame(
            SignatureRequest::STATUS_WAITING,
            $seller2->status,
            'seller_2 must be activated (pen handed on) after seller_1 clean accept',
        );
    }

    public function test_pending_flag_routes_to_agent_checkpoint(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $template = $session['signatureTemplate'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);

        // A flag raises a PENDING amendment BEFORE completion routing runs.
        DocumentAmendment::create([
            'document_id'             => $session['document']->id,
            'signature_template_id'   => $template->id,
            'amended_by_request_id'   => $seller1->id,
            'amendment_type'          => DocumentAmendment::TYPE_FLAG_RAISED,
            'flag_origin'             => DocumentAmendment::FLAG_ORIGIN_SIGNING_PARTY,
            'flag_clause_ref'         => '3.7',
            'flag_reason'             => 'Concern',
            'section_reference'       => 'Clause 3.7',
            'original_text'           => 'x',
            'new_text'                => 'y',
            'document_version_before' => 1,
            'document_version_after'  => 1,
            'document_hash_before'    => $template->document_hash,
            'document_hash_after'     => null,
            'status'                  => DocumentAmendment::STATUS_PENDING,
        ]);

        $seller1->update(['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now()]);
        app(SignatureService::class)->handlePartyCompletion($template, 'seller', $seller1);

        $template->refresh();
        $this->assertSame(
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            $template->status,
            'a pending flag/strikeout MUST route back to the agent checkpoint',
        );
    }
}
