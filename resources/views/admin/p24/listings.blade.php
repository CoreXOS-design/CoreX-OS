@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">P24 Listing Browser</h1>
            <p class="text-xs" style="color: var(--text-muted);">
                <a href="{{ route('admin.p24.index') }}" class="hover:underline">Dashboard</a> / Listings &middot; browse the Property24 listings CoreX has seen.
            </p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="props-filter-panel rounded-md px-5 py-4" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('admin.p24.listings') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Suburb</label>
                <select name="suburb" class="list-header-filter {{ request('suburb') ? 'is-active' : '' }}">
                    <option value="">All suburbs</option>
                    @foreach($suburbs as $sub)
                        <option value="{{ $sub }}" {{ request('suburb') === $sub ? 'selected' : '' }}>{{ $sub }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Property Type</label>
                <select name="type" class="list-header-filter {{ request('type') ? 'is-active' : '' }}">
                    <option value="">All types</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}" {{ request('type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Min Price</label>
                <input type="number" name="min_price" value="{{ request('min_price') }}" placeholder="e.g. 500000" class="list-header-filter {{ request('min_price') !== null && request('min_price') !== '' ? 'is-active' : '' }}">
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium" style="color: var(--text-secondary);">Max Price</label>
                <input type="number" name="max_price" value="{{ request('max_price') }}" placeholder="e.g. 3000000" class="list-header-filter {{ request('max_price') !== null && request('max_price') !== '' ? 'is-active' : '' }}">
            </div>
            <button type="submit" class="corex-btn-primary text-sm" style="height:2.25rem;">Filter</button>
        </form>
    </div>

        {{-- Results --}}
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="ds-section-header">Results ({{ $listings->total() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead style="background:var(--surface-2); color:var(--text-muted)">
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
                    <tbody class="divide-y" style="--tw-divide-y-color:var(--border)">
                        @forelse($listings as $listing)
                        <tr>
                            <td class="px-4 py-3" style="color:var(--text-secondary)">{{ $listing->first_seen_date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                @if($listing->p24_url)
                                    <a href="{{ $listing->p24_url }}" target="_blank" class="hover:underline" style="color:var(--brand-icon)">{{ $listing->p24_listing_number }}</a>
                                @else
                                    {{ $listing->p24_listing_number }}
                                @endif
                                @if($listing->is_mandated)
                                    <span class="ds-badge ds-badge-pill ds-badge-warning ml-1">Mandated</span>
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
                                <span class="ds-badge {{ $listing->listing_status === 'active' ? 'ds-badge-success' : ($listing->listing_status === 'sold' ? 'ds-badge-info' : 'ds-badge-muted') }}">{{ ucfirst($listing->listing_status) }}</span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="11" class="px-4 py-8 text-center" style="color:var(--text-muted)">No listings match your filters.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4" style="border-top:1px solid var(--border);">{{ $listings->appends(request()->query())->links() }}</div>
        </div>
    </div>
@endsection
