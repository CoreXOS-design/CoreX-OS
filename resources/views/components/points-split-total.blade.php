{{--
    A points total shown as manual + automatic + combined (Johan, 21 Oct 2026). The three numbers come from
    ActivityPointsService (one rule): manual + automatic = total. Pass the array ActivityPointsService::sumSplit() returns.

    Usage: <x-points-split-total :split="$split" label="Points this month" />
           <x-points-split-total :split="$split" split-only />   (just the Manual / Automatic line, under a number the page already shows)
           <x-points-split-total :split="$split" size="sm" />   (smaller, for a table cell)
--}}
@props(['split', 'label' => null, 'size' => 'md', 'testid' => null, 'splitOnly' => false])
@php
    $manual = (float) ($split['manual'] ?? 0);
    $auto = (float) ($split['automatic'] ?? 0);
    $total = (float) ($split['total'] ?? ($manual + $auto));
    $big = $size === 'sm' ? 'text-sm font-bold' : 'text-lg font-bold';
@endphp
<div {{ $attributes }} @if($testid) data-testid="{{ $testid }}" @endif>
    @unless($splitOnly)
    @if($label)<div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $label }}</div>@endif
    <div class="{{ $big }}" style="color:var(--text-primary);" data-part="total" title="Combined: manual + automatic">{{ number_format($total, 0) }}</div>
    @endunless
    <div class="text-[11px] flex flex-wrap items-center gap-x-3 gap-y-0.5" style="color:var(--text-muted);">
        <span class="inline-flex items-center gap-1" data-part="manual" title="Captured by the agent"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--brand-icon, #0ea5e9);"></span>Manual {{ number_format($manual, 0) }}</span>
        <span class="inline-flex items-center gap-1" data-part="automatic" title="Credited by CoreX work"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--ds-amber, #f59e0b);"></span>Automatic {{ number_format($auto, 0) }}</span>
    </div>
</div>
