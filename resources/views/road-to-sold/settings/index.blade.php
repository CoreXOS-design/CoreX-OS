@extends('layouts.corex')

{{--
    AT-454 - Road to Sold settings hub (General tab).
    Spec: .ai/specs/road-to-sold.md section 4 / 5.1.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    No Alpine on this page: the reorder buttons are plain JS at the bottom.
--}}

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Road to Sold settings</h1>
            <p class="text-sm text-white/60">How the daily call list is built, how targets are measured, and when an agent is shown as on track.</p>
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

    @unless($settings->isCustomised())
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            You have not changed anything yet - everything below shows the standard defaults, and they are already working for your agents.
        </div>
    @endunless

    @if(! $canManage)
        <div class="rounded-md px-4 py-3 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
            You can look at these settings but not change them. Ask an administrator for the "Manage Road to Sold settings" permission.
        </div>
    @endif

    <form method="POST" action="{{ route('road-to-sold.settings.general.update') }}" class="space-y-4" id="rts-settings-form">
        @csrf
        @method('PUT')
        <fieldset @disabled(! $canManage) class="space-y-4" style="border:0; padding:0; margin:0; min-width:0;">

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
                        <span style="color:var(--text-muted);">Off (recommended): each agent's own suburbs come first, then the list carries on into your agency's other suburbs if the day is not full. On: agents only ever get properties from their own suburbs - an agent with no suburbs gets no list.</span>
                    </span>
                </label>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="area_refresh_warn_days">Ask for a refresh when an area was last captured more than this many days ago</label>
                    <input id="area_refresh_warn_days" type="number" min="1" max="365" name="area_refresh_warn_days"
                           value="{{ old('area_refresh_warn_days', $settings->areaRefreshWarnDays()) }}"
                           class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <span class="text-xs ml-2" style="color:var(--text-muted);">days (standard: {{ $defaults['area_refresh_warn_days'] }}). Refreshing an area stays a manual job.</span>
                </div>
            </div>
        </div>

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
                        <span style="color:var(--text-muted);">Off: an agent on approved half-day leave still owes the full target that day. On: that day's target is halved (rounded to the nearest whole number). Whole days of leave and public holidays never carry a target.</span>
                    </span>
                </label>
                <div>
                    <div class="text-xs font-semibold mb-2" style="color:var(--text-muted);">Properties an agent did not get to by the end of the day</div>
                    <label class="flex items-start gap-3 text-sm mb-2" style="color:var(--text-primary);">
                        <input type="radio" name="missed_items_mode" value="show_missed" class="mt-1" @checked(old('missed_items_mode', $settings->missedItemsMode()) === 'show_missed')>
                        <span><span class="font-semibold">Show them as missed</span> (standard) - they stay on the day they were meant for and count against that day.</span>
                    </label>
                    <label class="flex items-start gap-3 text-sm" style="color:var(--text-primary);">
                        <input type="radio" name="missed_items_mode" value="roll_over" class="mt-1" @checked(old('missed_items_mode', $settings->missedItemsMode()) === 'roll_over')>
                        <span><span class="font-semibold">Roll them over</span> - they move to the agent's next working day.</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Selections --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Where the call list comes from</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Follow-ups due today always come first. After that CoreX fills the day from the sources below, top to bottom, until the agent's target is reached. Switch a source off to skip it; move it up or down to change the order.</p>
            </div>
            <ul class="divide-y" id="rts-selections" style="border-color:var(--border);">
                @foreach($settings->selections() as $i => $sel)
                    <li class="flex items-center gap-3 px-5 py-3 text-sm" data-rts-row style="color:var(--text-primary);">
                        <input type="hidden" data-name="key" name="selections[{{ $i }}][key]" value="{{ $sel['key'] }}">
                        <input type="hidden" data-name="enabled-off" name="selections[{{ $i }}][enabled]" value="0">
                        <label class="flex items-center gap-2 flex-1">
                            <input type="checkbox" data-name="enabled" name="selections[{{ $i }}][enabled]" value="1" @checked($sel['enabled'])>
                            <span class="font-semibold">{{ $sel['label'] }}</span>
                        </label>
                        <button type="button" data-rts-move="up" title="Move up" class="px-2 py-1 rounded-md text-xs" style="border:1px solid var(--border);">&uarr;</button>
                        <button type="button" data-rts-move="down" title="Move down" class="px-2 py-1 rounded-md text-xs" style="border:1px solid var(--border);">&darr;</button>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Selection thresholds --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Limits for those sources</h3>
            </div>
            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach([
                    ['new_on_market_days', 'New on market: listed within this many days', $settings->newOnMarketDays(), 1, 365, 'days'],
                    ['long_on_market_days', 'Long on market: on the market for more than this many days', $settings->longOnMarketDays(), 1, 3650, 'days'],
                    ['owner_known_quantity', 'Owner already known: how many to show each day', $settings->ownerKnownQuantity(), 0, 100, 'properties'],
                    ['stuck_stock_min_days', 'My stock not selling: on the market for at least', $settings->stuckStockMinDaysOnMarket(), 1, 3650, 'days'],
                    ['stuck_stock_max_views', '...and fewer views than', $settings->stuckStockMaxViews(), 0, 100000, 'views'],
                    ['stuck_stock_max_leads', '...and fewer leads than', $settings->stuckStockMaxLeads(), 0, 1000, 'leads'],
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

        {{-- Outcomes --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">After a call</h3>
            </div>
            <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach([
                    ['no_answer_drop_after', 'Drop a property off the agent\'s list after this many no-answers in a row', $settings->noAnswerDropAfter(), 1, 20, 'calls'],
                    ['hold_days', 'An agent keeps a property for this long after logging an outcome', $settings->holdDays(), 1, 365, 'days'],
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

        {{-- Summary bar --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">The on-track bar</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Each step compares what an agent should have done by today this month with what they have actually done.</p>
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
                    ] as [$name, $label, $value])
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                            <input id="{{ $name }}" type="number" min="1" max="100" name="{{ $name }}" value="{{ old($name, $value) }}"
                                   class="w-28 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <span class="text-xs ml-2" style="color:var(--text-muted);">% of target (standard: {{ $defaults[$name] }})</span>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs" style="color:var(--text-muted);">In between the two numbers the bar shows amber.</p>
            </div>
        </div>

        </fieldset>

        @if($canManage)
            <div class="flex items-center justify-between gap-3">
                <button type="submit" class="px-5 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9);">Save settings</button>
            </div>
        @endif
    </form>

    @if($canManage)
        <form method="POST" action="{{ route('road-to-sold.settings.general.reset') }}"
              onsubmit="return confirm('Put every Road to Sold setting back to the standard defaults? Areas and targets are not affected.');">
            @csrf
            <button type="submit" class="text-xs underline" style="color:var(--text-muted);">Put everything back to the defaults</button>
        </form>
    @endif

    @if($recent->isNotEmpty())
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
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
