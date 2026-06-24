{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    use App\Models\PresentationOutcome;
    $reasonLabels = [
        'price_too_high_seller'      => 'Seller insisted on higher price',
        'price_too_low_seller'       => 'Seller wanted to underprice',
        'commission_concerns'        => 'Commission concerns',
        'sole_mandate_concerns'      => 'Sole mandate concerns',
        'family_pressure'            => 'Family pressure',
        'existing_relationship'      => 'Prior agent relationship',
        'agency_reputation'          => 'Agency reputation',
        'agent_personality'          => 'Agent personality fit',
        'timing_change'              => 'Timing change',
        'property_issues_discovered' => 'Property issues',
        'price_match_with_other'     => 'Competitor matched price',
        'other'                      => 'Other',
    ];
    // [display label, ds-badge variant]. Wins = green/info; losses are neutral
    // analytics outcomes, not error states — they use amber/grey, never red.
    $outcomeLabel = function (string $o): array {
        $map = [
            'won_mandate'           => ['Won mandate',         'success'],
            'won_sale'              => ['Won + sale',          'info'],
            'lost_to_competitor'    => ['Lost to competitor',  'warning'],
            'lost_to_no_decision'   => ['No decision',         'warning'],
            'lost_to_price_dispute' => ['Price/strategy',      'warning'],
            'lost_to_no_response'   => ['No response',         'default'],
            'still_pending'         => ['Pending',             'info'],
            'other'                 => ['Other',               'default'],
        ];
        return $map[$o] ?? ['Outcome', 'default'];
    };
    $wonTotal = $wonMandate + $wonSale;
    $winRate  = $totalOutcomes > 0 ? round($wonTotal / $totalOutcomes * 100, 1) : 0.0;
    $lossMax  = !empty($lossReasons) ? max($lossReasons) : 1;

    $hasFilters = $outcomeFilter || $reasonFilter || $agentFilter;
@endphp

<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div data-tour="pres-outcomes-intro">
                <h1 class="text-xl font-bold text-white leading-tight">Outcomes Dashboard</h1>
                <p class="text-sm text-white/60">
                    Win rate, loss reasons, and pipeline health across {{ number_format($totalPresentations) }} presentation{{ $totalPresentations === 1 ? '' : 's' }} in the selected window.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('presentations.index') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Presentations
                </a>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET"
          class="rounded-md p-4 grid gap-3"
          style="background: var(--surface); border: 1px solid var(--border); grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));"
          data-tour="pres-outcomes-filters">
        <div>
            <label for="from" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">From</label>
            <input id="from" type="date" name="from" value="{{ $from->toDateString() }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label for="to" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">To</label>
            <input id="to" type="date" name="to" value="{{ $to->toDateString() }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        <div>
            <label for="outcome" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Outcome</label>
            <select id="outcome" name="outcome"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                @foreach(PresentationOutcome::ALL_OUTCOMES as $o)
                    <option value="{{ $o }}" @selected($outcomeFilter === $o)>{{ $outcomeLabel($o)[0] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="reason" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Loss reason</label>
            <select id="reason" name="reason"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                @foreach($reasonLabels as $k => $v)
                    <option value="{{ $k }}" @selected($reasonFilter === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        @if($isManager)
        <div>
            <label for="agent_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Agent</label>
            <select id="agent_id" name="agent_id"
                    class="w-full rounded-md px-3 py-2 text-sm"
                    style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All</option>
                @foreach($agents as $a)
                    <option value="{{ $a->id }}" @selected((int) $agentFilter === (int) $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="flex items-end gap-2">
            <button type="submit" class="corex-btn-primary text-sm">Apply</button>
            @if($hasFilters)
                <a href="{{ route('corex.presentations.outcomes.index') }}" class="corex-btn-outline text-sm">Clear</a>
            @endif
        </div>
    </form>

    {{-- Top metrics --}}
    @php
        $metrics = [
            ['Total outcomes',      number_format($totalOutcomes), null],
            ['Won mandates',        number_format($wonTotal),      $totalOutcomes > 0 ? number_format($wonTotal / $totalOutcomes * 100, 1) . '%' : '—'],
            ['Lost to competitor',  number_format($lostComp),      $totalOutcomes > 0 ? number_format($lostComp / $totalOutcomes * 100, 1) . '%' : '—'],
            ['No decision',         number_format($lostNoDec),     $totalOutcomes > 0 ? number_format($lostNoDec / $totalOutcomes * 100, 1) . '%' : '—'],
            ['Avg days to outcome', $avgDays !== null ? number_format($avgDays) . 'd' : '—', null],
            ['Still pending',       number_format($stillPending),  null],
        ];
    @endphp
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));" data-tour="pres-outcomes-metrics">
        @foreach($metrics as [$lbl, $val, $sub])
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $lbl }}</div>
                <div class="mt-1 font-semibold" style="font-size: 1.625rem; color: var(--text-primary);">{{ $val }}</div>
                @if($sub)
                    <div class="mt-0.5 text-xs" style="color: var(--text-muted);">{{ $sub }} of outcomes</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Loss reasons chart --}}
    @if(!empty($lossReasons))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-tour="pres-outcomes-loss-reasons">
            <h2 class="ds-section-header" style="margin: 0 0 12px 0;">Loss reasons breakdown</h2>
            <div class="flex flex-col gap-2.5">
                @foreach($lossReasons as $key => $count)
                    @php $pct = max(2, round(($count / $lossMax) * 100)); @endphp
                    <div class="grid items-center gap-3" style="grid-template-columns: minmax(120px, 200px) 1fr 30px;">
                        <div class="text-xs" style="color: var(--text-secondary);">{{ $reasonLabels[$key] ?? $key }}</div>
                        <div class="ds-progress-track">
                            <div class="ds-progress-bar ds-bar-amber" style="width: {{ $pct }}%;"></div>
                        </div>
                        <div class="text-xs text-right" style="color: var(--text-secondary);">{{ number_format($count) }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Outcomes list --}}
    @if($outcomes->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No outcomes to show</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">
                @if($hasFilters)
                    No outcomes match the current filters. Try widening the date range or clearing filters.
                @else
                    Outcomes appear here once they are recorded against your presentations.
                @endif
            </p>
            @if($hasFilters)
                <a href="{{ route('corex.presentations.outcomes.index') }}" class="corex-btn-primary text-sm">Clear filters</a>
            @else
                <a href="{{ route('presentations.index') }}" class="corex-btn-primary text-sm">Go to Presentations</a>
            @endif
        </div>
    @else
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Property</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Outcome</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Reason / Competitor</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Recorded by</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Decision</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($outcomes as $o)
                        @php [$ol, $ovariant] = $outcomeLabel($o->outcome); @endphp
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3" style="color: var(--text-primary);">
                                <a href="{{ route('presentations.show', $o->presentation_id) }}"
                                   class="font-medium no-underline" style="color: var(--text-primary);">
                                    {{ $o->presentation?->property_address ?: 'Presentation #' . $o->presentation_id }}
                                </a>
                                @if($o->presentation?->suburb)
                                    <div class="text-xs" style="color: var(--text-muted);">{{ $o->presentation->suburb }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="ds-badge ds-badge-{{ $ovariant }}">{{ $ol }}</span>
                                @if($o->locked)
                                    <span title="Locked for analytics" class="ml-1 text-xs" style="color: var(--text-muted);">🔒</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                @if($o->cancellation_reason)
                                    {{ $reasonLabels[$o->cancellation_reason] ?? $o->cancellation_reason }}
                                @endif
                                @if($o->cancellation_competitor_agency)
                                    <div class="text-xs" style="color: var(--text-muted);">{{ $o->cancellation_competitor_agency }}@if($o->cancellation_competitor_price) @ R {{ number_format((int) $o->cancellation_competitor_price) }}@endif</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $o->recorder?->name }}
                                <div class="text-xs" style="color: var(--text-muted);">{{ $o->recorded_at?->diffForHumans() }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">
                                {{ $o->decision_at?->format('j M Y') ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('presentations.show', $o->presentation_id) }}"
                                   class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">Open →</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $outcomes->links() }}</div>
        </div>
    @endif

</div>
@endsection
