<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-324/AT-325 — two same-role co-signers (two sellers) who BOTH initial must
 * EACH keep their own initials in signed_initials.
 *
 * Regression guard for the wet-ink write-side collision at SigningController::
 * completeWeb(): signed_initials was keyed by the bare party_role and OVERWRITTEN
 * per completion ($existingInitials[$partyRole] = $initials), so the 2nd seller's
 * completion clobbered the 1st seller's captured initials — the ink survived in
 * web_template_data['signatures'] but vanished from signed_initials, the store the
 * review + PDF actually read. It is now keyed by the CANONICAL per-recipient key
 * (seller vs seller_2) and MERGED, so both signers' initials persist.
 */
final class CoSignerInitialsPersistTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_second_co_seller_does_not_clobber_first_sellers_initials(): void
    {
        $img = 'data:image/png;base64,iVBORw0KGgo=';

        // Two sellers + an agent last, so completing both sellers does NOT finalise
        // the document (no synchronous PDF generation in the test).
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2, includeAgent: true);
        $documentId = $session['document']->id;
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        // Seller 1 signs and initials two page breaks (keys are recipient-distinct,
        // exactly as the client emits them: base role for the 1st same-role signer).
        $r1 = $this->postJson('/sign/' . $seller1->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => [
                'owner_party-sig-0' => $img,
                'seller-init-0'     => $img,
                'seller-init-1'     => $img,
            ],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);
        $this->assertNotSame(422, $r1->getStatusCode(), 'seller 1 completion must pass the floor');

        $afterS1 = (Document::find($documentId)->web_template_data ?? [])['signed_initials'] ?? [];
        $this->assertArrayHasKey('seller', $afterS1, 'seller 1 initials stored under the canonical key');
        $this->assertArrayHasKey('seller-init-0', $afterS1['seller']);
        $this->assertArrayHasKey('seller-init-1', $afterS1['seller']);

        // Seller 2 must be completable (not WAITING), then signs + initials.
        $seller2->refresh();
        if ($seller2->status === SignatureRequest::STATUS_WAITING) {
            $seller2->update(['status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now()]);
        }
        $r2 = $this->postJson('/sign/' . $seller2->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => [
                'owner_party-sig-0' => $img,
                'seller_2-init-0'   => $img,
                'seller_2-init-1'   => $img,
            ],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '9002026700099'],
        ]);
        $this->assertNotSame(422, $r2->getStatusCode(), 'seller 2 completion must pass the floor');

        // THE regression assertions — both co-sellers' initials survive, each under
        // its OWN canonical key, after the 2nd seller completes.
        $signed = (Document::find($documentId)->web_template_data ?? [])['signed_initials'] ?? [];

        $this->assertArrayHasKey('seller', $signed, "seller 1's initials must NOT be clobbered by seller 2");
        $this->assertArrayHasKey('seller-init-0', $signed['seller']);
        $this->assertArrayHasKey('seller-init-1', $signed['seller']);

        $this->assertArrayHasKey('seller_2', $signed, "seller 2's initials stored under its own key");
        $this->assertArrayHasKey('seller_2-init-0', $signed['seller_2']);
        $this->assertArrayHasKey('seller_2-init-1', $signed['seller_2']);
    }
}
