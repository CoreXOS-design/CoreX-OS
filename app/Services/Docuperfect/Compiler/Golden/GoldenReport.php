<?php

namespace App\Services\Docuperfect\Compiler\Golden;

use App\Services\Docuperfect\Compiler\Linter\LintSeverity;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * The auditable certification result for one compiled template version (§7 CI gate: "a
 * template version cannot be marked publishable in CI unless its full golden set is green").
 * Immutable. Aggregates the whole-template findings (the linter gate) and every
 * per-combination result.
 *
 * Two verdicts, mirroring WS1's ERROR-vs-PENDING split:
 *   - `certifiable()`        — the CI publish gate: NOTHING blocks (no structural ERROR, and the
 *     render tier is proven — no PENDING). This is what gates publish.
 *   - `structurallyCertified()` — the structural tier is clean; the only thing outstanding is
 *     the render tier awaiting WS2. Lets the harness show real progress before WS2 lands,
 *     without ever pretending render parity is proven.
 */
final class GoldenReport
{
    /**
     * @param GoldenFinding[]          $templateFindings whole-template (linter) findings
     * @param GoldenCombinationResult[] $results
     */
    public function __construct(
        private readonly array $templateFindings,
        private readonly array $results,
    ) {
    }

    /** @return GoldenCombinationResult[] */
    public function combinations(): array
    {
        return $this->results;
    }

    /** @return GoldenFinding[] whole-template (non-combination) findings */
    public function templateFindings(): array
    {
        return $this->templateFindings;
    }

    /** @return GoldenFinding[] every finding, template + all combinations */
    public function allFindings(): array
    {
        $all = $this->templateFindings;
        foreach ($this->results as $r) {
            $all = array_merge($all, $r->findings());
        }

        return $all;
    }

    /** The CI publish gate — nothing blocks (no ERROR, no PENDING). */
    public function certifiable(): bool
    {
        foreach ($this->allFindings() as $f) {
            if ($f->blocksCertification()) {
                return false;
            }
        }

        return true;
    }

    /** Structural tier clean across template + every combination (render PENDING ignored). */
    public function structurallyCertified(): bool
    {
        foreach ($this->allFindings() as $f) {
            if ($f->tier === 'structural' && $f->blocksCertification()) {
                return false;
            }
        }

        return true;
    }

    /** Is the only thing outstanding the render tier awaiting WS2? */
    public function renderPending(): bool
    {
        foreach ($this->allFindings() as $f) {
            if ($f->tier === 'render' && $f->severity === LintSeverity::PENDING) {
                return true;
            }
        }

        return false;
    }

    /** @return GoldenFinding[] blocking findings only, template + combinations */
    public function blocking(): array
    {
        return array_values(array_filter($this->allFindings(), static fn (GoldenFinding $f): bool => $f->blocksCertification()));
    }

    /** @return GoldenCombinationResult[] the combinations that failed structurally */
    public function failedCombinations(): array
    {
        return array_values(array_filter($this->results, static fn (GoldenCombinationResult $r): bool => !$r->structurallyPassed()));
    }

    public function combinationCount(): int
    {
        return count($this->results);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'certifiable' => $this->certifiable(),
            'structurally_certified' => $this->structurallyCertified(),
            'render_pending' => $this->renderPending(),
            'combination_count' => $this->combinationCount(),
            'template_findings' => array_map(static fn (GoldenFinding $f): array => $f->toArray(), $this->templateFindings),
            'combinations' => array_map(static function (GoldenCombinationResult $r): array {
                return [
                    'label' => $r->combination->label,
                    'structurally_passed' => $r->structurallyPassed(),
                    'render_pending' => $r->renderPending(),
                    'findings' => array_map(static fn (GoldenFinding $f): array => $f->toArray(), $r->findings()),
                ];
            }, $this->results),
        ];
    }
}
