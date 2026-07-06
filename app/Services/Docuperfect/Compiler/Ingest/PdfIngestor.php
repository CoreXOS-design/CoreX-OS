<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\DocumentIngestor;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * AT-177 / WS4-E — ingests a .pdf (Door B) via smalot/pdfparser. PDF carries text without rich
 * block structure, so we reconstruct paragraphs from blank-line runs and insert an explicit
 * page-break between pages (segmentation types those as page_break blocks). Lower-fidelity than
 * DOCX by nature — segmentation flags more for operator confirmation, never silently guesses.
 */
final class PdfIngestor implements DocumentIngestor
{
    public function __construct(private readonly ?Parser $parser = null)
    {
    }

    public function supports(string $mime): bool
    {
        return $mime === 'application/pdf';
    }

    public function ingest(string $path, string $sourceRef, array $options = []): IngestedDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Could not read the PDF [{$sourceRef}].");
        }

        $parser = $this->parser ?? new Parser();

        try {
            $document = $parser->parseFile($path);
            $pages = $document->getPages();
        } catch (Throwable $e) {
            throw new RuntimeException("Could not read [{$sourceRef}] as a PDF. " . $e->getMessage());
        }

        $htmlPages = [];
        foreach ($pages as $page) {
            $htmlPages[] = $this->paragraphsToHtml($page->getText());
        }

        $normalized = implode('<div class="page-break"></div>', array_filter($htmlPages, static fn (string $h): bool => trim($h) !== ''));

        return new IngestedDocument(
            sourceType: IngestedDocument::SOURCE_PDF,
            sourceRef: $sourceRef,
            normalizedHtml: $normalized,
            assets: [],
            meta: ['ingestor' => 'pdf:smalot', 'page_count' => count($pages), 'bytes' => strlen($normalized)],
        );
    }

    private function paragraphsToHtml(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/u', trim($text)) ?: [];
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $clean = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
            if ($clean !== '') {
                $out[] = '<p>' . htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5) . '</p>';
            }
        }

        return implode('', $out);
    }
}
