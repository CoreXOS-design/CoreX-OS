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
        // AT-253 (Rule 17) — read: sentinel 0 → guarded defaults, never agency 1's settings.
        $agencyId = (int) ($user?->effectiveAgencyId() ?: 0);
        $settings = CommissionSetting::forAgency($agencyId);

        return view('commission.calculator', compact('settings'));
    }
}
