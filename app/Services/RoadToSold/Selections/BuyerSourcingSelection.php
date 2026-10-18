<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;

/**
 * Selections 1 and 2 — stock sourcing for buyers. A listing is a candidate when active buyers have a
 * perfect or strong match on it (prospecting_buyer_matches, the same cache the Work tab reads).
 * Which match levels count and which buyer states are "active" are agency settings (standard: perfect and
 * strong; new and warm); deleted buyers never count.
 *
 *   my_buyers_sourcing      only buyers whose agent (or second agent) is this agent
 *   agency_buyers_sourcing  any of the agency's buyers
 */
final class BuyerSourcingSelection extends MicPoolSelection
{
    public const MINE = 'my_buyers_sourcing';
    public const AGENCY = 'agency_buyers_sourcing';

    public function __construct(private readonly string $key)
    {
    }

    public static function mine(): self
    {
        return new self(self::MINE);
    }

    public static function agency(): self
    {
        return new self(self::AGENCY);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->key === self::MINE ? 'Stock for my buyers' : 'Stock for the agency\'s buyers';
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $mine = $this->key === self::MINE;
        $ids = $ctx->identityIds ?: [$ctx->user->id];
        $idIn = implode(',', array_map('intval', $ids));
        // settings values are catalogue-checked slugs, quoted through the connection all the same
        $quote = fn (array $values) => implode(',', array_map(fn ($v) => \Illuminate\Support\Facades\DB::getPdo()->quote((string) $v), $values));
        $states = $quote($ctx->settings->buyerActiveStates);
        $tiers = $quote($ctx->settings->buyerMatchTiers);
        $agentSql = $mine ? "AND (c.agent_id IN ({$idIn}) OR c.second_agent_id IN ({$idIn}))" : '';

        $q->join(
            \Illuminate\Support\Facades\DB::raw(
                "(SELECT pbm.prospecting_listing_id AS lid, COUNT(DISTINCT pbm.contact_id) AS buyers, MAX(pbm.score) AS best
                    FROM prospecting_buyer_matches pbm
                    JOIN contacts c ON c.id = pbm.contact_id
                   WHERE pbm.agency_id = " . (int) $ctx->agencyId . "
                     AND pbm.dismissed_at IS NULL
                     AND pbm.tier IN ({$tiers})
                     AND c.deleted_at IS NULL AND c.is_buyer = 1 AND c.buyer_state IN ({$states})
                     {$agentSql}
                   GROUP BY pbm.prospecting_listing_id) bm"
            ),
            'bm.lid', '=', 'pl.id'
        );
        $q->addSelect('bm.buyers', 'bm.best');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('bm.buyers')->orderByDesc('bm.best');
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $n = (int) $row->buyers;
        $who = $this->key === self::MINE ? ($n === 1 ? 'of your buyers is' : 'of your buyers are') : ($n === 1 ? 'active buyer is' : 'active buyers are');

        return "{$n} {$who} looking for a property like this (best match " . (int) $row->best . '%)';
    }
}
