{{--
    Phase D2 — one row in the "This Week" hero block.
    `$tile` must be a TileDTO instance.

    Urgency accents the left border + the action arrow on hover. No coloured
    backgrounds — the hero is meant to feel like a clean list, not a dashboard.
--}}
@php
    // Urgency tiers map to documented semantic tokens (UI_DESIGN_SYSTEM.md §1.5),
    // each with a fallback hex per §5.10. No raw hex in markup (strict rule 10).
    $urgencyColor = match ($tile->urgency) {
        'red'     => 'var(--ds-crimson, #dc2626)',
        'orange'  => 'var(--ds-amber, #f59e0b)',
        'blue'    => 'var(--brand-icon, #0ea5e9)',
        'green'   => 'var(--ds-green, #10b981)',
        default   => 'var(--text-muted)',
    };
@endphp
<a href="{{ $tile->actionUrl }}"
   class="mic-this-week-tile"
   style="display: flex; align-items: center; gap: 16px;
          padding: 12px 16px;
          border: 1px solid var(--border);
          border-left: 3px solid {{ $urgencyColor }};
          border-radius: 6px;
          text-decoration: none;
          background: var(--surface);
          transition: background 120ms ease, border-color 120ms ease;">
    <div style="font-size: 1.5rem; line-height: 1;">{{ $tile->emoji }}</div>
    <div style="flex: 1; min-width: 0;">
        <div style="font-size: 0.875rem; font-weight: 500; color: var(--text-primary);">{{ $tile->sentence }}</div>
    </div>
    <div style="font-size: 0.75rem; color: var(--text-muted); white-space: nowrap;">
        {{ $tile->actionLabel }} →
    </div>
</a>
