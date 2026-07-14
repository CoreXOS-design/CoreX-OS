{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">P24 Suburb Mappings</h1>
                <p class="text-sm text-white/60">Map Property24 suburb IDs so CoreX can pull listings and alerts for your areas.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.settings') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Settings
                </a>
            </div>
        </div>
    </div>

    {{-- Flash messages (§3.9 Alert block) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <div class="flex-1">{{ session('error') }}</div>
        </div>
    @endif

    {{-- Filter bar (§3.8) — server-side: the table can hold the full ~27k-row
         national P24 location tree, so filtering and paging happen in SQL. --}}
    <form method="GET" action="{{ route('admin.p24-suburbs.index') }}"
          class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[12rem] max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="q" value="{{ $search }}"
                       placeholder="Search name or P24 ID..."
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md focus:outline-none transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- AT-246 — first-class filters: province → town → region (region = town-level). --}}
            <select name="province" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All provinces</option>
                @foreach($provinceOptions as $pid => $pname)
                <option value="{{ $pid }}" {{ (string) $selectedProvince === (string) $pid ? 'selected' : '' }}>{{ $pname }}</option>
                @endforeach
            </select>

            <select name="town" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All towns</option>
                @foreach($townOptions as $t)
                <option value="{{ $t->p24_city_id }}" {{ (string) $selectedTown === (string) $t->p24_city_id ? 'selected' : '' }}>{{ $t->name }}</option>
                @endforeach
            </select>

            <select name="region" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All regions</option>
                @foreach($regions as $r)
                <option value="{{ $r }}" {{ $selectedRegion === $r ? 'selected' : '' }}>{{ $aliases[$r] ?? $r }}</option>
                @endforeach
            </select>

            <select name="confirmed" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All</option>
                <option value="1" {{ $selectedStatus === '1' ? 'selected' : '' }}>Confirmed</option>
                <option value="0" {{ $selectedStatus === '0' ? 'selected' : '' }}>Unconfirmed</option>
            </select>

            <button type="submit" class="corex-btn-primary text-sm">Search</button>

            @if($search !== '' || $selectedRegion !== '' || $selectedStatus !== '' || $selectedProvince !== '' || $selectedTown !== '')
            <a href="{{ route('admin.p24-suburbs.index') }}" class="corex-btn-outline text-sm">Clear</a>
            @endif

            <span class="text-sm ml-auto" style="color: var(--text-muted);">
                @if($suburbs->total() > 0)
                    Showing {{ number_format($suburbs->firstItem()) }}–{{ number_format($suburbs->lastItem()) }} of {{ number_format($suburbs->total()) }}
                @else
                    No matching suburbs
                @endif
            </span>
        </div>
    </form>

    {{-- Add New Suburb --}}
    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-3">Add New Suburb</h3>

        <form method="POST" action="{{ route('admin.p24-suburbs.store') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Suburb Name</label>
                <input type="text" name="name" required class="rounded-md px-3 py-2 text-sm w-44"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. Margate">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">P24 ID</label>
                <input type="number" name="p24_id" class="rounded-md px-3 py-2 text-sm w-28"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="e.g. 6348">
            </div>
            {{-- AT-246 — the hardcoded "Region: kzn-south-coast" field is retired. A new
                 suburb inherits its region from its P24 town (once mapped), or gets the
                 marked suburb-level fallback in the row below. No pinned default. --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Surrounding IDs</label>
                <input type="text" name="surrounding_ids" class="rounded-md px-3 py-2 text-sm w-36"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="6357,6358">
            </div>
            <div class="flex items-center gap-2">
                <input type="hidden" name="confirmed" value="0">
                <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                    <input type="checkbox" name="confirmed" value="1" class="rounded"
                           style="border: 1px solid var(--border); accent-color: var(--brand-button, #0ea5e9);">
                    Confirmed
                </label>
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add</button>
        </form>
    </div>

    {{-- AT-246 — Region display names (aliases render on top). Rename a municipality
         for your market (Ray Nkonyeni → "Hibiscus Coast"); blank falls back to the
         municipal name. This is what the Region column below shows. --}}
    @if($regions->isNotEmpty())
    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-1">Region display names</h3>
        <p class="text-xs mb-3" style="color: var(--text-muted);">The MDB municipality is the source of truth; give it the name your agency uses. Shown everywhere region appears, including MIC “By region”.</p>
        <div class="flex flex-wrap gap-3">
            @foreach($regions as $muni)
            <form method="POST" action="{{ route('admin.p24-suburbs.alias', ['municipality' => $muni]) }}"
                  class="flex items-end gap-2 rounded-md p-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                @csrf @method('PUT')
                <div>
                    <label class="block text-[10px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">{{ $muni }}</label>
                    <input type="text" name="alias" value="{{ $aliases[$muni] ?? '' }}" placeholder="{{ $muni }}"
                           class="rounded-md px-2 py-1 text-sm w-40"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <button type="submit" class="corex-btn-outline text-xs">Save</button>
            </form>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Suburbs Table --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table id="suburbs-table" class="min-w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-2.5">Name</th>
                        <th class="text-left px-4 py-2.5">Slug</th>
                        <th class="text-right px-4 py-2.5">P24 ID</th>
                        <th class="text-left px-4 py-2.5">P24 Town</th>
                        <th class="text-left px-4 py-2.5">Region <span class="font-normal normal-case" style="color:var(--text-muted);">(per town)</span></th>
                        <th class="text-left px-4 py-2.5">Surrounding</th>
                        <th class="text-center px-4 py-2.5">Confirmed</th>
                        <th class="text-right px-4 py-2.5">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suburbs as $suburb)
                    <tr id="row-{{ $suburb->id }}">
                        <form method="POST" action="{{ route('admin.p24-suburbs.update', $suburb) }}">
                            @csrf
                            @method('PUT')
                            <td class="px-4 py-3">
                                <input type="text" name="name" value="{{ $suburb->name }}" class="rounded-md px-2 py-1 text-sm w-full"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $suburb->slug }}</td>
                            <td class="px-4 py-3">
                                <input type="number" name="p24_id" value="{{ $suburb->p24_id }}" class="rounded-md px-2 py-1 text-sm w-20 text-right"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            </td>
                            @php
                                $cityId   = $suburb->p24_city_id;
                                $townRow  = $cityId ? ($townsByCity[$cityId] ?? null) : null;
                                $cityRow  = $cityId ? ($citiesById[$cityId] ?? null) : null;
                                $townName = $cityRow->name ?? ($townRow->name ?? null);
                                $provName = ($cityRow && isset($provincesById[$cityRow->p24_province_id]))
                                    ? $provincesById[$cityRow->p24_province_id] : null;
                                $townRegion = $townRow->region ?? null;
                            @endphp
                            {{-- P24 Town → Province: read-only P24 hierarchy (AT-246 point 3). --}}
                            <td class="px-4 py-3">
                                @if($townName)
                                    <div class="text-xs font-medium" style="color: var(--text-primary);">{{ $townName }}</div>
                                    <div class="text-[10px]" style="color: var(--text-muted);">{{ $provName ?? '—' }}</div>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            {{-- Region = the TOWN's region (read THROUGH the town), edited at town
                                 (P24 city) level so it applies to every suburb in the town. Assigning
                                 materialises the agency town if one doesn't exist yet, so EVERY suburb
                                 with a P24 city is assignable — never a dead end. The select is
                                 associated (HTML5 form=) with a sibling city-form after the table, so
                                 it never nests inside this suburb row-form. --}}
                            <td class="px-4 py-3">
                                @if($cityId)
                                    <select name="region" form="cityrgn-{{ $suburb->id }}"
                                            onchange="document.getElementById('cityrgn-{{ $suburb->id }}').submit()"
                                            class="rounded-md px-2 py-1 text-sm w-44"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="">— Unassigned —</option>
                                        @foreach($municipalityOptions as $muni)
                                        <option value="{{ $muni }}" @selected($townRegion === $muni)>{{ $aliases[$muni] ?? $muni }}</option>
                                        @endforeach
                                    </select>
                                    <div class="text-[10px] mt-1" style="color: var(--text-muted);"
                                         title="Region is set per town — changing it moves every suburb in {{ $townName }}">
                                        applies to all of {{ $townName ?? 'this town' }}
                                    </div>
                                @else
                                    {{-- Truly townless (a manual suburb with no P24 city): suburb-level
                                         fallback, clearly marked; saved with the row's Save button. --}}
                                    <select name="region"
                                            class="rounded-md px-2 py-1 text-sm w-44"
                                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                        <option value="">— Unassigned —</option>
                                        @foreach($municipalityOptions as $muni)
                                        <option value="{{ $muni }}" @selected(($suburb->region ?? null) === $muni)>{{ $aliases[$muni] ?? $muni }}</option>
                                        @endforeach
                                    </select>
                                    <div class="text-[10px] mt-1" style="color: var(--ds-amber, #b45309);"
                                         title="No P24 town for this suburb — region is set on the suburb itself">
                                        suburb-level (no P24 town) · press Save
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" name="surrounding_ids" value="{{ is_array($suburb->surrounding_ids) ? implode(',', $suburb->surrounding_ids) : '' }}" class="rounded-md px-2 py-1 text-sm w-28"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="6357,6358">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <input type="hidden" name="confirmed" value="0">
                                <input type="checkbox" name="confirmed" value="1" {{ $suburb->confirmed ? 'checked' : '' }} class="rounded"
                                       style="border: 1px solid var(--border); accent-color: var(--brand-button, #0ea5e9);">
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" class="corex-btn-primary text-xs">Save</button>
                        </form>
                                    <form method="POST" action="{{ route('admin.p24-suburbs.destroy', $suburb) }}" class="inline" onsubmit="return confirm('Delete {{ $suburb->name }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1 rounded-md text-xs font-semibold transition-colors"
                                                style="color: var(--ds-crimson, #c41e3a); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 35%, transparent); background: transparent;">Delete</button>
                                    </form>
                                </div>
                            </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            @if($search !== '' || $selectedRegion !== '' || $selectedStatus !== '' || $selectedProvince !== '' || $selectedTown !== '')
                                No suburbs match your filters. <a href="{{ route('admin.p24-suburbs.index') }}" style="color: var(--brand-icon, #0ea5e9);">Clear filters</a>.
                            @else
                                No suburbs configured. Add one above or run the seeder.
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- AT-246 — sibling city-region forms for the per-row Region selects (HTML5
             form= association keeps them out of the suburb row-forms; nesting forms
             is invalid). One per page-row that has a P24 city; assigning materialises
             the agency town if needed and applies to the whole town. --}}
        @foreach($suburbs as $suburb)
            @if($suburb->p24_city_id)
            <form id="cityrgn-{{ $suburb->id }}" method="POST"
                  action="{{ route('admin.p24-suburbs.city-region', $suburb->p24_city_id) }}" class="hidden">
                @csrf @method('PUT')
            </form>
            @endif
        @endforeach

        {{-- Pagination --}}
        @if($suburbs->hasPages())
        <div class="flex items-center justify-between gap-3 px-4 py-3"
             style="border-top: 1px solid var(--border);">
            <span class="text-xs" style="color: var(--text-muted);">
                Page {{ number_format($suburbs->currentPage()) }} of {{ number_format($suburbs->lastPage()) }}
            </span>
            <div class="flex items-center gap-2">
                @if($suburbs->onFirstPage())
                    <span class="px-3 py-1 rounded-md text-xs font-semibold opacity-40"
                          style="border: 1px solid var(--border); color: var(--text-muted);">Previous</span>
                @else
                    <a href="{{ $suburbs->previousPageUrl() }}" class="px-3 py-1 rounded-md text-xs font-semibold transition-colors"
                       style="border: 1px solid var(--border); color: var(--text-primary);">Previous</a>
                @endif

                @if($suburbs->hasMorePages())
                    <a href="{{ $suburbs->nextPageUrl() }}" class="px-3 py-1 rounded-md text-xs font-semibold transition-colors"
                       style="border: 1px solid var(--border); color: var(--text-primary);">Next</a>
                @else
                    <span class="px-3 py-1 rounded-md text-xs font-semibold opacity-40"
                          style="border: 1px solid var(--border); color: var(--text-muted);">Next</span>
                @endif
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
