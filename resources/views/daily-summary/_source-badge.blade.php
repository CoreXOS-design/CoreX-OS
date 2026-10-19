{{-- Shared manual/auto source-breakdown badge for the 3 daily-summary index
     tables (agent/bm/admin). Expects $it with manual_count / auto_count ints. --}}
<span class="inline-flex items-center gap-1 text-xs whitespace-nowrap">
    @if(($it['manual_count'] ?? 0) > 0)
        <span class="ds-badge ds-badge-pill ds-badge-info" title="Manually captured entries">{{ number_format($it['manual_count']) }} man</span>
    @endif
    @if(($it['auto_count'] ?? 0) > 0)
        <span class="ds-badge ds-badge-pill ds-badge-success" title="Auto-credited entries">{{ number_format($it['auto_count']) }} auto</span>
    @endif
    @if(($it['manual_count'] ?? 0) === 0 && ($it['auto_count'] ?? 0) === 0)
        <span style="color: var(--text-faint, var(--text-muted));">—</span>
    @endif
</span>
