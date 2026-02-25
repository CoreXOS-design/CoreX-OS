<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;margin:-1.5rem -1.5rem 1.5rem;padding:1.5rem 2rem;">
            <h2 class="text-xl font-bold text-white">P24 Listing Browser</h2>
            <p class="text-sm text-blue-200 mt-1">
                <a href="{{ route('admin.p24.index') }}" class="hover:underline">Dashboard</a> / Listings
            </p>
        </div>
    </x-slot>

    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

        {{-- Filters --}}
        <div class="ds-status-card">
            <h3 class="ds-section-header">Filters</h3>
            <form method="GET" action="{{ route('admin.p24.listings') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mt-4">
                <div>
                    <label class="ds-label">Suburb</label>
                    <select name="suburb" class="w-full rounded border-gray-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500">
                        <option value="">All</option>
                        @foreach($suburbs as $sub)
                            <option value="{{ $sub }}" {{ request('suburb') === $sub ? 'selected' : '' }}>{{ $sub }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ds-label">Property Type</label>
                    <select name="type" class="w-full rounded border-gray-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500">
                        <option value="">All</option>
                        @foreach($types as $type)
                            <option value="{{ $type }}" {{ request('type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ds-label">Min Price</label>
                    <input type="number" name="min_price" value="{{ request('min_price') }}" placeholder="e.g. 500000" class="w-full rounded border-gray-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>
                <div>
                    <label class="ds-label">Max Price</label>
                    <input type="number" name="max_price" value="{{ request('max_price') }}" placeholder="e.g. 3000000" class="w-full rounded border-gray-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="nexus-btn-primary w-full">Filter</button>
                </div>
            </form>
        </div>

        {{-- Results --}}
        <div class="ds-status-card">
            <h3 class="ds-section-header">Results ({{ $listings->total() }})</h3>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-sm ds-table">
                    <thead class="bg-slate-50 dark:bg-slate-900/40 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-3">First Seen</th>
                            <th class="text-left px-4 py-3">P24 Number</th>
                            <th class="text-left px-4 py-3">Type</th>
                            <th class="text-left px-4 py-3">Suburb</th>
                            <th class="text-right px-4 py-3">Price</th>
                            <th class="text-right px-4 py-3">Beds</th>
                            <th class="text-right px-4 py-3">Baths</th>
                            <th class="text-right px-4 py-3">Garages</th>
                            <th class="text-right px-4 py-3">Days on Market</th>
                            <th class="text-right px-4 py-3">Price Change</th>
                            <th class="text-left px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($listings as $listing)
                        <tr>
                            <td class="px-4 py-3 text-slate-600">{{ $listing->first_seen_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                @if($listing->p24_url)
                                    <a href="{{ $listing->p24_url }}" target="_blank" class="text-cyan-600 hover:underline">{{ $listing->p24_listing_number }}</a>
                                @else
                                    {{ $listing->p24_listing_number }}
                                @endif
                                @if($listing->is_mandated)
                                    <span class="ml-1 text-xs px-1 py-0.5 rounded bg-amber-100 text-amber-700">Mandated</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $listing->property_type ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $listing->suburb ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium">R {{ number_format($listing->asking_price, 0, '.', ' ') }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->bedrooms ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->bathrooms ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->garages ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">{{ $listing->days_on_market ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if($listing->price_change_percent !== null)
                                    <span class="{{ $listing->price_change_percent < 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                        {{ $listing->price_change_percent > 0 ? '+' : '' }}{{ $listing->price_change_percent }}%
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
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
                            <td colspan="11" class="px-4 py-8 text-center text-gray-500">No listings match your filters.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $listings->appends(request()->query())->links() }}</div>
        </div>
    </div>
</x-app-layout>
