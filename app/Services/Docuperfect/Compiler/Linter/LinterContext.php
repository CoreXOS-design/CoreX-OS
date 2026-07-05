<?php

namespace App\Services\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Per-run context handed to every {@see LintRule}. Immutable. Carries:
 *  - the optional {@see RenderParityVerifier} (L6) — null until WS2 provides one;
 *  - `$maxInstancesPerParty`: how many instances of a OneOrMore party the L4 combination
 *    enumerator materialises. Bounded so the enumeration can never blow up.
 *
 * L7's forbidden-class fact is NOT configured here: it lives on the canonical
 * {@see \App\Support\Docuperfect\Cds\Enums\LegalClass::forbidsEsign()} (WS0), the single
 * source of truth for which document classes may not be e-signed under SA law.
 */
final class LinterContext
{
    public function __construct(
        public readonly ?RenderParityVerifier $renderParityVerifier = null,
        public readonly int $maxInstancesPerParty = 2,
    ) {
    }
}
