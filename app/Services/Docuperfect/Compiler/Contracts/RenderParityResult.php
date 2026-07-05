<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Outcome of one web↔PDF render-parity comparison for a single party-combination
 * (§4 L6). Immutable. Returned by a {@see RenderParityVerifier}.
 *
 * `$matched` — true iff the structural diff of the web render and the PDF render is
 * empty (same blocks, same bound values, same anchors). `$webHash`/`$pdfHash` are the
 * parity hashes to be stamped onto the published artifact (§2 `render_parity`).
 * `$differences` carries block-addressed diff notes when `$matched` is false.
 */
final class RenderParityResult
{
    /**
     * @param string[] $differences Human/block-addressed diff notes (empty when matched).
     */
    public function __construct(
        public readonly bool $matched,
        public readonly string $webHash,
        public readonly string $pdfHash,
        public readonly array $differences = [],
    ) {
    }
}
