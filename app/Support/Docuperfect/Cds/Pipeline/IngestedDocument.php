<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Pipeline;

/**
 * AT-177 / WS4-E — the normalized intermediate parse produced by an ingestor (spec §3 step 1).
 *
 * Door A and Door B share everything downstream of ingest, so every source (DOCX, PDF, an
 * existing CoreX catalogue template) normalizes to THIS shape: clean HTML + declared assets +
 * metadata. Segmentation (§3 step 2) consumes only this — it never touches the raw source.
 */
final class IngestedDocument
{
    public const SOURCE_DOCX = 'docx';
    public const SOURCE_PDF = 'pdf';
    public const SOURCE_CATALOGUE = 'catalogue';

    /**
     * @param string              $sourceType    one of SOURCE_*
     * @param string              $sourceRef     filename / template id the parse came from
     * @param string              $normalizedHtml body HTML, normalized (no <head>/<html>)
     * @param list<array{key:string,kind:string,ref:string,hash?:string}> $assets  letterhead/images
     * @param array<string,mixed> $meta          title, page_count, detected_marker_count, warnings…
     */
    public function __construct(
        public readonly string $sourceType,
        public readonly string $sourceRef,
        public readonly string $normalizedHtml,
        public readonly array $assets = [],
        public readonly array $meta = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['source_type'] ?? self::SOURCE_DOCX),
            (string) ($data['source_ref'] ?? ''),
            (string) ($data['normalized_html'] ?? ''),
            array_values($data['assets'] ?? []),
            $data['meta'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_ref' => $this->sourceRef,
            'normalized_html' => $this->normalizedHtml,
            'assets' => $this->assets,
            'meta' => $this->meta,
        ];
    }
}
