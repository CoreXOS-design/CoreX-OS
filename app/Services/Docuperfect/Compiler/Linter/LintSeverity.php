<?php

namespace App\Services\Docuperfect\Compiler\Linter;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Severity of a single {@see LintFinding}. Publish is gated on there being NO blocking
 * findings (§4: "cannot be published unless every rule passes").
 *
 *  - ERROR   : a hard rule violation. Blocks publish.
 *  - PENDING : a rule that could not be certified because a dependency is absent
 *              (today: L6 has no {@see \App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier}
 *              because WS2 isn't built). NOT a pass — parity is unproven — so it BLOCKS
 *              publish, honestly. It is distinct from ERROR so callers can tell "broken"
 *              from "not yet verifiable".
 *  - WARNING : advisory; does NOT block publish.
 *  - PASS    : a rule ran and found nothing (informational; emitted so the report can
 *              show which rules were exercised — no silent coverage gaps).
 */
enum LintSeverity: string
{
    case ERROR = 'error';
    case PENDING = 'pending';
    case WARNING = 'warning';
    case PASS = 'pass';

    /** Does a finding of this severity prevent publish? */
    public function blocksPublish(): bool
    {
        return $this === self::ERROR || $this === self::PENDING;
    }
}
