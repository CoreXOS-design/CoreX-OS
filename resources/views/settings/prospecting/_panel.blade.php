{{-- Prospecting Setup panel body.
     Shared by:
       - resources/views/settings/prospecting/index.blade.php (standalone page)
       - resources/views/prospecting/index.blade.php (drawer on the prospecting tab)
     Receives: $activeTab, $towns, $propertyTypes, $bedroomSegments,
               $priceBandsSale, $priceBandsRental, and $context ('page' or 'drawer').
     Both contexts POST to the same Prompt-04 routes; the drawer relies on a
     #setup-open URL fragment + Alpine x-init to re-open itself after a
     full-page reload. --}}
@php
    $context = $context ?? 'page';
    $suggestionsUrlBase = url('/corex/settings/prospecting/suggestions');
@endphp

<div class="space-y-5"
     x-data="{
        activeTab: '{{ in_array($activeTab ?? 'towns', ['towns', '']) ? 'property-types' : $activeTab }}',
        showSuggestionPicker: false,
        suggestionRegion: '',
        suggestionData: null,
        suggestionLoading: false,
        suggestionError: null,
        async loadRegion(key) {
            this.suggestionData = null;
            if (!key) return;
            this.suggestionLoading = true;
            this.suggestionError = null;
            try {
                const r = await fetch('{{ $suggestionsUrlBase }}/' + key, {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('Could not load suggestions (' + r.status + ')');
                const data = await r.json();
                data.towns.forEach(t => {
                    t._include = t.suburbs.some(s => !s.already_exists);
                    t.suburbs.forEach(s => { s._include = !s.already_exists; });
                });
                this.suggestionData = data;
            } catch (e) {
                this.suggestionError = e.message || 'Failed to load';
            } finally {
                this.suggestionLoading = false;
            }
        },
     }"
     @if($context === 'page')
       x-init="$watch('activeTab', v => { const u = new URL(window.location); u.searchParams.set('tab', v); window.history.replaceState({}, '', u); })"
     @endif
     >

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <strong>{{ $errors->first() }}</strong>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);">
        {{-- AT-246 — the "Towns & Suburbs" tab and the separate "Regions" door are
             RETIRED from nav. Region assignment now lives on exactly ONE surface:
             Settings → P24 Suburb Mappings (the region column, read through each
             suburb's town). The tab content + regions route are kept (soft) but
             have no nav door. --}}
        @foreach(['property-types' => 'Property Types', 'bedroom-segments' => 'Bedroom Segments', 'price-bands' => 'Price Bands', 'buyer-match-tiers' => 'Buyer Match Tiers'] as $key => $label)
            <button @click="activeTab = '{{ $key }}'"
                    :class="activeTab === '{{ $key }}' ? 'border-b-2' : 'border-b-2 border-transparent'"
                    :style="activeTab === '{{ $key }}' ? 'color: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-secondary);'"
                    class="px-4 py-3 text-xs font-semibold whitespace-nowrap">{{ $label }}</button>
        @endforeach
    </div>

    {{-- TAB 1 (RETIRED, AT-246): Towns & Suburbs — no nav door; region assignment
         moved to the P24 Suburb Mappings screen. Kept hidden (soft retire). --}}
    <div x-show="false" x-cloak class="space-y-4">

        {{-- Unmapped Suburbs Cleanup Widget (Prompt 07).
             Surfaces every suburb found in the agency's listings or active
             wishlists that isn't yet mapped to a town. One-click "Map" per row
             attaches the suburb to a chosen town. Maximum-leverage cleanup. --}}
        @php $unmappedSuburbs = $unmappedSuburbs ?? collect(); @endphp
        @if($unmappedSuburbs->isNotEmpty())
            <div class="rounded-md p-4"
                 x-data="{ showUnmapped: true }"
                 style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 40%, var(--border));">

                <div class="flex items-start justify-between gap-3 mb-3">
                    <div class="min-w-0">
                        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--ds-amber, #b45309);">
                            ⚠ {{ $unmappedSuburbs->count() }} unmapped suburb{{ $unmappedSuburbs->count() === 1 ? '' : 's' }} found
                        </h3>
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">
                            These suburbs appear in your listings or active buyer wishlists but aren't yet mapped to a town.
                            Map them to surface their <strong>{{ $unmappedSuburbs->sum('total') }}</strong> listings &amp; buyers in the correct town buckets.
                        </p>
                    </div>
                    <button type="button" @click="showUnmapped = !showUnmapped"
                            class="text-[10px] font-semibold px-2 py-1 rounded flex-shrink-0"
                            style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                        <span x-text="showUnmapped ? 'Hide list' : 'Show list'"></span>
                    </button>
                </div>

                <div x-show="showUnmapped" x-cloak>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr style="border-bottom: 1px solid var(--border);">
                                    <th class="text-left py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Suburb (as found)</th>
                                    <th class="text-center py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Listings</th>
                                    <th class="text-center py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Wishlists</th>
                                    <th class="text-left py-2 px-3 text-[10px] uppercase tracking-wider" style="color: var(--text-muted);">Map to town</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($unmappedSuburbs as $row)
                                    <tr style="border-bottom: 1px solid var(--border);">
                                        <td class="py-2 px-3" style="color: var(--text-primary);">
                                            <div class="font-medium">{{ $row['suburb_raw'] }}</div>
                                            <div class="text-[10px]" style="color: var(--text-muted);">normalised: {{ $row['suburb_normalised'] }}</div>
                                        </td>
                                        <td class="text-center py-2 px-3">
                                            @if($row['listing_count'] > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold"
                                                      style="background: color-mix(in srgb, var(--brand-button) 15%, transparent); color: var(--brand-button);">
                                                    {{ $row['listing_count'] }}
                                                </span>
                                            @else
                                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center py-2 px-3">
                                            @if($row['wishlist_count'] > 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold"
                                                      style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 20%, transparent); color: var(--ds-amber, #b45309);">
                                                    {{ $row['wishlist_count'] }}
                                                </span>
                                            @else
                                                <span class="text-xs" style="color: var(--text-muted);">—</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3">
                                            <form method="POST" action="{{ route('settings.prospecting.suburbs.map') }}" class="flex items-center gap-2 flex-wrap">
                                                @csrf
                                                <input type="hidden" name="suburb_name" value="{{ $row['suburb_raw'] }}">
                                                <select name="town_id" required
                                                        class="text-xs px-2 py-1 rounded"
                                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                                    <option value="">— pick a town —</option>
                                                    @foreach($towns as $town)
                                                        <option value="{{ $town->id }}">{{ $town->name }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit"
                                                        class="text-[10px] font-semibold px-3 py-1 rounded text-white"
                                                        style="background: var(--brand-button);">
                                                    Map
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[11px] mt-3" style="color: var(--text-muted);">
                        💡 Tip: if a suburb in your area isn't really a separate place (e.g. "Margate Beach" is part of Margate),
                        map it to its parent town. If it really IS a new town in your area, add the town first in the list below,
                        then come back here to map the suburb.
                    </p>
                </div>
            </div>
        @endif

        {{-- Build from suggested regions (curated SA library) --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                <div>
                    <h2 class="text-xs font-semibold uppercase tracking-wider mb-1" style="color: var(--text-muted);">Quick start</h2>
                    <p class="text-xs" style="color: var(--text-secondary);">
                        Build your town list from a curated South African region library. Pick a region,
                        review the suggestions, tick which to include, and import in one go. Suburbs that
                        already exist in your data are flagged and skipped.
                    </p>
                </div>
                <button type="button"
                        @click="showSuggestionPicker = !showSuggestionPicker; if (showSuggestionPicker && !suggestionData) suggestionRegion = ''"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md whitespace-nowrap"
                        style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">
                    📍 <span x-text="showSuggestionPicker ? 'Hide region picker' : 'Build from suggested regions'"></span>
                </button>
            </div>

            <div x-show="showSuggestionPicker" x-cloak class="mt-4 space-y-3 pt-3" style="border-top: 1px solid var(--border);">
                <div class="flex flex-col md:flex-row gap-2 items-start md:items-center">
                    <label class="text-xs font-medium" style="color: var(--text-secondary);">Region:</label>
                    <select x-model="suggestionRegion" @change="loadRegion(suggestionRegion)"
                            class="rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">— Select a region —</option>
                        @foreach($suggestionRegions ?? [] as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <template x-if="suggestionLoading">
                        <span class="text-xs" style="color: var(--text-muted);">Loading…</span>
                    </template>
                    <template x-if="suggestionError">
                        <span class="text-xs" style="color: var(--ds-crimson);" x-text="suggestionError"></span>
                    </template>
                </div>

                <template x-if="suggestionData">
                    <form method="POST" action="{{ route('settings.prospecting.towns.bulk-import') }}" class="space-y-3">
                        @csrf
                        <div class="space-y-2">
                            <template x-for="(town, ti) in suggestionData.towns" :key="town.name">
                                <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                                    <label class="flex items-center gap-2 cursor-pointer mb-2">
                                        <input type="checkbox" x-model="town._include" class="rounded">
                                        <span class="text-sm font-semibold" style="color: var(--text-primary);" x-text="town.name"></span>
                                        <span class="text-[10px]" style="color: var(--text-muted);" x-text="'(' + town.suburbs.length + ' suburb' + (town.suburbs.length === 1 ? '' : 's') + ')'"></span>
                                    </label>
                                    <div class="pl-6 flex flex-wrap gap-2">
                                        <template x-for="(suburb, si) in town.suburbs" :key="suburb.name">
                                            <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer">
                                                <input type="checkbox" x-model="suburb._include" :disabled="!town._include || suburb.already_exists" class="rounded">
                                                <span x-text="suburb.name" :style="suburb.already_exists ? 'color: var(--text-muted); text-decoration: line-through;' : 'color: var(--text-primary);'"></span>
                                                <template x-if="suburb.already_exists">
                                                    <span class="text-[10px] px-1 py-0.5 rounded" style="background: rgba(16,185,129,0.10); color: #047857;">already in your data</span>
                                                </template>
                                            </label>
                                        </template>
                                    </div>
                                    {{-- Hidden inputs for selected suburbs --}}
                                    <template x-for="(suburb, si) in town.suburbs" :key="'h-' + suburb.name">
                                        <template x-if="town._include && suburb._include && !suburb.already_exists">
                                            <input type="hidden" :name="'towns[' + ti + '][suburbs][]'" :value="suburb.name">
                                        </template>
                                    </template>
                                    <template x-if="town._include">
                                        <input type="hidden" :name="'towns[' + ti + '][name]'" :value="town.name">
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-between items-center pt-2" style="border-top: 1px solid var(--border);">
                            <span class="text-xs" style="color: var(--text-muted);">
                                Importing will add only ticked items not already in your data.
                            </span>
                            <div class="flex gap-2">
                                <button type="button" @click="suggestionData = null; suggestionRegion = ''"
                                        class="text-xs font-medium px-3 py-1.5 rounded-md"
                                        style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">Cancel</button>
                                <button type="submit"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-md text-white"
                                        style="background: var(--brand-button);">Import selected</button>
                            </div>
                        </div>
                    </form>
                </template>
            </div>
        </div>

        {{-- Add Town inline form --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <h2 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Add Town</h2>
            <form method="POST" action="{{ route('settings.prospecting.towns.store') }}" class="flex flex-col md:flex-row gap-2">
                @csrf
                <input type="text" name="name" required maxlength="100" placeholder="Town name (e.g. Margate)"
                       class="flex-1 rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <input type="text" name="region" maxlength="100" placeholder="Region (optional)"
                       class="md:w-64 rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button);">+ Add Town</button>
            </form>
        </div>

        {{-- Towns list --}}
        <div class="space-y-2">
            @forelse($towns as $i => $town)
                <div x-data="{ editingTown: false, addingSuburb: false, editingSuburbId: null }"
                     class="rounded-md" style="background: var(--surface-2); border: 1px solid var(--border);">

                    {{-- Town header (read) --}}
                    <div x-show="!editingTown" class="flex items-center justify-between px-4 py-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-[10px] font-mono px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">#{{ $town->display_order }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $town->name }}</div>
                                @if($town->region)
                                    <div class="text-[10px]" style="color: var(--text-muted);">{{ $town->region }}</div>
                                @endif
                            </div>
                            <span class="text-[10px] px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">{{ $town->suburbs->count() }} suburb{{ $town->suburbs->count() === 1 ? '' : 's' }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            {{-- Reorder up/down --}}
                            @if($i > 0)
                                <form method="POST" action="{{ route('settings.prospecting.towns.reorder') }}" class="inline">
                                    @csrf
                                    @foreach($towns as $j => $t)
                                        @php
                                            $newPos = $j === $i ? $i - 1 : ($j === $i - 1 ? $i : $j);
                                        @endphp
                                        <input type="hidden" name="order[{{ $newPos }}]" value="{{ $t->id }}">
                                    @endforeach
                                    <button type="submit" title="Move up" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↑</button>
                                </form>
                            @endif
                            @if($i < $towns->count() - 1)
                                <form method="POST" action="{{ route('settings.prospecting.towns.reorder') }}" class="inline">
                                    @csrf
                                    @foreach($towns as $j => $t)
                                        @php
                                            $newPos = $j === $i ? $i + 1 : ($j === $i + 1 ? $i : $j);
                                        @endphp
                                        <input type="hidden" name="order[{{ $newPos }}]" value="{{ $t->id }}">
                                    @endforeach
                                    <button type="submit" title="Move down" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↓</button>
                                </form>
                            @endif
                            <button type="button" @click="addingSuburb = !addingSuburb" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">+ Suburb</button>
                            <button type="button" @click="editingTown = true" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">Edit</button>
                            <form method="POST" action="{{ route('settings.prospecting.towns.archive', $town) }}" class="inline" onsubmit="return confirm('Archive {{ $town->name }}? Its suburbs remain mapped but the town is hidden.');">
                                @csrf
                                <button type="submit" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.25);">Archive</button>
                            </form>
                        </div>
                    </div>

                    {{-- Town inline edit form --}}
                    <form x-show="editingTown" x-cloak method="POST" action="{{ route('settings.prospecting.towns.update', $town) }}" class="px-4 py-3 flex flex-col md:flex-row gap-2">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" required maxlength="100" value="{{ $town->name }}"
                               class="flex-1 rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="text" name="region" maxlength="100" value="{{ $town->region }}" placeholder="Region (optional)"
                               class="md:w-48 rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="text-xs font-semibold px-3 py-2 rounded-md text-white" style="background: var(--brand-button);">Save</button>
                        <button type="button" @click="editingTown = false" class="text-xs font-medium px-3 py-2 rounded-md" style="background: var(--surface); color: var(--text-muted);">Cancel</button>
                    </form>

                    {{-- Add suburb inline --}}
                    <form x-show="addingSuburb" x-cloak method="POST" action="{{ route('settings.prospecting.suburbs.store', $town) }}" class="px-4 py-3 flex gap-2" style="border-top: 1px solid var(--border); background: var(--surface);">
                        @csrf
                        <input type="text" name="suburb_name" required maxlength="150" placeholder="Suburb name"
                               class="flex-1 rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="text-xs font-semibold px-3 py-2 rounded-md text-white" style="background: var(--brand-button);">Add</button>
                        <button type="button" @click="addingSuburb = false" class="text-xs font-medium px-3 py-2 rounded-md" style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);">Cancel</button>
                    </form>

                    {{-- Suburbs list --}}
                    @if($town->suburbs->isNotEmpty())
                        <div class="px-4 py-2" style="border-top: 1px solid var(--border);">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($town->suburbs as $suburb)
                                    <div x-data="{ editing: false }" class="inline-flex items-center gap-1">
                                        <div x-show="!editing" class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                            <span>{{ $suburb->suburb_name }}</span>
                                            <button type="button" @click="editing = true" class="text-[10px] opacity-60 hover:opacity-100">✎</button>
                                            <form method="POST" action="{{ route('settings.prospecting.suburbs.archive', $suburb) }}" class="inline" onsubmit="return confirm('Archive suburb {{ $suburb->suburb_name }}?');">
                                                @csrf
                                                <button type="submit" class="text-[10px] opacity-60 hover:opacity-100" style="color: #b91c1c;">×</button>
                                            </form>
                                        </div>
                                        <form x-show="editing" x-cloak method="POST" action="{{ route('settings.prospecting.suburbs.update', $suburb) }}" class="inline-flex items-center gap-1">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="suburb_name" required maxlength="150" value="{{ $suburb->suburb_name }}"
                                                   class="rounded px-2 py-1 text-xs"
                                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                            <button type="submit" class="text-[10px] font-semibold px-2 py-1 rounded text-white" style="background: var(--brand-button);">Save</button>
                                            <button type="button" @click="editing = false" class="text-[10px] px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted);">Cancel</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm py-6 text-center" style="color: var(--text-muted);">No towns yet. Add one above.</p>
            @endforelse
        </div>
    </div>

    {{-- TAB 2: Property Types --}}
    <div x-show="activeTab === 'property-types'" x-cloak class="space-y-4">
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <h2 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Add Property Type</h2>
            <form method="POST" action="{{ route('settings.prospecting.property-types.store') }}" class="flex gap-2">
                @csrf
                <input type="text" name="name" required maxlength="100" placeholder="Property type name"
                       class="flex-1 rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button);">+ Add</button>
            </form>
        </div>

        <div class="space-y-1">
            @forelse($propertyTypes as $i => $type)
                <div x-data="{ editing: false }" class="rounded-md flex items-center justify-between px-4 py-2" style="background: var(--surface-2); border: 1px solid var(--border); {{ $type->is_active ? '' : 'opacity: 0.5;' }}">
                    <div x-show="!editing" class="flex items-center gap-3 min-w-0">
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">#{{ $type->display_order }}</span>
                        <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $type->name }}</span>
                        @if(!$type->is_active)
                            <span class="text-[10px] px-1.5 py-0.5 rounded" style="background: rgba(245,158,11,0.15); color: #b45309;">Inactive</span>
                        @endif
                    </div>
                    <form x-show="editing" x-cloak method="POST" action="{{ route('settings.prospecting.property-types.update', $type) }}" class="flex-1 flex gap-2">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" required maxlength="100" value="{{ $type->name }}"
                               class="flex-1 rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="text-xs font-semibold px-3 py-2 rounded-md text-white" style="background: var(--brand-button);">Save</button>
                        <button type="button" @click="editing = false" class="text-xs px-3 py-2 rounded-md" style="background: var(--surface); color: var(--text-muted);">Cancel</button>
                    </form>

                    <div x-show="!editing" class="flex items-center gap-1">
                        @if($i > 0)
                            <form method="POST" action="{{ route('settings.prospecting.property-types.reorder') }}" class="inline">
                                @csrf
                                @foreach($propertyTypes as $j => $t)
                                    @php $newPos = $j === $i ? $i - 1 : ($j === $i - 1 ? $i : $j); @endphp
                                    <input type="hidden" name="order[{{ $newPos }}]" value="{{ $t->id }}">
                                @endforeach
                                <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↑</button>
                            </form>
                        @endif
                        @if($i < $propertyTypes->count() - 1)
                            <form method="POST" action="{{ route('settings.prospecting.property-types.reorder') }}" class="inline">
                                @csrf
                                @foreach($propertyTypes as $j => $t)
                                    @php $newPos = $j === $i ? $i + 1 : ($j === $i + 1 ? $i : $j); @endphp
                                    <input type="hidden" name="order[{{ $newPos }}]" value="{{ $t->id }}">
                                @endforeach
                                <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↓</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('settings.prospecting.property-types.toggle', $type) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);">{{ $type->is_active ? 'Deactivate' : 'Activate' }}</button>
                        </form>
                        <button type="button" @click="editing = true" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">Edit</button>
                        <form method="POST" action="{{ route('settings.prospecting.property-types.archive', $type) }}" class="inline" onsubmit="return confirm('Archive {{ $type->name }}?');">
                            @csrf
                            <button type="submit" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.25);">Archive</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm py-6 text-center" style="color: var(--text-muted);">No property types yet.</p>
            @endforelse
        </div>
    </div>

    {{-- TAB 3: Bedroom Segments --}}
    <div x-show="activeTab === 'bedroom-segments'" x-cloak class="space-y-4">
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <h2 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Add Bedroom Segment</h2>
            <form method="POST" action="{{ route('settings.prospecting.bedroom-segments.store') }}" class="grid grid-cols-1 md:grid-cols-[1fr,100px,100px,auto] gap-2">
                @csrf
                <input type="text" name="name" required maxlength="50" placeholder="Name (e.g. 1 bed, Studio)"
                       class="rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <input type="number" name="beds_min" required min="0" max="20" placeholder="Min"
                       class="rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <input type="number" name="beds_max" min="0" max="20" placeholder="Max (blank = ∞)"
                       class="rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button);">+ Add</button>
            </form>
        </div>

        <div class="space-y-1">
            @forelse($bedroomSegments as $i => $segment)
                <div x-data="{ editing: false }" class="rounded-md flex items-center justify-between px-4 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <div x-show="!editing" class="flex items-center gap-3">
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">#{{ $segment->display_order }}</span>
                        <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $segment->name }}</span>
                        <span class="text-[10px]" style="color: var(--text-muted);">{{ $segment->beds_min }}{{ $segment->beds_max !== null ? ' to ' . $segment->beds_max : '+' }} beds</span>
                    </div>
                    <form x-show="editing" x-cloak method="POST" action="{{ route('settings.prospecting.bedroom-segments.update', $segment) }}" class="flex-1 grid grid-cols-[1fr,80px,80px,auto,auto] gap-2 items-center">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" required maxlength="50" value="{{ $segment->name }}"
                               class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="number" name="beds_min" required min="0" max="20" value="{{ $segment->beds_min }}"
                               class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="number" name="beds_max" min="0" max="20" value="{{ $segment->beds_max }}" placeholder="∞"
                               class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="text-xs font-semibold px-3 py-2 rounded-md text-white" style="background: var(--brand-button);">Save</button>
                        <button type="button" @click="editing = false" class="text-xs px-3 py-2 rounded-md" style="background: var(--surface); color: var(--text-muted);">Cancel</button>
                    </form>
                    <div x-show="!editing" class="flex items-center gap-1">
                        @if($i > 0)
                            <form method="POST" action="{{ route('settings.prospecting.bedroom-segments.reorder') }}" class="inline">
                                @csrf
                                @foreach($bedroomSegments as $j => $s)
                                    @php $newPos = $j === $i ? $i - 1 : ($j === $i - 1 ? $i : $j); @endphp
                                    <input type="hidden" name="order[{{ $newPos }}]" value="{{ $s->id }}">
                                @endforeach
                                <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↑</button>
                            </form>
                        @endif
                        @if($i < $bedroomSegments->count() - 1)
                            <form method="POST" action="{{ route('settings.prospecting.bedroom-segments.reorder') }}" class="inline">
                                @csrf
                                @foreach($bedroomSegments as $j => $s)
                                    @php $newPos = $j === $i ? $i + 1 : ($j === $i + 1 ? $i : $j); @endphp
                                    <input type="hidden" name="order[{{ $newPos }}]" value="{{ $s->id }}">
                                @endforeach
                                <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↓</button>
                            </form>
                        @endif
                        <button type="button" @click="editing = true" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">Edit</button>
                        <form method="POST" action="{{ route('settings.prospecting.bedroom-segments.archive', $segment) }}" class="inline" onsubmit="return confirm('Archive segment {{ $segment->name }}?');">
                            @csrf
                            <button type="submit" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.25);">Archive</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-sm py-6 text-center" style="color: var(--text-muted);">No bedroom segments yet.</p>
            @endforelse
        </div>
    </div>

    {{-- TAB 4: Price Bands --}}
    <div x-show="activeTab === 'price-bands'" x-cloak class="space-y-6">
        @foreach(['sale' => ['label' => 'Sale price bands (rand)', 'data' => $priceBandsSale], 'rental' => ['label' => 'Rental price bands (rand / month)', 'data' => $priceBandsRental]] as $type => $section)
            <div class="space-y-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">{{ $section['label'] }}</h2>

                <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <form method="POST" action="{{ route('settings.prospecting.price-bands.store') }}" class="grid grid-cols-1 md:grid-cols-[1fr,140px,140px,auto] gap-2">
                        @csrf
                        <input type="hidden" name="listing_type" value="{{ $type }}">
                        <input type="text" name="name" required maxlength="100" placeholder="Band name (e.g. {{ $type === 'sale' ? 'Mid' : 'Standard' }})"
                               class="rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="number" name="price_min" required min="0" step="{{ $type === 'sale' ? '50000' : '500' }}" placeholder="Min (R)"
                               class="rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <input type="number" name="price_max" min="0" step="{{ $type === 'sale' ? '50000' : '500' }}" placeholder="Max (R, blank = ∞)"
                               class="rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button);">+ Add Band</button>
                    </form>
                </div>

                <div class="space-y-1">
                    @forelse($section['data'] as $i => $band)
                        <div x-data="{ editing: false }" class="rounded-md flex items-center justify-between px-4 py-2" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div x-show="!editing" class="flex items-center gap-3">
                                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">#{{ $band->display_order }}</span>
                                <span class="text-sm font-medium" style="color: var(--text-primary);">{{ $band->name }}</span>
                                <span class="text-[10px]" style="color: var(--text-muted);">R {{ number_format($band->price_min) }}{{ $band->price_max !== null ? ' – R ' . number_format($band->price_max) : '+' }}</span>
                            </div>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('settings.prospecting.price-bands.update', $band) }}" class="flex-1 grid grid-cols-[1fr,120px,120px,auto,auto] gap-2 items-center">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="listing_type" value="{{ $band->listing_type }}">
                                <input type="text" name="name" required maxlength="100" value="{{ $band->name }}"
                                       class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="number" name="price_min" required min="0" step="{{ $type === 'sale' ? '50000' : '500' }}" value="{{ $band->price_min }}"
                                       class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="number" name="price_max" min="0" step="{{ $type === 'sale' ? '50000' : '500' }}" value="{{ $band->price_max }}" placeholder="∞"
                                       class="rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                <button type="submit" class="text-xs font-semibold px-3 py-2 rounded-md text-white" style="background: var(--brand-button);">Save</button>
                                <button type="button" @click="editing = false" class="text-xs px-3 py-2 rounded-md" style="background: var(--surface); color: var(--text-muted);">Cancel</button>
                            </form>
                            <div x-show="!editing" class="flex items-center gap-1">
                                @if($i > 0)
                                    <form method="POST" action="{{ route('settings.prospecting.price-bands.reorder') }}" class="inline">
                                        @csrf
                                        @foreach($section['data'] as $j => $b)
                                            @php $newPos = $j === $i ? $i - 1 : ($j === $i - 1 ? $i : $j); @endphp
                                            <input type="hidden" name="order[{{ $newPos }}]" value="{{ $b->id }}">
                                        @endforeach
                                        <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↑</button>
                                    </form>
                                @endif
                                @if($i < $section['data']->count() - 1)
                                    <form method="POST" action="{{ route('settings.prospecting.price-bands.reorder') }}" class="inline">
                                        @csrf
                                        @foreach($section['data'] as $j => $b)
                                            @php $newPos = $j === $i ? $i + 1 : ($j === $i + 1 ? $i : $j); @endphp
                                            <input type="hidden" name="order[{{ $newPos }}]" value="{{ $b->id }}">
                                        @endforeach
                                        <button type="submit" class="text-xs px-2 py-1 rounded" style="background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);">↓</button>
                                    </form>
                                @endif
                                <button type="button" @click="editing = true" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">Edit</button>
                                <form method="POST" action="{{ route('settings.prospecting.price-bands.archive', $band) }}" class="inline" onsubmit="return confirm('Archive band {{ $band->name }}?');">
                                    @csrf
                                    <button type="submit" class="text-[10px] font-semibold px-2 py-1 rounded" style="background: rgba(239,68,68,0.10); color: #b91c1c; border: 1px solid rgba(239,68,68,0.25);">Archive</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm py-3 text-center" style="color: var(--text-muted);">No {{ $type }} bands yet.</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- TAB 5: Buyer Match Tiers — agency-configurable score cutoffs for the
         prospecting-tab tier badge (🟢 strong · 🟡 mid · ⚪ weak). --}}
    <div x-show="activeTab === 'buyer-match-tiers'" x-cloak class="space-y-4">
        @include('settings.prospecting._buyer-match-tiers', ['tier' => $buyerMatchTier ?? null])
    </div>

</div>
{{-- end Alpine x-data wrapper --}}
