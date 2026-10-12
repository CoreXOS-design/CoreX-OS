<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionLot;
use App\Models\PropertySettingItem;
use Illuminate\Http\Request;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §7 screen 8, §8.4. Results across
 * every concluded lot (sold / sold_subject_to_confirmation / passed_in /
 * withdrawn) — "results appear here as lots are knocked down."
 */
class AuctionResultController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $auctionId = $request->query('auction_id', '');
        $lotStatus = $request->query('lot_status', '');
        $reserveMet = $request->query('reserve_met', '');
        $dateFrom = $request->query('date_from', '');
        $dateTo = $request->query('date_to', '');
        $branchId = $request->query('branch_id', '');
        $agentId = $request->query('agent_id', '');
        $priceMin = $request->query('price_min', '');
        $priceMax = $request->query('price_max', '');
        $sort = $request->query('sort', 'auction_date');
        $dir = $request->query('dir', 'desc');

        // Every auction_lots column is qualified with its table name
        // throughout — the auction_date sort below joins `auctions`, which
        // also has a `status` (and `id`) column, so a bare column name is
        // genuinely ambiguous to MySQL the moment that join is present, not
        // just a style preference.
        $query = AuctionLot::query()
            ->whereIn('auction_lots.status', [
                AuctionLot::STATUS_SOLD, AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION,
                AuctionLot::STATUS_PASSED_IN, AuctionLot::STATUS_WITHDRAWN,
            ])
            ->with(['auction', 'property.agent', 'property.branch', 'winningBidder.contact']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('auction_lots.lot_number', 'like', "%{$search}%")
                    ->orWhereHas('property', fn ($p) => $p->where('address', 'like', "%{$search}%")->orWhere('suburb', 'like', "%{$search}%"))
                    ->orWhereHas('winningBidder.contact', fn ($c) => $c->search($search));
            });
        }
        if ($auctionId !== '') $query->where('auction_lots.auction_id', $auctionId);
        if ($lotStatus !== '') $query->where('auction_lots.status', $lotStatus);
        if ($reserveMet !== '') $query->where('auction_lots.reserve_met', $reserveMet === '1');
        if ($dateFrom !== '') $query->whereHas('auction', fn ($a) => $a->whereDate('starts_at', '>=', $dateFrom));
        if ($dateTo !== '') $query->whereHas('auction', fn ($a) => $a->whereDate('starts_at', '<=', $dateTo));
        if ($branchId !== '') $query->whereHas('property', fn ($p) => $p->where('branch_id', $branchId));
        if ($agentId !== '') $query->whereHas('property', fn ($p) => $p->where('agent_id', $agentId));
        if ($priceMin !== '' && is_numeric($priceMin)) $query->where('auction_lots.hammer_price', '>=', $priceMin);
        if ($priceMax !== '' && is_numeric($priceMax)) $query->where('auction_lots.hammer_price', '<=', $priceMax);

        $sortable = ['lot_number', 'hammer_price', 'reserve_met', 'status'];
        if ($sort === 'auction_date') {
            $query->join('auctions', 'auctions.id', '=', 'auction_lots.auction_id')
                ->orderBy('auctions.starts_at', $dir === 'asc' ? 'asc' : 'desc')
                ->select('auction_lots.*');
        } elseif (in_array($sort, $sortable, true)) {
            $query->orderBy("auction_lots.{$sort}", $dir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('auction_lots.id');
        }

        $lots = $query->paginate(25)->withQueryString();
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        return view('corex.auctions.results', [
            'lots' => $lots,
            'filters' => compact('search', 'auctionId', 'lotStatus', 'reserveMet', 'dateFrom', 'dateTo', 'branchId', 'agentId', 'priceMin', 'priceMax', 'sort', 'dir'),
            'statusLabels' => PropertySettingItem::auctionLotStatusLabelsFor($agencyId),
            'canSeeReserve' => AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
                || auth()->user()->hasPermission('auctions.reserve.view'),
        ]);
    }

    /** Export is scoped IDENTICALLY to the screen — same query builder, never a second unscoped path. */
    public function export(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('auctions.results.export'), 403);

        // Reuse index()'s exact filtering by calling it and pulling the
        // paginator's underlying query rather than a second implementation.
        $agencyId = (int) auth()->user()->effectiveAgencyId();
        $canSeeReserve = AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
            || auth()->user()->hasPermission('auctions.reserve.view');

        $request->query->set('page', 1);
        $view = $this->index($request);
        $lots = $view->getData()['lots'];

        $filename = 'auction-results-'.now()->format('Y-m-d-His').'.csv';
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename={$filename}"];

        $callback = function () use ($lots, $canSeeReserve) {
            $out = fopen('php://output', 'w');
            $header = ['Auction', 'Lot', 'Property', 'Status', 'Hammer Price'];
            if ($canSeeReserve) $header[] = 'Reserve';
            $header[] = 'Reserve Met';
            $header[] = 'Buyer';
            fputcsv($out, $header, ',', '"', '\\');

            foreach ($lots as $lot) {
                $row = [
                    $lot->auction?->reference, $lot->lot_number, $lot->property?->address,
                    ucwords(str_replace('_', ' ', $lot->status)), $lot->hammer_price,
                ];
                if ($canSeeReserve) $row[] = $lot->reserve_price;
                $row[] = is_null($lot->reserve_met) ? '' : ($lot->reserve_met ? 'Yes' : 'No');
                $row[] = $lot->winningBidder?->contact?->full_name;
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}
