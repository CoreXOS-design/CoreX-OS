<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AT-528 (mic-work-tab.md 13.2 / 13.5): the Adverts view's server-side filters, applied to the SAME agency-scoped ProspectingListing query the Work
 * screen already builds (so agency, branch and own scoping are never bypassed: this only narrows it further).
 *
 *  suburbs[]  our p24_suburbs.id (or a suburb name)        type[]/type  property type            price_min / price_max     beds (minimum bedrooms)
 *  show_status  active (default) | all  - all adds the UNDER OFFER and SOLD adverts to the on-market ones (withdrawn adverts stay out)
 *  owner_only  1  - advertised by the owner                sole_mandate_only  1  - sole-mandate wording (agency words) or a P24 alert flagged mandated
 *  claimed     any | unclaimed | mine | others  - on the property file's claim
 *
 * Every window, word list and default comes from the agency's MIC settings; nothing here is a constant of one agency.
 */
final class MicAdvertFilters
{
    /** the query-string keys this class owns (the per-user memory stores exactly these) */
    public const KEYS = ['suburbs', 'type', 'price_min', 'price_max', 'beds', 'show_status', 'owner_only', 'sole_mandate_only', 'claimed'];

    /** True when the request carries any filter this class owns. */
    public static function present(array $input): bool
    {
        foreach (self::KEYS as $k) {
            if (isset($input[$k]) && $input[$k] !== '' && $input[$k] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<\App\Models\ProspectingListing>  $query
     * @param  array<string,mixed>  $in  the request's query/input
     * @return Builder<\App\Models\ProspectingListing>
     */
    public function apply(Builder $query, array $in, int $agencyId, User $user): Builder
    {
        $suburbs = array_values(array_filter((array) ($in['suburbs'] ?? []), fn ($v) => $v !== '' && $v !== null));
        if ($suburbs) {
            $ids = array_values(array_filter($suburbs, fn ($v) => ctype_digit((string) $v)));
            $names = array_values(array_map(fn ($v) => mb_strtolower(trim((string) $v)), array_diff($suburbs, $ids)));
            $query->where(function ($q) use ($ids, $names) {
                if ($ids) {
                    $q->whereIn('prospecting_listings.p24_suburb_id', array_map('intval', $ids));
                }
                if ($names) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(prospecting_listings.suburb))'), $names);
                }
            });
        }
        $types = array_values(array_filter((array) ($in['type'] ?? []), fn ($v) => trim((string) $v) !== ''));
        if ($types) {
            $query->whereIn('prospecting_listings.property_type', array_map('strval', $types));
        }
        if (isset($in['price_min']) && is_numeric($in['price_min']) && (int) $in['price_min'] > 0) {
            $query->where('prospecting_listings.price', '>=', (int) $in['price_min']);
        }
        if (isset($in['price_max']) && is_numeric($in['price_max']) && (int) $in['price_max'] > 0) {
            $query->where('prospecting_listings.price', '<=', (int) $in['price_max']);
        }
        if (isset($in['beds']) && is_numeric($in['beds']) && (int) $in['beds'] > 0) {
            $query->where('prospecting_listings.bedrooms', '>=', (int) $in['beds']);
        }
        if (! empty($in['owner_only']) && $in['owner_only'] !== '0') {
            $query->where('prospecting_listings.advertiser_type', 'owner');
        }
        if (! empty($in['sole_mandate_only']) && $in['sole_mandate_only'] !== '0') {
            $query->where(fn ($q) => $this->soleMandateSql($q, $agencyId));
        }
        $claimed = (string) ($in['claimed'] ?? 'any');
        if (in_array($claimed, ['unclaimed', 'mine', 'others'], true)) {
            $claimedBy = fn ($w) => $w->select(DB::raw(1))->from('tracked_property_agency_work as cw')->where('cw.agency_id', $agencyId)
                ->whereColumn('cw.tracked_property_id', 'prospecting_listings.tracked_property_id')->whereNotNull('cw.claimed_by_user_id');
            match ($claimed) {
                'unclaimed' => $query->whereNotExists($claimedBy),
                'mine' => $query->whereExists(fn ($w) => $claimedBy($w)->where('cw.claimed_by_user_id', $user->id)),
                'others' => $query->whereExists(fn ($w) => $claimedBy($w)->where('cw.claimed_by_user_id', '!=', $user->id)),
            };
        }

        return $query;
    }

    /**
     * The on-market-only default (the Work screen hides everything that is not live) and its lift: `show_status=all` keeps the live adverts AND adds
     * the under offer / sold ones. Withdrawn and gone adverts are never added here.
     *
     * @param  Builder<\App\Models\ProspectingListing>  $query
     */
    public function liftOnMarketDefault(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('prospecting_listings.is_active', true)->orWhereIn('prospecting_listings.portal_status', ['under_offer', 'sold']));
    }

    /** SQL: the advert's description (snippet or full) holds one of the agency's mandate phrases as a whole phrase, or a P24 alert for it was flagged mandated. */
    private function soleMandateSql($q, int $agencyId): void
    {
        // The P24 alerts flagged mandated are a short list for the agency (hundreds): read them once and match by IN lists, never a correlated lookup per advert.
        $alerts = DB::table('p24_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_mandated', 1)->get(['canonical_ref', 'tracked_property_id']);
        $refs = $alerts->pluck('canonical_ref')->filter()->unique()->values()->all();
        $files = $alerts->pluck('tracked_property_id')->filter()->unique()->values()->all();
        $q->where('prospecting_listings.sole_mandate_wording', 1)
            ->orWhere(function ($m) use ($refs, $files) {
                $m->whereIn('prospecting_listings.canonical_ref', $refs ?: ['-'])->orWhereIn('prospecting_listings.tracked_property_id', $files ?: [0]);
            });
    }

    /**
     * Whole-phrase, case-insensitive match of the agency's mandate words in a text. "SOLE MANDATE...!!!" matches "sole mandate"; "nonsole mandate" does not.
     *
     * @param  list<string>  $words  lower-case phrases
     */
    public static function mentionsMandate(?string $text, array $words): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        foreach ($words as $w) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/iu', $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The suburb dropdown: the agency's working suburbs first, then every other suburb that has adverts, each with its live advert count.
     *
     * @return array{working: list<array{id:int,name:string,count:int}>, others: list<array{id:int,name:string,count:int}>}
     */
    public function suburbOptions(int $agencyId): array
    {
        return MicWorkList::remember('suburb-options:' . $agencyId, 300, function () use ($agencyId) {
            $counts = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', 1)->whereNotNull('p24_suburb_id')
                ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id as id, COUNT(*) as n')->pluck('n', 'id');
            $working = array_keys(app(WorkingSuburbs::class)->ids($agencyId));
            $names = DB::table('p24_suburbs')->whereIn('id', array_values(array_unique(array_merge($working, $counts->keys()->all()))))->pluck('name', 'id');
            $row = fn ($id) => ['id' => (int) $id, 'name' => MicWorkList::titleSuburb($names[$id] ?? ('#' . $id)), 'count' => (int) ($counts[$id] ?? 0)];
            $first = array_map($row, $working);
            usort($first, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
            $rest = array_map($row, array_values(array_diff($counts->keys()->all(), $working)));
            usort($rest, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

            return ['working' => $first, 'others' => $rest];
        });
    }
}
