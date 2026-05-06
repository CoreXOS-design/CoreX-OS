@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Market Intelligence</h1>
                <p class="text-sm text-white/60">Portal listings captured by your team — {{ number_format($listings->total()) }} results.</p>
            </div>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">Total Active Listings</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--text-primary);">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">Average Asking Price</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--text-primary);">R {{ number_format($stats['avg_price']) }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">New This Week</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--text-primary);">{{ number_format($stats['new_this_week']) }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">Price Reductions</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--brand-icon);">{{ number_format($stats['price_reductions']) }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">Cross-Listed</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--ds-amber);">{{ number_format($stats['cross_listed']) }}</div>
        </div>
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">Buyer Matched</div>
            <div class="text-[1.625rem] font-semibold" style="color: #10b981;">{{ number_format($stats['buyer_matched'] ?? 0) }}</div>
        </div>
    </div>

    {{-- Buyer Match Toggle --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('prospecting.index', array_merge(request()->except('matched_only'), ['matched_only' => '1'])) }}"
           class="text-xs px-3 py-1.5 rounded-md no-underline {{ request('matched_only') === '1' ? 'font-bold' : '' }}"
           style="{{ request('matched_only') === '1' ? 'background:#10b981;color:#fff;' : 'background:var(--surface);color:var(--text-muted);border:1px solid var(--border);' }}">
            Show Buyer-Matched Only
        </a>
        @if(request('matched_only'))
        <a href="{{ route('prospecting.index', request()->except('matched_only')) }}" class="text-xs no-underline" style="color:var(--text-muted);">Clear filter</a>
        @endif
        <a href="{{ route('prospecting.index', array_merge(request()->except('sort','dir'), ['sort' => 'buyer_matches', 'dir' => 'desc'])) }}"
           class="text-xs px-3 py-1.5 rounded-md no-underline" style="background:var(--surface);color:var(--text-muted);border:1px solid var(--border);">
            Sort by Buyer Demand
        </a>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('prospecting.index') }}" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            {{-- Portal --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Portal</label>
                <select name="portal_source" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="all" {{ request('portal_source', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="p24" {{ request('portal_source') === 'p24' ? 'selected' : '' }}>Property24</option>
                    <option value="pp" {{ request('portal_source') === 'pp' ? 'selected' : '' }}>Private Property</option>
                </select>
            </div>

            {{-- Suburb --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Suburb</label>
                <select name="suburb" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="">All suburbs</option>
                    @foreach($suburbs as $s)
                    <option value="{{ $s }}" {{ request('suburb') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Property Type --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Property Type</label>
                <select name="property_type" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="">All types</option>
                    @foreach($propertyTypes as $pt)
                    <option value="{{ $pt }}" {{ request('property_type') === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Price Min --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Price Min</label>
                <input type="number" name="price_min" value="{{ request('price_min') }}" placeholder="0"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- Price Max --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Price Max</label>
                <input type="number" name="price_max" value="{{ request('price_max') }}" placeholder="Any"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            {{-- Beds --}}
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Beds</label>
                <select name="bedrooms_min" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="" {{ !request('bedrooms_min') ? 'selected' : '' }}>Any</option>
                    <option value="1" {{ request('bedrooms_min') === '1' ? 'selected' : '' }}>1+</option>
                    <option value="2" {{ request('bedrooms_min') === '2' ? 'selected' : '' }}>2+</option>
                    <option value="3" {{ request('bedrooms_min') === '3' ? 'selected' : '' }}>3+</option>
                    <option value="4" {{ request('bedrooms_min') === '4' ? 'selected' : '' }}>4+</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mt-3">
            <div class="col-span-2">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Address, suburb, agent, agency..."
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>

            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                <select name="is_active" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="all" {{ request('is_active', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Removed</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Captured By</label>
                <select name="captured_by" onchange="this.form.submit()" class="list-header-filter w-full">
                    <option value="">All agents</option>
                    @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ request('captured_by') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="corex-btn-primary">Apply</button>
                <a href="{{ route('prospecting.index') }}" class="corex-btn-outline">Reset</a>
            </div>
        </div>
    </form>

    {{-- Claim filter buttons + stats --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center gap-2">
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => null]) }}"
               class="{{ !request('claim_filter') ? 'corex-btn-primary' : 'corex-btn-outline' }}">
                All
            </a>
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => 'unclaimed']) }}"
               class="{{ request('claim_filter') === 'unclaimed' ? 'corex-btn-primary' : 'corex-btn-outline' }}">
                Unclaimed
            </a>
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => 'my_claims']) }}"
               class="{{ request('claim_filter') === 'my_claims' ? 'corex-btn-primary' : 'corex-btn-outline' }}">
                My Claims
            </a>
        </div>
        <div class="flex items-center gap-4 text-xs" style="color: var(--text-secondary);">
            <span>My Claims: <strong style="color: var(--brand-icon);">{{ number_format($claimStats['my_claims']) }}</strong></span>
            <span>Total Claimed: <strong style="color: var(--text-primary);">{{ number_format($claimStats['total_claimed']) }}</strong></span>
            <span>Expiring: <strong style="color: var(--ds-amber);">{{ number_format($claimStats['expiring_soon']) }}</strong></span>
        </div>
    </div>

    {{-- Results table --}}
    @if($listings->count())
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted); width: 60px;">Photo</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Address</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'suburb', 'dir' => request('sort') === 'suburb' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color: var(--text-muted); text-decoration: none;">Suburb {!! request('sort') === 'suburb' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' !!}</a>
                        </th>
                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'price', 'dir' => request('sort') === 'price' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color: var(--text-muted); text-decoration: none;">Price {!! request('sort') === 'price' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' !!}</a>
                        </th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color: #10b981;">Buyers</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Bed|Bath|Gar</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agency</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Portal</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ref</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Claim</th>
                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'first_seen_at', 'dir' => request('sort') === 'first_seen_at' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color: var(--text-muted); text-decoration: none;">First Seen {!! request('sort') === 'first_seen_at' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' !!}</a>
                        </th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($listings as $listing)
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);">
                        {{-- Photo --}}
                        <td class="px-4 py-3">
                            @if($listing->thumbnail_path)
                            <img src="{{ route('prospecting.thumbnail', $listing) }}" alt=""
                                 class="w-[50px] h-[38px] object-cover rounded-md" style="border: 1px solid var(--border);">
                            @else
                            <div class="w-[50px] h-[38px] rounded-md flex items-center justify-center"
                                 style="background: var(--surface-2); border: 1px solid var(--border);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color: var(--text-muted);">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                </svg>
                            </div>
                            @endif
                        </td>

                        {{-- Address --}}
                        <td class="px-4 py-3">
                            <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                               class="text-sm font-medium hover:underline" style="color: var(--brand-icon);">
                                {{ Str::limit($listing->address, 40) }}
                            </a>
                        </td>

                        {{-- Suburb --}}
                        <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">{{ $listing->suburb }}</td>

                        {{-- Price --}}
                        <td class="px-4 py-3 text-right">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">R {{ number_format($listing->price) }}</div>
                            @if($listing->price_changed_at && $listing->priceHistory && $listing->priceHistory->count())
                                @php $lastChange = $listing->priceHistory->sortByDesc('changed_at')->first(); @endphp
                                @if($lastChange)
                                    @if($lastChange->new_price < $lastChange->old_price)
                                    <div class="text-xs" style="color: var(--ds-green);">was R {{ number_format($lastChange->old_price) }} &#8595;</div>
                                    @else
                                    <div class="text-xs" style="color: var(--ds-amber);">was R {{ number_format($lastChange->old_price) }} &#8593;</div>
                                    @endif
                                @endif
                            @endif
                        </td>

                        {{-- Buyer Matches --}}
                        <td class="px-4 py-3 text-center">
                            @if(($listing->buyer_match_count ?? 0) > 0)
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full"
                                      style="{{ ($listing->buyer_match_count ?? 0) >= 5 ? 'background:rgba(239,68,68,0.15);color:#ef4444;' : (($listing->buyer_match_count ?? 0) >= 2 ? 'background:rgba(245,158,11,0.15);color:#f59e0b;' : 'background:rgba(16,185,129,0.15);color:#10b981;') }}">
                                    {{ $listing->buyer_match_count }} buyer{{ $listing->buyer_match_count > 1 ? 's' : '' }}
                                </span>
                            @endif
                        </td>

                        {{-- Beds|Baths|Garages --}}
                        <td class="px-4 py-3 text-center text-sm" style="color: var(--text-secondary);">
                            {{ $listing->bedrooms ?? '-' }}|{{ $listing->bathrooms ?? '-' }}|{{ $listing->garages ?? '-' }}
                        </td>

                        {{-- Type --}}
                        <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">{{ $listing->property_type ?? '-' }}</td>

                        {{-- Agent --}}
                        <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">{{ Str::limit($listing->agent_name, 20) ?? '-' }}</td>

                        {{-- Agency --}}
                        <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">{{ Str::limit($listing->agency_name, 20) ?? '-' }}</td>

                        {{-- Portal badges --}}
                        <td class="px-4 py-3 text-center">
                            @if(!empty($listing->portals))
                                @foreach($listing->portals as $portal)
                                <a href="{{ $portal['url'] }}" target="_blank" rel="noopener"
                                   class="ds-badge ds-badge-info me-0.5"
                                   style="text-decoration: none;"
                                   title="{{ $portal['ref'] }}">
                                    {{ $portal['source'] === 'p24' ? 'P24' : 'PP' }}
                                </a>
                                @endforeach
                            @else
                                <span class="ds-badge ds-badge-info">{{ $listing->portal_source === 'p24' ? 'P24' : 'PP' }}</span>
                            @endif
                        </td>

                        {{-- Portal Ref --}}
                        <td class="px-4 py-3">
                            @if(!empty($listing->portals))
                                @foreach($listing->portals as $portal)
                                <a href="{{ $portal['url'] }}" target="_blank" rel="noopener"
                                   class="hover:underline"
                                   style="font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace; font-size: 0.75rem; color: var(--brand-icon); text-decoration: none;">{{ $portal['ref'] }}</a>
                                @if(!$loop->last) <br> @endif
                                @endforeach
                            @elseif($listing->portal_ref)
                            <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                               class="hover:underline"
                               style="font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace; font-size: 0.75rem; color: var(--brand-icon); text-decoration: none;">{{ $listing->portal_ref }}</a>
                            @else
                            <span style="font-size: 0.75rem; color: var(--text-muted);">—</span>
                            @endif
                        </td>

                        {{-- Claim --}}
                        <td class="px-4 py-3 text-center">
                            @if($listing->activeClaim)
                                @php
                                    $claim = $listing->activeClaim;
                                    $statusBadge = match($claim->status) {
                                        'claimed' => 'ds-badge-warning',
                                        'contacted' => 'ds-badge-info',
                                        'meeting_set' => 'ds-badge-info',
                                        'listing' => 'ds-badge-success',
                                        default => 'ds-badge-default',
                                    };
                                @endphp
                                <div class="flex flex-col items-center gap-1">
                                    <span class="text-xs font-medium" style="color: var(--brand-icon);">
                                        {{ $claim->user->name }}
                                    </span>
                                    <span class="ds-badge {{ $statusBadge }}">
                                        {{ ucfirst(str_replace('_', ' ', $claim->status)) }}
                                    </span>
                                    @if(!$claim->feedback_at)
                                        @php $hoursLeft = max(0, round(48 - $claim->claimed_at->diffInHours(now()))); @endphp
                                        <span class="text-xs" style="color: var(--text-muted);">
                                            {{ $hoursLeft < 1 ? '< 1h left' : $hoursLeft . 'h left' }}
                                        </span>
                                    @endif
                                    @if($claim->flagged_at)
                                        <span class="ds-badge ds-badge-danger">BM Review</span>
                                    @endif
                                    @if($claim->user_id === auth()->id() && $claim->is_active)
                                        <button type="button"
                                            onclick="openFeedbackModal({{ $listing->id }}, '{{ $claim->status }}')"
                                            class="text-xs font-semibold hover:underline" style="color: var(--brand-icon);">
                                            Update
                                        </button>
                                    @endif
                                </div>
                            @else
                                <form method="POST" action="{{ route('prospecting.claim', $listing) }}">
                                    @csrf
                                    <button type="submit" class="corex-btn-outline" style="padding: 0.25rem 0.625rem; font-size: 0.6875rem;">
                                        Claim
                                    </button>
                                </form>
                            @endif
                        </td>

                        {{-- First Seen --}}
                        <td class="px-4 py-3 text-sm" style="color: var(--text-secondary);">
                            {{ $listing->first_seen_at->format('d M Y') }}
                            @if(!empty($listing->email_first_seen))
                                <div class="text-xs" style="color: var(--ds-amber);" title="First seen in P24 email alerts">
                                    Email: {{ \Carbon\Carbon::parse($listing->email_first_seen)->format('d M Y') }}
                                </div>
                            @endif
                            @if(!empty($listing->email_times_seen) && $listing->email_times_seen > 1)
                                <div class="text-xs" style="color: var(--text-muted);">
                                    Seen {{ number_format($listing->email_times_seen) }}x
                                </div>
                            @endif
                        </td>

                        {{-- Status --}}
                        <td class="px-4 py-3 text-center">
                            @if($listing->is_active)
                            <span class="ds-badge ds-badge-success">Active</span>
                            @else
                            <span class="ds-badge ds-badge-default">Removed</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
            {{ $listings->withQueryString()->links() }}
        </div>
    </div>

    @else
    {{-- Empty state --}}
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No listings captured yet</h3>
        <p class="text-sm" style="color: var(--text-muted);">Install the Chrome Extension to start capturing portal listings.</p>
    </div>
    @endif

    {{-- Feedback Modal --}}
    <style>[x-cloak] { display: none !important; }</style>
    <div x-data="{ open: false, listingId: null, status: 'contacted' }" x-show="open" x-cloak
         style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5);"
         @open-feedback.window="listingId = $event.detail.id; status = $event.detail.status; open = true"
         @keydown.escape.window="open = false">
        <div @click.outside="open = false"
             class="rounded-md p-5 w-full"
             style="background: var(--surface); border: 1px solid var(--border); max-width: 380px;">
            <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Update Claim Status</h3>

            <form :action="'/prospecting/' + listingId + '/feedback'" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Status</label>
                    <select name="status" x-model="status"
                            class="w-full rounded-md px-3 py-2 text-sm"
                            style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="contacted">Contacted</option>
                        <option value="meeting_set">Meeting Set</option>
                        <option value="listing">Listing</option>
                        <option value="not_interested">Not Interested</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes (optional)</label>
                    <textarea name="notes" rows="3"
                              class="w-full rounded-md px-3 py-2 text-sm"
                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                              placeholder="Any notes about this contact..."></textarea>
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="open = false" class="corex-btn-outline">
                        Cancel
                    </button>
                    <button type="submit" class="corex-btn-primary">
                        Save Feedback
                    </button>
                </div>
            </form>

            {{-- Release claim --}}
            <div class="mt-4 pt-4" style="border-top: 1px solid var(--border);">
                <form :action="'/prospecting/' + listingId + '/release'" method="POST"
                      onsubmit="return confirm('Release this claim? Another agent will be able to claim it.')">
                    @csrf
                    <button type="submit" class="text-xs font-semibold hover:underline" style="color: var(--ds-crimson);">
                        Release Claim
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
function openFeedbackModal(listingId, currentStatus) {
    window.dispatchEvent(new CustomEvent('open-feedback', {
        detail: { id: listingId, status: currentStatus }
    }));
}
</script>
@endsection
