<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\ServerHealthService;
use Illuminate\Support\Facades\Auth;

/**
 * System Developer → Server Health. A lean, read-only, now-focused monitor of
 * live server vitals (no historical storage in v1). The page polls the JSON
 * endpoint (~10s). Access is permission-gated at the route (view_server_health);
 * the defensive abort_unless below is belt-and-suspenders.
 */
class ServerHealthController extends Controller
{
    public function __construct(private readonly ServerHealthService $health)
    {
    }

    /** The page shell (client polls the JSON endpoint for live values). */
    public function index()
    {
        abort_unless(Auth::user()?->hasPermission('view_server_health'), 403);

        return view('admin.system-health.index', [
            'diskAmber' => ServerHealthService::DISK_AMBER_PCT,
            'diskRed'   => ServerHealthService::DISK_RED_PCT,
        ]);
    }

    /** Cheap JSON snapshot for the 10s poll. Failure-contained in the service. */
    public function data()
    {
        abort_unless(Auth::user()?->hasPermission('view_server_health'), 403);

        return response()->json($this->health->snapshot());
    }
}
