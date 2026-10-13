<?php

namespace App\Services\RoadToSold\Selections;

/**
 * One of the nine ways the Road to Sold list finds properties to phone.
 *
 * candidates() returns at most $limit proposals, best first, ALREADY ordered the way the day is
 * filled: the agent's own suburbs before the rest (or only theirs when the agency locks areas),
 * properties with an address before those without, then the selection's own ranking. It must apply
 * every "never suggest" rule itself — the builder trusts what it gets back.
 */
interface Selection
{
    /** The stored key (see SettingsService): my_buyers_sourcing … my_stock_not_selling. */
    public function key(): string;

    /** Plain-English name shown to agents and in the settings page. */
    public function label(): string;

    /** @return Candidate[] */
    public function candidates(SelectionContext $ctx, int $limit): array;
}
