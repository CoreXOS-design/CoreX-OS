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
    /** The first window read from the pool; each further window doubles, up to MAX_PAGE (the scan never stops short). */
    private const PAGE = 200;
    private const MAX_PAGE = 3200;

    /** Rows read per window; overridable so a test can prove the scan keeps going past a short first window. */
    protected function firstPage(): int
    {
        return self::PAGE;
    }

    /** prospecting_listings.listing_type for the track. */
    protected function listingType(string $track): string
    {
        return $track === 'rentals' ? 'rental' : 'sale';
    }

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
        $offset = 0;
        $size = max(1, $this->firstPage());
        $stock = $ctx->otherStock();
        $excluded = array_flip($ctx->excludeTrackedIds);

        // Keep reading windows until the day is filled or the pool is exhausted - never a fixed cut-off that
        // could leave a day short while eligible properties remain further down the ranking.
        while (count($out) < $limit) {
            $rows = $this->pool($ctx)->offset($offset)->limit($size)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $offset += $rows->count();

            $trackedIds = $rows->pluck('tracked_property_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
            $blocked = array_flip($ctx->guard->blockedFor($ctx->user, $trackedIds, $ctx->track));
            $held = $this->skipOwnHeld() ? array_flip($ctx->guard->ownHeld($ctx->user, $trackedIds, $ctx->track)) : [];

            foreach ($rows as $row) {
                // Never another agent's stock - linked or not, whatever its status.
                if ($stock->ownedByAnother([
                    'tracked_property_id'     => $row->tracked_property_id,
                    'matched_property_id'     => $row->matched_property_id,
                    'promoted_to_property_id' => $row->promoted_to_property_id,
                    'portal_ref'              => $row->portal_ref,
                    'normalized_address'      => $row->normalized_address,
                ])) {
                    continue;
                }

                $tid = $row->tracked_property_id ? (int) $row->tracked_property_id : null;
                if ($tid !== null) {
                    if (isset($blocked[$tid]) || isset($held[$tid])) {
                        continue;
                    }
                } else {
                    // Every row on a list has a tracked property, so a hold or a do-not-contact can always see it.
                    $tid = $ctx->resolver()->forListing((int) $row->listing_id, $ctx->agencyId, $ctx->user->id);
                    if ($tid === null || isset($excluded[$tid])) {
                        continue;
                    }
                    if ($ctx->guard->blockedFor($ctx->user, [$tid], $ctx->track) !== []
                        || ($this->skipOwnHeld() && $ctx->guard->ownHeld($ctx->user, [$tid], $ctx->track) !== [])) {
                        continue;
                    }
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

            if ($rows->count() < $size) {
                break;
            }
            $size = min($size * 2, self::MAX_PAGE);
        }

        return $out;
    }

    /** The base pool for this agent and day, with this selection's filters and ordering applied. */
    protected function pool(SelectionContext $ctx): Builder
    {
        $me = $ctx->identityIds ?: [$ctx->user->id];
        $meIn = implode(',', array_map('intval', $me));

        [$areaSql, $areaBindings] = $this->areaSql($ctx);

        $q = DB::table('prospecting_listings as pl')
            ->leftJoin('tracked_properties as tp', function ($j) {
                $j->on('tp.id', '=', 'pl.tracked_property_id')->whereNull('tp.deleted_at');
            })
            ->where('pl.agency_id', $ctx->agencyId)
            ->whereNull('pl.deleted_at')
            ->where('pl.is_active', 1)
            // the track: sales draws on sale adverts, rentals on adverts to let
            ->where('pl.listing_type', $this->listingType($ctx->track))
            // a listing whose tracked property was deleted is no longer a property we can work
            ->where(fn ($w) => $w->whereNull('pl.tracked_property_id')->orWhereNotNull('tp.id'))
            ->where(fn ($w) => $w->whereNull('tp.id')->orWhereNotIn('tp.status', ['archived', 'duplicate']))
            // never the agency's own on-market stock (the agent's own stock is selections 8 and 9)
            ->where(fn ($w) => app(OnMarketStockService::class)->applyNotStock($w, $ctx->agencyId, 'pl.portal_ref', 'pl.normalized_address'))
            // never a property that is already ANOTHER agent's CoreX property, whatever its status (the linked case;
            // the unlinked case - same portal reference or address, no link - is caught per row by OtherAgentStock).
            // Two plain primary-key checks, NOT `px.id IN (a, b)`: that form defeats the key and scans properties
            // for every listing (60s+ on 34k live rows, found on real QA1 data; this form takes 0.26s).
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM properties px WHERE px.id = pl.matched_property_id AND px.deleted_at IS NULL
                    AND px.agent_id IS NOT NULL AND px.agent_id NOT IN ({$meIn}) AND (px.status IS NULL OR px.status <> 'other_agency_stock'))"
            )
            ->whereRaw(
                "NOT EXISTS (SELECT 1 FROM properties px2 WHERE px2.id = tp.promoted_to_property_id AND px2.deleted_at IS NULL
                    AND px2.agent_id IS NOT NULL AND px2.agent_id NOT IN ({$meIn}) AND (px2.status IS NULL OR px2.status <> 'other_agency_stock'))"
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
                'pl.portal_ref', 'pl.normalized_address', 'tp.promoted_to_property_id',
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
            $terms[] = 'tp.suburb_normalised IN (' . implode(',', array_fill(0, count($ctx->areaSuburbNames), '?')) . ')';
            array_push($bindings, ...$ctx->areaSuburbNames);
        }
        if ($ctx->areaSuburbNamesLower !== []) {
            $terms[] = 'LOWER(TRIM(pl.suburb)) IN (' . implode(',', array_fill(0, count($ctx->areaSuburbNamesLower), '?')) . ')';
            array_push($bindings, ...$ctx->areaSuburbNamesLower);
        }
        if ($terms === []) {
            return ['0', []];
        }

        return ['(CASE WHEN (' . implode(' OR ', $terms) . ') THEN 1 ELSE 0 END)', $bindings];
    }
}
