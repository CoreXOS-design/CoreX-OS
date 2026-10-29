<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Screen S2 — Lots (.ai/specs/auctions.md §5): list across all auctions, create a lot for a property,
 * edit, withdraw, archive and restore. All writes go through AuctionLotService, the same writer the
 * property's Auction tab uses.
 */
class AuctionLotController extends Controller
{
    private const SORTABLE = ['auction', 'lot_number', 'title', 'status', 'opens_at', 'closes_at', 'opening_bid'];

    public function __construct(private AuctionLotService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $status = trim((string) $request->query('status', ''));
        $agentId = trim((string) $request->query('agent_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $archived = $request->boolean('archived');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'auction';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionLot::visibleTo($user)
            ->join('auctions', 'auctions.id', '=', 'auction_lots.auction_id')
            ->select('auction_lots.*')
            ->with(['auction', 'property.agent']);
        if ($archived) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('auction_lots.lot_number', $search)
                    ->orWhere('auction_lots.title', 'like', "%{$search}%")
                    ->orWhere('auction_lots.reference', 'like', "%{$search}%")
                    ->orWhereHas('property', fn ($p) => $p->where('title', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('street_name', 'like', "%{$search}%")
                        ->orWhere('suburb', 'like', "%{$search}%"))
                    ->orWhereHas('property.agent', fn ($a) => $a->where('name', 'like', "%{$search}%"));
            });
        }
        if ($auctionId !== '' && ctype_digit($auctionId)) {
            $query->where('auction_lots.auction_id', (int) $auctionId);
        }
        if (in_array($status, AuctionLot::STATUSES, true)) {
            $query->where('auction_lots.status', $status);
        }
        if ($agentId !== '' && ctype_digit($agentId)) {
            $query->whereHas('property', fn ($p) => $p->where('agent_id', (int) $agentId));
        }
        if ($dateFrom !== '') {
            $query->whereDate('auctions.starts_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('auctions.starts_at', '<=', $dateTo);
        }

        match ($sort) {
            'auction' => $query->orderBy('auctions.starts_at', $dir)->orderBy('auction_lots.lot_number'),
            'title' => $query->orderBy('auction_lots.title', $dir),
            default => $query->orderBy('auction_lots.' . $sort, $dir),
        };

        return view('corex.auctions.lots.index', [
            'lots' => $query->paginate(25)->withQueryString(),
            'filters' => compact('search', 'auctionId', 'status', 'agentId', 'dateFrom', 'dateTo', 'archived', 'sort', 'dir'),
            'auctions' => Auction::visibleTo($user)->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
            'agents' => User::query()->where('agency_id', $user->effectiveAgencyId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $property = null;
        if ($pid = $request->query('property_id')) {
            $property = Property::visibleTo($user)->find($pid);
        }

        return view('corex.auctions.lots.form', [
            'lot' => new AuctionLot(['auction_id' => $request->query('auction_id')]),
            'property' => $property,
            'auctions' => $this->openAuctions($user),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request);
        $property = $this->eligibleProperty($user, (int) $request->input('property_id'));

        if ($existing = $property->currentAuctionLot()) {
            throw ValidationException::withMessages(['property_id' => "That property is already Lot {$existing->lot_number} in another auction. Edit that lot instead."]);
        }
        if (empty($data['auction_id'])) {
            throw ValidationException::withMessages(['auction_id' => 'Pick the auction this lot belongs to.']);
        }

        $lot = $this->service->saveForProperty($property, $data, $user);

        return redirect()->route('corex.auctions.lots.edit', $lot)->with('status', "Lot {$lot->lot_number} created.");
    }

    public function edit(Request $request, int $lot)
    {
        $lot = $this->find($request, $lot, withTrashed: true);
        $lot->load(['auction', 'property']);

        return view('corex.auctions.lots.form', [
            'lot' => $lot,
            'property' => $lot->property,
            'auctions' => $this->openAuctions($request->user(), $lot),
        ]);
    }

    public function update(Request $request, int $lot)
    {
        $lot = $this->find($request, $lot);
        abort_if($lot->isConcluded(), 422, 'A finished lot can no longer be changed.');

        $data = $this->validated($request);
        // The lot number is only chosen when the lot is created.
        unset($data['lot_number']);
        $this->service->saveForProperty($lot->property, $data, $request->user(), $lot);

        return back()->with('status', 'Lot saved.');
    }

    public function withdraw(Request $request, int $lot)
    {
        $request->validate(['reason' => 'nullable|string|max:300']);
        $this->service->withdraw($this->find($request, $lot), $request->input('reason'));

        return back()->with('status', 'Lot withdrawn. The property can go into another auction.');
    }

    public function archive(Request $request, int $lot)
    {
        $lot = $this->find($request, $lot);
        if (! in_array($lot->status, [AuctionLot::STATUS_DRAFT, AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_WITHDRAWN], true)) {
            return back()->withErrors(['archive' => 'A lot with a result cannot be archived.']);
        }
        $lot->delete();
        $this->service->releaseProperty($lot);

        return redirect()->route('corex.auctions.lots.index')->with('status', 'Lot archived.');
    }

    public function restore(Request $request, int $lot)
    {
        $lot = $this->find($request, $lot, withTrashed: true);
        if ($lot->property?->currentAuctionLot()) {
            return back()->withErrors(['restore' => 'That property is already in another live lot.']);
        }
        $lot->restore();
        $lot->forceFill(['status' => AuctionLot::STATUS_DRAFT])->save();
        $lot->property?->forceFill(['sale_method' => 'auction'])->save();

        return redirect()->route('corex.auctions.lots.edit', $lot)->with('status', 'Lot restored as a draft.');
    }

    private function find(Request $request, int $id, bool $withTrashed = false): AuctionLot
    {
        $q = AuctionLot::visibleTo($request->user());
        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->findOrFail($id);
    }

    /** Sale listings the user may see; a rental is never auctioned. */
    private function eligibleProperty(User $user, int $propertyId): Property
    {
        $property = Property::visibleTo($user)->find($propertyId);
        if (! $property || $property->isRental()) {
            throw ValidationException::withMessages(['property_id' => 'Pick one of your properties that is for sale.']);
        }

        return $property;
    }

    /** Auctions a lot can still be placed in: not finished, not cancelled. The lot's current one is always listed. */
    private function openAuctions(User $user, ?AuctionLot $lot = null)
    {
        return Auction::visibleTo($user)
            ->where(fn ($q) => $q->whereNotIn('status', [Auction::STATUS_COMPLETED, Auction::STATUS_CANCELLED])
                ->when($lot, fn ($q2) => $q2->orWhere('id', $lot->auction_id)))
            ->orderByDesc('starts_at')->get(['id', 'reference', 'title', 'starts_at']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'auction_id' => 'nullable|integer',
            'lot_number' => 'nullable|integer|min:1',
            'title' => 'nullable|string|max:200',
            'reference' => 'nullable|string|max:60',
            'external_ref' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:20000',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'opening_bid' => 'nullable|numeric|min:0',
            'reserve_price' => 'nullable|numeric|min:0',
            'guide_price_min' => 'nullable|numeric|min:0',
            'guide_price_max' => 'nullable|numeric|min:0',
        ]);
    }
}
