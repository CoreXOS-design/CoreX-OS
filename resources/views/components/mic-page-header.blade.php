{{-- Market Intelligence shared page header (Pattern A — branded).
     Single source of truth for the MIC header so Work / Opportunities /
     Analyse / Market Pulse / Team / Importer all render identically.
     UI_DESIGN_SYSTEM.md v 2026-04-20 §2.4.

     Props:
       title    — page name (required)
       subtitle — optional context line
     Slot:
       actions  — optional right-aligned action buttons (white-on-navy). --}}
@props(['title', 'subtitle' => null])
<div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a); margin-bottom: 16px;">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <div class="text-xs font-semibold uppercase text-white/50" style="letter-spacing: 0.06em;">Market Intelligence</div>
            <h1 class="text-xl font-bold text-white leading-tight">{{ $title }}</h1>
            @if($subtitle)
            <p class="text-sm text-white/60" style="margin: 4px 0 0 0;">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
        <div class="flex items-center gap-2 flex-wrap">{{ $actions }}</div>
        @endisset
    </div>
</div>
