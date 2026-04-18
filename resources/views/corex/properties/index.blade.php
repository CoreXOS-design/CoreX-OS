@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ view: localStorage.getItem('prop_view') || 'grid' }" x-init="$watch('view', v => localStorage.setItem('prop_view', v))">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 flex items-center justify-between flex-wrap gap-3" style="background:linear-gradient(135deg,var(--brand-default,#0b2a4a) 0%,#163a5c 100%);">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Properties</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.65);">Manage listings &amp; publish to website</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('corex.properties.create') }}"
               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-md text-xs font-medium transition-all duration-200"
               style="background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.15);"
               title="Classic single-page form">
                Classic form
            </a>
            <a href="{{ route('corex.properties.wizard') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-semibold transition-all duration-200"
               style="background:#fff;color:var(--brand-default,#0b2a4a);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                New Property
            </a>
        </div>
    </div>

    {{-- KPI stats --}}
    @php
        $kpiIcons = [
            'Total'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>',
            'Active'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9" fill="none"/>',
            'Draft'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>',
            'Sold'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
            'Published' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918"/>',
        ];
        $kpiColors = [
            'Total'     => ['bg' => 'rgba(14,165,233,0.10)',  'fg' => '#0284c7'],
            'Active'    => ['bg' => 'rgba(34,197,94,0.10)',   'fg' => '#15803d'],
            'Draft'     => ['bg' => 'rgba(245,158,11,0.10)',  'fg' => '#b45309'],
            'Sold'      => ['bg' => 'rgba(99,102,241,0.10)',  'fg' => '#4338ca'],
            'Published' => ['bg' => 'rgba(236,72,153,0.10)',  'fg' => '#be185d'],
        ];
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 xl:gap-4">
        @foreach([
            ['label' => 'Total',     'value' => $stats['total']],
            ['label' => 'Active',    'value' => $stats['active']],
            ['label' => 'Draft',     'value' => $stats['draft']],
            ['label' => 'Sold',      'value' => $stats['sold']],
            ['label' => 'Published', 'value' => $stats['synced']],
        ] as $kpi)
        @php $c = $kpiColors[$kpi['label']] ?? ['bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)']; @endphp
        <div class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-200 cursor-default"
             style="background:var(--surface); border:1px solid var(--border);"
             onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 16px rgba(0,0,0,0.06)'"
             onmouseout="this.style.transform='';this.style.boxShadow=''">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-md flex-shrink-0" style="background:{{ $c['bg'] }};color:{{ $c['fg'] }};">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    {!! $kpiIcons[$kpi['label']] ?? '' !!}
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-xl font-bold leading-none" style="color:var(--brand-default,#0b2a4a);">{{ $kpi['value'] }}</div>
                <div class="text-[11px] font-medium mt-0.5 uppercase tracking-wider" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0;color:#166534;background:#f0fdf4;">
        {{ session('success') }}
    </div>
    @endif

    {{-- Filters --}}
    @php
        $dataScope = \App\Services\PermissionService::getDataScope(auth()->user(), 'properties');
        // Determine if any "advanced" filters are active so the panel auto-expands
        $advancedActive = collect([
            $filters['listingType'] ?? '', $filters['propertyType'] ?? '',
            $filters['category'] ?? '',     $filters['mandateType'] ?? '',
            $filters['branchFilter'] ?? '', $filters['priceMin'] ?? '',
            $filters['priceMax'] ?? '',     $filters['bedsMin'] ?? '',
            $filters['bathsMin'] ?? '',
        ])->filter(fn($v) => $v !== '' && $v !== null)->isNotEmpty();
    @endphp
    <div x-data="{
            agentPicker: false,
            agentSearch: '',
            advancedOpen: {{ $advancedActive ? 'true' : 'false' }},
            agents: {{ $agentList->toJson() }},
            get filtered() {
                if (!this.agentSearch) return this.agents;
                const q = this.agentSearch.toLowerCase();
                return this.agents.filter(a => a.name.toLowerCase().includes(q) || a.email.toLowerCase().includes(q));
            }
         }"
         class="rounded-md px-4 py-3" style="background:var(--surface);border:1px solid var(--border);">

        <form method="GET" action="{{ route('corex.properties.index') }}" class="flex flex-wrap items-center gap-3">

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color:var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Search title, suburb, or P24 ref..."
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md transition-all duration-300"
                       style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);outline:none;">
            </div>

            {{-- Scope (agents only) --}}
            @if($dataScope === 'own')
            <select name="scope" onchange="this.form.submit()" class="list-header-filter">
                <option value="my" {{ $scope === 'my' ? 'selected' : '' }}>My Listings</option>
                <option value="branch" {{ $scope === 'branch' ? 'selected' : '' }}>Branch</option>
            </select>
            @endif

            {{-- Status --}}
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="" {{ $status === '' ? 'selected' : '' }}>All Statuses</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="draft" {{ $status === 'draft' ? 'selected' : '' }}>Draft</option>
                <option value="sold" {{ $status === 'sold' ? 'selected' : '' }}>Sold</option>
                <option value="withdrawn" {{ $status === 'withdrawn' ? 'selected' : '' }}>Withdrawn</option>
            </select>

            {{-- Listing Type --}}
            <select name="listing_type" onchange="this.form.submit()" class="list-header-filter">
                <option value="" {{ ($filters['listingType'] ?? '') === '' ? 'selected' : '' }}>Sale &amp; Rental</option>
                <option value="sale"   {{ ($filters['listingType'] ?? '') === 'sale'   ? 'selected' : '' }}>For Sale</option>
                <option value="rental" {{ ($filters['listingType'] ?? '') === 'rental' ? 'selected' : '' }}>For Rental</option>
            </select>

            {{-- Sort --}}
            <select name="sort" onchange="this.form.submit()" class="list-header-filter">
                <option value="newest"     {{ ($filters['sort'] ?? 'newest') === 'newest'     ? 'selected' : '' }}>Newest first</option>
                <option value="oldest"     {{ ($filters['sort'] ?? '') === 'oldest'     ? 'selected' : '' }}>Oldest first</option>
                <option value="price_desc" {{ ($filters['sort'] ?? '') === 'price_desc' ? 'selected' : '' }}>Price: high → low</option>
                <option value="price_asc"  {{ ($filters['sort'] ?? '') === 'price_asc'  ? 'selected' : '' }}>Price: low → high</option>
                <option value="title"      {{ ($filters['sort'] ?? '') === 'title'      ? 'selected' : '' }}>Title (A–Z)</option>
            </select>

            {{-- Agent picker (admin/bm only) --}}
            @if($canPickAgent)
            <input type="hidden" name="agent_id" value="{{ $filterAgentId }}">
            <div class="relative" @click.outside="agentPicker = false">
                <button type="button" @click="agentPicker = !agentPicker"
                        class="list-header-filter inline-flex items-center gap-1.5 cursor-pointer"
                        style="{{ $selectedAgent ? 'border-color:var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);' : '' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-1a6 6 0 016-6h0M16 19l2 2 4-4"/>
                    </svg>
                    {{ $selectedAgent ? $selectedAgent->name : 'All Agents' }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                @if($selectedAgent)
                <a href="{{ route('corex.properties.index', ['status' => $status, 'search' => $search, 'agent_id' => '']) }}"
                   class="ml-1 inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold transition-all duration-300"
                   style="color:var(--text-muted);" title="Clear agent filter">&times;</a>
                @endif

                {{-- Picker dropdown --}}
                <div x-show="agentPicker"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-1"
                     class="absolute top-full mt-1.5 left-0 z-50 w-72 rounded-md overflow-hidden"
                     style="background:var(--surface);border:1px solid var(--border);box-shadow:0 8px 30px rgba(0,0,0,0.12);"
                     x-cloak>

                    <div class="p-3" style="border-bottom:1px solid var(--border);">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                            </svg>
                            <input type="text" x-model="agentSearch" placeholder="Search agents..."
                                   class="w-full pl-8 pr-3 py-1.5 text-xs rounded-md outline-none transition-all duration-300"
                                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"
                                   @keydown.escape="agentPicker = false">
                        </div>
                    </div>

                    <div style="max-height:260px;overflow-y:auto;">
                        <a href="{{ route('corex.properties.index', ['status' => $status, 'search' => $search, 'agent_id' => '']) }}"
                           class="flex items-center gap-2 px-4 py-2.5 text-xs font-semibold transition-all duration-300"
                           style="color:var(--text-secondary);border-bottom:1px solid var(--border);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold" style="background:var(--surface-2);color:var(--text-secondary);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                            </span>
                            All agents
                        </a>

                        <template x-for="agent in filtered" :key="agent.id">
                            <a :href="`{{ route('corex.properties.index') }}?agent_id=${agent.id}&status={{ $status }}&search={{ urlencode($search) }}`"
                               class="flex items-center gap-2.5 px-4 py-2.5 text-xs transition-all duration-300"
                               :style="({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? 'background:var(--surface-2);' : '')"
                               onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold flex-shrink-0"
                                      style="background:var(--brand-default,#0b2a4a);color:#fff;"
                                      x-text="agent.name.charAt(0).toUpperCase()">
                                </span>
                                <div class="min-w-0">
                                    <div class="font-semibold truncate" style="color:var(--text-primary);" x-text="agent.name"></div>
                                    <div class="truncate" style="color:var(--text-muted);" x-text="agent.email"></div>
                                </div>
                                <template x-if="{{ $filterAgentId ? $filterAgentId : 0 }} === agent.id">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:var(--brand-icon,#0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </template>
                            </a>
                        </template>

                        <div x-show="filtered.length === 0" class="px-4 py-4 text-xs text-center" style="color:var(--text-muted);">
                            No agents found
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>

            {{-- More filters toggle --}}
            <button type="button" @click="advancedOpen = !advancedOpen"
                    class="list-header-filter inline-flex items-center gap-1.5 cursor-pointer"
                    :style="advancedOpen ? 'border-color:var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);' : ''">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18M6 12h12m-9 7.5h6"/>
                </svg>
                <span>More filters</span>
                @if($advancedActive)
                <span class="inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full text-[9px] font-bold" style="background:var(--brand-icon,#0ea5e9);color:#fff;">●</span>
                @endif
            </button>

            @if(collect(request()->except(['direction','page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <a href="{{ route('corex.properties.index') }}" class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear all</a>
            @endif

            {{-- View toggle --}}
            <div class="flex items-center gap-0.5 ml-auto rounded-md p-0.5" style="background:var(--surface-2);border:1px solid var(--border);">
                <button type="button" @click="view = 'grid'"
                        class="p-1.5 rounded transition-all duration-300"
                        :style="view === 'grid' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'color:var(--text-muted);'"
                        title="Grid view">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                </button>
                <button type="button" @click="view = 'list'"
                        class="p-1.5 rounded transition-all duration-300"
                        :style="view === 'list' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'color:var(--text-muted);'"
                        title="List view">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>

            {{-- ── Advanced filters panel ───────────────────────────────────── --}}
            <div x-show="advancedOpen" x-cloak x-transition class="w-full mt-3 pt-3" style="border-top:1px dashed var(--border);">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-3">

                    {{-- Property Type --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Property Type</label>
                        <select name="property_type" class="list-header-filter w-full">
                            <option value="">Any type</option>
                            @foreach($filterOptions['property_types'] as $opt)
                                <option value="{{ $opt->name }}" {{ ($filters['propertyType'] ?? '') === $opt->name ? 'selected' : '' }}>{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Category --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Category</label>
                        <select name="category" class="list-header-filter w-full">
                            <option value="">Any category</option>
                            @foreach($filterOptions['categories'] as $opt)
                                <option value="{{ $opt->name }}" {{ ($filters['category'] ?? '') === $opt->name ? 'selected' : '' }}>{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Mandate --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Mandate</label>
                        <select name="mandate_type" class="list-header-filter w-full">
                            <option value="">Any mandate</option>
                            @foreach($filterOptions['mandate_types'] as $opt)
                                <option value="{{ $opt->name }}" {{ ($filters['mandateType'] ?? '') === $opt->name ? 'selected' : '' }}>{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Branch (admin/BM only) --}}
                    @if($canPickAgent && $filterOptions['branches']->isNotEmpty())
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Branch</label>
                        <select name="branch_id" class="list-header-filter w-full">
                            <option value="">All branches</option>
                            @foreach($filterOptions['branches'] as $branch)
                                <option value="{{ $branch->id }}" {{ (string) ($filters['branchFilter'] ?? '') === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    {{-- Min Beds --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Min Beds</label>
                        <select name="beds_min" class="list-header-filter w-full">
                            <option value="">Any</option>
                            @for($i = 1; $i <= 6; $i++)
                                <option value="{{ $i }}" {{ (string) ($filters['bedsMin'] ?? '') === (string) $i ? 'selected' : '' }}>{{ $i }}+</option>
                            @endfor
                        </select>
                    </div>

                    {{-- Min Baths --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Min Baths</label>
                        <select name="baths_min" class="list-header-filter w-full">
                            <option value="">Any</option>
                            @for($i = 1; $i <= 6; $i++)
                                <option value="{{ $i }}" {{ (string) ($filters['bathsMin'] ?? '') === (string) $i ? 'selected' : '' }}>{{ $i }}+</option>
                            @endfor
                        </select>
                    </div>

                    {{-- Price Min --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Price Min (R)</label>
                        <input type="number" name="price_min" min="0" step="50000"
                               value="{{ $filters['priceMin'] ?? '' }}" placeholder="0"
                               class="list-header-filter w-full">
                    </div>

                    {{-- Price Max --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Price Max (R)</label>
                        <input type="number" name="price_max" min="0" step="50000"
                               value="{{ $filters['priceMax'] ?? '' }}" placeholder="No max"
                               class="list-header-filter w-full">
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 mt-3">
                    <button type="submit" class="corex-btn-primary text-xs px-4 py-1.5">Apply filters</button>
                </div>
            </div>
        </form>

        {{-- ── Active filter chips ──────────────────────────────────────────── --}}
        @php
            $chipBase = request()->except(['page']);
            $chips = [];
            if ($search !== '')                       $chips[] = ['label' => 'Search: "'.$search.'"',                'key' => 'search'];
            if ($status !== '')                       $chips[] = ['label' => 'Status: '.ucfirst($status),            'key' => 'status'];
            if (($filters['listingType'] ?? '') !== '')  $chips[] = ['label' => $filters['listingType'] === 'sale' ? 'For Sale' : 'For Rental', 'key' => 'listing_type'];
            if (($filters['propertyType'] ?? '') !== '') $chips[] = ['label' => 'Type: '.$filters['propertyType'],   'key' => 'property_type'];
            if (($filters['category'] ?? '') !== '')     $chips[] = ['label' => 'Category: '.$filters['category'],   'key' => 'category'];
            if (($filters['mandateType'] ?? '') !== '')  $chips[] = ['label' => 'Mandate: '.$filters['mandateType'], 'key' => 'mandate_type'];
            if (($filters['branchFilter'] ?? '') !== '' && $canPickAgent) {
                $b = $filterOptions['branches']->firstWhere('id', (int) $filters['branchFilter']);
                if ($b) $chips[] = ['label' => 'Branch: '.$b->name, 'key' => 'branch_id'];
            }
            if (($filters['bedsMin'] ?? '') !== '')   $chips[] = ['label' => $filters['bedsMin'].'+ beds',  'key' => 'beds_min'];
            if (($filters['bathsMin'] ?? '') !== '')  $chips[] = ['label' => $filters['bathsMin'].'+ baths','key' => 'baths_min'];
            if (($filters['priceMin'] ?? '') !== '')  $chips[] = ['label' => 'Min R '.number_format((int) $filters['priceMin'], 0, '.', ' '), 'key' => 'price_min'];
            if (($filters['priceMax'] ?? '') !== '')  $chips[] = ['label' => 'Max R '.number_format((int) $filters['priceMax'], 0, '.', ' '), 'key' => 'price_max'];
        @endphp
        @if(count($chips))
        <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3" style="border-top:1px dashed var(--border);">
            <span class="text-[10px] font-bold uppercase tracking-wider" style="color:var(--text-muted);">Active:</span>
            @foreach($chips as $chip)
                @php $params = $chipBase; unset($params[$chip['key']]); @endphp
                <a href="{{ route('corex.properties.index', $params) }}"
                   class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium transition-all duration-300"
                   style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);"
                   onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 18%, transparent)'"
                   onmouseout="this.style.background='color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent)'"
                   title="Remove this filter">
                    {{ $chip['label'] }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </a>
            @endforeach
        </div>
        @endif

    </div>

    {{-- Cards grid --}}
    @if($properties->isEmpty())
    <div class="rounded-md py-14 px-6 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <div class="relative mx-auto mb-4" style="width:96px;height:96px;">
            <div class="absolute inset-0 rounded-full" style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent);"></div>
            <svg xmlns="http://www.w3.org/2000/svg" class="absolute inset-0 m-auto w-14 h-14" style="color:var(--brand-icon,#0ea5e9);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>
            </svg>
            <span class="absolute -right-1 -bottom-1 inline-flex items-center justify-center w-7 h-7 rounded-full text-white font-bold" style="background:var(--brand-icon,#0ea5e9);box-shadow:0 2px 6px rgba(14,165,233,0.4);">+</span>
        </div>
        @if(collect(request()->except(['direction','page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <h3 class="text-base font-semibold" style="color:var(--text-primary);">No properties match these filters.</h3>
            <p class="text-sm mt-1" style="color:var(--text-muted);">Try clearing some filters, or add a new listing.</p>
        @else
            <h3 class="text-base font-semibold" style="color:var(--text-primary);">No properties yet.</h3>
            <p class="text-sm mt-1" style="color:var(--text-muted);">Start with your first listing. Takes under 3 minutes.</p>
        @endif
        <div class="mt-5 flex items-center justify-center gap-2 flex-wrap">
            <a href="{{ route('corex.properties.wizard') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-sm font-semibold text-white transition-all duration-200"
               style="background:var(--brand-button,#0ea5e9);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Create my first listing
            </a>
            @if(collect(request()->except(['direction','page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <a href="{{ route('corex.properties.index') }}" class="text-sm font-medium" style="color:var(--text-muted);">Clear filters</a>
            @endif
        </div>
    </div>
    @else

    {{-- ═══ GRID VIEW ═══ --}}
    <div x-show="view === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        @foreach($properties as $property)
        @php
            $images = $property->allImages();
            $thumb  = $images[0] ?? null;
            $sMap = [
                'draft'     => ['bg' => 'var(--surface-2)', 'text' => 'var(--text-muted)', 'label' => 'Draft'],
                'active'    => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Active'],
                'sold'      => ['bg' => '#dbeafe', 'text' => '#1e40af', 'label' => 'Sold'],
                'withdrawn' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Withdrawn'],
            ];
            $sc = $sMap[$property->status] ?? ['bg' => 'var(--surface-2)', 'text' => 'var(--text-muted)', 'label' => ucfirst($property->status)];
        @endphp
        <div class="rounded-md overflow-hidden flex flex-col transition-all duration-300"
             style="background:var(--surface); border:1px solid var(--border);"
             onmouseover="this.style.borderColor='var(--brand-icon,#0ea5e9)';this.style.boxShadow='0 4px 16px rgba(0,0,0,0.06)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">

            {{-- Thumbnail --}}
            <a href="{{ route('corex.properties.show', $property) }}" class="relative block h-44 flex-shrink-0 overflow-hidden" style="background:var(--brand-default,#0b2a4a);">
                @if($thumb)
                    <img src="{{ $thumb }}" alt="{{ $property->title }}" class="w-full h-full object-cover">
                @else
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12" style="color:rgba(255,255,255,0.12);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>
                        </svg>
                    </div>
                @endif

                {{-- Overlay gradient --}}
                <div class="absolute inset-x-0 bottom-0 h-20" style="background:linear-gradient(to top,rgba(0,0,0,0.45),transparent);pointer-events:none;"></div>

                {{-- Price on image --}}
                <span class="absolute bottom-2.5 left-3 text-base font-bold text-white" style="text-shadow:0 1px 3px rgba(0,0,0,0.4);">{{ $property->formattedPrice() }}</span>

                {{-- Live badge --}}
                @if($property->isPublished())
                <span class="absolute top-2.5 left-2.5 px-2 py-0.5 rounded-md text-[10px] font-bold tracking-wider uppercase" style="background:var(--brand-icon,#0ea5e9);color:#fff;">Live</span>
                @endif

                {{-- Status badge --}}
                <span class="absolute top-2.5 right-2.5 px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide"
                      style="background:{{ $sc['bg'] }};color:{{ $sc['text'] }};">{{ $sc['label'] }}</span>

                {{-- Photo count --}}
                @if(count($images) > 0)
                <span class="absolute bottom-2.5 right-2.5 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-medium" style="background:rgba(0,0,0,0.5);color:#fff;backdrop-filter:blur(4px);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5L5 21"/></svg>
                    {{ count($images) }}
                </span>
                @endif
            </a>

            {{-- Content --}}
            <div class="px-3.5 py-3 flex flex-col flex-1">

                {{-- Title --}}
                <a href="{{ route('corex.properties.show', $property) }}" class="text-sm font-semibold leading-snug line-clamp-1 transition-all duration-300" style="color:var(--text-primary);" onmouseover="this.style.color='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.color='var(--text-primary)'">
                    {{ $property->title }}
                </a>

                {{-- Location --}}
                <div class="flex items-center gap-1.5 mt-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
                    </svg>
                    <span class="text-xs truncate" style="color:var(--text-secondary);">
                        {{ $property->buildDisplayAddress() }}
                    </span>
                </div>

                {{-- Property type + features row --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs" style="color:var(--text-secondary);">
                    <span class="capitalize">{{ str_replace('_', ' ', $property->property_type) }}</span>
                    <span style="color:var(--border);">|</span>
                    @if($property->beds)<span>{{ $property->beds }} Bed</span>@endif
                    @if($property->baths)<span>{{ $property->baths }} Bath</span>@endif
                    @if($property->garages)<span>{{ $property->garages }} Gar</span>@endif
                    @if($property->size_m2)<span>{{ number_format($property->size_m2) }} m²</span>@endif
                </div>

                @if($property->p24_ref)
                <div class="mt-1.5">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold"
                          style="background:rgba(14,165,233,0.12); color:#0284c7; border:1px solid rgba(14,165,233,0.3);"
                          title="Property24 listing number">
                        P24: {{ $property->p24_ref }}
                    </span>
                </div>
                @endif

                <div class="flex-1"></div>

                {{-- Footer --}}
                <div class="flex items-center justify-between mt-2.5 pt-2.5" style="border-top:1px solid var(--border);">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-md text-[9px] font-bold flex-shrink-0" style="background:var(--brand-default,#0b2a4a);color:#fff;">{{ strtoupper(substr($property->agent?->name ?? '?', 0, 1)) }}</span>
                        <span class="text-xs truncate" style="color:var(--text-muted);" title="{{ $property->agent?->name }}">{{ $property->agent?->name ?? '—' }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <a href="{{ route('corex.properties.show', $property) }}"
                           class="corex-btn-outline text-[10px] px-2 py-1">View</a>
                        <a href="{{ route('corex.properties.ad', $property) }}"
                           target="_blank"
                           class="corex-btn-outline text-[10px] px-2 py-1"
                           title="Create Ad">Ad</a>
                        <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                              onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-[10px] font-medium px-2 py-1 rounded-md transition-all duration-300"
                                    style="color:var(--text-muted);"
                                    onmouseover="this.style.color='#ef4444';this.style.background='rgba(239,68,68,0.08)'"
                                    onmouseout="this.style.color='var(--text-muted)';this.style.background=''">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ═══ LIST VIEW ═══ --}}
    <div x-show="view === 'list'" x-cloak class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="border-bottom:1px solid var(--border);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Property</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Location</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold hidden sm:table-cell" style="color:var(--text-muted);">Type</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Price</th>
                    <th class="text-center px-4 py-2.5 text-xs font-semibold hidden md:table-cell" style="color:var(--text-muted);">Bed</th>
                    <th class="text-center px-4 py-2.5 text-xs font-semibold hidden md:table-cell" style="color:var(--text-muted);">Bath</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold hidden lg:table-cell" style="color:var(--text-muted);">Agent</th>
                    <th class="text-center px-4 py-2.5 text-xs font-semibold" style="color:var(--text-muted);">Status</th>
                    <th class="px-4 py-2.5 text-xs font-semibold text-right" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $property)
                @php
                    $sMap = [
                        'draft'     => ['bg' => 'var(--surface-2)', 'text' => 'var(--text-muted)', 'label' => 'Draft'],
                        'active'    => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Active'],
                        'sold'      => ['bg' => '#dbeafe', 'text' => '#1e40af', 'label' => 'Sold'],
                        'withdrawn' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Withdrawn'],
                    ];
                    $sc = $sMap[$property->status] ?? ['bg' => 'var(--surface-2)', 'text' => 'var(--text-muted)', 'label' => ucfirst($property->status)];
                @endphp
                <tr class="transition-all duration-300" style="border-bottom:1px solid var(--border);"
                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                    <td class="px-4 py-2.5">
                        <a href="{{ route('corex.properties.show', $property) }}" class="font-semibold text-sm transition-all duration-300" style="color:var(--text-primary);" onmouseover="this.style.color='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.color='var(--text-primary)'">
                            {{ Str::limit($property->title, 35) }}
                        </a>
                        @if($property->p24_ref)
                        <div class="text-[10px] font-mono mt-0.5" style="color:#0284c7;" title="Property24 listing number">P24: {{ $property->p24_ref }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs" style="color:var(--text-secondary);">
                        {{ $property->buildDisplayAddress() }}
                    </td>
                    <td class="px-4 py-2.5 text-xs capitalize hidden sm:table-cell" style="color:var(--text-secondary);">
                        {{ str_replace('_', ' ', $property->property_type) }}
                    </td>
                    <td class="px-4 py-2.5 text-sm font-semibold text-right" style="color:var(--brand-default,#0b2a4a);">
                        {{ $property->formattedPrice() }}
                    </td>
                    <td class="px-4 py-2.5 text-xs text-center hidden md:table-cell" style="color:var(--text-secondary);">{{ $property->beds ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs text-center hidden md:table-cell" style="color:var(--text-secondary);">{{ $property->baths ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-xs hidden lg:table-cell" style="color:var(--text-muted);">{{ $property->agent?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-center">
                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide" style="background:{{ $sc['bg'] }};color:{{ $sc['text'] }};">{{ $sc['label'] }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('corex.properties.show', $property) }}" class="corex-btn-outline text-[10px] px-2 py-1">View</a>
                            <a href="{{ route('corex.properties.ad', $property) }}" target="_blank" class="corex-btn-outline text-[10px] px-2 py-1">Ad</a>
                            <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                                  onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded-md transition-all duration-300" style="color:var(--text-muted);"
                                        onmouseover="this.style.color='#ef4444';this.style.background='rgba(239,68,68,0.08)'"
                                        onmouseout="this.style.color='var(--text-muted)';this.style.background=''">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-right mt-2" style="color:var(--text-muted);">
        {{ $properties->count() }} {{ \Illuminate\Support\Str::plural('property', $properties->count()) }} shown
    </p>
    @endif

</div>
@endsection
