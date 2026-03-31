<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use Illuminate\Http\Request;

class RevenueShareController extends Controller
{
    public function calculator(Request $request)
    {
        $user = auth()->user();
        $agencyId = $user->effectiveAgencyId() ?? 1;
        $settings = CommissionSetting::forAgency($agencyId);

        return view('commission.calculator', compact('settings'));
    }
}
