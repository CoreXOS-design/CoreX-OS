<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use Illuminate\Http\Request;

/**
 * Screen S7 — Results (.ai/specs/auctions.md §5): every lot that has an outcome (sold, sold subject to confirmation, passed
 * in, withdrawn), with the hammer price, whether the reserve was met, the winning bidder and the bids it took. A CSV export
 * carries the same rows.
 */
class ResultController extends Controller
{
    private const OUTCOMES = [
        AuctionLot::STATUS_SOLD => 'Sold',
        AuctionLot::STATUS_SOLD_SUBJECT => 'Sold subject to confirmation',
        AuctionLot::STATUS_PASSED_IN => 'Passed in',
        AuctionLot::STATUS_WITHDRAWN => 'Withdrawn',
    ];

    private const SORTABLE = ['result_at', 'hammer_price', 'lot', 'outcome'];

    public function index(Request $request)
    {
        [$query, $filters] = $this->query($request);

        $base = (clone $query)->reorder();
        $stats = [
            'sold' => (clone $base)->where('auction_lots.status', AuctionLot::STATUS_SOLD)->count(),
            'subject' => (clone $base)->where('auction_lots.status', AuctionLot::STATUS_SOLD_SUBJECT)->count(),
            'passed_in' => (clone $base)->where('auction_lots.status', AuctionLot::STATUS_PASSED_IN)->count(),
            'turnover' => (float) (clone $base)->where('auction_lots.status', AuctionLot::STATUS_SOLD)->sum('auction_lots.hammer_price'),
            'highest' => (float) (clone $base)->whereIn('auction_lots.status', [AuctionLot::STATUS_SOLD, AuctionLot::STATUS_SOLD_SUBJECT])->max('auction_lots.hammer_price'),
            'bids_per_lot' => $this->bidsPerLot($base),
            'commission' => (float) \App\Models\AuctionInvoice::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('status', ['issued', 'part_paid', 'paid'])
                ->whereIn('lot_id', (clone $base)->pluck('auction_lots.id'))->sum('commission_amount'),
        ];

        $lots = $query->paginate(25)->withQueryString();
        $winners = $this->winners($lots->getCollection());

        return view('corex.auctions.results', [
            'lots' => $lots,
            'winners' => $winners,
            'bidCounts' => AuctionBid::withoutGlobalScopes()->where('status', 'valid')->whereNull('deleted_at')->whereIn('lot_id', $lots->pluck('id'))
                ->selectRaw('lot_id, COUNT(*) as n')->groupBy('lot_id')->pluck('n', 'lot_id'),
            'stats' => $stats,
            'filters' => $filters,
            'outcomes' => self::OUTCOMES,
            'auctions' => Auction::visibleTo($request->user())->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
            'canReserve' => $request->user()->hasPermission('auctions.reserve.view'),
        ]);
    }

    public function export(Request $request)
    {
        [$query] = $this->query($request);
        $canReserve = $request->user()->hasPermission('auctions.reserve.view');

        return response()->streamDownload(function () use ($query, $canReserve) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_filter(['Result date', 'Auction', 'Lot', 'Property', 'Outcome', 'Hammer price', $canReserve ? 'Reserve' : null, $canReserve ? 'Reserve met' : null, 'Winning bidder no.', 'Winning bidder', 'Valid bids']));

            $query->chunk(200, function ($lots) use ($out, $canReserve) {
                $winners = $this->winners($lots);
                $counts = AuctionBid::withoutGlobalScopes()->where('status', 'valid')->whereNull('deleted_at')->whereIn('lot_id', $lots->pluck('id'))
                    ->selectRaw('lot_id, COUNT(*) as n')->groupBy('lot_id')->pluck('n', 'lot_id');
                foreach ($lots as $lot) {
                    $w = $winners[$lot->id] ?? null;
                    $row = [
                        $lot->result_at?->format('Y-m-d H:i'), $lot->auction?->reference, $lot->lot_number, $lot->property?->buildDisplayAddress() ?: $lot->displayTitle(),
                        self::OUTCOMES[$lot->status] ?? $lot->status, $lot->hammer_price !== null ? number_format((float) $lot->hammer_price, 2, '.', '') : '',
                    ];
                    if ($canReserve) {
                        $row[] = $lot->reserve_price !== null ? number_format((float) $lot->reserve_price, 2, '.', '') : '';
                        $row[] = $lot->hammer_price !== null && $lot->reserve_price !== null ? ((float) $lot->hammer_price >= (float) $lot->reserve_price ? 'Yes' : 'No') : '';
                    }
                    array_push($row, $w?->bidder_number, $w?->displayName(), $counts[$lot->id] ?? 0);
                    fputcsv($out, $row);
                }
            });
            fclose($out);
        }, 'auction-results-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // ── shared ─────────────────────────────────────────────────────────────

    private function query(Request $request): array
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $outcome = trim((string) $request->query('outcome', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'result_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionLot::visibleTo($user)->select('auction_lots.*')
            ->whereIn('auction_lots.status', array_keys(self::OUTCOMES))->whereNotNull('auction_lots.result_at')
            ->with(['auction', 'property']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('auction_lots.lot_number', $search)->orWhere('auction_lots.title', 'like', "%{$search}%")
                    ->orWhereHas('property', fn ($p) => $p->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")->orWhere('street_name', 'like', "%{$search}%"))
                    ->orWhereIn('auction_lots.winning_bid_id', AuctionBid::withoutGlobalScopes()->select('id')->whereIn('registration_id',
                        AuctionRegistration::withoutGlobalScopes()->select('id')->where(fn ($r) => $r->where('company_name', 'like', "%{$search}%")
                            ->orWhere('bidder_number', ctype_digit($search) ? (int) $search : -1)
                            ->orWhereIn('contact_id', \App\Models\Contact::withoutGlobalScopes()->select('id')->where(fn ($c) => $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))));
            });
        }
        if ($auctionId !== '' && ctype_digit($auctionId)) {
            $query->where('auction_lots.auction_id', (int) $auctionId);
        }
        if (array_key_exists($outcome, self::OUTCOMES)) {
            $query->where('auction_lots.status', $outcome);
        }
        if ($dateFrom !== '') {
            $query->whereDate('auction_lots.result_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('auction_lots.result_at', '<=', $dateTo);
        }

        match ($sort) {
            'lot' => $query->orderBy('auction_lots.lot_number', $dir),
            'outcome' => $query->orderBy('auction_lots.status', $dir),
            default => $query->orderBy('auction_lots.' . $sort, $dir),
        };
        $query->orderByDesc('auction_lots.id');

        return [$query, compact('search', 'auctionId', 'outcome', 'dateFrom', 'dateTo', 'sort', 'dir')];
    }

    /** @return \Illuminate\Support\Collection<int, AuctionRegistration> lot id => winning registration */
    private function winners($lots)
    {
        $bids = AuctionBid::withoutGlobalScopes()->whereIn('id', $lots->pluck('winning_bid_id')->filter())->get()->keyBy('id');
        $regs = AuctionRegistration::withoutGlobalScopes()->with('contact')->whereIn('id', $bids->pluck('registration_id'))->get()->keyBy('id');

        return $lots->mapWithKeys(fn ($lot) => [$lot->id => $regs->get($bids->get($lot->winning_bid_id)?->registration_id)])->filter();
    }

    private function bidsPerLot($base): float
    {
        $ids = (clone $base)->pluck('auction_lots.id');
        if ($ids->isEmpty()) {
            return 0.0;
        }

        return round(AuctionBid::withoutGlobalScopes()->where('status', 'valid')->whereNull('deleted_at')->whereIn('lot_id', $ids)->count() / $ids->count(), 1);
    }
}
