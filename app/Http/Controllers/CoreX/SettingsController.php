<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return view('corex.settings');
    }
}
