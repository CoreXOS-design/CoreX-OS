{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold results')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $trackParams = \App\Services\RoadToSold\Track::params($track);
@endphp
<div class="w-full space-y-5 rts-page" data-qa="result-settings">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold results</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">One list of answers to "what happened at the appointment or presentation?". The appointment feedback form, the presentation panel and the card on Road to Sold all use it, so a result is recorded once and agrees everywhere.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('road-to-sold.settings.offramps.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Stops here</a>
                <a href="{{ route('road-to-sold.settings.outcomes.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Outcomes</a>
                <a href="{{ route('road-to-sold.index', $trackParams) }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
            </div>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $errors->first() }}</div>@endif

    @unless($ready)
        <div class="rounded-md px-6 py-8 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" data-qa="result-not-ready">
            The result list is not switched on yet - the standard list is in use everywhere. Once it is switched on you can rename, add and switch off results here.
        </div>
    @else
    <form method="GET" class="flex items-end gap-3 flex-wrap" data-qa="result-filters">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rq">Search</label>
            <input id="rq" name="q" value="{{ $q }}" placeholder="Result name" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rs">Show</label>
            <select id="rs" name="status" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['all' => 'All', 'active' => 'On', 'off' => 'Switched off', 'archived' => 'Archived'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="ro">Sort</label>
            <select id="ro" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(['order' => 'Order on the screen', 'name' => 'Name', 'class' => 'What it does'] as $v => $l)<option value="{{ $v }}" @selected($sort === $v)>{{ $l }}</option>@endforeach
            </select></div>
        <button class="corex-btn-outline corex-btn-xs" type="submit">Search</button>
    </form>

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead><tr style="color: var(--text-muted);"><th class="px-3 py-2 text-left">Result</th><th class="px-3 py-2 text-left">What it does</th><th class="px-3 py-2 text-left">Set aside as</th><th class="px-3 py-2 text-left">Back in (days)</th><th class="px-3 py-2 text-right"></th></tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr style="border-top: 1px solid var(--border);" data-qa="result-row" @if($r->deleted_at) style="opacity:.6;" @endif>
                    <form method="POST" action="{{ route('road-to-sold.settings.results.update', $r->id) }}">@csrf @method('PUT')
                    <td class="px-3 py-2"><input name="label" value="{{ $r->label }}" maxlength="120" required class="w-full rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($r->deleted_at)></td>
                    <td class="px-3 py-2">
                        @if($r->is_system || $r->deleted_at)<span>{{ $classes[$r->class] ?? $r->class }}</span><input type="hidden" name="class" value="{{ $r->class }}">
                        @else<select name="class" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}">@foreach($classes as $v => $l)<option value="{{ $v }}" @selected($r->class === $v)>{{ $l }}</option>@endforeach</select>@endif
                    </td>
                    <td class="px-3 py-2"><select name="park_reason_key" class="rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($r->deleted_at || $r->class === 'won')>
                        <option value="">{{ $r->class === 'won' ? 'Not set aside' : 'Standard for this result' }}</option>
                        @foreach($reasons as $reason)<option value="{{ $reason->key }}" @selected($r->park_reason_key === $reason->key)>{{ $reason->label }}</option>@endforeach</select></td>
                    <td class="px-3 py-2"><input name="revisit_days" type="number" min="1" max="730" value="{{ $r->revisit_days }}" class="w-20 rounded-md px-2 py-1 text-xs" style="{{ $inputStyle }}" @disabled($r->deleted_at || $r->class === 'won')></td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @unless($r->deleted_at)<button class="corex-btn-primary corex-btn-xs" type="submit">Save</button>@endunless
                    </form>
                            @if($r->deleted_at)
                                <form method="POST" action="{{ route('road-to-sold.settings.results.restore', $r->id) }}">@csrf<button class="corex-btn-outline corex-btn-xs" type="submit">Restore</button></form>
                            @else
                                <form method="POST" action="{{ route('road-to-sold.settings.results.toggle', $r->id) }}">@csrf<button class="corex-btn-outline corex-btn-xs" type="submit">{{ $r->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                                <form method="POST" action="{{ route('road-to-sold.settings.results.archive', $r->id) }}" onsubmit="return confirm('Archive this result? Records that used it keep its wording.')">@csrf @method('DELETE')<button class="corex-btn-outline corex-btn-xs" type="submit">Archive</button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-6 py-10 text-center text-sm" style="color: var(--text-primary);" data-qa="result-empty">@if($q !== '' || $status !== 'all')Nothing matches this search.@else No results yet.@endif</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if(method_exists($rows, 'links')){{ $rows->links() }}@endif

    <form method="POST" action="{{ route('road-to-sold.settings.results.store') }}" class="rounded-md p-4 flex items-end gap-3 flex-wrap" style="background: var(--surface); border: 1px solid var(--border);" data-qa="result-add">
        @csrf
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div class="grow"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nl">Add a result</label>
            <input id="nl" name="label" required maxlength="120" placeholder="e.g. Chose a friend who is an agent" class="w-full rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nc">What it does</label>
            <select id="nc" name="class" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($classes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="nd">Back in (days)</label>
            <input id="nd" name="revisit_days" type="number" min="1" max="730" class="w-24 rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <button class="corex-btn-primary corex-btn-xs" type="submit">Add</button>
    </form>
    @endunless
</div>
@endsection
