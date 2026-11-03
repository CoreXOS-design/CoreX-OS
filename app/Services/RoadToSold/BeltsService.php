<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold page layout v2 (road-to-sold.md 23.31): the three belts of the page - Get stock (BeltService's ten tiles), Maintain stock (23.26) and Sell stock (23.27) -
 * with the SAME four-line tile on every station (label, N to do, N moved on today, N late, each its own link) and exactly one belt open.
 *
 * Maintain and Sell are rendered from config('road-to-sold-belt.belts'). A station whose data exists (Maintain: the agent's own Active properties) is counted from it;
 * a station whose data is a GAP in 23.26 / 23.27 (every Sell station: there is no buyer-lead record yet; every "late" figure: the due windows are not set up) shows 0 and says so
 * on its list. Nothing here invents a number.
 */
final class BeltsService
{
    private const PER_PAGE = 25;

    /** @return list<array<string,mixed>> the configured stations of a belt other than Get stock */
    public static function stationsOf(string $belt): array
    {
        return array_values((array) (config('road-to-sold-belt.belts.' . $belt . '.stations') ?? []));
    }

    /** A Maintain / Sell station by key, with its belt; null for anything else (the Get-stock stations are BeltService::station()). */
    public static function station(string $key): ?array
    {
        foreach (['maintain', 'sell'] as $belt) {
            foreach (self::stationsOf($belt) as $s) {
                if ($s['key'] === $key) {
                    return $s + ['belt' => $belt];
                }
            }
        }

        return null;
    }

    public static function label(string $belt): string
    {
        return (string) (config('road-to-sold-belt.belts.' . $belt . '.label') ?? ucfirst($belt) . ' stock');
    }

    /**
     * The three belts, in order, with the one that is open. Opening rule (23.31): the belt the user asked for (?belt=) > the one they left open last time > the belt with the
     * most late items > Get stock.
     *
     * @param  list<array<string,mixed>>  $getTiles  BeltService::tiles()
     * @return array{belts:list<array<string,mixed>>, open:string}
     */
    public function belts(User $agent, array $getTiles, array $routeBase, ?string $asked, ?string $remembered, ?Carbon $day = null): array
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $belts = [];
        $get = array_map(fn (array $t) => $this->withFourLines($t, $routeBase), $getTiles);
        $belts[] = $this->belt('get', $get);
        foreach (['maintain', 'sell'] as $b) {
            $tiles = [];
            $figs = $b === 'maintain' ? $this->maintainFigures($agent, $day) : [];
            foreach (self::stationsOf($b) as $s) {
                $f = $figs[$s['key']] ?? ['todo' => 0, 'moved' => 0, 'late' => 0];
                $link = fn (array $x) => array_filter($routeBase + ['station' => $s['key']] + $x, fn ($v) => $v !== null && $v !== '');
                $tiles[] = [
                    'key' => $s['key'], 'label' => (string) $s['label'], 'short' => (string) $s['short'], 'kind' => 'backlog', 'count' => $f['todo'], 'alert' => $f['late'],
                    'todo' => $f['todo'], 'moved' => $f['moved'], 'late' => $f['late'], 'tgt_line' => null, 'gap' => ! empty($s['gap']) ? (string) $s['gap'] : null,
                    'url' => $link([]),
                    'lines' => [
                        ['key' => 'todo', 'text' => $f['todo'] . ' to do', 'n' => $f['todo'], 'url' => $link([]), 'bad' => false],
                        ['key' => 'moved', 'text' => $f['moved'] . ' moved on today', 'n' => $f['moved'], 'url' => $link(['filter' => 'moved']), 'bad' => false],
                        ['key' => 'late', 'text' => $f['late'] . ' late', 'n' => $f['late'], 'url' => $link(['filter' => 'late']), 'bad' => $f['late'] > 0],
                    ],
                ];
            }
            $belts[] = $this->belt($b, $tiles);
        }

        $valid = array_column($belts, 'key');
        $open = in_array($asked, $valid, true) ? $asked : (in_array($remembered, $valid, true) ? $remembered : null);
        if ($open === null) {
            $mostLate = collect($belts)->sortByDesc('late')->first();
            $open = $mostLate && $mostLate['late'] > 0 ? $mostLate['key'] : 'get';
        }
        foreach ($belts as &$b) {
            $b['open'] = $b['key'] === $open;
        }
        unset($b);

        return ['belts' => $belts, 'open' => $open];
    }

    /** @param list<array<string,mixed>> $tiles */
    private function belt(string $key, array $tiles): array
    {
        // an arrival tile (Draft / Active / Advertised) has nothing "to do" in the backlog sense - its first line is "this month"
        $todo = (int) collect($tiles)->reject(fn ($t) => ($t['kind'] ?? '') === 'arrival')->sum('todo');

        return [
            'key' => $key, 'label' => self::label($key), 'stations' => count($tiles), 'tiles' => $tiles,
            'todo' => $todo, 'late' => (int) collect($tiles)->sum('late'), 'open' => false,
        ];
    }

    /** The tile's lines are always four with the label: to do / moved on today / late - a station with no late line (the last four, and Today's work) still gets one. */
    private function withFourLines(array $t, array $routeBase): array
    {
        $lines = $t['lines'] ?? [];
        if (! collect($lines)->contains('key', 'late')) {
            $key = (string) $t['key'];
            $url = $key === 'loaded' ? array_filter($routeBase + ['status' => 'missed']) : array_filter($routeBase + ['station' => $key, 'filter' => in_array($t['kind'] ?? '', ['arrival'], true) ? 'todo' : 'late']);
            $lines[] = ['key' => 'late', 'text' => '0 late', 'n' => 0, 'url' => $url, 'bad' => false];
        }
        $t['lines'] = $lines;

        return $t;
    }

    // ---- Maintain stock (23.26): the agent's own Active properties ----

    private function base(User $agent)
    {
        return DB::table('properties as p')->where('p.agency_id', (int) ($agent->effectiveAgencyId() ?: 0))->where('p.agent_id', $agent->id)
            ->whereIn('p.status', ['active', 'for_sale'])->whereNull('p.deleted_at');
    }

    /** SQL that is true when the property has reached the station (23.26.1). */
    private function doneSql(string $source): string
    {
        return match ($source) {
            'portals' => '((p.p24_activated_at IS NOT NULL AND p.p24_last_submitted_at IS NOT NULL) OR (p.pp_activated_at IS NOT NULL AND p.pp_last_submitted_at IS NOT NULL))',
            'social' => "EXISTS (SELECT 1 FROM property_marketing_posts m WHERE m.property_id = p.id AND m.status = 'published' AND m.deleted_at IS NULL)",
            'sellerlink' => 'EXISTS (SELECT 1 FROM property_seller_links s WHERE s.property_id = p.id AND s.revoked_at IS NULL)',
            default => '0',
        };
    }

    /** SQL for WHEN the property first reached the station (null when it has not). */
    private function whenSql(string $source): string
    {
        return match ($source) {
            'portals' => 'LEAST(COALESCE(CASE WHEN p.p24_last_submitted_at IS NOT NULL THEN p.p24_activated_at END, \'9999-12-31\'), COALESCE(CASE WHEN p.pp_last_submitted_at IS NOT NULL THEN p.pp_activated_at END, \'9999-12-31\'))',
            'social' => "(SELECT MIN(m.published_at) FROM property_marketing_posts m WHERE m.property_id = p.id AND m.status = 'published' AND m.deleted_at IS NULL)",
            'sellerlink' => '(SELECT MIN(s.generated_at) FROM property_seller_links s WHERE s.property_id = p.id AND s.revoked_at IS NULL)',
            default => 'NULL',
        };
    }

    /** @return array<string,array{todo:int,moved:int,late:int}> late is 0 until the due windows exist (23.26: open question 11) */
    public function maintainFigures(User $agent, Carbon $day): array
    {
        $out = [];
        foreach (self::stationsOf('maintain') as $s) {
            $src = (string) ($s['source'] ?? '');
            $todo = $this->base($agent)->whereRaw('NOT ' . $this->doneSql($src))->count();
            $moved = $this->base($agent)->whereRaw($this->doneSql($src))->whereRaw($this->whenSql($src) . ' >= ? AND ' . $this->whenSql($src) . ' < ?', [$day->toDateTimeString(), $day->copy()->addDay()->toDateTimeString()])->count();
            $out[$s['key']] = ['todo' => $todo, 'moved' => $moved, 'late' => 0];
        }

        return $out;
    }

    /**
     * The list behind a Maintain / Sell tile, in the shape _block-station reads. Maintain lists the agent's properties (one simple row each); Sell has no record to list yet.
     *
     * @param  array{q?:string,page?:int,filter?:string,date?:?string}  $f
     */
    public function list(User $agent, string $stationKey, array $f): array
    {
        $s = self::station($stationKey);
        abort_if($s === null, 404);
        $day = Carbon::parse($f['date'] ?? now()->toDateString())->startOfDay();
        $filters = ['todo' => 'To do', 'moved' => 'Moved on today', 'late' => 'Late', 'all' => 'All'];
        $filter = array_key_exists((string) ($f['filter'] ?? ''), $filters) ? (string) $f['filter'] : 'todo';
        $page = max(1, (int) ($f['page'] ?? 1));
        $figs = $s['belt'] === 'maintain' ? ($this->maintainFigures($agent, $day)[$stationKey] ?? ['todo' => 0, 'moved' => 0, 'late' => 0]) : ['todo' => 0, 'moved' => 0, 'late' => 0];
        $label = (string) $s['label'];
        $rows = collect();
        if ($s['belt'] === 'maintain' && in_array($filter, ['todo', 'moved', 'all'], true)) {
            $src = (string) $s['source'];
            $q = $this->base($agent)->selectRaw('p.id as id, p.title as title, p.address as address, p.suburb as suburb, ' . $this->whenSql($src) . ' as reached');
            if ($filter === 'todo') {
                $q->whereRaw('NOT ' . $this->doneSql($src));
            } elseif ($filter === 'moved') {
                $q->whereRaw($this->doneSql($src))->whereRaw($this->whenSql($src) . ' >= ? AND ' . $this->whenSql($src) . ' < ?', [$day->toDateTimeString(), $day->copy()->addDay()->toDateTimeString()]);
            }
            $term = trim((string) ($f['q'] ?? ''));
            if ($term !== '') {
                $q->where(fn ($w) => $w->where('p.address', 'like', '%' . $term . '%')->orWhere('p.title', 'like', '%' . $term . '%')->orWhere('p.suburb', 'like', '%' . $term . '%'));
            }
            $rows = $q->orderBy('p.address')->get()->map(fn ($r) => [
                'id' => (int) $r->id, 'title' => trim((string) ($r->address ?: $r->title)), 'suburb' => (string) $r->suburb,
                'reached' => $r->reached && ! str_starts_with((string) $r->reached, '9999') ? Carbon::parse($r->reached)->format('j M Y') : null,
                'url' => \Illuminate\Support\Facades\Route::has('corex.properties.show') ? route('corex.properties.show', $r->id) : null,
            ]);
        }
        $paginator = new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]);
        $headings = ['todo' => $label . ' - to do', 'moved' => $label . ' - moved on today', 'late' => $label . ' - late', 'all' => $label . ' - everything'];
        $empty = ! empty($s['gap']) ? $s['gap'] : ($filter === 'late' ? 'Nothing is late here: the due windows for this belt are not set up yet, so nothing can be late.' : ($filter === 'moved' ? 'Nothing has moved on from this station today.' : 'Nothing is waiting at this station.'));

        return [
            'rows' => $paginator, 'label' => $label, 'heading' => $headings[$filter], 'empty' => $empty, 'owed' => false, 'simple' => true,
            'filter' => $filter, 'filters' => collect($filters)->map(fn ($l, $k) => ['label' => $l, 'n' => match ($k) { 'todo' => $figs['todo'], 'moved' => $figs['moved'], 'late' => $figs['late'], default => null }])->all(),
            'owed_count' => 0, 'kind' => 'backlog',
        ];
    }
}
