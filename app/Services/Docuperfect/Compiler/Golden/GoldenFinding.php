<?php

namespace App\Services\Docuperfect\Compiler\Golden;

use App\Services\Docuperfect\Compiler\Linter\LintSeverity;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness).
 *
 * One combination-scoped golden result. Reuses the linter's {@see LintSeverity} so the
 * publish gate is uniform across the linter and the harness (ERROR/PENDING block; WARNING/PASS
 * do not).
 *
 *  - $combinationLabel : which named combination this is about ('' = whole-template).
 *  - $tier             : 'structural' (runs now) or 'render' (needs WS2's probe).
 *  - $target           : addressed element (party key / field id / block id / '').
 */
final class GoldenFinding
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $combinationLabel,
        public readonly LintSeverity $severity,
        public readonly string $tier,
        public readonly string $code,
        public readonly string $target,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    /** @param array<string,mixed> $context */
    public static function structuralError(string $combo, string $code, string $target, string $message, array $context = []): self
    {
        return new self($combo, LintSeverity::ERROR, 'structural', $code, $target, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function renderError(string $combo, string $code, string $target, string $message, array $context = []): self
    {
        return new self($combo, LintSeverity::ERROR, 'render', $code, $target, $message, $context);
    }

    public static function renderPending(string $combo, string $message): self
    {
        return new self($combo, LintSeverity::PENDING, 'render', 'render_probe_absent', '', $message);
    }

    public static function pass(string $combo, string $tier, string $message = 'ok'): self
    {
        return new self($combo, LintSeverity::PASS, $tier, 'ok', '', $message);
    }

    public function blocksCertification(): bool
    {
        return $this->severity->blocksPublish();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'combination' => $this->combinationLabel,
            'severity' => $this->severity->value,
            'tier' => $this->tier,
            'code' => $this->code,
            'target' => $this->target,
            'message' => $this->message,
        ];
    }
}
