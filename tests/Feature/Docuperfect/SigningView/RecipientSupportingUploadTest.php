<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignedDocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * E-SIGN recipient supporting-document uploads (cc2).
 *
 * Staff asked for recipients to be able to upload supporting documents during signing —
 * OPTIONAL, must never gate signing, and must stay available AFTER signing. This pins:
 *   1. a verified recipient can upload before signing; files land as SignedDocumentVersion
 *      kind='supporting' filed against the signing package, and signing state is untouched;
 *   2. the upload is reachable AFTER completion via the same token (no re-verify needed);
 *   3. the upload never flips signing_method / status / wet-ink fields.
 */
final class RecipientSupportingUploadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_verified_recipient_can_upload_supporting_docs_before_signing_without_touching_signing_state(): void
    {
        Storage::fake('local');
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        /** @var SignatureRequest $recipient */
        $recipient = $this->recipient($session['recipients'], 'seller', 1);
        $document = $session['document'];

        $token = $recipient->token;
        $priorMethod = $recipient->signing_method;
        $priorStatus = $recipient->status;

        $response = $this->withSession(["signing_verified_{$token}" => true])
            ->post("/sign/{$token}/supporting-documents", [
                'supporting_files' => [
                    UploadedFile::fake()->create('id-copy.pdf', 120, 'application/pdf'),
                    UploadedFile::fake()->create('proof.jpg', 80, 'image/jpeg'),
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('supporting_success');

        $filed = SignedDocumentVersion::where('signature_request_id', $recipient->id)
            ->where('kind', SignedDocumentVersion::KIND_SUPPORTING)
            ->get();
        $this->assertCount(2, $filed, 'both supporting files should be filed against the signing package');
        $this->assertSame($document->id, (int) $filed->first()->document_id);
        $this->assertSame(0, (int) $filed->first()->version_number, 'supporting docs must not consume a signed-version number');

        // Signing state must be completely untouched — the upload is never a gate.
        $recipient->refresh();
        $this->assertSame($priorMethod, $recipient->signing_method);
        $this->assertSame($priorStatus, $recipient->status);
        $this->assertNull($recipient->wet_ink_upload_path);
    }

    public function test_supporting_upload_stays_available_after_signing_via_same_token(): void
    {
        Storage::fake('local');
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        /** @var SignatureRequest $recipient */
        $recipient = $this->recipient($session['recipients'], 'seller', 1);
        $token = $recipient->token;

        // The recipient has already signed.
        $recipient->update(['status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now()]);

        // No verified session this time — a returning signer with just their link.
        $response = $this->post("/sign/{$token}/supporting-documents", [
            'supporting_files' => [UploadedFile::fake()->create('late-addendum.pdf', 90, 'application/pdf')],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('supporting_success');

        $this->assertSame(1, SignedDocumentVersion::where('signature_request_id', $recipient->id)
            ->where('kind', SignedDocumentVersion::KIND_SUPPORTING)->count());

        // Still completed — a post-sign upload never disturbs the completed state.
        $recipient->refresh();
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $recipient->status);
    }

    public function test_unverified_recipient_who_has_not_signed_cannot_upload(): void
    {
        Storage::fake('local');
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        /** @var SignatureRequest $recipient */
        $recipient = $this->recipient($session['recipients'], 'seller', 1);
        $token = $recipient->token;

        $response = $this->post("/sign/{$token}/supporting-documents", [
            'supporting_files' => [UploadedFile::fake()->create('x.pdf', 50, 'application/pdf')],
        ]);

        $response->assertRedirect(route('signatures.external', $token));
        $this->assertSame(0, SignedDocumentVersion::where('signature_request_id', $recipient->id)->count());
    }
}
