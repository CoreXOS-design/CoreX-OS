@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-7xl mx-auto space-y-5">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-5 flex items-center justify-between" style="background:var(--brand-primary,#0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Properties</h2>
            <p class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">Manage listings &amp; publish to website</p>
        </div>
        <a href="{{ route('nexus.properties.create') }}"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white transition-opacity"
           style="background:var(--brand-secondary,#00b4d8);"
           onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Property
        </a>
    </div>

    {{-- KPI stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @foreach([
            ['label' => 'Total',     'value' => $stats['total'],  'color' => '#0b2a4a', 'bg' => 'rgba(11,42,74,0.07)'],
            ['label' => 'Active',    'value' => $stats['active'], 'color' => '#166534', 'bg' => '#dcfce7'],
            ['label' => 'Draft',     'value' => $stats['draft'],  'color' => '#64748b', 'bg' => '#f1f5f9'],
            ['label' => 'Sold',      'value' => $stats['sold'],   'color' => '#1e40af', 'bg' => '#dbeafe'],
            ['label' => 'Published', 'value' => $stats['synced'], 'color' => '#00838f', 'bg' => '#e0f7fa'],
        ] as $kpi)
        <div class="rounded-xl px-4 py-3 text-center" style="background:{{ $kpi['bg'] }};">
            <div class="text-2xl font-bold leading-none" style="color:{{ $kpi['color'] }};">{{ $kpi['value'] }}</div>
            <div class="text-xs font-medium mt-1" style="color:{{ $kpi['color'] }};opacity:.7;">{{ $kpi['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- Flash --}}
    @if(session('success'))
    <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
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
        @if(!in_array($role, ['super_admin', 'admin', 'branch_manager']))
        <div class="flex rounded-lg overflow-hidden border" style="border-color:rgba(11,42,74,0.18);">
            @foreach(['my' => 'My Listings', 'branch' => 'Branch'] as $val => $lbl)
            <a href="{{ request()->fullUrlWithQuery(['scope' => $val, 'status' => $status]) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $scope === $val ? 'background:var(--brand-primary,#0b2a4a);color:#fff;' : 'background:#fff;color:#64748b;' }}">
                {{ $lbl }}
            </a>
            @endforeach
        </div>
        @endif

        {{-- Status tabs --}}
        <div class="flex rounded-lg overflow-hidden border" style="border-color:rgba(11,42,74,0.18);">
            @foreach(['' => 'All', 'active' => 'Active', 'draft' => 'Draft', 'sold' => 'Sold', 'withdrawn' => 'Withdrawn'] as $val => $lbl)
            <a href="{{ request()->fullUrlWithQuery(['status' => $val, 'scope' => $scope, 'agent_id' => $filterAgentId]) }}"
               class="px-3 py-1.5 text-xs font-semibold"
               style="{{ $status === $val ? 'background:var(--brand-primary,#0b2a4a);color:#fff;' : 'background:#fff;color:#64748b;' }}">
                {{ $lbl }}
            </a>
            @endforeach
        </div>

        {{-- Agent picker button (admin/bm only) --}}
        @if($canPickAgent)
        <div class="relative">
            <button type="button" @click="agentPicker = !agentPicker"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors"
                    style="{{ $selectedAgent ? 'background:var(--brand-primary,#0b2a4a);color:#fff;border-color:var(--brand-primary,#0b2a4a);' : 'background:#fff;color:#475569;border-color:rgba(11,42,74,0.18);' }}">
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
            <a href="{{ route('nexus.properties.index', ['status' => $status, 'search' => $search]) }}"
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
                 style="position:absolute;top:calc(100% + 6px);left:0;z-index:50;width:300px;background:#fff;border-radius:14px;border:1px solid rgba(11,42,74,0.14);box-shadow:0 10px 40px rgba(0,0,0,0.15);overflow:hidden;"
                 x-cloak>

                {{-- Header --}}
                <div style="padding:12px 14px 8px;border-bottom:1px solid rgba(11,42,74,0.08);">
                    <p class="text-xs font-semibold" style="color:#0b2a4a;margin-bottom:8px;">View another agent's properties</p>
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color:#94a3b8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>
                        </svg>
                        <input type="text" x-model="agentSearch" placeholder="Search agents…"
                               class="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border outline-none"
                               style="border-color:rgba(11,42,74,0.15);color:#334155;"
                               @keydown.escape="agentPicker = false">
                    </div>
                </div>

                {{-- Agent list --}}
                <div style="max-height:260px;overflow-y:auto;">
                    {{-- All agents option --}}
                    <a href="{{ route('nexus.properties.index', ['status' => $status, 'search' => $search]) }}"
                       class="flex items-center gap-2 px-4 py-2.5 text-xs font-semibold"
                       style="color:#64748b;border-bottom:1px solid rgba(11,42,74,0.06);"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold" style="background:#f1f5f9;color:#64748b;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1h5M12 12a4 4 0 100-8 4 4 0 000 8z"/></svg>
                        </span>
                        All agents
                    </a>

                    <template x-for="agent in filtered" :key="agent.id">
                        <a :href="`{{ route('nexus.properties.index') }}?agent_id=${agent.id}&status={{ $status }}&search={{ urlencode($search) }}`"
                           class="flex items-center gap-2.5 px-4 py-2.5 text-xs"
                           :style="`background:{{ $filterAgentId != '' ? '' : '' }}` + ({{ $filterAgentId ? $filterAgentId : 0 }} === agent.id ? 'background:#eff6ff;' : '')"
                           onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=({{ $filterAgentId ? $filterAgentId : 0 }} === this._agentId ? '#eff6ff' : '')">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0"
                                  style="background:var(--brand-primary,#0b2a4a);color:#fff;"
                                  x-text="agent.name.charAt(0).toUpperCase()">
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-700 truncate" x-text="agent.name"></div>
                                <div class="text-slate-400 truncate" x-text="agent.email"></div>
                            </div>
                            <template x-if="{{ $filterAgentId ? $filterAgentId : 0 }} === agent.id">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 ml-auto flex-shrink-0" style="color:#00b4d8;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </template>
                        </a>
                    </template>

                    <div x-show="filtered.length === 0" class="px-4 py-4 text-xs text-center" style="color:#94a3b8;">
                        No agents found
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Search --}}
        <form method="GET" action="{{ route('nexus.properties.index') }}" class="flex items-center gap-2 ml-auto">
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
                       class="pl-8 pr-3 py-1.5 rounded-lg border text-xs text-slate-700 outline-none"
                       style="border-color:rgba(11,42,74,0.18);width:210px;background:#fff;"
                       onfocus="this.style.borderColor='#00b4d8';this.style.boxShadow='0 0 0 2px rgba(0,180,216,0.15)'"
                       onblur="this.style.borderColor='rgba(11,42,74,0.18)';this.style.boxShadow='none'">
            </div>
            <button type="submit"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                    style="background:var(--brand-secondary,#00b4d8);">Search</button>
            @if($search)
            <a href="{{ route('nexus.properties.index', ['scope' => $scope, 'status' => $status, 'agent_id' => $filterAgentId]) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold border"
               style="color:#64748b;border-color:rgba(11,42,74,0.18);background:#fff;">Clear</a>
            @endif
        </form>

    </div>

    {{-- Cards grid --}}
    @if($properties->isEmpty())
    <div class="rounded-2xl bg-white border border-slate-200 py-16 text-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto w-12 h-12 mb-3" style="color:#cbd5e1;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9.75L12 3l9 6.75V21H3V9.75z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21V12h6v9"/>
        </svg>
        <p class="text-slate-500 text-sm">No properties found.</p>
        <a href="{{ route('nexus.properties.create') }}"
           class="mt-3 inline-block text-sm font-semibold"
           style="color:var(--brand-secondary,#00b4d8);">+ Create the first listing</a>
    </div>
    @else
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
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
        <div class="rounded-2xl bg-white border border-slate-200 overflow-hidden flex flex-col shadow-sm"
             style="transition:box-shadow .15s;"
             onmouseover="this.style.boxShadow='0 6px 24px rgba(0,0,0,0.10)'"
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
                <div class="text-xl font-bold leading-none" style="color:var(--brand-primary,#0b2a4a);">
                    {{ $property->formattedPrice() }}
                </div>

                {{-- Title --}}
                <div class="text-sm font-semibold text-slate-700 mt-1 leading-snug">
                    {{ $property->title }}
                </div>

                {{-- Location + type --}}
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                    <span class="text-xs text-slate-500">
                        {{ $property->suburb }}@if($property->city), {{ $property->city }}@endif
                    </span>
                    <span class="px-1.5 py-0.5 rounded text-xs capitalize" style="background:#f1f5f9;color:#64748b;">
                        {{ str_replace('_', ' ', $property->property_type) }}
                    </span>
                </div>

                {{-- Feature chips --}}
                <div class="flex flex-wrap gap-1.5 mt-2.5">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;">
                        {{ $property->beds }} bed
                    </span>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;">
                        {{ $property->baths }} bath
                    </span>
                    @if($property->garages)
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;">
                        {{ $property->garages }} gar
                    </span>
                    @endif
                    @if($property->size_m2)
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" style="background:#f8fafc;color:#475569;border:1px solid #e2e8f0;">
                        {{ number_format($property->size_m2) }} m²
                    </span>
                    @endif
                </div>

                <div class="flex-1"></div>

                {{-- Footer --}}
                <div class="flex items-center justify-between mt-3 pt-3" style="border-top:1px solid #f1f5f9;">
                    <span class="text-xs text-slate-400 truncate max-w-[110px]" title="{{ $property->agent?->name }}">
                        {{ $property->agent?->name ?? '—' }}
                    </span>
                    <div class="flex items-center gap-1.5">
                        <a href="{{ route('nexus.properties.edit', $property) }}"
                           class="px-3 py-1 rounded-lg text-xs font-semibold text-white transition-opacity hover:opacity-80"
                           style="background:var(--brand-primary,#0b2a4a);">
                            Edit
                        </a>
                        <a href="{{ route('nexus.properties.ad', $property) }}"
                           target="_blank"
                           class="inline-flex items-center gap-1 px-3 py-1 rounded-lg text-xs font-semibold transition-opacity hover:opacity-80"
                           style="background:#7c3aed;color:#fff;"
                           title="Create Ad">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            Ad
                        </a>
                        <form method="POST" action="{{ route('nexus.properties.destroy', $property) }}"
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

    <p class="text-xs text-right" style="color:#94a3b8;">
        {{ $properties->count() }} {{ \Illuminate\Support\Str::plural('property', $properties->count()) }} shown
    </p>
    @endif

</div>
@endsection
