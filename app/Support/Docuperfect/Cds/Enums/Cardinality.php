<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — a declared party's cardinality (spec §2 Party.cardinality).
 *  - One      : exactly one instance (e.g. the agent, a single witness).
 *  - OneOrMore: 1..n instances (e.g. seller_1, seller_2 …) — drives L4 combination enumeration.
 */
enum Cardinality: string
{
    case One = 'one';
    case OneOrMore = 'one_or_more';
}
