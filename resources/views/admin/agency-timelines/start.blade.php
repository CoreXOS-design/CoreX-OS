{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — start an agency timeline (AT-447). --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $nice = fn ($t) => preg_replace('/\\{\\{\\s*agency_name\\s*\\}\\}/', $agency->name, $t);
    $stepsJs = collect($preview)->map(fn ($p) => [
        'id' => $p['id'], 'offset' => $p['offset'], 'title' => $nice($p['title']), 'live' => $p['live'],
        'date' => old('dates.' . $p['id'], $p['date']->toDateString()),
        'edited' => old('dates.' . $p['id']) !== null,
    ])->values();
@endphp
<div class="w-full space-y-5"
     x-data="{
        start: @js(old('start_date', $start->toDateString())),
        today: @js(now()->toDateString()),
        steps: @js($stepsJs),
        add(d, n) { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); },
        recalc() { if (!this.start) return; this.steps.forEach(s => { if (!s.edited) s.date = this.add(this.start, s.offset); }); },
        resetStep(s) { s.edited = false; s.date = this.add(this.start, s.offset); },
        get pastStart() { return !!this.start && this.start < this.today; },
     }">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Start timeline — {{ $agency->name }}</h1>
                <p class="text-xs" style="color: var(--text-muted);">Pick the start date, then adjust any step date for this agency. You can still change dates and add custom steps afterwards.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.agency-timelines.index') }}" class="corex-btn-outline text-xs">Back to timelines</a>
            </div>
        </div>
    </div>

    @include('admin.partials.platform-flash')

    <form method="POST" action="{{ route('admin.agency-timelines.start', $agency) }}" class="space-y-5">
        @csrf

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <label class="ds-label block mb-1" for="start_date">Start date</label>
            <div class="flex flex-wrap items-center gap-3">
                <input id="start_date" type="date" name="start_date" x-model="start" @input="recalc()" :min="today" required class="ds-field">
                <span class="text-xs" style="color: var(--text-muted);">Cannot be in the past. Changing it moves every step you haven't edited by hand.</span>
            </div>
            <p x-show="pastStart" x-cloak class="text-xs mt-2" style="color: var(--ds-crimson);">The start date cannot be in the past.</p>
        </div>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Step</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Default</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in steps" :key="s.id">
                            <tr>
                                <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">
                                    <span x-text="s.title"></span>
                                    <span x-show="s.live" class="ds-badge ds-badge-success ml-1">Go live</span>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="date" :name="'dates[' + s.id + ']'" x-model="s.date" @input="s.edited = true" :min="start" class="ds-field">
                                </td>
                                <td class="px-4 py-3 text-right text-xs whitespace-nowrap" style="color: var(--text-muted);">
                                    <span x-text="'start + ' + s.offset + 'd'"></span>
                                    <button type="button" x-show="s.edited" x-cloak @click="resetStep(s)" class="corex-btn-outline corex-btn-xs ml-3">Reset</button>
                                </td>
                            </tr>
                        </template>
                        @if(empty($preview))
                            <tr><td colspan="3" class="px-4 py-10 text-center" style="color: var(--text-muted);">There are no default steps yet — add some in <a class="underline" href="{{ route('admin.timeline-defaults.index') }}">Dev Settings → Agency timeline defaults</a>.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button class="corex-btn-primary text-sm" type="submit" :disabled="pastStart" :style="pastStart ? 'opacity:.5;cursor:not-allowed;' : ''">Start timeline</button>
            <a href="{{ route('admin.agency-timelines.index') }}" class="text-xs underline" style="color: var(--text-muted);">Cancel</a>
        </div>
    </form>
</div>
@endsection
