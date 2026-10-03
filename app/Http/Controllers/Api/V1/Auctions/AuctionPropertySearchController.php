<?php

namespace App\Http\Controllers\Api\V1\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Typeahead for "Attach a Property" on the auction page. Uses the canonical
 * Property::searchAddress() (address, unit/complex, title) and the user's own/
 * branch/agency visibility, and leaves out properties already lots in this auction.
 */
class AuctionPropertySearchController extends Controller
{
    public function index(Request $request, Auction $auction): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $attached = $auction->lots()->pluck('property_id');

        $results = Property::query()
            ->visibleTo($request->user())
            ->where('agency_id', $auction->agency_id)
            ->whereNotIn('id', $attached)
            ->searchAddress($term)
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (Property $p) => [
                'id' => $p->id,
                'label' => $p->buildDisplayAddress(),
                'title' => $p->title,
                'status' => $p->statusBadge(),
            ]);

        return response()->json($results);
    }
}
