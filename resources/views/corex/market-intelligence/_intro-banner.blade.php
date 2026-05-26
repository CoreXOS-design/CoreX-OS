{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.8). --}}
{{--
    F.8 — One-time dismissable Market Intelligence intro banner.

    Visible on first visit (localStorage key `corex.mi.intro.dismissed.v1`
    absent). Click × to dismiss; the key is set and the banner stays
    hidden across reloads until the user clears localStorage. Bumping
    the version suffix (`.v2`, `.v3`) re-shows the banner to everyone —
    useful when major feature changes warrant a re-intro.

    Uses native browser tooltips and the design-token + fallback pattern
    per UI_DESIGN_SYSTEM.md §5.10.
--}}

<div x-data="{ shown: !localStorage.getItem('corex.mi.intro.dismissed.v1') }"
     x-show="shown"
     x-cloak
     x-transition:enter="transition opacity ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition opacity ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     role="region"
     aria-label="Market intelligence introduction"
     style="display: flex; align-items: flex-start; gap: 12px;
            padding: 10px 14px;
            background: var(--surface-2, #f0f2f8);
            border-left: 3px solid var(--brand-icon, #0ea5e9);
            border-top: 1px solid var(--border, rgba(0,0,0,0.07));
            border-bottom: 1px solid var(--border, rgba(0,0,0,0.07));
            color: var(--text-primary, #111827);">

    {{-- Icon column --}}
    <div style="width: 24px; height: 24px; border-radius: 50%;
                background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 16%, transparent);
                color: var(--brand-icon, #0ea5e9);
                display: flex; align-items: center; justify-content: center;
                flex-shrink: 0; font-weight: 700; font-size: 0.75rem;">
        i
    </div>

    {{-- Body --}}
    <div style="flex: 1; min-width: 0; font-size: 0.8125rem; line-height: 1.55;">
        <strong style="color: var(--text-primary, #111827);">Welcome to Market intelligence.</strong>
        This is your canvass list — properties not yet on our books.
        <span style="display: inline-flex; align-items: center; gap: 3px;">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--ds-green, #10b981); display: inline-block;"></span>
            <span>strong-tier buyer match</span>
        </span>
        means high likelihood of conversion;
        <span style="display: inline-flex; align-items: center; gap: 3px;">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--ds-amber, #f59e0b); display: inline-block;"></span>
            <span>mid-tier</span>
        </span>
        is worth a look. Click <em>Property intel</em> on any row to open the full record.
        Switch to <em>Analyse</em> mode (top right) for the weekly market brief and heat matrix.
    </div>

    {{-- Dismiss --}}
    <button type="button"
            @click="localStorage.setItem('corex.mi.intro.dismissed.v1', '1'); shown = false"
            aria-label="Dismiss introduction"
            title="Dismiss this introduction. It will not show again on this device."
            style="background: none; border: none; cursor: pointer;
                   font-size: 1.25rem; line-height: 1; padding: 0 4px;
                   color: var(--text-muted, #9ca3af);">
        ×
    </button>
</div>
