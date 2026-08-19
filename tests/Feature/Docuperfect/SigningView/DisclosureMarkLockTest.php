<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-303 Stage 1 — MDF disclosure-mark LOCK.
 *
 * The mandatory-disclosure grid is shared, document-scoped state (one answer per
 * question, no per-recipient key). Before this fix, on a 2-seller MDF the second
 * seller could silently overwrite the first seller's already-signed answers,
 * voiding the first seller's agreement.
 *
 * These tests drive the REAL /sign/{token}/complete-web route and prove:
 *   1. the first owner-party signer AUTHORS the lock (snapshot of their answers);
 *   2. a downstream signer's CONFLICTING answer is refused (422) and the first
 *      signer's answer survives untouched, with a denial audit row;
 *   3. a downstream signer who AGREES (identical answer) is NOT blocked.
 */
final class DisclosureMarkLockTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /** Minimal valid completeWeb payload for a web/CDS signer. */
    private function completionPayload(array $disclosureAnswers): array
    {
        return [
            'consented'           => true,
            'consent_timestamp'   => now()->toIso8601String(),
            // capturedAnyMark floor — one signature image is enough.
            'signatures'          => ['seller_1-sig-1' => 'data:image/png;base64,iVBORw0KGgo='],
            // field floor — any non-empty value clears it.
            'field_values'        => ['x' => 'y'],
            'disclosure_answers'  => $disclosureAnswers,
        ];
    }

    public function test_first_owner_signer_authors_the_lock(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $document = $session['document'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);

        $resp = $this->postJson(
            '/sign/' . $seller1->token . '/complete-web',
            $this->completionPayload(['disclosure_doc_1' => 'yes'])
        );
        $resp->assertOk();

        $lock = $document->fresh()->web_template_data['disclosure_lock'] ?? null;
        $this->assertIsArray($lock, 'the first owner signer must author a disclosure lock');
        $this->assertTrue($lock['locked']);
        $this->assertSame((int) $seller1->id, (int) $lock['request_id']);
        $this->assertSame('yes', $lock['answers']['disclosure_doc_1'] ?? null);
    }

    public function test_downstream_signer_conflicting_answer_is_refused_and_original_survives(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $document = $session['document'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $seller2  = $this->recipient($session['recipients'], 'seller', 2);

        // Seller 1 signs 'yes' → lock authored.
        $this->postJson(
            '/sign/' . $seller1->token . '/complete-web',
            $this->completionPayload(['disclosure_doc_1' => 'yes'])
        )->assertOk();

        // Isolate the lock behaviour from routing — ensure seller 2 is signable.
        $seller2->update(['status' => SignatureRequest::STATUS_PENDING]);

        // Seller 2 tries to flip the SAME question to 'no'.
        $resp = $this->postJson(
            '/sign/' . $seller2->token . '/complete-web',
            $this->completionPayload(['disclosure_doc_1' => 'no'])
        );

        $resp->assertStatus(422);
        $resp->assertJsonPath('ok', false);
        $this->assertContains('disclosure_doc_1', $resp->json('locked_keys') ?? []);

        // The first signer's answer is untouched.
        $stored = $document->fresh()->web_template_data['disclosure_answers'] ?? [];
        $this->assertSame('yes', $stored['disclosure_doc_1'] ?? null,
            "seller 2's overwrite must NOT change seller 1's signed answer");

        // The refusal is audit-logged.
        $this->assertTrue(
            SignatureAuditLog::where('signature_template_id', $session['signatureTemplate']->id)
                ->where('action', 'disclosure_lock_write_denied')
                ->exists(),
            'a denied downstream overwrite must be audit-logged'
        );
    }

    public function test_downstream_signer_who_agrees_is_not_blocked(): void
    {
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $seller2  = $this->recipient($session['recipients'], 'seller', 2);

        $this->postJson(
            '/sign/' . $seller1->token . '/complete-web',
            $this->completionPayload(['disclosure_doc_1' => 'yes'])
        )->assertOk();

        $seller2->update(['status' => SignatureRequest::STATUS_PENDING]);

        // Seller 2 affirms the SAME answer — a genuine agree, must pass.
        $this->postJson(
            '/sign/' . $seller2->token . '/complete-web',
            $this->completionPayload(['disclosure_doc_1' => 'yes'])
        )->assertOk();
    }
}
