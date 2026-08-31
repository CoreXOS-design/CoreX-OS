<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-387-pack-conditions (Johan 2026-08-31, cc1's overnight finding — proven
 * independently by two lanes) — a condition a recipient adds during signing,
 * and the agent then accepts and initials, is silently dropped from the
 * finished document when that document is a PACK.
 *
 * Root cause, confirmed by direct reproduction against real code
 * (CanonicalDocumentRenderer::refreshInsertableBlocks() ~line 941-960):
 * insertable-block discovery reads block ids from
 * $document->template?->insertable_blocks — CDS-parsed per-template
 * metadata, always the BARE id ("other_conditions"), oblivious to pack
 * merging. A pack's actual rendered canonical_html carries a PACK-SCOPED id
 * ("other_conditions__<random>", per LegacyOtherConditionsBridge's own
 * resolveBlockId() convention) on the SAME div. The XPath lookup
 * `[@data-block-id="{bare id}"]` against HTML that only contains the
 * scoped id returns zero nodes, so `if ($nodes->length === 0) { continue; }`
 * skips the block entirely — the DocumentCondition row and the agent's
 * ConditionInitial are both written correctly to the DB, but
 * refreshInsertableBlocks() never re-bakes the block, so neither the
 * recipient's condition text nor the agent's initial ever reaches the
 * stored canonical_html or anything rendered from it (PDF, review page,
 * print). This bites BOTH the recipient's own addCondition() call and the
 * agent's initialCondition() accept call — both end in the same broken
 * refresh. This test drives the agent's accept step (SignatureController::
 * initialCondition(), the fix's most direct trigger) against a hand-built
 * fixture verified byte-for-byte against the real renderer's own output
 * shape (InsertableBlockRenderer::renderBlockPartialInner()'s wrapper div).
 *
 * Test 2 (single-document boundary guard) proves the SAME action correctly
 * bakes the condition when the block id is bare-to-bare (no pack scoping in
 * play) — so if these two paths are ever unified, a regression here is
 * caught immediately rather than assumed fixed by association.
 */
final class PackConditionBakingRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const CONDITION_TEXT = 'ZZZ-AT387 the seller wants a 60 day transfer period instead of 30.';

    /**
     * @return array{document: Document, condition: DocumentCondition, agent: User}
     */
    private function fixture(bool $isPack): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Pack Cond Agency ' . Str::random(6), 'slug' => 'zzz-packcond-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Pack Cond Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Pack Cond Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);

        // The real fix will call InsertableBlockRenderer to resolve this;
        // today CanonicalDocumentRenderer sources block ids from
        // insertable_blocks metadata, which is always bare — mirrored here
        // exactly as the real CDS parser produces it.
        $tplA = DocuperfectTemplate::create([
            'name' => 'ZZZ Pack Cond Template A', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $agencyId,
            'insertable_blocks' => [['id' => 'other_conditions', 'purpose' => 'other_conditions', 'auto_number' => true, 'label' => 'Other Conditions']],
        ]);

        // A pack's rendered block id is scoped; a single document's is bare
        // — this is the ONLY structural difference between the two fixtures,
        // matching exactly what the two runtime paths actually produce.
        $blockId = $isPack ? 'other_conditions__zzzpackcond' : 'other_conditions';

        $canonicalHtml = '<div class="corex-document-wrapper"><p>ZZZ Body A clause text.</p>'
            . '<div class="insertable-block" data-block-id="' . $blockId . '" data-purpose="other_conditions" data-auto-number="1" style="margin:1rem 0;">'
            . '<p class="no-conditions-yet" style="color:#6b7280; font-style:italic;">No conditions yet.</p>'
            . '</div></div>';
        if ($isPack) {
            $canonicalHtml .= '<div class="corex-document-wrapper"><p>ZZZ Body B clause text.</p></div>';
        }

        $templateIds = $isPack ? [$tplA->id, DocuperfectTemplate::create([
            'name' => 'ZZZ Pack Cond Template B', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [],
            'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ])->id] : [$tplA->id];

        $document = Document::create([
            'name' => 'ZZZ Pack Cond Doc (' . ($isPack ? 'pack' : 'single') . ')', 'document_type' => 'mandate',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'owner_id' => $agent->id, 'template_id' => $tplA->id,
            'web_template_data' => [
                'template_ids' => $templateIds,
                'merged_html' => $canonicalHtml,
                'canonical_html' => $canonicalHtml,
            ],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, 'created_by' => $agent->id,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => $agent->name, 'signer_email' => 'zzzpackcondagent@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_COMPLETED, 'signing_order' => 1,
        ]);

        // The recipient's own condition — block_id here is EXACTLY what a
        // real recipient's addCondition() call stores (read off the live
        // page's data-block-id, i.e. whatever the renderer actually used).
        $condition = DocumentCondition::create([
            'signature_template_id' => $template->id, 'agency_id' => $agencyId,
            'block_id' => $blockId, 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => self::CONDITION_TEXT,
            'is_locked' => false, 'is_override' => false,
            'added_via' => 'recipient_signing', 'source' => 'custom',
        ]);

        return ['document' => $document, 'condition' => $condition, 'agent' => $agent];
    }

    /** Generate a real PDF from the given HTML and return its extracted text via pdftotext. */
    private function extractPdfText(string $html, int $documentId): string
    {
        $pdfPath = app(SigningController::class)->generatePdfFromHtml($html, $documentId);
        $this->assertNotNull($pdfPath, 'PDF generation must succeed to prove the artifact — a null path means the test cannot speak to the real rendered PDF at all');
        $this->assertFileExists($pdfPath);

        $txtPath = $pdfPath . '.txt';
        exec('pdftotext ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($txtPath) . ' 2>&1');
        $text = file_exists($txtPath) ? (string) file_get_contents($txtPath) : '';

        @unlink($pdfPath);
        @unlink($txtPath);

        return $text;
    }

    public function test_recipient_added_condition_accepted_by_agent_reaches_finished_pack_document(): void
    {
        ['document' => $document, 'condition' => $condition, 'agent' => $agent] = $this->fixture(isPack: true);

        $this->assertStringNotContainsString(
            self::CONDITION_TEXT,
            $document->fresh()->web_template_data['canonical_html'],
            'fixture sanity — the condition must NOT be baked in yet, or the test proves nothing'
        );

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['initial_image' => 'data:image/png;base64,iVBORw0KGgo=']);
        $request->setUserResolver(fn () => $agent);
        $response = app(SignatureController::class)->initialCondition($request, $document->fresh(), $condition->fresh());
        $this->assertTrue((bool) ($response->getData(true)['ok'] ?? false), 'the accept endpoint itself must report success — this is NOT what the bug is about, but it must hold for the rest of the test to mean anything');

        $html = (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString(
            self::CONDITION_TEXT,
            $html,
            'AT-387-pack-conditions: a recipient-added condition, accepted by the agent, must be baked into the finished PACK document\'s canonical_html — it is being silently dropped because refreshInsertableBlocks() looks up the block by its BARE id while a pack renders it under a pack-scoped id, so the XPath match finds nothing and the whole block is skipped'
        );

        $pdfText = $this->extractPdfText($html, $document->id);
        $this->assertStringContainsString(
            self::CONDITION_TEXT,
            $pdfText,
            'the agreed condition text must reach the ACTUAL RENDERED PDF, not just sit in an unbaked DB row — a passing canonical_html check with a blank PDF would be exactly the kind of false comfort this test exists to prevent'
        );
    }

    public function test_recipient_added_condition_accepted_by_agent_reaches_finished_single_document(): void
    {
        // Boundary guard — the SAME action, on a single (non-pack) document,
        // where the block id is bare-to-bare. This must PASS today; if it
        // ever starts failing alongside the pack test, the two code paths
        // have been unified in a way that broke BOTH, not just packs.
        ['document' => $document, 'condition' => $condition, 'agent' => $agent] = $this->fixture(isPack: false);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['initial_image' => 'data:image/png;base64,iVBORw0KGgo=']);
        $request->setUserResolver(fn () => $agent);
        $response = app(SignatureController::class)->initialCondition($request, $document->fresh(), $condition->fresh());
        $this->assertTrue((bool) ($response->getData(true)['ok'] ?? false));

        $html = (string) ($document->fresh()->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString(
            self::CONDITION_TEXT,
            $html,
            'boundary guard — a single (non-pack) document with a bare block id must still bake the accepted condition correctly; this is the behaviour packs are missing'
        );

        $pdfText = $this->extractPdfText($html, $document->id);
        $this->assertStringContainsString(self::CONDITION_TEXT, $pdfText);
    }
}
