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
        $status = array_values(array_filter((array) $request->query('status', []), fn ($v) => $v !== '' && $v !== null));
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

    /**
     * §9 steps 1-2, the "create one inline for this property" path: reached
     * from the property show page's "Send to Auction" link
     * (?property_id=X). $prefillProperty is validated here (own agency,
     * exists) so the form can never silently attach a property from another
     * tenant — the actual attach still only happens in store(), after the
     * auction itself is created, exactly as addLot() would do it separately.
     */
    public function create(Request $request)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();
        $prefillProperty = null;
        if ($propertyId = $request->query('property_id')) {
            $prefillProperty = Property::where('id', $propertyId)->where('agency_id', $agencyId)->first();
        }

        return view('corex.auctions.create', [
            'auction' => new Auction(),
            'branches' => \App\Models\Branch::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctionTypes' => PropertySettingItem::query()->group(PropertySettingItem::GROUP_AUCTION_TYPE)->where('active', true)->get(),
            'internalAuctioneers' => User::query()->where('agency_id', $agencyId)->orderBy('name')->get(),
            'auctioneerMode' => AgencyAuctionSettings::auctioneerModeFor($agencyId),
            'biddingModesEnabled' => AgencyAuctionSettings::biddingModesEnabledFor($agencyId),
            'prefillProperty' => $prefillProperty,
        ]);
    }

    public function store(Request $request)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        $data = $this->validateAuction($request, $agencyId);
        $data['agency_id'] = $agencyId;
        $data['created_by_id'] = auth()->id();

        $auction = Auction::create($data);

        // §9 steps 1-2 combined: a property carried in from the "Send to
        // Auction" link becomes Lot 1 of the just-created auction, in the
        // SAME request — never a property flagged sale_method='auction'
        // with no lot behind it. Re-validated against this agency (never
        // trust the hidden field alone).
        if ($propertyId = $request->input('property_id')) {
            $property = Property::where('id', $propertyId)->where('agency_id', $agencyId)->first();
            if ($property) {
                $this->attachPropertyAsLot($auction, $property, $request->only(['reserve_price', 'guide_price_min', 'guide_price_max', 'opening_bid']));
            }
        }

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
            'advertisingOnly' => AgencyAuctionSettings::advertisingOnlyFor($agencyId),
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

        $property = Property::where('id', $validated['property_id'])->where('agency_id', $auction->agency_id)->firstOrFail();

        $lot = $this->attachPropertyAsLot($auction, $property, $validated);

        return redirect()->route('corex.auctions.show', $auction)->with('status', "Lot #{$lot->lot_number} added.");
    }

    /**
     * The one place a property becomes an auction lot. Used by both addLot()
     * (attaching to an existing auction from the catalogue builder) and
     * store() (the "create one inline for this property" path from the
     * property show page) — a single implementation so the two entry points
     * can never drift apart on what "attach" actually does.
     */
    private function attachPropertyAsLot(Auction $auction, Property $property, array $priceFields): AuctionLot
    {
        return app(\App\Services\Auctions\AuctionLotAttacher::class)->attach($auction, $property, $priceFields);
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
        // Seller authority + FFC + external-auctioneer details must be in place
        // BEFORE the catalogue (and the public page) goes live — see
        // AuctionPublishGate. The status service stays a pure state machine.
        $blockers = app(\App\Services\Auctions\AuctionPublishGate::class)->blockers($auction);
        if (! empty($blockers)) {
            return back()->withErrors(['auction' => 'Cannot publish yet:'])->with('publish_blockers', $blockers);
        }

        $published = (new AuctionLotStatusService())->publishCatalogue($auction, auth()->id());

        if (empty($published)) {
            return back()->withErrors(['auction' => 'No draft lots to publish — add at least one lot first.']);
        }

        return redirect()->route('corex.auctions.show', $auction)->with('status', count($published).' lot(s) published to the catalogue.');
    }

    /**
     * Staff view/download of the uploaded Rules of Auction / Conditions of Sale PDFs.
     * The public route only serves a published catalogue, so staff need their own
     * (agency-scoped via route-model binding) to check a document before publishing.
     */
    public function document(Request $request, Auction $auction, string $kind)
    {
        abort_unless(in_array($kind, ['rules', 'conditions'], true), 404);

        $path = $auction->{$kind.'_file_path'};
        abort_if(blank($path) || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404, 'This document file could not be found — upload it again on the Edit page.');

        $name = $auction->{$kind.'_file_name'} ?: ($kind.'.pdf');
        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        return $request->boolean('download')
            ? $disk->download($path, $name, ['Content-Type' => 'application/pdf'])
            : $disk->response($path, $name, ['Content-Type' => 'application/pdf']);
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
            'external_registration_url' => 'nullable|url|max:500',
            'auctioneer_phone' => 'nullable|string|max:40',
            'auctioneer_email' => 'nullable|email|max:255',
            'rules_file' => 'nullable|file|mimes:pdf|max:10240',
            'conditions_file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        $enabledModes = AgencyAuctionSettings::biddingModesEnabledFor($agencyId);
        if (! in_array($data['bidding_mode'], $enabledModes, true)) {
            abort(422, 'That bidding mode is not enabled for this agency (Settings → Auctions).');
        }

        // Uploaded PDFs are stored on the private disk and served only through
        // the public auction page's document route (published catalogues only).
        foreach (['rules' => 'rules_file', 'conditions' => 'conditions_file'] as $kind => $input) {
            if ($request->hasFile($input)) {
                $file = $request->file($input);
                $stored = $file->store("auctions/{$agencyId}/{$kind}", 'local');
                // store() returns false (not an exception) when the disk can't be written —
                // saving that as a path produced a document that "exists" but never opens.
                if ($stored === false) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        $input => 'The file could not be saved on the server — please try again, and tell support if it keeps happening.',
                    ]);
                }
                $data[$kind.'_file_path'] = $stored;
                $data[$kind.'_file_name'] = $file->getClientOriginalName();
            }
            unset($data[$input]);
        }

        return $data;
    }
}
