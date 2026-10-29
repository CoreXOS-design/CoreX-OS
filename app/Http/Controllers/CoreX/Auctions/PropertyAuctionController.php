<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Document;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The property side of Auctions (.ai/specs/auctions.md §6): mark/unmark a property as auction, the
 * Auction tab (an editor of the property's one live lot) and the Drive "Auction pack" visibility.
 */
class PropertyAuctionController extends Controller
{
    public function __construct(private AuctionLotService $service) {}

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
