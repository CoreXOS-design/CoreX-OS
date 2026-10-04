@props([
    // [['key' => string|null, 'label' => string, 'count' => int, 'href' => string, 'active' => bool], ...]
    // First entry is expected to be the "Total" tile. Caller builds hrefs/active
    // state (each list's own status vocabulary differs) — this component only renders.
    'tiles' => [],
    'scopeOptions' => [],
    'resolvedScope' => null,
    'routeName' => null,
    'perPage' => 25,
    'perPageOptions' => [10, 25, 50, 100],
    'archivable' => false,
    'archived' => false,
    'printUrl' => null,
    'exportXlsxUrl' => null,
    'exportCsvUrl' => null,
])

{{--
    .ai/specs/rentals-rebuild.md §1.1 — the shared rental list standard: one
    partial for the status-tile row + list toolbar (per-page, show archived,
    print list, export), used by Leases/Inspections/Fault Reports/Work
    Orders alike instead of four copies of the same markup. Visual pattern
    matches the Properties-screen `pstat-v2` tiles already shipping on the
    Command Centre and Job Cards list — not a new design.

    Search/sort/filter stay each screen's own <form> (named fields differ
    per entity, per BUILD_STANDARD §1b) — this component does not render
    them.
--}}

@if(count($scopeOptions) > 1)
<div class="flex items-center gap-2">
    <span class="text-xs font-medium" style="color: var(--text-secondary);">Showing:</span>
    <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        @foreach($scopeOptions as $i => $sc)
        <a href="{{ route($routeName, array_merge(request()->except(['scope', 'page']), ['scope' => $sc])) }}"
           class="px-3 py-1.5 text-xs font-semibold"
           style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ $resolvedScope === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }}</a>
        @endforeach
    </div>
</div>
@endif

@if(!empty($tiles))
<div style="display:grid; grid-template-columns: repeat({{ count($tiles) }}, minmax(0, 1fr)); gap: 0.5rem;">
    @foreach($tiles as $tile)
        <a href="{{ $tile['href'] }}"
           class="pstat-v2 px-3.5 py-2 flex items-center justify-between gap-3 no-underline cursor-pointer"
           style="{{ ($tile['active'] ?? false) ? 'border-color:color-mix(in srgb, var(--brand-icon,#6366f1) 40%, transparent);background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface));' : '' }}">
            <div class="min-w-0">
                <div class="text-lg font-bold leading-none tabular-nums" style="color:var(--text-primary);">{{ number_format((int) $tile['count']) }}</div>
                <div class="text-[0.6875rem] font-medium mt-0.5 uppercase tracking-wider" style="color:var(--text-muted);">{{ $tile['label'] }}</div>
            </div>
        </a>
    @endforeach
</div>
@endif

<div class="flex flex-wrap items-center justify-between gap-2">
    <div class="flex items-center gap-2">
        @if($archivable)
            <a href="{{ route($routeName, array_merge(request()->except(['archived', 'page']), ['archived' => $archived ? null : 1])) }}"
               class="corex-btn-outline text-xs {{ $archived ? 'corex-tab-active' : '' }}">
                {{ $archived ? 'Hide archived' : 'Show archived' }}
            </a>
        @endif
    </div>
    <div class="flex items-center gap-2">
        @if($routeName)
            <label class="text-xs flex items-center gap-1" style="color: var(--text-muted);">
                Per page
                <select onchange="window.location.href = this.value" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
                    @foreach($perPageOptions as $opt)
                        <option value="{{ route($routeName, array_merge(request()->except(['page']), ['per_page' => $opt])) }}" @selected((int) $perPage === (int) $opt)>{{ $opt }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if($printUrl)
            <a href="{{ $printUrl }}" target="_blank" class="corex-btn-outline text-xs">Print list</a>
        @endif
        @if($exportXlsxUrl)
            <a href="{{ $exportXlsxUrl }}" class="corex-btn-outline text-xs">Export (.xlsx)</a>
        @endif
        @if($exportCsvUrl)
            <a href="{{ $exportCsvUrl }}" class="corex-btn-outline text-xs">Export (.csv)</a>
        @endif
    </div>
</div>
