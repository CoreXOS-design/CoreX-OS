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

    /** FIX B — the completed-doc captions + Schedule + amendment marks are forced black in the PDF. */
    public function test_fixb_captions_schedule_and_marks_forced_black(): void
    {
        $out = $this->wrap($this->sampleBody());
        // "Signed by" captions + "Initialed by" pills → black (they render green on screen).
        $this->assertMatchesRegularExpression('/\.corex-sig-caption[^{]*\{[^}]*color:\s*#000/is', $out, '"Signed by" caption must be black in the PDF');
        $this->assertMatchesRegularExpression('/\.change-initialed[^{]*\{[^}]*color:\s*#000/is', $out, '"Initialed by" pill must be black in the PDF');
        // Inserted (reword) text stays visible via a BLACK UNDERLINE (not a yellow highlight).
        $this->assertMatchesRegularExpression('/\.change-ins[^{]*\{[^}]*text-decoration:\s*underline/is', $out, 'reword inserts must be underlined (distinguishable, no highlight)');
        // The appended Schedule of Amendments is blackened where it IS kept (the audit copy).
        $this->assertStringContainsString('.change-history-page', $out, 'the amendment Schedule must be forced black on the audit copy');
        $this->assertMatchesRegularExpression('/\.change-history-page span\[style\*="line-through"\]/i', $out, 'Schedule Removed column = black line-through');
        $this->assertMatchesRegularExpression('/\.change-history-page span\[style\*="background"\][^{]*\{[^}]*underline/is', $out, 'Schedule Inserted column = black underline (drops the yellow highlight)');
    }

    /** FIX B — the "Signed by" caption carries the stable PDF hook class while keeping its screen colour. */
    public function test_fixb_signed_by_caption_has_pdf_hook_class(): void
    {
        $src = (string) file_get_contents(app_path('Services/Docuperfect/CanonicalInkComposer.php'));
        $this->assertStringContainsString("'corex-sig-caption'", $src, 'the Signed-by caption must carry the corex-sig-caption class for the PDF override');
        $this->assertStringContainsString('#059669', $src, 'the caption keeps its green inline style for the SCREEN');
    }

    /** FIX A — the recipient (client) PDF excludes the Schedule of Amendments; the audit copy keeps it. */
    public function test_fixa_strips_schedule_from_recipient_copy_only(): void
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">Body text here.</p></div>';
        $schedule = '<div class="change-history-page" style="page-break-before:always;">'
            . '<h3>Schedule of Amendments</h3><p>desc</p>'
            . '<table><thead><tr><th>#</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table></div>';
        $full = $body . $schedule;

        $svc = app(\App\Services\Docuperfect\SignaturePdfService::class);
        $m = new \ReflectionMethod(\App\Services\Docuperfect\SignaturePdfService::class, 'stripAmendmentSchedule');
        $m->setAccessible(true);
        $clientHtml = $m->invoke($svc, $full);

        // Recipient copy: NO Schedule, but the document body is intact + byte-identical.
        $this->assertStringNotContainsString('change-history-page', $clientHtml, 'recipient copy must NOT contain the Schedule');
        $this->assertStringNotContainsString('Schedule of Amendments', $clientHtml);
        $this->assertStringContainsString('Body text here.', $clientHtml, 'the document body is kept');
        $this->assertSame($body, trim($clientHtml), 'only the appendix is removed — the rest is byte-identical');
        // Audit copy uses the UNSTRIPPED html → keeps the Schedule.
        $this->assertStringContainsString('change-history-page', $full);
    }
}
