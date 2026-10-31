{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- New and coming (AT-478 §12.5 + §12.9, spec .ai/specs/agency-feature-rollout.md §17c): for everyone in an agency
     on gradual rollout — what switches on next (ask for it early) and everything that already did, only what
     this person can open. NewAndComingController. --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">New and coming</h1>
        <p class="text-xs mt-1 max-w-2xl" style="color: var(--text-muted);">
            Your agency is switching CoreX on a few features at a time. Here is what is coming next, and everything that has already switched on for you — with a guided tour where there is one.
            @if ($plan->status === 'paused') Right now the introduction is paused, so nothing new switches on until your admin resumes it. @endif
        </p>
    </div>

    @if (session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if (session('error') || $errors->any())
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">{{ session('error') ?: $errors->first() }}</div>
    @endif

    <form method="GET" class="rounded-md px-5 py-3 flex flex-wrap items-end gap-3" style="background: var(--surface); border: 1px solid var(--border);">
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Search a feature</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Show</span>
            <select name="show" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}">
                <option value="all" @selected($filters['show'] === 'all')>Coming and switched on</option>
                <option value="coming" @selected($filters['show'] === 'coming')>Coming next</option>
                <option value="on" @selected($filters['show'] === 'on')>Already switched on</option>
            </select></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">Date from</span>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
        <label class="block"><span class="text-[11px] font-semibold" style="color:var(--text-muted);">to</span>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block mt-1 {{ $input }}" style="{{ $inputStyle }}"></label>
        <button type="submit" class="corex-btn-outline">Apply</button>
        <a href="{{ route('corex.new-and-coming.index') }}" class="corex-btn-outline">Clear</a>
    </form>

    @if ($filters['show'] !== 'on')
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-section="coming">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="ds-section-header">Coming next</div>
                <div class="ds-section-sub">Need something sooner? Ask for it early and your admin is told.</div>
            </div>
            @forelse ($coming as $g)
                <div class="px-5 py-4" style="border-top: 1px solid var(--border);">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="ds-badge ds-badge-pill ds-badge-info tabular-nums">{{ $g['when'] ? $g['when']->format('D j M') : 'When your admin resumes' }}</span>
                        <span class="font-semibold text-sm" style="color: var(--text-primary);">{{ $g['wave']->title }}</span>
                    </div>
                    @if ($g['wave']->intro_text)<p class="text-xs mt-1" style="color: var(--text-muted);">{{ $g['wave']->intro_text }}</p>@endif
                    <div class="mt-2 space-y-2">
                        @foreach ($g['features'] as $f)
                            <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                                <div>
                                    <div style="color: var(--text-primary);">{{ $f['label'] }}</div>
                                    @if ($f['explain'] !== '')<div class="text-xs" style="color: var(--text-secondary);">{{ $f['explain'] }}</div>@endif
                                </div>
                                @if ($f['requested_at'])
                                    <span class="text-xs whitespace-nowrap" style="color: var(--text-muted);">You asked {{ $f['requested_at']->format('j M') }}</span>
                                @else
                                    <form method="POST" action="{{ route('corex.new-and-coming.early', $f['item_id']) }}">@csrf<button type="submit" class="corex-btn-outline corex-btn-xs">Ask for this early</button></form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">
                    {{ $filtered ? 'Nothing coming matches these filters.' : 'Everything has switched on — there is nothing more to come.' }}
                </div>
            @endforelse
        </div>
    @endif

    @if ($filters['show'] !== 'coming')
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-section="on">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="ds-section-header">Already switched on</div>
                <div class="ds-section-sub">Newest first — including the ones you closed the pop-up on.</div>
            </div>
            @forelse ($on as $g)
                <div class="px-5 py-4" style="border-top: 1px solid var(--border);">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="ds-badge ds-badge-pill ds-badge-success tabular-nums">On since {{ $g['wave']->released_at->format('j M') }}</span>
                        <span class="font-semibold text-sm" style="color: var(--text-primary);">{{ $g['wave']->title }}</span>
                    </div>
                    @if ($g['wave']->intro_text)<p class="text-xs mt-1" style="color: var(--text-muted);">{{ $g['wave']->intro_text }}</p>@endif
                    <div class="mt-2 space-y-2">
                        @foreach ($g['features'] as $f)
                            <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                                <div>
                                    <div style="color: var(--text-primary);">{{ $f['label'] }}</div>
                                    @if ($f['explain'] !== '')<div class="text-xs" style="color: var(--text-secondary);">{{ $f['explain'] }}</div>@endif
                                </div>
                                @if ($f['state'] === 'waiting')
                                    <span class="ds-badge ds-badge-warning">Waiting for your admin</span>
                                @elseif ($f['tour'])
                                    <a href="{{ $f['tour']['url'] }}" class="corex-btn-primary corex-btn-xs whitespace-nowrap" title="{{ $f['tour']['title'] }}">Take the tour</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="px-5 py-10 text-center text-sm" style="color: var(--text-muted);">
                    {{ $filtered ? 'Nothing switched on matches these filters.' : 'Nothing has switched on yet — the first group is on its way.' }}
                </div>
            @endforelse
            @if ($on->hasPages())
                <div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $on->links() }}</div>
            @endif
        </div>
    @endif
</div>
@endsection
