<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use Tests\Concerns\RequiresChromiumPdf;
use Tests\TestCase;

/**
 * AT-387 — corrupted characters must never reach a client-facing PDF.
 *
 * Renders a document carrying the FULL risky character set through the real e-sign PDF path
 * (SigningController::wrapHtmlForPdf → generatePdfFromHtml → scripts/html-to-pdf.mjs → Chromium) and reads the
 * PDF's own text back, asserting every character survives and none turns into mojibake, a replacement
 * character or a missing-glyph box.
 *
 * The regression it pins: Word-imported templates bullet with the Symbol font's private-use code point
 * U+F0B7. No font on the server owns that code point, so the Exclusive Authority to Sell printed two empty
 * boxes where its notice bullets belong. html-to-pdf.mjs now maps Symbol/Wingdings private-use glyphs to the
 * real Unicode character before anything is measured, for every PDF (not per template).
 */
final class PdfCharacterFidelityTest extends TestCase
{
    use RequiresChromiumPdf;

    /** Every character class the ticket names, in the shapes real client documents carry them. */
    private const RISKY = [
        'curly apostrophe' => 'Seller’s',
        'curly quotes'     => '“mandate”',
        'en dash'          => 'Erf 12 – Uvongo',
        'em dash'          => 'Terms — conditions',
        'accented names'   => 'José Müller, Zoë Brontë, François Çelik',
        'bullet'           => '• item',
        'middle dot'       => 'a · b',
        'copyright'        => '© 2026',
        'euro'             => '€ 500',
        'ellipsis'         => 'and so on…',
        'rand currency'    => 'R 1 250 000',
    ];

    /** Mojibake signatures: UTF-8 bytes decoded as Windows-1252/Latin-1, and the replacement character. */
    private const MOJIBAKE = '/(â€|Ã[\x{0080}-\x{00BF}]|Â[\x{00A0}-\x{00BF}]|ï¿½|\x{FFFD})/u';

    private function pdfText(string $html): string
    {
        $this->requireChromiumPdf();
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed on this box.');
        }

        $pdf = app(SigningController::class)->generatePdfFromHtml($html, 387);
        $this->assertFileExists($pdf);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($pdf) . ' - 2>/dev/null');
        @unlink($pdf);

        // NBSP and ordinary spaces read back the same; compare on ordinary spaces.
        return str_replace("\u{00A0}", ' ', $text);
    }

    private function body(): string
    {
        $paras = '';
        foreach (self::RISKY as $text) {
            // R 1 250 000 carries its thousands separators as non-breaking spaces, as the merge pipeline emits them.
            $paras .= '<p class="corex-clause">' . str_replace('R 1 250 000', "R 1\u{00A0}250\u{00A0}000", $text) . '</p>';
        }

        return '<div class="corex-document-wrapper">' . $paras . '</div>';
    }

    public function test_full_risky_character_set_survives_the_real_pdf_path(): void
    {
        $text = $this->pdfText(app(SigningController::class)->wrapHtmlForPdf($this->body()));

        $this->assertEveryRiskyCharacterPrinted($text);
        $this->assertDoesNotMatchRegularExpression(self::MOJIBAKE, $text, 'no mojibake or replacement characters may reach the PDF');
    }

    /**
     * Characters are what is under test, not the font's kerning: pdftotext closes the gap around a middle dot
     * ("a · b" reads back "a·b"), so compare with whitespace removed. The Rand amount keeps its own spacing check.
     */
    private function assertEveryRiskyCharacterPrinted(string $text): void
    {
        $squash = static fn (string $s): string => (string) preg_replace('/\s+/u', '', $s);
        foreach (self::RISKY as $label => $expected) {
            $this->assertStringContainsString($squash($expected), $squash($text), "{$label} must print exactly as authored");
        }
        $this->assertMatchesRegularExpression('/R 1 250 000/', $text, 'a Rand amount keeps its thousands separators');
    }

    public function test_a_full_html_document_with_no_charset_declaration_still_prints_clean(): void
    {
        // A stored/imported document that already carries <html><head> but never declared its charset: the wrapper
        // injects styles and returns it as-is, so nothing else guarantees UTF-8. A big ASCII stylesheet first pushes
        // the first non-ASCII byte well past any encoding sniff window.
        $pad  = str_repeat('.x{color:#000} ', 6000);
        $html = '<!DOCTYPE html><html><head><title>t</title><style>' . $pad . '</style></head><body>' . $this->body() . '</body></html>';

        $text = $this->pdfText(app(SigningController::class)->wrapHtmlForPdf($html));

        $this->assertEveryRiskyCharacterPrinted($text);
        $this->assertDoesNotMatchRegularExpression(self::MOJIBAKE, $text);
    }

    public function test_word_symbol_and_wingdings_glyphs_print_as_real_characters_not_boxes(): void
    {
        // The Exclusive Authority to Sell stores its notice bullets as clause numbers "\u{F0B7}" (Symbol font).
        $html = '<div class="corex-document-wrapper">'
            . '<div class="corex-clause"><span class="corex-clause-number">' . "\u{F0B7}" . '</span> <span class="corex-clause-text">That you are changing your address.</span></div>'
            . '<div class="corex-clause"><span class="corex-clause-number">' . "\u{F0B7}" . '</span> <span class="corex-clause-text">State the new address.</span></div>'
            . '<div class="corex-clause">' . "\u{F0FC}" . ' Ticked item</div>'
            . '</div>';

        $text = $this->pdfText(app(SigningController::class)->wrapHtmlForPdf($html));

        $this->assertMatchesRegularExpression('/•\s+That you are changing your address\./u', $text, 'the Symbol bullet must print as a real bullet');
        $this->assertMatchesRegularExpression('/•\s+State the new address\./u', $text);
        $this->assertStringContainsString('✓ Ticked item', $text, 'the Wingdings tick must print as a real tick');
        $this->assertDoesNotMatchRegularExpression('/[\x{E000}-\x{F8FF}]/u', $text, 'no private-use character may remain in the PDF');
    }

    public function test_every_render_script_loads_the_glyph_normaliser(): void
    {
        // The normaliser is only worth anything if each Chromium script calls it BEFORE it measures or prints.
        foreach (['html-to-pdf.mjs', 'web-template-flatten.mjs'] as $script) {
            $src = (string) file_get_contents(base_path('scripts/' . $script));
            $this->assertStringContainsString("from './lib/pdf-glyph-normalise.mjs'", $src, "{$script} must import the glyph normaliser");
            $this->assertStringContainsString('await normalisePrivateUseGlyphs(page)', $src, "{$script} must run it");
            $this->assertLessThan(
                (int) strpos($src, 'page.pdf('),
                (int) strpos($src, 'await normalisePrivateUseGlyphs(page)'),
                "{$script} must normalise BEFORE page.pdf()"
            );
        }
    }
}
