<?php

namespace App\Http\Controllers\Api\V1\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;

/** GET /api/v1/auctions/property-search — the property picker on the Lots screen (.ai/specs/auctions.md §11). */
class AuctionPropertySearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $rows = Property::visibleTo($request->user())
            ->where('listing_type', '!=', 'rental')
            ->whereDoesntHave('auctionLots', fn ($l) => $l->live())
            ->where(function ($w) use ($q) {
                $like = "%{$q}%";
                $w->where('title', 'like', $like)->orWhere('address', 'like', $like)
                    ->orWhere('street_name', 'like', $like)->orWhere('suburb', 'like', $like)
                    ->orWhere('property_number', 'like', $like)->orWhere('erf_number', 'like', $like);
            })
            ->orderBy('title')->limit(15)->get();

        return response()->json(['data' => $rows->map(fn (Property $p) => [
            'id' => $p->id,
            'label' => trim($p->title . ' — ' . $p->buildDisplayAddress(), ' —'),
            'price' => $p->price,
        ])->values()]);
    }
}
