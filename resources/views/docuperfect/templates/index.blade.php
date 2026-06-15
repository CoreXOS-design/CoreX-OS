{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $hasActiveFilters = request('search')
        || (request('status', 'active') !== 'active')
        || request('category')
        || request('type')
        || request('document_type')
        || request('visibility');
@endphp
<div class="w-full space-y-5" x-data="{ viewMode: localStorage.getItem('docuperfect_tpl_view') || 'grid' }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Template Management</h1>
                <p class="text-sm text-white/60">Upload, organise, and configure your document templates.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('docuperfect.templates.upload') }}" enctype="multipart/form-data" class="flex items-center" id="tplUploadForm">
                    @csrf
                    <label class="corex-btn-primary cursor-pointer text-sm inline-flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Upload Template
                        <input type="file" name="pdf" accept=".pdf" class="hidden" onchange="document.getElementById('tplUploadForm').submit();">
                    </label>
                </form>
            </div>
        </div>
    </div>

    {{-- Flash --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    {{-- Filter bar --}}
    <div class="rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        <form method="GET" action="{{ route('docuperfect.templates.index') }}" class="flex flex-wrap items-center gap-3">
            @if(request('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="direction" value="{{ request('direction', 'asc') }}">
            @endif

            {{-- Search --}}
            <div class="relative flex-1 min-w-[180px] max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 pointer-events-none" style="color: var(--text-muted);" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search templates..."
                       onchange="this.form.submit()"
                       class="list-header-filter w-full"
                       style="padding-left: 2.25rem;">
            </div>

            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
            </select>
            <select name="category" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All categories</option>
                <option value="sales" {{ request('category') === 'sales' ? 'selected' : '' }}>Sales</option>
                <option value="rentals" {{ request('category') === 'rentals' ? 'selected' : '' }}>Rentals</option>
                <option value="none" {{ request('category') === 'none' ? 'selected' : '' }}>Uncategorized</option>
            </select>
            <select name="type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All types</option>
                <option value="sales" {{ request('type') === 'sales' ? 'selected' : '' }}>Sales</option>
                <option value="rental" {{ request('type') === 'rental' ? 'selected' : '' }}>Rental</option>
                <option value="compliance" {{ request('type') === 'compliance' ? 'selected' : '' }}>Compliance</option>
            </select>
            <select name="document_type" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All doc types</option>
                @foreach($documentTypes as $dt)
                <option value="{{ $dt->id }}" {{ request('document_type') == $dt->id ? 'selected' : '' }}>{{ $dt->name }}</option>
                @endforeach
                <option value="none" {{ request('document_type') === 'none' ? 'selected' : '' }}>Uncategorized</option>
            </select>
            <select name="visibility" onchange="this.form.submit()" class="list-header-filter">
                <option value="">All visibility</option>
                <option value="global" {{ request('visibility') === 'global' ? 'selected' : '' }}>Global</option>
                <option value="branch" {{ request('visibility') === 'branch' ? 'selected' : '' }}>Branch-specific</option>
            </select>

            <button type="submit" class="corex-btn-outline text-xs px-3 py-2">Search</button>
            @if($hasActiveFilters)
            <a href="{{ route('docuperfect.templates.index') }}" class="text-xs underline transition-all duration-300" style="color: var(--text-muted);">Clear</a>
            @endif

            <span class="ml-auto text-xs flex-shrink-0" style="color: var(--text-muted);">
                {{ number_format($templates->total()) }} {{ \Illuminate\Support\Str::plural('template', $templates->total()) }}
            </span>
        </form>
    </div>

    @if($templates->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z"/>
                </svg>
            </div>
            @if(request('search') || request('document_type') || request('visibility') || request('category'))
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matching templates</h3>
                <p class="text-sm" style="color: var(--text-muted);">Try adjusting your filters or search terms.</p>
            @elseif($showArchived)
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No archived templates</h3>
                <p class="text-sm" style="color: var(--text-muted);">Archived templates will appear here.</p>
            @else
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No templates yet</h3>
                <p class="text-sm mb-4" style="color: var(--text-muted);">Upload a PDF to create your first template.</p>
                <form method="POST" action="{{ route('docuperfect.templates.upload') }}" enctype="multipart/form-data" class="inline-flex" id="tplUploadFormEmpty">
                    @csrf
                    <label class="corex-btn-primary cursor-pointer text-sm">
                        + Upload Template
                        <input type="file" name="pdf" accept=".pdf" class="hidden" onchange="document.getElementById('tplUploadFormEmpty').submit();">
                    </label>
                </form>
            @endif
        </div>
    @else
        <div>
            {{-- View toggle --}}
            <div class="flex items-center justify-end gap-2">
                <div class="flex items-center rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                    <button @click="viewMode = 'grid'; localStorage.setItem('docuperfect_tpl_view', 'grid')"
                            :style="viewMode === 'grid' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);'"
                            class="px-2.5 py-1.5 transition-all duration-300" title="Grid view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M4 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5ZM14 5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1V5ZM4 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-4ZM14 15a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-4Z"/>
                        </svg>
                    </button>
                    <button @click="viewMode = 'list'; localStorage.setItem('docuperfect_tpl_view', 'list')"
                            :style="viewMode === 'list' ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);'"
                            class="px-2.5 py-1.5 transition-all duration-300" title="List view">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Grid View --}}
            <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mt-4">
                @foreach($templates as $tpl)
                <div class="corex-template-card rounded-md p-4 flex flex-col transition-all duration-300"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-start justify-between mb-1">
                        <div class="font-semibold text-sm leading-tight" style="color: var(--text-primary);">{{ $tpl->name }}</div>
                        <div class="flex items-center gap-1 ml-2 flex-shrink-0">
                            @if($tpl->category === 'sales')
                            <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">Sales</span>
                            @elseif($tpl->category === 'rentals')
                            <span class="ds-badge ds-badge-info">Rentals</span>
                            @endif
                            @if($tpl->documentType)
                            <span class="ds-badge ds-badge-default">{{ $tpl->documentType->name }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="text-xs mb-1 flex flex-wrap items-center gap-1" style="color: var(--text-muted);">
                        @if($tpl->is_global)
                            <span class="ds-badge ds-badge-success">Global</span>
                        @else
                            {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                        @endif
                        @if($tpl->is_esign)
                            <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent); color: var(--brand-icon);">E-Sign</span>
                        @endif
                    </div>
                    <div class="text-[0.6875rem] mb-3" style="color: var(--text-muted);">{{ $tpl->page_count }} page{{ $tpl->page_count !== 1 ? 's' : '' }} &middot; {{ $tpl->owner->name ?? '—' }} &middot; {{ $tpl->created_at?->format('d M Y') ?? '—' }}</div>

                    @if($tpl->render_type === 'web')
                    <div class="flex-1 flex flex-col items-center justify-center mb-3 rounded-md py-6" style="background: var(--surface-2); border: 1px dashed var(--border);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" style="stroke: var(--brand-icon);">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent); color: var(--brand-icon);">Web Template</span>
                    </div>
                    @elseif($tpl->page_count > 0)
                    <div class="flex-1 flex items-center justify-center mb-3">
                        <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                             alt="{{ $tpl->name }}"
                             style="max-height: 200px; width: auto;"
                             loading="lazy"
                             class="rounded-md shadow-sm mx-auto block" />
                    </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2 mt-auto pt-3" style="border-top: 1px solid var(--border);">
                        @if($showArchived)
                            <form method="POST" action="{{ route('docuperfect.templates.restore', $tpl->id) }}" class="inline">
                                @csrf
                                <button class="corex-btn-primary text-xs px-3 py-1.5">Restore</button>
                            </form>
                        @else
                            <a href="{{ route('docuperfect.templates.edit', $tpl->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Edit</a>
                            @if($tpl->is_esign)
                            <a href="{{ route('docuperfect.templates.wizardConfig', $tpl->id) }}" class="corex-btn-outline text-xs px-3 py-1.5">Wizard Setup</a>
                            @endif
                            <form method="POST" action="{{ route('docuperfect.templates.copy', $tpl->id) }}" class="inline">
                                @csrf
                                <button class="corex-btn-outline text-xs px-3 py-1.5">Copy</button>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.templates.archive', $tpl->id) }}" class="inline" onsubmit="return confirm('Archive this template?');">
                                @csrf
                                <button class="corex-btn-outline text-xs px-3 py-1.5">Archive</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline ml-auto" onsubmit="return confirm('Permanently delete this template? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button class="text-xs font-semibold transition-all duration-300 hover:opacity-80" style="color: var(--ds-crimson);">Delete</button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- List View --}}
            <div x-show="viewMode === 'list'" x-cloak class="mt-4">
                <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
                    <table class="w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 w-12"></th>
                                <x-sort-header field="name" label="Name" />
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branches</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Owner</th>
                                <x-sort-header field="page_count" label="Pages" align="center" />
                                <x-sort-header field="created_at" label="Created" align="right" />
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($templates as $tpl)
                            <tr style="border-top: 1px solid var(--border);">
                                <td class="px-4 py-2">
                                    @if($tpl->render_type === 'web')
                                    <div class="w-10 h-14 flex items-center justify-center rounded-md" style="background: var(--surface-2); border: 1px dashed var(--border);">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" style="stroke: var(--brand-icon);">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </div>
                                    @elseif($tpl->page_count > 0)
                                    <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                                         alt="{{ $tpl->name }}"
                                         class="w-10 h-14 object-cover rounded-md"
                                         loading="lazy" />
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-medium" style="color: var(--text-primary);">
                                    {{ $tpl->name }}
                                    @if($tpl->is_esign)
                                        <span class="ds-badge ml-1" style="background: color-mix(in srgb, var(--brand-icon) 15%, transparent); color: var(--brand-icon);">E-Sign</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-1 flex-wrap">
                                        @if($tpl->category === 'sales')
                                        <span class="ds-badge" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">Sales</span>
                                        @elseif($tpl->category === 'rentals')
                                        <span class="ds-badge ds-badge-info">Rentals</span>
                                        @endif
                                        @if($tpl->documentType)
                                        <span class="ds-badge ds-badge-default">{{ $tpl->documentType->name }}</span>
                                        @elseif($tpl->template_type)
                                        <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->template_type }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">
                                    @if($tpl->is_global)
                                        <span class="ds-badge ds-badge-success">Global</span>
                                    @else
                                        {{ $tpl->branches->pluck('name')->join(', ') ?: '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">{{ $tpl->owner->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-center" style="color: var(--text-secondary);">{{ $tpl->page_count }}</td>
                                <td class="px-4 py-2 text-right text-xs" style="color: var(--text-secondary);">{{ $tpl->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($showArchived)
                                            <form method="POST" action="{{ route('docuperfect.templates.restore', $tpl->id) }}" class="inline">
                                                @csrf
                                                <button class="corex-btn-primary text-xs px-2 py-1">Restore</button>
                                            </form>
                                        @else
                                            <a href="{{ route('docuperfect.templates.edit', $tpl->id) }}" class="corex-btn-outline text-xs px-2 py-1">Edit</a>
                                            <form method="POST" action="{{ route('docuperfect.templates.copy', $tpl->id) }}" class="inline">
                                                @csrf
                                                <button class="corex-btn-outline text-xs px-2 py-1">Copy</button>
                                            </form>
                                        @endif
                                        <form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline" onsubmit="return confirm('Permanently delete?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs font-semibold px-2 py-1 transition-colors hover:opacity-80" style="color: var(--ds-crimson);">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div>
            {{ $templates->links() }}
        </div>
    @endif

</div>
@endsection
