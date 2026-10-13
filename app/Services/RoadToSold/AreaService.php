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
            ->where('agency_id', $agencyId)->where('track', $track)->where('user_id', $user->id)
            ->pluck('p24_suburb_id')->map(fn ($i) => (int) $i)->all();

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
        $pool = $this->poolQuery($agencyId);
        if ($suburbIds !== null) {
            $pool->whereIn('s.id', array_map('intval', $suburbIds));
        }
        $names = $pool->get(['s.id', 's.name'])->mapWithKeys(fn ($r) => [(int) $r->id => mb_strtolower(trim((string) $r->name))]);

        $seen = DB::table('prospecting_listings')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('suburb')
            ->when($suburbIds !== null, fn ($q) => $q->whereIn(DB::raw('LOWER(TRIM(suburb))'), $names->values()->unique()->all()))
            ->selectRaw('LOWER(TRIM(suburb)) as k, portal_source, MAX(last_seen_at) as newest')
            ->groupBy(DB::raw('LOWER(TRIM(suburb))'), 'portal_source')
            ->get()
            ->groupBy('k');

        $minion = DB::table('minion_capture_areas')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($suburbIds !== null, fn ($q) => $q->whereIn('p24_suburb_id', $names->keys()->all()))
            ->pluck('last_captured_at', 'p24_suburb_id');

        $out = [];
        foreach ($names as $id => $key) {
            $p24 = null;
            $pp  = null;
            foreach ($seen->get($key, collect()) as $row) {
                $at = $row->newest ? Carbon::parse($row->newest) : null;
                if ($row->portal_source === 'p24') {
                    $p24 = $at;
                } elseif ($row->portal_source === 'pp') {
                    $pp = $at;
                }
            }
            $m = $minion->get($id);
            if ($m) {
                $m   = Carbon::parse($m);
                $p24 = ($p24 === null || $m->greaterThan($p24)) ? $m : $p24;
            }
            $out[(int) $id] = ['p24' => $p24, 'pp' => $pp];
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
     * The suburb needs a manual refresh when NEITHER portal has been captured within $warnDays.
     *
     * @param array{p24:?Carbon,pp:?Carbon} $captured
     */
    public function needsRefresh(array $captured, int $warnDays): bool
    {
        $newest = collect([$captured['p24'] ?? null, $captured['pp'] ?? null])->filter()->max();

        return $this->isStale($newest, $warnDays);
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
