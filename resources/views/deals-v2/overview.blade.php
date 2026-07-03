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
    $ragColour = fn ($rag) => match ($rag) {
        'overdue' => 'var(--ds-red, #dc2626)',
        'red'     => 'var(--ds-red, #ef4444)',
        'amber'   => 'var(--ds-amber, #f59e0b)',
        'green'   => 'var(--ds-green, #10b981)',
        default   => 'var(--text-muted, #9ca3af)',
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
            <div class="flex items-center gap-2">
                {{-- Scope switcher — only scopes up to the user's permitted level render. --}}
                <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid rgba(255,255,255,0.25);">
                    @foreach($scopeLabels as $key => $label)
                        @if(($scopeRank[$key] ?? 9) <= $maxRank)
                            <a href="{{ route('deals-v2.overview', ['scope' => $key]) }}"
                               class="px-3 py-1.5 text-sm font-medium"
                               style="{{ $scope === $key ? 'background: white; color: var(--brand-default, #0b2a4a);' : 'color: white;' }}">{{ $label }}</a>
                        @endif
                    @endforeach
                </div>
                <a href="{{ route('deals-v2.export', ['scope' => $scope]) }}"
                   class="px-3 py-1.5 rounded-md text-sm font-semibold" style="background: white; color: var(--brand-default, #0b2a4a);">Export CSV</a>
                @if(Route::has('deals-v2.index'))
                    <a href="{{ route('deals-v2.index') }}" class="px-3 py-1.5 rounded-md text-sm text-white" style="border: 1px solid rgba(255,255,255,0.25);">Register</a>
                @endif
            </div>
        </div>
    </div>

    {{-- KPI cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        @foreach($cards as $card)
            <div class="rounded-md p-4" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-left: 3px solid {{ $card['rag'] ? $ragColour($card['rag']) : 'var(--brand-default, #0b2a4a)' }};">
                <div class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted, #6b7280);">{{ $card['label'] }}</div>
                <div class="mt-1.5 text-2xl font-bold" style="color: var(--text-primary, #111827);">
                    @if(($card['format'] ?? null) === 'zar')
                        R {{ number_format((float) $card['value'], 0) }}
                    @elseif(($card['format'] ?? null) === 'days')
                        @if($card['value'] === null)—@else{{ $card['value'] }} <span class="text-sm font-normal">days</span>@endif
                    @else
                        {{ $card['value'] }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Milestone board (non-draggable) --}}
    <div>
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-secondary, #374151);">By current milestone</h2>
        @if($board->isEmpty())
            <div class="rounded-md p-8 text-center text-sm" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); color: var(--text-muted, #9ca3af);">
                No active deals in this view.
            </div>
        @else
            <div class="flex gap-3 overflow-x-auto pb-2">
                @foreach($board as $milestone => $deals)
                    <div class="flex-shrink-0 w-64 rounded-md" style="background: var(--surface-2, #f0f2f8); border: 1px solid var(--border, #e5e7eb);">
                        <div class="px-3 py-2 flex items-center justify-between" style="border-bottom: 1px solid var(--border, #e5e7eb);">
                            <span class="text-sm font-semibold truncate" style="color: var(--text-primary, #111827);">{{ $milestone }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full" style="background: var(--surface, #fff); color: var(--text-muted, #6b7280);">{{ $deals->count() }}</span>
                        </div>
                        <div class="p-2 space-y-2" style="max-height: 60vh; overflow-y: auto;">
                            @foreach($deals as $d)
                                <a href="{{ route('deals-v2.show', $d) }}" target="_blank" rel="noopener"
                                   class="block rounded p-2.5" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-left: 3px solid {{ $ragColour($d->overall_rag) }};">
                                    <div class="text-sm font-semibold truncate" style="color: var(--text-primary, #111827);">{{ $d->reference ?: 'Deal #' . $d->id }}</div>
                                    <div class="text-xs truncate" style="color: var(--text-muted, #6b7280);">{{ $d->property?->address ?: '—' }}</div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted, #9ca3af);">{{ $d->listingAgent?->name }}</div>
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
            <div class="flex items-center gap-2">
                @if($icalToken)
                    <input type="text" readonly value="{{ route('deals-v2.ical', $icalToken) }}" onclick="this.select()"
                           class="text-xs rounded px-2 py-1.5" style="min-width: 320px; background: var(--surface-2, #f0f2f8); color: var(--text-secondary, #374151); border: 1px solid var(--border, #e5e7eb);">
                    <form method="POST" action="{{ route('deals-v2.ical.regenerate') }}">@csrf
                        <button type="submit" class="text-xs px-2.5 py-1.5 rounded font-semibold" style="border: 1px solid var(--border, #e5e7eb); color: var(--text-secondary, #4b5563);" title="Issue a new link; the old one stops working">Regenerate</button>
                    </form>
                    <form method="POST" action="{{ route('deals-v2.ical.disable') }}">@csrf
                        <button type="submit" class="text-xs px-2.5 py-1.5 rounded" style="border: 1px solid var(--border, #e5e7eb); color: var(--ds-red, #c0392b);">Disable</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('deals-v2.ical.regenerate') }}">@csrf
                        <button type="submit" class="text-sm px-3 py-1.5 rounded-md font-semibold" style="background: var(--brand-default, #0b2a4a); color: #fff;">Generate feed link</button>
                    </form>
                @endif
            </div>
        </div>
        @if(session('status'))
            <div class="mt-2 text-xs" style="color: var(--ds-green, #059669);">{{ session('status') }}</div>
        @endif
    </div>
</div>
@endsection
