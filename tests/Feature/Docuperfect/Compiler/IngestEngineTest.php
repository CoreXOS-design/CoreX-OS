<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler;

use App\Services\Docuperfect\Compiler\Ingest\DocxIngestor;
use App\Services\Docuperfect\Compiler\Ingest\HtmlIngestor;
use App\Services\Docuperfect\Compiler\Ingest\PdfIngestor;
use App\Services\Docuperfect\Compiler\Rendering\ChromiumPdfRenderService;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * WS4-E Gate 2 — the ingest engine drives real converters (pandoc, smalot/pdfparser, Chromium).
 * Integration; each case self-skips when its tool is unavailable so CI stays green.
 */
final class IngestEngineTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir() . '/ws4e-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->work . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->work);
        parent::tearDown();
    }

    private function pandocAvailable(): bool
    {
        try {
            $p = new Process(['pandoc', '--version']);
            $p->run();

            return $p->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_html_ingestor_normalizes_a_catalogue_document(): void
    {
        $path = $this->work . '/cat.html';
        file_put_contents($path, '<html><head><style>.x{}</style></head><body><div class="corex-page"><h1>ADDENDUM B</h1><p>Extra info.</p></div></body></html>');

        $doc = (new HtmlIngestor())->ingest($path, 'addendum.html');

        $this->assertSame(IngestedDocument::SOURCE_CATALOGUE, $doc->sourceType);
        $this->assertStringContainsString('ADDENDUM B', $doc->normalizedHtml);
        $this->assertStringNotContainsString('<style', $doc->normalizedHtml);
    }

    public function test_docx_ingestor_converts_a_real_word_document(): void
    {
        if (! $this->pandocAvailable()) {
            $this->markTestSkipped('pandoc is not installed on this host.');
        }

        // Build a real .docx from HTML with pandoc, then ingest it back.
        $html = $this->work . '/src.html';
        $docx = $this->work . '/src.docx';
        file_put_contents($html, '<h1>Mandatory Disclosure</h1><p>The seller confirms clause 1 is true.</p><p>Signature: ____________</p>');
        (new Process(['pandoc', $html, '-o', $docx]))->mustRun();

        $doc = (new DocxIngestor())->ingest($docx, 'mandate.docx');

        $this->assertSame(IngestedDocument::SOURCE_DOCX, $doc->sourceType);
        $this->assertStringContainsString('Mandatory Disclosure', $doc->normalizedHtml);
        $this->assertStringContainsString('seller confirms clause 1', $doc->normalizedHtml);
    }

    public function test_pdf_ingestor_extracts_text_and_marks_pages(): void
    {
        $pdfService = new ChromiumPdfRenderService();
        if (! $pdfService->isAvailable()) {
            $this->markTestSkipped('Headless Chromium is not installed on this host.');
        }

        $pdfPath = $this->work . '/doc.pdf';
        file_put_contents($pdfPath, $pdfService->htmlToPdf(
            '<h1>Sales Mandate</h1><p>The parties agree as follows.</p><p>Purchase price: R 1,850,000.</p>',
        ));

        $doc = (new PdfIngestor())->ingest($pdfPath, 'mandate.pdf');

        $this->assertSame(IngestedDocument::SOURCE_PDF, $doc->sourceType);
        $this->assertStringContainsString('Sales Mandate', strip_tags($doc->normalizedHtml));
        $this->assertStringContainsString('1,850,000', strip_tags($doc->normalizedHtml));
        $this->assertGreaterThanOrEqual(1, $doc->meta['page_count']);
    }

    public function test_unreadable_file_throws_a_user_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        (new DocxIngestor())->ingest($this->work . '/missing.docx', 'missing.docx');
    }
}
