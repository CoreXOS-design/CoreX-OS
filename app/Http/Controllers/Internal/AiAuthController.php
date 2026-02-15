<?php

namespace App\Http\Controllers\Internal;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AiAuthController extends Controller
{
    public function check(Request $request)
    {
        if ($request->user()) {
            return response('OK', 200)
                ->header('X-User-ID', (string)$request->user()->id)
                ->header('X-User-Name', $request->user()->name ?? '');
        }

        return response('Unauthorized', 401);
    }
}
