<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Document;
use App\Services\Auctions\AuctionLotService;
use App\Models\Auction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The property side of Auctions (.ai/specs/auctions.md §6): mark/unmark a property as auction, the
 * Auction tab (an editor of the property's one live lot) and the Drive "Auction pack" visibility.
 */
class PropertyAuctionController extends Controller
{
    public function __construct(private AuctionLotService $service) {}

    /**
     * "Add to auction": ask which auction, which lot number, the date (filled in from the auction) and the time, then put the
     * property into a real lot straight away — so it shows under Lots at once — and land on its Auction tab for the rest.
     */
    public function add(Request $request, int $property)
    {
        $user = $request->user();
        $property = $this->property($request, $property);

        try {
            $data = $request->validate([
                'auction_id' => 'required',
                'lot_number' => 'nullable|integer|min:1|max:9999',
                'opens_date' => 'required|date',
                'opens_time' => ['required', 'date_format:H:i'],
                'closes_date' => 'nullable|date',
                'closes_time' => ['nullable', 'date_format:H:i'],
            ], [
                'auction_id.required' => 'Choose which auction this property is going into.',
                'opens_date.required' => 'Enter the date.',
                'opens_time.required' => 'Enter the time.',
                'opens_time.date_format' => 'Enter the time like 10:30.',
                'closes_time.date_format' => 'Enter the closing time like 15:00.',
            ]);

            if ($property->isRental()) {
                throw ValidationException::withMessages(['auction_id' => 'Only a property for sale can go on auction.']);
            }
            if ($existing = $property->currentAuctionLot()) {
                throw ValidationException::withMessages(['auction_id' => "This property is already Lot {$existing->lot_number} in {$existing->auction->reference}. Change it on the Auction tab."]);
            }

            $auction = null;
            if ($data['auction_id'] !== 'new') {
                $auction = Auction::visibleTo($user)->find((int) $data['auction_id']);
                if (! $auction || ! $auction->isEditable()) {
                    throw ValidationException::withMessages(['auction_id' => 'Pick one of your upcoming auctions.']);
                }
            }

            // A live auction has one date and time. A timed one (or a new one-property auction) has bidding that opens and closes.
            $needsClose = $auction === null || $auction->format !== 'live';
            if ($needsClose && (empty($data['closes_date']) || empty($data['closes_time']))) {
                throw ValidationException::withMessages(['closes_date' => 'Enter the date and time bidding closes.']);
            }

            $lot = $this->service->saveForProperty($property, [
                'auction_id' => $auction?->id,
                'lot_number' => $data['lot_number'] ?? null,
                'opens_at' => Carbon::parse($data['opens_date'] . ' ' . $data['opens_time'])->toDateTimeString(),
                'closes_at' => $needsClose ? Carbon::parse($data['closes_date'] . ' ' . $data['closes_time'])->toDateTimeString() : null,
            ], $user);
        } catch (ValidationException $e) {
            session()->flash('add_to_auction_open', true);   // reopen the dialog with the messages

            throw $e;
        }

        return redirect()->to(route('corex.properties.show', $property) . '?tab=auction')
            ->with('status', "Added to {$lot->auction->reference} as Lot {$lot->lot_number}. Set the opening bid and reserve below.");
    }

    public function mark(Request $request, int $property)
    {
        $property = $this->property($request, $property);
        if ($property->isRental()) {
            throw ValidationException::withMessages(['sale_method' => 'Only a property for sale can go on auction.']);
        }
        $property->sale_method = 'auction';
        $property->save();

        return redirect()->to(route('corex.properties.show', $property) . '?tab=auction')
            ->with('status', 'This property is now marked as an auction property. Set when bidding opens and closes on the Auction tab.');
    }

    public function unmark(Request $request, int $property)
    {
        $property = $this->property($request, $property);
        if ($property->currentAuctionLot()) {
            return back()->withErrors(['sale_method' => 'Withdraw its auction lot before taking this property off auction.']);
        }
        $property->sale_method = 'private_treaty';
        $property->save();

        return redirect()->route('corex.properties.show', $property)->with('status', 'This property is no longer an auction property.');
    }

    public function update(Request $request, int $property)
    {
        $property = $this->property($request, $property);
        abort_unless($property->isAuction(), 422, 'Mark this property as an auction property first.');

        $data = $request->validate([
            'auction_id' => 'nullable|integer',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date',
            'opening_bid' => 'nullable|numeric|min:0',
            'reserve_price' => 'nullable|numeric|min:0',
            'guide_price_min' => 'nullable|numeric|min:0',
            'guide_price_max' => 'nullable|numeric|min:0',
        ]);
        if ($request->boolean('create_single')) {
            $data['auction_id'] = null;
        }

        $lot = $this->service->saveForProperty($property, $data, $request->user());

        return redirect()->to(route('corex.properties.show', $property) . '?tab=auction')
            ->with('status', "Saved. This property is Lot {$lot->lot_number} in {$lot->auction->reference}.");
    }

    public function withdraw(Request $request, int $property)
    {
        $property = $this->property($request, $property);
        $lot = $property->currentAuctionLot();
        abort_unless($lot, 404);
        $this->service->withdraw($lot, $request->input('reason'));

        return redirect()->to(route('corex.properties.show', $property) . '?tab=auction')->with('status', 'The lot was withdrawn.');
    }

    public function fileVisibility(Request $request, int $property, int $file)
    {
        $property = $this->property($request, $property);
        $data = $request->validate(['auction_visibility' => 'nullable|in:public,registered']);

        // The flag lives on the document<->property link (document_properties), not on the document itself.
        $document = $property->documents()->where('documents.id', $file)->firstOrFail();
        $property->documents()->updateExistingPivot($document->id, ['auction_visibility' => $data['auction_visibility'] ?? null]);

        return response()->json(['ok' => true, 'auction_visibility' => $data['auction_visibility'] ?? null]);
    }

    private function property(Request $request, int $id): Property
    {
        return Property::visibleTo($request->user())->findOrFail($id);
    }
}
