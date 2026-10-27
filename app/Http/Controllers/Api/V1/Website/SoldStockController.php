<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\SoldStockResource;
use App\Models\SoldStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/website/sold - the agency website's Sold showcase / sold map.
 *
 * Reads sold_stock ONLY (frozen at the sale), so a later status change on the property
 * (re-prospecting, a new listing cycle) can never remove a sale from here or turn it into a
 * For Sale advert. Same auth/scope/throttle as /listings. Spec: .ai/specs/sold-stock.md section 6.
 */
class SoldStockController extends Controller
{
    private const SORTS = [
        '-sale_date' => ['sale_date', 'desc'], 'sale_date' => ['sale_date', 'asc'],
        '-sale_price' => ['sale_price', 'desc'], 'sale_price' => ['sale_price', 'asc'],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(50, (int) $request->integer('per_page', 20)));
        $key     = $request->user();

        $query = SoldStock::query()->where('agency_id', $key->agency_id);

        if ($request->filled('suburb')) {
            $query->where('suburb', (string) $request->query('suburb'));
        }
        if ($request->filled('since')) {
            $query->whereDate('sale_date', '>=', (string) $request->query('since'));
        }
        if ($request->filled('until')) {
            $query->whereDate('sale_date', '<=', (string) $request->query('until'));
        }
        if ($request->filled('within_days')) {
            $query->whereDate('sale_date', '>=', now()->subDays(max(1, (int) $request->query('within_days')))->toDateString());
        }

        [$col, $dir] = self::SORTS[(string) $request->query('sort', '-sale_date')] ?? self::SORTS['-sale_date'];

        return SoldStockResource::collection(
            $query->orderBy($col, $dir)->orderByDesc('id')->paginate($perPage)->appends($request->query())
        );
    }
}
