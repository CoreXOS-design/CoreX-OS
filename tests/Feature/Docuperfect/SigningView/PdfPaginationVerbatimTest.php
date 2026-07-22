<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
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
        $this->assertStringContainsString('height: 297mm', $out, 'each page box must be a full A4 height (no re-flow)');
    }

    public function test_canonical_unpaginated_input_keeps_default_flow_css(): void
    {
        $out = $this->wrap(
            '<div class="corex-document-wrapper"><p>flowing canonical body, no page boxes</p></div>'
        );

        $this->assertStringNotContainsString('one .corex-a4-page = one physical page', $out, 'canonical input must NOT receive the pre-paginated override');
        $this->assertStringContainsString('zoom: 0.82', $out, 'canonical input keeps the default fit-scale');
    }
}
