<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;margin:-1.5rem -1.5rem 1.5rem;padding:1.5rem 2rem;">
            <h2 class="text-xl font-bold text-white">Property24 Market Intelligence</h2>
            <p class="text-sm text-blue-200 mt-1">Automated listing tracking from P24 alert emails</p>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        {{-- Flash messages --}}
        @if(session('success'))
            <div class="mb-4 p-3 rounded-lg bg-green-100 text-green-800 text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 rounded-lg bg-red-100 text-red-800 text-sm">{{ session('error') }}</div>
        @endif

        {{-- SECTION 1: Import Status --}}
        <div class="ds-status-card">
            <h3 class="ds-section-header">Import Status</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
                <div>
                    <div class="ds-label">Last Import</div>
                    <div class="ds-value">{{ $lastImport ? $lastImport->created_at->diffForHumans() : 'Never' }}</div>
                </div>
                <div>
                    <div class="ds-label">Emails Processed (30d)</div>
                    <div class="ds-value">{{ number_format($emailsProcessed30d) }}</div>
                </div>
                <div>
                    <div class="ds-label">Total Listings Tracked</div>
                    <div class="ds-value">{{ number_format($totalListings) }}</div>
                </div>
                <div>
                    <div class="ds-label">Connection Status</div>
                    <div class="ds-value flex items-center gap-2">
                        @if($imapConfigured)
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <span class="text-emerald-700 text-sm">Configured</span>
                        @else
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500"></span>
                            <span class="text-red-700 text-sm">Not configured</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <form method="POST" action="{{ route('admin.p24.import') }}">
                    @csrf
                    <button type="submit" class="nexus-btn-primary">
                        Run Import Now
                    </button>
                </form>
            </div>
        </div>

        {{-- SECTION 2: Stats Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">New Listings This Month</div>
                <div class="ds-value text-2xl">{{ number_format($newThisMonth) }}</div>
                @if($monthChangePercent !== null)
                    <div class="text-xs mt-1 {{ $monthChangePercent >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ $monthChangePercent >= 0 ? '+' : '' }}{{ $monthChangePercent }}% vs last month ({{ number_format($newLastMonth) }})
                    </div>
                @else
                    <div class="text-xs text-gray-500 mt-1">Last month: {{ number_format($newLastMonth) }}</div>
                @endif
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Average Asking Price</div>
                <div class="ds-value text-2xl">R {{ number_format($avgAskingPrice, 0, '.', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">Across {{ number_format($activeListings) }} active listings</div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Most Active Suburb</div>
                @if($mostActiveSuburb)
                    <div class="ds-value text-2xl">{{ $mostActiveSuburb->suburb ?? 'Unknown' }}</div>
                    <div class="text-xs text-gray-500 mt-1">{{ number_format($mostActiveSuburb->cnt) }} listings</div>
                @else
                    <div class="ds-value text-2xl text-gray-400">No data</div>
                @endif
            </div>
        </div>

        {{-- SECTION 3: Listings by Suburb --}}
        @if($suburbStats->isNotEmpty())
        <div class="ds-status-card">
            <h3 class="ds-section-header">Listings by Suburb</h3>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-3">Suburb</th>
                            <th class="text-right px-4 py-3">Active</th>
                            <th class="text-right px-4 py-3">Avg Price</th>
                            <th class="text-right px-4 py-3">Min</th>
                            <th class="text-right px-4 py-3">Max</th>
                            <th class="text-right px-4 py-3">New This Month</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach($suburbStats as $row)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">{{ $row->suburb }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row->listing_count) }}</td>
                            <td class="px-4 py-3 text-right">R {{ number_format($row->avg_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">R {{ number_format($row->min_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">R {{ number_format($row->max_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($row->new_this_month) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- SECTION 4: Recent Listings --}}
        <div class="ds-status-card">
            <h3 class="ds-section-header">Recent Listings</h3>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-3">Date</th>
                            <th class="text-left px-4 py-3">P24 Number</th>
                            <th class="text-left px-4 py-3">Type</th>
                            <th class="text-left px-4 py-3">Suburb</th>
                            <th class="text-right px-4 py-3">Price</th>
                            <th class="text-right px-4 py-3">Beds</th>
                            <th class="text-right px-4 py-3">Baths</th>
                            <th class="text-left px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($recentListings as $listing)
                        <tr>
                            <td class="px-4 py-3 text-slate-600">{{ $listing->first_seen_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                @if($listing->p24_url)
                                    <a href="{{ $listing->p24_url }}" target="_blank" class="text-cyan-600 hover:underline">{{ $listing->p24_listing_number }}</a>
                                @else
                                    {{ $listing->p24_listing_number }}
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $listing->property_type ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $listing->suburb ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium">R {{ number_format($listing->asking_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->bedrooms ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->bathrooms ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border
                                    @if($listing->listing_status === 'active')
                                        border-emerald-200 bg-emerald-50 text-emerald-800
                                    @elseif($listing->listing_status === 'sold')
                                        border-blue-200 bg-blue-50 text-blue-800
                                    @else
                                        border-gray-200 bg-gray-50 text-gray-600
                                    @endif
                                ">{{ ucfirst($listing->listing_status) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No listings imported yet. Run an import to get started.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $recentListings->links() }}</div>
        </div>

        {{-- SECTION 5: Price Changes (collapsed) --}}
        <div class="ds-status-card" x-data="{ priceOpen: false }">
            <button type="button" @click="priceOpen = !priceOpen" class="ds-section-header w-full text-left flex items-center justify-between">
                <span>Price Changes ({{ $priceChanges->count() }})</span>
                <svg class="w-5 h-5 transition-transform" :class="priceOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="priceOpen" x-cloak x-transition class="overflow-x-auto mt-4">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-3">Date</th>
                            <th class="text-left px-4 py-3">Listing</th>
                            <th class="text-left px-4 py-3">Suburb</th>
                            <th class="text-right px-4 py-3">Old Price</th>
                            <th class="text-right px-4 py-3">New Price</th>
                            <th class="text-right px-4 py-3">Change</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($priceChanges as $change)
                        @php
                            $pct = $change->old_price > 0
                                ? round((($change->new_price - $change->old_price) / $change->old_price) * 100, 1)
                                : 0;
                        @endphp
                        <tr>
                            <td class="px-4 py-3 text-slate-600">{{ $change->change_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">{{ $change->listing->p24_listing_number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $change->listing->suburb ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">R {{ number_format($change->old_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">R {{ number_format($change->new_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right font-medium {{ $pct < 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                {{ $pct > 0 ? '+' : '' }}{{ $pct }}%
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">No price changes recorded yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- SECTION 6: Import Log (collapsed) --}}
        <div class="ds-status-card" x-data="{ logOpen: false }">
            <button type="button" @click="logOpen = !logOpen" class="ds-section-header w-full text-left flex items-center justify-between">
                <span>Import Log ({{ $importLog->count() }})</span>
                <svg class="w-5 h-5 transition-transform" :class="logOpen && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="logOpen" x-cloak x-transition class="overflow-x-auto mt-4">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-3">Date</th>
                            <th class="text-left px-4 py-3">Subject</th>
                            <th class="text-right px-4 py-3">Found</th>
                            <th class="text-right px-4 py-3">New</th>
                            <th class="text-right px-4 py-3">Updated</th>
                            <th class="text-left px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($importLog as $log)
                        <tr>
                            <td class="px-4 py-3 text-slate-600">{{ $log->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3 max-w-xs truncate" title="{{ $log->email_subject }}">{{ Str::limit($log->email_subject, 60) }}</td>
                            <td class="px-4 py-3 text-right">{{ $log->listings_found }}</td>
                            <td class="px-4 py-3 text-right">{{ $log->listings_new }}</td>
                            <td class="px-4 py-3 text-right">{{ $log->listings_updated }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border
                                    @if($log->status === 'success')
                                        border-emerald-200 bg-emerald-50 text-emerald-800
                                    @elseif($log->status === 'error')
                                        border-rose-200 bg-rose-50 text-rose-800
                                    @else
                                        border-amber-200 bg-amber-50 text-amber-800
                                    @endif
                                ">{{ ucfirst($log->status) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">No import runs yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</x-app-layout>
