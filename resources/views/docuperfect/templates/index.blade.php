@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto">

    <x-list-header
        title="Template Management"
        :form-action="route('docuperfect.templates.index')"
        :paginator="$templates"
        search-placeholder="Search templates..."
    >
        <x-slot:filters>
            <select name="status" onchange="this.form.submit()" class="list-header-filter">
                <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="archived" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
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
        </x-slot:filters>
        <x-slot:actions>
            <form method="POST" action="{{ route('docuperfect.templates.upload') }}" enctype="multipart/form-data" class="flex items-center" id="tplUploadForm">
                @csrf
                <label class="corex-btn-primary cursor-pointer text-sm">
                    + Upload Template
                    <input type="file" name="pdf" accept=".pdf" class="hidden" onchange="document.getElementById('tplUploadForm').submit();">
                </label>
            </form>
        </x-slot:actions>
    </x-list-header>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm mt-4" style="border: 1px solid var(--ds-green, #10b981); background: rgba(16,185,129,0.1); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif

    @if($templates->isEmpty())
        <div class="rounded-md p-6 text-center mt-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-sm" style="color: var(--text-muted);">
                @if(request('search') || request('document_type') || request('visibility'))
                    No templates match your search.
                @elseif($showArchived)
                    No archived templates.
                @else
                    No templates yet. Upload a PDF to create one.
                @endif
            </div>
        </div>
    @else
        <div x-data="{ viewMode: localStorage.getItem('docuperfect_tpl_view') || 'grid' }" class="mt-4">
            {{-- View toggle --}}
            <div class="flex items-center justify-end gap-2 mb-4">
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
            <div x-show="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($templates as $tpl)
                <div class="rounded-md p-4 flex flex-col transition-all duration-300 hover:shadow-lg"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     onmouseover="this.style.borderColor='var(--brand-icon)'" onmouseout="this.style.borderColor='var(--border)'">
                    <div class="flex items-start justify-between mb-1">
                        <div class="font-semibold text-sm leading-tight" style="color: var(--text-primary);">{{ $tpl->name }}</div>
                        @if($tpl->documentType)
                        <span class="ds-badge ds-badge-info text-[10px] ml-2 flex-shrink-0">{{ $tpl->documentType->name }}</span>
                        @elseif($tpl->template_type)
                        <span class="ds-badge ds-badge-info text-[10px] ml-2 flex-shrink-0">{{ $tpl->template_type }}</span>
                        @endif
                    </div>
                    <div class="text-xs mb-1 flex flex-wrap items-center gap-1" style="color: var(--text-muted);">
                        @if($tpl->is_global)
                            <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                        @else
                            {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                        @endif
                        @if($tpl->is_esign)
                            <span class="ds-badge text-[10px]" style="background: rgba(20,184,166,0.15); color: #14b8a6;">E-Sign</span>
                        @endif
                    </div>
                    <div class="text-[11px] mb-3" style="color: var(--text-muted);">{{ $tpl->page_count }} page{{ $tpl->page_count !== 1 ? 's' : '' }} &middot; {{ $tpl->owner->name ?? '—' }} &middot; {{ $tpl->created_at?->format('d M Y') ?? '—' }}</div>

                    @if($tpl->render_type === 'web')
                    <div class="flex-1 flex flex-col items-center justify-center mb-3 rounded-md py-6" style="background: var(--surface-raised, #1e293b); border: 1px dashed var(--border);">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00d4aa">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        <span class="inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full" style="background: #00d4aa; color: #0f172a;">Web Template</span>
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
                                <button class="text-xs px-2 py-1.5 transition-all duration-300 hover:opacity-80" style="color: var(--text-muted);">Archive</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline ml-auto" onsubmit="return confirm('Permanently delete this template? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button class="text-xs transition-all duration-300 hover:opacity-80" style="color: #ef4444;">Delete</button>
                        </form>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- List View --}}
            <div x-show="viewMode === 'list'" x-cloak>
                <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
                    <table class="w-full text-sm ds-table">
                        <thead>
                            <tr>
                                <th class="text-left px-4 py-3 w-12"></th>
                                <x-sort-header field="name" label="Name" />
                                <th class="text-left px-4 py-3">Type</th>
                                <th class="text-left px-4 py-3">Branches</th>
                                <th class="text-left px-4 py-3">Owner</th>
                                <x-sort-header field="page_count" label="Pages" align="center" />
                                <x-sort-header field="created_at" label="Created" align="right" />
                                <th class="text-right px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($templates as $tpl)
                            <tr class="transition-all duration-300">
                                <td class="px-4 py-2">
                                    @if($tpl->render_type === 'web')
                                    <div class="w-10 h-14 flex items-center justify-center rounded-md" style="background: var(--surface-raised, #1e293b); border: 1px dashed var(--border);">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00d4aa">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                        </svg>
                                    </div>
                                    @elseif($tpl->page_count > 0)
                                    <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                                         alt="{{ $tpl->name }}"
                                         class="w-10 h-14 object-cover rounded-md shadow-sm"
                                         loading="lazy" />
                                    @endif
                                </td>
                                <td class="px-4 py-2 font-medium" style="color: var(--text-primary);">
                                    {{ $tpl->name }}
                                    @if($tpl->is_esign)
                                        <span class="ds-badge text-[10px] ml-1" style="background: rgba(20,184,166,0.15); color: #14b8a6;">E-Sign</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @if($tpl->documentType)
                                    <span class="ds-badge ds-badge-info text-[10px]">{{ $tpl->documentType->name }}</span>
                                    @elseif($tpl->template_type)
                                    <span class="text-xs" style="color: var(--text-muted);">{{ $tpl->template_type }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">
                                    @if($tpl->is_global)
                                        <span class="ds-badge ds-badge-success text-[10px]">Global</span>
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
                                            <button class="text-xs px-1 transition-all duration-300 hover:opacity-80" style="color: #ef4444;">Del</button>
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

        <div class="mt-4">
            {{ $templates->links() }}
        </div>
    @endif

</div>
@endsection
