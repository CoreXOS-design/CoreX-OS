<?php

namespace App\Http\Controllers\Nexus;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return view('nexus.settings');
    }
}
