<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\DocumentIngestor;
use RuntimeException;

/**
 * AT-177 / WS4-E — resolves the right {@see DocumentIngestor} for a MIME type. The Compile
 * Studio (WS4-S) depends on THIS, never on a concrete parser, so new source formats plug in
 * without touching the Studio.
 */
final class IngestorRegistry
{
    /** @var list<DocumentIngestor> */
    private array $ingestors;

    /** @param list<DocumentIngestor>|null $ingestors */
    public function __construct(?array $ingestors = null)
    {
        $this->ingestors = $ingestors ?? [
            new DocxIngestor(),
            new PdfIngestor(),
            new HtmlIngestor(),
        ];
    }

    public function supports(string $mime): bool
    {
        foreach ($this->ingestors as $ingestor) {
            if ($ingestor->supports($mime)) {
                return true;
            }
        }

        return false;
    }

    /** @throws RuntimeException when no ingestor handles the MIME type. */
    public function for(string $mime): DocumentIngestor
    {
        foreach ($this->ingestors as $ingestor) {
            if ($ingestor->supports($mime)) {
                return $ingestor;
            }
        }

        throw new RuntimeException("No compiler ingestor supports the file type [{$mime}]. Supported: Word (.docx), PDF, HTML.");
    }
}
