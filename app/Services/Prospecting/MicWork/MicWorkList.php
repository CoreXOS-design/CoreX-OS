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

    /** The ONE suburb formatter for the Work tab (list, panels): title case, never the raw capitals the portals send. */
    public static function titleSuburb(?string $name): string
    {
        return \Illuminate\Support\Str::title(mb_strtolower(trim((string) $name)));
    }

    /** "12 Foo Road, SHELLY BEACH" -> "12 Foo Road, Shelly Beach": the suburb after the last comma goes through the same formatter. */
    public static function tidyAddress(?string $address): string
    {
        $a = trim((string) $address);
        $at = strrpos($a, ', ');

        return $at === false ? $a : substr($a, 0, $at + 2) . self::titleSuburb(substr($a, $at + 2));
    }

    /** CMA / deeds addresses arrive in capitals ("938 FASCADALE"): title-case the whole line when it has no lowercase at all, else tidy the suburb only. */
    public static function tidyLine(?string $address): string
    {
        $a = trim((string) $address);

        return $a !== '' && $a === mb_strtoupper($a) ? \Illuminate\Support\Str::title(mb_strtolower($a)) : self::tidyAddress($a);
    }

    /**
     * Which pending review pairs raise the "Possible match" chip on a row: only pairs that say two files are probably the SAME property - a Property24
     * group, a same-agency twin, a same-address freehold. Flat / complex / unit / scheme pairs ("no unit number - may be a different unit"), own-stock
     * and capture pairs, advert clashes, map-point and unit-split reviews do not (they are noise on the worklist; they stay on the Possible matches page).
     * Interim until cc4 lists the kinds in 11.3 with an explicit unit/scheme flag: the same_address kind is told apart by its reason text.
     */
    public static function raisesPossibleMatch(?string $kind, string $reason): bool
    {
        if (in_array($kind, ['portal_group', 'twin'], true)) {
            return true;
        }

        return $kind === 'same_address' && ! preg_match('/flat|complex|unit|scheme/i', $reason);
    }

    /** @return list<string> */
    public static function sortKeys(): array
    {
        return array_merge(array_keys(self::SORTS), self::hasFileStats() ? ['buyers'] : [], self::hasScores() ? ['score'] : []);
    }

    /** Opportunity score once cc5's stored stats exist; until then newest activity first. */
    public static function defaultSort(): string
    {
        return self::hasScores() ? 'score' : 'last_seen';
    }

    /** True once cc5's score has actually been computed for this agency (the stats table can exist with every score still empty). */
    public static function hasScores(): bool
    {
        if (! self::hasFileStats()) {
            return false;
        }
        $agencyId = (int) (auth()->user()?->effectiveAgencyId() ?? 0);

        return \Illuminate\Support\Facades\Cache::remember("mic-work:has-scores:$agencyId", 300, fn () => DB::table('mic_file_stats')->where('agency_id', $agencyId)->whereNotNull('score')->exists());
    }

    public static function hasFileStats(): bool
    {
        static $has = null;

        return $has ??= Schema::hasTable('mic_file_stats');
    }

    /**
     * @param  Builder<ProspectingListing>  $filtered  the fully filtered, agency-scoped advert query (no order, no page)
     */
    public function page(Builder $filtered, int $agencyId, string $sort, string $dir, int $page, bool $includeOwnStock = false): LengthAwarePaginator
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        if (! $includeOwnStock) {
            // Our own stock is not prospecting: files our agency holds are out unless the manager asks for them (mic-work-tab 6.7).
            $filtered = (clone $filtered)->whereNotExists(function ($w) use ($agencyId) {
                $w->select(DB::raw(1))->from('tracked_property_agency_work as ow')->where('ow.agency_id', $agencyId)->where('ow.is_own_stock', 1)
                    ->whereColumn('ow.tracked_property_id', 'prospecting_listings.tracked_property_id');
            });
        }
        $base = (clone $filtered)->toBase()->reorder()->select([])
            ->selectRaw('COALESCE(prospecting_listings.tracked_property_id, -prospecting_listings.id) AS fkey')
            ->selectRaw('MAX(last_seen_at) AS last_seen, MIN(COALESCE(listed_date, first_seen_at)) AS since, COUNT(*) AS n')
            ->groupBy('fkey');

        // one flat DISTINCT count over the same filtered set - cheaper than counting the grouped subquery. Total and the page's keys are cached for a
        // few minutes per exact query (the SQL + its bindings carry the agency and the viewer's own/branch scope), never in tests.
        $remember = function (string $part, int $ttl, \Closure $fn) use ($filtered, $sort, $dir, $page) {
            if (app()->runningUnitTests()) {
                return $fn();
            }
            $q = (clone $filtered)->toBase();

            return \Illuminate\Support\Facades\Cache::remember('mic-work:' . $part . ':' . md5($q->toSql() . json_encode($q->getBindings()) . "|$sort|$dir|$page"), $ttl, $fn);
        };
        $total = (int) $remember('total', 300, fn () => (clone $filtered)->toBase()->reorder()->selectRaw('COUNT(DISTINCT COALESCE(prospecting_listings.tracked_property_id, -prospecting_listings.id)) AS c')->value('c'));

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
        $keys = $remember('keys', 120, fn () => $q->orderBy('fkey')->forPage($page, self::PER_PAGE)->pluck('fkey')->map(fn ($k) => (int) $k)->all());

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
                'portal_status', 'advertiser_type', 'is_reduced', 'canonical_ref', 'p24_suburb_id'])
            ->groupBy(fn ($l) => $l->tracked_property_id ?: -$l->id);
        $advertIds = $adverts->flatten()->pluck('id')->all();

        $tps = $tpIds ? TrackedProperty::withoutGlobalScopes()->where('agency_id', $agencyId)->whereIn('id', $tpIds)
            ->get(['id', 'street_number', 'street_name', 'unit_number', 'complex_name', 'suburb', 'p24_suburb_id', 'property_type', 'bedrooms', 'bathrooms', 'garages',
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

        $subIds = $adverts->flatten()->pluck('p24_suburb_id')->merge($tps->pluck('p24_suburb_id'))->filter()->unique()->values()->all();
        $subNames = $subIds ? DB::table('p24_suburbs')->whereIn('id', $subIds)->pluck('name', 'id') : collect();

        // P24 / PP e-mail alerts of this agency for the page's files: by link to the file, or by the advert's canonical ref.
        $refKey = [];
        foreach ($adverts as $fk => $list) {
            foreach ($list as $a) {
                if ($a->canonical_ref) {
                    $refKey[$a->canonical_ref] = $fk;
                }
            }
        }
        $alertsByKey = [];
        foreach (\App\Services\Prospecting\AlertPropertyLinker::PORTALS as $portal => $cfg) {
            $cfg['model']::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agencyId)
                ->where(fn ($q) => $q->whereIn('tracked_property_id', $tpIds ?: [0])->orWhereIn('canonical_ref', array_keys($refKey) ?: ['-']))
                ->with(['priceChanges' => fn ($q) => $q->orderBy('change_date')])->get()
                ->each(function ($al) use (&$alertsByKey, $refKey, $portal, $cfg) {
                    $fk = $al->tracked_property_id ?: ($refKey[$al->canonical_ref] ?? null);
                    if ($fk === null) {
                        return;
                    }
                    $alertsByKey[$fk][] = [
                        'portal' => $portal === 'p24' ? 'P24' : 'PP', 'number' => (string) $al->{$cfg['number']},
                        'first' => $al->first_seen_date?->format('j M Y'), 'last' => $al->last_seen_date?->format('j M Y'),
                        'price' => (int) $al->asking_price, 'original' => $al->original_price !== null ? (int) $al->original_price : null,
                        'changes' => $al->priceChanges->map(fn ($c) => ['date' => $c->change_date->format('j M Y'), 'old' => (int) $c->old_price, 'new' => (int) $c->new_price])->all(),
                    ];
                });
        }

        // Pending review pairs ("Possible match" chip): this agency's pending identity_possible decisions that touch a page file, either side.
        $pairs = [];
        if ($tpIds) {
            $pend = DB::table('property_match_decisions as d')->where('d.agency_id', $agencyId)->where('d.subject_type', \App\Services\Prospecting\PropertyIdentityResolver::DECISION_SUBJECT)
                ->whereNull('d.deleted_at')->whereNull('d.confirmed_at')->whereNull('d.rejected_at')
                ->where(fn ($q) => $q->whereIn('d.matched_id', $tpIds)->orWhereRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(d.incoming_facts, '$.incoming_tracked_property_id')) AS UNSIGNED) IN (" . implode(',', array_map('intval', $tpIds)) . ')'))
                ->get(['d.id', 'd.matched_id', 'd.incoming_facts', 'd.reason']);
            foreach ($pend as $d) {
                if (! self::raisesPossibleMatch(json_decode((string) $d->incoming_facts, true)['kind'] ?? null, (string) $d->reason)) {
                    continue;
                }
                $inc = (int) (json_decode((string) $d->incoming_facts, true)['incoming_tracked_property_id'] ?? 0);
                foreach ([(int) $d->matched_id, $inc] as $side) {
                    if ($side && in_array($side, $tpIds, true)) {
                        $pairs[$side][] = (int) $d->id;
                    }
                }
            }
        }

        // Top matched buyers per file (own agency, strong enough to count), for the expand.
        $buyerNames = [];
        if ($advertIds) {
            $advKey = [];
            foreach ($adverts as $fk => $list) {
                foreach ($list as $a) {
                    $advKey[$a->id] = $fk;
                }
            }
            DB::table('prospecting_buyer_matches as m')->join('contacts as c', 'c.id', '=', 'm.contact_id')
                ->where('m.agency_id', $agencyId)->where('c.agency_id', $agencyId)->whereNull('c.deleted_at')->whereNull('m.dismissed_at')->where('m.score', '>=', 50)
                ->whereIn('m.prospecting_listing_id', $advertIds)->orderByDesc('m.score')->limit(600)
                ->get(['m.prospecting_listing_id', 'm.contact_id', 'm.score', 'c.first_name', 'c.last_name'])
                ->each(function ($m) use (&$buyerNames, $advKey) {
                    $fk = $advKey[$m->prospecting_listing_id] ?? null;
                    if ($fk !== null && ! isset($buyerNames[$fk][$m->contact_id]) && count($buyerNames[$fk] ?? []) < 3) {
                        $buyerNames[$fk][$m->contact_id] = ['id' => (int) $m->contact_id, 'name' => trim($m->first_name . ' ' . $m->last_name) ?: 'Buyer #' . $m->contact_id, 'score' => (int) $m->score];
                    }
                });
        }

        // Our history: claims on the file's adverts, and our own past/present mandates on the file.
        $claims = $advertIds ? DB::table('prospecting_claims as cl')->leftJoin('users as u', 'u.id', '=', 'cl.user_id')->where('cl.agency_id', $agencyId)->whereNull('cl.deleted_at')
            ->whereIn('cl.prospecting_listing_id', $advertIds)->orderByDesc('cl.claimed_at')->limit(300)->get(['cl.prospecting_listing_id', 'cl.status', 'cl.claimed_at', 'cl.released_at', 'cl.release_reason', 'u.name as who']) : collect();
        $claimsByKey = [];
        foreach ($claims as $c) {
            $claimsByKey[$advKey[$c->prospecting_listing_id] ?? 0][] = ['who' => $c->who, 'status' => $c->status, 'at' => $c->claimed_at ? \Carbon\Carbon::parse($c->claimed_at)->format('j M Y') : null, 'released' => $c->released_at ? \Carbon\Carbon::parse($c->released_at)->format('j M Y') : null, 'reason' => $c->release_reason];
        }
        $mandates = $tpIds ? DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $tpIds)
            ->orderByDesc('id')->get(['tracked_property_id', 'status', 'mandate_type', 'expiry_date', 'price'])->groupBy('tracked_property_id') : collect();

        // cc5's market context per file (spec 11.1b, FileContext::forFiles) - nearby sales and the suburb line - when that service exists.
        $context = [];
        $ctxClass = 'App\\Services\\MarketIntelligence\\Context\\FileContext';
        if ($tpIds && class_exists($ctxClass) && method_exists($ctxClass, 'forFiles')) {
            try {
                $context = app($ctxClass)->forFiles($agencyId, $tpIds);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('MIC Work list: MarketContext failed', ['error' => $e->getMessage()]);
            }
        }

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

            // Price now and the move come from ONE advert: the lead advert on the file (the live one seen last). Its own history only.
            $leadChanges = $this->netChanges($history->get($lead->id, collect())); // R 0 (POA) dropped, same-day flip-flops netted
            $priceNow = (int) ($lead->price ?: 0);
            $firstPrice = $leadChanges ? (int) $leadChanges[0]['old'] : null;
            $move = ($firstPrice && $priceNow && $priceNow !== $firstPrice) ? $priceNow - $firstPrice : null;
            $cuts = $move !== null && $move < 0 ? count(array_filter($leadChanges, fn ($c) => $c['new'] < $c['old'])) : 0;

            $addr = $tp && trim((string) $tp->street_name) !== ''
                ? trim(implode(' ', array_filter([in_array(trim((string) $tp->street_number), ['', '0'], true) ? null : trim((string) $tp->street_number), trim((string) $tp->street_name)])))
                : ($list->first(fn ($a) => trim((string) $a->address) !== '' && $a->address !== ProspectingListing::ADDRESS_PLACEHOLDER)?->address);
            $pid = $tp?->p24_suburb_id ?: $lead->p24_suburb_id;
            $suburb = $pid && $subNames->has($pid) ? $subNames[$pid] : self::titleSuburb($tp?->suburb ?: $lead->suburb);
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
            if ($dom['days'] !== null && $dom['days'] < 7) {
                $dom['label'] = 'new';
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
                'alert' => $srcRows->contains(fn ($s) => $s['alert']) || ! empty($alertsByKey[$key]),
                'alerts' => $alertsByKey[$key] ?? [],
                'pair_ids' => $pairs[$key] ?? [],
                'buyer_names' => array_values($buyerNames[$key] ?? []),
                'claims' => $this->collapseClaims($claimsByKey[$key] ?? []),
                'mandates' => ($mandates->get($key) ?? collect())->map(fn ($m) => ['status' => $m->status, 'type' => $m->mandate_type, 'expiry' => $m->expiry_date ? \Carbon\Carbon::parse($m->expiry_date)->format('j M Y') : null, 'price' => $m->price ? (int) $m->price : null])->all(),
                'context' => $context[$key] ?? null,
                'advert_count' => $live->count(),
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
                'buyers_mid' => $st->buyers_mid ?? null,
                'claimed_by' => $w->claimed_by ?? null,
                'work_status' => $w->prospecting_status ?? null,
                'work_reason' => $w->prospecting_reason ?? null,
                'adverts' => $list->map(fn ($a) => [
                    'portal' => strtoupper($a->portal_source), 'owner' => $a->advertiser_type === 'owner', 'ref' => $a->portal_ref, 'url' => $a->portal_url, 'agency' => $a->agency_name, 'agent' => $a->agent_name,
                    'price' => $a->price, 'listed' => $a->listed_date?->format('j M Y'), 'seen' => $a->first_seen_at?->format('j M Y'), 'active' => (bool) $a->is_active,
                    'status' => $a->portal_status,
                    'changes' => $this->netChanges($history->get($a->id, collect())),
                ])->all(),
                'deeds_info' => $tp && $tp->deeds_captured_at ? ['registered' => $tp->deeds_registered_date ? \Carbon\Carbon::parse($tp->deeds_registered_date)->format('j M Y') : null, 'price' => $tp->last_known_sold_price, 'bond' => $tp->bond_amount] : null,
            ];
        }

        return $out;
    }

    /**
     * The same person claiming the same advert the same day with the same outcome is one line with a count (a file's adverts each carry their own claim).
     *
     * @param  list<array<string, mixed>>  $claims
     * @return list<array<string, mixed>>
     */
    private function collapseClaims(array $claims): array
    {
        $out = [];
        foreach ($claims as $c) {
            $k = ($c['who'] ?? '') . '|' . ($c['status'] ?? '') . '|' . ($c['at'] ?? '') . '|' . ($c['released'] ?? '') . '|' . ($c['reason'] ?? '');
            if (isset($out[$k])) {
                $out[$k]['count']++;
            } else {
                $out[$k] = $c + ['count' => 1];
            }
        }

        return array_slice(array_values($out), 0, 6);
    }

    /**
     * A price history as the REAL changes: R 0 (price on application) rows are dropped, and a run of changes on the same day is netted into one line
     * (first old price -> last new price) - and vanishes when the day ends where it began (990k -> 1.05m -> 990k is not a change).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows  prospecting_price_history rows, oldest first
     * @return list<array{date:string, old:int, new:int}>
     */
    private function netChanges($rows): array
    {
        $days = [];
        foreach ($rows as $h) {
            if ((float) $h->old_price <= 0 || (float) $h->new_price <= 0) {
                continue;
            }
            $day = \Carbon\Carbon::parse($h->changed_at)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day]['new'] = (int) $h->new_price;
            } else {
                $days[$day] = ['day' => $day, 'old' => (int) $h->old_price, 'new' => (int) $h->new_price];
            }
        }
        $out = [];
        foreach ($days as $d) {
            if ($d['old'] !== $d['new']) {
                $out[] = ['date' => \Carbon\Carbon::parse($d['day'])->format('j M Y'), 'old' => $d['old'], 'new' => $d['new']];
            }
        }

        return $out;
    }
}
