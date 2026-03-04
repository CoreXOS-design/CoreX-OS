<x-app-layout>

{{-- Master Alpine state for the entire page --}}
<div x-data="p24Alerts()" class="max-w-7xl mx-auto px-4 py-6 space-y-5">

    {{-- ===== STICKY HEADER ===== --}}
    <x-list-header
        title="P24 Alerts"
        :count="$activeListings"
        :total="$totalListings"
        search-placeholder="Search suburb, P24 ref, address, type..."
        search-model="globalSearch"
    >
        <x-slot:filters>
            <select x-model="globalSuburb" class="list-header-filter">
                <option value="">All suburbs</option>
                @foreach($allSuburbs as $s)
                <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
            <select x-model="globalType" class="list-header-filter">
                <option value="">All types</option>
                @foreach($allPropertyTypes as $t)
                <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </x-slot:filters>
        <x-slot:actions>
            <a href="{{ route('admin.p24.listings') }}" class="corex-btn-outline text-xs">Browse All</a>
            <form method="POST" action="{{ route('admin.p24.import') }}" class="inline">
                @csrf
                <button type="submit" class="corex-btn-primary text-sm">Run Import</button>
            </form>
        </x-slot:actions>
    </x-list-header>

    {{-- ===== IMPORT STATUS + STATS ===== --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">Last Import</div>
            <div class="text-sm font-semibold mt-0.5">{{ $lastImport ? $lastImport->created_at->diffForHumans() : 'Never' }}</div>
        </div>
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">Emails (30d)</div>
            <div class="text-sm font-semibold mt-0.5">{{ number_format($emailsProcessed30d) }}</div>
        </div>
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">Active Listings</div>
            <div class="text-sm font-semibold mt-0.5">{{ number_format($activeListings) }}</div>
        </div>
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">New This Month</div>
            <div class="text-sm font-semibold mt-0.5">
                {{ number_format($newThisMonth) }}
                @if($monthChangePercent !== null)
                <span class="text-xs {{ $monthChangePercent >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ $monthChangePercent >= 0 ? '+' : '' }}{{ $monthChangePercent }}%
                </span>
                @endif
            </div>
        </div>
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">Avg Price</div>
            <div class="text-sm font-semibold mt-0.5">R {{ number_format($avgAskingPrice, 0, '.', ' ') }}</div>
        </div>
        <div class="ds-status-card p-3">
            <div class="text-[11px] text-gray-500 uppercase tracking-wider">IMAP</div>
            <div class="text-sm font-semibold mt-0.5 flex items-center gap-1.5">
                <span class="inline-block w-2 h-2 rounded-full {{ $imapConfigured ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                {{ $imapConfigured ? 'OK' : 'N/A' }}
            </div>
        </div>
    </div>

    {{-- ===== SECTION 1: LISTINGS BY SUBURB (Collapsible) ===== --}}
    <div class="ds-status-card" style="padding:0; overflow:hidden;">
        <button type="button"
                @click="toggleSection('suburbs')"
                class="w-full text-left px-4 py-3 flex items-center justify-between bg-slate-50 hover:bg-slate-100 transition-colors">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Listings by Suburb</h3>
                <span class="text-xs text-gray-400">{{ $suburbStats->count() }} suburbs &middot; {{ $suburbStats->sum('listing_count') }} listings</span>
            </div>
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="sections.suburbs && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>

        <div x-show="sections.suburbs" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
            @if($suburbStats->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-gray-400">No active listings by suburb.</div>
            @else
                {{-- Summary table --}}
                <div class="overflow-x-auto border-b border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Suburb</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Active</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Avg Price</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Min</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Max</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">New/Month</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($suburbStats as $row)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->suburb }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($row->listing_count) }}</td>
                                <td class="px-3 py-2 text-right">R {{ number_format($row->avg_price, 0, '.', ' ') }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">R {{ number_format($row->min_price, 0, '.', ' ') }}</td>
                                <td class="px-3 py-2 text-right text-gray-500">R {{ number_format($row->max_price, 0, '.', ' ') }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($row->new_this_month) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Collapsible per-suburb listing groups --}}
                @foreach($listingsBySuburb as $suburbName => $listings)
                <div class="border-b border-slate-100 last:border-b-0">
                    <button type="button"
                            @click="toggleSuburb('{{ md5($suburbName) }}')"
                            class="w-full text-left px-4 py-2.5 flex items-center justify-between hover:bg-slate-50 transition-colors">
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 text-gray-400 transition-transform" :class="openSuburbs['{{ md5($suburbName) }}'] && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <span class="text-sm font-semibold text-gray-700">{{ $suburbName }}</span>
                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">{{ $listings->count() }}</span>
                        </div>
                    </button>

                    <div x-show="openSuburbs['{{ md5($suburbName) }}']" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50/50 text-gray-500">
                                    <tr>
                                        <th class="text-left px-3 py-1.5 text-xs font-semibold">P24 Ref</th>
                                        <th class="text-left px-3 py-1.5 text-xs font-semibold">Type</th>
                                        <th class="text-right px-3 py-1.5 text-xs font-semibold">Price</th>
                                        <th class="text-right px-3 py-1.5 text-xs font-semibold">Beds</th>
                                        <th class="text-right px-3 py-1.5 text-xs font-semibold">Baths</th>
                                        <th class="text-right px-3 py-1.5 text-xs font-semibold">Days</th>
                                        <th class="text-left px-3 py-1.5 text-xs font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    @foreach($listings as $listing)
                                    <tr class="hover:bg-blue-50/30">
                                        <td class="px-3 py-1.5">
                                            @if($listing->p24_url)
                                            <a href="{{ $listing->p24_url }}" target="_blank" rel="noopener" class="text-teal-600 hover:underline font-medium">{{ $listing->p24_listing_number }}</a>
                                            @else
                                            <span class="text-gray-600">{{ $listing->p24_listing_number }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-1.5 text-gray-600">{{ $listing->property_type ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right font-medium">R {{ number_format($listing->asking_price, 0, '.', ' ') }}</td>
                                        <td class="px-3 py-1.5 text-right text-gray-600">{{ $listing->bedrooms ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right text-gray-600">{{ $listing->bathrooms ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right text-gray-500">{{ $listing->days_on_market ?? '—' }}</td>
                                        <td class="px-3 py-1.5">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium
                                                @if($listing->listing_status === 'active') bg-emerald-50 text-emerald-700
                                                @elseif($listing->listing_status === 'sold') bg-blue-50 text-blue-700
                                                @else bg-gray-100 text-gray-500 @endif
                                            ">{{ ucfirst($listing->listing_status ?? 'unknown') }}</span>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endforeach
            @endif
        </div>
    </div>

    {{-- ===== SECTION 2: PRICE CHANGES (Collapsible + Sortable + Filterable) ===== --}}
    <div class="ds-status-card" style="padding:0; overflow:hidden;">
        <button type="button"
                @click="toggleSection('priceChanges')"
                class="w-full text-left px-4 py-3 flex items-center justify-between bg-slate-50 hover:bg-slate-100 transition-colors">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Price Changes</h3>
                <span class="text-xs text-gray-400" x-text="filteredPriceChanges().length + ' of {{ $priceChanges->count() }}'"></span>
            </div>
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="sections.priceChanges && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>

        <div x-show="sections.priceChanges" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
            @if($priceChanges->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-gray-400">No price changes in the last 30 days.</div>
            @else
                {{-- Section filters --}}
                <div class="flex flex-wrap items-center gap-2 px-4 py-2 bg-gray-50/50 border-b border-slate-100">
                    <select x-model="pcSuburb" class="list-header-filter text-xs">
                        <option value="">All suburbs</option>
                        @foreach($priceChanges->pluck('listing.suburb')->filter()->unique()->sort()->values() as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                    <select x-model="pcDirection" class="list-header-filter text-xs">
                        <option value="">Up & Down</option>
                        <option value="down">Reductions only</option>
                        <option value="up">Increases only</option>
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortPc('change_date')">
                                    Date <span x-text="pcSortIcon('change_date')"></span>
                                </th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">P24 Ref</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortPc('suburb')">
                                    Suburb <span x-text="pcSortIcon('suburb')"></span>
                                </th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortPc('old_price')">
                                    Old Price <span x-text="pcSortIcon('old_price')"></span>
                                </th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortPc('new_price')">
                                    New Price <span x-text="pcSortIcon('new_price')"></span>
                                </th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortPc('pct')">
                                    Change <span x-text="pcSortIcon('pct')"></span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="row in filteredPriceChanges()" :key="row.id">
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-3 py-2 text-gray-500 text-xs" x-text="row.change_date"></td>
                                    <td class="px-3 py-2">
                                        <template x-if="row.p24_url">
                                            <a :href="row.p24_url" target="_blank" rel="noopener" class="text-teal-600 hover:underline font-medium" x-text="row.p24_number"></a>
                                        </template>
                                        <template x-if="!row.p24_url">
                                            <span class="text-gray-600" x-text="row.p24_number"></span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600" x-text="row.suburb"></td>
                                    <td class="px-3 py-2 text-right text-gray-500" x-text="'R ' + Number(row.old_price).toLocaleString('en-ZA')"></td>
                                    <td class="px-3 py-2 text-right font-medium" x-text="'R ' + Number(row.new_price).toLocaleString('en-ZA')"></td>
                                    <td class="px-3 py-2 text-right font-semibold"
                                        :class="row.pct < 0 ? 'text-emerald-600' : 'text-red-600'"
                                        x-text="(row.pct > 0 ? '+' : '') + row.pct + '%'"></td>
                                </tr>
                            </template>
                            <tr x-show="filteredPriceChanges().length === 0">
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No price changes match your filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ===== SECTION 3: RECENT LISTINGS (Collapsible + Sortable + Filterable) ===== --}}
    <div class="ds-status-card" style="padding:0; overflow:hidden;">
        <button type="button"
                @click="toggleSection('recentListings')"
                class="w-full text-left px-4 py-3 flex items-center justify-between bg-slate-50 hover:bg-slate-100 transition-colors">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Recent Listings</h3>
                <span class="text-xs text-gray-400" x-text="filteredRecent().length + ' of {{ $recentListings->count() }}'"></span>
            </div>
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="sections.recentListings && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>

        <div x-show="sections.recentListings" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
            @if($recentListings->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-gray-400">No listings imported yet. Run an import to get started.</div>
            @else
                {{-- Section filters --}}
                <div class="flex flex-wrap items-center gap-2 px-4 py-2 bg-gray-50/50 border-b border-slate-100">
                    <select x-model="rlSuburb" class="list-header-filter text-xs">
                        <option value="">All suburbs</option>
                        @foreach($recentListings->pluck('suburb')->filter()->unique()->sort()->values() as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                    <select x-model="rlType" class="list-header-filter text-xs">
                        <option value="">All types</option>
                        @foreach($recentListings->pluck('property_type')->filter()->unique()->sort()->values() as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                    <select x-model="rlBeds" class="list-header-filter text-xs">
                        <option value="">Any beds</option>
                        @foreach($recentListings->pluck('bedrooms')->filter()->unique()->sort()->values() as $b)
                        <option value="{{ $b }}">{{ $b }}+ beds</option>
                        @endforeach
                    </select>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortRl('first_seen')">
                                    Listed <span x-text="rlSortIcon('first_seen')"></span>
                                </th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">P24 Ref</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortRl('suburb')">
                                    Suburb <span x-text="rlSortIcon('suburb')"></span>
                                </th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Type</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortRl('price')">
                                    Price <span x-text="rlSortIcon('price')"></span>
                                </th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase cursor-pointer hover:text-gray-800" @click="sortRl('beds')">
                                    Beds <span x-text="rlSortIcon('beds')"></span>
                                </th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Baths</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="row in filteredRecent()" :key="row.id">
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-3 py-2 text-gray-500 text-xs" x-text="row.first_seen"></td>
                                    <td class="px-3 py-2">
                                        <template x-if="row.p24_url">
                                            <a :href="row.p24_url" target="_blank" rel="noopener" class="text-teal-600 hover:underline font-medium" x-text="row.p24_number"></a>
                                        </template>
                                        <template x-if="!row.p24_url">
                                            <span class="text-gray-600" x-text="row.p24_number"></span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600" x-text="row.suburb"></td>
                                    <td class="px-3 py-2 text-gray-600" x-text="row.type || '—'"></td>
                                    <td class="px-3 py-2 text-right font-medium" x-text="'R ' + Number(row.price).toLocaleString('en-ZA')"></td>
                                    <td class="px-3 py-2 text-right text-gray-600" x-text="row.beds || '—'"></td>
                                    <td class="px-3 py-2 text-right text-gray-600" x-text="row.baths || '—'"></td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium"
                                              :class="{
                                                  'bg-emerald-50 text-emerald-700': row.status === 'active',
                                                  'bg-blue-50 text-blue-700': row.status === 'sold',
                                                  'bg-gray-100 text-gray-500': row.status !== 'active' && row.status !== 'sold'
                                              }"
                                              x-text="row.status ? row.status.charAt(0).toUpperCase() + row.status.slice(1) : 'Unknown'"></span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredRecent().length === 0">
                                <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-400">No recent listings match your filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ===== SECTION 4: IMPORT LOG (Collapsible) ===== --}}
    <div class="ds-status-card" style="padding:0; overflow:hidden;">
        <button type="button"
                @click="toggleSection('importLog')"
                class="w-full text-left px-4 py-3 flex items-center justify-between bg-slate-50 hover:bg-slate-100 transition-colors">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider">Import Log</h3>
                <span class="text-xs text-gray-400">{{ $importLog->count() }} runs</span>
            </div>
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="sections.importLog && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>

        <div x-show="sections.importLog" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
            @if($importLog->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-gray-400">No import runs yet.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Date</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Subject</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Found</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">New</th>
                                <th class="text-right px-3 py-2 text-xs font-semibold uppercase">Updated</th>
                                <th class="text-left px-3 py-2 text-xs font-semibold uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($importLog as $log)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-3 py-2 text-gray-500 text-xs">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2 max-w-xs truncate text-gray-700" title="{{ $log->email_subject }}">{{ Str::limit($log->email_subject, 60) }}</td>
                                <td class="px-3 py-2 text-right">{{ $log->listings_found }}</td>
                                <td class="px-3 py-2 text-right">{{ $log->listings_new }}</td>
                                <td class="px-3 py-2 text-right">{{ $log->listings_updated }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium
                                        @if($log->status === 'success') bg-emerald-50 text-emerald-700
                                        @elseif($log->status === 'error') bg-rose-50 text-rose-700
                                        @else bg-amber-50 text-amber-700 @endif
                                    ">{{ ucfirst($log->status) }}</span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

</div>

{{-- ===== ALPINE COMPONENT ===== --}}
<script>
function p24Alerts() {
    // Hydrate section states from sessionStorage
    const stored = JSON.parse(sessionStorage.getItem('p24_sections') || '{}');
    const storedSuburbs = JSON.parse(sessionStorage.getItem('p24_suburbs') || '{}');

    return {
        // Global search/filter (bound to list-header)
        globalSearch: '',
        globalSuburb: '',
        globalType: '',

        // Section collapse state (default: all expanded except importLog)
        sections: {
            suburbs: stored.suburbs !== undefined ? stored.suburbs : true,
            priceChanges: stored.priceChanges !== undefined ? stored.priceChanges : true,
            recentListings: stored.recentListings !== undefined ? stored.recentListings : true,
            importLog: stored.importLog !== undefined ? stored.importLog : false,
        },

        // Per-suburb collapse state (default: all collapsed)
        openSuburbs: storedSuburbs,

        toggleSection(key) {
            this.sections[key] = !this.sections[key];
            sessionStorage.setItem('p24_sections', JSON.stringify(this.sections));
        },

        toggleSuburb(hash) {
            this.openSuburbs[hash] = !this.openSuburbs[hash];
            sessionStorage.setItem('p24_suburbs', JSON.stringify(this.openSuburbs));
        },

        // ── Price Changes sort + filter ──
        pcSort: 'change_date',
        pcDir: 'desc',
        pcSuburb: '',
        pcDirection: '',

        pcData: @json($priceChangesData),

        sortPc(field) {
            if (this.pcSort === field) {
                this.pcDir = this.pcDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.pcSort = field;
                this.pcDir = field === 'change_date' ? 'desc' : 'asc';
            }
        },

        pcSortIcon(field) {
            if (this.pcSort !== field) return '';
            return this.pcDir === 'asc' ? '\u2191' : '\u2193';
        },

        filteredPriceChanges() {
            let data = [...this.pcData];
            const gs = this.globalSearch.toLowerCase();

            // Global search
            if (gs) {
                data = data.filter(r =>
                    r.suburb.toLowerCase().includes(gs) ||
                    r.p24_number.toLowerCase().includes(gs)
                );
            }
            // Global suburb filter
            if (this.globalSuburb) {
                data = data.filter(r => r.suburb === this.globalSuburb);
            }

            // Section filters
            if (this.pcSuburb) {
                data = data.filter(r => r.suburb === this.pcSuburb);
            }
            if (this.pcDirection === 'down') {
                data = data.filter(r => r.pct < 0);
            } else if (this.pcDirection === 'up') {
                data = data.filter(r => r.pct > 0);
            }

            // Sort
            const dir = this.pcDir === 'asc' ? 1 : -1;
            const sortKey = this.pcSort;
            data.sort((a, b) => {
                let va = a[sortKey], vb = b[sortKey];
                if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb || '').toLowerCase(); }
                if (va < vb) return -1 * dir;
                if (va > vb) return 1 * dir;
                return 0;
            });

            return data;
        },

        // ── Recent Listings sort + filter ──
        rlSort: 'first_seen',
        rlDir: 'desc',
        rlSuburb: '',
        rlType: '',
        rlBeds: '',

        rlData: @json($recentListingsData),

        sortRl(field) {
            if (this.rlSort === field) {
                this.rlDir = this.rlDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.rlSort = field;
                this.rlDir = field === 'first_seen' || field === 'price' ? 'desc' : 'asc';
            }
        },

        rlSortIcon(field) {
            if (this.rlSort !== field) return '';
            return this.rlDir === 'asc' ? '\u2191' : '\u2193';
        },

        filteredRecent() {
            let data = [...this.rlData];
            const gs = this.globalSearch.toLowerCase();

            // Global search
            if (gs) {
                data = data.filter(r =>
                    r.suburb.toLowerCase().includes(gs) ||
                    r.p24_number.toLowerCase().includes(gs) ||
                    (r.type || '').toLowerCase().includes(gs)
                );
            }
            // Global filters
            if (this.globalSuburb) {
                data = data.filter(r => r.suburb === this.globalSuburb);
            }
            if (this.globalType) {
                data = data.filter(r => r.type === this.globalType);
            }

            // Section filters
            if (this.rlSuburb) {
                data = data.filter(r => r.suburb === this.rlSuburb);
            }
            if (this.rlType) {
                data = data.filter(r => r.type === this.rlType);
            }
            if (this.rlBeds) {
                const minBeds = parseInt(this.rlBeds);
                data = data.filter(r => r.beds !== null && r.beds >= minBeds);
            }

            // Sort
            const dir = this.rlDir === 'asc' ? 1 : -1;
            const sortKey = this.rlSort;
            data.sort((a, b) => {
                let va = a[sortKey], vb = b[sortKey];
                if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb || '').toLowerCase(); }
                if (va === null || va === undefined) va = '';
                if (vb === null || vb === undefined) vb = '';
                if (va < vb) return -1 * dir;
                if (va > vb) return 1 * dir;
                return 0;
            });

            return data;
        },
    };
}
</script>

</x-app-layout>
