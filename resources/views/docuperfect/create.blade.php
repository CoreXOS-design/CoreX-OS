@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">Create Document</h2>
        <div class="text-sm text-white/60">Choose a template or launch a document pack.</div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    <div x-data="{ tab: 'templates', viewMode: localStorage.getItem('docuperfect_view_mode') || 'grid', typeFilter: '', search: '' }">

        {{-- Toggle bar --}}
        <div class="flex items-center gap-1 mb-5 border border-slate-300 rounded-lg overflow-hidden w-fit">
            <button @click="tab = 'templates'"
                    :class="tab === 'templates' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:text-slate-800'"
                    class="px-4 py-2 text-sm font-medium transition-colors">
                Templates
            </button>
            <button @click="tab = 'packs'"
                    :class="tab === 'packs' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 hover:text-slate-800'"
                    class="px-4 py-2 text-sm font-medium transition-colors">
                Document Packs
            </button>
        </div>

        {{-- ===================== TEMPLATES TAB ===================== --}}
        <div x-show="tab === 'templates'">
            {{-- Filter bar --}}
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <select x-model="typeFilter" class="rounded-lg border border-slate-300 bg-white text-slate-700 px-2 py-1.5 text-xs">
                        <option value="">All Types</option>
                        @foreach($documentTypes as $dt)
                        <option value="{{ $dt->id }}">{{ $dt->name }}</option>
                        @endforeach
                        <option value="none">Uncategorized</option>
                    </select>
                    <input type="text" x-model="search" placeholder="Search templates…"
                           class="rounded-lg border border-slate-300 bg-white text-slate-700 px-3 py-1.5 text-xs w-48" />
                </div>
                <div class="flex items-center border border-slate-300 rounded-lg overflow-hidden">
                    <button @click="viewMode = 'grid'; localStorage.setItem('docuperfect_view_mode', 'grid')"
                            :class="viewMode === 'grid' ? 'bg-slate-900 text-white' : 'bg-white text-slate-500 hover:text-slate-700'"
                            class="px-2 py-1.5 transition-colors" title="Grid view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M4 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5ZM14 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1V5ZM4 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4ZM14 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-4Z"/>
                        </svg>
                    </button>
                    <button @click="viewMode = 'list'; localStorage.setItem('docuperfect_view_mode', 'list')"
                            :class="viewMode === 'list' ? 'bg-slate-900 text-white' : 'bg-white text-slate-500 hover:text-slate-700'"
                            class="px-2 py-1.5 transition-colors" title="List view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            @if($templates->isEmpty())
                <div class="ds-status-card p-6 text-center">
                    <div class="text-sm text-slate-500">No templates available yet.</div>
                </div>
            @else
                {{-- Grid View --}}
                <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    @foreach($templates as $tpl)
                    <div class="ds-status-card p-4 flex flex-col"
                         x-show="(typeFilter === '' || typeFilter === '{{ $tpl->document_type_id ?? 'none' }}') && (search === '' || '{{ strtolower(addslashes($tpl->name)) }}'.includes(search.toLowerCase()))" x-cloak>
                        <div class="flex items-start justify-between mb-1">
                            <div class="font-semibold text-slate-900 text-sm leading-tight">{{ $tpl->name }}</div>
                            @if($tpl->documentType)
                            <span class="ds-badge ds-badge-info text-[10px] ml-2 flex-shrink-0">{{ $tpl->documentType->name }}</span>
                            @elseif($tpl->template_type)
                            <span class="ds-badge ds-badge-info text-[10px] ml-2 flex-shrink-0">{{ $tpl->template_type }}</span>
                            @endif
                        </div>
                        <div class="text-xs text-slate-500 mb-3">
                            @if($tpl->is_global)
                                Global
                            @else
                                {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                            @endif
                        </div>
                        @if($tpl->page_count > 0)
                        <div class="flex-1 flex items-center justify-center mb-3">
                            <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                                 alt="{{ $tpl->name }}"
                                 style="max-height: 200px; width: auto;"
                                 loading="lazy"
                                 class="rounded shadow-sm mx-auto block" />
                        </div>
                        @endif
                        <div class="mt-auto pt-3 border-t border-slate-100">
                            <a href="{{ route('docuperfect.documents.create', $tpl->id) }}" class="nexus-btn-primary text-xs px-3 py-1.5">Create Document</a>
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- List View --}}
                <div x-show="viewMode === 'list'" x-cloak>
                    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full text-sm ds-table">
                            <thead>
                                <tr>
                                    <th class="text-left px-4 py-3 w-12"></th>
                                    <th class="text-left px-4 py-3">Name</th>
                                    <th class="text-left px-4 py-3">Type</th>
                                    <th class="text-left px-4 py-3">Branches</th>
                                    <th class="text-center px-4 py-3">Pages</th>
                                    <th class="text-right px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($templates as $tpl)
                                <tr x-show="(typeFilter === '' || typeFilter === '{{ $tpl->document_type_id ?? 'none' }}') && (search === '' || '{{ strtolower(addslashes($tpl->name)) }}'.includes(search.toLowerCase()))" x-cloak>
                                    <td class="px-4 py-2">
                                        @if($tpl->page_count > 0)
                                        <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                                             alt="{{ $tpl->name }}"
                                             class="w-10 h-14 object-cover rounded shadow-sm"
                                             loading="lazy" />
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-medium text-slate-900">{{ $tpl->name }}</td>
                                    <td class="px-4 py-2">
                                        @if($tpl->documentType)
                                        <span class="ds-badge ds-badge-info text-[10px]">{{ $tpl->documentType->name }}</span>
                                        @elseif($tpl->template_type)
                                        <span class="text-xs text-slate-500">{{ $tpl->template_type }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-xs text-slate-500">
                                        @if($tpl->is_global)
                                            <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                                        @else
                                            {{ $tpl->branches->pluck('name')->join(', ') ?: '—' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center text-slate-500">{{ $tpl->page_count }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <a href="{{ route('docuperfect.documents.create', $tpl->id) }}" class="nexus-btn-primary text-xs px-3 py-1.5">Create</a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ===================== PACKS TAB ===================== --}}
        <div x-show="tab === 'packs'" x-cloak>
            @if($packs->isEmpty())
                <div class="ds-status-card p-6 text-center">
                    <div class="text-sm text-slate-500">No document packs available yet.</div>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($packs as $pack)
                    <div class="ds-status-card p-4 flex flex-col">
                        <div class="font-semibold text-slate-900 text-sm leading-tight mb-1">{{ $pack->name }}</div>
                        @if($pack->description)
                        <div class="text-xs text-slate-500 mb-2">{{ $pack->description }}</div>
                        @endif
                        <div class="text-xs text-slate-400 mb-3">
                            @if($pack->usesSlots())
                                {{ $pack->slots->count() }} slot{{ $pack->slots->count() !== 1 ? 's' : '' }}
                            @else
                                {{ $pack->templates->count() }} template{{ $pack->templates->count() !== 1 ? 's' : '' }}
                            @endif
                            &middot;
                            @if($pack->is_global)
                                <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                            @else
                                {{ $pack->branches->pluck('name')->join(', ') ?: 'No branches' }}
                            @endif
                        </div>

                        @if($pack->usesSlots() && $pack->slots->isNotEmpty())
                        <div class="flex-1 mb-3">
                            <div class="text-[11px] text-slate-400 uppercase tracking-wider mb-1">Pack contents</div>
                            <ul class="text-xs text-slate-600 space-y-0.5">
                                @foreach($pack->slots as $slot)
                                <li class="flex items-center gap-1">
                                    @if($slot->slot_type === 'required')
                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500 flex-shrink-0"></span>
                                    @elseif($slot->slot_type === 'selectable')
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                                    @else
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 flex-shrink-0"></span>
                                    @endif
                                    {{ $slot->label }}
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @elseif($pack->templates->isNotEmpty())
                        <div class="flex-1 mb-3">
                            <div class="text-[11px] text-slate-400 uppercase tracking-wider mb-1">Templates included</div>
                            <ul class="text-xs text-slate-600 space-y-0.5">
                                @foreach($pack->templates as $tpl)
                                <li class="flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 flex-shrink-0"></span>
                                    {{ $tpl->name }}
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif

                        <div class="mt-auto pt-3 border-t border-slate-100">
                            <a href="{{ route('docuperfect.packs.showLaunch', $pack->id) }}" class="nexus-btn-primary text-xs px-3 py-1.5 inline-block">Launch Pack</a>
                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

</div>
@endsection
