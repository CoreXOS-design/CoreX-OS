{{-- Prospecting Intelligence — Buyer Funnel panel.
     Two views toggled via ?funnel_view=inflow|mix.
     Inflow: 5-row x 4-status table, each cell clickable → filter page.
     Mix:    horizontal stacked bars per window, scaled by row total.

     Consumes:
       $snapshot   — IntelligenceSnapshot (uses ->buyerFunnel)
       $filters    — controller filter array (uses ->funnel_view)
       $urlWith    — closure from _summary-block (filter-aware URL builder)
--}}

@php
    $funnel  = $snapshot->buyerFunnel;
    $view    = $filters['funnel_view'] ?? 'inflow';

    $windows = [
        'last_7_days'   => ['label' => 'Last 7 days',   'days' => 7],
        'last_30_days'  => ['label' => 'Last 30 days',  'days' => 30],
        'last_60_days'  => ['label' => 'Last 60 days',  'days' => 60],
        'last_90_days'  => ['label' => 'Last 90 days',  'days' => 90],
        'last_180_days' => ['label' => 'Last 180 days', 'days' => 180],
    ];

    // Status palette — match the buyer-pipeline state pills used elsewhere
    // (resources/views/command-center/buyers/detail.blade.php).
    $statuses = [
        'new'  => ['label' => 'New',  'color' => '#3b82f6'], // blue
        'warm' => ['label' => 'Warm', 'color' => '#10b981'], // green
        'cold' => ['label' => 'Cold', 'color' => '#f59e0b'], // amber
        'lost' => ['label' => 'Lost', 'color' => '#ef4444'], // red
    ];

    $cellUrl = function ($windowKey, $statusKey) use ($urlWith, $windows) {
        $days = $windows[$windowKey]['days'];
        return $urlWith([
            'buyers_since' => now()->subDays($days)->format('Y-m-d'),
            'buyer_state'  => $statusKey,
        ]);
    };

    $rowTotal    = fn ($row) => array_sum($row);
    $maxRowTotal = max(array_map($rowTotal, $funnel)) ?: 1;
@endphp

<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">

    {{-- Header + view toggle --}}
    <div class="flex items-center justify-between mb-4 gap-3">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">Buyer Funnel</h3>
            <p class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                New buyers entering the pipeline by status, over time. Click a cell to filter the page.
            </p>
        </div>

        <div class="inline-flex rounded overflow-hidden text-[11px] flex-shrink-0" style="border: 1px solid var(--border);">
            <a href="{{ $urlWith(['funnel_view' => 'inflow']) }}"
               class="px-2.5 py-1 no-underline transition"
               @if($view === 'inflow')
                   style="background: var(--brand-button); color: #fff;"
               @else
                   style="background: var(--surface); color: var(--text-secondary);"
               @endif>Inflow table</a>
            <a href="{{ $urlWith(['funnel_view' => 'mix']) }}"
               class="px-2.5 py-1 no-underline transition"
               @if($view === 'mix')
                   style="background: var(--brand-button); color: #fff;"
               @else
                   style="background: var(--surface); color: var(--text-secondary);"
               @endif>Status mix</a>
        </div>
    </div>

    @if($view === 'inflow')
        {{-- Inflow table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <th class="text-left py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Window</th>
                        @foreach($statuses as $key => $status)
                            <th class="text-center py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-block w-2 h-2 rounded-full" style="background: {{ $status['color'] }};"></span>
                                    {{ $status['label'] }}
                                </span>
                            </th>
                        @endforeach
                        <th class="text-right py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Row total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($windows as $windowKey => $window)
                        @php $row = $funnel[$windowKey] ?? []; @endphp
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="py-2 px-3 font-medium" style="color: var(--text-primary);">{{ $window['label'] }}</td>
                            @foreach($statuses as $statusKey => $status)
                                @php $count = $row[$statusKey] ?? 0; @endphp
                                <td class="text-center py-2 px-3">
                                    @if($count > 0)
                                        <a href="{{ $cellUrl($windowKey, $statusKey) }}"
                                           class="inline-flex items-center justify-center min-w-[2.5rem] px-2 py-1 rounded text-xs font-semibold no-underline transition hover:brightness-110"
                                           style="background: color-mix(in srgb, {{ $status['color'] }} 18%, transparent); color: {{ $status['color'] }};">
                                            {{ $count }}
                                        </a>
                                    @else
                                        <span class="text-xs" style="color: var(--text-muted);">0</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-right py-2 px-3 font-semibold" style="color: var(--text-primary);">{{ $rowTotal($row) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        {{-- Status mix — horizontal stacked bars --}}
        <div class="space-y-3">
            @foreach($windows as $windowKey => $window)
                @php
                    $row     = $funnel[$windowKey] ?? [];
                    $total   = $rowTotal($row);
                    $widthPct = ($total / $maxRowTotal) * 100;
                @endphp
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs" style="color: var(--text-secondary);">{{ $window['label'] }}</span>
                        <span class="text-[11px] font-medium" style="color: var(--text-primary);">
                            {{ $total }} buyer{{ $total === 1 ? '' : 's' }}
                        </span>
                    </div>
                    @if($total > 0)
                        <div class="relative h-5 rounded overflow-hidden flex"
                             style="background: var(--surface-2); width: {{ max($widthPct, 5) }}%;">
                            @foreach($statuses as $statusKey => $status)
                                @php
                                    $count = $row[$statusKey] ?? 0;
                                    $segmentPct = ($count / $total) * 100;
                                @endphp
                                @if($count > 0)
                                    <a href="{{ $cellUrl($windowKey, $statusKey) }}"
                                       class="flex items-center justify-center text-[10px] font-semibold no-underline transition hover:brightness-110"
                                       style="width: {{ $segmentPct }}%; background: {{ $status['color'] }}; color: #fff;"
                                       title="{{ $status['label'] }}: {{ $count }} buyer{{ $count === 1 ? '' : 's' }}">
                                        @if($segmentPct >= 15)
                                            {{ $count }}
                                        @endif
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="h-5 rounded text-[10px] flex items-center px-2"
                             style="background: var(--surface-2); color: var(--text-muted);">no buyers</div>
                    @endif
                </div>
            @endforeach

            {{-- Legend --}}
            <div class="flex flex-wrap items-center gap-3 pt-2 text-[10px]"
                 style="color: var(--text-muted); border-top: 1px solid var(--border);">
                @foreach($statuses as $statusKey => $status)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm" style="background: {{ $status['color'] }};"></span>
                        {{ $status['label'] }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif
</div>
