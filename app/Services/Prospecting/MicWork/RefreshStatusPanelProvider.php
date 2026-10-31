<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\User;
use App\Services\Prospecting\CrawlRecords;
use App\Services\Prospecting\P24AreaPageLinks;
use App\Services\Prospecting\MicWork\Contracts\PanelProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tile 11 "Market data last refreshed" (contract 11.3.F / 11.9): one row per suburb the agency works, oldest first, the viewer's own suburbs first,
 * with when each portal was last READ COMPLETELY (the crawl record: complete and unfiltered; until one exists, the newest advert sighting there, flagged `*_approx`) and a link to start the refresh. `overdue` = either portal
 * never read, or the older read is past the agency's refresh interval (default 7 days).
 */
final class RefreshStatusPanelProvider implements PanelProvider
{
    public function tile(): int
    {
        return 11;
    }

    public function rows(int $agencyId, User $user, array $filters): array
    {
        $interval = MicTileSettings::refreshIntervalDays($agencyId);
        $reads = app(CrawlRecords::class)->lastCrawlBySuburb($agencyId)->groupBy('suburb_id');

        // OUR working suburbs (stock, claims, leads, or listed as areas in MIC Settings) come first; every other suburb only when "show all" is on
        $working = $this->workingSuburbs($agencyId);
        $showAll = ! empty($filters['show_all']) || $working === [];
        $suburbIds = collect(array_keys($working));
        if ($showAll) {
            $suburbIds = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', 1)->whereNotNull('p24_suburb_id')
                ->distinct()->pluck('p24_suburb_id')->merge($reads->keys())->merge($suburbIds)->unique()->values();
        }
        if ($suburbIds->isEmpty()) {
            return [];
        }
        $mine = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('captured_by_user_id', $user->id)->whereNotNull('p24_suburb_id')
            ->distinct()->pluck('p24_suburb_id')->flip();
        $scopeMine = ($filters['scope'] ?? 'all') === 'mine';
        // Until real crawl records exist for a suburb + portal, the last read is APPROXIMATED from the newest time an advert there was seen on that portal
        // (every read of a suburb refreshes its adverts). A real, complete crawl replaces it. Marked approx in the row.
        // The last read of a suburb on a portal = the moment by which at least `share`% of its LIVE adverts there had been seen (the share-th most recent
        // last_seen), and only for suburbs with at least `min` live adverts: one advert touched by a back-fill or an alert is not a read of the suburb.
        $share = MicTileSettings::approxReadMinSharePct($agencyId) / 100;
        $minAdverts = MicTileSettings::approxReadMinAdverts($agencyId);
        $approx = collect(DB::select(
            'SELECT sid, portal, at FROM (SELECT l.p24_suburb_id AS sid, l.portal_source AS portal, l.last_seen_at AS at,
                    ROW_NUMBER() OVER (PARTITION BY l.p24_suburb_id, l.portal_source ORDER BY l.last_seen_at DESC) AS rn,
                    COUNT(*) OVER (PARTITION BY l.p24_suburb_id, l.portal_source) AS cnt
                 FROM prospecting_listings l WHERE l.agency_id = ? AND l.deleted_at IS NULL AND l.is_active = 1 AND l.p24_suburb_id IS NOT NULL) x
             WHERE cnt >= ? AND rn = CEIL(cnt * ?)',
            [$agencyId, $minAdverts, $share]
        ))->groupBy('sid')->map(fn ($g) => $g->pluck('at', 'portal'));

        $sub = DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')->leftJoin('p24_provinces as pr', 'pr.id', '=', 'c.p24_province_id')
            ->whereIn('s.id', $suburbIds->all())->whereNull('s.deleted_at')
            ->get(['s.id', 's.name', 's.slug', 's.p24_id', 'c.name as city', 'pr.name as province']);
        $pp = DB::table('pp_suburbs')->whereIn(DB::raw('LOWER(TRIM(name))'), $sub->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all())->get(['name', 'pp_suburb_id'])
            ->keyBy(fn ($r) => mb_strtolower(trim((string) $r->name)));

        // when each suburb's free P24 area pages were last read (global public facts, so no agency filter)
        $areaRead = DB::table('p24_suburb_trends')->whereIn('p24_suburb_id', $sub->pluck('p24_id')->filter()->all())->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id, MAX(read_on) as at')->pluck('at', 'p24_suburb_id');

        $rows = [];
        foreach ($sub as $s) {
            if ($scopeMine && ! isset($mine[$s->id])) {
                continue;
            }
            $real = fn (string $portal) => optional(collect($reads[$s->id] ?? [])->firstWhere('portal', $portal))->finished_at;
            $p24Real = $real('p24');
            $ppReal = $real('pp');
            $p24 = $p24Real ?: ($approx[$s->id]['p24'] ?? null);
            $ppRead = $ppReal ?: ($approx[$s->id]['pp'] ?? null);
            $known = collect([$p24, $ppRead])->filter()->map(fn ($d) => Carbon::parse($d));
            $age = $known->isEmpty() ? null : (int) $known->min()->diffInDays(now());
            $slug = fn (?string $v) => trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $v)), '-');
            $ppId = $pp[mb_strtolower(trim((string) $s->name))]->pp_suburb_id ?? null;
            $rows[] = [
                'suburb_id'   => (int) $s->id,
                'suburb'      => (string) $s->name,
                'p24_read_at' => $p24 ? Carbon::parse($p24)->toIso8601String() : null,
                'pp_read_at'  => $ppRead ? Carbon::parse($ppRead)->toIso8601String() : null,
                'age_days'    => $age,
                'overdue'     => ! $p24 || ! $ppRead || ($age !== null && $age > $interval),
                // newest first; one place builds every address (P24AreaPageLinks), tested against what the crawl record accepts
                'p24_url'     => $s->p24_id && $s->city ? P24AreaPageLinks::p24SearchUrl($s->slug ?: $slug($s->name), $slug($s->city), $slug($s->province) ?: 'kwazulu-natal', (int) $s->p24_id) : null,
                'pp_url'      => $ppId ? P24AreaPageLinks::ppSearchUrl((int) $ppId) : null,
                // "Read area pages": one click opens the trends page; the extension then reads agencies and streets by itself
                'area_url'    => $s->p24_id && $s->city ? P24AreaPageLinks::areaUrls($s->slug ?: $slug($s->name), $slug($s->city), $slug($s->province) ?: 'kwazulu-natal', (int) $s->p24_id)['trends'] : null,
                'area_read_at' => ($areaRead[(int) $s->p24_id] ?? null) ? Carbon::parse($areaRead[(int) $s->p24_id])->toDateString() : null,
                'mine'        => isset($mine[$s->id]),
                'working'     => isset($working[$s->id]),
                'working_why' => $working[$s->id] ?? [],
                'p24_approx'  => $p24 !== null && ! $p24Real,
                'pp_approx'   => $ppRead !== null && ! $ppReal,
                'approx_label' => ($p24 !== null && ! $p24Real) || ($ppRead !== null && ! $ppReal) ? 'approx (before crawl tracking)' : null,
            ];
        }
        // our working suburbs first, then the rest; inside each: never read first, then the oldest read
        usort($rows, fn ($a, $b) => [$a['working'] ? 0 : 1, $a['mine'] ? 0 : 1, $a['age_days'] === null ? 0 : 1, -($a['age_days'] ?? 0)] <=> [$b['working'] ? 0 : 1, $b['mine'] ? 0 : 1, $b['age_days'] === null ? 0 : 1, -($b['age_days'] ?? 0)]);

        return $rows;
    }

    /**
     * The numbers on the face of tile 11 (contract 11.3.I), over OUR working suburbs only: `count` = `oldest_age_days` (the oldest read, in days; null when
     * a working suburb was never read - see `never_read`), `older_than_days` = working suburbs never read or read longer ago than `interval_days`,
     * `interval_days` = Settings > Market data refresh "Every N days" (the SAME value the row `overdue` flag uses), `working_suburbs` = how many there are.
     * Approximate reads count, flagged in the rows.
     *
     * @return array{count:?int, reason:string, note:?string, oldest_age_days:?int, older_than_days:int, interval_days:int, never_read:int, working_suburbs:int}
     */
    public function summary(int $agencyId, User $user): array
    {
        $interval = MicTileSettings::refreshStaleDays($agencyId);
        $rows = collect($this->rows($agencyId, $user, ['show_all' => false]))->where('working', true)->values();
        $ages = $rows->pluck('age_days')->filter(fn ($a) => $a !== null);
        $never = $rows->filter(fn ($r) => $r['age_days'] === null)->count();
        $older = $rows->filter(fn ($r) => $r['age_days'] === null || $r['age_days'] > $interval)->count();
        $oldest = $ages->isEmpty() ? null : (int) $ages->max();

        return [
            'count' => $oldest, 'oldest_age_days' => $oldest, 'older_than_days' => $older, 'interval_days' => $interval, 'never_read' => $never, 'working_suburbs' => $rows->count(),
            'reason' => $rows->isEmpty() ? 'No working suburbs yet' : 'Oldest read ' . ($oldest === null ? 'never' : $oldest . ' days ago') . '; ' . $older . ' of ' . $rows->count() . ' working suburbs older than ' . $interval . ' days',
            'note' => $rows->contains(fn ($r) => $r['p24_approx'] || $r['pp_approx']) ? 'some reads are approximate (before crawl tracking)' : null,
        ];
    }

    /** @return array<int,list<string>> suburb id => why - see WorkingSuburbs */
    public function workingSuburbs(int $agencyId): array
    {
        return app(WorkingSuburbs::class)->ids($agencyId);
    }

    public function around(int $agencyId, User $user, string $triggerId): array
    {
        return [];
    }
}
