<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\User;
use App\Services\Prospecting\CrawlRecords;
use App\Services\Prospecting\MicWork\Contracts\PanelProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tile 11 "Market data last refreshed" (contract 11.3.F / 11.9): one row per suburb the agency works, oldest first, the viewer's own suburbs first,
 * with when each portal was last READ COMPLETELY (the crawl record: complete and unfiltered) and a link to start the refresh. `overdue` = either portal
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

        $suburbIds = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', 1)->whereNotNull('p24_suburb_id')
            ->distinct()->pluck('p24_suburb_id')->merge($reads->keys())->unique()->values();
        if ($suburbIds->isEmpty()) {
            return [];
        }
        $mine = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('captured_by_user_id', $user->id)->whereNotNull('p24_suburb_id')
            ->distinct()->pluck('p24_suburb_id')->flip();
        $scopeMine = ($filters['scope'] ?? 'all') === 'mine';

        $sub = DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')->leftJoin('p24_provinces as pr', 'pr.id', '=', 'c.p24_province_id')
            ->whereIn('s.id', $suburbIds->all())->whereNull('s.deleted_at')
            ->get(['s.id', 's.name', 's.slug', 's.p24_id', 'c.name as city', 'pr.name as province']);
        $pp = DB::table('pp_suburbs')->whereIn(DB::raw('LOWER(TRIM(name))'), $sub->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all())->get(['name', 'pp_suburb_id'])
            ->keyBy(fn ($r) => mb_strtolower(trim((string) $r->name)));

        $rows = [];
        foreach ($sub as $s) {
            if ($scopeMine && ! isset($mine[$s->id])) {
                continue;
            }
            $at = fn (string $portal) => optional(collect($reads[$s->id] ?? [])->firstWhere('portal', $portal))->finished_at;
            $p24 = $at('p24');
            $ppRead = $at('pp');
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
                'p24_url'     => $s->p24_id && $s->city ? 'https://www.property24.com/for-sale/' . ($s->slug ?: $slug($s->name)) . '/' . $slug($s->city) . '/' . ($slug($s->province) ?: 'kwazulu-natal') . '/' . $s->p24_id : null,
                'pp_url'      => $ppId ? 'https://www.privateproperty.co.za/for-sale/advanced-search?si=' . $ppId . '&sorttype=Default&sortorder=Ascending' : null,
                'mine'        => isset($mine[$s->id]),
            ];
        }
        // the viewer's suburbs first, then never read, then oldest read
        usort($rows, fn ($a, $b) => [$a['mine'] ? 0 : 1, $a['age_days'] === null ? 0 : 1, -($a['age_days'] ?? 0)] <=> [$b['mine'] ? 0 : 1, $b['age_days'] === null ? 0 : 1, -($b['age_days'] ?? 0)]);

        return $rows;
    }

    public function around(int $agencyId, User $user, string $triggerId): array
    {
        return [];
    }
}
