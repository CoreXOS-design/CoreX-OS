<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use Illuminate\Http\Request;

/**
 * AT-432 — .ai/specs/auctions.md §4. Settings → Auctions, the switchboard
 * behind Andre's three shape-defining decisions. Renders every field on one
 * screen; `update()` writes every field the form posts (unlike the
 * onboarding wizard step, which posts a SUBSET and must guard each boolean
 * with $request->has() — see AgencyOnboardingSetupController's auctions
 * step for that guard).
 */
class AuctionSettingsController extends Controller
{
    public function show()
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        return view('corex.settings.auctions', [
            'agencyId' => $agencyId,
            'settings' => AgencyAuctionSettings::where('agency_id', $agencyId)->first(),
            'defaults' => AgencyAuctionSettings::class,
        ]);
    }

    public function update(Request $request)
    {
        $agencyId = (int) auth()->user()->effectiveAgencyId();

        $data = $request->validate([
            'auctioneer_mode' => 'required|in:internal,external,both',
            'bidding_modes_enabled' => 'required|array|min:1',
            'bidding_modes_enabled.*' => 'in:in_room,online,hybrid',
            'default_bidding_mode' => 'required|in:in_room,online,hybrid',
            'online_auto_extend_enabled' => 'boolean',
            'online_auto_extend_minutes' => 'required|integer|min:1|max:30',
            'online_bid_increment_mode' => 'required|in:fixed,banded',
            'proxy_bidding_enabled' => 'boolean',
            'absentee_bids_enabled' => 'boolean',
            'phone_bidding_enabled' => 'boolean',
            'bid_retraction_allowed' => 'boolean',

            'fee_model' => 'required|in:buyers_premium,sellers_commission,both',
            'buyers_premium_percent' => 'required|numeric|min:0|max:100',
            'buyers_premium_vat_inclusive' => 'boolean',
            'buyers_premium_minimum' => 'nullable|numeric|min:0',
            'sellers_commission_percent' => 'nullable|numeric|min:0|max:100',
            'vat_rate_source' => 'required|in:system,override',
            'premium_payable_on' => 'required|in:fall_of_hammer,confirmation,registration',

            'registration_required' => 'boolean',
            'registration_opens_days_before' => 'required|integer|min:0',
            'registration_closes' => 'required|in:at_start,hours_before',
            'registration_closes_hours_before' => 'required|integer|min:0',
            'require_fica_before_paddle' => 'boolean',
            'registration_deposit_required' => 'boolean',
            'registration_deposit_amount' => 'required|numeric|min:0',
            'registration_deposit_refund_days' => 'required|integer|min:0',
            'require_signed_rules_before_paddle' => 'boolean',
            'paddle_number_mode' => 'required|in:sequential,manual',
            'entity_bidders_allowed' => 'boolean',

            'reserve_visibility' => 'required|in:private,disclosed_on_the_day,published',
            'guide_price_enabled' => 'boolean',
            'confirmation_period_enabled' => 'boolean',
            'confirmation_period_days' => 'required|integer|min:1|max:60',
            'vendor_bidding_disclosure' => 'nullable|string',

            'purchase_deposit_mode' => 'required|in:percent,fixed,none',
            'purchase_deposit_percent' => 'nullable|numeric|min:0|max:100',
            'purchase_deposit_fixed_amount' => 'nullable|numeric|min:0',
            'purchase_deposit_due_days' => 'required|integer|min:0',
            'balance_due_days' => 'required|integer|min:0',
        ]);

        // Checkboxes absent from the payload mean "unchecked" on this full-page
        // form (every field renders here, unlike the wizard's subset) — coerce
        // explicitly so an unchecked box is stored as false, not left null.
        foreach ([
            'online_auto_extend_enabled', 'proxy_bidding_enabled', 'absentee_bids_enabled',
            'phone_bidding_enabled', 'bid_retraction_allowed', 'buyers_premium_vat_inclusive',
            'registration_required', 'require_fica_before_paddle', 'registration_deposit_required',
            'require_signed_rules_before_paddle', 'entity_bidders_allowed', 'guide_price_enabled',
            'confirmation_period_enabled',
        ] as $bool) {
            $data[$bool] = $request->boolean($bool);
        }

        AgencyAuctionSettings::updateOrCreate(['agency_id' => $agencyId], $data);

        return back()->with('status', 'Auction settings saved.');
    }
}
