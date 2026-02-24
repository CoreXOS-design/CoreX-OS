@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Documents</h2>
            <div class="text-sm text-white/60">Create, fill, and manage your documents.</div>
        </div>
        @if($user->isAdmin() || $user->isBranchManager())
        <form method="POST" action="{{ route('docuperfect.templates.upload') }}" enctype="multipart/form-data" class="flex items-center gap-2" id="uploadForm">
            @csrf
            <label class="nexus-btn-primary cursor-pointer text-sm" style="background:rgba(255,255,255,0.15);">
                + Upload Template
                <input type="file" name="pdf" accept=".pdf" class="hidden" onchange="document.getElementById('uploadForm').submit();">
            </label>
        </form>
        @endif
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- My Documents --}}
    <div>
        <h3 class="ds-section-header mb-3">My Documents</h3>

        @if($documents->isEmpty())
            <div class="ds-status-card p-6 text-center">
                <div class="text-sm text-slate-500">No documents yet — create one from a template below.</div>
            </div>
        @else
            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <table class="w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-3">Name</th>
                            <th class="text-left px-4 py-3">Template</th>
                            <th class="text-left px-4 py-3">Last Edited</th>
                            @if($user->isAdmin() || $user->isBranchManager())
                            <th class="text-left px-4 py-3">Agent</th>
                            @endif
                            <th class="text-right px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $doc->name }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $doc->template->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $doc->updated_at->format('d M Y H:i') }}</td>
                            @if($user->isAdmin() || $user->isBranchManager())
                            <td class="px-4 py-3 text-slate-600">{{ $doc->owner->name ?? '—' }}</td>
                            @endif
                            <td class="px-4 py-3 text-right space-x-2">
                                <a href="{{ route('docuperfect.documents.edit', $doc->id) }}" class="ds-link text-sm">Edit</a>
                                <form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document?');">
                                    @csrf
                                    <button class="text-sm text-slate-400 hover:text-red-600">Archive</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Available Templates --}}
    <div>
        <h3 class="ds-section-header mb-3">Available Templates</h3>

        @if($templates->isEmpty())
            <div class="ds-status-card p-6 text-center">
                <div class="text-sm text-slate-500">No templates available yet.</div>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($templates as $tpl)
                <div class="ds-status-card p-4 flex flex-col">
                    <div class="flex items-start justify-between mb-1">
                        <div class="font-semibold text-slate-900 text-sm leading-tight">{{ $tpl->name }}</div>
                        @if($tpl->template_type)
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
                    <div class="flex items-center gap-2 mt-auto pt-3 border-t border-slate-100">
                        <a href="{{ route('docuperfect.documents.create', $tpl->id) }}" class="nexus-btn-primary text-xs px-3 py-1.5">Create Document</a>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <a href="{{ route('docuperfect.templates.edit', $tpl->id) }}" class="nexus-btn-outline text-xs px-3 py-1.5">Edit</a>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection
