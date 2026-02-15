<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AiBuddyController extends Controller
{
    public function index(Request $request)
    {
        return view('ai.buddy', [
            'user' => $request->user(),
        ]);
    }
}
