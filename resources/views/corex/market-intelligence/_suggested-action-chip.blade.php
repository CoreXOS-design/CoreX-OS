{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.3 — Suggested-action chip.

    Input: $suggested — SuggestedAction DTO from E.1 (or null).

    Renders one chip per the DTO's tier / icon / click-type. When $suggested
    is null, renders a small inert "—" placeholder so the row stays balanced.

    Tier visual hierarchy (per Build E v2 spec §6.2):
      CRITICAL → solid red, white text — R1 FLAG TO BM, R2 EXPIRING
      ACTION   → teal background-mix, teal text + border — R4/R5/R6/R7
      AWAIT    → amber background-mix, amber text + border — R3 LOG OUTCOME
      INFO     → outline only, slate text — R8/R9

    Spec: build-f-market-intelligence-redesign-spec.md §10;
          build-e-suggested-action-chips-spec.md §6.
--}}

@php
    $s = $suggested ?? null;
    $listingIdForModal = $listing->id ?? 0;
@endphp

@if($s === null)
    <span style="display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; font-size: 0.625rem; font-weight: 600; color: var(--text-muted); border: 1px dashed var(--border); border-radius: 4px; min-width: 60px; text-align: center;"
          title="No suggested action right now.">—</span>
@else
    @php
        // Tier → style. Solid for CRITICAL; tinted bg for ACTION/AWAIT; outline for INFO.
        $tierStyle = match($s->tier) {
            'critical' => 'background: var(--ds-crimson, #dc2626); color: #fff; border: 1px solid var(--ds-crimson, #dc2626);',
            'action'   => 'background: color-mix(in srgb, var(--ds-green, #10b981) 18%, transparent); color: var(--ds-green, #10b981); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 45%, transparent);',
            'await'    => 'background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--ds-amber, #f59e0b); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, transparent);',
            'info'     => 'background: transparent; color: var(--text-secondary); border: 1px solid var(--border);',
            default    => 'background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);',
        };

        // Plain-text tooltip (strip <strong>/<br>) — the title attribute can't render HTML.
        $tooltipText = trim(strip_tags(str_replace(['<br>', '<br/>'], ' — ', $s->tooltipHtml)));

        $iconSvg = match($s->icon) {
            'alarm-clock' => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21a8 8 0 1 0 0-16 8 8 0 0 0 0 16z"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M4 19l-2 2"/><path d="m22 19-2 2"/></svg>',
            'target'      => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
            'clock'       => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
            'info'        => '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
            default       => '',
        };

        $baseChipStyle = 'display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; border-radius: 4px; text-decoration: none; cursor: pointer; white-space: nowrap;';
    @endphp

    @if($s->clickType === 'anchor')
        <a href="{{ $s->href }}"
           class="mi-suggested-chip"
           data-rank="{{ $s->rank }}"
           data-tier="{{ $s->tier }}"
           title="{{ $tooltipText }}"
           style="{{ $baseChipStyle }} {{ $tierStyle }}"
           onclick="event.stopPropagation();">
            {!! $iconSvg !!}
            <span>{{ $s->label }}</span>
        </a>
    @elseif($s->clickType === 'alpine')
        <button type="button"
                class="mi-suggested-chip"
                data-rank="{{ $s->rank }}"
                data-tier="{{ $s->tier }}"
                title="{{ $tooltipText }}"
                style="{{ $baseChipStyle }} {{ $tierStyle }}"
                @click.stop="{{ $s->alpineCall }}">
            {!! $iconSvg !!}
            <span>{{ $s->label }}</span>
        </button>
    @elseif($s->clickType === 'modal')
        <button type="button"
                class="mi-suggested-chip"
                data-rank="{{ $s->rank }}"
                data-tier="{{ $s->tier }}"
                title="{{ $tooltipText }}"
                style="{{ $baseChipStyle }} {{ $tierStyle }}"
                @click.stop="$dispatch('mi-open-modal', { key: '{{ $s->modalKey }}', listingId: {{ $listingIdForModal }} })">
            {!! $iconSvg !!}
            <span>{{ $s->label }}</span>
        </button>
    @endif
@endif
