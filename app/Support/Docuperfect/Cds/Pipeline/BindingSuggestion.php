<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Pipeline;

/**
 * AT-177 / WS4-E — one suggested Data Dictionary binding for a fill-point (spec §3 step 3).
 *
 * AI (or a heuristic) SUGGESTS; the operator CONFIRMS (Pillar principle: AI enhances, never
 * replaces). The Studio shows a ranked list of these against each unbound field; the operator
 * picks one, and only then is the field bound. An unbound field cannot compile (linter L1).
 */
final class BindingSuggestion
{
    public function __construct(
        public readonly string $dictionaryKey,
        public readonly float $confidence,
        public readonly string $reason = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'dictionary_key' => $this->dictionaryKey,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
        ];
    }
}
