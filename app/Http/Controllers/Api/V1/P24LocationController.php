<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use Illuminate\Http\Request;

/**
 * Read-only endpoints that serve the locally-cached P24 location tree to the
 * property form (cascading Province → City → Suburb selects). Backed by the
 * `p24_provinces` / `p24_cities` / `p24_suburbs` tables which the
 * `p24:sync-locations` artisan command populates from the P24 API.
 */
class P24LocationController extends Controller
{
    public function provinces(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = P24Province::query()->orderBy('name');
        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }

        return response()->json([
            'data' => $query->limit(50)->get(['id', 'name', 'p24_id']),
        ]);
    }

    public function cities(Request $request)
    {
        $request->validate([
            'province_id' => 'required|integer|exists:p24_provinces,id',
            'q'           => 'nullable|string|max:80',
            'all'         => 'nullable|boolean',
        ]);

        $query = P24City::query()
            ->where('p24_province_id', (int) $request->query('province_id'))
            ->orderBy('name');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }

        if (!$request->boolean('all')) {
            $query->limit(500);
        }

        return response()->json([
            'data' => $query->get(['id', 'name', 'p24_id']),
        ]);
    }

    public function suburbs(Request $request)
    {
        $request->validate([
            'city_id' => 'required|integer|exists:p24_cities,id',
            'q'       => 'nullable|string|max:80',
            'all'     => 'nullable|boolean',
        ]);

        $query = P24Suburb::query()
            ->where('p24_city_id', (int) $request->query('city_id'))
            ->orderBy('name');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'like', "%{$q}%");
        }

        if (!$request->boolean('all')) {
            $query->limit(500);
        }

        return response()->json([
            'data' => $query->get(['id', 'name', 'p24_id']),
        ]);
    }
}
