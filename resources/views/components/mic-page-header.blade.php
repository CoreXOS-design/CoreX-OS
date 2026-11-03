{{-- Market Intelligence shared page header (Pattern A — branded).
     Single source of truth for the MIC header so Work / Opportunities /
     Analyse / Market Pulse / Team / Importer all render identically.
     UI_DESIGN_SYSTEM.md v 2026-04-20 §2.4.

     Props:
       title    — page name (required)
       subtitle — optional context line
     Slot:
       actions  — optional right-aligned action buttons (white-on-navy). --}}
@props(['title', 'subtitle' => null, 'compact' => false])
<div class="corex-page-banner" style="{{ $compact ? 'margin-bottom: 8px; padding-top: 4px; padding-bottom: 4px;' : 'margin-bottom: 16px;' }}">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between {{ $compact ? 'gap-1' : 'gap-3' }}">
        @if($compact)
        {{-- One line: "MARKET INTELLIGENCE · Work" (mic-work-tab.md 13.2b). No subtitle. --}}
        <div style="display: flex; align-items: baseline; gap: 6px;">
            <span class="font-semibold uppercase" style="font-size: 0.625rem; letter-spacing: 0.06em; color: var(--text-faint);">Market Intelligence</span>
            <span style="color: var(--text-faint);">·</span>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $title }}</h1>
        </div>
        @else
        <div>
            <div class="font-semibold uppercase" style="font-size: 0.625rem; letter-spacing: 0.06em; color: var(--text-faint);">Market Intelligence</div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $title }}</h1>
            @if($subtitle)
            <p class="text-xs" style="margin: 2px 0 0 0; color: var(--text-muted);">{{ $subtitle }}</p>
            @endif
        </div>
        @endif
        {{-- Always rendered so the tour "?" launcher has a header home on every MIC
             page; the surface variant matches the flat neutral header chrome. The
             launcher self-gates (renders nothing on tour-less pages). --}}
        <div class="flex flex-wrap items-center gap-2">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            {{ $actions ?? '' }}
        </div>
    </div>
</div>
