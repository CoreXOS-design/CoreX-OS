@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ view: localStorage.getItem('prop_view') || 'grid' }" x-init="$watch('view', v => localStorage.setItem('prop_view', v))">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Properties</h1>
                <p class="text-sm text-white/60">Manage listings and publish to website.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if(auth()->user() && auth()->user()->isOwnerRole())
                <a href="{{ route('corex.properties.import-sold') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   title="Bulk-import sold listings from a spreadsheet">
                    Import Sold
                </a>
                @endif
                <a href="{{ route('corex.properties.create') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08);color:#fff;border:1px solid rgba(255,255,255,0.18);"
                   title="Classic single-page form">
                    Classic form
                </a>
                <a href="{{ route('corex.properties.wizard') }}" class="corex-btn-primary inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Property
                </a>
            </div>
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
            'Total'     => ['bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)',  'fg' => 'var(--brand-icon)'],
            'Active'    => ['bg' => 'color-mix(in srgb, var(--ds-green) 12%, transparent)',    'fg' => 'var(--ds-green)'],
            'Draft'     => ['bg' => 'color-mix(in srgb, var(--ds-amber) 12%, transparent)',    'fg' => 'var(--ds-amber)'],
            'Sold'      => ['bg' => 'color-mix(in srgb, var(--ds-navy) 12%, transparent)',     'fg' => 'var(--ds-navy)'],
            'Published' => ['bg' => 'color-mix(in srgb, var(--brand-icon) 12%, transparent)',  'fg' => 'var(--brand-icon)'],
        ];
    @endphp
    @php
        $kpiTiles = [
            ['label' => 'Total',     'value' => $stats['total'],  'filter' => ''],
            ['label' => 'Active',    'value' => $stats['active'], 'filter' => 'active'],
            ['label' => 'Draft',     'value' => $stats['draft'],  'filter' => 'draft'],
            ['label' => 'Sold',      'value' => $stats['sold'],   'filter' => 'sold'],
            ['label' => 'Published', 'value' => $stats['synced'], 'filter' => 'published'],
        ];
        $currentStatus = $status ?? '';
        $baseUrl = request()->url();
        $preserveParams = collect(request()->query())->except('status', 'page')->toArray();
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 xl:gap-4">
        @foreach($kpiTiles as $kpi)
        @php
            $c = $kpiColors[$kpi['label']] ?? ['bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'];
            $isActive = ($kpi['filter'] === '' && $currentStatus === '') || $kpi['filter'] === $currentStatus;
            $tileUrl = $kpi['filter'] === ''
                ? $baseUrl . '?' . http_build_query($preserveParams)
                : $baseUrl . '?' . http_build_query(array_merge($preserveParams, ['status' => $kpi['filter']]));
        @endphp
        <a href="{{ $tileUrl }}" class="rounded-md px-4 py-3 flex items-center gap-3 transition-all duration-300 no-underline cursor-pointer hover:opacity-80"
             style="background:var(--surface); border:{{ $isActive ? '2px' : '1px' }} solid {{ $isActive ? $c['fg'] : 'var(--border)' }};">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-md flex-shrink-0" style="background:{{ $c['bg'] }};color:{{ $c['fg'] }};">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    {!! $kpiIcons[$kpi['label']] ?? '' !!}
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-[1.625rem] font-semibold leading-none" style="color:var(--text-primary);">{{ number_format((int) $kpi['value']) }}</div>
                <div class="text-[0.6875rem] font-medium mt-1 uppercase tracking-wider" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
            </div>
        </a>
        @endforeach
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                color: var(--text-primary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
        </svg>
        <div class="flex-1">{{ session('success') }}</div>
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
                       onchange="this.form.submit()"
                       class="list-header-filter w-full"
                       style="padding-left:2.25rem;">
            </div>

            {{-- My / All pill toggle (role must grant Data Scope = On for properties.view) --}}
            @if($canPickAgent)
            @php
                $pcuId      = (string) auth()->id();
                $pcIsMine   = (string) $filterAgentId === $pcuId;
                $pcIsAll    = $filterAgentId === '';
                $pcCarry    = request()->except(['agent_id', 'page']);
                $pcMineUrl  = route('corex.properties.index', array_merge($pcCarry, ['agent_id' => $pcuId]));
                $pcAllUrl   = route('corex.properties.index', array_merge($pcCarry, ['agent_id' => '']));
                $pcAllLabel = $dataScope === 'branch' ? 'branch' : 'agency';
            @endphp
            <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <a href="{{ $pcMineUrl }}"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="{{ $pcIsMine ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show only my properties">
                    My Properties
                </a>
                <a href="{{ $pcAllUrl }}"
                   class="px-3 py-2 text-xs font-semibold no-underline transition-all duration-300"
                   style="border-left:1px solid var(--border); {{ $pcIsAll ? 'background:var(--brand-icon,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-muted);' }}"
                   title="Show all {{ $pcAllLabel }} properties">
                    All Properties
                </a>
            </div>
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

            {{-- Agent picker (admin/bm only) — right-aligned modal --}}
            @if($canPickAgent)
            <input type="hidden" name="agent_id" value="{{ $filterAgentId }}">
            <div class="ml-auto flex items-center gap-1">
                <button type="button" @click="agentPicker = true"
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
                   class="inline-flex items-center justify-center w-6 h-6 rounded-md text-xs font-bold transition-all duration-300"
                   style="color:var(--text-muted);" title="Clear agent filter">&times;</a>
                @endif
            </div>

            {{-- Modal popup --}}
            <div x-show="agentPicker" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 style="background:rgba(0,0,0,0.5);"
                 @click.self="agentPicker = false"
                 @keydown.escape.window="agentPicker = false"
                 x-transition.opacity>
                <div class="w-full max-w-md rounded-md overflow-hidden"
                     style="background:var(--surface);border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,0.3);">

                    <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                        <h3 class="text-sm font-semibold" style="color:var(--text-primary);">Select Agent</h3>
                        <button type="button" @click="agentPicker = false"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-md transition-all duration-300"
                                style="color:var(--text-muted);"
                                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-3" style="border-bottom:1px solid var(--border);">
                        <div class="relative">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                            </svg>
                            <input type="text" x-model="agentSearch" placeholder="Search agents..."
                                   class="w-full pl-8 pr-3 py-1.5 text-xs rounded-md outline-none transition-all duration-300"
                                   style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);">
                        </div>
                    </div>

                    <div style="max-height:55vh;overflow-y:auto;">
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

            {{-- View toggle --}}
            <div class="flex items-center gap-0.5 rounded-md" style="height:2.25rem;padding:0.125rem;background:var(--surface-2);border:1px solid var(--border);">
                <button type="button" @click="view = 'grid'"
                        class="h-full px-2 rounded transition-all duration-300 inline-flex items-center justify-center"
                        :style="view === 'grid' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'color:var(--text-muted);'"
                        title="Grid view">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                </button>
                <button type="button" @click="view = 'list'"
                        class="h-full px-2 rounded transition-all duration-300 inline-flex items-center justify-center"
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
                        <select name="property_type" onchange="this.form.submit()" class="list-header-filter w-full">
                            <option value="">Any type</option>
                            @foreach($filterOptions['property_types'] as $opt)
                                <option value="{{ $opt->name }}" {{ ($filters['propertyType'] ?? '') === $opt->name ? 'selected' : '' }}>{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Category --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Category</label>
                        <select name="category" onchange="this.form.submit()" class="list-header-filter w-full">
                            <option value="">Any category</option>
                            @foreach($filterOptions['categories'] as $opt)
                                <option value="{{ $opt->name }}" {{ ($filters['category'] ?? '') === $opt->name ? 'selected' : '' }}>{{ $opt->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Mandate --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Mandate</label>
                        <select name="mandate_type" onchange="this.form.submit()" class="list-header-filter w-full">
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
                        <select name="branch_id" onchange="this.form.submit()" class="list-header-filter w-full">
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
                        <select name="beds_min" onchange="this.form.submit()" class="list-header-filter w-full">
                            <option value="">Any</option>
                            @for($i = 1; $i <= 6; $i++)
                                <option value="{{ $i }}" {{ (string) ($filters['bedsMin'] ?? '') === (string) $i ? 'selected' : '' }}>{{ $i }}+</option>
                            @endfor
                        </select>
                    </div>

                    {{-- Min Baths --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Min Baths</label>
                        <select name="baths_min" onchange="this.form.submit()" class="list-header-filter w-full">
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
                               onchange="this.form.submit()"
                               class="list-header-filter w-full">
                    </div>

                    {{-- Price Max --}}
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Price Max (R)</label>
                        <input type="number" name="price_max" min="0" step="50000"
                               value="{{ $filters['priceMax'] ?? '' }}" placeholder="No max"
                               onchange="this.form.submit()"
                               class="list-header-filter w-full">
                    </div>
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
            $listingTypeLabel = strtolower((string) ($property->listing_type ?? 'sale')) === 'rental' ? 'For Rent' : 'For Sale';
            $statusLabel = ucwords(str_replace('_', ' ', (string) ($property->status ?: 'Draft')));
            $brandPillStyle = 'background:var(--brand-default); color:#fff; border:none;';
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

                {{-- Listing type + Status badges (left, side-by-side) --}}
                <div class="absolute top-2.5 left-2.5 flex flex-row items-center gap-1.5">
                    <span class="text-xs px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $listingTypeLabel }}</span>
                    <span class="text-xs px-2.5 py-1 rounded-full font-semibold" style="{{ $brandPillStyle }}">{{ $statusLabel }}</span>
                </div>

                {{-- Live badge (moved to right to make room for branded pills) --}}
                @if($property->isPublished())
                <span class="ds-badge ds-badge-info absolute top-2.5 right-2.5" style="background:var(--brand-icon);color:#fff;">Live</span>
                @endif

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
                        @php
                            $addrParts = [];
                            if (!empty($property->unit_number)) $addrParts[] = 'Unit ' . $property->unit_number;
                            if (!empty($property->complex_name)) $addrParts[] = $property->complex_name;
                            if (!empty($property->street_number) && !empty($property->street_name)) {
                                $addrParts[] = $property->street_number . ' ' . $property->street_name;
                            } elseif (!empty($property->street_name)) {
                                $addrParts[] = $property->street_name;
                            } elseif (!empty($property->address)) {
                                $addrParts[] = $property->address;
                            }
                            if (!empty($property->suburb)) $addrParts[] = $property->suburb;
                            if (!empty($property->city) && strtolower($property->city) !== strtolower($property->suburb ?? '')) {
                                $addrParts[] = $property->city;
                            }
                        @endphp
                        {{ count($addrParts) ? implode(', ', $addrParts) : '—' }}
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
                          style="background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon); border:1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);"
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
                                    onmouseover="this.style.color='var(--ds-crimson)';this.style.background='color-mix(in srgb, var(--ds-crimson) 8%, transparent)'"
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
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm ds-table">
            @php
                $sortParams = collect(request()->query())->except('sort', 'dir', 'page')->toArray();
                $sortCols = [
                    ['key' => 'title',            'label' => 'Property',  'align' => 'text-left',   'hide' => ''],
                    ['key' => 'suburb',            'label' => 'Location',  'align' => 'text-left',   'hide' => ''],
                    ['key' => 'property_type',     'label' => 'Type',      'align' => 'text-left',   'hide' => 'hidden sm:table-cell'],
                    ['key' => 'price',             'label' => 'Price',     'align' => 'text-right',  'hide' => ''],
                    ['key' => 'beds',              'label' => 'Bed',       'align' => 'text-center', 'hide' => 'hidden md:table-cell'],
                    ['key' => 'baths',             'label' => 'Bath',      'align' => 'text-center', 'hide' => 'hidden md:table-cell'],
                    ['key' => null,                'label' => 'Agent',     'align' => 'text-left',   'hide' => 'hidden lg:table-cell'],
                    ['key' => 'marketing_status',  'label' => 'Marketing', 'align' => 'text-center', 'hide' => 'hidden md:table-cell'],
                    ['key' => 'status',            'label' => 'Status',    'align' => 'text-center', 'hide' => ''],
                ];
            @endphp
            <thead>
                <tr style="background: var(--surface-2);">
                    @foreach($sortCols as $col)
                        <th class="{{ $col['align'] }} px-4 py-2.5 text-xs font-semibold uppercase tracking-wider {{ $col['hide'] }}" style="color:var(--text-muted);">
                            @if($col['key'])
                                @php
                                    $isCurrentSort = ($currentSort ?? '') === $col['key'];
                                    $nextDir = $isCurrentSort && ($currentDir ?? 'desc') === 'asc' ? 'desc' : 'asc';
                                    $arrow = $isCurrentSort ? (($currentDir ?? 'desc') === 'asc' ? '&#9650;' : '&#9660;') : '';
                                @endphp
                                <a href="{{ request()->url() }}?{{ http_build_query(array_merge($sortParams, ['sort' => $col['key'], 'dir' => $nextDir])) }}"
                                   class="no-underline hover:opacity-70 transition" style="color:{{ $isCurrentSort ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ $col['label'] }}{!! $arrow !!}</a>
                            @else
                                {{ $col['label'] }}
                            @endif
                        </th>
                    @endforeach
                    <th class="px-4 py-2.5 text-xs font-semibold text-right" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($properties as $property)
                @php
                    $rowListingLabel = strtolower((string) ($property->listing_type ?? 'sale')) === 'rental' ? 'For Rent' : 'For Sale';
                    $rowStatusLabel = ucwords(str_replace('_', ' ', (string) ($property->status ?: 'Draft')));
                    $rowBrandPillStyle = 'background:var(--brand-default); color:#fff; border:none;';
                @endphp
                <tr class="transition-all duration-300" style="border-bottom:1px solid var(--border);"
                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                    <td class="px-4 py-2.5">
                        <a href="{{ route('corex.properties.show', $property) }}" class="font-semibold text-sm transition-all duration-300" style="color:var(--text-primary);" onmouseover="this.style.color='var(--brand-icon,#0ea5e9)'" onmouseout="this.style.color='var(--text-primary)'">
                            {{ Str::limit($property->title, 35) }}
                        </a>
                        @if($property->p24_ref)
                        <div class="text-[10px] font-mono mt-0.5" style="color:var(--brand-icon);" title="Property24 listing number">P24: {{ $property->p24_ref }}</div>
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
                    <td class="px-4 py-2.5 text-center hidden md:table-cell">
                        @php
                            $ms = $property->marketing_status ?? 'n/a';
                            $msStyle = match($ms) {
                                'live' => 'background:#10b981; color:#fff;',
                                'ready' => 'background:rgba(0,212,170,.15); color:#047857;',
                                'blocked' => 'background:rgba(245,158,11,.15); color:#b45309;',
                                default => '',
                            };
                        @endphp
                        @if($ms !== 'n/a')
                            <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded" style="{{ $msStyle }}" title="{{ $property->marketing_status_detail ?? '' }}">{{ ucfirst($ms) }}</span>
                        @else
                            <span class="text-[10px]" style="color:var(--text-muted);">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-left">
                        <div class="inline-flex flex-row gap-1.5 items-center">
                            <span class="text-xs px-2.5 py-1 rounded-full font-semibold" style="{{ $rowBrandPillStyle }}">{{ $rowListingLabel }}</span>
                            <span class="text-xs px-2.5 py-1 rounded-full font-semibold" style="{{ $rowBrandPillStyle }}">{{ $rowStatusLabel }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-2.5 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('corex.properties.show', $property) }}" class="corex-btn-outline text-[10px] px-2 py-1">View</a>
                            <a href="{{ route('corex.properties.ad', $property) }}" target="_blank" class="corex-btn-outline text-[10px] px-2 py-1">Ad</a>
                            <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                                  onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded-md transition-all duration-300" style="color:var(--text-muted);"
                                        onmouseover="this.style.color='var(--ds-crimson)';this.style.background='color-mix(in srgb, var(--ds-crimson) 8%, transparent)'"
                                        onmouseout="this.style.color='var(--text-muted)';this.style.background=''">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
      </div>
    </div>

    <p class="text-xs text-right mt-2" style="color:var(--text-muted);">
        Showing {{ $properties->firstItem() ?? 0 }}–{{ $properties->lastItem() ?? 0 }} of
        {{ $properties->total() }} {{ \Illuminate\Support\Str::plural('property', $properties->total()) }}
    </p>

    {{-- Pagination --}}
    @if($properties->hasPages())
    <div class="mt-3">
        {{ $properties->links() }}
    </div>
    @endif
    @endif

</div>
@endsection
