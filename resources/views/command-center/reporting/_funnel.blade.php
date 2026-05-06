{{-- Conversion Funnel Partial --}}
@if(!empty($funnel))
<div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-sm font-semibold mb-3" style="color: var(--text-primary);">Conversion Funnel</h2>
    <div class="space-y-2">
        @php $maxCount = collect($funnel)->max('count') ?: 1; @endphp
        @foreach($funnel as $stage)
            <div class="flex items-center gap-3">
                <span class="text-xs w-28 flex-shrink-0 font-medium" style="color: var(--text-primary);">{{ $stage['stage'] }}</span>
                <div class="flex-1 h-6 rounded overflow-hidden relative" style="background: var(--surface-2);">
                    <div class="h-full rounded flex items-center px-2" style="width: {{ max(5, ($stage['count'] / $maxCount) * 100) }}%; background: #00d4aa;">
                        <span class="text-[10px] font-bold text-white">{{ $stage['count'] }}</span>
                    </div>
                </div>
                @if($stage['rate'] !== null)
                    <span class="text-[10px] w-12 text-right font-medium" style="color: {{ $stage['rate'] >= 30 ? '#10b981' : ($stage['rate'] >= 15 ? '#f59e0b' : '#ef4444') }};">{{ $stage['rate'] }}%</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
