<?php

namespace App\Services\RoadToSold\Selections;

use App\Services\Matching\BuyerMatchRules;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Station 1 "Stock loaded", sources 1 and 2 (road-to-sold.md 23.14). A listing is a candidate when buyers have a STRONG-tier match on it
 * (prospecting_buyer_matches, the same cache the Work tab reads, built under the CURRENT matching rules - mic-work-tab.md 13.15), the buyer is active, the
 * match is not dismissed for that buyer (the "Not for <buyer>" action sets dismissed_at, 13.17) and nobody has claimed the property (pool()).
 *
 *   my_buyers_sourcing      only buyers whose agent (or second agent) is this agent          row: "for Piet · 3 buyers"
 *   agency_buyers_sourcing  COLLEAGUES' buyers - never mine (source 1 has them). Setting `rts.source_for_colleagues`: off = none; branch = buyers whose agent
 *                           is in this agent's branch; agency = the branch's buyers first, then the rest of the agency's.   row: "for Sanet (Mia Manager)"
 *
 * "Strong" is the agency's own line (buyer_match_tiers.strong_min_score, default 80, the same one the Work tab's "Buyers N strong" uses) on top of the tiers the
 * agency allows (perfect / strong by default). An unverified match tops out below it by construction (13.15), so it never feeds the engine.
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
        return $this->key === self::MINE ? 'Stock for my buyers' : 'Stock for colleagues\' buyers';
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        // colleagues' buyers can be switched off, and a branch-only setting with an agent who has no branch has nobody to draw on
        if ($this->key === self::AGENCY && ($ctx->settings->sourceForColleagues === 'off'
            || ($ctx->settings->sourceForColleagues === 'branch' && ! $this->myBranch($ctx)))) {
            return [];
        }

        return parent::candidates($ctx, $limit);
    }

    private function myBranch(SelectionContext $ctx): ?int
    {
        $b = $ctx->user->effectiveBranchId() ?? $ctx->user->branch_id;

        return $b ? (int) $b : null;
    }

    /**
     * The lowest score a buyer match may have to feed the engine: the lowest line among the tiers the agency allows. Standard (perfect + strong) = the agency's own
     * strong line (buyer_match_tiers.strong_min_score, default 80, the one the Work tab's "Buyers N strong" uses); an agency that also allows `approximate` gets its mid line.
     */
    private function floor(SelectionContext $ctx): int
    {
        $t = app(ProspectingConfigurationService::class)->buyerMatchTiers($ctx->agencyId);
        $line = ['perfect' => 90, 'strong' => (int) ($t['strong_min_score'] ?? 80), 'approximate' => (int) ($t['mid_min_score'] ?? 50)];
        $floors = array_map(fn ($tier) => $line[$tier] ?? 100, $ctx->settings->buyerMatchTiers);

        return max(1, min(100, $floors === [] ? (int) ($t['strong_min_score'] ?? 80) : min($floors)));
    }

    /** The conditions every buyer match must meet for this source, as SQL over `pbm` (matches), `c` (the buyer) and `u` (the buyer's agent). Values are quoted. */
    private function conditions(SelectionContext $ctx): string
    {
        $pdo = DB::getPdo();
        $quote = fn (array $values) => implode(',', array_map(fn ($v) => $pdo->quote((string) $v), $values));
        $ids = $ctx->identityIds ?: [$ctx->user->id];
        $idIn = implode(',', array_map('intval', $ids));
        $mineSql = "(c.agent_id IN ({$idIn}) OR c.second_agent_id IN ({$idIn}))";

        $sql = 'pbm.agency_id = ' . (int) $ctx->agencyId
            . ' AND pbm.dismissed_at IS NULL'
            . ' AND pbm.tier IN (' . $quote($ctx->settings->buyerMatchTiers) . ')'
            . ' AND pbm.score >= ' . $this->floor($ctx)
            // only matches built under the current rules (a row from before the rules last changed may show a property that is no longer a match)
            . " AND JSON_UNQUOTE(JSON_EXTRACT(pbm.matched_features, '$.rules')) = " . $pdo->quote(BuyerMatchRules::VERSION)
            . ' AND c.deleted_at IS NULL AND c.is_buyer = 1 AND c.buyer_state IN (' . $quote($ctx->settings->buyerActiveStates) . ')';

        if ($this->key === self::MINE) {
            return $sql . ' AND ' . $mineSql;
        }
        // NOT mine, spelled out: second_agent_id is usually NULL and NOT (x OR NULL) would drop the row
        $sql .= " AND c.agent_id IS NOT NULL AND c.agent_id NOT IN ({$idIn}) AND (c.second_agent_id IS NULL OR c.second_agent_id NOT IN ({$idIn}))";
        if ($ctx->settings->sourceForColleagues === 'branch') {
            $sql .= ' AND u.branch_id = ' . (int) $this->myBranch($ctx);
        }

        return $sql;
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $branch = (int) ($this->myBranch($ctx) ?? 0);
        $q->join(
            DB::raw(
                '(SELECT pbm.prospecting_listing_id AS lid, COUNT(DISTINCT pbm.contact_id) AS buyers, MAX(pbm.score) AS best,'
                . " MAX(CASE WHEN u.branch_id = {$branch} THEN 1 ELSE 0 END) AS same_branch"
                . ' FROM prospecting_buyer_matches pbm'
                . ' JOIN contacts c ON c.id = pbm.contact_id'
                . ' LEFT JOIN users u ON u.id = c.agent_id'
                . ' WHERE ' . $this->conditions($ctx)
                . ' GROUP BY pbm.prospecting_listing_id) bm'
            ),
            'bm.lid', '=', 'pl.id'
        );
        $q->addSelect('bm.buyers', 'bm.best', 'bm.same_branch');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        // colleagues: the agent's own branch before the rest of the agency (23.14), then the most buyers, then the best match
        if ($this->key === self::AGENCY) {
            $q->orderByDesc('bm.same_branch');
        }
        $q->orderByDesc('bm.buyers')->orderByDesc('bm.best');
    }

    /** The buyers behind the count: the agent's own branch first for colleagues, best match first, the buyer's agent named. */
    protected function explain(object $row, SelectionContext $ctx): array
    {
        $branch = (int) ($this->myBranch($ctx) ?? 0);
        $buyers = DB::table('prospecting_buyer_matches as pbm')
            ->join('contacts as c', 'c.id', '=', 'pbm.contact_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.agent_id')
            ->where('pbm.prospecting_listing_id', (int) $row->listing_id)
            ->whereRaw($this->conditions($ctx))
            ->selectRaw('c.id, c.first_name, c.last_name, c.agent_id, u.name as agent_name, u.branch_id as agent_branch, MAX(pbm.score) as score')
            ->groupBy('c.id', 'c.first_name', 'c.last_name', 'c.agent_id', 'u.name', 'u.branch_id')
            ->when($this->key === self::AGENCY, fn ($q) => $q->orderByRaw('(u.branch_id = ' . $branch . ') DESC'))
            ->orderByDesc('score')->orderBy('c.id')->limit(12)->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => trim($b->first_name . ' ' . $b->last_name), 'agent' => $b->agent_name, 'agent_id' => $b->agent_id ? (int) $b->agent_id : null,
                'same_branch' => $branch > 0 && (int) $b->agent_branch === $branch, 'score' => (int) $b->score])->all();
        if ($buyers === []) {
            return ['line' => $this->reason($row, $ctx), 'facts' => null];
        }
        $out = $this->key === self::MINE
            ? \App\Services\RoadToSold\Reasons\ReasonLine::myBuyers($buyers)
            : \App\Services\RoadToSold\Reasons\ReasonLine::agencyBuyers($buyers);
        // the count the ranking used can include buyers beyond the twelve read here
        if ((int) $row->buyers > count($buyers)) {
            $out['facts']['count'] = (int) $row->buyers;
        }
        if ($this->key === self::AGENCY) {
            $out['facts']['scope'] = $ctx->settings->sourceForColleagues;
        }

        return $out;
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        return $this->key === self::MINE ? 'for your buyers' : 'for a colleague\'s buyer';
    }
}
