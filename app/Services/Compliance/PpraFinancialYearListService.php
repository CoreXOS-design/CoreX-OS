<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PPRA Inspection Pack Phase E — item (j), .ai/specs/ppra-inspection-pack.md
 * §6.7 (v3, redefined). "Active AND advertised at any point in the current
 * financial year" — not simply created/sold in that window.
 *
 * v3 correction (2026-09-28, Johan): this is a NEW admin-only PPRA screen,
 * not a default-filter change to the existing DR2 deals list or rentals
 * list — those stay exactly as every agent/branch manager already knows
 * them for daily use. Item (j)'s FY-bound view lives only inside the PPRA
 * Inspection Pack.
 *
 * Advertised proof, per channel:
 *   - P24 / PrivateProperty: single-value *_activated_at timestamp columns
 *     on `properties` (overwrite-on-reactivation — see the stated
 *     limitation below).
 *   - Agency's own website: `property_website_syndication`, which DOES
 *     carry real history via SoftDeletes (a row survives deactivation as a
 *     soft-deleted record) — the one channel with a genuine on/off trail.
 *
 * STATED LIMITATION (disclosed, not hidden): p24_activated_at/pp_activated_at
 * hold only the MOST RECENT activation. A property advertised, pulled, and
 * re-advertised within the same FY still correctly counts as "advertised at
 * some point in FY{Y}" (all item j requires), but this derivation cannot
 * reconstruct how many separate advertising windows a property had within
 * the year from those two columns alone.
 */
class PpraFinancialYearListService
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveRange(Agency $agency, ?string $customFrom = null, ?string $customTo = null): array
    {
        if ($customFrom && $customTo) {
            return [Carbon::parse($customFrom)->startOfDay(), Carbon::parse($customTo)->endOfDay()];
        }

        $startMonth = $agency->financial_year_start_month ?: 3;
        $now = now();
        $fyStartYear = $now->month >= $startMonth ? $now->year : $now->year - 1;

        $from = Carbon::create($fyStartYear, $startMonth, 1)->startOfDay();
        $to = $from->copy()->addYear()->subDay()->endOfDay();

        return [$from, $to];
    }

    /** items k/j/report — a human label like "1 Mar 2026 – 28 Feb 2027". */
    public function rangeLabel(Carbon $from, Carbon $to): string
    {
        return $from->format('j M Y') . ' – ' . $to->format('j M Y');
    }

    /**
     * @return Collection<int, Property> every active-and-advertised property
     *   of the given listing_type ('sale'|'rental') for this agency, in the window.
     */
    public function advertisedListings(Agency $agency, string $listingType, Carbon $from, Carbon $to): Collection
    {
        $websiteAdvertisedIds = DB::table('property_website_syndication')
            ->where('agency_id', $agency->id)
            ->where('created_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $from);
            })
            ->where(function ($q) {
                $q->where('enabled', true)->orWhereNotNull('activated_at')->orWhereNotNull('last_submitted_at');
            })
            ->pluck('property_id');

        return Property::where('agency_id', $agency->id)
            ->where('listing_type', $listingType)
            ->where(function ($q) use ($from, $to, $websiteAdvertisedIds) {
                $q->whereBetween('p24_activated_at', [$from, $to])
                    ->orWhere(fn ($q2) => $q2->where('p24_activated_at', '<=', $to)->where('p24_syndication_enabled', true))
                    ->orWhereBetween('pp_activated_at', [$from, $to])
                    ->orWhere(fn ($q2) => $q2->where('pp_activated_at', '<=', $to)->where('pp_syndication_enabled', true))
                    ->orWhereIn('id', $websiteAdvertisedIds);
            })
            ->orderBy('address')
            ->get();
    }

    /**
     * @return array{sales: int, rentals: int}
     */
    public function counts(Agency $agency, Carbon $from, Carbon $to): array
    {
        return [
            'sales'   => $this->advertisedListings($agency, 'sale', $from, $to)->count(),
            'rentals' => $this->advertisedListings($agency, 'rental', $from, $to)->count(),
        ];
    }
}
