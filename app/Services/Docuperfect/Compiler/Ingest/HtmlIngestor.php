<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\DocumentIngestor;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use RuntimeException;

/**
 * AT-177 / WS4-E — ingests raw/rendered HTML (an existing CoreX catalogue template rendered to
 * HTML, or an uploaded .html). The backbone ingestor: the DOCX and PDF ingestors convert to
 * HTML then defer to the same normalizer.
 */
final class HtmlIngestor implements DocumentIngestor
{
    public function __construct(private readonly HtmlNormalizer $normalizer = new HtmlNormalizer())
    {
    }

    public function supports(string $mime): bool
    {
        return in_array($mime, ['text/html', 'application/xhtml+xml'], true);
    }

    public function ingest(string $path, string $sourceRef, array $options = []): IngestedDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Could not read the HTML file [{$sourceRef}].");
        }

        $normalized = $this->normalizer->normalize((string) file_get_contents($path));

        return new IngestedDocument(
            sourceType: (string) ($options['source_type'] ?? IngestedDocument::SOURCE_CATALOGUE),
            sourceRef: $sourceRef,
            normalizedHtml: $normalized,
            assets: [],
            meta: ['ingestor' => 'html', 'bytes' => strlen($normalized)],
        );
    }
}
