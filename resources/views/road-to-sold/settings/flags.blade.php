@extends('layouts.corex')

{{--
    AT-454 v2 phase 5 - Road to Sold settings -> Training & flags. Spec: .ai/specs/road-to-sold.md sections 9.3, 9.4, 13.1.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Needs $tabs, $settings, $rows (array: key, label, skill, pointer, available, archived), $choices (kind => [id => title]), $filters, $track.
    Screen-text rule (Johan): every line is data or a control.
--}}

@php
    $fieldStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $rb = \App\Services\RoadToSold\Support\Routing::base();
    $kinds = \App\Models\RoadToSold\RtsTrainingPointer::KINDS;
    $f = $filters;
    $filtered = ($f['q'] ?? '') !== '' || $f['show'] !== 'all';
    $defaults = \App\Models\RoadToSold\RtsSetting::SCALAR_DEFAULTS;
@endphp

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5 cx-equal-btns">

    <div class="rounded-md px-6 py-5 corex-page-banner flex items-center justify-between flex-wrap gap-3">
        <h1 class="text-xl font-bold text-white leading-tight">Road to Sold - training and flags</h1>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route($rb . 'general.update') }}" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @csrf @method('PUT')
        <input type="hidden" name="back_to" value="flags">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);"><h3 class="text-sm font-bold" style="color:var(--text-primary);">When a ratio is flagged, and how targets are suggested</h3></div>
        <div class="p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                ['flag_min_sample', 'Fewest events before a ratio can be flagged', $settings->flagMinSample(), 1, 1000, 'events'],
                ['flag_below_pct', 'Flag a ratio below', $settings->flagBelowPct(), 1, 100, '% of the agency median'],
                ['flag_window_days', 'The funnel and its flags look back', $settings->flagWindowDays(), 7, 366, 'days'],
                ['suggest_window_weeks', 'A suggested target learns from', $settings->suggestWindowWeeks(), 2, 52, 'weeks'],
                ['suggest_growth_pct', 'A suggested target allows growth of', $settings->suggestGrowthPct(), 0, 100, '%'],
            ] as [$name, $label, $value, $min, $max, $unit])
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="{{ $name }}">{{ $label }}</label>
                    <input id="{{ $name }}" type="number" name="{{ $name }}" min="{{ $min }}" max="{{ $max }}" value="{{ old($name, $value) }}" class="w-28 rounded-md px-3 py-2 text-sm" style="{{ $fieldStyle }}">
                    <span class="text-xs ml-2" style="color:var(--text-muted);">{{ $unit }} (standard: {{ $defaults[$name] }})</span>
                </div>
            @endforeach
        </div>
        <div class="px-5 pb-5"><button type="submit" class="inline-flex items-center justify-center h-10 px-5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-icon, #0ea5e9); border:1px solid var(--brand-icon, #0ea5e9);">Save</button></div>
    </form>

    <form method="GET" action="{{ route($rb . 'flags.index') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tp-q">Search</label>
            <input id="tp-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Ratio or skill" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }} min-width:14rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);" for="tp-show">Show</label>
            <select id="tp-show" name="show" class="rounded-md px-3 py-2 text-xs" style="{{ $fieldStyle }}" onchange="this.form.submit()">
                @foreach(['all' => 'Every ratio', 'chosen' => 'Training chosen', 'none' => 'No training yet', 'archived' => 'Archived'] as $k => $l)<option value="{{ $k }}" @selected($f['show'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route($rb . 'flags.index') }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background:var(--surface); border:1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color:var(--text-muted);">
                    <th class="px-3 py-2 text-left">Ratio</th>
                    <th class="px-3 py-2 text-left">Skill it points at</th>
                    <th class="px-3 py-2 text-left">Training</th>
                    <th class="px-3 py-2 text-left">Choose</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                <tr style="border-top:1px solid var(--border);" x-data="{ kind: '{{ $r['pointer']->kind ?? 'course' }}' }">
                    <td class="px-3 py-2 font-semibold" style="color:var(--text-primary);">{{ $r['label'] }}</td>
                    <td class="px-3 py-2" style="color:var(--text-primary);">{{ $r['skill'] }}</td>
                    <td class="px-3 py-2" style="color:var(--text-primary);">
                        @if($r['pointer'])
                            {{ $kinds[$r['pointer']->kind] }}: {{ $r['pointer']->label }}
                            @if($r['available'] === false)<div style="color:var(--text-muted);">No longer available - choose again</div>@endif
                        @elseif($r['archived'])
                            <span style="color:var(--text-muted);">Archived: {{ $r['archived']->label }}</span>
                        @else
                            <span style="color:var(--text-muted);">None chosen</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <form method="POST" action="{{ route($rb . 'flags.pointer.save') }}" class="flex items-center gap-2 flex-wrap">@csrf
                            <input type="hidden" name="ratio_key" value="{{ $r['key'] }}">
                            <label class="sr-only" for="kind-{{ $r['key'] }}">Kind of training</label>
                            <select id="kind-{{ $r['key'] }}" name="kind" x-model="kind" class="rounded-md px-2 py-1 text-xs" style="{{ $fieldStyle }}">
                                @foreach($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                            @foreach($kinds as $k => $l)
                                <template x-if="kind === '{{ $k }}'">
                                    <span>
                                        <label class="sr-only" for="t-{{ $r['key'] }}-{{ $k }}">{{ $l }}</label>
                                        <select id="t-{{ $r['key'] }}-{{ $k }}" name="target" class="rounded-md px-2 py-1 text-xs" style="{{ $fieldStyle }} max-width:16rem;" required>
                                            <option value="">Choose...</option>
                                            @foreach($choices[$k] ?? [] as $id => $title)<option value="{{ $id }}" @selected($r['pointer'] && $r['pointer']->kind === $k && (string) ($r['pointer']->target_id ?? $r['pointer']->target_key) === (string) $id)>{{ $title }}</option>@endforeach
                                        </select>
                                    </span>
                                </template>
                            @endforeach
                            <button type="submit" class="corex-btn-primary corex-btn-xs">Save</button>
                        </form>
                    </td>
                    <td class="px-3 py-2 text-right">
                        @if($r['pointer'])
                            <form method="POST" action="{{ route($rb . 'flags.pointer.archive', $r['key']) }}" onsubmit="return confirm('Remove the training for this skill? You can bring it back from the Archived list.');">@csrf @method('DELETE')<button type="submit" class="corex-btn-outline corex-btn-xs">Archive</button></form>
                        @elseif($r['archived'])
                            <form method="POST" action="{{ route($rb . 'flags.pointer.restore', $r['key']) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Restore</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-6 py-12 text-center">
                    <div class="text-base font-semibold" style="color:var(--text-primary);">Nothing matches this search.</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);">Clear the search or change the filter to see more.</div>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
