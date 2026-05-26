{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.2 — Work / Analyse branded header (Pattern A per §2.4).

    Left: page title + agency subtitle.
    Right: Work | Analyse mode toggle (segmented control) + Setup button
    + manager-only "Show in-stock too" toggle.

    Query-string preservation: every link merges request()->except(...) so
    flipping the mode does not silently wipe the user's filters.

    Spec: build-f-market-intelligence-redesign-spec.md §8.1.
--}}
@php
    $mode = request('mode', 'work') === 'analyse' ? 'analyse' : 'work';
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;
    $includeInStockToggle = (bool) request()->boolean('include_in_stock');
    $agency = auth()->user()?->agency?->name ?? 'Your agency';

    $workQuery    = array_merge(request()->except(['mode']), ['mode' => 'work']);
    $analyseQuery = array_merge(request()->except(['mode']), ['mode' => 'analyse']);
@endphp

<div class="rounded-md px-6 py-5 mb-4" style="background: var(--brand-default, #0b2a4a);">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-white leading-tight">Market Intelligence</h1>
            <p class="text-sm text-white/60">{{ $agency }} — canvass list of properties not yet on your books.</p>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            {{-- Work / Analyse segmented toggle --}}
            <div class="inline-flex rounded-md overflow-hidden"
                 style="border: 1px solid rgba(255,255,255,0.25); background: rgba(255,255,255,0.08);">
                <a href="{{ route('market-intelligence.index', $workQuery) }}"
                   class="px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
                   style="{{ $mode === 'work' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'color: rgba(255,255,255,0.7);' }}">
                    Work
                </a>
                <a href="{{ route('market-intelligence.index', $analyseQuery) }}"
                   class="px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
                   style="{{ $mode === 'analyse' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'color: rgba(255,255,255,0.7);' }}">
                    Analyse
                </a>
            </div>

            @if($isManager)
                <label class="inline-flex items-center gap-2 text-xs cursor-pointer text-white/70"
                       title="Audit-only: include listings already promoted to agency stock">
                    <input type="checkbox"
                           {{ $includeInStockToggle ? 'checked' : '' }}
                           onchange="(function(cb){
                               const url = new URL(window.location.href);
                               if (cb.checked) { url.searchParams.set('include_in_stock','1'); }
                               else { url.searchParams.delete('include_in_stock'); }
                               window.location.href = url.toString();
                           })(this)">
                    Show in-stock too
                </label>

                <a href="{{ route('settings.prospecting.index') }}"
                   class="corex-btn-outline"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);"
                   title="Configure prospecting segments and suggested-action thresholds">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block mr-1">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    Setup
                </a>
            @endif
        </div>
    </div>
</div>
