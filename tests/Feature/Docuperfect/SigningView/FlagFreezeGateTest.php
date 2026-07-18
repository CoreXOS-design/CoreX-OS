<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-291 ITEM 5 — flag freeze workflow (server enforcement).
 *
 * When a recipient flags a clause for agent review the document freezes:
 *   1. completeWeb() (and the marker complete() twin) MUST reject a
 *      completion POST while any flag amendment is still PENDING — the
 *      client hides the submit surface, but a crafted / JS-failed POST
 *      must be blocked server-side too (a party must never complete a
 *      document that is about to change).
 *   2. The freeze must LIFT the moment the agent resolves the flag. The
 *      resolution cascade never rewrites the web_template_data.clause_flags
 *      JSON, so show() derives each flag's status from the live
 *      DocumentAmendment record — a resolved amendment drops the banner.
 *
 * These assertions pin the two SigningController changes (completeWeb
 * gate + hydrateClauseFlagStatuses) against the real signing pipeline.
 */
final class FlagFreezeGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /**
     * Create a real pending flag amendment for a recipient — mirrors the
     * columns flagClause() writes so NOT-NULL contracts are honoured.
     */
    private function raiseFlag(array $session, $recipient, string $clauseRef, string $status): DocumentAmendment
    {
        return DocumentAmendment::create([
            'document_id'             => $session['document']->id,
            'signature_template_id'   => $session['signatureTemplate']->id,
            'amended_by_request_id'   => $recipient->id,
            'amendment_type'          => DocumentAmendment::TYPE_FLAG_RAISED,
            'flag_origin'             => DocumentAmendment::FLAG_ORIGIN_SIGNING_PARTY,
            'flag_clause_ref'         => $clauseRef,
            'flag_reason'             => 'I want a longer notice period',
            'section_reference'       => 'Clause ' . $clauseRef,
            'original_text'           => 'The notice period is 30 days.',
            'new_text'                => 'The notice period is 60 days.',
            'document_version_before' => 1,
            'document_version_after'  => 1,
            'document_hash_before'    => $session['signatureTemplate']->document_hash,
            'document_hash_after'     => null,
            'status'                  => $status,
        ]);
    }

    /**
     * ITEM 5.1 — completeWeb is blocked (423) while a flag amendment is
     * still pending agent review, even if the client-side lock is bypassed.
     */
    public function test_complete_web_is_blocked_while_a_flag_is_pending(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $this->raiseFlag($session, $seller1, '3.7', DocumentAmendment::STATUS_PENDING);
        $session['signatureTemplate']->update([
            'status'           => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_PENDING_REVIEW,
        ]);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'field_values' => [],
        ]);

        $response->assertStatus(423);
        $response->assertJson(['ok' => false]);
    }

    /**
     * ITEM 5.1b — once the agent resolves the flag (amendment no longer
     * PENDING) the server freeze gate lifts: completeWeb no longer 423s.
     */
    public function test_complete_web_freeze_gate_lifts_once_flag_resolved(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $this->raiseFlag($session, $seller1, '3.7', DocumentAmendment::STATUS_REJECTED);
        // Agent resolution returns the template to signing.
        $session['signatureTemplate']->update([
            'status'           => SignatureTemplate::STATUS_SIGNING,
            'amendment_status' => SignatureTemplate::AMENDMENT_STATUS_REJECTED,
        ]);

        $response = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'field_values' => [],
        ]);

        // The freeze gate must NOT fire once the flag is resolved. We assert
        // the 423 freeze response is gone specifically (downstream completion
        // side-effects are out of scope for this gate boundary test).
        $this->assertNotSame(
            423,
            $response->getStatusCode(),
            'Freeze gate must lift once the flag amendment is resolved (got 423 — still frozen).',
        );
    }

    /**
     * ITEM 5.2 — the freeze-lift derivation. show() re-stamps each persisted
     * clause-flag's status from the LIVE DocumentAmendment (the resolution
     * cascade never rewrites the clause_flags JSON): a PENDING amendment stays
     * 'pending_review' (banner keeps the recipient frozen), a resolved
     * amendment becomes 'resolved' (banner lifts), and an unknown amendment_id
     * keeps its stored status (never fabricated to resolved). Tested directly
     * against hydrateClauseFlagStatuses — the client banner itself is an Alpine
     * x-if driven by this seed at runtime, not assertable from raw HTML.
     */
    public function test_hydrate_clause_flag_statuses_derives_from_live_amendment(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $template = $session['signatureTemplate'];

        $pending  = $this->raiseFlag($session, $seller1, '3.7', DocumentAmendment::STATUS_PENDING);
        $resolved = $this->raiseFlag($session, $seller1, '4.1', DocumentAmendment::STATUS_ACCEPTED);

        $clauseFlags = [
            'seller' => [
                ['clauseNum' => '3.7', 'amendment_id' => $pending->id,  'status' => 'pending_review'],
                ['clauseNum' => '4.1', 'amendment_id' => $resolved->id, 'status' => 'pending_review'],
                ['clauseNum' => '9.9', 'amendment_id' => 999999,        'status' => 'pending_review'],
            ],
        ];

        $method = new \ReflectionMethod(
            \App\Http\Controllers\Docuperfect\SigningController::class,
            'hydrateClauseFlagStatuses',
        );
        $method->setAccessible(true);
        $out = $method->invoke(
            app(\App\Http\Controllers\Docuperfect\SigningController::class),
            $clauseFlags,
            $template,
        );

        $this->assertSame('pending_review', $out['seller'][0]['status'], 'pending amendment stays pending');
        $this->assertSame('resolved',       $out['seller'][1]['status'], 'accepted amendment resolves — freeze lifts');
        $this->assertSame('pending_review', $out['seller'][2]['status'], 'unknown amendment_id keeps stored status');
    }
}
