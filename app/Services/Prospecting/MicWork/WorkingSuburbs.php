<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 4.9 (mic-work-tab.md 11.3.K): which suburbs are OUR working suburbs, and the counts behind each.
 *
 * Rule: if the agency has ticked Areas in the MIC Settings, those are its working suburbs. With no Areas ticked, a suburb counts only when it has at
 * least `workingSuburbMinCount` (default 3) of OUR stock + claims + portal leads, where stock = own listings there now, claims = properties claimed in the
 * last 12 months, leads = portal leads received on our listings in the last 12 months. One listing no longer makes a whole suburb "ours".
 *
 * `counts()` is the one method for Settings > Areas "Suggested from your work": every suburb with any work, biggest first, with the three counts, whether
 * it qualifies and whether it is already an area. Everything is bound to the agency.
 */
final class WorkingSuburbs
{
    /**
     * @return list<array{suburb_id:int, suburb:string, stock:int, claims:int, leads:int, total:int, qualifies:bool, is_area:bool}>
     */
    public function counts(int $agencyId): array
    {
        $min = MicTileSettings::workingSuburbMinCount($agencyId);
        $since = now()->subMonths(12);
        $by = [];
        $add = function ($rows, string $key) use (&$by) {
            foreach ($rows as $r) {
                $by[(int) $r->sid][$key] = (int) $r->n;
            }
        };
        if (Schema::hasTable('mic_file_stats')) {
            $add(DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('own_stock', 1)->whereNotNull('p24_suburb_id')
                ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id as sid, COUNT(*) as n')->get(), 'stock');
        }
        $add(DB::table('tracked_property_agency_work as w')->join('tracked_properties as t', 't.id', '=', 'w.tracked_property_id')
            ->where('w.agency_id', $agencyId)->whereNull('w.deleted_at')->whereNotNull('w.claimed_by_user_id')->where('w.claimed_at', '>=', $since)->whereNotNull('t.p24_suburb_id')
            ->groupBy('t.p24_suburb_id')->selectRaw('t.p24_suburb_id as sid, COUNT(*) as n')->get(), 'claims');
        $add(DB::table('portal_leads as l')->join('properties as p', function ($j) {
            $j->on('p.id', '=', 'l.listing_id')->on('p.agency_id', '=', 'l.agency_id');
        })->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.received_at', '>=', $since)->whereNotNull('p.p24_suburb_id')
            ->groupBy('p.p24_suburb_id')->selectRaw('p.p24_suburb_id as sid, COUNT(*) as n')->get(), 'leads');

        $areas = array_flip($this->areas($agencyId));
        foreach (array_keys($areas) as $id) {
            $by[$id] ??= [];
        }
        $names = DB::table('p24_suburbs')->whereIn('id', array_keys($by))->pluck('name', 'id');
        $out = [];
        foreach ($by as $id => $c) {
            $total = ($c['stock'] ?? 0) + ($c['claims'] ?? 0) + ($c['leads'] ?? 0);
            $out[] = ['suburb_id' => (int) $id, 'suburb' => (string) ($names[$id] ?? ('#' . $id)), 'stock' => $c['stock'] ?? 0, 'claims' => $c['claims'] ?? 0, 'leads' => $c['leads'] ?? 0,
                'total' => $total, 'qualifies' => $total >= $min, 'is_area' => isset($areas[$id])];
        }
        usort($out, fn ($a, $b) => [$b['total'], $a['suburb']] <=> [$a['total'], $b['suburb']]);

        return $out;
    }

    /**
     * The working set: suburb id => why (`area`, or any of `stock`, `claim`, `lead`).
     *
     * @return array<int,list<string>>
     */
    public function ids(int $agencyId): array
    {
        $areas = $this->areas($agencyId);
        $out = [];
        if ($areas !== []) {
            foreach ($areas as $id) {
                $out[$id] = ['area'];
            }

            return $out;
        }
        foreach ($this->counts($agencyId) as $r) {
            if ($r['qualifies']) {
                $out[$r['suburb_id']] = array_values(array_filter([$r['stock'] > 0 ? 'stock' : null, $r['claims'] > 0 ? 'claim' : null, $r['leads'] > 0 ? 'lead' : null]));
            }
        }

        return $out;
    }

    /** @return list<int> the Areas ticked in the MIC Settings (our p24_suburbs.id) */
    public function areas(int $agencyId): array
    {
        try {
            if (class_exists('App\\Models\\MicAgencySetting')) {
                return \App\Models\MicAgencySetting::for($agencyId)->areas();
            }
        } catch (\Throwable $e) {
            // settings unavailable
        }

        return [];
    }
}
