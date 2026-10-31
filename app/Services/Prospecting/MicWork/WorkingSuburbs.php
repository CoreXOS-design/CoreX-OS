<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Services\Address\SuburbResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which suburbs are OUR working suburbs, and the counts behind each (mic-work-tab.md 11.3.K, rewritten by Johan's ruling of 31 Oct 19:08:
 * "if we import stock then it should count as one of our suburbs - why would I want to import stock if I don't want to work that area?").
 *
 * A suburb is ours when the agency IMPORTED adverts there (the MIC extension capture or the portal email alerts, either portal), OR holds own stock or
 * a claim there, OR has it ticked as an Area in the MIC Settings - unless the agency chose "Hide this suburb" (a suburb imported by mistake). A ticked
 * Area stays even if hidden. The old minimum-count rule and "leads alone" are gone (leads are still counted, for information only).
 *
 * `counts()` is the single source for Settings > Areas "Suggested from your work"; `ids()` is the working set the panel and tile 11 use.
 */
final class WorkingSuburbs
{
    /**
     * @return list<array{suburb_id:int, suburb:string, adverts:int, alerts:int, stock:int, claims:int, leads:int, total:int, why:list<string>, qualifies:bool, is_area:bool, hidden:bool}>
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
        // imported: every advert the agency's extension captured, any portal, any age (a suburb it once imported is one it works)
        $add(DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('p24_suburb_id')
            ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id as sid, COUNT(*) as n')->get(), 'adverts');
        // imported by email alert (the alert rows carry the suburb as text)
        $alertIds = [];
        foreach (['p24_listings', 'pp_alert_listings'] as $table) {
            foreach (DB::table($table)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereRaw("TRIM(COALESCE(suburb,'')) <> ''")->groupBy('suburb')->selectRaw('suburb, COUNT(*) as n')->get() as $r) {
                $id = SuburbResolver::resolve((string) $r->suburb)['p24_suburb_id'];
                if ($id) {
                    $alertIds[(int) $id] = ($alertIds[(int) $id] ?? 0) + (int) $r->n;
                }
            }
        }
        foreach ($alertIds as $id => $n) {
            $by[$id]['alerts'] = $n;
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
                $isArea ? 'area' : null, ($c['adverts'] ?? 0) > 0 ? 'import' : null, ($c['alerts'] ?? 0) > 0 ? 'alert' : null,
                ($c['stock'] ?? 0) > 0 ? 'stock' : null, ($c['claims'] ?? 0) > 0 ? 'claim' : null,
            ]));
            $out[] = [
                'suburb_id' => (int) $id, 'suburb' => (string) ($names[$id] ?? ('#' . $id)),
                'adverts' => $c['adverts'] ?? 0, 'alerts' => $c['alerts'] ?? 0, 'stock' => $c['stock'] ?? 0, 'claims' => $c['claims'] ?? 0, 'leads' => $c['leads'] ?? 0,
                'total' => ($c['stock'] ?? 0) + ($c['claims'] ?? 0) + ($c['leads'] ?? 0),
                'why' => $why, 'is_area' => $isArea, 'hidden' => $isHidden,
                'qualifies' => $isArea || (! $isHidden && $why !== []),
            ];
        }
        usort($out, fn ($a, $b) => [$b['adverts'] + $b['alerts'] + $b['total'], $a['suburb']] <=> [$a['adverts'] + $a['alerts'] + $a['total'], $b['suburb']]);

        return $out;
    }

    /**
     * The working set: suburb id => why (`area`, `import`, `alert`, `stock`, `claim`).
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
