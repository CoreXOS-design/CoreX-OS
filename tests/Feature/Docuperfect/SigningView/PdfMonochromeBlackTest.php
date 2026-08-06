<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use Tests\TestCase;

/**
 * AT-374 — the FILED/emailed/printed legal PDF renders in MONOCHROME BLACK; the on-screen signing /
 * Fill & Review views KEEP their colour (red strikes, yellow reword inserts) for usability.
 *
 * The PDF is produced only through SigningController::wrapHtmlForPdf() (SignaturePdfService's client +
 * internal copies + audit cert). A monochrome override is appended LAST to that wrapper's <style> so it
 * wins over the CDS + change-mark styles: every amendment mark, Other-Conditions block and amendment
 * initial block renders black with no colour fill, and every signature/initial (drawn or typed) is forced
 * to solid black regardless of the captured ink colour. The screen path (the shared change-mark stylesheet)
 * is NOT modified — this test guards that separation so the two never re-couple.
 */
final class PdfMonochromeBlackTest extends TestCase
{
    private function wrap(string $fragment): string
    {
        return app(SigningController::class)->wrapHtmlForPdf($fragment);
    }

    /** A representative signed/amended body: red strike, yellow reword, initial row, a coloured signature. */
    private function sampleBody(): string
    {
        return '<div class="corex-document-wrapper">'
            . '<div class="corex-clause">Fee '
            . '<span class="change-inline" data-strikethrough-applied="1" data-change-id="c1">'
            . '<del class="change-del" data-change-id="c1" style="color:#dc2626">seven percent</del> '
            . '<ins class="change-ins" data-change-id="c1" style="background:#fde68a">six percent</ins></span>.</div>'
            . '<div class="change-initial-row" data-change-id="c1" style="background:#fef9c3">'
            . '<span class="cir-label">Initial this change:</span>'
            . '<span class="cir-slot cir-filled"><span class="cir-name">Agent</span>'
            . '<span class="cir-ink"><img class="cir-ink-img" src="data:image/png;base64,AAAA"></span></span></div>'
            . '<span data-marker-type="signature" data-marker-party="agent"><img class="web-sig-signed-img" src="data:image/png;base64,AAAA"></span>'
            . '</div>';
    }

    public function test_pdf_wrapper_appends_the_monochrome_override(): void
    {
        $out = $this->wrap($this->sampleBody());

        $this->assertStringContainsString('MONOCHROME BLACK DOCUMENT CONTENT', $out, 'the PDF wrapper must carry the AT-374 monochrome override');
        // The override must sit AFTER the CDS stylesheet so it wins the cascade.
        $cdsPos  = strpos($out, 'CDS Document Stylesheet');
        $monoPos = strpos($out, 'MONOCHROME BLACK DOCUMENT CONTENT');
        $this->assertNotFalse($cdsPos);
        $this->assertNotFalse($monoPos);
        $this->assertGreaterThan($cdsPos, $monoPos, 'the monochrome override must be appended AFTER the CDS styles to win');
    }

    public function test_pdf_forces_marks_and_signatures_black(): void
    {
        $out = $this->wrap($this->sampleBody());

        // Struck removals + reword inserts + amendment initial blocks → black, no colour fill.
        $this->assertMatchesRegularExpression('/\.change-del[^{]*\{[^}]*color:\s*#000/is', $out, 'strike text must be forced black');
        $this->assertStringContainsString('text-decoration-color: #000', $out, 'the strike-through line must be black, not red');
        $this->assertMatchesRegularExpression('/\.change-ins[^{]*\{[^}]*background:\s*transparent/is', $out, 'reword inserts must lose their colour highlight');
        // Signatures + initials forced to solid black (brightness(0)).
        $this->assertStringContainsString('brightness(0)', $out, 'signature/initial images must be forced to solid black');
        // The HEADER / letterhead stays full colour — the override must NOT desaturate the whole wrapper and
        // must NOT blanket-blacken every wrapper descendant; content is targeted by content classes instead.
        $this->assertDoesNotMatchRegularExpression('/\.corex-document-wrapper\s*\{[^}]*grayscale/is', $out, 'the whole document must NOT be desaturated — the header/letterhead keeps its colour');
        $this->assertDoesNotMatchRegularExpression('/\.corex-document-wrapper\s+\*[^{]*\{[^}]*color:\s*#000/is', $out, 'must not blanket-blacken every wrapper descendant (would blacken the header)');
        $this->assertStringContainsString('.corex-clause', $out, 'black-ink rules must be scoped to document content classes');
    }

    public function test_screen_change_mark_styles_stay_coloured(): void
    {
        // The shared on-screen stylesheet must NOT carry the PDF monochrome forcing — the screen keeps colour.
        $screen = (string) file_get_contents(resource_path('views/docuperfect/shared/_change-mark-styles.blade.php'));
        $this->assertStringNotContainsString('MONOCHROME BLACK (PDF/print output', $screen, 'screen styles must not carry the PDF monochrome override');
        $this->assertStringNotContainsString('grayscale(1)', $screen, 'screen must not be desaturated');
        // And the red strike colour still lives in the screen stylesheet (colour retained for usability).
        $this->assertMatchesRegularExpression('/change-del/i', $screen, 'screen keeps its change-mark styling');
    }
}
