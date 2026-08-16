{{-- Shared manual/auto source-breakdown badge for the 3 daily-summary index
     tables (agent/bm/admin). Expects $it with manual_count / auto_count ints. --}}
<span class="inline-flex items-center gap-1 text-xs whitespace-nowrap">
    @if(($it['manual_count'] ?? 0) > 0)
        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 font-semibold"
              style="background: color-mix(in srgb, var(--ds-blue) 12%, transparent); color: var(--ds-blue);"
              title="Manually captured entries">{{ number_format($it['manual_count']) }} man</span>
    @endif
    @if(($it['auto_count'] ?? 0) > 0)
        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 font-semibold"
              style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);"
              title="Auto-credited entries">{{ number_format($it['auto_count']) }} auto</span>
    @endif
    @if(($it['manual_count'] ?? 0) === 0 && ($it['auto_count'] ?? 0) === 0)
        <span style="color: var(--text-faint, var(--text-muted));">—</span>
    @endif
</span>
