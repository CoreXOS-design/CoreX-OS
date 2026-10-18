<?php

namespace App\Services\RoadToSold;

use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Property;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsHiddenArea;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 — which suburbs each agent works, and how fresh each suburb's data is.
 * Contract: .ai/specs/road-to-sold.md §2.3. Lane 2 (ListBuilderService) reads this.
 *
 * POOL (Johan's ruling, 13 Oct 2026 16:03) = the agency's working areas are DERIVED, never hand-set: the suburbs the agency
 * holds stock in (its own properties, Other Agency Stock excluded) and the suburbs its Market Intelligence has captured adverts
 * for (prospecting_listings, placed by the tracked property's p24_suburb_id). A suburb is a working area when stock + adverts
 * reach the agency setting min_area_properties (default 1) and an administrator has not hidden it (or its town). Keyed on
 * p24_suburbs.id - the id tracked_properties.p24_suburb_id holds. There is NO fallback to "every suburb": no stock and no MIC
 * data = an empty pool. Computed on read from two index-backed GROUP BYs and cached per agency for 5 minutes (a maintained
 * table would need hooks into every stock, capture and import path and could drift; the cache is the cheaper, always-true answer).
 *
 * WHOSE AREAS (spec 11.1, the one assistant rule): areas belong to the LIST OWNER - User::ownershipUserId(),
 * the agent an assistant acts for (honouring a multi-agent assistant's "Acting for" choice); a plain user is
 * their own list owner. A direct call with an assistant therefore answers with the agent's areas.
 *
 * MATCHING (spec 5.7): a property belongs to an area by the suburb ID where it has one. Only a row with
 * no suburb id is matched by NAME, and then the name must agree with the area's town (city id, else town
 * name) - so "Beachview" in two towns never merges. A row with no town at all is matched by name only
 * when that name is unique among the agency's pool suburbs.
 */
class AreaService
{
    private const DERIVED_CACHE_SECONDS = 300;

    // ── The derived pool ──────────────────────────────────────────────────

    /**
     * Raw per-suburb facts for the agency, before the minimum and the hidden list are applied.
     *
     * @return array<int,array{own:int,mic:int,p24:?string,pp:?string}> keyed by p24_suburbs.id; p24 / pp = newest advert seen ('Y-m-d H:i:s')
     */
    public function derived(int $agencyId): array
    {
        if ($agencyId <= 0) {
            return [];
        }
        // Tests read straight after writing, so they skip the cache; everywhere else it is 5 minutes.
        if (app()->environment('testing')) {
            return $this->computeDerived($agencyId);
        }

        return Cache::remember('rts:derived-areas:' . $agencyId, self::DERIVED_CACHE_SECONDS, fn () => $this->computeDerived($agencyId));
    }

    public static function forgetDerived(int $agencyId): void
    {
        Cache::forget('rts:derived-areas:' . $agencyId);
    }

    private function computeDerived(int $agencyId): array
    {
        $out  = [];
        $bump = function (int $id, string $field, int $n) use (&$out): void {
            $out[$id] ??= ['own' => 0, 'mic' => 0, 'p24' => null, 'pp' => null];
            $out[$id][$field] += $n;
        };
        $seen = function (int $id, string $portal, ?string $at) use (&$out): void {
            if ($at === null || ! in_array($portal, ['p24', 'pp'], true)) {
                return;
            }
            $out[$id] ??= ['own' => 0, 'mic' => 0, 'p24' => null, 'pp' => null];
            if ($out[$id][$portal] === null || $at > $out[$id][$portal]) {
                $out[$id][$portal] = $at;
            }
        };
        $byName = []; // rows with no suburb id: [city id][lowered name] => [[field, n, portal, at]]

        // (a) the agency's own stock (Other Agency Stock is another agency's advert and never counts)
        $stock = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', Property::STATUS_OTHER_AGENCY_STOCK));
        foreach ((clone $stock)->whereNotNull('p24_suburb_id')->selectRaw('p24_suburb_id as sid, COUNT(*) as n')->groupBy('p24_suburb_id')->get() as $r) {
            $bump((int) $r->sid, 'own', (int) $r->n);
        }
        foreach ((clone $stock)->whereNull('p24_suburb_id')->whereNotNull('p24_city_id')->whereNotNull('suburb')
            ->selectRaw('p24_city_id as cid, LOWER(TRIM(suburb)) as k, COUNT(*) as n')->groupBy('p24_city_id', DB::raw('LOWER(TRIM(suburb))'))->get() as $r) {
            $byName[(int) $r->cid][(string) $r->k][] = ['own', (int) $r->n, null, null];
        }

        // (b) Market Intelligence: every advert captured for the agency, placed by its tracked property's suburb id
        $loose = []; // no suburb id and no city: attached only to a suburb already in the pool whose name is unique there
        foreach (DB::table('prospecting_listings as pl')
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'pl.tracked_property_id')
            ->where('pl.agency_id', $agencyId)->whereNull('pl.deleted_at')
            ->selectRaw('tp.p24_suburb_id as sid, tp.p24_city_id as cid, LOWER(TRIM(pl.suburb)) as k, pl.portal_source as src, COUNT(*) as n, MAX(pl.last_seen_at) as newest')
            ->groupBy('tp.p24_suburb_id', 'tp.p24_city_id', DB::raw('LOWER(TRIM(pl.suburb))'), 'pl.portal_source')->get() as $r) {
            $at = $r->newest ? (string) $r->newest : null;
            if ($r->sid !== null) {
                $bump((int) $r->sid, 'mic', (int) $r->n);
                $seen((int) $r->sid, (string) $r->src, $at);
            } elseif ($r->cid !== null && $r->k !== null && $r->k !== '') {
                $byName[(int) $r->cid][(string) $r->k][] = ['mic', (int) $r->n, (string) $r->src, $at];
            } elseif ($r->k !== null && $r->k !== '') {
                $loose[] = [(string) $r->k, (int) $r->n, (string) $r->src, $at];
            }
        }

        // rows with a city but no suburb id: the suburb is the ONE suburb of that city with that name
        if ($byName !== []) {
            $names = [];
            foreach (DB::table('p24_suburbs')->whereNull('deleted_at')->whereIn('p24_city_id', array_keys($byName))->get(['id', 'name', 'p24_city_id']) as $s) {
                $names[(int) $s->p24_city_id][mb_strtolower(trim((string) $s->name))][] = (int) $s->id;
            }
            foreach ($byName as $cid => $byKey) {
                foreach ($byKey as $k => $entries) {
                    $ids = $names[$cid][$k] ?? [];
                    if (count($ids) !== 1) {
                        continue; // unknown or ambiguous: never guessed
                    }
                    foreach ($entries as [$field, $n, $portal, $at]) {
                        $bump($ids[0], $field, $n);
                        if ($portal !== null) {
                            $seen($ids[0], $portal, $at);
                        }
                    }
                }
            }
        }

        // adverts with no id and no city: attach to a suburb already in the pool when its name is unique among the pool's
        if ($loose !== [] && $out !== []) {
            $unique = [];
            foreach (DB::table('p24_suburbs')->whereIn('id', array_keys($out))->get(['id', 'name']) as $s) {
                $unique[mb_strtolower(trim((string) $s->name))][] = (int) $s->id;
            }
            foreach ($loose as [$k, $n, $portal, $at]) {
                if (count($unique[$k] ?? []) === 1) {
                    $bump($unique[$k][0], 'mic', $n);
                    $seen($unique[$k][0], $portal, $at);
                }
            }
        }

        return $out;
    }

    /** @return array{suburbs:int[],cities:int[]} what an administrator has hidden from Road to Sold */
    public function hiddenSets(int $agencyId, string $track = RtsSetting::TRACK_SALES): array
    {
        $rows = DB::table('rts_hidden_areas')->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')->get(['kind', 'p24_suburb_id', 'p24_city_id']);

        return [
            'suburbs' => $rows->where('kind', RtsHiddenArea::KIND_SUBURB)->pluck('p24_suburb_id')->filter()->map(fn ($i) => (int) $i)->values()->all(),
            'cities'  => $rows->where('kind', RtsHiddenArea::KIND_TOWN)->pluck('p24_city_id')->filter()->map(fn ($i) => (int) $i)->values()->all(),
        ];
    }

    /** @return int[] the suburb ids that reach the agency's minimum (hidden ones are removed in poolQuery). */
    public function candidateIds(int $agencyId, string $track = RtsSetting::TRACK_SALES): array
    {
        $min = SettingsService::forAgency($agencyId, $track)->minAreaProperties();

        return collect($this->derived($agencyId))->filter(fn ($r) => ($r['own'] + $r['mic']) >= $min)->keys()->map(fn ($i) => (int) $i)->all();
    }

    /** Query over the agency's pool: columns id, name, p24_city_id, town_name (the P24 town the suburb sits in). */
    public function poolQuery(int $agencyId, string $track = RtsSetting::TRACK_SALES): Builder
    {
        $hidden = $this->hiddenSets($agencyId, $track);

        return DB::table('p24_suburbs as s')
            ->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')
            ->whereNull('s.deleted_at')
            ->whereIn('s.id', $this->candidateIds($agencyId, $track) ?: [0])
            ->when($hidden['suburbs'] !== [], fn ($q) => $q->whereNotIn('s.id', $hidden['suburbs']))
            ->when($hidden['cities'] !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('s.p24_city_id')->orWhereNotIn('s.p24_city_id', $hidden['cities'])))
            ->selectRaw('s.id, s.name, s.p24_city_id, c.name as town_name');
    }

    /** @param int[] $suburbIds @return int[] the ids that really are in this agency's pool */
    public function idsInPool(int $agencyId, array $suburbIds, string $track = RtsSetting::TRACK_SALES): array
    {
        $suburbIds = array_values(array_unique(array_filter(array_map('intval', $suburbIds), fn ($i) => $i > 0)));
        if ($suburbIds === []) {
            return [];
        }

        return $this->poolQuery($agencyId, $track)->whereIn('s.id', $suburbIds)->pluck('s.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * The towns that have at least one suburb in the pool (for the town filter / grouping): id = the P24 town id.
     *
     * @return Collection<int,object{id:int,name:string,p24_city_id:int}>
     */
    public function towns(int $agencyId, string $track = RtsSetting::TRACK_SALES): Collection
    {
        return $this->poolQuery($agencyId, $track)->whereNotNull('s.p24_city_id')->get()
            ->unique('p24_city_id')
            ->map(fn ($r) => (object) ['id' => (int) $r->p24_city_id, 'name' => (string) ($r->town_name ?? 'Unknown town'), 'p24_city_id' => (int) $r->p24_city_id])
            ->sortBy(fn ($t) => mb_strtolower($t->name))->values();
    }

    /** True when something is hidden from Road to Sold for this agency (the empty state then offers Restore). */
    public function hiddenCount(int $agencyId, string $track = RtsSetting::TRACK_SALES): int
    {
        return DB::table('rts_hidden_areas')->where('agency_id', $agencyId)->where('track', $track)->whereNull('deleted_at')->count();
    }

    /**
     * Hide a suburb or a whole town from Road to Sold (soft - restoreHidden() brings it back).
     *
     * @param  'suburb'|'town' $kind
     */
    public function hide(int $agencyId, string $track, string $kind, int $id, ?int $byUserId, ?string $reason = null): RtsHiddenArea
    {
        $column = $kind === RtsHiddenArea::KIND_TOWN ? 'p24_city_id' : 'p24_suburb_id';
        $row = RtsHiddenArea::withTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('kind', $kind)->where($column, $id)->first();
        if ($row && ! $row->trashed()) {
            return $row;
        }
        if ($row) {
            $row->restore();
            $row->forceFill(['hidden_by_user_id' => $byUserId, 'reason' => $reason])->save();

            return $row;
        }

        return RtsHiddenArea::create(['agency_id' => $agencyId, 'track' => $track, 'kind' => $kind, $column => $id, 'hidden_by_user_id' => $byUserId, 'reason' => $reason]);
    }

    // ── Assignment reads ──────────────────────────────────────────────────

    /** @return int[] the user's active p24_suburb_ids, minus anything an administrator has hidden (in the pool or not — see idsInPool). */
    public function suburbIdsFor(User $user, string $track = RtsSetting::TRACK_SALES): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return [];
        }

        $ids = RtsAgentArea::query()
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', (int) $user->ownershipUserId())
            ->pluck('p24_suburb_id')->map(fn ($i) => (int) $i)->unique()->values()->all();

        // An assignment is kept even when its suburb has since dropped out of the derived pool (nothing is deleted - the
        // Areas screen flags it); only a suburb (or town) an administrator has HIDDEN stops feeding the agent's lists.
        return $this->withoutHidden($agencyId, $track, $ids);
    }

    /** @param int[] $ids @return int[] */
    private function withoutHidden(int $agencyId, string $track, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $hidden = $this->hiddenSets($agencyId, $track);
        if ($hidden['suburbs'] === [] && $hidden['cities'] === []) {
            return $ids;
        }
        $city = DB::table('p24_suburbs')->whereIn('id', $ids)->pluck('p24_city_id', 'id');

        return array_values(array_filter($ids, fn ($id) => ! in_array($id, $hidden['suburbs'], true) && ! in_array((int) ($city[$id] ?? 0), $hidden['cities'], true)));
    }

    /** Suburb names in the tracked-property normalised form (tracked_properties.suburb_normalised). @return string[] */
    public function suburbNamesNormalisedFor(User $user, string $track = RtsSetting::TRACK_SALES): array
    {
        return $this->namesFor($user, $track, fn (string $n) => TrackedPropertyAddress::normaliseSuburb($n));
    }

    /** Suburb names as lower(trim()) — the form MIC uses against prospecting_listings.suburb. @return string[] */
    public function suburbNamesLowerTrimFor(User $user, string $track = RtsSetting::TRACK_SALES): array
    {
        return $this->namesFor($user, $track, fn (string $n) => mb_strtolower(trim($n)));
    }

    /**
     * The user's areas in the form property matching needs: the suburb id, its names, its P24 city and town,
     * and whether its name is unique among the agency's pool suburbs. Feed to matchSql().
     *
     * @return array<int,array{p24_suburb_id:int,name_normalised:string,name_lower:string,p24_city_id:?int,town:?string,unique_in_pool:bool}>
     */
    public function suburbMatchesFor(User $user, string $track = RtsSetting::TRACK_SALES): array
    {
        $ids = $this->suburbIdsFor($user, $track);
        if ($ids === []) {
            return [];
        }
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        // The agent's own suburbs are read straight (an assigned suburb may have dropped below the minimum); "unique" is judged
        // against the whole pool PLUS those suburbs.
        $mine = DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')
            ->whereNull('s.deleted_at')->whereIn('s.id', $ids)->orderBy('s.name')
            ->get(['s.id', 's.name', 's.p24_city_id', DB::raw('c.name as town_name')]);
        $known  = $this->poolQuery($agencyId, $track)->get(['s.id', 's.name'])->keyBy('id');
        foreach ($mine as $r) {
            $known[$r->id] = $r;
        }
        $counts = $known->countBy(fn ($r) => mb_strtolower(trim((string) $r->name)));

        return $mine
            ->map(function ($r) use ($counts) {
                $lower = mb_strtolower(trim((string) $r->name));

                return [
                    'p24_suburb_id'   => (int) $r->id,
                    'name_normalised' => TrackedPropertyAddress::normaliseSuburb((string) $r->name),
                    'name_lower'      => $lower,
                    'p24_city_id'     => $r->p24_city_id !== null ? (int) $r->p24_city_id : null,
                    'town'            => $r->town_name !== null ? (string) $r->town_name : null,
                    'unique_in_pool'  => ($counts[$lower] ?? 0) <= 1,
                ];
            })->values()->all();
    }

    /**
     * The ONE area-membership test: SQL (1 when the row is in one of these areas) and its bindings, for a
     * query that has a tracked-property alias ($tp, may be NULL-joined) and a listing alias ($pl).
     * By suburb id first; by name only for rows with no suburb id and only when the town agrees.
     *
     * @param  array<int,array> $matches from suburbMatchesFor()
     * @return array{0:string,1:array} ['0',[]] when there are no areas
     */
    public static function matchSql(array $matches, string $tp = 'tp', string $pl = 'pl'): array
    {
        if ($matches === []) {
            return ['0', []];
        }

        $ids      = array_values(array_unique(array_map(fn ($m) => (int) $m['p24_suburb_id'], $matches)));
        $terms    = [$tp . '.p24_suburb_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
        $bindings = $ids;

        foreach ($matches as $m) {
            // Bindings are positional: the name test comes first in the SQL, the town test after it.
            $town     = [];
            $townBind = [];
            if ($m['p24_city_id'] !== null) {
                $town[]     = $tp . '.p24_city_id = ?';
                $townBind[] = (int) $m['p24_city_id'];
            }
            $unknown = $tp . '.p24_city_id IS NULL AND ';
            if ($m['town'] !== null && trim((string) $m['town']) !== '') {
                $unknown   .= '(' . $tp . '.town IS NULL OR LOWER(TRIM(' . $tp . '.town)) = ?)';
                $townBind[] = mb_strtolower(trim((string) $m['town']));
            } else {
                $unknown .= $tp . '.town IS NULL';
            }
            // Town unknown on the row: the name alone decides only when it is unique in the pool.
            $town[] = '(' . $unknown . ')';
            $guard  = $m['unique_in_pool']
                ? implode(' OR ', $town)
                : '(' . implode(' OR ', $town) . ') AND NOT (' . $tp . '.p24_city_id IS NULL AND ' . $tp . '.town IS NULL)';

            $terms[] = '(' . $tp . '.p24_suburb_id IS NULL AND (' . $tp . '.suburb_normalised = ? OR LOWER(TRIM(' . $pl . '.suburb)) = ?) AND (' . $guard . '))';
            array_push($bindings, $m['name_normalised'], $m['name_lower'], ...$townBind);
        }

        return ['(CASE WHEN (' . implode(' OR ', $terms) . ') THEN 1 ELSE 0 END)', $bindings];
    }

    public function hasAreas(User $user, string $track = RtsSetting::TRACK_SALES): bool
    {
        return $this->suburbIdsFor($user, $track) !== [];
    }

    /**
     * Active agents in the agency who may use Road to Sold but have no areas yet.
     * Drives the "lock is on, these agents get no list" warning.
     *
     * @return Collection<int,User>
     */
    public function agentsWithoutAreas(int $agencyId, string $track = RtsSetting::TRACK_SALES): Collection
    {
        if ($agencyId <= 0) {
            return collect();
        }

        $withAreas = RtsAgentArea::query()->where('agency_id', $agencyId)->where('track', $track)
            ->pluck('user_id')->unique()->all();

        return User::query()
            ->where('agency_id', $agencyId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('is_assistant')->orWhere('is_assistant', false))
            ->whereNotIn('id', $withAreas)
            ->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('access_road_to_sold'))
            ->values();
    }

    // ── Freshness ("last captured") ───────────────────────────────────────

    /**
     * Newest capture per suburb per portal. P24 = newer of minion_capture_areas.last_captured_at and the newest P24 advert
     * seen; PP = newest PP advert seen. null = never captured. Read from the same derived facts as the pool.
     *
     * @param  int[]|null $suburbIds limit to these (null = every pool suburb)
     * @return array<int,array{p24:?Carbon,pp:?Carbon}> keyed by p24_suburb_id
     */
    public function lastCaptured(int $agencyId, ?array $suburbIds = null, string $track = RtsSetting::TRACK_SALES): array
    {
        $ids = $suburbIds === null
            ? $this->poolQuery($agencyId, $track)->pluck('s.id')->map(fn ($i) => (int) $i)->all()
            : array_values(array_unique(array_map('intval', $suburbIds)));
        if ($ids === []) {
            return [];
        }

        $derived = $this->derived($agencyId);
        $minion  = DB::table('minion_capture_areas')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereIn('p24_suburb_id', $ids)->pluck('last_captured_at', 'p24_suburb_id');

        $out = [];
        foreach ($ids as $id) {
            $d   = $derived[$id] ?? null;
            $p24 = ($d['p24'] ?? null) ? Carbon::parse($d['p24']) : null;
            $pp  = ($d['pp'] ?? null) ? Carbon::parse($d['pp']) : null;
            $m   = $minion->get($id);
            if ($m) {
                $m   = Carbon::parse($m);
                $p24 = ($p24 === null || $m->greaterThan($p24)) ? $m : $p24;
            }
            $out[$id] = ['p24' => $p24, 'pp' => $pp];
        }

        return $out;
    }

    /** Single-suburb convenience over lastCaptured(). @return array{p24:?Carbon,pp:?Carbon} */
    public function lastRefreshed(int $agencyId, int $p24SuburbId): array
    {
        return $this->lastCaptured($agencyId, [$p24SuburbId])[$p24SuburbId] ?? ['p24' => null, 'pp' => null];
    }

    /** null (never) counts as stale. */
    public function isStale(?Carbon $at, int $warnDays): bool
    {
        return $at === null || $at->lt(now()->subDays($warnDays));
    }

    /**
     * Which portals have gone stale for a suburb: 'p24' and/or 'pp' when that portal was not captured
     * within $warnDays (never = stale). Each portal prompts on its own - a fresh P24 capture does not
     * hide a missing Private Property one.
     *
     * Only the portals the agency says it captures from are checked ($portals, default both): an agency
     * without Private Property is never asked to refresh it.
     *
     * @param  array{p24:?Carbon,pp:?Carbon} $captured
     * @param  string[]|null $portals subset of ['p24','pp']; null = both
     * @return string[]
     */
    public function portalsNeedingRefresh(array $captured, int $warnDays, ?array $portals = null): array
    {
        $stale = [];
        foreach (['p24', 'pp'] as $portal) {
            if ($portals !== null && ! in_array($portal, $portals, true)) {
                continue;
            }
            if ($this->isStale($captured[$portal] ?? null, $warnDays)) {
                $stale[] = $portal;
            }
        }

        return $stale;
    }

    /** True when at least one portal needs a refresh. @param array{p24:?Carbon,pp:?Carbon} $captured */
    public function needsRefresh(array $captured, int $warnDays, ?array $portals = null): bool
    {
        return $this->portalsNeedingRefresh($captured, $warnDays, $portals) !== [];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function namesFor(User $user, string $track, callable $format): array
    {
        $ids = $this->suburbIdsFor($user, $track);
        if ($ids === []) {
            return [];
        }

        return DB::table('p24_suburbs')->whereIn('id', $ids)->pluck('name')
            ->map(fn ($n) => $format((string) $n))->filter()->unique()->values()->all();
    }
}
