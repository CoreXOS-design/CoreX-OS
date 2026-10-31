<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertySourceFacts;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Work tab list (mic-work-tab.md 2.1 D): ONE ROW PER PROPERTY FILE. A file's adverts (P24 + PP, any agency) are grouped under its
 * tracked property; an advert with no file stands as its own one-advert row (key = -advert id). The caller hands over the SAME filtered
 * ProspectingListing query the old per-advert list used, so search, filters, tiles and agency/branch/own scoping all apply unchanged; this class
 * only groups, sorts and pages it. Two steps, no per-row queries: (1) the page's file keys by SQL group, (2) one batched read per column source.
 */
final class MicWorkList
{
    public const PER_PAGE = 50;

    /** sort key => SQL over the grouped adverts. Dir "desc" = the natural "most first" order for the column. */
    private const SORTS = [
        'last_seen' => 'MAX(last_seen_at)',
        'time' => 'MIN(COALESCE(listed_date, first_seen_at))', // oldest first when "most first"
        'price' => 'MAX(price)',
        'suburb' => 'MIN(suburb)',
        'address' => 'MIN(COALESCE((SELECT street_name FROM tracked_properties tp0 WHERE tp0.id = prospecting_listings.tracked_property_id), address, suburb))',
    ];

    public function __construct(private readonly PropertySourceFacts $sourceFacts) {}

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_merge(array_keys(self::SORTS), self::hasFileStats() ? ['score', 'buyers'] : []);
    }

    /** Opportunity score once cc5's stored stats exist; until then newest activity first. */
    public static function defaultSort(): string
    {
        return self::hasFileStats() ? 'score' : 'last_seen';
    }

    public static function hasFileStats(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('mic_file_stats');
    }

    /**
     * @param  Builder<ProspectingListing>  $filtered  the fully filtered, agency-scoped advert query (no order, no page)
     */
    public function page(Builder $filtered, int $agencyId, string $sort, string $dir, int $page): LengthAwarePaginator
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $base = (clone $filtered)->toBase()->reorder()->select([])
            ->selectRaw('COALESCE(prospecting_listings.tracked_property_id, -prospecting_listings.id) AS fkey')
            ->selectRaw('MAX(last_seen_at) AS last_seen, MIN(COALESCE(listed_date, first_seen_at)) AS since, COUNT(*) AS n')
            ->groupBy('fkey');

        $total = (int) DB::query()->fromSub((clone $base), 'g')->count();

        $q = clone $base;
        if (in_array($sort, ['score', 'buyers'], true) && self::hasFileStats()) {
            $col = $sort === 'score' ? 'score' : 'buyers_strong';
            $q->selectRaw("MAX((SELECT s.$col FROM mic_file_stats s WHERE s.agency_id = prospecting_listings.agency_id AND s.tracked_property_id = prospecting_listings.tracked_property_id)) AS ord")
                ->orderBy('ord', $dir);
        } else {
            $expr = self::SORTS[$sort] ?? self::SORTS['last_seen'];
            $q->selectRaw("$expr AS ord");
            // "time" most-first = the longest on the market = the OLDEST date first.
            $q->orderBy('ord', $sort === 'time' ? ($dir === 'desc' ? 'asc' : 'desc') : $dir);
        }
        $keys = $q->orderBy('fkey')->forPage($page, self::PER_PAGE)->pluck('fkey')->map(fn ($k) => (int) $k)->all();

        $rows = $keys ? $this->rows($keys, $agencyId) : [];

        return new Paginator($rows, $total, self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }

    /**
     * @param  list<int>  $keys  tracked property ids (positive) or negated advert ids (single-advert rows)
     * @return list<array<string, mixed>>
     */
    private function rows(array $keys, int $agencyId): array
    {
        $tpIds = array_values(array_filter($keys, fn ($k) => $k > 0));
        $loneIds = array_values(array_map(fn ($k) => -$k, array_filter($keys, fn ($k) => $k < 0)));

        $adverts = ProspectingListing::query()->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereIn('tracked_property_id', $tpIds ?: [0])->orWhereIn('id', $loneIds ?: [0]))
            ->orderByDesc('is_active')->orderByDesc('last_seen_at')
            ->get(['id', 'tracked_property_id', 'portal_source', 'portal_ref', 'portal_url', 'address', 'suburb', 'price', 'bedrooms', 'bathrooms', 'garages',
                'property_size_m2', 'erf_size_m2', 'property_type', 'agent_name', 'agency_name', 'first_seen_at', 'last_seen_at', 'listed_date', 'is_active',
                'portal_status', 'advertiser_type', 'is_reduced', 'canonical_ref'])
            ->groupBy(fn ($l) => $l->tracked_property_id ?: -$l->id);
        $advertIds = $adverts->flatten()->pluck('id')->all();

        $tps = $tpIds ? TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereIn('id', $tpIds)
            ->get(['id', 'street_number', 'street_name', 'unit_number', 'complex_name', 'suburb', 'property_type', 'bedrooms', 'bathrooms', 'garages',
                'floor_size_m2', 'erf_size_m2', 'deeds_captured_at', 'deeds_registered_date', 'last_known_sold_price', 'last_known_sold_date', 'bond_amount'])->keyBy('id') : collect();

        $sources = $this->sourceFacts->forListings($agencyId, $adverts->flatten());

        $history = $advertIds ? DB::table('prospecting_price_history')->whereIn('prospecting_listing_id', $advertIds)
            ->orderBy('changed_at')->get(['prospecting_listing_id', 'old_price', 'new_price', 'changed_at'])->groupBy('prospecting_listing_id') : collect();

        $deeds = $tpIds ? DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('source_type', 'deeds_capture')->whereNull('deleted_at')
            ->whereIn('tracked_property_id', $tpIds)->pluck('tracked_property_id')->flip() : collect();

        $work = $tpIds ? DB::table('tracked_property_agency_work as w')->leftJoin('users as u', 'u.id', '=', 'w.claimed_by_user_id')
            ->where('w.agency_id', $agencyId)->whereIn('w.tracked_property_id', $tpIds)
            ->get(['w.tracked_property_id', 'w.prospecting_status', 'w.prospecting_reason', 'w.is_own_stock', 'w.claimed_by_user_id', 'u.name as claimed_by'])->keyBy('tracked_property_id') : collect();

        $buyers = $advertIds ? DB::table('prospecting_buyer_matches')->where('agency_id', $agencyId)->whereIn('prospecting_listing_id', $advertIds)
            ->whereNull('dismissed_at')->where('score', '>=', 50)->groupBy('prospecting_listing_id')
            ->selectRaw('prospecting_listing_id, COUNT(DISTINCT contact_id) c')->pluck('c', 'prospecting_listing_id') : collect();

        $stats = ($tpIds && self::hasFileStats()) ? DB::table('mic_file_stats')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $tpIds)->get()->keyBy('tracked_property_id') : collect();

        $out = [];
        foreach ($keys as $key) {
            $list = $adverts->get($key, collect());
            if ($list->isEmpty()) {
                continue;
            }
            $active = $list->where('is_active', true);
            $live = $active->isNotEmpty() ? $active : $list;
            $tp = $key > 0 ? $tps->get($key) : null;
            $lead = $live->first();

            // price now + move since first seen (history of every advert on the file)
            $prices = [];
            $cuts = 0;
            $firstPrice = null;
            foreach ($list as $a) {
                foreach ($history->get($a->id, collect()) as $h) {
                    if ((float) $h->new_price < (float) $h->old_price) {
                        $cuts++;
                    }
                    $firstPrice = $firstPrice ?? (float) $h->old_price;
                }
            }
            $priceNow = (int) ($lead->price ?: 0);
            $move = ($firstPrice && $priceNow && $priceNow != (int) $firstPrice) ? $priceNow - (int) $firstPrice : null;

            $addr = $tp && trim((string) $tp->street_name) !== ''
                ? trim(implode(' ', array_filter([in_array(trim((string) $tp->street_number), ['', '0'], true) ? null : trim((string) $tp->street_number), trim((string) $tp->street_name)])))
                : ($list->first(fn ($a) => trim((string) $a->address) !== '' && $a->address !== ProspectingListing::ADDRESS_PLACEHOLDER)?->address);
            $suburb = $tp?->suburb ?: $lead->suburb;
            $type = $tp?->property_type ?: $lead->property_type;
            $beds = $tp?->bedrooms ?? $list->max('bedrooms');
            $baths = $tp?->bathrooms ?? $list->max('bathrooms');
            $garages = $tp?->garages ?? $list->max('garages');
            $erf = $tp?->erf_size_m2 ?: $list->max('erf_size_m2');
            $floor = $tp?->floor_size_m2 ?: $list->max('property_size_m2');
            if (! $addr) {
                $addr = trim(implode(' · ', array_filter([$suburb, $type, $tp?->complex_name ? $tp->complex_name . ($erf ? ' ' . number_format((float) $erf) . ' m²' : '') : null]))) ?: 'Address not known yet';
            }

            // time on market: portal listed date is exact; else first seen is a MINIMUM ("N+") until a complete crawl is on record (cc5 DomEstimator / mic_file_stats).
            $st = $stats->get($key);
            $listed = $list->pluck('listed_date')->filter()->min();
            $first = $list->pluck('first_seen_at')->filter()->min();
            if ($st && $st->dom_days !== null) {
                $dom = ['days' => (int) $st->dom_days, 'label' => match ($st->dom_mode) { 'listed' => $st->dom_days, 'approx' => '~' . $st->dom_days, default => $st->dom_days . '+' }];
            } elseif ($listed) {
                $d = (int) $listed->diffInDays(now());
                $dom = ['days' => $d, 'label' => (string) $d];
            } elseif ($first) {
                $d = (int) $first->diffInDays(now());
                $dom = ['days' => $d, 'label' => $d . '+'];
            } else {
                $dom = ['days' => null, 'label' => '-'];
            }

            $agencies = $live->pluck('agency_name')->map(fn ($n) => trim((string) $n))->filter(fn ($n) => $n !== '' && strcasecmp($n, 'Sale') !== 0)->unique()->values();
            $owner = $live->contains(fn ($a) => $a->advertiser_type === 'owner');
            $statuses = $list->pluck('portal_status')->filter()->unique();
            $flags = [];
            if ($live->contains(fn ($a) => $a->portal_status === 'under_offer')) { $flags[] = 'Under offer'; }
            if ($statuses->contains('sold')) { $flags[] = 'Sold'; }
            if ($active->isEmpty()) { $flags[] = 'Gone'; }
            if ($cuts > 0 || $live->contains(fn ($a) => $a->is_reduced)) { $flags[] = 'Reduced'; }
            if ($st && $st->relisted) { $flags[] = 'Re-listed'; }

            $srcRows = $list->map(fn ($a) => $sources[(int) $a->id] ?? null)->filter();
            $w = $work->get($key);
            $out[] = [
                'key' => $key,
                'tp_id' => $key > 0 ? $key : null,
                'score' => $st && $st->score !== null ? (float) $st->score : null,
                'reasons' => $st ? array_values(array_filter([$st->reason_1 ?? null, $st->reason_2 ?? null])) : [],
                'p24' => $list->contains('portal_source', 'p24'),
                'pp' => $list->contains('portal_source', 'pp'),
                'alert' => $srcRows->contains(fn ($s) => $s['alert']),
                'own_stock' => (bool) ($w->is_own_stock ?? false),
                'deeds' => $deeds->has($key),
                'owner' => $owner,
                'address' => $addr,
                'suburb' => $suburb,
                'facts' => ['type' => $type, 'beds' => $beds, 'baths' => $baths, 'garages' => $garages, 'floor' => $floor, 'erf' => $erf],
                'price' => $priceNow ?: null,
                'move' => $move,
                'cuts' => $cuts,
                'agencies' => $agencies->all(),
                'dom' => $dom,
                'last_seen' => $list->pluck('last_seen_at')->filter()->max(),
                'flags' => $flags,
                'buyers' => (int) $list->sum(fn ($a) => (int) ($buyers[$a->id] ?? 0)),
                'buyers_strong' => $st->buyers_strong ?? null,
                'claimed_by' => $w->claimed_by ?? null,
                'work_status' => $w->prospecting_status ?? null,
                'work_reason' => $w->prospecting_reason ?? null,
                'adverts' => $list->map(fn ($a) => [
                    'portal' => strtoupper($a->portal_source), 'ref' => $a->portal_ref, 'url' => $a->portal_url, 'agency' => $a->agency_name, 'agent' => $a->agent_name,
                    'price' => $a->price, 'listed' => $a->listed_date?->format('j M Y'), 'seen' => $a->first_seen_at?->format('j M Y'), 'active' => (bool) $a->is_active,
                    'status' => $a->portal_status,
                    'changes' => $history->get($a->id, collect())->map(fn ($h) => ['date' => \Carbon\Carbon::parse($h->changed_at)->format('j M Y'), 'old' => (int) $h->old_price, 'new' => (int) $h->new_price])->all(),
                ])->all(),
                'deeds_info' => $tp && $tp->deeds_captured_at ? ['registered' => $tp->deeds_registered_date ? \Carbon\Carbon::parse($tp->deeds_registered_date)->format('j M Y') : null, 'price' => $tp->last_known_sold_price, 'bond' => $tp->bond_amount] : null,
            ];
        }

        return $out;
    }
}
