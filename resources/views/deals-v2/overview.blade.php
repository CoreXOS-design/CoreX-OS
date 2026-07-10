@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-158 DR2 WS8 (§12) — pipeline overview: scoped KPI cards + milestone board.
     Branch_manager + admin only (deals_v2.view_overview). Non-draggable board:
     status changes only through proper step completion (anti-gaming). --}}
@section('corex-content')
@php
    $scopeLabels = ['own' => 'Mine', 'branch' => 'Branch', 'all' => 'Company'];
    $scopeRank = ['own' => 1, 'branch' => 2, 'all' => 3];
    $maxRank = $scopeRank[$permittedScope] ?? 1;
    // RAG uses documented semantic tokens. Red is a genuine danger/attention
    // state here (overdue / red RAG) — permitted per UI_DESIGN_SYSTEM.md §1.5.
    $ragColour = fn ($rag) => match ($rag) {
        'overdue', 'red' => 'var(--ds-crimson, #c41e3a)',
        'amber'          => 'var(--ds-amber, #f59e0b)',
        'green'          => 'var(--ds-green, #059669)',
        default          => 'var(--text-muted, #9ca3af)',
    };
@endphp
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded banner) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Pipeline Overview</h1>
                <p class="text-sm text-white/60">The whole book at a glance — attention, value, and where every deal sits.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Scope switcher — only scopes up to the user's permitted level render. --}}
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid rgba(255,255,255,0.25);">
                    @foreach($scopeLabels as $key => $label)
                        @if(($scopeRank[$key] ?? 9) <= $maxRank)
                            <a href="{{ route('deals-v2.overview', ['scope' => $key]) }}"
                               class="px-3 py-1.5 text-sm font-medium transition-colors"
                               style="{{ $scope === $key ? 'background: #fff; color: var(--brand-default, #0b2a4a);' : 'color: #fff;' }}">{{ $label }}</a>
                        @endif
                    @endforeach
                </div>
                <a href="{{ route('deals-v2.export', ['scope' => $scope]) }}"
                   class="corex-btn-outline text-sm"
                   style="color: #fff; border-color: rgba(255,255,255,0.25); background: rgba(255,255,255,0.08);">
                    Export CSV
                </a>
                @if(Route::has('deals-v2.index'))
                    <a href="{{ route('deals-v2.index') }}"
                       class="corex-btn-outline text-sm"
                       style="color: #fff; border-color: rgba(255,255,255,0.25); background: rgba(255,255,255,0.08);">
                        Register
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        @foreach($cards as $card)
            <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-left: 3px solid {{ $card['rag'] ? $ragColour($card['rag']) : 'var(--brand-default, #0b2a4a)' }};">
                <div class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted, #6b7280);">{{ $card['label'] }}</div>
                <div class="mt-1.5 text-[1.625rem] font-semibold leading-tight" style="color: var(--text-primary, #111827);">
                    @if(($card['format'] ?? null) === 'zar')
                        R {{ number_format((float) $card['value'], 0) }}
                    @elseif(($card['format'] ?? null) === 'days')
                        @if($card['value'] === null)—@else{{ number_format($card['value']) }} <span class="text-sm font-normal">days</span>@endif
                    @else
                        {{ number_format((int) $card['value']) }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Milestone board (non-draggable) --}}
    <div>
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-secondary, #374151);">By current milestone</h2>
        @if($board->isEmpty())
            {{-- Empty state (§3.10) --}}
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary, #111827);">No active deals in this view</h3>
                <p class="text-sm" style="color: var(--text-muted, #9ca3af);">Deals appear here as they progress through their pipeline milestones.</p>
                @if(Route::has('deals-v2.create'))
                    <a href="{{ route('deals-v2.create') }}" class="corex-btn-primary mt-4">New Deal</a>
                @endif
            </div>
        @else
            <div class="flex gap-3 overflow-x-auto pb-2">
                @foreach($board as $milestone => $deals)
                    <div class="flex-shrink-0 w-64 rounded-md" style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb);">
                        <div class="px-3 py-2 flex items-center justify-between" style="border-bottom: 1px solid var(--border, #e5e7eb);">
                            <span class="text-sm font-semibold truncate" style="color: var(--text-primary, #111827);">{{ $milestone }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full" style="background: var(--surface, #fff); color: var(--text-muted, #6b7280);">{{ number_format($deals->count()) }}</span>
                        </div>
                        <div class="p-2 space-y-2" style="max-height: 60vh; overflow-y: auto;">
                            @foreach($deals as $d)
                                @php($hasOverride = $d->stepInstances->contains(fn ($s) => data_get($s->completion_data, 'completed_with_reason')))
                                <a href="{{ route('deals-v2.show', $d) }}" target="_blank" rel="noopener"
                                   class="block rounded-md p-2.5 transition-colors" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-left: 3px solid {{ $ragColour($d->overall_rag) }};">
                                    <div class="text-sm font-semibold truncate" style="color: var(--text-primary, #111827);">{{ $d->reference ?: 'Deal #' . $d->id }}</div>
                                    <div class="text-xs truncate" style="color: var(--text-muted, #6b7280);">{{ $d->property?->address ?: '—' }}</div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted, #9ca3af);">{{ $d->listingAgent?->name }}</div>
                                    @if($hasOverride)
                                        {{-- Anti-gaming oversight marker: a step was completed without its requirement. --}}
                                        <span class="inline-block mt-1 rounded-md px-1.5 py-0.5 text-[0.6875rem] font-semibold" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--ds-amber, #b45309);"
                                              title="A step was completed without its normal requirement — reason recorded on the deal timeline.">⚑ completed with reason</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- WS8 (§12) — subscribe: per-user iCal feed of your deal deadlines. --}}
    <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <div class="text-sm font-semibold" style="color: var(--text-primary, #111827);">Subscribe in your calendar</div>
                <p class="text-xs" style="color: var(--text-muted, #6b7280);">A private, read-only feed of your deal-step deadlines. Paste this URL into Google/Apple/Outlook calendar subscriptions.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($icalToken)
                    <input type="text" readonly value="{{ route('deals-v2.ical', $icalToken) }}" onclick="this.select()"
                           class="text-xs rounded-md px-2 py-1.5" style="min-width: 320px; background: var(--surface-2, #f0f2f8); color: var(--text-secondary, #374151); border: 1px solid var(--border, #e5e7eb);">
                    <form method="POST" action="{{ route('deals-v2.ical.regenerate') }}">@csrf
                        <button type="submit" class="corex-btn-outline text-xs" title="Issue a new link; the old one stops working">Regenerate</button>
                    </form>
                    <form method="POST" action="{{ route('deals-v2.ical.disable') }}">@csrf
                        <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson, #c41e3a); border-color: color-mix(in srgb, var(--ds-crimson, #c41e3a) 40%, transparent);">Disable</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('deals-v2.ical.regenerate') }}">@csrf
                        <button type="submit" class="corex-btn-primary text-sm">Generate feed link</button>
                    </form>
                @endif
            </div>
        </div>
        @if(session('status'))
            {{-- Success notice (§3.9) --}}
            <div class="mt-3 rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary, #111827);">{{ session('status') }}</div>
        @endif
    </div>
</div>
@endsection
