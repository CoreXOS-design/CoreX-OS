<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;

class AgencySwitcherController extends Controller
{
    public function switch(Agency $agency)
    {
        session(['active_agency_id' => $agency->id]);

        return back()->with('success', "Switched to {$agency->name}.");
    }

    public function clear()
    {
        session()->forget('active_agency_id');

        return back()->with('success', 'Viewing all agencies.');
    }
}
