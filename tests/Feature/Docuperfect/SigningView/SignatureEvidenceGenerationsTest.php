<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\SignaturePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * Johan's signature-evidence rule (9 Sep 2026): a re-signature must NEVER overwrite the original; every signing
 * is kept. SigningController::completeWeb used to array_merge() each posted place over documents.web_template_data
 * (signatures[blockId], signed_initials[party][key]) — a second signing of the same place destroyed the first.
 * Each place is now also kept as a numbered generation (signature_place_generations) with time, IP and user agent;
 * the JSON map keeps the latest so rendering is unchanged.
 */
final class SignatureEvidenceGenerationsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private const IMG_A = 'data:image/png;base64,iVBORw0KGgoAAAA=';
    private const IMG_B = 'data:image/png;base64,iVBORw0KGgoBBBB=';

    private function completeWith(SignatureRequest $r, array $signatures)
    {
        return $this->postJson('/sign/' . $r->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => $signatures,
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);
    }

    /** @return array{0: Document, 1: SignatureRequest} */
    private function seller(): array
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);

        return [$session['document'], $this->recipient($session['recipients'], 'seller', 1)];
    }

    public function test_single_signing_writes_generation_one_and_leaves_the_stored_values_as_before(): void
    {
        [$document, $seller] = $this->seller();

        $r = $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A, 'seller-init-0' => self::IMG_A]);
        $this->assertNotSame(422, $r->getStatusCode());

        $rows = SignaturePlaceGeneration::where('document_id', $document->id)->orderBy('place_key')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 1], $rows->pluck('generation')->all());
        $this->assertSame('initial', $rows->firstWhere('place_key', 'seller-init-0')->kind);
        $this->assertSame('signature', $rows->firstWhere('place_key', 'owner_party-sig-0')->kind);
        $this->assertNull($rows[0]->superseded_at);
        $this->assertNotNull($rows[0]->signed_at);
        $this->assertSame($seller->id, (int) $rows[0]->signature_request_id);

        $data = Document::find($document->id)->web_template_data;
        $this->assertSame(self::IMG_A, $data['signatures']['owner_party-sig-0']);
        $this->assertSame(self::IMG_A, $data['signed_initials']['seller']['seller-init-0']);

        $this->assertSame(0, SignatureAuditLog::where('action', 'signature_resigned')->count(), 'a single signing adds no re-sign audit row');
    }

    public function test_a_re_signature_keeps_both_and_the_latest_is_what_is_stored_and_rendered(): void
    {
        [$document, $seller] = $this->seller();

        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A, 'seller-init-0' => self::IMG_A]);

        // The returning-signer round (changes-only / amendment) re-opens the request.
        $seller->refresh()->update(['status' => SignatureRequest::STATUS_PENDING, 'completed_at' => null]);
        $r = $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_B, 'seller-init-0' => self::IMG_B]);
        $this->assertNotSame(422, $r->getStatusCode());

        $sig = SignaturePlaceGeneration::forPlace($document->id, 'owner_party-sig-0')->orderBy('generation')->get();
        $this->assertCount(2, $sig, 'the original and the re-signature are both on record');
        $this->assertSame(self::IMG_A, $sig[0]->image, 'the original image is untouched');
        $this->assertSame(self::IMG_B, $sig[1]->image);
        $this->assertNotNull($sig[0]->superseded_at, 'generation 1 is stamped superseded, not removed');
        $this->assertSame($sig[1]->id, (int) $sig[0]->superseded_by_id);
        $this->assertNull($sig[1]->superseded_at);

        $init = SignaturePlaceGeneration::forPlace($document->id, 'seller-init-0')->orderBy('generation')->get();
        $this->assertSame([self::IMG_A, self::IMG_B], $init->pluck('image')->all());

        // The latest generation is what the document stores (and therefore renders).
        $data = Document::find($document->id)->web_template_data;
        $this->assertSame(self::IMG_B, $data['signatures']['owner_party-sig-0']);
        $this->assertSame(self::IMG_B, $data['signed_initials']['seller']['seller-init-0']);

        // ...and the baked canonical document the signer's ink lands in shows the latest, not the first.
        $canonical = (string) ($data['canonical_html'] ?? '');
        $this->assertStringContainsString('iVBORw0KGgoBBBB', $canonical);
        $this->assertStringNotContainsString('iVBORw0KGgoAAAA', $canonical);
    }

    public function test_the_audit_trail_lists_every_generation_and_the_certificate_describes_it(): void
    {
        [$document, $seller] = $this->seller();
        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A]);
        $seller->refresh()->update(['status' => SignatureRequest::STATUS_PENDING, 'completed_at' => null]);
        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_B]);

        $log = SignatureAuditLog::where('action', 'signature_resigned')->get();
        $this->assertCount(1, $log);
        $meta = $log[0]->metadata_json;
        $this->assertSame('owner_party-sig-0', $meta['place_key']);
        $this->assertSame(2, $meta['generation']);
        $this->assertSame(1, $meta['previous_generation']);
        $this->assertNotEmpty($meta['previous_signed_at']);
        $this->assertNotSame($meta['previous_image_hash'], $meta['image_hash']);
        $this->assertNotEmpty($log[0]->actor_ip_address ?? '127.0.0.1');

        $text = SignaturePdfService::auditActionDescription($log[0]);
        $this->assertStringContainsString('signed a signature again', $text);
        $this->assertStringContainsString('version 2', $text);
        $this->assertStringContainsString('earlier version 1 is kept on record', $text);
    }

    public function test_the_same_image_posted_again_does_not_invent_a_generation(): void
    {
        [$document, $seller] = $this->seller();
        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A]);
        $seller->refresh()->update(['status' => SignatureRequest::STATUS_PENDING, 'completed_at' => null]);
        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A]);

        $this->assertSame(1, SignaturePlaceGeneration::forPlace($document->id, 'owner_party-sig-0')->count());
        $this->assertSame(0, SignatureAuditLog::where('action', 'signature_resigned')->count());
    }

    public function test_a_document_signed_before_generations_existed_keeps_its_original_when_re_signed(): void
    {
        [$document, $seller] = $this->seller();

        // The pre-existing state: the original lives only in the JSON map, no generation rows.
        $data = $document->web_template_data ?? [];
        $data['signatures']['owner_party-sig-0'] = self::IMG_A;
        $document->update(['web_template_data' => $data]);
        $this->assertSame(0, SignaturePlaceGeneration::count());

        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_B]);

        $rows = SignaturePlaceGeneration::forPlace($document->id, 'owner_party-sig-0')->orderBy('generation')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(self::IMG_A, $rows[0]->image, 'the original recovered from the JSON before it was replaced');
        $this->assertSame('legacy_json', $rows[0]->source);
        $this->assertNull($rows[0]->signed_at, 'its time was never recorded — not invented');
        $this->assertSame(self::IMG_B, $rows[1]->image);
        $this->assertSame(1, SignatureAuditLog::where('action', 'signature_resigned')->count());
    }

    public function test_a_generation_is_evidence_it_cannot_be_edited_or_deleted(): void
    {
        [$document, $seller] = $this->seller();
        $this->completeWith($seller, ['owner_party-sig-0' => self::IMG_A]);
        $row = SignaturePlaceGeneration::forPlace($document->id, 'owner_party-sig-0')->firstOrFail();

        try {
            $row->update(['image' => self::IMG_B]);
            $this->fail('editing a generation must be refused');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        try {
            $row->delete();
            $this->fail('deleting a generation must be refused');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $this->assertSame(self::IMG_A, $row->fresh()->image);
    }
}
