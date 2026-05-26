{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Conversion Funnel Partial --}}
@if(!empty($funnel))
<div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-lg font-semibold mb-3" style="color: var(--text-primary);">Conversion Funnel</h2>
    <div class="space-y-2">
        @php $maxCount = collect($funnel)->max('count') ?: 1; @endphp
        @foreach($funnel as $stage)
            @php
                $rate = $stage['rate'];
                $rateColor = $rate === null
                    ? 'var(--text-muted)'
                    : ($rate >= 30 ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)');
            @endphp
            <div class="flex items-center gap-3">
                <span class="text-xs w-28 flex-shrink-0 font-medium" style="color: var(--text-primary);">{{ $stage['stage'] }}</span>
                <div class="ds-progress-track flex-1" style="height: 12px;">
                    <div class="ds-progress-bar" style="width: {{ max(5, ($stage['count'] / $maxCount) * 100) }}%; background: var(--brand-icon, #0ea5e9); display: flex; align-items: center; padding: 0 0.5rem;">
                        <span class="text-[0.6875rem] font-semibold text-white">{{ number_format($stage['count']) }}</span>
                    </div>
                </div>
                @if($rate !== null)
                    <span class="text-xs w-12 text-right font-semibold" style="color: {{ $rateColor }};">{{ number_format($rate, 1) }}%</span>
                @else
                    <span class="text-xs w-12 text-right" style="color: var(--text-muted);">—</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
