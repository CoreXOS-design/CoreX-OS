{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold v4 (road-to-sold.md 23.25 / 23.11): the agency's off-ramp reasons for "Stops here (say why)" on every step. Excel-style rows, same layout as the results list. --}}
@extends('layouts.corex-app')
@section('title', 'Stops here - reasons per step')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $trackParams = \App\Services\RoadToSold\Track::params($track);
@endphp
<div class="w-full space-y-5 rts-page" data-qa="offramp-settings">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Stops here - reasons per step</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">What an agent can say when a property stops: on Today's work (Swap, "Not for me") or at one of the steps - Owner, Contact, Appointment, Presentation, Results, Draft, Active. "Other" ends every step's list and always asks for a few words. A reason that counts as <strong>Not selling</strong> takes the property off everyone's daily lists and marks it Not selling; one that counts as <strong>Lost</strong> closes the claim as lost to another agency. "Back in" is the days until it comes back; 0 means never.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('road-to-sold.settings.results.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Results</a>
                <a href="{{ route('road-to-sold.settings.outcomes.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Outcomes</a>
                <a href="{{ route('road-to-sold.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
            </div>
        </div>
    </div>

    @include('road-to-sold.settings._tabs', ['tabs' => $tabs])

    @if(session('status'))<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $errors->first() }}</div>@endif

    @unless($ready)
        <div class="rounded-md px-6 py-8 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" data-qa="offramp-not-ready">The reason list by step is not switched on yet - the standard reasons are in use.</div>
    @else
    <form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="offramp-filters">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="oq">Search</label>
            <input id="oq" name="q" value="{{ $q }}" placeholder="Words or code" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="ost">Step</label>
            <select id="ost" name="step" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="0">All steps</option>
                @foreach($steps as $n => $l)<option value="{{ $n }}" @selected($stepFilter === $n)>{{ $l }}</option>@endforeach
                <option value="-1" @selected($stepFilter === -1)>Not in the popup</option>
            </select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="os">Show</label>
            <select id="os" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['all' => 'All', 'active' => 'On', 'off' => 'Switched off', 'archived' => 'Archived'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="oo">Sort</label>
            <select id="oo" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['step' => 'Step', 'order' => 'Order on the screen', 'label' => 'Words', 'code' => 'Code'] as $v => $l)<option value="{{ $v }}" @selected($sort === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <button class="corex-btn-outline corex-btn-xs" type="submit">Search</button>
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead><tr style="color: var(--text-muted);"><th class="px-3 py-2 text-left">Code</th><th class="px-3 py-2 text-left">Words</th><th class="px-3 py-2 text-left">Step</th><th class="px-3 py-2 text-left">Counts as</th><th class="px-3 py-2 text-left">Back in (days)</th><th class="px-3 py-2 text-left">On</th><th class="px-3 py-2 text-right"></th></tr></thead>
            <tbody>
            @forelse($rows as $r)
                @php $fid = 'orf' . $r->id; $locked = (bool) $r->deleted_at; @endphp
                <tr style="border-top: 1px solid var(--border);@if($r->deleted_at) opacity:.6;@endif" data-qa="offramp-row" data-key="{{ $r->key }}">
                    <td class="px-3 py-2" style="color: var(--text-muted);"><code>{{ $r->key }}</code>@if($r->is_other) <span title="Every step's list ends with this one">(Other)</span>@endif</td>
                    <td class="px-3 py-2"><input form="{{ $fid }}" name="label" value="{{ $r->label }}" maxlength="120" required class="w-full rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($locked)></td>
                    <td class="px-3 py-2">
                        @if($r->is_other || $locked)<span>{{ $r->step ? ($steps[$r->step] ?? ($steps[4] ?? '')) : 'Not in the popup' }}</span><input form="{{ $fid }}" type="hidden" name="step" value="{{ $r->step }}">
                        @else<select form="{{ $fid }}" name="step" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}"><option value="">Not in the popup</option>@foreach($steps as $n => $l)<option value="{{ $n }}" @selected((int) $r->step === $n)>{{ $l }}</option>@endforeach</select>@endif
                    </td>
                    <td class="px-3 py-2"><select form="{{ $fid }}" name="counts_as" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($locked)>@foreach($countsAs as $v => $l)<option value="{{ $v }}" @selected(($r->counts_as ?? 'lost') === $v)>{{ $l }}</option>@endforeach</select></td>
                    <td class="px-3 py-2"><input form="{{ $fid }}" name="revisit_days" type="number" min="0" max="730" value="{{ $r->default_revisit_days }}" class="w-20 rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($locked)></td>
                    <td class="px-3 py-2">{{ $r->deleted_at ? 'Archived' : ($r->is_active ? 'Yes' : 'Switched off') }}</td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <form id="{{ $fid }}" method="POST" action="{{ route('road-to-sold.settings.offramps.update', $r->id) }}">@csrf @method('PUT')@unless($locked)<button class="corex-btn-primary corex-btn-xs" type="submit">Save</button>@endunless</form>
                            @if($r->deleted_at)
                                <form method="POST" action="{{ route('road-to-sold.settings.offramps.restore', $r->id) }}">@csrf<button class="corex-btn-outline corex-btn-xs" type="submit">Restore</button></form>
                            @elseif(! $r->is_other)
                                <form method="POST" action="{{ route('road-to-sold.settings.offramps.toggle', $r->id) }}">@csrf<button class="corex-btn-outline corex-btn-xs" type="submit">{{ $r->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                                <form method="POST" action="{{ route('road-to-sold.settings.offramps.archive', $r->id) }}" onsubmit="return confirm('Archive this reason? Records that used it keep its wording.')">@csrf @method('DELETE')<button class="corex-btn-outline corex-btn-xs" type="submit">Archive</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-6 py-10 text-center text-sm" style="color: var(--text-primary);" data-qa="offramp-empty">@if($q !== '' || $status !== 'all' || $stepFilter !== 0)Nothing matches this search.@else No reasons yet.@endif</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if(method_exists($rows, 'links')){{ $rows->links() }}@endif

    <form method="POST" action="{{ route('road-to-sold.settings.offramps.store') }}" class="rounded-md p-4 flex items-end gap-3 flex-wrap" style="background: var(--surface); border: 1px solid var(--border);" data-qa="offramp-add">
        @csrf
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div class="grow"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nl">Add a reason</label>
            <input id="nl" name="label" required maxlength="120" placeholder="e.g. Seller emigrating" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="ns">Step</label>
            <select id="ns" name="step" required class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"><option value="">Pick a step</option>@foreach($steps as $n => $l)<option value="{{ $n }}">{{ $l }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nc">Counts as</label>
            <select id="nc" name="counts_as" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($countsAs as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nd">Back in (days)</label>
            <input id="nd" name="revisit_days" type="number" min="0" max="730" class="w-24 rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <button class="corex-btn-primary corex-btn-xs" type="submit">Add</button>
    </form>
    @endunless
</div>
@endsection
