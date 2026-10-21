{{--
    Legend for the manual / automatic points split (Johan, 21 Oct 2026). One definition of the two colours for every
    screen that shows points: Manual = captured by the agent; Automatic = credited by CoreX's own work (calendar
    events, captured contacts, deals ...). Colours are design-system tokens, so they follow light / dark themes.
    Usage: <x-points-legend />
--}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3 text-[11px]', 'style' => 'color:var(--text-muted);']) }} data-testid="points-legend">
    <span class="inline-flex items-center gap-1" title="Points the agent captured themselves"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--brand-icon, #0ea5e9);"></span>Manual (captured by the agent)</span>
    <span class="inline-flex items-center gap-1" title="Points CoreX credited from the agent's work: calendar events, captured contacts, deals and so on"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--ds-amber, #f59e0b);"></span>Automatic (credited by CoreX work)</span>
</span>
