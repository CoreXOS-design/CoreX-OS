<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index()
    {
        return view('corex.settings');
    }

    public function generateApiToken(Request $request)
    {
        $plaintext = Str::random(64);

        $request->user()->update([
            'api_token' => hash('sha256', $plaintext),
        ]);

        return response()->json(['token' => $plaintext]);
    }
}
