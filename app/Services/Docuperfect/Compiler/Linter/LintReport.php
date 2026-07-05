<?php

namespace App\Services\Docuperfect\Compiler\Linter;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * The auditable output of one lint run (§4: "Gate output is an auditable lint report
 * attached to the version"). Immutable collection of {@see LintFinding}s plus the
 * publish verdict.
 *
 * `publishable()` is the gate: TRUE only when NO finding blocks publish (no ERROR and no
 * PENDING). A WARNING or PASS never blocks. This report is designed to be JSON-serialized
 * and stored against the compiled template version.
 */
final class LintReport
{
    /** @var LintFinding[] */
    private array $findings;

    /** @param LintFinding[] $findings */
    public function __construct(array $findings)
    {
        // Re-index defensively.
        $this->findings = array_values($findings);
    }

    /** @return LintFinding[] */
    public function findings(): array
    {
        return $this->findings;
    }

    /** The publish gate — true iff nothing blocks publish. */
    public function publishable(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->blocksPublish()) {
                return false;
            }
        }

        return true;
    }

    /** @return LintFinding[] only the publish-blocking findings (ERROR + PENDING) */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f): bool => $f->blocksPublish()));
    }

    /** @return LintFinding[] */
    public function errors(): array
    {
        return $this->bySeverity(LintSeverity::ERROR);
    }

    /** @return LintFinding[] */
    public function pending(): array
    {
        return $this->bySeverity(LintSeverity::PENDING);
    }

    /** @return LintFinding[] */
    public function warnings(): array
    {
        return $this->bySeverity(LintSeverity::WARNING);
    }

    /** @return LintFinding[] every finding produced by a given rule ("L1".."L7") */
    public function findingsForRule(string $rule): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f): bool => $f->rule === $rule));
    }

    /** Did a given rule produce any blocking finding? */
    public function ruleFailed(string $rule): bool
    {
        foreach ($this->findingsForRule($rule) as $f) {
            if ($f->blocksPublish()) {
                return true;
            }
        }

        return false;
    }

    /** The first blocking finding (for a terse error surface), or null. */
    public function firstBlocking(): ?LintFinding
    {
        return $this->blocking()[0] ?? null;
    }

    /** @return string[] distinct rule codes that produced a blocking finding */
    public function failedRules(): array
    {
        $rules = [];
        foreach ($this->blocking() as $f) {
            $rules[$f->rule] = true;
        }

        return array_keys($rules);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'publishable' => $this->publishable(),
            'failed_rules' => $this->failedRules(),
            'counts' => [
                'error' => count($this->errors()),
                'pending' => count($this->pending()),
                'warning' => count($this->warnings()),
            ],
            'findings' => array_map(static fn (LintFinding $f): array => $f->toArray(), $this->findings),
        ];
    }

    /** @param LintSeverity $severity @return LintFinding[] */
    private function bySeverity(LintSeverity $severity): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f): bool => $f->severity === $severity));
    }
}
