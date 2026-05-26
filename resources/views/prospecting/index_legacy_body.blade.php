{{--
    Build F.1 — legacy prospecting body extracted as a re-includable partial.

    The pre-F.1 page lived directly in prospecting/index.blade.php; F.1 moved
    the body here so BOTH the new route /corex/market-intelligence (via
    corex/market-intelligence/index.blade.php) AND the legacy prospecting.index
    route (via prospecting/index.blade.php) can re-include it during the
    migration window. Identical rendering, two callers. F.2 onwards rebuilds
    the row + filter rail; this partial is the temporary scaffolding.

    Outer flex container: holds the main content column + the optional
    Prospecting Setup drawer as a flex sibling (the post-Prompt-11.4 calendar
    pattern). When the drawer is closed it takes 0 space; when open it docks
    to the right and the main column shrinks. NO fixed positioning, NO
    backdrop. Escape closes. Survives full reload via #setup-open fragment.
--}}
<div class="flex gap-0 min-h-[60vh]"
     x-data="{
        setupDrawerOpen: false,
        releaseModalOpen: false,
        releaseClaimId: null,
        releaseClaimLabel: '',
        buyerPanelOpen: false,
        buyerPanelLoading: false,
        buyerPanelHtml: '',
        async openBuyerPanel(listingId) {
            this.buyerPanelOpen = true;
            this.buyerPanelLoading = true;
            this.buyerPanelHtml = '';
            try {
                const r = await fetch(`/corex/prospecting/${listingId}/buyer-matches`, {
                    headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('Failed to load (' + r.status + ')');
                this.buyerPanelHtml = await r.text();
            } catch (e) {
                this.buyerPanelHtml = '<div class=\'p-6 text-sm\' style=\'color:var(--ds-crimson);\'>Failed to load buyer matches: ' + (e.message || 'error') + '</div>';
            } finally {
                this.buyerPanelLoading = false;
            }
        }
     }"
     x-init="if (window.location.hash === '#setup-open') setupDrawerOpen = true;
             $watch('setupDrawerOpen', v => { if (v) window.location.hash = 'setup-open'; else if (window.location.hash === '#setup-open') history.replaceState(null, '', window.location.pathname + window.location.search); });">

<div class="flex-1 min-w-0 overflow-y-auto">
<div class="max-w-7xl mx-auto space-y-6">

    {{-- Buyer-match regeneration banner (spec D7) --}}
    @if($regenerating ?? false)
    <div class="rounded-md px-4 py-3 flex items-center gap-2 text-sm"
         style="background: rgba(245,158,11,.12); color: #b45309; border: 1px solid rgba(245,158,11,.30);">
        <span>⚠</span>
        <span>Rebuilding buyer matches — counts may be stale. Refresh in a few minutes.</span>
    </div>
    @endif

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Market Intelligence</h1>
                <p class="text-sm text-white/60">Portal listings captured by your team — {{ number_format($listings->total()) }} results.</p>
            </div>
            <div class="flex items-center gap-2">
                @if(auth()->user()?->hasPermission('prospecting_setup.manage'))
                <button type="button"
                        @click="setupDrawerOpen = !setupDrawerOpen"
                        title="Configure prospecting segments (towns, property types, bedroom segments, price bands)"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md inline-flex items-center gap-1.5"
                        style="background: rgba(255,255,255,0.10); color: #fff; border: 1px solid rgba(255,255,255,0.20);">
                    ⚙ <span>Setup</span>
                </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Smart Filter Presets — one-click filters that scope both the aggregate
         tiles and the listings table below. Hooks into the existing URL-state
         filter pipeline via ?preset=<key>. --}}
    @include('prospecting._smart-filter-presets', [
        'presets'              => $presets,
        'activePreset'         => $activePreset,
        'isProspectingManager' => $isProspectingManager,
    ])

    {{-- Prospecting Intelligence — summary block (Prompt 04).
         Consumes $snapshot, $filters, $segmentLabels — all passed by
         ProspectingController@index post-Prompt-03 refactor. --}}
    @include('prospecting._summary-block')

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
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-xs font-medium mb-1" style="color: var(--text-muted);">In Our Stock</div>
            <div class="text-[1.625rem] font-semibold" style="color: var(--brand-default);">{{ number_format($stats['in_stock'] ?? 0) }}</div>
        </div>
    </div>

    {{-- Match Toggles --}}
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
        <span class="text-xs" style="color:var(--border);">|</span>
        <a href="{{ route('prospecting.index', array_merge(request()->except('stock_filter'), ['stock_filter' => 'in_stock'])) }}"
           class="text-xs px-3 py-1.5 rounded-md no-underline {{ request('stock_filter') === 'in_stock' ? 'font-bold' : '' }}"
           style="{{ request('stock_filter') === 'in_stock' ? 'background:var(--brand-default);color:#fff;' : 'background:var(--surface);color:var(--text-muted);border:1px solid var(--border);' }}">
            In Stock
        </a>
        <a href="{{ route('prospecting.index', array_merge(request()->except('stock_filter'), ['stock_filter' => 'not_in_stock'])) }}"
           class="text-xs px-3 py-1.5 rounded-md no-underline {{ request('stock_filter') === 'not_in_stock' ? 'font-bold' : '' }}"
           style="{{ request('stock_filter') === 'not_in_stock' ? 'background:var(--brand-default);color:#fff;' : 'background:var(--surface);color:var(--text-muted);border:1px solid var(--border);' }}">
            Not In Stock
        </a>
        @if(request('stock_filter'))
        <a href="{{ route('prospecting.index', request()->except('stock_filter')) }}" class="text-xs no-underline" style="color:var(--text-muted);">Clear</a>
        @endif
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

    {{-- Filtered-to-zero banner — sits above the listing table.
         Rebuilds $urlWithout inline because Blade closures defined inside
         _summary-block don't leak back to this parent scope. --}}
    @php
        // User-applied filters only (exclude system params like agency_id,
        // pagination, sort, listing_type default, and the funnel view toggle).
        $systemKeys = ['agency_id', 'listing_type', 'funnel_view', 'sort', 'page', 'per_page'];
        $userFilterCount = collect($filters ?? [])
            ->except($systemKeys)
            ->filter(fn ($v) => $v !== null && $v !== '' && $v !== false)
            ->count();

        // Local urlWithout closure — DateTimeInterface → Y-m-d serialisation
        // mirrors _summary-block's $serialiseFilters helper.
        $urlWithoutBuilder = function (string $key) use ($filters) {
            $new = $filters ?? [];
            unset($new[$key]);
            $clean = [];
            foreach ($new as $k => $v) {
                if ($v === null || $v === '' || $v === false) continue;
                if ($k === 'agency_id') continue;
                if ($v instanceof \DateTimeInterface) { $clean[$k] = $v->format('Y-m-d'); continue; }
                if (is_array($v))                     { $clean[$k] = array_values($v); continue; }
                $clean[$k] = $v;
            }
            return route('prospecting.index') . ($clean ? '?' . http_build_query($clean) : '');
        };

        // Snapshot is built from the intelligence-layer filter set; its
        // activeListings figure reflects all user-applied filter keys
        // (town_id, bedroom_segment_id, etc.). The legacy $listings paginator
        // uses different filter keys (portal_source, etc.) so checking it
        // alone would miss the new-filter empty-state cases.
        $intelEmpty = isset($snapshot) ? $snapshot->activeListings === 0 : false;
    @endphp

    @if($intelEmpty && $userFilterCount > 0)
        @include('prospecting._empty-state', [
            'kind'       => 'filtered_to_zero',
            'filters'    => $filters ?? [],
            'urlWithout' => $urlWithoutBuilder,
        ])
    @endif

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
                        @php
                            $stateId         = $listing->id;
                            $stPitch         = $listingStates['pitches'][$stateId] ?? null;
                            $stClaim         = $listingStates['claims'][$stateId] ?? null;
                            $stPres          = $listingStates['presentations'][$stateId] ?? null;
                            $stContactCnt    = $listingStates['contact_counts'][$stateId] ?? 0;
                            $stPromoted      = $listing->matched_property_id
                                                && isset($listingStates['promotions'][(int) $listing->matched_property_id]);
                            $stClaimedByMe   = $stClaim && (int) $stClaim['user_id'] === (int) auth()->id();
                            $stClaimedByOther = $stClaim && !$stClaimedByMe;
                            $stTempLock      = $listingStates['temp_locks'][$stateId] ?? null;
                            $stLockedByMe    = $stTempLock && (int) $stTempLock['user_id'] === (int) auth()->id();
                            $stLockedByOther = $stTempLock && !$stLockedByMe;
                            $canPitch        = auth()->user()?->hasPermission('outreach.compose');
                            $canManageClaims = auth()->user()?->hasPermission('prospecting_setup.manage');
                        @endphp
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                                   class="text-sm font-medium hover:underline" style="color: var(--brand-icon);">
                                    {{ Str::limit($listing->address, 40) }}
                                </a>

                                {{-- IN STOCK badge (existing) — links to property show --}}
                                @if($listing->matched_property_id)
                                <a href="{{ route('corex.properties.show', $listing->matched_property_id) }}"
                                   class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline" style="background:var(--brand-default); color:#fff;">
                                    IN STOCK
                                </a>
                                @endif

                                {{-- Pitched badge — green if old, amber if within 7 days --}}
                                @if($stPitch)
                                <a href="{{ $canPitch ? route('seller-outreach.composer.timeline', ['contact' => $stPitch['contact_id']]) : '#' }}"
                                   @if(!$canPitch) onclick="return false;" @endif
                                   class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline"
                                   style="background: {{ $stPitch['is_recent'] ? 'color-mix(in srgb, var(--ds-amber) 18%, transparent)' : 'color-mix(in srgb, var(--ds-green) 18%, transparent)' }};
                                          color: {{ $stPitch['is_recent'] ? 'var(--ds-amber)' : 'var(--ds-green)' }};
                                          border: 1px solid currentColor;"
                                   title="Last pitched {{ \Carbon\Carbon::parse($stPitch['sent_at'])->diffForHumans() }}{{ $stPitch['agent_name'] ? ' by ' . $stPitch['agent_name'] : '' }} via {{ $stPitch['channel'] }}{{ $stPitch['outcome'] && $stPitch['outcome'] !== 'sent' ? ' · outcome: ' . str_replace('_', ' ', $stPitch['outcome']) : '' }}">
                                    {{ $stPitch['is_recent'] ? '⚠' : '✅' }} Pitched {{ \Carbon\Carbon::parse($stPitch['sent_at'])->format('j M') }}
                                </a>
                                @endif

                                {{-- Claim badge — only show on rows OTHER than the existing Claim column to surface a quick visual cue --}}
                                @if($stClaim)
                                <span class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                      style="background: {{ $stClaim['is_expiring'] ? 'color-mix(in srgb, var(--ds-crimson, #dc2626) 18%, transparent)' : 'color-mix(in srgb, var(--brand-button) 18%, transparent)' }};
                                             color: {{ $stClaim['is_expiring'] ? 'var(--ds-crimson, #dc2626)' : 'var(--brand-button)' }};
                                             border: 1px solid currentColor;"
                                      title="Claimed {{ \Carbon\Carbon::parse($stClaim['claimed_at'])->diffForHumans() }} · status: {{ $stClaim['status'] }}{{ $stClaim['hours_left'] !== null ? ' · ' . round($stClaim['hours_left'], 1) . 'h until expiry' : '' }}">
                                    {{ $stClaim['is_expiring'] ? '⏰' : '🔒' }}
                                    {{ $stClaimedByMe ? 'You' : (explode(' ', (string) $stClaim['claimer_name'])[0] ?? 'Other') }}
                                </span>
                                @endif

                                {{-- Temp pitch-lock badge — visible only when another agent has the composer open. --}}
                                @if($stLockedByOther)
                                <span class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                      style="background: color-mix(in srgb, var(--ds-amber) 18%, transparent); color: var(--ds-amber); border: 1px solid currentColor;"
                                      title="{{ $stTempLock['user_name'] }} is composing a pitch on this listing (lock expires in {{ (int) $stTempLock['minutes_left'] }} min)">
                                    ⏳ Pitching ({{ explode(' ', (string) $stTempLock['user_name'])[0] ?? 'agent' }})
                                </span>
                                @endif

                                {{-- BM/admin or owner — Release claim button --}}
                                @if($stClaim && ($canManageClaims || $stClaimedByMe))
                                <button type="button"
                                        @click="releaseClaimId = {{ $stClaim['claim_id'] }}; releaseClaimLabel = '{{ addslashes((string) ($stClaim['claimer_name'] ?? 'agent')) }}'; releaseModalOpen = true"
                                        class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                        style="background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);"
                                        title="Release this claim back to the prospecting pool">
                                    ↩ Release
                                </button>
                                @endif

                                {{-- Presentation badge --}}
                                @if($stPres)
                                <a href="/presentations/{{ $stPres['presentation_id'] }}" target="_blank"
                                   class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline"
                                   style="background: color-mix(in srgb, #7c3aed 18%, transparent); color: #7c3aed; border: 1px solid currentColor;"
                                   title="Presentation '{{ $stPres['title'] ?: 'Untitled' }}'{{ $stPres['creator_name'] ? ' by ' . $stPres['creator_name'] : '' }} on {{ \Carbon\Carbon::parse($stPres['created_at'])->format('j M Y') }}">
                                    📊 Presented
                                </a>
                                @endif

                                {{-- Contact-linked badge --}}
                                @if($stContactCnt > 0)
                                <span class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                      style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);"
                                      title="{{ $stContactCnt }} contact{{ $stContactCnt === 1 ? '' : 's' }} linked to this property">
                                    👤 {{ $stContactCnt }}
                                </span>
                                @endif

                                {{-- State-aware primary CTA — only one renders per row --}}
                                @if($canPitch)
                                    @if($stClaimedByOther)
                                        <span class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                              style="background: var(--surface-2); color: var(--text-muted); border: 1px dashed var(--border);"
                                              title="This listing is claimed by another agent in your agency — coordinate before pitching">
                                            Claimed
                                        </span>
                                    @elseif($stLockedByOther)
                                        {{-- Temp lock by another agent → Pitch button blocked. --}}
                                        <span class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                              style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border: 1px dashed currentColor;"
                                              title="{{ $stTempLock['user_name'] }} is composing a pitch on this listing. Lock expires in {{ (int) $stTempLock['minutes_left'] }} min.">
                                            ⏳ Pitching in progress
                                        </span>
                                    @elseif($stPromoted)
                                        <a href="{{ route('seller-outreach.entry.from-property', $listing->matched_property_id) }}"
                                           class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline"
                                           style="background: color-mix(in srgb, #00d4aa 18%, transparent); color: #00d4aa; border: 1px solid color-mix(in srgb, #00d4aa 35%, transparent);"
                                           title="Property is in your agency stock — pitch via the property record">
                                            💬 Pitch (stock)
                                        </a>
                                    @elseif($stPitch && $stPitch['is_recent'])
                                        <a href="{{ route('seller-outreach.composer.timeline', ['contact' => $stPitch['contact_id']]) }}"
                                           class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline"
                                           style="background: color-mix(in srgb, var(--ds-amber) 18%, transparent); color: var(--ds-amber); border: 1px solid var(--ds-amber);"
                                           title="Pitched recently — review the timeline before sending again">
                                            ⚠ View pitch
                                        </a>
                                    @else
                                        <a href="{{ route('seller-outreach.entry.from-prospecting', $listing->id) }}"
                                           class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded no-underline"
                                           style="background: color-mix(in srgb, #00d4aa 18%, transparent); color: #00d4aa; border: 1px solid color-mix(in srgb, #00d4aa 35%, transparent);"
                                           title="Capture the seller's contact and compose a pitch about this property">
                                            💬 Pitch seller
                                        </a>
                                        {{-- Explicit Claim button: lets an agent take ownership without pitching yet
                                             (e.g. parking a hot lead). Only shown when listing is genuinely available. --}}
                                        @if(!$stClaim)
                                            <form method="POST" action="{{ route('prospecting.claim', $listing->id) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1 text-[0.625rem] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded"
                                                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                                                        title="Claim ownership of this listing without pitching yet">
                                                    🔒 Claim
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                @endif
                            </div>
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

                        {{-- Buyer Matches — tier-ranked badge with agency-configurable cutoffs.
                             Click to open the side-panel drill-down with full buyer details.
                             Defaults (HFC): strong ≥80, mid 50-79, weak <50.
                             Configure under Settings → Prospecting Setup → Buyer Match Tiers. --}}
                        <td class="px-4 py-3 text-center">
                            @php
                                $bt = $buyerTiers[$listing->id] ?? null;
                                $btShowWeak = (bool) ($tierConfig['show_weak_in_badge'] ?? true);
                                $btTitle = $bt
                                    ? trim(
                                        ($bt['strong'] > 0 ? $bt['strong'] . ' ' . $tierConfig['strong_label'] . ' · ' : '') .
                                        ($bt['mid']    > 0 ? $bt['mid']    . ' ' . $tierConfig['mid_label']    . ' · ' : '') .
                                        ($bt['weak']   > 0 ? $bt['weak']   . ' ' . $tierConfig['weak_label']   . ' · ' : '') .
                                        ($bt['top_score'] !== null ? 'top score ' . $bt['top_score'] . '%' : ''),
                                        ' ·'
                                    )
                                    : '';
                            @endphp
                            @if($bt && $bt['total'] > 0)
                                <button type="button"
                                        @click="openBuyerPanel({{ $listing->id }})"
                                        class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full transition hover:brightness-110"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                        title="{{ $btTitle }} — click for details">
                                    @if($bt['strong'] > 0)
                                        <span class="inline-flex items-center gap-0.5" style="color: var(--ds-green, #10b981);">🟢 {{ $bt['strong'] }}</span>
                                    @endif
                                    @if($bt['mid'] > 0)
                                        <span class="inline-flex items-center gap-0.5 {{ $bt['strong'] > 0 ? 'pl-1' : '' }}"
                                              style="color: var(--ds-amber, #f59e0b); {{ $bt['strong'] > 0 ? 'border-left: 1px solid var(--border); margin-left: 2px;' : '' }}">
                                            🟡 {{ $bt['mid'] }}
                                        </span>
                                    @endif
                                    @if($bt['weak'] > 0 && $btShowWeak)
                                        <span class="inline-flex items-center gap-0.5 {{ ($bt['strong'] + $bt['mid']) > 0 ? 'pl-1' : '' }}"
                                              style="color: var(--text-muted); {{ ($bt['strong'] + $bt['mid']) > 0 ? 'border-left: 1px solid var(--border); margin-left: 2px;' : '' }}">
                                            ⚪ {{ $bt['weak'] }}
                                        </span>
                                    @endif
                                </button>
                            @else
                                <span class="inline-flex items-center text-[10px] px-2 py-0.5 rounded-full"
                                      style="background: var(--surface-2); color: var(--text-muted);"
                                      title="No matching buyers above the configured threshold — consider widening wishlists or lowering thresholds in Settings → Prospecting Setup → Buyer Match Tiers">
                                    No matches
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

                        {{-- Portal source — colour-differentiated per portal:
                             P24 = #1e40af (Property24 brand blue family)
                             PP  = #059669 (Private Property brand green family) --}}
                        <td class="px-4 py-3 text-center">
                            @php
                                $portalStyle = fn ($s) => $s === 'p24'
                                    ? 'background:#1e40af;color:#fff;'
                                    : ($s === 'pp' ? 'background:#059669;color:#fff;' : 'background:#6b7280;color:#fff;');
                                $portalLabel = fn ($s) => $s === 'p24' ? 'P24' : ($s === 'pp' ? 'PP' : strtoupper((string) $s));
                            @endphp
                            @if(!empty($listing->portals))
                                @foreach($listing->portals as $portal)
                                <a href="{{ $portal['url'] }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold me-0.5 no-underline"
                                   style="{{ $portalStyle($portal['source']) }}"
                                   title="{{ $portal['ref'] }}">
                                    {{ $portalLabel($portal['source']) }}
                                </a>
                                @endforeach
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold"
                                      style="{{ $portalStyle($listing->portal_source) }}">
                                    {{ $portalLabel($listing->portal_source) }}
                                </span>
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

</div>{{-- /max-w-7xl mx-auto --}}
</div>{{-- /flex-1 main content column --}}

{{-- Prospecting Setup drawer (flex sibling, opens on ⚙ click) --}}
@if(auth()->user()?->hasPermission('prospecting_setup.manage'))
<aside x-show="setupDrawerOpen" x-cloak
       x-transition:enter="transform transition ease-out duration-200"
       x-transition:enter-start="translate-x-full opacity-0"
       x-transition:enter-end="translate-x-0 opacity-100"
       x-transition:leave="transform transition ease-in duration-150"
       x-transition:leave-start="translate-x-0 opacity-100"
       x-transition:leave-end="translate-x-full opacity-0"
       @keydown.escape.window="setupDrawerOpen = false"
       class="w-full max-w-3xl flex-shrink-0 flex flex-col overflow-hidden"
       style="background: var(--surface); border-left: 1px solid var(--border); box-shadow: -4px 0 12px rgba(0,0,0,0.08);">

    <div class="flex items-center justify-between px-5 py-3 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
        <h2 class="text-base font-semibold" style="color: var(--text-primary);">Prospecting Setup</h2>
        <button type="button" @click="setupDrawerOpen = false"
                class="text-xl leading-none px-2"
                style="color: var(--text-muted); background: none; border: none; cursor: pointer;">×</button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-4">
        @include('settings.prospecting._panel', [
            'activeTab'         => 'towns',
            'towns'             => $prospectingSetupTowns,
            'propertyTypes'     => $prospectingSetupPropertyTypes,
            'bedroomSegments'   => $prospectingSetupBedroomSegments,
            'priceBandsSale'    => $prospectingSetupPriceBandsSale,
            'priceBandsRental'  => $prospectingSetupPriceBandsRental,
            'suggestionRegions' => $prospectingSetupSuggestionRegions,
            'unmappedSuburbs'   => $prospectingSetupUnmappedSuburbs,
            'buyerMatchTier'    => $tierConfig ?? null,
            'context'           => 'drawer',
        ])
    </div>
</aside>
@endif

{{-- BM/admin or self-release modal — captures a reason and posts to releaseAsManager. --}}
<div x-show="releaseModalOpen" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center px-4"
     style="background: rgba(0,0,0,0.55);"
     @click.self="releaseModalOpen = false"
     @keydown.escape.window="releaseModalOpen = false">
    <div class="p-5 rounded-md max-w-md w-full"
         style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-base font-semibold mb-2" style="color: var(--text-primary);">Release claim</h2>
        <p class="text-sm mb-3" style="color: var(--text-secondary);">
            This listing will return to the prospecting pool and any agent can claim it.
            The original claimer's history is preserved on the claim record.
        </p>
        <form :action="`/corex/prospecting/claims/${releaseClaimId}/release-as-manager`" method="POST">
            @csrf
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                Reason (required)
            </label>
            <input type="text" name="reason" required maxlength="500"
                   placeholder="e.g. Agent on leave / no contact in 14 days"
                   class="w-full px-3 py-2 text-sm rounded mb-3"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <div class="flex items-center justify-end gap-2">
                <button type="button" @click="releaseModalOpen = false"
                        class="px-3 py-1.5 text-sm rounded"
                        style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                    Cancel
                </button>
                <button type="submit"
                        class="px-3 py-1.5 text-sm font-medium rounded text-white"
                        style="background: var(--ds-crimson, #dc2626);">
                    Release claim
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Buyer-match drill-down side panel (slides in from the right when an agent clicks a tier badge).
     Loads /corex/prospecting/{listing}/buyer-matches as HTML via fetch. --}}
<div x-show="buyerPanelOpen" x-cloak
     @click="buyerPanelOpen = false"
     class="fixed inset-0 z-40"
     style="background: rgba(0,0,0,0.45);"
     x-transition.opacity></div>

<div x-show="buyerPanelOpen" x-cloak
     class="fixed inset-y-0 right-0 w-full md:w-[480px] z-50 shadow-2xl overflow-y-auto"
     style="background: var(--surface); border-left: 1px solid var(--border);"
     x-transition:enter="transition transform duration-200"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition transform duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     @keydown.escape.window="buyerPanelOpen = false">

    <div class="sticky top-0 flex items-center justify-between px-4 py-3 z-10"
         style="background: var(--brand-default, #0b2a4a); color: #fff;">
        <h2 class="text-sm font-semibold">Buyer Matches</h2>
        <button type="button" @click="buyerPanelOpen = false"
                class="text-2xl leading-none px-2"
                style="color: rgba(255,255,255,0.9);">×</button>
    </div>

    <template x-if="buyerPanelLoading">
        <div class="p-8 text-center text-sm" style="color: var(--text-muted);">Loading…</div>
    </template>

    <div x-show="!buyerPanelLoading" x-html="buyerPanelHtml"></div>
</div>

</div>{{-- /outer flex container --}}

<script>
function openFeedbackModal(listingId, currentStatus) {
    window.dispatchEvent(new CustomEvent('open-feedback', {
        detail: { id: listingId, status: currentStatus }
    }));
}
</script>

