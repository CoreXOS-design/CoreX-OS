<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Support\Docuperfect\Cds\Pipeline\BindingSuggestion;

/**
 * AT-177 / WS4-E → WS4-S seam (spec §3 step 3). Suggests ranked Data Dictionary bindings for
 * an unbound fill-point. AI (or a heuristic) SUGGESTS; the operator CONFIRMS in the Studio —
 * AI enhances, never replaces (Pillar principle).
 *
 * INTEGRATION (AT-177): WS4-E (cc2) ships a heuristic implementation now (label/context →
 * dictionary key) and can swap an AI-backed one behind the same interface later. The Studio
 * (WS4-S, cc1) renders the returned suggestions against each unbound field.
 */
interface BindingSuggester
{
    /**
     * @param string   $fieldLabel        the fill-point's label ("Purchase Price")
     * @param string   $contextText       nearby prose to disambiguate ("…the sum of R…")
     * @param int|null $agencyId          resolve against this agency's dictionary overrides
     * @param int      $dictionaryVersion pin suggestions to this dictionary version
     * @return list<BindingSuggestion> ranked, highest-confidence first
     */
    public function suggest(string $fieldLabel, string $contextText = '', ?int $agencyId = null, int $dictionaryVersion = 1): array;
}
