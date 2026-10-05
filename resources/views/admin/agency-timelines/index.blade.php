{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — platform-owner Agency Timeline index (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $sortLink = fn ($key, $label) => '<a href="' . e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => ($sort === $key && $dir === 'asc') ? 'desc' : 'asc', 'page' => null])) . '" style="color:inherit;">' . e($label) . ($sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a>';
    $tones = ['not_started' => ['Not started', 'var(--text-muted)'], 'running' => ['Running', 'var(--brand-icon)'], 'live' => ['Live', 'var(--ds-green)'], 'paused' => ['Paused', 'var(--ds-amber)']];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Agency Timeline</h1>
        <p class="text-xs" style="color: var(--text-muted);">The onboarding plan for each agency, from take-on to going live. Start one, add custom steps, and share the public link with the agency. Defaults are edited in <a href="{{ route('admin.timeline-defaults.index') }}" class="underline">Dev Settings → Agency timeline defaults</a>.</p>
    </div>

    <div class="corex-kpi-grid">
        <x-corex-kpi-card title="Agencies" :value="number_format($kpis['total'])" />
        <x-corex-kpi-card title="Running" :value="number_format($kpis['running'])" />
        <x-corex-kpi-card title="Live" :value="number_format($kpis['live'])" />
        <x-corex-kpi-card title="With overdue steps" :value="number_format($kpis['overdue'])" />
    </div>

    @include('admin.partials.platform-flash')

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);">
        <div>
            <label class="ds-label block mb-1">Search agency</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Agency name…" class="ds-field" style="min-width:14rem;">
        </div>
        <div>
            <label class="ds-label block mb-1">Status</label>
            <select name="status" class="ds-field">
                <option value="">All</option>
                @foreach($tones as $k => [$label])<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <button class="corex-btn-primary text-xs" type="submit">Filter</button>
        @if(request()->hasAny(['q', 'status']))<a href="{{ route('admin.agency-timelines.index') }}" class="text-xs underline" style="color:var(--text-muted);">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{!! $sortLink('agency', 'Agency') !!}</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Progress</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Next due</th>
                        <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{!! $sortLink('overdue', 'Overdue') !!}</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{!! $sortLink('start', 'Start') !!}</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{!! $sortLink('go_live', 'Go live') !!}</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $r)
                    @php [$label, $tone] = $tones[$r['status']]; $a = $r['agency']; @endphp
                    <tr>
                        <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">
                            {{ $a->name }}
                            @if($a->is_demo)<span class="ds-badge ds-badge-default ml-1">Demo</span>@endif
                            @unless($a->is_active)<span class="ds-badge ds-badge-default ml-1">Inactive</span>@endunless
                        </td>
                        <td class="px-4 py-3"><span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background: color-mix(in srgb, {{ $tone }} 14%, transparent); color: {{ $tone }};">{{ $label }}</span></td>
                        <td class="px-4 py-3" style="min-width:140px;">
                            @if($r['timeline'])
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-1.5 rounded-full overflow-hidden" style="background:var(--surface-2);"><div class="h-1.5 rounded-full" style="width: {{ $r['total'] ? round($r['done'] / $r['total'] * 100) : 0 }}%; background: var(--brand-icon);"></div></div>
                                    <span class="text-xs tabular-nums" style="color:var(--text-muted);">{{ $r['done'] }}/{{ $r['total'] }}</span>
                                </div>
                            @else <span style="color:var(--text-muted);">—</span> @endif
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $r['next'] ? $r['next']->title . ' · ' . $r['next']->due_date->format('j M') : '—' }}</td>
                        <td class="px-4 py-3 text-center">@if($r['overdue'])<span class="ds-badge ds-badge-danger">{{ $r['overdue'] }}</span>@else<span style="color:var(--text-muted);">0</span>@endif</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $r['start']?->format('j M Y') ?? '—' }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">
                            @if($r['expected'])
                                {{ $r['expected']->format('j M Y') }}
                                @if($r['slip'])<span class="block text-xs" style="color:var(--ds-crimson);">pushed {{ $r['slip'] }}d (was {{ $r['planned']->format('j M') }})</span>@endif
                            @else — @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($r['timeline'])
                                <a href="{{ route('admin.agency-timelines.show', $r['timeline']) }}" class="text-xs font-semibold" style="color:var(--brand-icon);">Open</a>
                                @if($r['timeline']->public_link_enabled)
                                    <button type="button" class="text-xs font-semibold ml-3" style="color:var(--brand-icon);"
                                            x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($r['timeline']->publicUrl())); done = true; setTimeout(() => done = false, 1800)"
                                            x-text="done ? 'Copied ✓' : 'Copy link'"></button>
                                @endif
                            @else
                                <a href="{{ route('admin.agency-timelines.start-form', $a) }}" class="corex-btn-primary text-xs">Start timeline</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center" style="color:var(--text-muted);">No agencies match. @if(request()->hasAny(['q','status']))<a href="{{ route('admin.agency-timelines.index') }}" class="underline">Clear the filters</a>.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $rows->links() }}
</div>
@endsection
