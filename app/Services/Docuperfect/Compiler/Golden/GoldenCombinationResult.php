<?php

namespace App\Services\Docuperfect\Compiler\Golden;

use App\Services\Docuperfect\Compiler\Linter\LintSeverity;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * The result of certifying ONE {@see GoldenCombination}: its findings across the structural
 * and render tiers. Immutable.
 */
final class GoldenCombinationResult
{
    /** @param GoldenFinding[] $findings */
    public function __construct(
        public readonly GoldenCombination $combination,
        private readonly array $findings,
    ) {
    }

    /** @return GoldenFinding[] */
    public function findings(): array
    {
        return $this->findings;
    }

    /** No blocking finding of any tier. */
    public function passed(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->blocksCertification()) {
                return false;
            }
        }

        return true;
    }

    /** Structural tier is clean (render PENDING ignored). */
    public function structurallyPassed(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->tier === 'structural' && $f->blocksCertification()) {
                return false;
            }
        }

        return true;
    }

    public function renderPending(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->tier === 'render' && $f->severity === LintSeverity::PENDING) {
                return true;
            }
        }

        return false;
    }

    /** @return GoldenFinding[] blocking findings only */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, static fn (GoldenFinding $f): bool => $f->blocksCertification()));
    }
}
