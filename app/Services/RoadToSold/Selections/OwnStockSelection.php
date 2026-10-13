<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use App\Services\RoadToSold\Support\TrackedPropertyResolver;
use Illuminate\Support\Facades\DB;

/**
 * Shared base for selections 8 and 9 — the agent's OWN CoreX stock.
 *
 * Hard rule (Johan): an agent NEVER gets another agent's stock. Every query here is anchored on
 * properties.agent_id IN (the viewer's own identity ids) — there is no code path in this class or its
 * children that reads another agent's property. For a listing-agent-only selection that is the whole
 * "scoping": an agent who owns no stock simply gets nothing from it.
 *
 * The canonical property key is the tracked property (conductor ruling): looked up through
 * tracked_properties.promoted_to_property_id, and created through match-or-create when the property was
 * never tracked, so holds and do-not-contact always see the row; the property id rides along for links.
 */
abstract class OwnStockSelection implements Selection
{
    /** properties.listing_type for the track. */
    protected function listingType(string $track): string
    {
        return $track === 'rentals' ? 'rental' : 'sale';
    }

    protected function ownProperties(SelectionContext $ctx): Builder
    {
        $ids = $ctx->identityIds ?: [$ctx->user->id];

        $q = DB::table('properties as p')
            ->where('p.agency_id', $ctx->agencyId)
            ->whereNull('p.deleted_at')
            ->whereIn('p.agent_id', array_map('intval', $ids))
            ->where('p.listing_type', $this->listingType($ctx->track));

        if ($ctx->excludePropertyIds !== []) {
            $q->whereNotIn('p.id', $ctx->excludePropertyIds);
        }

        return $q;
    }

    /** @param int[] $propertyIds @return array<int,int> property id => tracked property id */
    protected function trackedIdsFor(array $propertyIds, int $agencyId): array
    {
        if ($propertyIds === []) {
            return [];
        }

        $map = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['archived', 'duplicate'])
            ->whereIn('promoted_to_property_id', $propertyIds)
            ->groupBy('promoted_to_property_id')
            ->select('promoted_to_property_id', DB::raw('MIN(id) as tracked_id'))
            ->pluck('tracked_id', 'promoted_to_property_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        // A property never promoted but already given a tracked property by an earlier build (match-or-create's
        // external reference): found again here, so it is not matched afresh on every build.
        $missing = array_values(array_diff($propertyIds, array_keys($map)));
        if ($missing !== []) {
            $refs = DB::table('tracked_property_external_refs as r')
                ->join('tracked_properties as t', 't.id', '=', 'r.tracked_property_id')
                ->where('r.agency_id', $agencyId)->where('r.source_type', TrackedPropertyResolver::PROPERTY_SOURCE)->whereNull('r.deleted_at')
                ->whereNull('t.deleted_at')->whereNotIn('t.status', ['archived', 'duplicate'])
                ->whereIn('r.source_ref', array_map(fn ($id) => TrackedPropertyResolver::PROPERTY_REF_PREFIX . $id, $missing))
                ->get(['r.source_ref', 'r.tracked_property_id']);
            foreach ($refs as $r) {
                $map[(int) substr($r->source_ref, strlen(TrackedPropertyResolver::PROPERTY_REF_PREFIX))] = (int) $r->tracked_property_id;
            }
        }

        return $map;
    }

    /**
     * Turn property rows (already in the order the selection wants) into candidates: attach the
     * tracked property, drop anything the guard blocks or the agent already holds, drop anything
     * already on today's list, stop at the limit.
     *
     * @param  iterable<object{id:int,address:?string}> $rows
     * @return Candidate[]
     */
    protected function toCandidates(iterable $rows, SelectionContext $ctx, int $limit, callable $reason, string $key): array
    {
        $rows = collect($rows)->values();
        $tracked = $this->trackedIdsFor($rows->pluck('id')->map(fn ($v) => (int) $v)->all(), $ctx->agencyId);
        $excluded = array_flip($ctx->excludeTrackedIds);

        // Holds and do-not-contact are keyed on the tracked property, so every row needs one: an own property
        // never tracked yet gets one here (match-or-create); if that fails the property is not suggested.
        $keyed = [];
        foreach ($rows as $row) {
            $tid = $tracked[(int) $row->id] ?? $ctx->resolver()->forProperty((int) $row->id, $ctx->agencyId, $ctx->user->id);
            if ($tid === null) {
                continue;
            }
            $keyed[] = [$row, (int) $tid];
        }
        $trackedIds = array_values(array_unique(array_column($keyed, 1)));
        $blocked = array_flip($ctx->guard->blockedFor($ctx->user, $trackedIds, $ctx->track, $ctx->date));
        $held = array_flip($ctx->guard->ownHeld($ctx->user, $trackedIds, $ctx->track));

        $out = [];
        foreach ($keyed as [$row, $tid]) {
            if (isset($blocked[$tid]) || isset($held[$tid]) || isset($excluded[$tid])) {
                continue;
            }
            $out[] = new Candidate(
                trackedPropertyId: $tid,
                prospectingListingId: null,
                propertyId: (int) $row->id,
                selection: $key,
                reason: $reason($row),
                hasAddress: trim((string) $row->address) !== '',
                inArea: true, // the agent's own stock is theirs wherever it is
            );
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
