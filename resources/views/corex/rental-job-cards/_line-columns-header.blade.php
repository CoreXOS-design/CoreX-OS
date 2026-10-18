{{--
    2026-10-05 (Johan QA1 finding #3) — one header row per task block,
    sitting above BOTH the existing-lines table and the add-line row below
    it, using the EXACT same grid-template-columns as both (shared via
    App\Support\RentalJobCardLineGrid so all three can never drift apart).
    Compact, muted, no helper text — just the column names so an agent can
    tell what they're filling in where.

    Expects: $pricesOn (bool), $vatRegistered (bool); optional $showCost (bool — the viewer holds rental_job_cards.view_costs: adds Cost and Margin).
--}}
@php
    $headerGridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vatRegistered, $showCost ?? false);
    $headerLabels = \App\Support\RentalJobCardLineGrid::labels($pricesOn, $vatRegistered, $showCost ?? false);
@endphp
<div style="{{ $headerGridStyle }}" class="text-[11px] font-medium uppercase tracking-wide">
    @foreach($headerLabels as $label)
        <span style="color: var(--text-muted); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; {{ \App\Support\RentalJobCardLineGrid::cellStyle() }}">{{ $label }}</span>
    @endforeach
</div>
