<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Services\Address\SuburbResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which suburbs are OUR working suburbs, and the counts behind each (mic-work-tab.md 11.3.K; Johan's ruling of 31 Oct, read by the conductor 19:30).
 *
 * A suburb is ours when ANY of these is true: the agency ran a crawl or search that NAMED it (a deliberate import - not the P24 alert emails, which are a feed
 * the agency does not choose suburb by suburb, and not a town-wide or region-wide page); it holds own stock or a claim there; it is ticked as an Area in the MIC
 * Settings. "Hide this suburb" removes one imported by mistake (an Area stays even if also hidden). Adverts, alerts and leads are counted for information only.
 *
 * `counts()` is the single source for Settings > Areas "Suggested from your work"; `ids()` is the working set the panel and tile 11 use.
 */
final class WorkingSuburbs
{
    /**
     * @return list<array{suburb_id:int, suburb:string, searches:int, adverts:int, alerts:int, stock:int, claims:int, leads:int, total:int, why:list<string>, qualifies:bool, is_area:bool, hidden:bool}>
     */
    public function counts(int $agencyId): array
    {
        $by = [];
        $add = function ($rows, string $key) use (&$by) {
            foreach ($rows as $r) {
                if ($r->sid) {
                    $by[(int) $r->sid][$key] = (int) $r->n;
                }
            }
        };
        // searched: suburbs the agency's own crawls / searches NAMED (a results page for that suburb). A town-wide or region-wide page names no suburb, and the
        // P24 alert emails are a feed the agency does not choose suburb by suburb: neither makes a suburb ours. Adverts and alerts are counted for information only.
        foreach ($this->searchedSuburbs($agencyId) as $sid => $n) {
            $by[$sid]['searches'] = $n;
        }
        $add(DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('p24_suburb_id')
            ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id as sid, COUNT(*) as n')->get(), 'adverts');
        foreach (DB::table('p24_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereRaw("TRIM(COALESCE(suburb,'')) <> ''")->groupBy('suburb')->selectRaw('suburb, COUNT(*) as n')->get() as $r) {
            $id = SuburbResolver::resolve((string) $r->suburb)['p24_suburb_id'];
            if ($id) {
                $by[(int) $id]['alerts'] = ($by[(int) $id]['alerts'] ?? 0) + (int) $r->n;
            }
        }
        if (Schema::hasTable('mic_file_stats')) {
            $add(DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('own_stock', 1)->whereNotNull('p24_suburb_id')
                ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id as sid, COUNT(*) as n')->get(), 'stock');
        }
        $add(DB::table('tracked_property_agency_work as w')->join('tracked_properties as t', 't.id', '=', 'w.tracked_property_id')
            ->where('w.agency_id', $agencyId)->whereNull('w.deleted_at')->whereNotNull('w.claimed_by_user_id')->whereNotNull('t.p24_suburb_id')
            ->groupBy('t.p24_suburb_id')->selectRaw('t.p24_suburb_id as sid, COUNT(*) as n')->get(), 'claims');
        $add(DB::table('portal_leads as l')->join('properties as p', function ($j) {
            $j->on('p.id', '=', 'l.listing_id')->on('p.agency_id', '=', 'l.agency_id');
        })->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.received_at', '>=', now()->subMonths(12))->whereNotNull('p.p24_suburb_id')
            ->groupBy('p.p24_suburb_id')->selectRaw('p.p24_suburb_id as sid, COUNT(*) as n')->get(), 'leads');

        $areas = array_flip($this->areas($agencyId));
        $hidden = array_flip($this->hidden($agencyId));
        foreach (array_keys($areas) as $id) {
            $by[$id] ??= [];
        }
        $names = DB::table('p24_suburbs')->whereIn('id', array_keys($by))->pluck('name', 'id');
        $out = [];
        foreach ($by as $id => $c) {
            $isArea = isset($areas[$id]);
            $isHidden = isset($hidden[$id]);
            $why = array_values(array_filter([
                $isArea ? 'area' : null, ($c['searches'] ?? 0) > 0 ? 'search' : null,
                ($c['stock'] ?? 0) > 0 ? 'stock' : null, ($c['claims'] ?? 0) > 0 ? 'claim' : null,
            ]));
            $out[] = [
                'suburb_id' => (int) $id, 'suburb' => (string) ($names[$id] ?? ('#' . $id)), 'searches' => $c['searches'] ?? 0,
                'adverts' => $c['adverts'] ?? 0, 'alerts' => $c['alerts'] ?? 0, 'stock' => $c['stock'] ?? 0, 'claims' => $c['claims'] ?? 0, 'leads' => $c['leads'] ?? 0,
                'total' => ($c['stock'] ?? 0) + ($c['claims'] ?? 0) + ($c['leads'] ?? 0),
                'why' => $why, 'is_area' => $isArea, 'hidden' => $isHidden,
                'qualifies' => $isArea || (! $isHidden && $why !== []),
            ];
        }
        $weight = fn (array $r) => $r['searches'] + $r['stock'] + $r['claims'];
        usort($out, fn ($a, $b) => [(int) $b['qualifies'], $weight($b), $a['suburb']] <=> [(int) $a['qualifies'], $weight($a), $b['suburb']]);

        return $out;
    }

    /**
     * The working set: suburb id => why (`area`, `search`, `stock`, `claim`).
     *
     * @return array<int,list<string>>
     */
    public function ids(int $agencyId): array
    {
        $out = [];
        foreach ($this->counts($agencyId) as $r) {
            if ($r['qualifies']) {
                $out[$r['suburb_id']] = $r['why'];
            }
        }

        return $out;
    }

    /**
     * Suburbs (our p24_suburbs.id => number of searches) the agency's searches named. A P24 results URL names a suburb in its path
     * (`/for-sale/<suburb>/<town>/<province>/<id>` - two names before the province) or in `s=` of an advanced search; one name is a TOWN, `/alias/` a region and
     * `cid=` towns: none of those names a suburb. A numeric or named `crawl_suburb` (cc3) counts too. PrivatePropertys ids are not mapped, so PP searches add nothing.
     *
     * @return array<int,int>
     */
    public function searchedSuburbs(int $agencyId): array
    {
        $byP24 = DB::table('p24_suburbs')->pluck('id', 'p24_id')->all();
        $found = [];
        $count = function (int $p24Id) use ($byP24, &$found) {
            if (isset($byP24[$p24Id])) {
                $found[(int) $byP24[$p24Id]] = ($found[(int) $byP24[$p24Id]] ?? 0) + 1;
            }
        };
        foreach (DB::table('prospecting_searches')->where('agency_id', $agencyId)->get(['search_url', 'portal_source', 'crawl_suburb']) as $r) {
            if (trim((string) $r->crawl_suburb) !== '') {
                $id = ctype_digit((string) $r->crawl_suburb) ? (int) $r->crawl_suburb : (int) (SuburbResolver::resolve((string) $r->crawl_suburb)['p24_suburb_id'] ?? 0);
                if ($id > 0) {
                    $found[$id] = ($found[$id] ?? 0) + 1;
                }
            }
            if ($r->portal_source !== 'p24' || ! $r->search_url) {
                continue;
            }
            $u = parse_url((string) $r->search_url);
            $segments = array_values(array_filter(explode('/', (string) ($u['path'] ?? '')), fn ($x) => $x !== ''));
            if (! str_contains((string) ($u['path'] ?? ''), '/alias/') && preg_match('~^[a-z-]*for-sale$~', $segments[0] ?? '')) {
                $names = [];
                foreach (array_slice($segments, 1) as $seg) {
                    if (ctype_digit($seg) || preg_match('~^p\d+$~', $seg) || in_array($seg, ['kwazulu-natal', 'gauteng', 'western-cape', 'eastern-cape', 'free-state', 'limpopo', 'mpumalanga', 'north-west', 'northern-cape'], true)) {
                        break;
                    }
                    $names[] = $seg;
                }
                $last = array_values(array_filter($segments, 'ctype_digit'));
                if (count($names) >= 2 && $last !== []) {
                    $count((int) end($last));
                }
            }
            parse_str((string) ($u['query'] ?? ''), $q);
            $sp = [];
            if (isset($q['sp'])) {
                parse_str((string) $q['sp'], $sp);
            }
            $list = $sp['s'] ?? ($q['s'] ?? null);
            foreach (is_string($list) && $list !== '' ? explode(',', $list) : [] as $x) {
                if (ctype_digit(trim($x))) {
                    $count((int) trim($x));
                }
            }
        }

        return $found;
    }

    /** @return list<int> the Areas ticked in the MIC Settings (our p24_suburbs.id) */
    public function areas(int $agencyId): array
    {
        return $this->setting($agencyId, 'areas', 'areas');
    }

    /** @return list<int> suburbs the agency chose "Hide this suburb" for (MicAgencySetting `hiddenSuburbs()` or the stored value `hidden_suburbs`) */
    public function hidden(int $agencyId): array
    {
        return $this->setting($agencyId, 'hiddenSuburbs', 'hidden_suburbs');
    }

    /** @return list<int> */
    private function setting(int $agencyId, string $method, string $key): array
    {
        try {
            if (class_exists('App\\Models\\MicAgencySetting')) {
                $s = \App\Models\MicAgencySetting::for($agencyId);
                $v = method_exists($s, $method) ? $s->$method() : (($s->values ?? [])[$key] ?? []);

                return is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v), fn ($n) => $n > 0))) : [];
            }
        } catch (\Throwable $e) {
            // settings unavailable
        }

        return [];
    }
}
