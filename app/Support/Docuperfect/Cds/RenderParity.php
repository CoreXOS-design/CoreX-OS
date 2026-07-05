<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

/**
 * CDS v2 — the proven web↔PDF render-parity hashes (spec §2 render_parity, linter L6).
 *
 * Stored ON the artifact once L6 passes: rendering every party-combination in web and
 * PDF and structurally diffing them. Because one engine (headless Chromium — §12 ruling 3)
 * prints both surfaces, parity is a compile-time guarantee, not a runtime hope.
 */
final class RenderParity
{
    public function __construct(
        public readonly ?string $webHash = null,
        public readonly ?string $pdfHash = null,
    ) {
    }

    public function isProven(): bool
    {
        return $this->webHash !== null && $this->pdfHash !== null;
    }

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            isset($data['web_hash']) ? (string) $data['web_hash'] : null,
            isset($data['pdf_hash']) ? (string) $data['pdf_hash'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'web_hash' => $this->webHash,
            'pdf_hash' => $this->pdfHash,
        ];
    }
}
