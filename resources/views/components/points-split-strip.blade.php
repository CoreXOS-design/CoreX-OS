{{--
    Stacked day-by-day points bars: manual (bottom, blue) and automatic (top, amber) in one bar per day, so the eye
    sees both the day's total and which part was captured by the agent and which credited by CoreX work.
    Johan, 21 Oct 2026. Same bar style as the strips it replaces (flex bars, design-system tokens); one legend below.

    days  : Y-m-d => ['manual' => float, 'automatic' => float]   (ActivityPointsService::sumByDateSplit() shape)
    label : 'D' (Mon) or 'j' (day of month) - the little text under each bar
    height: pixel height of the tallest bar
--}}
@props(['days', 'label' => 'D', 'height' => 60, 'legend' => true, 'testid' => 'points-split-strip'])
@php
    $max = 1.0;
    foreach ($days as $d) { $max = max($max, (float) (($d['manual'] ?? 0) + ($d['automatic'] ?? 0))); }
    $any = $max > 1.0 || collect($days)->contains(fn ($d) => (($d['manual'] ?? 0) + ($d['automatic'] ?? 0)) > 0);
@endphp
<div data-testid="{{ $testid }}" {{ $attributes }}>
    <div class="flex items-end gap-1" style="height:{{ $height + 12 }}px;">
        @foreach($days as $day => $d)
            @php
                $m = (float) ($d['manual'] ?? 0); $a = (float) ($d['automatic'] ?? 0);
                $hm = $m > 0 ? max(2, (int) round($m / $max * $height)) : 0;
                $ha = $a > 0 ? max(2, (int) round($a / $max * $height)) : 0;
                $date = \Carbon\CarbonImmutable::parse($day);
            @endphp
            <div class="flex-1 text-center min-w-0" data-day="{{ $day }}" data-manual="{{ round($m, 2) }}" data-automatic="{{ round($a, 2) }}"
                 title="{{ $date->format('D j M') }}: {{ number_format($m + $a, 0) }} points - manual {{ number_format($m, 0) }}, automatic {{ number_format($a, 0) }}">
                <div class="flex flex-col justify-end items-stretch" style="height:{{ $height }}px;">
                    @if($ha > 0)<div style="height:{{ $ha }}px; background:var(--ds-amber, #f59e0b); border-radius:3px 3px 0 0;"></div>@endif
                    @if($hm > 0)<div style="height:{{ $hm }}px; background:var(--brand-icon, #0ea5e9); {{ $ha > 0 ? '' : 'border-radius:3px 3px 0 0;' }}"></div>@endif
                    @if($hm === 0 && $ha === 0)<div style="height:2px; background:var(--border);"></div>@endif
                </div>
                <div class="text-[9px] mt-1" style="color:var(--text-muted);">{{ $date->format($label) }}</div>
            </div>
        @endforeach
    </div>
    @if($legend)<div class="mt-2"><x-points-legend /></div>@endif
</div>
