<?php

namespace App\Services\RoadToSold;

use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 — which suburbs each agent works, and how fresh each suburb's data is.
 * Contract: .ai/specs/road-to-sold.md §2.3. Lane 2 (ListBuilderService) reads this.
 *
 * POOL = the agency's Prospecting Setup suburbs: every p24 suburb in the P24 city of
 * one of the agency's towns (towns.p24_city_id), plus any suburb the agency ticked for
 * P24 auto-import (minion_capture_areas). Keyed on p24_suburbs.id — the same id that
 * tracked_properties.p24_suburb_id holds.
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
    /** Query over the agency's pool: columns id, name, p24_city_id, town_name. */
    public function poolQuery(int $agencyId): Builder
    {
        return DB::table('p24_suburbs as s')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.p24_id')
            ->where(function ($q) use ($agencyId) {
                $q->whereIn('s.p24_city_id', function ($sub) use ($agencyId) {
                    $sub->select('t.p24_city_id')->from('towns as t')
                        ->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereNotNull('t.p24_city_id');
                })->orWhereIn('s.id', function ($sub) use ($agencyId) {
                    $sub->select('m.p24_suburb_id')->from('minion_capture_areas as m')
                        ->where('m.agency_id', $agencyId)->whereNull('m.deleted_at');
                });
            })
            ->selectRaw(
                's.id, s.name, s.p24_city_id, ('
                . 'select t2.name from towns t2 where t2.agency_id = ? and t2.p24_city_id = s.p24_city_id and t2.deleted_at is null '
                . 'order by t2.display_order, t2.id limit 1) as town_name',
                [$agencyId]
            );
    }

    /** @param int[] $suburbIds @return int[] the ids that really are in this agency's pool */
    public function idsInPool(int $agencyId, array $suburbIds): array
    {
        $suburbIds = array_values(array_unique(array_filter(array_map('intval', $suburbIds), fn ($i) => $i > 0)));
        if ($suburbIds === []) {
            return [];
        }

        return $this->poolQuery($agencyId)->whereIn('s.id', $suburbIds)->pluck('s.id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * The agency's towns that map to a P24 city (for the town filter / grouping).
     *
     * @return Collection<int,object{id:int,name:string,p24_city_id:int}>
     */
    public function towns(int $agencyId): Collection
    {
        return DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('p24_city_id')->orderBy('display_order')->orderBy('name')
            ->get(['id', 'name', 'p24_city_id']);
    }

    // ── Assignment reads ──────────────────────────────────────────────────

    /** @return int[] the user's active p24_suburb_ids (still in the pool or not — see idsInPool). */
    public function suburbIdsFor(User $user, string $track = RtsSetting::TRACK_SALES): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return [];
        }

        $ids = RtsAgentArea::query()
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', (int) $user->ownershipUserId())
            ->pluck('p24_suburb_id')->map(fn ($i) => (int) $i)->unique()->values()->all();

        // Ignore assignments whose suburb has since left the agency's Prospecting Setup (spec §6).
        return $this->idsInPool($agencyId, $ids);
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

        $counts = $this->poolQuery($agencyId)->get(['s.id', 's.name'])
            ->countBy(fn ($r) => mb_strtolower(trim((string) $r->name)));

        return $this->poolQuery($agencyId)->whereIn('s.id', $ids)->orderBy('s.name')->get()
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
     * Newest capture per suburb per portal, for the whole agency in two small queries.
     * P24 = newer of minion_capture_areas.last_captured_at and the newest P24 listing seen;
     * PP  = newest PP listing seen. null = never captured.
     *
     * @param  int[]|null $suburbIds limit to these (null = every pool suburb)
     * @return array<int,array{p24:?Carbon,pp:?Carbon}> keyed by p24_suburb_id
     */
    public function lastCaptured(int $agencyId, ?array $suburbIds = null): array
    {
        // The whole pool is read (one query) so a name shared by two pool suburbs is known to be shared,
        // even when only some suburbs were asked for.
        $all   = $this->poolQuery($agencyId)->get(['s.id', 's.name', 's.p24_city_id']);
        $dups  = $all->countBy(fn ($r) => mb_strtolower(trim((string) $r->name)));
        $wanted = $suburbIds === null ? null : array_flip(array_map('intval', $suburbIds));
        $pool  = $all->filter(fn ($r) => $wanted === null || isset($wanted[(int) $r->id]))->values();

        $names = $pool->map(fn ($r) => mb_strtolower(trim((string) $r->name)))->unique()->values()->all();

        // Newest listing per (suburb text, portal, the tracked property's suburb id and city).
        $seen = DB::table('prospecting_listings as pl')
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'pl.tracked_property_id')
            ->where('pl.agency_id', $agencyId)->whereNull('pl.deleted_at')->whereNotNull('pl.suburb')
            ->when($suburbIds !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn(DB::raw('LOWER(TRIM(pl.suburb))'), $names === [] ? ['-'] : $names)
                ->orWhereIn('tp.p24_suburb_id', array_keys($wanted ?? []) ?: [0])))
            ->selectRaw('LOWER(TRIM(pl.suburb)) as k, pl.portal_source, tp.p24_suburb_id as tp_suburb, tp.p24_city_id as tp_city, MAX(pl.last_seen_at) as newest')
            ->groupBy(DB::raw('LOWER(TRIM(pl.suburb))'), 'pl.portal_source', 'tp.p24_suburb_id', 'tp.p24_city_id')
            ->get();

        $minion = DB::table('minion_capture_areas')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($suburbIds !== null, fn ($q) => $q->whereIn('p24_suburb_id', array_keys($wanted ?? []) ?: [0]))
            ->pluck('last_captured_at', 'p24_suburb_id');

        $out = [];
        foreach ($pool as $r) {
            $id    = (int) $r->id;
            $key   = mb_strtolower(trim((string) $r->name));
            $city  = $r->p24_city_id !== null ? (int) $r->p24_city_id : null;
            $p24   = null;
            $pp    = null;
            foreach ($seen as $row) {
                $byId   = $row->tp_suburb !== null && (int) $row->tp_suburb === $id;
                // No suburb id on the row: by name, and the row's city must not contradict this suburb's city.
                // A row with no city at all counts only when the name is unique in the pool.
                $byName = $row->tp_suburb === null && $row->k === $key
                    && ($row->tp_city !== null ? (int) $row->tp_city === $city : ($dups[$key] ?? 0) <= 1);
                if (! $byId && ! $byName) {
                    continue;
                }
                $at = $row->newest ? Carbon::parse($row->newest) : null;
                if ($row->portal_source === 'p24') {
                    $p24 = ($p24 === null || ($at && $at->greaterThan($p24))) ? $at : $p24;
                } elseif ($row->portal_source === 'pp') {
                    $pp = ($pp === null || ($at && $at->greaterThan($pp))) ? $at : $pp;
                }
            }
            $m = $minion->get($id);
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
