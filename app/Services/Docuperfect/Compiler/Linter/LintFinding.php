<?php

namespace App\Services\Docuperfect\Compiler\Linter;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * One block-addressed lint result (§4: "A failed lint blocks publish with precise,
 * block-addressed errors"). Immutable.
 *
 *  - $rule     : the rule that produced this, "L1".."L7".
 *  - $severity : {@see LintSeverity}.
 *  - $target   : the addressed element — a block_id where possible; otherwise a field_id,
 *                party key, anchor_id, or '' for a template-level finding. This is what
 *                makes a failure actionable ("fails on the exact rule + block").
 *  - $code     : stable machine code (e.g. 'field_unbound', 'anchor_missing_for_party').
 *  - $message  : plain-language, block-addressed explanation.
 *  - $context  : optional extra data (e.g. the offending binding ref, the dict version).
 */
final class LintFinding
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $rule,
        public readonly LintSeverity $severity,
        public readonly string $target,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    /** @param array<string,mixed> $context */
    public static function error(string $rule, string $target, string $code, string $message, array $context = []): self
    {
        return new self($rule, LintSeverity::ERROR, $target, $code, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function pending(string $rule, string $target, string $code, string $message, array $context = []): self
    {
        return new self($rule, LintSeverity::PENDING, $target, $code, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $rule, string $target, string $code, string $message, array $context = []): self
    {
        return new self($rule, LintSeverity::WARNING, $target, $code, $message, $context);
    }

    /** A rule ran clean. $target '' = whole template. */
    public static function pass(string $rule, string $message = 'ok', string $target = ''): self
    {
        return new self($rule, LintSeverity::PASS, $target, 'ok', $message);
    }

    public function blocksPublish(): bool
    {
        return $this->severity->blocksPublish();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity->value,
            'target' => $this->target,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
