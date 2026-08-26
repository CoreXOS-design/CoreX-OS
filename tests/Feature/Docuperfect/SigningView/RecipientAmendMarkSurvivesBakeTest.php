<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Services\Docuperfect\CanonicalDocumentRenderer;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-373 Issue D — a recipient's amendment (strike marks + change-initial rows WITH their captured
 * initial ink) must survive the completeWeb bake onto the served canonical document.
 *
 * Johan's "monday morning test" (doc 718): the recipient amended + initialed while the doc was still
 * v0, so the marks landed in merged_html (amendSource picks merged at v0). completeWeb baked the STALE
 * stored canonical_html — which never received the amendment — and froze it at v>=1, dropping the WHOLE
 * amendment from the served doc: the "Initialed by …" attribution survived (derived from merged's filled
 * slot) but the INITIAL slot boxes rendered empty (—). Fix: completeWeb re-derives the canonical from
 * merged_html before baking when the doc is not yet baked, so the marks + ink are carried in.
 */
final class RecipientAmendMarkSurvivesBakeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_recipient_amendment_initial_mark_survives_the_bake_onto_canonical(): void
    {
        $img = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $documentId = $session['document']->id;
        $tpl = $session['signatureTemplate'];
        $seller = $this->recipient($session['recipients'], 'seller', 1);

        // Send-time snapshot: compose + store the canonical from the ORIGINAL body (v0). This is the
        // stale non-empty canonical that the bug bakes verbatim — the precondition that reproduces it.
        app(CanonicalDocumentRenderer::class)->composeAndStore($tpl);
        $canonBefore = (string) (Document::find($documentId)->web_template_data['canonical_html'] ?? '');
        $this->assertNotSame('', trim($canonBefore), 'precondition: a non-empty v0 canonical snapshot exists');

        // The recipient amends a clause at their turn (real external endpoint → merged_html at v0),
        // then initials the change (real endpoint). Both land in merged_html; the stale canonical is untouched.
        $edit = $this->postJson('/sign/' . $seller->token . '/edit-selection', [
            'selected' => 'Authority', 'prefix' => 'Exclusive ', 'suffix' => ' to Sell',
            'replacement' => 'Mandate', 'mode' => 'inline',
        ]);
        $edit->assertOk();
        $changeId = $edit->json('change_id');
        $this->assertNotEmpty($changeId, 'precondition: the recipient edit authored');

        $this->postJson('/sign/' . $seller->token . '/initial-change', [
            'change_id' => $changeId, 'initial_image' => $img,
        ])->assertOk();

        // Precondition: the amendment + the seller's initial live in merged_html but NOT the stale canonical.
        $sel = app(SelectionEditService::class);
        $wtdMid = Document::find($documentId)->web_template_data;
        $this->assertTrue($sel->rowSlotFilled($wtdMid['merged_html'], $changeId, 'seller'),
            'precondition: seller initial is in merged_html');
        $this->assertFalse($sel->rowSlotFilled($wtdMid['canonical_html'] ?? '', $changeId, 'seller'),
            'precondition: the stale canonical does NOT yet carry the seller initial (reproduces the drop)');

        // The recipient submits (completeWeb bakes). The bake must carry the merged amendment into canonical.
        $resp = $this->postJson('/sign/' . $seller->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['owner_party-sig-0' => $img, 'seller-init-0' => $img],
            'initials'     => [],
            'field_values' => ['seller_id_number' => '8801015800088'],
        ]);
        $this->assertNotSame(422, $resp->getStatusCode(), 'the amending recipient must be able to submit');

        // THE assertion — the served canonical now carries the amendment AND the recipient's initial MARK,
        // not just the attribution. Baked (version >= 1), so this is the artifact the completed PDF renders.
        $doc = Document::find($documentId);
        $canon = (string) ($doc->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString('data-change-id="' . $changeId . '"', $canon,
            'the amendment change mark survives the bake onto the served canonical');
        $this->assertTrue($sel->rowSlotFilled($canon, $changeId, 'seller'),
            "the recipient's initial slot is FILLED on the served canonical (the mark, not just attribution)");
        $this->assertStringContainsString('cir-ink-img', $canon,
            'the captured initial IMAGE ink is present in the served canonical');
        $this->assertGreaterThanOrEqual(1, (int) ($doc->web_template_data['canonical_version'] ?? 0),
            'the doc is baked (version >= 1) — the assertions are against the served artifact');
    }
}
