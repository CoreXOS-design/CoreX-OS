@extends('layouts.corex')

@php
    $S = \App\Models\AgencyAuctionSettings::class;
@endphp

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col p-6 gap-6 max-w-4xl">
    <h1 class="text-xl font-semibold">Settings — Auctions</h1>
    <p class="text-sm text-gray-500">.ai/specs/auctions.md §4 — who runs your auctions, where bidding happens, and how the agency is paid.</p>

    @if(session('status'))<div class="rounded bg-green-50 text-green-800 px-4 py-2 text-sm">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">
            <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.auctions.update') }}" class="flex flex-col gap-8">
        @csrf

        <section>
            <h2 class="font-medium mb-3">How your agency uses Auctions</h2>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="advertising_only" value="1" class="mt-1" @checked(\App\Models\AgencyAuctionSettings::advertisingOnlyFor($agencyId))>
                <span><strong>Advertising only</strong> — Auctions is where we advertise auction properties. The sale itself is run elsewhere, so the Sale Room, bidder register and online registration are switched off. Untick to run the sale from CoreX.</span>
            </label>
        </section>

        <section>
            <h2 class="font-medium mb-3">Who runs the auction</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Auctioneer mode</label>
                    <select name="auctioneer_mode" class="corex-input w-full">
                        @foreach($S::AUCTIONEER_MODES as $m)
                            <option value="{{ $m }}" @selected((optional($settings)->auctioneer_mode ?? $S::DEFAULT_AUCTIONEER_MODE) === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Whether the Auction form offers our own auctioneer, an outside auction house, or asks each time.</p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="font-medium mb-3">Where bidding happens</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div class="sm:col-span-2">
                    <label class="block text-xs text-gray-500">Bidding modes enabled</label>
                    @php $enabledModes = optional($settings)->bidding_modes_enabled ?? $S::DEFAULT_BIDDING_MODES_ENABLED; @endphp
                    @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                        <label class="mr-4"><input type="checkbox" name="bidding_modes_enabled[]" value="{{ $val }}" @checked(in_array($val, $enabledModes, true))> {{ $label }}</label>
                    @endforeach
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Default bidding mode</label>
                    <select name="default_bidding_mode" class="corex-input w-full">
                        @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                            <option value="{{ $val }}" @selected((optional($settings)->default_bidding_mode ?? $S::DEFAULT_DEFAULT_BIDDING_MODE) === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label><input type="checkbox" name="online_auto_extend_enabled" value="1" @checked(optional($settings)->online_auto_extend_enabled ?? $S::DEFAULT_ONLINE_AUTO_EXTEND_ENABLED)> Auto-extend online bidding (anti-sniping)</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Auto-extend minutes</label>
                    <input type="number" name="online_auto_extend_minutes" min="1" max="30" value="{{ optional($settings)->online_auto_extend_minutes ?? $S::DEFAULT_ONLINE_AUTO_EXTEND_MINUTES }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Increment mode</label>
                    <select name="online_bid_increment_mode" class="corex-input w-full">
                        <option value="fixed" @selected((optional($settings)->online_bid_increment_mode ?? $S::DEFAULT_ONLINE_BID_INCREMENT_MODE) === 'fixed')>Fixed</option>
                        <option value="banded" @selected((optional($settings)->online_bid_increment_mode ?? $S::DEFAULT_ONLINE_BID_INCREMENT_MODE) === 'banded')>Banded</option>
                    </select>
                </div>
                <div><label><input type="checkbox" name="proxy_bidding_enabled" value="1" @checked(optional($settings)->proxy_bidding_enabled ?? $S::DEFAULT_PROXY_BIDDING_ENABLED)> Proxy bidding</label></div>
                <div><label><input type="checkbox" name="absentee_bids_enabled" value="1" @checked(optional($settings)->absentee_bids_enabled ?? $S::DEFAULT_ABSENTEE_BIDS_ENABLED)> Absentee bids</label></div>
                <div><label><input type="checkbox" name="phone_bidding_enabled" value="1" @checked(optional($settings)->phone_bidding_enabled ?? $S::DEFAULT_PHONE_BIDDING_ENABLED)> Phone bidding</label></div>
                <div><label><input type="checkbox" name="bid_retraction_allowed" value="1" @checked(optional($settings)->bid_retraction_allowed ?? $S::DEFAULT_BID_RETRACTION_ALLOWED)> Auctioneer may retract a bid</label></div>
            </div>
        </section>

        <section>
            <h2 class="font-medium mb-3">How the agency is paid</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Fee model</label>
                    <select name="fee_model" class="corex-input w-full">
                        @foreach($S::FEE_MODELS as $m)
                            <option value="{{ $m }}" @selected((optional($settings)->fee_model ?? $S::DEFAULT_FEE_MODEL) === $m)>{{ ucwords(str_replace('_', ' ', $m)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Buyer's premium %</label>
                    <input type="number" name="buyers_premium_percent" step="0.01" min="0" max="100" value="{{ optional($settings)->buyers_premium_percent ?? $S::DEFAULT_BUYERS_PREMIUM_PERCENT }}" class="corex-input w-full">
                </div>
                <div><label><input type="checkbox" name="buyers_premium_vat_inclusive" value="1" @checked(optional($settings)->buyers_premium_vat_inclusive ?? $S::DEFAULT_BUYERS_PREMIUM_VAT_INCLUSIVE)> Premium is VAT-inclusive</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Buyer's premium minimum (R)</label>
                    <input type="number" name="buyers_premium_minimum" step="0.01" min="0" value="{{ optional($settings)->buyers_premium_minimum }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Seller's commission %</label>
                    <input type="number" name="sellers_commission_percent" step="0.01" min="0" max="100" value="{{ optional($settings)->sellers_commission_percent }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">VAT rate source</label>
                    <select name="vat_rate_source" class="corex-input w-full">
                        <option value="system" @selected((optional($settings)->vat_rate_source ?? $S::DEFAULT_VAT_RATE_SOURCE) === 'system')>System (15%)</option>
                        <option value="override" @selected((optional($settings)->vat_rate_source ?? $S::DEFAULT_VAT_RATE_SOURCE) === 'override')>Agency override</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Premium payable on</label>
                    <select name="premium_payable_on" class="corex-input w-full">
                        @foreach($S::PREMIUM_PAYABLE_ON_OPTIONS as $o)
                            <option value="{{ $o }}" @selected((optional($settings)->premium_payable_on ?? $S::DEFAULT_PREMIUM_PAYABLE_ON) === $o)>{{ ucwords(str_replace('_', ' ', $o)) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section>
            <h2 class="font-medium mb-3">Bidder registration requirements</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div><label><input type="checkbox" name="registration_required" value="1" @checked(optional($settings)->registration_required ?? $S::DEFAULT_REGISTRATION_REQUIRED)> Registration required to bid</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Registration opens (days before)</label>
                    <input type="number" name="registration_opens_days_before" min="0" value="{{ optional($settings)->registration_opens_days_before ?? $S::DEFAULT_REGISTRATION_OPENS_DAYS_BEFORE }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Registration closes</label>
                    <select name="registration_closes" class="corex-input w-full">
                        <option value="at_start" @selected((optional($settings)->registration_closes ?? $S::DEFAULT_REGISTRATION_CLOSES) === 'at_start')>At the start</option>
                        <option value="hours_before" @selected((optional($settings)->registration_closes ?? $S::DEFAULT_REGISTRATION_CLOSES) === 'hours_before')>Hours before</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Hours before (if above)</label>
                    <input type="number" name="registration_closes_hours_before" min="0" value="{{ optional($settings)->registration_closes_hours_before ?? $S::DEFAULT_REGISTRATION_CLOSES_HOURS_BEFORE }}" class="corex-input w-full">
                </div>
                <div><label><input type="checkbox" name="require_fica_before_paddle" value="1" @checked(optional($settings)->require_fica_before_paddle ?? $S::DEFAULT_REQUIRE_FICA_BEFORE_PADDLE)> FICA required before paddle</label></div>
                <div><label><input type="checkbox" name="registration_deposit_required" value="1" @checked(optional($settings)->registration_deposit_required ?? $S::DEFAULT_REGISTRATION_DEPOSIT_REQUIRED)> Registration deposit required</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Deposit amount (R)</label>
                    <input type="number" name="registration_deposit_amount" step="0.01" min="0" value="{{ optional($settings)->registration_deposit_amount ?? $S::DEFAULT_REGISTRATION_DEPOSIT_AMOUNT }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Refund SLA (days)</label>
                    <input type="number" name="registration_deposit_refund_days" min="0" value="{{ optional($settings)->registration_deposit_refund_days ?? $S::DEFAULT_REGISTRATION_DEPOSIT_REFUND_DAYS }}" class="corex-input w-full">
                </div>
                <div><label><input type="checkbox" name="require_signed_rules_before_paddle" value="1" @checked(optional($settings)->require_signed_rules_before_paddle ?? $S::DEFAULT_REQUIRE_SIGNED_RULES_BEFORE_PADDLE)> Rules of Auction must be signed before paddle</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Paddle number mode</label>
                    <select name="paddle_number_mode" class="corex-input w-full">
                        <option value="sequential" @selected((optional($settings)->paddle_number_mode ?? $S::DEFAULT_PADDLE_NUMBER_MODE) === 'sequential')>Sequential</option>
                        <option value="manual" @selected((optional($settings)->paddle_number_mode ?? $S::DEFAULT_PADDLE_NUMBER_MODE) === 'manual')>Manual</option>
                    </select>
                </div>
                <div><label><input type="checkbox" name="entity_bidders_allowed" value="1" @checked(optional($settings)->entity_bidders_allowed ?? $S::DEFAULT_ENTITY_BIDDERS_ALLOWED)> Company/trust bidders allowed</label></div>
            </div>
        </section>

        <section>
            <h2 class="font-medium mb-3">Reserve, guide and confirmation</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Reserve visibility</label>
                    <select name="reserve_visibility" class="corex-input w-full">
                        @foreach($S::RESERVE_VISIBILITY_OPTIONS as $o)
                            <option value="{{ $o }}" @selected((optional($settings)->reserve_visibility ?? $S::DEFAULT_RESERVE_VISIBILITY) === $o)>{{ ucwords(str_replace('_', ' ', $o)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label><input type="checkbox" name="guide_price_enabled" value="1" @checked(optional($settings)->guide_price_enabled ?? $S::DEFAULT_GUIDE_PRICE_ENABLED)> Show a guide price publicly</label></div>
                <div><label><input type="checkbox" name="confirmation_period_enabled" value="1" @checked(optional($settings)->confirmation_period_enabled ?? $S::DEFAULT_CONFIRMATION_PERIOD_ENABLED)> Below-reserve sales go to seller confirmation</label></div>
                <div>
                    <label class="block text-xs text-gray-500">Confirmation period (days)</label>
                    <input type="number" name="confirmation_period_days" min="1" max="60" value="{{ optional($settings)->confirmation_period_days ?? $S::DEFAULT_CONFIRMATION_PERIOD_DAYS }}" class="corex-input w-full">
                </div>
                <div class="sm:col-span-3">
                    <label class="block text-xs text-gray-500">Vendor bidding disclosure (printed on every catalogue/advert — confirm wording with your attorney before first live use)</label>
                    <textarea name="vendor_bidding_disclosure" rows="2" class="corex-input w-full">{{ optional($settings)->vendor_bidding_disclosure ?? $S::DEFAULT_VENDOR_BIDDING_DISCLOSURE }}</textarea>
                </div>
            </div>
        </section>

        <section>
            <h2 class="font-medium mb-3">Deposit and settlement on the day</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div>
                    <label class="block text-xs text-gray-500">Purchase deposit mode</label>
                    <select name="purchase_deposit_mode" class="corex-input w-full">
                        @foreach($S::PURCHASE_DEPOSIT_MODES as $m)
                            <option value="{{ $m }}" @selected((optional($settings)->purchase_deposit_mode ?? $S::DEFAULT_PURCHASE_DEPOSIT_MODE) === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Deposit %</label>
                    <input type="number" name="purchase_deposit_percent" step="0.01" min="0" max="100" value="{{ optional($settings)->purchase_deposit_percent ?? $S::DEFAULT_PURCHASE_DEPOSIT_PERCENT }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Fixed amount (R, if mode=fixed)</label>
                    <input type="number" name="purchase_deposit_fixed_amount" step="0.01" min="0" value="{{ optional($settings)->purchase_deposit_fixed_amount }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Deposit due (days after hammer)</label>
                    <input type="number" name="purchase_deposit_due_days" min="0" value="{{ optional($settings)->purchase_deposit_due_days ?? $S::DEFAULT_PURCHASE_DEPOSIT_DUE_DAYS }}" class="corex-input w-full">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Balance due (days)</label>
                    <input type="number" name="balance_due_days" min="0" value="{{ optional($settings)->balance_due_days ?? $S::DEFAULT_BALANCE_DUE_DAYS }}" class="corex-input w-full">
                </div>
            </div>
        </section>

        <div>
            <button type="submit" class="corex-btn-primary">Save Auction Settings</button>
        </div>
    </form>
</div>
@endsection
