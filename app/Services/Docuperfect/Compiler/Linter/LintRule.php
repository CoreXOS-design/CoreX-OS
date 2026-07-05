<?php

namespace App\Services\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Support\Docuperfect\Cds\Cds;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * One compile-time linter rule (L1..L7, §4). A rule is a PURE function over the canonical
 * WS0 {@see Cds} DTO: it reads the compiled tree + the pinned {@see DataDictionaryResolver}
 * + the run {@see LinterContext} and returns zero or more {@see LintFinding}s. It never
 * mutates anything and reaches the dictionary only through the resolver contract. Purity is
 * what lets the golden fixtures enumerate it.
 *
 * A rule that finds no problem SHOULD return a single {@see LintFinding::pass()} so the
 * report records that the rule ran (no silent coverage gaps).
 */
interface LintRule
{
    /** Stable rule code, e.g. "L1". */
    public function code(): string;

    /**
     * @return LintFinding[]
     */
    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array;
}
