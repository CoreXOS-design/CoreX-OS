<?php

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use Tests\TestCase;

/**
 * The Word source's running page header / footer reach both PDF routes (spec cds-reimport-from-word.md section 10):
 *  - print-and-sign (dompdf): the frame is set first in <body> (dompdf repeats position:fixed only there) with the
 *    fixed-in-margin stylesheet; hoistPageFrame() is the one lifting step, tested on the parser's own output.
 *  - signed PDF (Chromium): corex-document.css loads whole (its @media print rules repeat the frame); Chromium cannot count
 *    pages from content, so the page number part is switched off there rather than printing "0" on every page.
 * DB-free.
 */
class SourcePageFramePdfTest extends TestCase
{
    public function test_the_signed_pdf_shell_loads_the_frame_css_and_switches_off_the_uncountable_page_number(): void
    {
        $html = app(SigningController::class)->wrapHtmlForPdf('<div class="corex-page-footer"><div class="corex-page-line"><span class="corex-page-left">VIPS</span><span class="corex-page-right">Page <span class="corex-page-number"></span></span></div></div><p>Body</p>');

        $this->assertStringContainsString('.corex-page-footer { position: fixed; bottom: -0.42in', $html, 'the stylesheet that repeats the footer on paper is in the PDF shell');
        $this->assertStringContainsString('.corex-page-footer .corex-page-right { display: none !important; }', $html);
        $this->assertStringContainsString('<p>Body</p>', $html);
    }

    public function test_the_print_and_sign_pdf_lifts_the_frame_to_the_top_of_the_body(): void
    {
        $this->assertStringContainsString('position: fixed', CdsRendererService::PDF_PAGE_FRAME_CSS);
        $this->assertStringContainsString('counter(page)', CdsRendererService::PDF_PAGE_FRAME_CSS, 'dompdf numbers the pages');

        $cds = [
            'sections' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Body']]]],
            'page_header' => ['lines' => [['text' => 'Running header']]],
            'page_footer' => ['lines' => [['text' => 'Doc line', 'right' => 'Page {PAGE}']], 'rule' => true],
        ];
        [$frame, $rest] = CdsRendererService::hoistPageFrame((new CdsRendererService())->render($cds));

        $this->assertStringContainsString('Running header', $frame);
        $this->assertStringContainsString('Doc line', $frame);
        $this->assertStringNotContainsString('Running header', $rest);
        $this->assertStringContainsString('Body', $rest);
    }
}
