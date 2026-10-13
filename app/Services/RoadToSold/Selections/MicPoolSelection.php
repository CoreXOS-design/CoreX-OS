<?php

namespace App\Services\RoadToSold\Selections;

use App\Models\ProspectingListing;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared machinery for the selections that draw on the Market Intelligence pool (1–7): the base
 * query, every "never suggest" exclusion, the area tiers, address-first ordering, twin collapse and
 * the suggestion guard. A subclass only says WHICH listings (filters), in WHAT order, and WHY.
 */
abstract class MicPoolSelection implements Selection
{
    private const PAGE = 200;
    private const MAX_PAGES = 8;

    /** Another agent's CoreX property in one of these states means the property is already taken. */
    private const TAKEN_STATUSES = ['active', 'for_sale', 'to_let', 'under_offer', 'on_show', 'prospecting', 'expired'];

    /** Narrow the pool (joins / wheres) and add any selection-specific select columns. */
    abstract protected function filter(Builder $q, SelectionContext $ctx): void;

    /** Selection-specific ordering, applied AFTER area tier and address-first. */
    abstract protected function order(Builder $q, SelectionContext $ctx): void;

    /** Plain-English reason for this row. */
    abstract protected function reason(object $row, SelectionContext $ctx): string;

    /** Whether the agent's own still-held properties are skipped (follow-ups bring them back when due). */
    protected function skipOwnHeld(): bool
    {
        return true;
    }

    /** Hard cap on how many this selection may give per request (e.g. owner-known quantity). */
    protected function cap(SelectionContext $ctx, int $limit): int
    {
        return $limit;
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        $limit = $this->cap($ctx, $limit);
        if ($limit <= 0) {
            return [];
        }
        // The lock needs areas: an agent with none gets nothing from the pool (the lane-1 warning says why).
        if ($ctx->locked() && ! $ctx->hasAreas()) {
            return [];
        }

        $out = [];
        $seen = [];
        for ($page = 1; $page <= self::MAX_PAGES && count($out) < $limit; $page++) {
            $rows = $this->pool($ctx)->forPage($page, self::PAGE)->get();
            if ($rows->isEmpty()) {
                break;
            }

            $trackedIds = $rows->pluck('tracked_property_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
            $blocked = array_flip($ctx->guard->blockedFor($ctx->user, $trackedIds, $ctx->track));
            $held = $this->skipOwnHeld() ? array_flip($ctx->guard->ownHeld($ctx->user, $trackedIds, $ctx->track)) : [];

            foreach ($rows as $row) {
                $tid = $row->tracked_property_id ? (int) $row->tracked_property_id : null;
                if ($tid !== null && (isset($blocked[$tid]) || isset($held[$tid]))) {
                    continue;
                }
                $c = new Candidate(
                    trackedPropertyId: $tid,
                    prospectingListingId: (int) $row->listing_id,
                    propertyId: $row->matched_property_id ? (int) $row->matched_property_id : null,
                    selection: $this->key(),
                    reason: $this->reason($row, $ctx),
                    hasAddress: (bool) $row->has_address,
                    inArea: (bool) $row->in_area,
                );
                if (isset($seen[$c->dedupeKey()])) {
                    continue; // the P24 / PP twin of one already taken
                }
                $seen[$c->dedupeKey()] = true;
                $out[] = $c;
                if (count($out) >= $limit) {
                    break 2;
                }
            }

            if ($rows->count() < self::PAGE) {
                break;
            }
        }

        return $out;
    }

    /** The base pool for this agent and day, with this selection's filters and ordering applied. */
    protected function pool(SelectionContext $ctx): Builder
    {
        $me = $ctx->identityIds ?: [$ctx->user->id];
        $meIn = implode(',', array_map('intval', $me));
        $taken = "'" . implode("','", self::TAKEN_STATUSES) . "'";

        [$areaSql, $areaBindings] = $this->areaSql($ctx);

        $q = DB::table('prospecting_listings as pl')
            ->leftJoin('tracked_properties as tp', function ($j) {
                $j->on('tp.id', '=', 'pl.tracked_property_id')->whereNull('tp.deleted_at');
            })
            ->where('pl.agency_id', $ctx->agencyId)
            ->whereNull('pl.deleted_at')
            ->where('pl.is_active', 1)
            // a listing whose tracked property was deleted is no longer a property we can work
            ->where(fn ($w) => $w->whereNull('pl.tracked_property_id')->orWhereNotNull('tp.id'))
            ->where(fn ($w) => $w->whereNull('tp.id')->orWhereNotIn('tp.status', ['archived', 'duplicate']))
            // never the agency's own on-market stock (the agent's own stock is selections 8 and 9)
            ->where(fn ($w) => app(OnMarketStockService::class)->applyNotStock($w, $ctx->agencyId, 'pl.portal_ref', 'pl.normalized_address'))
            // never a property that is already ANOTHER agent's CoreX listing / prospect
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM properties px WHERE px.deleted_at IS NULL AND px.agency_id = pl.agency_id
                    AND px.id IN (pl.matched_property_id, tp.promoted_to_property_id)
                    AND px.agent_id IS NOT NULL AND px.agent_id NOT IN ({$meIn}) AND px.status IN ({$taken}))"
            )
            // never a listing another agent has claimed or is pitching right now
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM prospecting_claims pc WHERE pc.prospecting_listing_id = pl.id
                    AND pc.is_active = 1 AND pc.released_at IS NULL AND pc.deleted_at IS NULL AND pc.user_id NOT IN ({$meIn}))"
            )
            // the app clock (bound), never SQL NOW(): timestamps are stored in app time, the DB session may not be
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM prospecting_pitch_locks pk WHERE pk.prospecting_listing_id = pl.id
                    AND pk.released_at IS NULL AND pk.expires_at > ? AND pk.user_id NOT IN ({$meIn}))",
                [now()->toDateTimeString()]
            )
            ->select([
                'pl.id as listing_id', 'pl.tracked_property_id', 'pl.matched_property_id', 'pl.price',
                'pl.address', 'pl.suburb', 'pl.listed_date', 'pl.first_seen_at', 'pl.portal_source',
            ])
            ->selectRaw(ProspectingListing::hasAddressSql('pl', 'tp') . ' as has_address')
            ->selectRaw($areaSql . ' as in_area', $areaBindings);

        if ($ctx->excludeTrackedIds !== []) {
            $q->where(fn ($w) => $w->whereNull('pl.tracked_property_id')->orWhereNotIn('pl.tracked_property_id', $ctx->excludeTrackedIds));
        }
        if ($ctx->excludeListingIds !== []) {
            $q->whereNotIn('pl.id', $ctx->excludeListingIds);
        }
        if ($ctx->locked()) {
            $q->whereRaw($areaSql, $areaBindings);
        }

        $this->filter($q, $ctx);

        // Areas first (unless locked, when everything left is in-area), then WITH an address, then the
        // selection's own ranking, then id so the order is stable page to page.
        if (! $ctx->locked() && $ctx->hasAreas()) {
            $q->orderByRaw($areaSql . ' DESC', $areaBindings);
        }
        $q->orderByRaw(ProspectingListing::hasAddressSql('pl', 'tp') . ' DESC');
        $this->order($q, $ctx);
        $q->orderBy('pl.id');

        return $q;
    }

    /** @return array{0:string,1:array} SQL fragment (1 when the listing is in the agent's areas) + bindings */
    private function areaSql(SelectionContext $ctx): array
    {
        $terms = [];
        $bindings = [];
        if ($ctx->areaSuburbIds !== []) {
            $terms[] = 'tp.p24_suburb_id IN (' . implode(',', array_fill(0, count($ctx->areaSuburbIds), '?')) . ')';
            array_push($bindings, ...array_map('intval', $ctx->areaSuburbIds));
        }
        if ($ctx->areaSuburbNames !== []) {
            $marks = implode(',', array_fill(0, count($ctx->areaSuburbNames), '?'));
            $terms[] = "tp.suburb_normalised IN ({$marks})";
            $terms[] = "LOWER(TRIM(pl.suburb)) IN ({$marks})";
            array_push($bindings, ...$ctx->areaSuburbNames, ...$ctx->areaSuburbNames);
        }
        if ($terms === []) {
            return ['0', []];
        }

        return ['(CASE WHEN (' . implode(' OR ', $terms) . ') THEN 1 ELSE 0 END)', $bindings];
    }
}
