<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;

/**
 * HOTFIX 2026-10-21 — the template's `~~~~OTHER_CONDITIONS~~~~` marker (clause 2.8 of the Exclusive Authority To Sell, the Open
 * mandate…) printed literally on paper copies: only the signing renderer fills it, and download-and-sign (and the non-first
 * wet-ink pack documents) never go through it. Unfilled → blank; Fill & Review conditions → printed; on every route, every
 * pack member; and the wet-ink confirmation screen shows what the PDF prints (the composed, per-party canonical).
 */
final class PaperOtherConditionsMarkerTest extends PaperRouteFixtureCase
{
    private function markerTemplate(int $n = 7, string $marker = '~~~~OTHER_CONDITIONS~~~~'): Template
    {
        // clause 2.8 plus the seller domicilium block the real mandates carry (so the screen test can see one block per seller)
        $t = $this->makeTemplate($n, '<p>2.8 Other: ' . $marker . ' END28</p>'
            . '<div class="corex-clause" data-role-block="seller" data-role-block-segment="address">Seller address: <span class="corex-field-value" data-field="seller_address"></span></div>');
        $this->templates[] = $t;

        return $t;
    }

    private function withConditions(Flow $flow, array $fill): Flow
    {
        $step = $flow->step_data;
        $step['fill_review'] = array_merge($step['fill_review'] ?? [], $fill);
        $flow->update(['step_data' => $step]);

        return $flow->fresh();
    }

    private function clause28(string $html): string
    {
        preg_match('/2\.8 Other:(.*?)END28/s', strip_tags(preg_replace('#<br\s*/?>#i', ' ', $html)), $m);

        return trim(preg_replace('/\s+/', ' ', $m[1] ?? '[clause 2.8 missing]'));
    }

    public function test_download_and_sign_unfilled_marker_prints_blank_not_tildes(): void
    {
        $flow = $this->flow([$this->markerTemplate()], 2, false);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printed(Document::findOrFail($flow->fresh()->step_data['document_id']));

        $this->assertStringNotContainsString('~~~', $printed);
        $this->assertSame('', $this->clause28($printed), 'clause 2.8 reads blank when nothing was entered');
    }

    public function test_download_and_sign_prints_the_fill_and_review_conditions(): void
    {
        $flow = $this->withConditions($this->flow([$this->markerTemplate()], 2, false), ['other_conditions_text' => "The seller keeps the garden furniture.\n\nOccupation is 1 March."]);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printed(Document::findOrFail($flow->fresh()->step_data['document_id']));

        $this->assertStringNotContainsString('~~~', $printed);
        $this->assertSame('1. The seller keeps the garden furniture. 2. Occupation is 1 March.', $this->clause28($printed));
        $this->assertSame(1, substr_count($printed, 'garden furniture'), 'printed once — not also appended as an "Additional Conditions" block');
    }

    public function test_a_single_frame_prints_without_numbering_and_text_is_escaped(): void
    {
        $flow = $this->withConditions($this->flow([$this->markerTemplate()], 2, false), ['other_condition_frames' => [['content' => 'Buyer pays <b>levies</b> & rates']]]);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printed(Document::findOrFail($flow->fresh()->step_data['document_id']));

        $this->assertStringContainsString('2.8 Other: Buyer pays &lt;b&gt;levies&lt;/b&gt; &amp; rates END28', preg_replace('/\s+/', ' ', $printed));
        $this->assertStringNotContainsString('<b>levies</b>', $printed, 'user text is escaped, never injected as markup');
        $this->assertStringNotContainsString('~~~', $printed);
    }

    /** @return array<string, array{0:int,1:string}> */
    public static function oddMarkers(): array
    {
        return [
            'misspelt' => [9, '~~~~Other Contitions~~~~'],
            'included items' => [10, '~~~~INCLUDED_ITEMS~~~~'],
            'custom' => [11, '~~~~CUSTOM:Special terms~~~~'],
            'html inside' => [12, '~~~~<span>other_conditions</span>~~~~'],
        ];
    }

    /**
     * @dataProvider oddMarkers
     */
    public function test_misspelt_custom_and_included_items_markers_never_print_literally(int $n, string $marker): void
    {
        $flow = $this->flow([$this->markerTemplate($n, $marker)], 2, false);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printed(Document::findOrFail($flow->fresh()->step_data['document_id']));

        $this->assertStringNotContainsString('~~~', $printed, $marker);
    }

    public function test_a_misspelt_other_conditions_marker_still_gets_the_agents_conditions(): void
    {
        $flow = $this->withConditions($this->flow([$this->markerTemplate(7, '~~~~Other Contitions~~~~')], 2, false), ['other_conditions_text' => 'Subject to bond approval.']);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $printed = $this->printed(Document::findOrFail($flow->fresh()->step_data['document_id']));

        $this->assertSame('Subject to bond approval.', $this->clause28($printed));
    }

    public function test_a_pack_gives_each_document_only_its_own_conditions(): void
    {
        $tpls = [$this->markerTemplate(7), $this->makeTemplate(8, '<p>2.8 Other: ~~~~OTHER_CONDITIONS~~~~ END28</p>')];
        $this->templates[] = $tpls[1];
        $flow = $this->withConditions($this->flow($tpls, 2, true), ['other_condition_frames' => [
            ['content' => 'Condition for document one', 'target_doc_index' => 0],
            ['content' => 'Condition for document two', 'target_doc_index' => 1],
        ]]);
        $this->runRoute('docuperfect.esign.prepareDownload', $flow);
        $docs = $this->packDocuments($flow);

        $this->assertSame('Condition for document one', $this->clause28($this->printed($docs[0])));
        $this->assertSame('Condition for document two', $this->clause28($this->printed($docs[1])));
        $this->assertStringNotContainsString('~~~', $this->printed($docs[0]) . $this->printed($docs[1]));
    }

    public function test_wet_ink_first_document_is_composed_and_the_confirmation_screen_shows_what_the_pdf_prints(): void
    {
        $flow = $this->withConditions($this->flow([$this->markerTemplate()], 3, false), ['other_conditions_text' => 'Occupation is 1 March.']);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
        $doc = Document::findOrFail($flow->fresh()->step_data['document_id']);

        // The stored raw body still carries the marker (compose() starts from it) — unchanged from before this hotfix …
        $this->assertStringContainsString('~~~~OTHER_CONDITIONS~~~~', (string) $doc->web_template_data['merged_html']);
        // … the composed canonical (what the PDF prints) has it filled, and one address block per seller:
        $canonical = (string) $doc->web_template_data['canonical_html'];
        $this->assertStringNotContainsString('~~~', $canonical);
        $this->assertStringContainsString('Occupation is 1 March.', $canonical);

        // The confirmation SCREEN used to show the raw merged_html (marker + the three sellers merged into one block).
        $screen = $this->actingAs($this->agent)->get(route('docuperfect.esign.wetInkConfirmation', $flow->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('~~~~', $screen);
        $this->assertStringContainsString('Occupation is 1 March.', $screen);
        // (the preview may sit inside an escaped attribute — decode before counting)
        $this->assertSame(3, substr_count(html_entity_decode(html_entity_decode($screen)), 'data-field="seller_address'), 'the screen shows one domicilium block per seller, like the PDF');
    }

    public function test_wet_ink_pack_non_first_documents_are_resolved_too(): void
    {
        $tpls = [$this->markerTemplate(7), $this->makeTemplate(8, '<p>2.8 Other: ~~~~OTHER_CONDITIONS~~~~ END28</p>')];
        $this->templates[] = $tpls[1];
        $flow = $this->withConditions($this->flow($tpls, 2, true), ['other_condition_frames' => [['content' => 'Only for the second document', 'target_doc_index' => 1]]]);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
        $docs = $this->packDocuments($flow);

        $this->assertStringNotContainsString('~~~', $this->printed($docs[1]));
        $this->assertSame('Only for the second document', $this->clause28($this->printed($docs[1])));
    }

    public function test_a_stored_document_with_no_canonical_never_prints_a_marker(): void
    {
        $flow = $this->flow([$this->markerTemplate()], 2, false);
        $this->runRoute('docuperfect.esign.prepareWetInk', $flow);
        $doc = Document::findOrFail($flow->fresh()->step_data['document_id']);
        $data = $doc->web_template_data;
        unset($data['canonical_html']);          // e.g. a document whose compose never ran
        $doc->update(['web_template_data' => $data]);

        $screen = $this->actingAs($this->agent)->get(route('docuperfect.esign.wetInkConfirmation', $flow->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('~~~~', $screen);
    }
}
