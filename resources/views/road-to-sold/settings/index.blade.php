@extends('layouts.corex')

{{--
    AT-454 - Road to Sold settings hub (General tab).
    Spec: .ai/specs/road-to-sold.md section 4 / 5.1.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    One group of settings at a time (left menu inside the page, 27 Oct 2026 - the old page was one long scroll): every group
    stays in the same form, so Save settings saves them all. The reorder buttons are plain JS at the bottom.
--}}

@php
    // Which group a field lives in: the page opens on the group with an error, else the one asked for, else the first.
    $groupOf = [
        'areas'    => ['lock_agents_to_areas', 'refresh_portals', 'area_refresh_warn_days', 'min_area_properties'],
        'targets'  => ['half_day_leave_halves_target', 'public_holiday_country', 'missed_items_mode'],
        'calllist' => ['selections'],
        'limits'   => ['new_on_market_days', 'long_on_market_days', 'owner_known_quantity', 'stuck_stock_min_days', 'stuck_stock_max_views', 'stuck_stock_max_leads', 'stuck_stock_lookback_days', 'hot_pocket_lookback_days', 'price_drop_lookback_days', 'expired_competitor_lookback_days', 'prospect_stall_days'],
        'matching' => ['buyer_match_tiers', 'active_buyer_states', 'on_market_statuses', 'add_to_day_max', 'hot_pocket_weight_buyers', 'hot_pocket_weight_leads', 'hot_pocket_weight_sales'],
        'aftercall' => ['no_answer_drop_after', 'hold_days'],
        'ontrack'  => ['summary_steps', 'summary_green_from_pct', 'summary_red_below_pct', 'summary_ahead_from_pct', 'capture_back_date_working_days', 'capture_needs_review'],
        'levels'   => ['levels_signoff_required', 'level_periods_required'],
    ];
    $groupLabels = ['areas' => 'Areas', 'targets' => 'Targets and missed items', 'calllist' => 'Where the call list comes from', 'limits' => 'Limits for those sources',
        'matching' => 'Matching and list size', 'aftercall' => 'After a call', 'ontrack' => 'On-track bar and capture', 'levels' => 'Levels and sign-off'];
    $open = array_key_exists((string) request('section'), $groupLabels) ? request('section') : (($panel ?? null) ? 'calllist' : 'areas');
    foreach ($errors->keys() as $errKey) {
        foreach ($groupOf as $g => $fields) {
            if (in_array(explode('.', $errKey)[0], $fields, true)) { $open = $g; break 2; }
        }
    }
@endphp

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5" x-data="{ g: '{{ $open }}' }">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold settings</h1>
        </div>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach($warnings as $warning)
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); color: var(--text-primary);">
            {!! $warning !!}
        </div>
    @endforeach

    @if(! $canManage)
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            You can look at these settings but not change them. Ask an administrator for the "Manage Road to Sold settings" permission.
        </div>
    @endif

    <div class="rts-gen">
    <nav class="rts-gen-menu" aria-label="Settings groups">
        @foreach($groupLabels as $gid => $glabel)
            <button type="button" @click="g = '{{ $gid }}'" :class="g === '{{ $gid }}' ? 'is-on' : ''" data-group="{{ $gid }}">{{ $glabel }}</button>
        @endforeach
        @if($recent->isNotEmpty())
            <button type="button" @click="g = 'history'" :class="g === 'history' ? 'is-on' : ''" data-group="history">Recent changes</button>
        @endif
    </nav>
    <div class="rts-gen-panel">
    <form method="POST" action="{{ route($rb . 'general.update') }}" class="space-y-4" id="rts-settings-form">
        @csrf
        @method('PUT')
        <fieldset @disabled(! $canManage) class="space-y-4" style="border:0; padding:0; margin:0; min-width:0;">

        <div x-show="g === 'areas'" x-cloak>
            {{-- Areas --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Areas</h3>
                </div>
                <div class="p-5 space-y-4">
                    <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                        <input type="hidden" name="lock_agents_to_areas" value="0">
                        <input type="checkbox" name="lock_agents_to_areas" value="1" class="mt-1" @checked(old('lock_agents_to_areas', $settings->lockAgentsToAreas()))>
                        <span>
                            <span class="font-semibold">Lock agents to their areas</span><br>
                            <span style="color:var(--text-muted);">On: agents only get their own suburbs. Off: own suburbs first, then the agency's others.</span>
                        </span>
                    </label>
                    <div>
                        <div class="text-xs font-semibold mb-1" style="color:var(--text-muted);">Portals we capture from</div>
                        <input type="hidden" name="refresh_portals[]" value="">
                        <div class="flex flex-wrap gap-x-5 gap-y-1">
                            @foreach($listChoices['refresh_portals'] as $value => $choiceLabel)
                                <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                    <input type="checkbox" name="refresh_portals[]" value="{{ $value }}" @checked(in_array($value, (array) old('refresh_portals', $settings->refreshPortals()), true))>
                                    <span>{{ $choiceLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="area_refresh_warn_days">Ask for a refresh when nothing was captured for an area in</label>
                        <input id="area_refresh_warn_days" type="number" min="1" max="365" name="area_refresh_warn_days"
                               value="{{ old('area_refresh_warn_days', $settings->areaRefreshWarnDays()) }}"
                               class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs ml-2" style="color:var(--text-muted);">days (standard: {{ $defaults['area_refresh_warn_days'] }})</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="min_area_properties">A suburb is a working area from</label>
                        <input id="min_area_properties" type="number" min="1" max="1000" name="min_area_properties"
                               value="{{ old('min_area_properties', $settings->minAreaProperties()) }}"
                               class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs ml-2" style="color:var(--text-muted);">properties (your stock plus Market Intelligence adverts; standard: {{ $defaults['min_area_properties'] }})</span>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="g === 'targets'" x-cloak>
            {{-- Targets & days --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Targets and missed items</h3>
                </div>
                <div class="p-5 space-y-4">
                    <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                        <input type="hidden" name="half_day_leave_halves_target" value="0">
                        <input type="checkbox" name="half_day_leave_halves_target" value="1" class="mt-1" @checked(old('half_day_leave_halves_target', $settings->halfDayLeaveHalvesTarget()))>
                        <span>
                            <span class="font-semibold">Half-day leave halves the target</span><br>
                            <span style="color:var(--text-muted);">On: half-day leave halves that day's target. Off: the full target still applies.</span>
                        </span>
                    </label>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="public_holiday_country">Public holidays of</label>
                        <select id="public_holiday_country" name="public_holiday_country" class="w-56 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            @foreach($holidayCountries as $code => $countryName)
                                <option value="{{ $code }}" @selected(old('public_holiday_country', $settings->holidayCountry()) === $code)>{{ $countryName }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <div class="text-xs font-semibold mb-2" style="color:var(--text-muted);">Properties not worked by the end of the day</div>
                        <label class="flex items-start gap-3 text-sm mb-2" style="color:var(--text-primary);">
                            <input type="radio" name="missed_items_mode" value="show_missed" class="mt-1" @checked(old('missed_items_mode', $settings->missedItemsMode()) === 'show_missed')>
                            <span><span class="font-semibold">Show as missed</span> (standard) - they stay on that day.</span>
                        </label>
                        <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                            <input type="radio" name="missed_items_mode" value="roll_over" class="mt-1" @checked(old('missed_items_mode', $settings->missedItemsMode()) === 'roll_over')>
                            <span><span class="font-semibold">Roll over</span> - they move to the next working day.</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div x-show="g === 'calllist'" x-cloak>
            {{-- Selections --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Where the call list comes from</h3>
                </div>
                <ul class="divide-y" id="rts-selections" style="border-color:var(--border);">
                    @foreach($settings->selections() as $i => $sel)
                        <li class="flex items-center gap-3 px-5 py-2 text-sm" data-rts-row style="color:var(--text-primary);">
                            <input type="hidden" data-name="key" name="selections[{{ $i }}][key]" value="{{ $sel['key'] }}">
                            <input type="hidden" data-name="enabled-off" name="selections[{{ $i }}][enabled]" value="0">
                            <label class="flex items-center gap-2 flex-1">
                                <input type="checkbox" data-name="enabled" name="selections[{{ $i }}][enabled]" value="1" @checked($sel['enabled'])>
                                <span class="font-semibold">{{ $sel['label'] }}</span>
                            </label>
                            @if($sel['key'] === 'hot_pockets')
                                <a href="{{ route($rb . 'index', ['view' => 'pockets', 'section' => 'calllist']) }}" class="corex-btn-outline" data-qa="view-hot-pockets">View</a>
                            @endif
                            <button type="button" data-rts-move="up" title="Move up" class="inline-flex items-center justify-center w-8 h-8 rounded-md text-sm leading-none" style="border:1px solid var(--border); color:var(--text-primary);">&uarr;</button>
                            <button type="button" data-rts-move="down" title="Move down" class="inline-flex items-center justify-center w-8 h-8 rounded-md text-sm leading-none" style="border:1px solid var(--border); color:var(--text-primary);">&darr;</button>
                        </li>
                    @endforeach
                </ul>
                <div class="flex items-center gap-3 px-5 py-2 text-sm" style="border-top:1px solid var(--border); color:var(--text-primary);">
                    <span class="font-semibold flex-1">Agency standards</span>
                    <a href="{{ route($rb . 'index', ['view' => 'standards', 'section' => 'calllist']) }}" class="corex-btn-outline" data-qa="view-standards">View</a>
                </div>
            </div>
        </div>

        <div x-show="g === 'limits'" x-cloak>
            {{-- Selection thresholds --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Limits for those sources</h3>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach([
                        ['new_on_market_days', 'New on market: listed within', $settings->newOnMarketDays(), 1, 365, 'days'],
                        ['long_on_market_days', 'Long on market: more than', $settings->longOnMarketDays(), 1, 3650, 'days'],
                        ['owner_known_quantity', 'Owner already known: show up to', $settings->ownerKnownQuantity(), 0, 100, 'properties'],
                        ['stuck_stock_min_days', 'Not selling: on the market at least', $settings->stuckStockMinDaysOnMarket(), 1, 3650, 'days'],
                        ['stuck_stock_max_views', '...and fewer views than', $settings->stuckStockMaxViews(), 0, 100000, 'views'],
                        ['stuck_stock_max_leads', '...and fewer leads than', $settings->stuckStockMaxLeads(), 0, 1000, 'leads'],
                        ['stuck_stock_lookback_days', '...counted over the last', $settings->stuckStockLookbackDays(), 1, 3650, 'days'],
                        ['hot_pocket_lookback_days', 'Hottest pockets: look back', $settings->hotPocketLookbackDays(), 1, 3650, 'days'],
                        ['price_drop_lookback_days', 'Price drops: look back', $settings->priceDropLookbackDays(), 1, 3650, 'days'],
                        ['expired_competitor_lookback_days', 'Expired competitor listings: withdrawn within', $settings->expiredCompetitorLookbackDays(), 1, 365, 'days'],
                        ['prospect_stall_days', 'A prospect is stalled after', $settings->prospectStallDays(), 1, 365, 'days'],
                    ] as [$name, $label, $value, $min, $max, $unit])
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                            <input id="{{ $name }}" type="number" min="{{ $min }}" max="{{ $max }}" name="{{ $name }}" value="{{ old($name, $value) }}"
                                   class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <span class="text-xs ml-2" style="color:var(--text-muted);">{{ $unit }} (standard: {{ $defaults[$name] }})</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-show="g === 'matching'" x-cloak>
            {{-- Matching rules and list limits (the values the list builder used to hard-code) --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Matching and list size</h3>
                </div>
                <div class="p-5 space-y-4">
                    @foreach([
                        'buyer_match_tiers'   => ['Buyer matches that count', $settings->buyerMatchTiers()],
                        'active_buyer_states' => ['Buyers that count as active', $settings->activeBuyerStates()],
                        'on_market_statuses'  => ['On the market when the status is', $settings->onMarketStatuses()],
                    ] as $field => [$groupLabel, $ticked])
                        <div>
                            <div class="text-xs font-semibold mb-1" style="color:var(--text-muted);">{{ $groupLabel }}</div>
                            <input type="hidden" name="{{ $field }}[]" value="">
                            <div class="flex flex-wrap gap-x-5 gap-y-1">
                                @foreach($listChoices[$field] as $value => $choiceLabel)
                                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                        <input type="checkbox" name="{{ $field }}[]" value="{{ $value }}" @checked(in_array($value, (array) old($field, $ticked), true))>
                                        <span>{{ $choiceLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach([
                            ['add_to_day_max', 'Add to my day: most at once', $settings->addToDayMax(), 1, 500, 'properties'],
                            ['hot_pocket_weight_buyers', 'Hottest pockets: weight of a buyer', $settings->hotPocketWeightBuyers(), 0, 100, 'points'],
                            ['hot_pocket_weight_leads', 'Hottest pockets: weight of a lead', $settings->hotPocketWeightLeads(), 0, 100, 'points'],
                            ['hot_pocket_weight_sales', 'Hottest pockets: weight of a sale', $settings->hotPocketWeightSales(), 0, 100, 'points'],
                        ] as [$name, $label, $value, $min, $max, $unit])
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                                <input id="{{ $name }}" type="number" min="{{ $min }}" max="{{ $max }}" name="{{ $name }}" value="{{ old($name, $value) }}"
                                       class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <span class="text-xs ml-2" style="color:var(--text-muted);">{{ $unit }} (standard: {{ $defaults[$name] }})</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div x-show="g === 'aftercall'" x-cloak>
            {{-- Outcomes --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">After a call</h3>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach([
                        ['no_answer_drop_after', 'Drop off the list after this many no-answers in a row', $settings->noAnswerDropAfter(), 1, 20, 'calls'],
                        ['hold_days', 'Agent keeps a property after logging an outcome for', $settings->holdDays(), 1, 365, 'days'],
                    ] as [$name, $label, $value, $min, $max, $unit])
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                            <input id="{{ $name }}" type="number" min="{{ $min }}" max="{{ $max }}" name="{{ $name }}" value="{{ old($name, $value) }}"
                                   class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <span class="text-xs ml-2" style="color:var(--text-muted);">{{ $unit }} (standard: {{ $defaults[$name] }})</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div x-show="g === 'ontrack'" x-cloak>
            {{-- Summary bar --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">The on-track bar</h3>
                </div>
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($settings->summarySteps() as $i => $step)
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                <input type="hidden" name="summary_steps[{{ $i }}][key]" value="{{ $step['key'] }}">
                                <input type="hidden" name="summary_steps[{{ $i }}][enabled]" value="0">
                                <input type="checkbox" name="summary_steps[{{ $i }}][enabled]" value="1" @checked($step['enabled'])>
                                <span>{{ $step['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach([
                            ['summary_green_from_pct', 'Green when at or above', $settings->summaryGreenFromPct()],
                            ['summary_red_below_pct', 'Red when below', $settings->summaryRedBelowPct()],
                            ['summary_ahead_from_pct', 'Ahead from', $settings->summaryAheadFromPct()],
                        ] as [$name, $label, $value])
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                                <input id="{{ $name }}" type="number" min="{{ $name === 'summary_ahead_from_pct' ? 100 : 1 }}" max="{{ $name === 'summary_ahead_from_pct' ? 500 : 100 }}" name="{{ $name }}" value="{{ old($name, $value) }}"
                                       class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <span class="text-xs ml-2" style="color:var(--text-muted);">% of target (standard: {{ $defaults[$name] }})</span>
                            </div>
                        @endforeach
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="capture_back_date_working_days">Captured tasks may be back-dated by</label>
                        <input id="capture_back_date_working_days" type="number" min="1" max="60" name="capture_back_date_working_days" value="{{ old('capture_back_date_working_days', $settings->captureBackDateWorkingDays()) }}"
                               class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs ml-2" style="color:var(--text-muted);" title="Weekends and the public holidays above do not count.">working days (standard: {{ $defaults['capture_back_date_working_days'] }})</span>
                    </div>
                    </div>
                    <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                        <input type="hidden" name="capture_needs_review" value="0">
                        <input type="checkbox" name="capture_needs_review" value="1" class="mt-1" @checked(old('capture_needs_review', $settings->captureNeedsReview()))>
                        <span>
                            <span class="font-semibold">Captured tasks wait for a manager's approval</span><br>
                            <span style="color:var(--text-muted);">On: a captured task counts once a manager approves it. Off: it counts as soon as it is saved. Managers can send any captured task back either way.</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div x-show="g === 'levels'" x-cloak>
            {{-- Levels and sign-off (v2 phase 4) --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
                <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Levels and sign-off</h3>
                </div>
                <div class="p-5 space-y-4">
                    <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                        <input type="hidden" name="levels_signoff_required" value="0">
                        <input type="checkbox" name="levels_signoff_required" value="1" class="mt-1" @checked(old('levels_signoff_required', $settings->levelsSignoffRequired()))>
                        <span>
                            <span class="font-semibold">A manager signs off each level</span><br>
                            <span style="color:var(--text-muted);">On: an agent moves up only when their manager approves it. Off: they move up by themselves as soon as they have met the level for the weeks below.</span>
                        </span>
                    </label>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="level_periods_required">Weeks on target before a level is put forward</label>
                        <input id="level_periods_required" type="number" min="1" max="52" name="level_periods_required" value="{{ old('level_periods_required', $settings->levelPeriodsRequired()) }}"
                               class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <span class="text-xs ml-2" style="color:var(--text-muted);">weeks in a row (standard: {{ $defaults['level_periods_required'] }})</span>
                    </div>
                </div>
            </div>
        </div>

        </fieldset>

        @if($canManage)
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center justify-center h-10 px-5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9); border:1px solid var(--brand-icon, #0ea5e9);">Save settings</button>
                <button type="submit" form="rts-reset-form" class="inline-flex items-center justify-center h-10 px-5 rounded-md text-sm font-semibold" style="border:1px solid var(--border); color:var(--text-primary);">Put everything back to the defaults</button>
            </div>
        @endif
    </form>

    @if($canManage)
        <form id="rts-reset-form" method="POST" action="{{ route($rb . 'general.reset') }}"
              onsubmit="return confirm('Put every Road to Sold setting back to the standard defaults? Suburbs and individual weekday numbers are not affected.');">
            @csrf
        </form>
    @endif

    @if($recent->isNotEmpty())
        <div x-show="g === 'history'" x-cloak style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Recent changes</h3>
            </div>
            <ul class="divide-y text-xs" style="border-color:var(--border); color:var(--text-muted);">
                @foreach($recent as $entry)
                    <li class="px-5 py-2">
                        {{ $entry->changed_at?->format('j M Y H:i') }} -
                        {{ $entry->changedBy?->name ?? 'Someone' }} changed
                        {{ collect(array_keys($entry->new_values ?? []))->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ') }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
    </div>
    </div>
</div>

@if(($panel ?? null) === 'pockets' || ($panel ?? null) === 'standards')
    @php $closeUrl = route($rb . 'index', ['section' => 'calllist']); @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.45);" data-qa="rts-view-modal"
         x-data @keydown.escape.window="window.location = '{{ $closeUrl }}'">
        <div class="w-full max-w-3xl rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border); max-height:88vh; display:flex; flex-direction:column;">
            <div class="flex items-center justify-between px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">{{ $panel === 'pockets' ? 'Hottest sales pockets' : 'Agency standards' }}</h3>
                <a href="{{ $closeUrl }}" class="corex-btn-outline" data-qa="rts-view-close">Close</a>
            </div>
            <div class="overflow-auto">
            @if($panel === 'pockets')
                <table class="w-full text-sm" style="color:var(--text-primary);" data-qa="pockets-table">
                    <thead><tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-4 py-2">Suburb</th>
                        <th class="px-3 py-2 text-right">Buyers matched</th>
                        <th class="px-3 py-2 text-right">Leads</th>
                        <th class="px-3 py-2 text-right">Sales, last {{ $settings->hotPocketLookbackDays() }} days</th>
                        <th class="px-4 py-2 text-right">Score</th>
                    </tr></thead>
                    <tbody>
                    @forelse($pockets as $pocket)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-2 font-semibold">{{ $pocket['label'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $pocket['buyers'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $pocket['leads'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $pocket['sales'] }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ $pocket['score'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center" style="color:var(--text-muted);">No suburb scores above zero yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            @else
                <table class="w-full text-sm" style="color:var(--text-primary);" data-qa="standards-table">
                    <thead><tr class="text-left text-xs uppercase tracking-wide" style="background:var(--surface-2); border-bottom:1px solid var(--border); color:var(--text-muted);">
                        <th class="px-4 py-2">Ratio</th>
                        <th class="px-3 py-2 text-right">Agency median</th>
                        <th class="px-3 py-2 text-right">Flagged below</th>
                        <th class="px-4 py-2 text-right">Agents counted</th>
                    </tr></thead>
                    <tbody>
                    @foreach($standards['rows'] as $row)
                        <tr style="border-bottom:1px solid var(--border);">
                            <td class="px-4 py-2 font-semibold">{{ $row['label'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['median'] === null ? 'not enough data yet' : rtrim(rtrim(number_format($row['median'], 1), '0'), '.') . '%' }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['flagged_below'] === null ? '-' : rtrim(rtrim(number_format($row['flagged_below'], 1), '0'), '.') . '%' }}</td>
                            <td class="px-4 py-2 text-right">{{ $row['agents'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="4" class="px-4 py-2 text-xs" style="color:var(--text-muted);">Last {{ $standards['days'] }} days, agents with at least {{ $standards['min_sample'] }} events counted; flagged below {{ $standards['below_pct'] }}% of the median.</td></tr></tfoot>
                </table>
            @endif
            </div>
        </div>
    </div>
@endif

<style>
    .rts-gen { display: grid; grid-template-columns: 14rem minmax(0, 1fr); gap: 1.25rem; align-items: start; }
    .rts-gen-menu { display: flex; flex-direction: column; gap: .25rem; position: sticky; top: 1rem; }
    .rts-gen-menu button { text-align: left; padding: .5rem .75rem; border-radius: 6px; font-size: .8125rem; font-weight: 600; background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); }
    .rts-gen-menu button.is-on { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; }
    @media (max-width: 767px) { .rts-gen { grid-template-columns: minmax(0, 1fr); } .rts-gen-menu { position: static; flex-direction: row; flex-wrap: wrap; } }
</style>

<script>
(function () {
    var list = document.getElementById('rts-selections');
    if (!list) { return; }
    function reindex() {
        var rows = list.querySelectorAll('[data-rts-row]');
        for (var i = 0; i < rows.length; i++) {
            var fields = rows[i].querySelectorAll('[data-name]');
            for (var f = 0; f < fields.length; f++) {
                var kind = fields[f].getAttribute('data-name');
                var leaf = (kind === 'key') ? 'key' : 'enabled';
                fields[f].setAttribute('name', 'selections[' + i + '][' + leaf + ']');
            }
        }
    }
    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rts-move]');
        if (!btn) { return; }
        var row = btn.closest('[data-rts-row]');
        if (btn.getAttribute('data-rts-move') === 'up' && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (btn.getAttribute('data-rts-move') === 'down' && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
        reindex();
    });
})();
</script>
@endsection
