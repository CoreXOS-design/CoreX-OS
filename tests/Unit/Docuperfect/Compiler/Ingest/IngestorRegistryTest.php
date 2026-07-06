<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\DocumentIngestor;
use App\Services\Docuperfect\Compiler\Ingest\DocxIngestor;
use App\Services\Docuperfect\Compiler\Ingest\HtmlIngestor;
use App\Services\Docuperfect\Compiler\Ingest\IngestorRegistry;
use App\Services\Docuperfect\Compiler\Ingest\PdfIngestor;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * WS4-E Gate 2 — the ingestor registry the Studio resolves through.
 */
final class IngestorRegistryTest extends TestCase
{
    public function test_default_registry_supports_docx_pdf_and_html(): void
    {
        $registry = new IngestorRegistry();

        $this->assertTrue($registry->supports('application/pdf'));
        $this->assertTrue($registry->supports('text/html'));
        $this->assertTrue($registry->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertInstanceOf(PdfIngestor::class, $registry->for('application/pdf'));
        $this->assertInstanceOf(HtmlIngestor::class, $registry->for('text/html'));
        $this->assertInstanceOf(DocxIngestor::class, $registry->for('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
    }

    public function test_unsupported_mime_throws_a_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No compiler ingestor supports/');
        (new IngestorRegistry())->for('image/png');
    }

    public function test_registry_is_extensible_with_a_custom_ingestor(): void
    {
        $fake = new class implements DocumentIngestor {
            public function supports(string $mime): bool
            {
                return $mime === 'application/x-custom';
            }

            public function ingest(string $path, string $sourceRef, array $options = []): IngestedDocument
            {
                return new IngestedDocument('catalogue', $sourceRef, '<p>custom</p>');
            }
        };

        $registry = new IngestorRegistry([$fake]);
        $this->assertTrue($registry->supports('application/x-custom'));
        $this->assertSame('<p>custom</p>', $registry->for('application/x-custom')->ingest('x', 'ref')->normalizedHtml);
        $this->assertFalse($registry->supports('application/pdf'));
    }
}
