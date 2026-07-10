{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ tab: 'templates', viewMode: localStorage.getItem('docuperfect_view_mode') || 'grid', typeFilter: '', tplTypeFilter: '', search: '' }">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Create Document</h1>
                <p class="text-sm text-white/60">Choose a template or document pack to get started — {{ number_format($templates->count()) }} template{{ $templates->count() === 1 ? '' : 's' }} available.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-outline corex-btn-on-brand">Back to Documents</a>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?section=my-portal&s=feature-documents') }}"
                   title="Create Document Settings"
                   aria-label="Create Document Settings"
                   class="dp-header-icon-btn inline-flex items-center justify-center rounded-md text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Controls bar: tabs + filters + search + view toggle --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        {{-- Top row: tabs + view toggle --}}
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                <button type="button" @click="tab = 'templates'"
                        :style="tab === 'templates' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-secondary);'"
                        class="px-4 py-2 text-sm font-medium transition-all duration-300">
                    Templates
                </button>
                <button type="button" @click="tab = 'packs'"
                        :style="tab === 'packs' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-secondary);'"
                        class="px-4 py-2 text-sm font-medium transition-all duration-300">
                    Document Packs
                </button>
            </div>

            <div class="flex items-center gap-3">
                <div class="flex items-center rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <button type="button" @click="viewMode = 'grid'; localStorage.setItem('docuperfect_view_mode', 'grid')"
                            :style="viewMode === 'grid' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-muted);'"
                            class="px-2.5 py-2 transition-all duration-300" title="Grid view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M4 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5ZM14 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1V5ZM4 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4ZM14 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-4Z"/>
                        </svg>
                    </button>
                    <button type="button" @click="viewMode = 'list'; localStorage.setItem('docuperfect_view_mode', 'list')"
                            :style="viewMode === 'list' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-2); color: var(--text-muted);'"
                            class="px-2.5 py-2 transition-all duration-300" title="List view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- Bottom row: search + filters --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text"
                       x-model.debounce.300ms="search"
                       placeholder="Search templates..."
                       class="w-full pl-10 pr-3 py-2 text-sm rounded-md focus:outline-none transition-all duration-300"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <select x-model="tplTypeFilter" class="rounded-md text-sm px-3 py-2 transition-all duration-300" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All types</option>
                <option value="sales">Sales</option>
                <option value="rental">Rental</option>
                <option value="compliance">Compliance</option>
            </select>
            <select x-model="typeFilter" class="rounded-md text-sm px-3 py-2 transition-all duration-300" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                <option value="">All doc types</option>
                @foreach($documentTypes as $dt)
                <option value="{{ $dt->id }}">{{ $dt->name }}</option>
                @endforeach
                <option value="none">Uncategorized</option>
            </select>
        </div>
    </div>

    {{-- ===================== TEMPLATES TAB ===================== --}}
    <div x-show="tab === 'templates'">
        @if($templates->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No templates yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Templates are created in DocuPerfect Settings — once added, they will appear here.</p>
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-outline">Back to Documents</a>
            </div>
        @else
            {{-- Grid View --}}
            <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($templates as $tpl)
                <div class="rounded-md p-4 flex flex-col transition-all duration-300 docuperfect-card"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     x-show="(tplTypeFilter === '' || tplTypeFilter === '{{ $tpl->template_type ?? '' }}') && (typeFilter === '' || typeFilter === '{{ $tpl->document_type_id ?? 'none' }}') && (search === '' || '{{ strtolower(addslashes($tpl->name)) }}'.includes(search.toLowerCase()))" x-cloak>
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="font-semibold text-sm leading-tight" style="color: var(--text-primary);">{{ $tpl->name }}</div>
                        @if($tpl->documentType)
                        <span class="ds-badge ds-badge-info flex-shrink-0">{{ \Illuminate\Support\Str::limit($tpl->documentType->name, 20, '') }}</span>
                        @elseif($tpl->template_type)
                        <span class="ds-badge ds-badge-info flex-shrink-0">{{ \Illuminate\Support\Str::limit($tpl->template_type, 20, '') }}</span>
                        @endif
                    </div>
                    <div class="text-xs mb-3" style="color: var(--text-muted);">
                        @if($tpl->is_global)
                            Global
                        @else
                            {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                        @endif
                    </div>
                    @if($tpl->page_count > 0)
                    <div class="flex-1 flex items-center justify-center mb-3 rounded-md overflow-hidden" style="background: var(--surface-2);">
                        <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                             alt="{{ $tpl->name }}"
                             style="max-height: 200px; width: auto;"
                             loading="lazy"
                             class="rounded-md mx-auto block" />
                    </div>
                    @endif
                    <div class="mt-auto pt-3" style="border-top: 1px solid var(--border);">
                        <a href="{{ route('docuperfect.documents.create', $tpl->id) }}" class="corex-btn-primary">Create Document</a>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- List View --}}
            <div x-show="viewMode === 'list'" x-cloak>
                <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr style="background: var(--surface-2);">
                                    <th class="text-left px-4 py-2.5 w-12 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);"></th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branches</th>
                                    <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Pages</th>
                                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($templates as $tpl)
                                <tr x-show="(tplTypeFilter === '' || tplTypeFilter === '{{ $tpl->template_type ?? '' }}') && (typeFilter === '' || typeFilter === '{{ $tpl->document_type_id ?? 'none' }}') && (search === '' || '{{ strtolower(addslashes($tpl->name)) }}'.includes(search.toLowerCase()))" x-cloak>
                                    <td class="px-4 py-3">
                                        @if($tpl->page_count > 0)
                                        <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                                             alt="{{ $tpl->name }}"
                                             class="w-10 h-14 object-cover rounded-md"
                                             loading="lazy" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $tpl->name }}</td>
                                    <td class="px-4 py-3">
                                        @if($tpl->documentType)
                                        <span class="ds-badge ds-badge-info">{{ \Illuminate\Support\Str::limit($tpl->documentType->name, 20, '') }}</span>
                                        @elseif($tpl->template_type)
                                        <span class="ds-badge ds-badge-default">{{ \Illuminate\Support\Str::limit($tpl->template_type, 20, '') }}</span>
                                        @else
                                        <span class="text-xs" style="color: var(--text-muted);">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">
                                        @if($tpl->is_global)
                                            <span class="ds-badge ds-badge-success">Global</span>
                                        @else
                                            {{ $tpl->branches->pluck('name')->join(', ') ?: '—' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center" style="color: var(--text-muted);">{{ number_format($tpl->page_count) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('docuperfect.documents.create', $tpl->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Create</a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ===================== PACKS TAB ===================== --}}
    <div x-show="tab === 'packs'" x-cloak>
        @if($packs->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document packs yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Document packs bundle multiple templates together — none have been configured for this agency.</p>
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-outline">Back to Documents</a>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($packs as $pack)
                <div class="rounded-md p-4 flex flex-col transition-all duration-300 docuperfect-card"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     x-show="search === '' || '{{ strtolower(addslashes($pack->name)) }}'.includes(search.toLowerCase())" x-cloak>
                    <div class="font-semibold text-sm leading-tight mb-2" style="color: var(--text-primary);">{{ $pack->name }}</div>
                    @if($pack->description)
                    <div class="text-xs mb-2" style="color: var(--text-muted);">{{ $pack->description }}</div>
                    @endif
                    <div class="text-xs mb-3 flex items-center gap-2 flex-wrap" style="color: var(--text-muted);">
                        <span>
                            @if($pack->usesSlots())
                                {{ number_format($pack->slots->count()) }} slot{{ $pack->slots->count() !== 1 ? 's' : '' }}
                            @else
                                {{ number_format($pack->templates->count()) }} template{{ $pack->templates->count() !== 1 ? 's' : '' }}
                            @endif
                        </span>
                        @if($pack->is_global)
                            <span class="ds-badge ds-badge-success">Global</span>
                        @else
                            <span>{{ $pack->branches->pluck('name')->join(', ') ?: 'No branches' }}</span>
                        @endif
                    </div>

                    @if($pack->usesSlots() && $pack->slots->isNotEmpty())
                    <div class="flex-1 mb-3">
                        <div class="text-[11px] uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Pack contents</div>
                        <ul class="text-xs space-y-1" style="color: var(--text-secondary);">
                            @foreach($pack->slots as $slot)
                            <li class="flex items-center gap-1.5">
                                @if($slot->slot_type === 'required')
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--ds-green);"></span>
                                @elseif($slot->slot_type === 'selectable')
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--ds-amber);"></span>
                                @else
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--brand-icon);"></span>
                                @endif
                                {{ $slot->label }}
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @elseif($pack->templates->isNotEmpty())
                    <div class="flex-1 mb-3">
                        <div class="text-[11px] uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">Templates included</div>
                        <ul class="text-xs space-y-1" style="color: var(--text-secondary);">
                            @foreach($pack->templates as $tpl)
                            <li class="flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--brand-icon);"></span>
                                {{ $tpl->name }}
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <div class="mt-auto pt-3" style="border-top: 1px solid var(--border);">
                        <a href="{{ route('docuperfect.packs.showLaunch', $pack->id) }}" class="corex-btn-primary inline-block">Launch Pack</a>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

</div>

<style>
    .docuperfect-card { transition: border-color 300ms ease, box-shadow 300ms ease; }
    .docuperfect-card:hover { border-color: var(--brand-icon) !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .dp-header-icon-btn {
        width: 30px; height: 30px;
        background: rgba(255,255,255,0.10);
        border: 1px solid rgba(255,255,255,0.18);
        transition: background 300ms ease;
    }
    .dp-header-icon-btn:hover { background: rgba(255,255,255,0.18); }
</style>
@endsection
