@extends('layouts.corex')

@section('corex-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Navy header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Market Intelligence</h2>
                <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Portal listings captured by your team</div>
            </div>
            <div class="text-sm font-medium" style="color:rgba(255,255,255,0.7);">
                {{ $listings->total() }} results
            </div>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Total Active Listings</div>
            <div class="text-xl font-bold" style="color:var(--text-primary);">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Average Asking Price</div>
            <div class="text-xl font-bold" style="color:var(--text-primary);">R {{ number_format($stats['avg_price']) }}</div>
        </div>
        <div class="rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">New This Week</div>
            <div class="text-xl font-bold" style="color:var(--text-primary);">{{ number_format($stats['new_this_week']) }}</div>
        </div>
        <div class="rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Price Reductions</div>
            <div class="text-xl font-bold" style="color:#22c55e;">{{ number_format($stats['price_reductions']) }}</div>
        </div>
        <div class="rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color:var(--text-muted);">Cross-Listed</div>
            <div class="text-xl font-bold" style="color:#a855f7;">{{ number_format($stats['cross_listed']) }}</div>
        </div>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('prospecting.index') }}" class="rounded-xl p-4" style="background:var(--surface); border:1px solid var(--border);">
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
            {{-- Portal --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Portal</label>
                <select name="portal_source" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="all" {{ request('portal_source', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="p24" {{ request('portal_source') === 'p24' ? 'selected' : '' }}>Property24</option>
                    <option value="pp" {{ request('portal_source') === 'pp' ? 'selected' : '' }}>Private Property</option>
                </select>
            </div>

            {{-- Suburb --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Suburb</label>
                <select name="suburb" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All suburbs</option>
                    @foreach($suburbs as $s)
                    <option value="{{ $s }}" {{ request('suburb') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Property Type --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Property Type</label>
                <select name="property_type" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All types</option>
                    @foreach($propertyTypes as $pt)
                    <option value="{{ $pt }}" {{ request('property_type') === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Price Min --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Price Min</label>
                <input type="number" name="price_min" value="{{ request('price_min') }}" placeholder="0"
                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            {{-- Price Max --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Price Max</label>
                <input type="number" name="price_max" value="{{ request('price_max') }}" placeholder="Any"
                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            {{-- Beds --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Beds</label>
                <select name="bedrooms_min" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="" {{ !request('bedrooms_min') ? 'selected' : '' }}>Any</option>
                    <option value="1" {{ request('bedrooms_min') === '1' ? 'selected' : '' }}>1+</option>
                    <option value="2" {{ request('bedrooms_min') === '2' ? 'selected' : '' }}>2+</option>
                    <option value="3" {{ request('bedrooms_min') === '3' ? 'selected' : '' }}>3+</option>
                    <option value="4" {{ request('bedrooms_min') === '4' ? 'selected' : '' }}>4+</option>
                </select>
            </div>
        </div>

        {{-- Second row: search + status + captured by + actions --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mt-3">
            {{-- Free text search --}}
            <div class="col-span-2">
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Address, suburb, agent, agency..."
                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </div>

            {{-- Status --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Status</label>
                <select name="is_active" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="all" {{ request('is_active', 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="1" {{ request('is_active') === '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ request('is_active') === '0' ? 'selected' : '' }}>Removed</option>
                </select>
            </div>

            {{-- Captured by --}}
            <div>
                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Captured By</label>
                <select name="captured_by" onchange="this.form.submit()"
                        class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All agents</option>
                    @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ request('captured_by') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Buttons --}}
            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium"
                        style="background:#0ea5e9; color:#fff;">Apply</button>
                <a href="{{ route('prospecting.index') }}" class="px-4 py-2 rounded-lg text-sm font-medium"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">Reset</a>
            </div>
        </div>
    </form>

    {{-- Claim filter buttons + stats --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center gap-2">
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => null]) }}"
               class="px-3 py-1.5 rounded text-xs font-semibold"
               style="{{ !request('claim_filter') ? 'background:#00d4aa; color:#0b2a4a;' : 'background:#0b2a4a; color:#00d4aa; border:1px solid #00d4aa;' }}">
                All
            </a>
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => 'unclaimed']) }}"
               class="px-3 py-1.5 rounded text-xs font-semibold"
               style="{{ request('claim_filter') === 'unclaimed' ? 'background:#00d4aa; color:#0b2a4a;' : 'background:#0b2a4a; color:#00d4aa; border:1px solid #00d4aa;' }}">
                Unclaimed
            </a>
            <a href="{{ request()->fullUrlWithQuery(['claim_filter' => 'my_claims']) }}"
               class="px-3 py-1.5 rounded text-xs font-semibold"
               style="{{ request('claim_filter') === 'my_claims' ? 'background:#00d4aa; color:#0b2a4a;' : 'background:#0b2a4a; color:#00d4aa; border:1px solid #00d4aa;' }}">
                My Claims
            </a>
        </div>
        <div class="flex items-center gap-4 text-xs" style="color:var(--text-secondary);">
            <span>My Claims: <strong style="color:#00d4aa;">{{ $claimStats['my_claims'] }}</strong></span>
            <span>Total Claimed: <strong style="color:var(--text-primary);">{{ $claimStats['total_claimed'] }}</strong></span>
            <span>Expiring: <strong style="color:#f59e0b;">{{ $claimStats['expiring_soon'] }}</strong></span>
        </div>
    </div>

    {{-- Results table --}}
    @if($listings->count())
    <div class="rounded-xl overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="color:var(--text-primary);">
                <thead>
                    <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted); width:60px;">Photo</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Address</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'suburb', 'dir' => request('sort') === 'suburb' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color:var(--text-muted); text-decoration:none;">Suburb {{ request('sort') === 'suburb' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' }}</a>
                        </th>
                        <th class="px-3 py-2.5 text-right text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'price', 'dir' => request('sort') === 'price' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color:var(--text-muted); text-decoration:none;">Price {{ request('sort') === 'price' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' }}</a>
                        </th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Bed|Bath|Gar</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Agent</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Agency</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Portal</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Ref</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Claim</th>
                        <th class="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'first_seen_at', 'dir' => request('sort') === 'first_seen_at' && request('dir', 'desc') === 'asc' ? 'desc' : 'asc']) }}"
                               style="color:var(--text-muted); text-decoration:none;">First Seen {{ request('sort') === 'first_seen_at' ? (request('dir') === 'asc' ? '&#9650;' : '&#9660;') : '' }}</a>
                        </th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($listings as $listing)
                    <tr style="border-bottom:1px solid var(--border);" class="hover:bg-white/5 transition-colors">
                        {{-- Photo --}}
                        <td class="px-3 py-2">
                            @if($listing->thumbnail_path)
                            <img src="{{ route('prospecting.thumbnail', $listing) }}" alt=""
                                 class="w-[50px] h-[38px] object-cover rounded" style="border:1px solid var(--border);">
                            @else
                            <div class="w-[50px] h-[38px] rounded flex items-center justify-center"
                                 style="background:var(--surface-2); border:1px solid var(--border);">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" style="color:var(--text-muted);">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" />
                                </svg>
                            </div>
                            @endif
                        </td>

                        {{-- Address --}}
                        <td class="px-3 py-2">
                            <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                               class="text-sm font-medium hover:underline" style="color:#0ea5e9;">
                                {{ Str::limit($listing->address, 40) }}
                            </a>
                        </td>

                        {{-- Suburb --}}
                        <td class="px-3 py-2 text-sm" style="color:var(--text-secondary);">{{ $listing->suburb }}</td>

                        {{-- Price --}}
                        <td class="px-3 py-2 text-right">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($listing->price) }}</div>
                            @if($listing->price_changed_at && $listing->priceHistory && $listing->priceHistory->count())
                                @php $lastChange = $listing->priceHistory->sortByDesc('changed_at')->first(); @endphp
                                @if($lastChange)
                                    @if($lastChange->new_price < $lastChange->old_price)
                                    <div class="text-xs" style="color:#22c55e;">was R {{ number_format($lastChange->old_price) }} &#8595;</div>
                                    @else
                                    <div class="text-xs" style="color:#ef4444;">was R {{ number_format($lastChange->old_price) }} &#8593;</div>
                                    @endif
                                @endif
                            @endif
                        </td>

                        {{-- Beds|Baths|Garages --}}
                        <td class="px-3 py-2 text-center text-sm" style="color:var(--text-secondary);">
                            {{ $listing->bedrooms ?? '-' }}|{{ $listing->bathrooms ?? '-' }}|{{ $listing->garages ?? '-' }}
                        </td>

                        {{-- Type --}}
                        <td class="px-3 py-2 text-sm" style="color:var(--text-secondary);">{{ $listing->property_type ?? '-' }}</td>

                        {{-- Agent --}}
                        <td class="px-3 py-2 text-sm" style="color:var(--text-secondary);">{{ Str::limit($listing->agent_name, 20) ?? '-' }}</td>

                        {{-- Agency --}}
                        <td class="px-3 py-2 text-sm" style="color:var(--text-secondary);">{{ Str::limit($listing->agency_name, 20) ?? '-' }}</td>

                        {{-- Portal badges --}}
                        <td class="px-3 py-2 text-center">
                            @if(!empty($listing->portals))
                                @foreach($listing->portals as $portal)
                                <a href="{{ $portal['url'] }}" target="_blank" rel="noopener"
                                   class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold me-0.5"
                                   style="background:{{ $portal['source'] === 'p24' ? '#0d9488' : '#7c3aed' }}; color:#fff; text-decoration:none;"
                                   title="{{ $portal['ref'] }}">
                                    {{ $portal['source'] === 'p24' ? 'P24' : 'PP' }}
                                </a>
                                @endforeach
                            @else
                                @if($listing->portal_source === 'p24')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold" style="background:#0d9488; color:#fff;">P24</span>
                                @else
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold" style="background:#7c3aed; color:#fff;">PP</span>
                                @endif
                            @endif
                        </td>

                        {{-- Portal Ref --}}
                        <td class="px-3 py-2">
                            @if(!empty($listing->portals))
                                @foreach($listing->portals as $portal)
                                <a href="{{ $portal['url'] }}" target="_blank" rel="noopener"
                                   style="font-family:ui-monospace,SFMono-Regular,monospace; font-size:0.75rem; color:{{ $portal['source'] === 'p24' ? '#0d9488' : '#7c3aed' }}; text-decoration:none;"
                                   class="hover:underline">{{ $portal['ref'] }}</a>
                                @if(!$loop->last) <br> @endif
                                @endforeach
                            @elseif($listing->portal_ref)
                            <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                               style="font-family:ui-monospace,SFMono-Regular,monospace; font-size:0.75rem; color:#0d9488; text-decoration:none;"
                               class="hover:underline">{{ $listing->portal_ref }}</a>
                            @else
                            <span style="font-size:0.75rem; color:var(--text-muted);">—</span>
                            @endif
                        </td>

                        {{-- Claim --}}
                        <td class="px-3 py-2 text-center">
                            @if($listing->activeClaim)
                                @php $claim = $listing->activeClaim; @endphp
                                <div class="flex flex-col items-center gap-0.5">
                                    <span class="text-xs font-medium" style="color:#00d4aa;">
                                        {{ $claim->user->name }}
                                    </span>
                                    <span class="text-xs font-semibold" style="color:
                                        {{ $claim->status === 'claimed' ? '#f59e0b' :
                                           ($claim->status === 'contacted' ? '#3b82f6' :
                                           ($claim->status === 'meeting_set' ? '#8b5cf6' :
                                           ($claim->status === 'listing' ? '#10b981' : '#6b7280'))) }};">
                                        {{ ucfirst(str_replace('_', ' ', $claim->status)) }}
                                    </span>
                                    @if(!$claim->feedback_at)
                                        @php $hoursLeft = max(0, round(48 - $claim->claimed_at->diffInHours(now()))); @endphp
                                        <span class="text-xs" style="color:var(--text-muted);">
                                            {{ $hoursLeft < 1 ? '< 1h left' : $hoursLeft . 'h left' }}
                                        </span>
                                    @endif
                                    @if($claim->flagged_at)
                                        <span class="text-xs font-bold" style="color:#ef4444;">BM Review</span>
                                    @endif
                                    @if($claim->user_id === auth()->id() && $claim->is_active)
                                        <button type="button"
                                            onclick="openFeedbackModal({{ $listing->id }}, '{{ $claim->status }}')"
                                            class="text-xs underline" style="color:#00d4aa;">
                                            Update
                                        </button>
                                    @endif
                                </div>
                            @else
                                <form method="POST" action="{{ route('prospecting.claim', $listing) }}">
                                    @csrf
                                    <button type="submit"
                                        class="px-2 py-1 text-xs rounded font-medium"
                                        style="background:#0b2a4a; color:#00d4aa; border:1px solid #00d4aa;">
                                        Claim
                                    </button>
                                </form>
                            @endif
                        </td>

                        {{-- First Seen --}}
                        <td class="px-3 py-2 text-sm" style="color:var(--text-secondary);">{{ $listing->first_seen_at->format('d M Y') }}</td>

                        {{-- Status --}}
                        <td class="px-3 py-2 text-center">
                            @if($listing->is_active)
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium" style="background:#dcfce7; color:#166534;">Active</span>
                            @else
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium" style="background:var(--surface-2); color:var(--text-muted);">Removed</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $listings->withQueryString()->links() }}
    </div>

    @else
    {{-- Empty state --}}
    <div class="rounded-xl px-6 py-12 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
             class="w-12 h-12 mx-auto mb-4" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
        </svg>
        <h3 class="text-lg font-semibold mb-2" style="color:var(--text-primary);">No listings captured yet</h3>
        <p class="text-sm" style="color:var(--text-muted);">Install the Chrome Extension to start capturing portal listings.</p>
    </div>
    @endif

    {{-- Feedback Modal --}}
    <div x-data="{ open: false, listingId: null, status: 'contacted' }" x-show="open" x-cloak
         style="position:fixed; inset:0; z-index:50; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.6);"
         @open-feedback.window="listingId = $event.detail.id; status = $event.detail.status; open = true"
         @keydown.escape.window="open = false">
        <div @click.outside="open = false"
             style="background:#0b2a4a; border:1px solid #1e3a5f; border-radius:12px; padding:24px; width:100%; max-width:440px;">
            <h3 class="text-lg font-bold mb-4" style="color:#fff;">Update Claim Status</h3>

            <form :action="'/prospecting/' + listingId + '/feedback'" method="POST">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.7);">Status</label>
                    <select name="status" x-model="status"
                            class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                            style="background:#132f4c; border:1px solid #1e3a5f; color:#fff;">
                        <option value="contacted">Contacted</option>
                        <option value="meeting_set">Meeting Set</option>
                        <option value="listing">Listing</option>
                        <option value="not_interested">Not Interested</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.7);">Notes (optional)</label>
                    <textarea name="notes" rows="3"
                              class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                              style="background:#132f4c; border:1px solid #1e3a5f; color:#fff;"
                              placeholder="Any notes about this contact..."></textarea>
                </div>

                <div class="flex items-center justify-between">
                    <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold"
                            style="background:#00d4aa; color:#0b2a4a;">
                        Save Feedback
                    </button>
                    <button type="button" @click="open = false"
                            class="px-4 py-2 rounded-lg text-sm font-medium"
                            style="background:transparent; color:rgba(255,255,255,0.6); border:1px solid #1e3a5f;">
                        Cancel
                    </button>
                </div>
            </form>

            {{-- Release claim (separate action) --}}
            <div class="mt-4 pt-4" style="border-top:1px solid #1e3a5f;">
                <form :action="'/prospecting/' + listingId + '/release'" method="POST"
                      onsubmit="return confirm('Release this claim? Another agent will be able to claim it.')">
                    @csrf
                    <button type="submit" class="text-xs underline" style="color:#ef4444;">
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
