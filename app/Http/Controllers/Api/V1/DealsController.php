<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DealsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = Deal::query()->latest('id');

        if ($period = $request->input('period')) {
            $query->where('period', $period);
        }

        return response()->json($query->paginate($perPage));
    }

    public function show(Deal $deal): JsonResponse
    {
        return response()->json($deal);
    }
}
