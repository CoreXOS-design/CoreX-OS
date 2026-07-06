<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Contracts\DocumentIngestor;
use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AT-177 / WS4-E — ingests a Word .docx (Door B: an agency's own document) by converting it to
 * clean HTML with pandoc, then normalizing (spec §3 step 1). pandoc preserves the block
 * structure (headings, paragraphs, tables) segmentation needs.
 *
 * Null-safe: an unreadable/corrupt file or a missing/failed pandoc comes back as a user-clear
 * RuntimeException, never a raw process error (BUILD_STANDARD §4).
 */
final class DocxIngestor implements DocumentIngestor
{
    private const MIMES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
    ];

    public function __construct(
        private readonly HtmlNormalizer $normalizer = new HtmlNormalizer(),
        private readonly string $pandoc = 'pandoc',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function supports(string $mime): bool
    {
        return in_array($mime, self::MIMES, true);
    }

    public function ingest(string $path, string $sourceRef, array $options = []): IngestedDocument
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Could not read the Word document [{$sourceRef}].");
        }

        $process = new Process([$this->pandoc, $path, '-f', 'docx', '-t', 'html', '--wrap=none']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException("Converting [{$sourceRef}] timed out.");
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Could not read [{$sourceRef}] as a Word document. " . trim($process->getErrorOutput()),
            );
        }

        $normalized = $this->normalizer->normalize($process->getOutput());

        return new IngestedDocument(
            sourceType: IngestedDocument::SOURCE_DOCX,
            sourceRef: $sourceRef,
            normalizedHtml: $normalized,
            assets: [],
            meta: ['ingestor' => 'docx:pandoc', 'bytes' => strlen($normalized)],
        );
    }
}
