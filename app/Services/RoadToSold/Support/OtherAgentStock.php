<?php

namespace App\Services\RoadToSold\Support;

use App\Models\ProspectingListing;
use App\Models\Property;
use App\Services\RoadToSold\Selections\Candidate;
use Illuminate\Support\Facades\DB;

/**
 * "An agent NEVER gets another agent's stock" - on every path (Johan, 13 Oct).
 *
 * One answer to one question: is this property (however the builder came to it: a MIC listing, a
 * follow-up, a rolled-over item, an added extra) already a CoreX property that belongs to a DIFFERENT
 * agent in this agency? It does not depend on the property's status - sold, withdrawn, expired, a
 * prospect or a draft are all still that agent's - and it does not depend on the listing having been
 * linked to the CoreX property: the match also goes through the portal reference and the normalised
 * address, which is how an unlinked property is found. Only a property that is itself another agency's
 * imported listing (status other_agency_stock) belongs to nobody here.
 *
 * Built once per build / add-to-day (it reads the agency's other-agent properties once and holds them
 * in memory); the checks after that are in-memory look-ups plus a point query for an unseen id.
 */
final class OtherAgentStock
{
    private const CHUNK = 3000;

    /** @var array{ids:array<int,true>,refs:array<string,true>,addrs:array<string,true>,tracked:array<int,true>}|null */
    private ?array $sets = null;

    /** @var array<int,int|null> property id => agent_id (null = nobody's / not found) */
    private array $agentOf = [];

    /** @var array<int,object|null> */
    private array $listingFacts = [];

    /** @var array<int,int|null> */
    private array $promotedOf = [];

    /** @param int[] $identityIds the viewer's own ids (an assistant's own is their agent's) */
    public function __construct(private readonly int $agencyId, private readonly array $identityIds)
    {
    }

    /**
     * @param array{property_id?:?int,tracked_property_id?:?int,matched_property_id?:?int,promoted_to_property_id?:?int,portal_ref?:?string,normalized_address?:?string} $facts
     */
    public function ownedByAnother(array $facts): bool
    {
        if ($this->agencyId <= 0) {
            return false;
        }

        // The property itself is known: its own agent decides. The agent's own stock is never "another's".
        if (! empty($facts['property_id'])) {
            $agent = $this->agentOfProperty((int) $facts['property_id']);
            if ($agent !== null) {
                return ! in_array($agent, $this->identityIds, true);
            }
        }

        $sets = $this->sets();
        if (! empty($facts['tracked_property_id']) && isset($sets['tracked'][(int) $facts['tracked_property_id']])) {
            return true;
        }
        foreach (['matched_property_id', 'promoted_to_property_id'] as $key) {
            if (! empty($facts[$key]) && isset($sets['ids'][(int) $facts[$key]])) {
                return true;
            }
        }
        if (! empty($facts['portal_ref']) && isset($sets['refs'][(string) $facts['portal_ref']])) {
            return true;
        }
        if (! empty($facts['normalized_address']) && isset($sets['addrs'][(string) $facts['normalized_address']])) {
            return true;
        }

        return false;
    }

    /** The same question for a candidate from a path that only carries ids (follow-up, rollover, extra). */
    public function candidateOwnedByAnother(Candidate $c): bool
    {
        $facts = [
            'property_id'         => $c->propertyId,
            'tracked_property_id' => $c->trackedPropertyId,
        ];
        if ($c->prospectingListingId) {
            $l = $this->listing((int) $c->prospectingListingId);
            if ($l) {
                $facts['matched_property_id'] = $l->matched_property_id;
                $facts['portal_ref']          = $l->portal_ref;
                $facts['normalized_address']  = $l->normalized_address;
                $facts['tracked_property_id'] ??= $l->tracked_property_id;
            }
        }
        if ($c->trackedPropertyId) {
            $facts['promoted_to_property_id'] = $this->promotedTo((int) $c->trackedPropertyId);
        }

        return $this->ownedByAnother($facts);
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @return array{ids:array<int,true>,refs:array<string,true>,addrs:array<string,true>,tracked:array<int,true>} */
    private function sets(): array
    {
        if ($this->sets !== null) {
            return $this->sets;
        }

        $rows = $this->others('p')->get(['p.id', 'p.p24_ref', 'p.pp_ref', 'p.address', 'p.suburb']);

        $ids = [];
        $refs = [];
        $addrs = [];
        foreach ($rows as $p) {
            $ids[(int) $p->id] = true;
            if (! empty($p->p24_ref)) {
                $refs['P24-' . $p->p24_ref] = true;
            }
            if (! empty($p->pp_ref)) {
                $refs['PP-' . $p->pp_ref] = true;
            }
            $norm = ProspectingListing::normalizeAddress($p->address, $p->suburb ?? '');
            if ($norm) {
                $addrs[$norm] = true;
            }
        }

        // Every tracked property that is, or has an advert that is, one of those properties - found with joins
        // (one indexed query each), not with thousands of ids pasted into IN lists.
        $tracked = [];
        $collect = function ($query) use (&$tracked) {
            foreach ($query->pluck('tracked_property_id') as $tid) {
                if ($tid) {
                    $tracked[(int) $tid] = true;
                }
            }
        };
        $collect($this->others('p')
            ->join('tracked_properties as tp', 'tp.promoted_to_property_id', '=', 'p.id')
            ->where('tp.agency_id', $this->agencyId)->whereNull('tp.deleted_at')
            ->select(DB::raw('tp.id as tracked_property_id')));
        $collect($this->others('p')
            ->join('prospecting_listings as pl', 'pl.matched_property_id', '=', 'p.id')
            ->where('pl.agency_id', $this->agencyId)->whereNull('pl.deleted_at')->whereNotNull('pl.tracked_property_id')
            ->select('pl.tracked_property_id'));
        foreach (['p24' => ['P24-', 'p24_ref'], 'pp' => ['PP-', 'pp_ref']] as $source => [$prefix, $column]) {
            $collect($this->others('p')
                ->whereNotNull("p.{$column}")->where("p.{$column}", '<>', '')
                ->join('prospecting_listings as pl', function ($j) use ($source, $prefix, $column) {
                    $j->on('pl.agency_id', '=', 'p.agency_id')->where('pl.portal_source', '=', $source)
                      ->whereRaw("pl.portal_ref = CONCAT('{$prefix}', p.{$column})");
                })
                ->whereNull('pl.deleted_at')->whereNotNull('pl.tracked_property_id')
                ->select('pl.tracked_property_id'));
        }
        // addresses are normalised in PHP (no stored column on properties), so they go in as a few large IN lists
        foreach (array_chunk(array_keys($addrs), self::CHUNK) as $chunk) {
            $collect($this->listings()->whereIn('normalized_address', $chunk));
        }

        return $this->sets = ['ids' => $ids, 'refs' => $refs, 'addrs' => $addrs, 'tracked' => $tracked];
    }

    /** The agency's properties that belong to another agent (not its own, not another agency's imported listing). */
    private function others(string $alias)
    {
        return DB::table("properties as {$alias}")
            ->where("{$alias}.agency_id", $this->agencyId)
            ->whereNull("{$alias}.deleted_at")
            ->whereNotNull("{$alias}.agent_id")
            ->when($this->identityIds !== [], fn ($q) => $q->whereNotIn("{$alias}.agent_id", $this->identityIds))
            ->where(fn ($w) => $w->whereNull("{$alias}.status")->orWhere("{$alias}.status", '<>', Property::STATUS_OTHER_AGENCY_STOCK));
    }

    private function listings()
    {
        return DB::table('prospecting_listings')->select('tracked_property_id')
            ->where('agency_id', $this->agencyId)->whereNull('deleted_at')->whereNotNull('tracked_property_id');
    }

    private function agentOfProperty(int $propertyId): ?int
    {
        if (! array_key_exists($propertyId, $this->agentOf)) {
            $p = DB::table('properties')->where('id', $propertyId)->where('agency_id', $this->agencyId)->whereNull('deleted_at')
                ->first(['agent_id', 'status']);
            $this->agentOf[$propertyId] = ($p && $p->agent_id && $p->status !== Property::STATUS_OTHER_AGENCY_STOCK) ? (int) $p->agent_id : null;
        }

        return $this->agentOf[$propertyId];
    }

    private function listing(int $listingId): ?object
    {
        if (! array_key_exists($listingId, $this->listingFacts)) {
            $this->listingFacts[$listingId] = DB::table('prospecting_listings')->where('id', $listingId)->where('agency_id', $this->agencyId)
                ->first(['matched_property_id', 'portal_ref', 'normalized_address', 'tracked_property_id']);
        }

        return $this->listingFacts[$listingId];
    }

    private function promotedTo(int $trackedId): ?int
    {
        if (! array_key_exists($trackedId, $this->promotedOf)) {
            $v = DB::table('tracked_properties')->where('id', $trackedId)->where('agency_id', $this->agencyId)->value('promoted_to_property_id');
            $this->promotedOf[$trackedId] = $v ? (int) $v : null;
        }

        return $this->promotedOf[$trackedId];
    }
}
