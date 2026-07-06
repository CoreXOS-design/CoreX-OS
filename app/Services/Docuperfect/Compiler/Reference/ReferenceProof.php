<?php

namespace App\Services\Docuperfect\Compiler\Reference;

use App\Services\Docuperfect\Compiler\Golden\GoldenReport;
use App\Services\Docuperfect\Compiler\Linter\LintReport;

/**
 * E-Sign Document Compiler — WS5 (reference proofs).
 *
 * The full-chain proof for one reference template (116/117/119): the linter verdict, the
 * golden-harness certification, and the side-by-side truth test — the auditable evidence
 * that a hand-compiled CDS is publishable and reproduces the real document. Immutable.
 *
 * `proven()` is the WS5 gate for a single template: it lints publishable (incl. live L6 and
 * legal-class L7), certifies across every party combination, and reproduces the document's
 * content + signature topology.
 */
final class ReferenceProof
{
    public function __construct(
        public readonly string $family,
        public readonly string $legalClass,
        public readonly LintReport $lint,
        public readonly GoldenReport $golden,
        public readonly SideBySideVerdict $sideBySide,
    ) {
    }

    public function proven(): bool
    {
        return $this->lint->publishable()
            && $this->golden->certifiable()
            && $this->sideBySide->passed();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'legal_class' => $this->legalClass,
            'proven' => $this->proven(),
            'lint' => [
                'publishable' => $this->lint->publishable(),
                'failed_rules' => $this->lint->failedRules(),
            ],
            'golden' => [
                'certifiable' => $this->golden->certifiable(),
                'combination_count' => $this->golden->combinationCount(),
            ],
            'side_by_side' => $this->sideBySide->toArray(),
        ];
    }
}
