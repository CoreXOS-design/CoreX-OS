{{--
    F.2 — Work / Analyse top bar.

    Left: agency / scope label.
    Centre-right: Work | Analyse mode toggle (segmented control).
    Right: admin in-stock audit toggle (manager-only) + Setup button.

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

<div class="mi-topbar"
     style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--surface); flex-wrap: wrap;">

    <div class="mi-topbar-left" style="display: flex; align-items: center; gap: 10px; min-width: 0;">
        <span style="font-size: 1.0625rem; font-weight: 600; color: var(--text-primary);">Market intelligence</span>
        <span style="color: var(--text-muted); font-size: 0.75rem;">·</span>
        <span style="color: var(--text-secondary); font-size: 0.8125rem;">{{ $agency }}</span>
    </div>

    <div class="mi-topbar-modes inline-flex rounded-md overflow-hidden"
         style="border: 1px solid var(--border);">
        <a href="{{ route('market-intelligence.index', $workQuery) }}"
           class="px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
           style="{{ $mode === 'work' ? 'background: var(--brand-default); color: #fff;' : 'background: var(--surface); color: var(--text-secondary);' }}">
            Work
        </a>
        <a href="{{ route('market-intelligence.index', $analyseQuery) }}"
           class="px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider no-underline"
           style="{{ $mode === 'analyse' ? 'background: var(--brand-default); color: #fff;' : 'background: var(--surface); color: var(--text-secondary);' }}">
            Analyse
        </a>
    </div>

    <div class="mi-topbar-right" style="display: flex; align-items: center; gap: 12px;">
        @if($isManager)
        <label class="inline-flex items-center gap-2 text-xs cursor-pointer"
               style="color: var(--text-secondary);"
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
           class="inline-flex items-center gap-1.5 text-xs font-semibold no-underline px-3 py-1.5 rounded-md"
           style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
           title="Configure prospecting segments and suggested-action thresholds">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            Setup
        </a>
        @endif
    </div>
</div>
