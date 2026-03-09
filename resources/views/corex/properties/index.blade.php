@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-5 flex items-center justify-between" style="background:var(--brand-default,#0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Properties</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">Manage listings &amp; publish to website</p>
        </div>
        <a href="{{ route('corex.properties.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-opacity"
           style="background:var(--brand-button,#0ea5e9);"
           onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Property
        </a>
    </div>

    {{-- KPI stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 xl:gap-4">
        @foreach([
            ['label' => 'Total',     'value' => $stats['total'],  'accent' => 'var(--accent)'],
            ['label' => 'Active',    'value' => $stats['active'], 'accent' => '#22c55e'],
            ['label' => 'Draft',     'value' => $stats['draft'],  'accent' => 'var(--text-muted)'],
            ['label' => 'Sold',      'value' => $stats['sold'],   'accent' => '#3b82f6'],
            ['label' => 'Published', 'value' => $stats['synced'], 'accent' => '#00b4d8'],
        ] as $kpi)
        <div class="rounded-xl px-4 py-3 text-center" style="background:var(--surface); border:1px solid var(--border);">
            <div class="text-2xl font-bold leading-none" style="color:{{ $kpi['accent'] }};">{{ $kpi['value'] }}</div>
            <div class="text-xs font-medium mt-1" style="color:var(--text-muted);">{{ $kpi['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0;color:#166534;background:#f0fdf4;">
        {{ session('success') }}
    </div>
    @endif

    {{-- Filters --}}
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
         class="flex flex-wrap items-center gap-3">

        {{-- Scope toggle — agents only --}}
        @php $dataScope = \App\Services\PermissionService::getDataScope(auth()->user(), 'properties'); @endphp
        @if($dataScope === 'own')
        <div class="flex rounded-lg overflow-hidden" style="border:1px solid var(--border);">

            @foreach(['my' => 'My Listings', 'branch' => 'Branch'] as $val => $lbl)
            <a href="{{ request()->fullUrlWithQuery(['scope' => $val, 'status' => $status]) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $scope === $val ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'background:var(--surface);color:var(--text-secondary);' }}">
                {{ $lbl }}
            </a>
            @endforeach
        </div>
        @endif

        {{-- Status tabs --}}
        <div class="flex rounded-lg overflow-hidden" style="border:1px solid var(--border);">
            @foreach(['' => 'All', 'active' => 'Active', 'draft' => 'Draft', 'sold' => 'Sold', 'withdrawn' => 'Withdrawn'] as $val => $lbl)
            <a href="{{ request()->fullUrlWithQuery(['status' => $val, 'scope' => $scope, 'agent_id' => $filterAgentId]) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $status === $val ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'background:var(--surface);color:var(--text-secondary);' }}">
                {{ $lbl }}
            </a>
            @endforeach
        </div>

        {{-- Agent picker button (admin/bm only) --}}
        @if($canPickAgent)
        <div class="relative">
            <button type="button" @click="agentPicker = !agentPicker"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors"
                    style="{{ $selectedAgent ? 'background:var(--brand-default,#0b2a4a);color:#fff;border-color:var(--brand-default,#0b2a4a);' : 'background:var(--surface);color:var(--text-secondary);border-color:var(--border);' }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-1a6 6 0 016-6h0M16 19l2 2 4-4"/>
                </svg>
                @if($selectedAgent)
                    {{ $selectedAgent->name }}
                @else
                    View Agent
                @endif
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            {{-- Clear agent filter --}}
            @if($selectedAgent)
            <a href="{{ route('corex.properties.index', ['status' => $status, 'search' => $search, 'agent_id' => '']) }}"
               class="ml-1 inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold"
               style="background:#fee2e2;color:#991b1b;" title="Clear agent filter">&times;</a>
            @endif

            {{-- Picker dropdown --}}
            <div x-show="agentPicker"
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 @click.outside="agentPicker = false"
                 style="position:absolute;top:calc(100% + 6px);left:0;z-index:50;width:300px;background:var(--surface);border-radius:14px;border:1px solid var(--border);box-shadow:0 10px 40px var(--shadow);overflow:hidden;"
                 x-cloak>

                {{-- Header --}}
                <div style="padding:12px 14px 8px;border-bottom:1px solid var(--border);">
                    <p class="text-xs font-semibold" style="color:var(--text-primary);margin-bottom:8px;">View another agent's properties</p>
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                        </svg>
                        <input type="text" x-model="agentSearch" placeholder="Search agents…"
                               class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg outline-none"
                               style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"
                               @keydown.escape="agentPicker = false">
                    </div>
                </div>

                {{-- Agent list --}}
                <div style="max-height:260px;overflow-y:auto;">
                    {{-- All agents option --}}
                    <a href="{{ route('corex.properties.index', ['status' => $status, 'search' => $search, 'agent_id' => '']) }}"
                       class="flex items-center gap-2 px-4 py-2.5 text-xs font-semibold"
                       style="color:var(--text-secondary);border-bottom:1px solid var(--border);"
                       onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold" style="background:var(--surface-2);color:var(--text-secondary);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                        </span>
                        All agents
                    </a>

                    <template x-for="agent in filtered" :key="agent.id">
                        <a :href="`{{ route('corex.properties.index') }}?agent_id=${agent.id}&status={{ $status }}&search={{ urlencode($search) }}`"
                           class="flex items-center gap-2.5 px-4 py-2.5 text-xs"
                           :style="`background:{{ $filterAgentId != '' ? '' : '' }}` + ({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? 'background:#eff6ff;' : '')"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=({{ $filterAgentId ? $filterAgentId : 0 }} === this._agentId ? 'var(--accent-glow)' : '')">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0"
                                  style="background:var(--brand-default,#0b2a4a);color:#fff;"
                                  x-text="agent.name.charAt(0).toUpperCase()">
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold truncate" style="color:var(--text-primary);" x-text="agent.name"></div>
                                <div class="truncate" style="color:var(--text-muted);" x-text="agent.email"></div>
                            </div>
                            <template x-if="{{ $filterAgentId ? $filterAgentId : 0 }} === agent.id">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:#00b4d8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
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

        {{-- Search --}}
        <form method="GET" action="{{ route('corex.properties.index') }}" class="flex items-center gap-2 ml-auto">
            <input type="hidden" name="scope"     value="{{ $scope }}">
            <input type="hidden" name="status"    value="{{ $status }}">
            @if($filterAgentId)
            <input type="hidden" name="agent_id"  value="{{ $filterAgentId }}">
            @endif
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:#94a3b8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                </svg>
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Search title, suburb…"
                       class="pl-8 pr-3 py-1.5 rounded-lg text-xs outline-none"
                       style="border:1px solid var(--border);width:210px;background:var(--surface);color:var(--text-primary);"
                       onfocus="this.style.borderColor='#00b4d8';this.style.boxShadow='0 0 0 2px rgba(0,180,216,0.15)'"
                       onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            </div>
            <button type="submit"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                    style="background:var(--brand-button,#0ea5e9);">Search</button>
            @if($search)
            <a href="{{ route('corex.properties.index', ['scope' => $scope, 'status' => $status, 'agent_id' => $filterAgentId]) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold"
               style="color:var(--text-secondary);border:1px solid var(--border);background:var(--surface);">Clear</a>
            @endif
        </form>

    </div>

    {{-- Cards grid --}}
    @if($properties->isEmpty())
    <div class="rounded-2xl py-16 text-center" style="background:var(--surface); border:1px solid var(--border);">
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
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5">
        @foreach($properties as $property)
        @php
            $images = $property->allImages();
            $thumb  = $images[0] ?? null;
            $sMap = [
                'draft'     => ['bg' => '#f1f5f9', 'text' => '#64748b', 'label' => 'Draft'],
                'active'    => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Active'],
                'sold'      => ['bg' => '#dbeafe', 'text' => '#1e40af', 'label' => 'Sold'],
                'withdrawn' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Withdrawn'],
            ];
            $sc = $sMap[$property->status] ?? ['bg' => '#f1f5f9', 'text' => '#64748b', 'label' => ucfirst($property->status)];
        @endphp
        <div class="rounded-2xl overflow-hidden flex flex-col shadow-sm"
             style="background:var(--surface); border:1px solid var(--border); transition:box-shadow .15s;"
             onmouseover="this.style.boxShadow='0 6px 24px var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">

            {{-- Thumbnail --}}
            <div class="relative h-44 flex-shrink-0" style="background:linear-gradient(135deg,#0b2a4a 0%,#0d3259 100%);">
                @if($thumb)
                    <img src="{{ $thumb }}" alt="{{ $property->title }}" class="w-full h-full object-cover">
                @else
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-14 h-14" style="color:rgba(255,255,255,0.15);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>
                        </svg>
                    </div>
                @endif

                {{-- Live badge --}}
                @if($property->isPublished())
                <span class="absolute top-2.5 left-2.5 px-2 py-0.5 rounded-full text-xs font-bold tracking-wide" style="background:#00b4d8;color:#fff;">LIVE</span>
                @endif

                {{-- Status badge --}}
                <span class="absolute top-2.5 right-2.5 px-2 py-0.5 rounded-full text-xs font-semibold"
                      style="background:{{ $sc['bg'] }};color:{{ $sc['text'] }};">{{ $sc['label'] }}</span>

                {{-- Photo count --}}
                @if(count($images) > 0)
                <span class="absolute bottom-2 right-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(0,0,0,0.45);color:#fff;">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 15l-5-5L5 21"/></svg>
                    {{ count($images) }}
                </span>
                @endif
            </div>

            {{-- Content --}}
            <div class="p-4 flex flex-col flex-1">

                {{-- Price --}}
                <div class="text-xl font-bold leading-none" style="color:#00b4d8;">
                    {{ $property->formattedPrice() }}
                </div>

                {{-- Title --}}
                <div class="text-sm font-semibold mt-1 leading-snug" style="color:var(--text-primary);">
                    {{ $property->title }}
                </div>

                {{-- Location + type --}}
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                    <span class="text-xs" style="color:var(--text-secondary);">
                        {{ $property->suburb }}@if($property->city), {{ $property->city }}@endif
                    </span>
                    <span class="px-1.5 py-0.5 rounded text-xs capitalize" style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">
                        {{ str_replace('_', ' ', $property->property_type) }}
                    </span>
                </div>

                {{-- Feature chips --}}
                <div class="flex flex-wrap gap-1.5 mt-2.5">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">
                        {{ $property->beds }} bed
                    </span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">
                        {{ $property->baths }} bath
                    </span>
                    @if($property->garages)
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">
                        {{ $property->garages }} gar
                    </span>
                    @endif
                    @if($property->size_m2)
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);">
                        {{ number_format($property->size_m2) }} m²
                    </span>
                    @endif
                </div>

                <div class="flex-1"></div>

                {{-- Footer --}}
                <div class="flex items-center justify-between mt-3 pt-3" style="border-top:1px solid var(--border);">
                    <span class="text-xs truncate max-w-[110px]" style="color:var(--text-muted);" title="{{ $property->agent?->name }}">
                        {{ $property->agent?->name ?? '—' }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <a href="{{ route('corex.properties.show', $property) }}"
                           class="px-3 py-1 rounded-lg text-xs font-semibold text-white transition-opacity hover:opacity-80"
                           style="background:var(--brand-default,#0b2a4a);">
                            View
                        </a>
                        <a href="{{ route('corex.properties.ad', $property) }}"
                           target="_blank"
                           class="inline-flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-semibold transition-opacity hover:opacity-80"
                           style="background:#7c3aed;color:#fff;"
                           title="Create Ad">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Ad
                        </a>
                        <form method="POST" action="{{ route('corex.properties.destroy', $property) }}"
                              onsubmit="return confirm('Delete \'{{ addslashes($property->title) }}\'?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="px-3 py-1 rounded-lg text-xs font-semibold transition-opacity hover:opacity-80"
                                    style="background:#fee2e2;color:#991b1b;">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
        @endforeach
    </div>

    <p class="text-xs text-right" style="color:var(--text-muted);">
        {{ $properties->count() }} {{ \Illuminate\Support\Str::plural('property', $properties->count()) }} shown
    </p>
    @endif

</div>
@endsection
