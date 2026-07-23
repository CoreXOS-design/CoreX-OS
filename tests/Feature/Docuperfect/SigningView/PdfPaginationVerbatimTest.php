<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignaturePdfService;
use Tests\TestCase;

/**
 * AT-328/AT-332 — the emailed/generated PDF must paginate to match the signer's
 * on-screen document. When the render input is the signer's already-paginated DOM
 * (signed_paginated_html: a sequence of fixed 210x297mm .corex-a4-page boxes), the
 * default PDF CSS (@page 18/20mm margins + counter(pages) footer, wrapper zoom:0.82,
 * .corex-a4-page reset to min-height:auto/padding:0) re-flowed 3 on-screen pages into
 * a 4-page PDF with a mismatched "Page X of Y" footer. wrapHtmlForPdf must instead map
 * each .corex-a4-page to EXACTLY one physical A4 page for pre-paginated input, and keep
 * the default fit-scale flow for un-paginated canonical input.
 */
final class PdfPaginationVerbatimTest extends TestCase
{
    private function wrap(string $html): string
    {
        return app(SigningController::class)->wrapHtmlForPdf($html);
    }

    public function test_pre_paginated_input_maps_each_a4_page_to_one_physical_page(): void
    {
        $out = $this->wrap(
            '<div class="corex-document-wrapper">'
            . '<div class="corex-a4-page">page one</div>'
            . '<div class="corex-a4-page">page two</div>'
            . '</div>'
        );

        $this->assertStringContainsString('@page { size: A4; margin: 0;', $out, 'pre-paginated render must zero the @page margins');
        $this->assertStringContainsString('page-break-after: always', $out, 'each .corex-a4-page must hard-break onto its own page');
        $this->assertStringContainsString('.corex-document-wrapper { zoom: 1', $out, 'the 0.82 fit-scale must be dropped for pre-paginated input');
        $this->assertStringContainsString('min-height: 297mm', $out, 'each page box is at least a full A4 sheet');

        // LEGAL WYSIWYG (Johan): the emailed PDF must NEVER hide signed content. The
        // page box grows to fit (min-height + height:auto + overflow:visible); a fixed
        // height with overflow:hidden clipped ~40% of a page below the 297mm cut.
        $this->assertStringContainsString('height: auto !important', $out, 'no fixed height — a fixed height is what clipped content');
        $this->assertStringContainsString('overflow: visible !important', $out, 'content must never be clipped (override wins the cascade, appended last)');
    }

    public function test_canonical_unpaginated_input_keeps_default_flow_css(): void
    {
        $out = $this->wrap(
            '<div class="corex-document-wrapper"><p>flowing canonical body, no page boxes</p></div>'
        );

        $this->assertStringNotContainsString('one .corex-a4-page = one physical page', $out, 'canonical input must NOT receive the pre-paginated override');
        $this->assertStringContainsString('zoom: 0.82', $out, 'canonical input keeps the default fit-scale');
    }

    /**
     * The "Page X of Y" footer MUST be position:absolute in the emailed PDF, exactly
     * as the signing view renders it. When the PDF omitted this rule the footer flowed
     * IN-LINE and added ~8px per box — enough to push a near-full page PAST one physical
     * A4 sheet and spill its bottom onto a near-blank next page (docs 455/454 rendered
     * 5 logical pages as 7 physical with 2 blanks). Absolute-positioned it consumes no
     * flow height, so the box the paginator measured is the box that prints.
     */
    public function test_pre_paginated_pdf_makes_page_number_footer_absolute(): void
    {
        $out = $this->wrap(
            '<div class="corex-document-wrapper">'
            . '<div class="corex-a4-page">page one<div class="page-number">Page 1 of 2</div></div>'
            . '<div class="corex-a4-page">page two<div class="page-number">Page 2 of 2</div></div>'
            . '</div>'
        );

        $this->assertMatchesRegularExpression(
            '/\.corex-a4-page\s+\.page-number\s*\{[^}]*position:\s*absolute/is',
            $out,
            'the page-number footer must be absolutely positioned so it consumes no flow height and cannot spill the box onto a near-blank sheet'
        );
        $this->assertStringContainsString('position: relative', $out, '.corex-a4-page must be the positioning context for the absolute footer');
    }

    /**
     * AT-332 fix — the signed PDF must NOT render the signer's frozen .corex-a4-page
     * DOM verbatim. Those boxes were sized by the signing browser's fonts; the emailed
     * PDF renders with substitute (taller) fonts, so a verbatim box overflows one A4
     * sheet and spills. injectInitialsPagination must always re-flow already-paginated
     * signed content through the shared measure-and-fit engine (idempotent, ink-
     * preserving) inside Chromium — via the __corexRepaginate hook html-to-pdf.mjs runs
     * after fonts + print media are applied. Pagination is presentation, not signed
     * content: re-flowing does not change what was signed.
     */
    public function test_signed_paginated_html_is_repaginated_not_verbatim(): void
    {
        $signed = '<div class="corex-document-wrapper" data-disclosure-doc="d">'
            . '<div class="corex-a4-page">clause body'
            . '<div class="corex-page-initials-row"><div class="corex-page-initials" data-marker-type="initial" data-marker-party="seller" data-signed="true"><img src="data:image/png;base64,AAAA"></div></div>'
            . '<div class="page-number">Page 1 of 1</div></div>'
            . '</div>';

        $document = new Document();
        $document->signed_paginated_html = $signed;
        $document->web_template_data = [
            'signed_initials' => ['seller' => ['seller-init-0' => 'data:image/png;base64,AAAA']],
        ];

        $template = new SignatureTemplate();
        $template->parties_json = [['role' => 'seller', 'role_label' => 'seller']];
        $template->setRelation('document', $document);

        $out = app(SignaturePdfService::class)->buildInjectedRenderHtml($template);

        // Re-pagination engine injected (NOT returned verbatim).
        $this->assertStringContainsString('paginateDocument', $out, 'the shared paginator must be injected');
        $this->assertStringContainsString('__corexRepaginate', $out, 'the deterministic post-fonts re-pagination hook must be present');
        $this->assertStringContainsString('id="pdfDocContent"', $out, 'signed content must be wrapped for the paginator to target');
        $this->assertStringContainsString('c.dataset.paginated="true"', $out, 'already-paginated input must trigger the idempotent de-paginate/re-flow path');
        $this->assertNotSame($signed, $out, 'the signer\'s frozen DOM must never be rendered verbatim');
    }
}
