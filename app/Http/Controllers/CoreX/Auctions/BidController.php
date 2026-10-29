<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Services\Auctions\BidEngine;
use Illuminate\Http\Request;

/**
 * Screen S4 — Bids (.ai/specs/auctions.md §5): every bid on every lot, with who placed it (staff only), when, and how, and
 * the power to retract one with a reason. Bids are never deleted.
 */
class BidController extends Controller
{
    private const SORTABLE = ['placed_at', 'amount', 'lot', 'status'];

    public function __construct(private BidEngine $engine) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $lotId = trim((string) $request->query('lot_id', ''));
        $registrationId = trim((string) $request->query('registration_id', ''));
        $status = trim((string) $request->query('status', ''));
        $source = trim((string) $request->query('source', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'placed_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionBid::visibleTo($user)->with(['lot.property', 'auction', 'registration.contact', 'registration.clientUser']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('lot', fn ($l) => $l->withTrashed()->where('lot_number', $search)->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('property', fn ($p) => $p->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")->orWhere('street_name', 'like', "%{$search}%")))
                    ->orWhereHas('registration', fn ($r) => $r->withTrashed()->where('bidder_number', ctype_digit($search) ? (int) $search : -1)
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn ($c) => $c->withoutGlobalScopes()->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                    ->orWhere('amount', is_numeric(str_replace([' ', ','], '', $search)) ? (float) str_replace([' ', ','], '', $search) : -1);
            });
        }
        foreach (['auction_id' => $auctionId, 'lot_id' => $lotId, 'registration_id' => $registrationId] as $col => $val) {
            if ($val !== '' && ctype_digit($val)) {
                $query->where($col, (int) $val);
            }
        }
        if (in_array($status, [AuctionBid::STATUS_VALID, AuctionBid::STATUS_RETRACTED], true)) {
            $query->where('status', $status);
        }
        if (array_key_exists($source, AuctionBid::SOURCES)) {
            $query->where('source', $source);
        }
        if ($dateFrom !== '') {
            $query->whereDate('placed_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('placed_at', '<=', $dateTo);
        }

        if ($sort === 'lot') {
            $query->orderBy(\App\Models\AuctionLot::withTrashed()->withoutGlobalScopes()->select('lot_number')->whereColumn('auction_lots.id', 'auction_bids.lot_id')->limit(1), $dir);
        } else {
            $query->orderBy($sort, $dir);
        }

        return view('corex.auctions.bids.index', [
            'bids' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => compact('search', 'auctionId', 'lotId', 'registrationId', 'status', 'source', 'dateFrom', 'dateTo', 'sort', 'dir'),
            'auctions' => Auction::visibleTo($user)->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
        ]);
    }

    public function retract(Request $request, int $bid)
    {
        $row = AuctionBid::visibleTo($request->user())->findOrFail($bid);
        $data = $request->validate(['reason' => 'required|string|max:300']);

        $this->engine->retract($row, $request->user(), $data['reason']);

        return back()->with('status', 'Bid retracted. The lead has moved to the next valid bid.');
    }
}
