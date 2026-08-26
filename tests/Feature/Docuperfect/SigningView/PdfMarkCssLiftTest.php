<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignaturePdfService;
use Tests\TestCase;

/**
 * MARK-RENDER CONTRACT (2026-08-03) — Item 3 render-consistency regression.
 *
 * The signing/review SCREENS @include the whole a4-page-styles partial (its <style>
 * + <script>), so every mark ([data-marker-type], .corex-ink--*, .sig-cell-line,
 * .corex-page-initials) is sized by ONE uniform contract. The signed/filed PDF path
 * previously lifted only the <script> (pagination JS), NOT that mark CSS — so in the
 * PDF each mark fell back to its DIVERGENT origin styling (component .sig-cell-line vs
 * MDF .mdf-sig-line vs baked inline 56/38px vs per-page 84x40 box), and signatures /
 * initials rendered inconsistently mark-to-mark and page-to-page.
 *
 * SignaturePdfService::buildInjectedRenderHtml() now lifts the uniform mark-sizing CSS
 * region (the /* PDF-MARK-CSS-START * / … /* PDF-MARK-CSS-END * / block of
 * a4-page-styles.blade.php) into the PDF's <style>, so "print == screen" by
 * construction. This test pins that lift and pins that a signature mark AND an initial
 * mark both carry the same uniform sizing contract from that single source.
 *
 * Test-only — asserts existing runtime behaviour; changes nothing.
 */
final class PdfMarkCssLiftTest extends TestCase
{
    /**
     * The mark-CSS region, extracted from the ONE partial the ceremony/agent-review
     * screens use, by the SAME delimiter regex SignaturePdfService uses. Tying the
     * assertion to this source proves the PDF cannot drift from the screen.
     */
    private function markCssRegionFromPartial(): string
    {
        $path = resource_path('views/docuperfect/signatures/partials/a4-page-styles.blade.php');
        $content = (string) file_get_contents($path);
        $this->assertMatchesRegularExpression(
            '/\/\*\s*PDF-MARK-CSS-START.*?\*\/(.*?)\/\*\s*PDF-MARK-CSS-END\s*\*\//is',
            $content,
            'a4-page-styles must carry the PDF-MARK-CSS-START/END delimiters that fence the uniform mark contract',
        );
        preg_match('/\/\*\s*PDF-MARK-CSS-START.*?\*\/(.*?)\/\*\s*PDF-MARK-CSS-END\s*\*\//is', $content, $m);

        return trim($m[1]);
    }

    /** A minimal, in-memory signed canonical carrying one signature mark and one initial mark. */
    private function templateWithBothMarks(): SignatureTemplate
    {
        $body = '<div class="corex-document-wrapper">'
            . '<span class="sig-cell-line" data-marker-type="signature" data-marker-party="seller">'
            . '<img class="corex-ink--signature" src="data:image/png;base64,AAAA"></span>'
            . '<span class="corex-page-initials" data-marker-type="initial" data-marker-party="seller">'
            . '<img class="corex-ink--initial" src="data:image/png;base64,BBBB"></span>'
            . '</div>';

        $document = new Document();
        // canonical_version >= 1 → forDisplay serves this accumulated canonical verbatim,
        // so the PDF body is exactly these two marks (see CanonicalDocumentRenderer::forDisplay).
        $document->web_template_data = [
            'canonical_html'    => $body,
            'canonical_version' => 1,
        ];

        $template = new SignatureTemplate();
        $template->parties_json = [['role' => 'seller', 'role_label' => 'seller']];
        $template->setRelation('document', $document);

        return $template;
    }

    public function test_pdf_html_contains_the_lifted_mark_css_region_verbatim(): void
    {
        $region = $this->markCssRegionFromPartial();
        $this->assertNotSame('', $region, 'the mark-CSS region must be non-empty in the source partial');

        $out = app(SignaturePdfService::class)->buildInjectedRenderHtml($this->templateWithBothMarks());

        // The whole uniform mark region is lifted VERBATIM into the PDF's <style> — the
        // PDF is not free to carry its own copy that could drift from the screen.
        $this->assertStringContainsString('<style>', $out, 'the PDF must open a <style> block for the lifted mark CSS');
        $this->assertStringContainsString($region, $out,
            'the PDF must contain the a4-page-styles PDF-MARK-CSS region verbatim (single source of truth: print == screen)');
    }

    public function test_signature_and_initial_marks_share_one_uniform_sizing_contract(): void
    {
        $out = app(SignaturePdfService::class)->buildInjectedRenderHtml($this->templateWithBothMarks());

        // Both mark TYPES are present in the printed body.
        $this->assertStringContainsString('data-marker-type="signature"', $out, 'the printed body carries the signature mark');
        $this->assertStringContainsString('data-marker-type="initial"', $out, 'the printed body carries the initial mark');

        // The SIGNATURE mark is sized by the uniform line/ink contract (NOT a divergent
        // component/inline fallback): the fixed-height signature-ink rule.
        $this->assertMatchesRegularExpression(
            '/img\.corex-ink--signature[^{]*\{[^}]*height:\s*36px\s*!important/is',
            $out,
            'signature ink must carry the uniform 36px height contract lifted from a4-page-styles',
        );

        // The INITIAL mark is sized by the SAME uniform contract, its own fixed height —
        // one contract governs both, so a signature and an initial render consistently.
        $this->assertMatchesRegularExpression(
            '/img\.corex-ink--initial[^{]*\{[^}]*height:\s*26px\s*!important/is',
            $out,
            'initial ink must carry the uniform 26px height contract lifted from a4-page-styles',
        );

        // The marker BLOCKS carry the uniform container contract too (signature line +
        // 84x40 initial box) — the block-level sizing that made every party's mark match.
        $this->assertStringContainsString('[data-marker-type="signature"]', $out, 'signature marker-block contract lifted');
        $this->assertStringContainsString('[data-marker-type="initial"]', $out, 'initial marker-block contract lifted');

        // Regression guard: the screen-only PAGE-LAYOUT CSS above the delimiter (which
        // conflicts with wrapHtmlForPdf's page shell) must NOT be lifted with the marks.
        $this->assertStringNotContainsString('PDF-MARK-CSS-START', $out,
            'only the fenced mark region is lifted — the delimiter/comment and page-layout CSS above it must not reach the PDF');
    }
}
