{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 5 - training flags: an agent whose ratio is well below the agency median. Derived on every view, stored nowhere. Needs $rows (paginator of
     arrays: agent, ratio, label, step, skill, flag, action, training, has_pointer), $filters, $steps, $settings, $period, $days, $medians, $track, $viewer, $tab. --}}
@extends('layouts.corex-app')
@section('title', 'Training flags')

@section('corex-content')
@php
    $f = $filters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $anyMedian = collect($medians)->filter(fn ($m) => $m !== null)->isNotEmpty();
    $filtered = ($f['q'] ?? '') !== '' || $f['status'] !== 'open' || ($f['step'] ?? '') !== '';
@endphp

<div class="w-full space-y-4 rts-page" x-data="{ noting: null }">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Training flags</h1>
                <span class="text-xs" style="color: var(--text-primary);">Last {{ $days }} days, to {{ $period->end->format('j M Y') }}</span>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ $rows->total() }}</strong> shown</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Road to Sold</a>
                @if(\Illuminate\Support\Facades\Route::has('road-to-sold.settings.flags.index') && $viewer->hasPermission('road_to_sold.manage_settings'))
                    <a href="{{ route('road-to-sold.settings.flags.index') }}" class="corex-btn-outline corex-btn-xs">Settings</a>
                @endif
            </div>
        </div>
    </div>
    @include('road-to-sold.funnel._tabs')

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            @foreach($errors->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('road-to-sold.funnel.flags') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fl-q">Search</label>
            <input id="fl-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent or skill" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 14rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fl-status">Status</label>
            <select id="fl-status" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['open' => 'Open', 'acted' => 'Acted on', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($f['status'] === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fl-step">Step</label>
            <select id="fl-step" name="step" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="">All steps</option>
                @foreach($steps as $k => $l)<option value="{{ $k }}" @selected(($f['step'] ?? '') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fl-sort">Sort</label>
            <select id="fl-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="severity" @selected($f['sort'] === 'severity')>Furthest below the median</option>
                <option value="agent" @selected($f['sort'] === 'agent')>Agent</option>
                <option value="skill" @selected($f['sort'] === 'skill')>Skill</option>
            </select>
        </div>
        <div>
            <select name="dir" aria-label="Sort direction" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="desc" @selected($f['dir'] === 'desc')>Highest first</option>
                <option value="asc" @selected($f['dir'] === 'asc')>Lowest first</option>
            </select>
        </div>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route('road-to-sold.funnel.flags', $params) }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Branch</th>
                    <th class="px-3 py-2 text-left">Flag</th>
                    <th class="px-3 py-2 text-left">Ratio</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left">Training</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($rows as $r)
                @php $key = $r['agent']->id . '-' . $r['ratio']; @endphp
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $r['agent']->name }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $r['agent']->branch?->name ?? '-' }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ \App\Services\RoadToSold\FunnelService::flagText($r['ratio']) }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        {{ $r['label'] }}: <strong>{{ $r['flag']['pct'] }}%</strong>
                        <div style="color: var(--text-muted);">agency median {{ $r['flag']['median'] }}% - {{ number_format($r['flag']['num']) }} of {{ number_format($r['flag']['den']) }}</div>
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($r['action'])
                            {{ match ($r['action']->kind) { 'training_assigned' => 'Training assigned', 'coaching_note' => 'Coaching note added', default => 'Marked as seen' } }}
                            <div style="color: var(--text-muted);">{{ $r['action']->by?->name }}, {{ \App\Support\AppDate::dateTime($r['action']->acted_at) }}@if($r['action']->kind === 'coaching_note' && $r['action']->note) - "{{ $r['action']->note }}"@endif</div>
                        @else
                            Open
                        @endif
                    </td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">
                        @if($r['training'])<a href="{{ $r['training']['url'] }}" class="underline">{{ $r['training']['label'] }}</a>@elseif($r['has_pointer'])<span style="color: var(--text-muted);">No longer available</span>@else<span style="color: var(--text-muted);">None chosen</span>@endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2 flex-wrap">
                            @if($r['training'])
                                <form method="POST" action="{{ route('road-to-sold.funnel.assign', $params) }}">@csrf
                                    <input type="hidden" name="agent_id" value="{{ $r['agent']->id }}"><input type="hidden" name="ratio_key" value="{{ $r['ratio'] }}">
                                    <button type="submit" class="corex-btn-primary corex-btn-xs">Assign training</button>
                                </form>
                            @endif
                            <button type="button" class="corex-btn-outline corex-btn-xs" @click="noting = noting === '{{ $key }}' ? null : '{{ $key }}'">Add note</button>
                            <form method="POST" action="{{ route('road-to-sold.funnel.acknowledge', $params) }}">@csrf
                                <input type="hidden" name="agent_id" value="{{ $r['agent']->id }}"><input type="hidden" name="ratio_key" value="{{ $r['ratio'] }}">
                                <button type="submit" class="corex-btn-outline corex-btn-xs">Mark as seen</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <tr x-show="noting === '{{ $key }}'" x-cloak style="border-top: 1px solid var(--border);">
                    <td colspan="7" class="px-3 py-3">
                        <form method="POST" action="{{ route('road-to-sold.funnel.note', $params) }}" class="flex items-start gap-2 flex-wrap">@csrf
                            <input type="hidden" name="agent_id" value="{{ $r['agent']->id }}"><input type="hidden" name="ratio_key" value="{{ $r['ratio'] }}">
                            <label class="sr-only" for="note-{{ $key }}">Coaching note</label>
                            <textarea id="note-{{ $key }}" name="note" rows="2" maxlength="2000" required placeholder="What did you cover with {{ $r['agent']->name }}?" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }} min-width: 22rem;"></textarea>
                            <button type="submit" class="corex-btn-primary corex-btn-xs">Save note</button>
                            <button type="button" class="corex-btn-outline corex-btn-xs" @click="noting = null">Cancel</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $filtered ? 'Nothing matches this search.' : ($anyMedian ? 'No one is flagged.' : 'Not enough data yet.') }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">
                            {{ $filtered ? 'Clear the search or change a filter to see more.' : ($anyMedian
                                ? 'No agent is below ' . $settings->flagBelowPct() . '% of the agency median with at least ' . $settings->flagMinSample() . ' events behind it.'
                                : 'A ratio needs at least ' . $settings->flagMinSample() . ' events in the window for ' . \App\Services\RoadToSold\FunnelService::MIN_AGENTS_FOR_MEDIAN . ' or more agents before the agency\'s own median means anything.') }}
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
@endsection
