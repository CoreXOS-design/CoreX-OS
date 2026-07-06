<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;

/**
 * AT-177 / WS4-E → WS4-S seam (spec §3 step 1). Normalizes one source document to the shared
 * {@see IngestedDocument} intermediate. Door A (catalogue) and Door B (agency upload) both
 * enter here; everything downstream is source-agnostic.
 *
 * INTEGRATION (AT-177): consumer-owned interface. WS4-E (cc2) ships the DOCX / PDF / catalogue
 * implementations; the Compile Studio (WS4-S, cc1) depends only on this interface + a registry
 * (`IngestorRegistry::for($mime)`) — it never hard-codes a parser.
 */
interface DocumentIngestor
{
    /** Can this ingestor handle the given MIME type? */
    public function supports(string $mime): bool;

    /**
     * Parse the file at $path into the normalized intermediate.
     *
     * @param string              $path      absolute path to the uploaded/stored file
     * @param string              $sourceRef a human ref (original filename / template id)
     * @param array<string,mixed> $options   ingestor-specific hints (e.g. family)
     *
     * @throws \RuntimeException with a user-clear message if the file cannot be parsed.
     */
    public function ingest(string $path, string $sourceRef, array $options = []): IngestedDocument;
}
