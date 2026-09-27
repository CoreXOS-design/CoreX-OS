<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use Illuminate\Http\Request;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §7 (screens 2-3), §8.1, §9.
 * Auction Diary (list) + the auction detail / catalogue-builder screen.
 * Lot-level actions live in AuctionLotController.
 */
class AuctionController extends Controller
{
    /** §8.1 — Auction Diary: search, sort, filter, pagination, empty state, agency scoping (via BelongsToAgency). */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = (array) $request->query('status', []);
        $biddingMode = trim((string) $request->query('bidding_mode', ''));
        $auctioneerKind = trim((string) $request->query('auctioneer_kind', ''));
        $branchId = $request->query('branch_id', '');
        $dateFrom = $request->query('date_from', '');
        $dateTo = $request->query('date_to', '');
        $sort = $request->query('sort', 'starts_at');
        $dir = $request->query('dir', 'asc');

        $query = Auction::with(['branch', 'auctioneerUser', 'auctioneerContact', 'lots']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('venue_name', 'like', "%{$search}%")
                    ->orWhere('auctioneer_company', 'like', "%{$search}%");
            });
        }

        if (! empty($status)) {
            $query->whereIn('status', $status);
        }
        if ($biddingMode !== '') {
            $query->where('bidding_mode', $biddingMode);
        }
        if ($auctioneerKind !== '') {
            $query->where('auctioneer_kind', $auctioneerKind);
        }
        if ($branchId !== '') {
            $query->where('branch_id', $branchId);
        }
        if ($dateFrom !== '') {
            $query->whereDate('starts_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('starts_at', '<=', $dateTo);
        }
        if ($request->boolean('has_unsold_lots')) {
            $query->whereHas('lots', fn ($q) => $q->whereNotIn('status', AuctionLot::CONCLUDED_STATUSES));
        }

        $sortable = ['starts_at', 'reference', 'title', 'status', 'created_at'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'starts_at';
        }
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir);

        $auctions = $query->paginate(25)->withQueryString();

        return view('corex.auctions.index', [
            'auctions' => $auctions,
            'filters' => compact('search', 'status', 'biddingMode', 'auctioneerKind', 'branchId', 'dateFrom', 'dateTo', 'sort', 'dir'),
            'statusOptions' => Auction::STATUSES,
        ]);
    }

    public function create()
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        return view('corex.auctions.create', [
            'auction' => new Auction(),
            'branches' => \App\Models\Branch::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctionTypes' => PropertySettingItem::query()->group(PropertySettingItem::GROUP_AUCTION_TYPE)->where('active', true)->get(),
            'internalAuctioneers' => User::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctioneerMode' => AgencyAuctionSettings::auctioneerModeFor($agencyId),
            'biddingModesEnabled' => AgencyAuctionSettings::biddingModesEnabledFor($agencyId),
        ]);
    }

    public function store(Request $request)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        $data = $this->validateAuction($request, $agencyId);
        $data['agency_id'] = $agencyId;
        $data['created_by_id'] = auth()->id();

        $auction = Auction::create($data);

        return redirect()->route('corex.auctions.show', $auction)->with('status', 'Auction created — add lots below, then publish the catalogue when ready.');
    }

    public function edit(Auction $auction)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        return view('corex.auctions.edit', [
            'auction' => $auction,
            'branches' => \App\Models\Branch::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctionTypes' => PropertySettingItem::query()->group(PropertySettingItem::GROUP_AUCTION_TYPE)->where('active', true)->get(),
            'internalAuctioneers' => User::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctioneerMode' => AgencyAuctionSettings::auctioneerModeFor($agencyId),
            'biddingModesEnabled' => AgencyAuctionSettings::biddingModesEnabledFor($agencyId),
        ]);
    }

    public function update(Request $request, Auction $auction)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();
        $auction->update($this->validateAuction($request, $agencyId));

        return redirect()->route('corex.auctions.show', $auction)->with('status', 'Auction updated.');
    }

    /** §7 screen 3 — the catalogue builder: auction detail + its lots + add-lot form + publish. */
    public function show(Auction $auction)
    {
        $agencyId = (int) $auction->agency_id;
        $canSeeReserve = AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published'
            || auth()->user()->hasPermission('auctions.reserve.view');

        return view('corex.auctions.show', [
            'auction' => $auction->load(['branch', 'auctioneerUser', 'auctioneerContact', 'lots.property']),
            'canSeeReserve' => $canSeeReserve,
            'canPublish' => auth()->user()->hasPermission('auctions.publish'),
            'statusLabels' => PropertySettingItem::auctionLotStatusLabelsFor($agencyId),
        ]);
    }

    public function archive(Auction $auction)
    {
        $auction->delete();

        return redirect()->route('corex.auctions.index')->with('status', 'Auction archived.');
    }

    public function restore(int $auction)
    {
        $model = Auction::withTrashed()->findOrFail($auction);
        $model->restore();

        return redirect()->route('corex.auctions.show', $model)->with('status', 'Auction restored.');
    }

    /**
     * §9 steps 1-2 combined: attaching a property to an auction is the UI
     * equivalent of "set to On Auction + attach". Sets Property::sale_method
     * immediately (the intent flag); the property's on-market STATUS only
     * changes when the catalogue publishes (§6.2 — AuctionLotStatusService
     * owns that, never this controller).
     */
    public function addLot(Request $request, Auction $auction)
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'reserve_price' => 'nullable|numeric|min:0',
            'guide_price_min' => 'nullable|numeric|min:0',
            'guide_price_max' => 'nullable|numeric|min:0',
            'opening_bid' => 'nullable|numeric|min:0',
        ]);

        $property = Property::findOrFail($validated['property_id']);

        $nextLotNumber = (int) ($auction->lots()->max('lot_number') ?? 0) + 1;

        $lot = AuctionLot::create([
            'agency_id' => $auction->agency_id,
            'auction_id' => $auction->id,
            'property_id' => $property->id,
            'lot_number' => $nextLotNumber,
            'reserve_price' => $validated['reserve_price'] ?? null,
            'guide_price_min' => $validated['guide_price_min'] ?? null,
            'guide_price_max' => $validated['guide_price_max'] ?? null,
            'opening_bid' => $validated['opening_bid'] ?? null,
        ]);

        $property->sale_method = 'auction';
        $property->save();

        return redirect()->route('corex.auctions.show', $auction)->with('status', "Lot #{$lot->lot_number} added.");
    }

    /** Only a lot that never catalogued may be removed here — a published lot is withdrawn instead (AuctionLotController). */
    public function removeLot(Auction $auction, AuctionLot $lot)
    {
        abort_unless($lot->auction_id === $auction->id, 404);

        if ($lot->status !== AuctionLot::STATUS_DRAFT) {
            return back()->withErrors(['lot' => 'This lot has already been catalogued — withdraw it from the lot page instead of removing it.']);
        }

        $property = $lot->property;
        $lot->delete();

        if ($property && ! $property->auctionLots()->whereNotIn('status', AuctionLot::CONCLUDED_STATUSES)->exists()) {
            $property->sale_method = 'private_treaty';
            $property->save();
        }

        return redirect()->route('corex.auctions.show', $auction)->with('status', 'Lot removed.');
    }

    /** §9 step 5. The actual property-status/audit-trail work is AuctionLotStatusService's — this is a thin controller action. */
    public function publish(Auction $auction)
    {
        $published = (new AuctionLotStatusService())->publishCatalogue($auction, auth()->id());

        if (empty($published)) {
            return back()->withErrors(['auction' => 'No draft lots to publish — add at least one lot first.']);
        }

        return redirect()->route('corex.auctions.show', $auction)->with('status', count($published).' lot(s) published to the catalogue.');
    }

    private function validateAuction(Request $request, int $agencyId): array
    {
        $data = $request->validate([
            'reference' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'auction_type_id' => 'nullable|integer|exists:property_setting_items,id',
            'bidding_mode' => 'required|in:in_room,online,hybrid',
            'auctioneer_kind' => 'required|in:internal,external',
            'auctioneer_user_id' => 'nullable|integer|exists:users,id',
            'auctioneer_contact_id' => 'nullable|integer|exists:contacts,id',
            'auctioneer_company' => 'nullable|string|max:255',
            'auctioneer_licence_no' => 'nullable|string|max:60',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'registration_opens_at' => 'nullable|date',
            'registration_closes_at' => 'nullable|date',
            'venue_name' => 'nullable|string|max:255',
            'venue_address' => 'nullable|string|max:500',
            'notes' => 'nullable|string',
        ]);

        $enabledModes = AgencyAuctionSettings::biddingModesEnabledFor($agencyId);
        if (! in_array($data['bidding_mode'], $enabledModes, true)) {
            abort(422, 'That bidding mode is not enabled for this agency (Settings → Auctions).');
        }

        return $data;
    }
}
