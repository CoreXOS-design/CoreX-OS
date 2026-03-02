@props(['title', 'value', 'trend' => 0, 'trendUp' => true, 'iconBg' => 'bg-sky-100 text-[#00b4d8]'])

<div class="corex-kpi-card">
    <div class="flex items-start justify-between">
        <div>
            <p class="corex-kpi-title">{{ $title }}</p>
            <p class="corex-kpi-value">{{ $value }}</p>
            <div class="corex-kpi-trend {{ $trendUp ? 'up' : 'down' }}">
                @if($trendUp)
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" />
                    </svg>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898m0 0 3.182-5.511m-3.182 5.51-5.511-3.181" />
                    </svg>
                @endif
                <span>{{ abs($trend) }}%</span>
                <span class="corex-kpi-subtitle">from last month</span>
            </div>
        </div>
        @if(isset($icon))
            <div class="corex-kpi-icon {{ $iconBg }}">
                {{ $icon }}
            </div>
        @endif
    </div>
</div>
