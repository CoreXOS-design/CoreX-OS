@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ view: localStorage.getItem('prop_view') || 'grid' }" x-init="$watch('view', v => localStorage.setItem('prop_view', v))">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5 flex items-center justify-between" style="background:var(--brand-default,#0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white tracking-tight">Properties</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">Manage listings &amp; publish to website</p>
        </div>
        <a href="{{ route('corex.properties.create') }}" class="corex-btn-primary text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Property
        </a>
    </div>

    {{-- KPI stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 xl:gap-4">
        @foreach([
            ['label' => 'Total',     'value' => $stats['total']],
            ['label' => 'Active',    'value' => $stats['active']],
            ['label' => 'Draft',     'value' => $stats['draft']],
            ['label' => 'Sold',      'value' => $stats['sold']],
            ['label' => 'Published', 'value' => $stats['synced']],
        ] as $kpi)
        <div class="rounded-md px-4 py-3 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-2xl font-bold leading-none" style="color:var(--brand-default,#0b2a4a);">{{ $kpi['value'] }}</div>
            <div class="text-xs font-medium mt-1" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
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
    @php $dataScope = \App\Services\PermissionService::getDataScope(auth()->user(), 'properties'); @endphp
    <div x-data="{
            agentPicker: false,
            agentSearch: '',
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
                       placeholder="Search title, suburb..."
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
            @if(collect(request()->except(['sort','direction','page']))->filter(fn($v) => $v !== null && $v !== '')->isNotEmpty())
            <a href="{{ route('corex.properties.index') }}" class="text-xs underline transition-all duration-300" style="color:var(--text-muted);">Clear</a>
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
        </form>

    </div>

    {{-- Cards grid --}}
    @if($properties->isEmpty())
    <div class="rounded-md py-16 text-center" style="background:var(--surface); border:1px solid var(--border);">
        <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto w-12 h-12 mb-3" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>
        </svg>
        <p class="text-sm" style="color:var(--text-secondary);">No properties found.</p>
        <a href="{{ route('corex.properties.create') }}"
           class="mt-3 inline-block text-sm font-semibold"
           style="color:var(--brand-icon,#0ea5e9);">+ Create the first listing</a>
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

                <div class="flex-1"></div>

                {{-- Completeness bar --}}
                @php
                    $cParts = array_filter([
                        !empty($property->title),
                        !empty($property->price),
                        !empty($property->property_type),
                        !empty($property->suburb),
                        !empty($property->description),
                        count($property->allImages()) > 0,
                        !empty($property->agent_id),
                        !empty($property->status) && $property->status !== 'draft',
                    ]);
                    $cScore = count($cParts) > 0 ? round((count($cParts) / 8) * 100) : 0;
                    $cColor = $cScore >= 80 ? '#22c55e' : ($cScore >= 50 ? '#f59e0b' : '#ef4444');
                @endphp
                <div class="flex items-center gap-2 mt-2">
                    <div class="flex-1 h-1.5 rounded-full overflow-hidden" style="background:var(--surface-3,#374151);">
                        <div class="h-full rounded-full transition-all" style="width:{{ $cScore }}%; background:{{ $cColor }};"></div>
                    </div>
                    <span class="text-[10px] font-bold flex-shrink-0" style="color:{{ $cColor }};">{{ $cScore }}%</span>
                </div>

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
