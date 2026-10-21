{{-- Shared manual / automatic source-breakdown for the 3 daily-summary index tables (agent/bm/admin).
     Expects $it with manual_count / auto_count (ints) and manual_points / auto_points (floats, the shared points rule).
     Johan, 21 Oct 2026: show the points as MANUAL and AUTOMATIC, together but separate, in the two series colours
     (blue = captured by the agent, amber = credited by CoreX work) - the same two colours as every points chart. --}}
<span class="inline-flex items-center gap-2 text-xs whitespace-nowrap" data-part="source-split">
    @if(($it['manual_count'] ?? 0) > 0)
        <span class="inline-flex items-center gap-1" data-part="manual" title="Captured by the agent: {{ number_format($it['manual_count']) }} {{ $it['manual_count'] === 1 ? 'entry' : 'entries' }}">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--brand-icon, #0ea5e9);"></span>{{ number_format((float) ($it['manual_points'] ?? 0), 0) }}
        </span>
    @endif
    @if(($it['auto_count'] ?? 0) > 0)
        <span class="inline-flex items-center gap-1" data-part="automatic" title="Credited by CoreX work: {{ number_format($it['auto_count']) }} {{ $it['auto_count'] === 1 ? 'entry' : 'entries' }}">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--ds-amber, #f59e0b);"></span>{{ number_format((float) ($it['auto_points'] ?? 0), 0) }}
        </span>
    @endif
    @if(($it['manual_count'] ?? 0) === 0 && ($it['auto_count'] ?? 0) === 0)
        <span style="color: var(--text-faint, var(--text-muted));">—</span>
    @endif
</span>
