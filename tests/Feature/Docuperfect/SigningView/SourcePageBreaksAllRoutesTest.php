<?php

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Http\Controllers\Docuperfect\TemplateController;
use App\Services\Docuperfect\CdsRendererService;
use App\Support\Docuperfect\SignatureBlock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * An imported document's hard page breaks and running header / footer are honoured on EVERY route, and its own signature
 * table means CoreX does not append its generic block (spec cds-reimport-from-word.md section 10):
 *   - the signing screen: the real e-sign paginator (a4-page-styles.blade.php) run in a real Chromium;
 *   - the signed PDF: that paginated page printed through SigningController::wrapHtmlForPdf() by Chromium;
 *   - print-and-sign: dompdf.
 * A fixture of four boxes separated by THREE hard breaks is four pages on all three (a document with N hard breaks has N + 1
 * pages; a break at the very start or end makes no blank sheet). The Chromium routes skip when no Chromium / node is installed.
 */
class SourcePageBreaksAllRoutesTest extends TestCase
{
    /** @return array<string,mixed> four one-row boxes, three hard breaks between them, a header and a footer with the page number */
    private function fixture(): array
    {
        $box = fn (string $t) => ['type' => 'table', 'rows' => [[$t]], 'src_borders' => true, 'cell_meta' => [[['borders' => 'trbl']]]];
        $brk = ['type' => 'page_break'];

        return [
            'sections' => [$box('Box one'), $brk, $box('Box two'), $brk, $box('Box three'), $brk, $box('Box four')],
            'page_header' => ['lines' => [['text' => 'Running header', 'align' => 'center']]],
            'page_footer' => ['lines' => [['text' => 'Doc footer', 'right' => 'Page {PAGE}']], 'rule' => true],
            'page_first_different' => true,
        ];
    }

    private function chromiumAvailable(): bool
    {
        return is_executable('/usr/bin/chromium') && is_file('/corex-qa1/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')
            && trim((string) shell_exec('command -v node')) !== '';
    }

    public function test_print_and_sign_pdf_has_one_page_per_break_plus_one(): void
    {
        $html = (new CdsRendererService())->render($this->fixture());
        [$frame, $body] = CdsRendererService::hoistPageFrame($html);
        $doc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . file_get_contents(public_path('css/corex-document.css')) . '</style><style>'
            . CdsRendererService::PDF_PAGE_FRAME_CSS . '</style></head><body>' . $frame . '<div class="corex-document-wrapper corex-look-source"><div class="corex-page">' . $body . '</div></div></body></html>';
        $pdf = Pdf::loadHTML($doc)->setPaper('a4', 'portrait')->output();

        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), 'three hard breaks = four pages, no blank sheet');
    }

    public function test_the_signing_paginator_and_the_signed_pdf_honour_breaks_and_the_documents_own_footer(): void
    {
        if (! $this->chromiumAvailable()) {
            $this->markTestSkipped('Chromium / node not available');
        }
        $dir = sys_get_temp_dir() . '/pgchk-' . uniqid();
        File::makeDirectory($dir);
        file_put_contents($dir . '/in.json', json_encode([
            'partial' => view('docuperfect.signatures.partials.a4-page-styles')->render(),
            'html' => (new CdsRendererService())->render($this->fixture()),
            'shell' => $dir . '/paginated.html',
        ]));
        $run = Process::path(base_path())->timeout(120)->run(['node', 'scripts/paginate-check.mjs', $dir . '/in.json']);
        $this->assertTrue($run->successful(), $run->errorOutput());
        $r = json_decode(trim($run->output()), true);

        $this->assertSame(4, $r['pages'], 'the signing screen: three hard breaks = four pages');
        $this->assertSame(0, $r['breakMarkersLeft'], 'the break markers are consumed, not left as content');
        $this->assertSame(['1', '2', '3', '4'], $r['pageNumbers'], "the document's own footer carries each page's number");
        $this->assertSame(4, $r['footers']);
        $this->assertSame(3, $r['headers'], 'the source has no header on page one (different first page)');
        $this->assertFalse($r['firstPageHasHeader']);
        $this->assertSame(0, $r['corexPageNumbers'], "CoreX's own 'Page X of Y' is not added when the document has a footer");
        $this->assertSame(0, $r['initialsRows'], "CoreX's initials strip is not added either");

        // The signed PDF: the paginated page through the real PDF shell, printed by Chromium.
        file_put_contents($dir . '/shell.html', app(SigningController::class)->wrapHtmlForPdf(file_get_contents($dir . '/paginated.html')));
        $print = Process::path(base_path())->timeout(120)->run(['node', 'scripts/print-shell-pdf.mjs', $dir . '/shell.html', $dir . '/out.pdf']);
        $this->assertTrue($print->successful(), $print->errorOutput());
        $this->assertSame(4, (int) trim($print->output()), 'the signed PDF: four pages, like the signing screen and the print-and-sign PDF');
        File::deleteDirectory($dir);
    }

    public function test_a_document_with_its_own_signature_table_is_not_followed_by_the_generic_block(): void
    {
        $compile = function (string $mode): string {
            $view = app(TemplateController::class)->generateCdsBladeView(
                ['title' => 'T', 'sections' => []], [], 987654, 'Signature block test', ['owner_party', 'acquiring_party'],
                '<p>Body</p>', [], 'standard', $mode
            );
            $path = resource_path('views/' . str_replace('.', '/', $view) . '.blade.php');
            $blade = file_get_contents($path);
            @unlink($path);

            return $blade;
        };

        $this->assertStringContainsString('components.signature-block', $compile(SignatureBlock::COREX), 'CoreX standard still appends its block');
        $this->assertStringNotContainsString('components.signature-block', $compile(SignatureBlock::DOCUMENT), 'from document: no generic Lessor / Lessee / Agent block');
        $this->assertStringContainsString('components.company-header', $compile(SignatureBlock::DOCUMENT), 'only the signature block is affected');
    }

    public function test_the_setting_defaults_to_from_document_when_the_saved_document_has_signature_places(): void
    {
        $this->assertSame(SignatureBlock::COREX, SignatureBlock::resolve(null, '<p>no places</p>'));
        $this->assertSame(SignatureBlock::DOCUMENT, SignatureBlock::resolve(null, '<td><span class="doc-tag doc-tag-signature" data-tag-type="signature">[SIG 1]</span></td>'));
        $this->assertSame(SignatureBlock::COREX, SignatureBlock::resolve('corex', '<span data-tag-type="signature"></span>'), "the person's choice wins");
        $this->assertSame(SignatureBlock::DOCUMENT, SignatureBlock::resolve('document', '<p>no places</p>'));
        $this->assertSame(SignatureBlock::COREX, SignatureBlock::fromState(['signature_block' => 'bogus']));
        $this->assertSame(SignatureBlock::COREX, SignatureBlock::fromState(null), 'a template that never had the setting is unchanged');
    }
}
