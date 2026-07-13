<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\CommissionSetting;
use Illuminate\Http\Request;

class CommissionSettingsController extends Controller
{
    public function edit()
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_settings'), 403);

        // Commission settings now live embedded in the unified Settings hub
        // (Operations → Commission & Revenue Share). Keep this legacy URL
        // working by redirecting to the hub section.
        return redirect()->route('corex.settings', ['s' => 'commission']);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_settings'), 403);

        $validated = $request->validate([
            'commission_split_agent' => ['required', 'integer', 'min:0', 'max:100'],
            'annual_cap' => ['required', 'numeric', 'min:0'],
            'post_cap_transaction_fee' => ['required', 'numeric', 'min:0'],
            'post_cap_fee_cap' => ['required', 'numeric', 'min:0'],
            'post_cap_reduced_fee' => ['required', 'numeric', 'min:0'],
            'monthly_platform_fee' => ['required', 'numeric', 'min:0'],
            'risk_management_fee' => ['required', 'numeric', 'min:0'],
            'risk_management_cap' => ['required', 'numeric', 'min:0'],
            'mentor_program_enabled' => ['nullable', 'boolean'],
            'mentor_extra_split' => ['required', 'integer', 'min:0', 'max:100'],
            'mentor_transactions' => ['required', 'integer', 'min:1', 'max:50'],
            'revenue_share_enabled' => ['nullable', 'boolean'],
            'revenue_share_pool_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'tier_1_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_2_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_3_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_4_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_5_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_6_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_7_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tier_4_flqa_requirement' => ['required', 'integer', 'min:0'],
            'tier_5_flqa_requirement' => ['required', 'integer', 'min:0'],
            'tier_6_flqa_requirement' => ['required', 'integer', 'min:0'],
            'tier_7_flqa_requirement' => ['required', 'integer', 'min:0'],
        ]);

        // Auto-calculate agency split
        $validated['commission_split_agency'] = 100 - $validated['commission_split_agent'];

        // Both forms that reach here (settings page + setup wizard) render these
        // toggles with a hidden "0" companion, so an unchecked box still arrives.
        // Guard on has() anyway — an absent key means the caller never rendered
        // the field, and must not be read as "off". See spec §6.1.
        foreach (['mentor_program_enabled', 'revenue_share_enabled'] as $flag) {
            if ($request->has($flag)) {
                $validated[$flag] = $request->boolean($flag);
            } else {
                unset($validated[$flag]);
            }
        }

        $agencyId = $user->effectiveAgencyId() ?? 1;
        $settings = CommissionSetting::forAgency($agencyId);
        $settings->update($validated);

        return redirect()->route('corex.settings', ['s' => 'commission'])
            ->with('success', 'Commission & Revenue Share settings saved.');
    }
}
