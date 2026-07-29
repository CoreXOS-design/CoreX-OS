<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\AmendmentAcceptance;
use App\Models\Docuperfect\DocumentAmendment;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-303 Stages 2-3 — MDF disclosure-MARK amendment flow.
 *
 * A downstream owner recipient (seller 2) may not silently overwrite seller 1's
 * locked marks (Stage 1). Instead they PROPOSE a change (strike original + new +
 * their initial), which routes BACK to seller 1 to COUNTER-INITIAL. Seller 1
 * agrees (ratify — amended mark stands) or declines (revert — original stands,
 * back to proposer). The doc cannot complete while a mark amendment is pending.
 * Counter-initials are keyed per signing request (identity) — joint sellers never
 * collapse.
 */
final class DisclosureMarkAmendmentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const KEY = 'disclosure_doc_1';
    private const INK = 'data:image/png;base64,iVBORw0KGgo=';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); // route-back sends signer emails; don't hit a transport.
    }

    /** Build a 2-seller session with seller 1's marks already LOCKED. */
    private function lockedSession(): array
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $document = $session['document'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $seller2  = $this->recipient($session['recipients'], 'seller', 2);

        // Seller 1 has signed → author the lock (as completeWeb would).
        $seller1->update(['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now()]);
        $seller2->update(['status' => SignatureRequest::STATUS_PENDING]);

        $webData = $document->web_template_data ?? [];
        $webData['disclosure_answers'] = [self::KEY => 'yes'];
        $webData['disclosure_lock'] = [
            'locked' => true,
            'request_id' => $seller1->id,
            'role_identity' => $seller1->role_identity,
            'signer_name' => $seller1->signer_name,
            'locked_at' => now()->toIso8601String(),
            'answers' => [self::KEY => 'yes'],
        ];
        $document->update(['web_template_data' => $webData]);

        return $session + ['seller1' => $seller1->fresh(), 'seller2' => $seller2->fresh()];
    }

    private function propose(SignatureRequest $seller2, string $newValue = 'no'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/sign/' . $seller2->token . '/disclosure/' . self::KEY . '/amend', [
            'new_value' => $newValue,
            'statement' => 'I am aware of defects in the roof',
            'initial_image' => self::INK,
        ]);
    }

    public function test_downstream_seller_proposes_amendment_that_routes_back_to_seller1(): void
    {
        $s = $this->lockedSession();
        $template = $s['signatureTemplate'];

        $resp = $this->propose($s['seller2']);
        $resp->assertOk();
        $resp->assertJsonPath('ok', true);

        $amendment = DocumentAmendment::where('signature_template_id', $template->id)->firstOrFail();
        $this->assertSame(DocumentAmendment::TYPE_MODIFICATION, $amendment->amendment_type);
        $this->assertSame('Disclosure', $amendment->section_reference);
        $this->assertSame(self::KEY, $amendment->flag_clause_ref);
        $this->assertSame(DocumentAmendment::STATUS_PENDING, $amendment->status);
        $this->assertSame((int) $s['seller2']->id, (int) $amendment->amended_by_request_id);

        // Proposer affirmed their own change with an initial.
        $this->assertTrue(AmendmentAcceptance::where('amendment_id', $amendment->id)
            ->where('signature_request_id', $s['seller2']->id)
            ->where('accepted', true)->whereNotNull('initial_image')->exists());

        // Seller 1 (earlier) reopened with a PENDING counter-initial to give.
        $seller1 = $s['seller1']->fresh();
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller1->status);
        $this->assertTrue(AmendmentAcceptance::where('amendment_id', $amendment->id)
            ->where('signature_request_id', $seller1->id)
            ->where('accepted', false)->where('rejected', false)->exists());

        // Proposer parked; template in amendment review.
        $this->assertSame(SignatureRequest::STATUS_WAITING, $s['seller2']->fresh()->status);
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_REVIEW, $template->fresh()->status);

        // Completion is GATED while the mark amendment is pending.
        $this->assertFalse(app(SignatureService::class)->isFullyComplete($template->fresh()));
    }

    public function test_seller1_counter_initials_ratifies_the_amended_mark(): void
    {
        $s = $this->lockedSession();
        $template = $s['signatureTemplate'];
        $document = $s['document'];

        $this->propose($s['seller2'])->assertOk();
        $amendment = DocumentAmendment::where('signature_template_id', $template->id)->firstOrFail();
        $seller1 = $s['seller1']->fresh(); // token was rotated by the route-back

        $this->postJson('/sign/' . $seller1->token . '/amendment/' . $amendment->id . '/accept', [
            'initial_image' => self::INK,
        ])->assertOk()->assertJsonPath('mark_amendment', true);

        // Amended mark now STANDS: it is the document truth AND re-locked.
        $wd = $document->fresh()->web_template_data;
        $this->assertSame('no', $wd['disclosure_answers'][self::KEY]);
        $this->assertSame('no', $wd['disclosure_lock']['answers'][self::KEY]);
        $this->assertSame('ratified', $wd['disclosure_mark_amendments'][self::KEY]['status']);
        $this->assertSame(DocumentAmendment::STATUS_ACCEPTED, $amendment->fresh()->status);

        // Seller 1's original signature stands (COMPLETED); proposer handed back.
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $seller1->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $s['seller2']->fresh()->status);
    }

    public function test_seller1_declines_reverts_to_original_and_routes_back_to_proposer(): void
    {
        $s = $this->lockedSession();
        $template = $s['signatureTemplate'];
        $document = $s['document'];

        $this->propose($s['seller2'])->assertOk();
        $amendment = DocumentAmendment::where('signature_template_id', $template->id)->firstOrFail();
        $seller1 = $s['seller1']->fresh();

        $this->postJson('/sign/' . $seller1->token . '/amendment/' . $amendment->id . '/reject', [
            'reason' => 'We agreed the roof answer stays YES.',
        ])->assertOk()->assertJsonPath('reverted', true);

        // Original mark STANDS; amendment reverted.
        $wd = $document->fresh()->web_template_data;
        $this->assertSame('yes', $wd['disclosure_answers'][self::KEY], 'original answer must survive a decline');
        $this->assertSame('reverted', $wd['disclosure_mark_amendments'][self::KEY]['status']);
        $this->assertSame(DocumentAmendment::STATUS_REJECTED, $amendment->fresh()->status);

        // Seller 1's original signature stands; proposer handed back to sign/accept.
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $seller1->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $s['seller2']->fresh()->status);
    }

    public function test_guards_reject_bad_proposals(): void
    {
        $s = $this->lockedSession();

        // Same value = not an amendment.
        $this->propose($s['seller2'], 'yes')->assertStatus(422);

        // Missing initial.
        $this->postJson('/sign/' . $s['seller2']->token . '/disclosure/' . self::KEY . '/amend', [
            'new_value' => 'no', 'statement' => 'x',
        ])->assertStatus(422);

        // The lock AUTHOR cannot use the amend path (edits directly).
        $this->postJson('/sign/' . $s['seller1']->token . '/disclosure/' . self::KEY . '/amend', [
            'new_value' => 'no', 'initial_image' => self::INK,
        ])->assertStatus(409);
    }
}
