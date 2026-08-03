<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentSealedVersion;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\DocumentSealService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * E-Sign P1 — the sealed, append-only, tamper-evident per-version trail.
 *
 * These assert the ADDITIVE recording layer: each seal writes an immutable,
 * hash-chained snapshot; the chain verifies and detects tampering; sealed rows
 * cannot be mutated; and wiring the terminal seal into completeDocument() does NOT
 * change completion behaviour (parity preserved). The mark-level parity / pack
 * ceremony / completeness invariants are guarded end-to-end by esign:regression-walk;
 * here we prove the seal layer itself and that completion still completes.
 */
class DocumentSealTrailTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    private function seal(): DocumentSealService
    {
        return app(DocumentSealService::class);
    }

    public function test_seal_writes_immutable_hash_chained_versions(): void
    {
        $s = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        /** @var Document $document */
        $document = $s['document'];

        $v1 = $this->seal()->seal($document, DocumentSealService::EVENT_CANDIDATE_SIGNED, ['template' => $s['signatureTemplate']]);
        $v2 = $this->seal()->seal($document, DocumentSealService::EVENT_RECIPIENT_SIGNED, ['template' => $s['signatureTemplate']]);

        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertSame(2, DocumentSealedVersion::where('document_id', $document->id)->count());

        // Monotonic versions.
        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);

        // First link: prev_hash null, content_hash = sha256('' . sealed_html).
        $this->assertNull($v1->prev_hash);
        $this->assertSame(hash('sha256', $v1->sealed_html), $v1->content_hash);

        // Second link chains to the first: prev_hash = v1.content_hash, and
        // content_hash = sha256(prev_hash . sealed_html).
        $this->assertSame($v1->content_hash, $v2->prev_hash);
        $this->assertSame(hash('sha256', $v1->content_hash . $v2->sealed_html), $v2->content_hash);

        // The whole chain verifies.
        $result = DocumentSealedVersion::verifyChain($document->id);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count']);
        $this->assertSame([], $result['breaks']);

        // Immutable — a sealed copy can never be updated.
        $this->expectException(\DomainException::class);
        $v1->sealed_html = 'tampered';
        $v1->save();
    }

    public function test_tampering_with_a_sealed_copy_is_detected(): void
    {
        $s = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        $document = $s['document'];

        $this->seal()->seal($document, DocumentSealService::EVENT_CANDIDATE_SIGNED, ['template' => $s['signatureTemplate']]);
        $this->seal()->seal($document, DocumentSealService::EVENT_RECIPIENT_SIGNED, ['template' => $s['signatureTemplate']]);

        $this->assertTrue(DocumentSealedVersion::verifyChain($document->id)['ok']);

        // Tamper by editing the stored bytes directly at the DB (bypassing the model
        // guard) — exactly what tamper-evidence must catch.
        DB::table('document_sealed_versions')
            ->where('document_id', $document->id)->where('version', 1)
            ->update(['sealed_html' => 'SECRETLY ALTERED']);

        $result = DocumentSealedVersion::verifyChain($document->id);
        $this->assertFalse($result['ok']);
        $this->assertContains(1, $result['breaks']);
    }

    public function test_complete_document_seals_a_final_version_without_changing_completion(): void
    {
        $s = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        /** @var SignatureTemplate $template */
        $template = $s['signatureTemplate'];
        $document = $s['document'];

        $this->assertSame(0, DocumentSealedVersion::where('document_id', $document->id)->count());

        app(SignatureService::class)->completeDocument($template);

        // Parity: completion behaviour is unchanged — the document still completes.
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $template->fresh()->status);

        // The terminal seal wrote exactly one 'completed' sealed version.
        $completed = DocumentSealedVersion::where('document_id', $document->id)
            ->where('event_type', DocumentSealService::EVENT_COMPLETED)->get();
        $this->assertCount(1, $completed);
        $this->assertTrue(DocumentSealedVersion::verifyChain($document->id)['ok']);
    }

    public function test_seal_is_fail_open_when_there_is_no_content(): void
    {
        $s = $this->buildCanonicalTemplate111Session(sellerCount: 1);
        $document = $s['document'];
        // A document with no canonical/merged HTML has nothing to seal.
        $document->update(['web_template_data' => []]);

        $result = $this->seal()->seal($document, DocumentSealService::EVENT_SIGNED, ['template' => $s['signatureTemplate']]);

        $this->assertNull($result);
        $this->assertSame(0, DocumentSealedVersion::where('document_id', $document->id)->count());
    }
}
