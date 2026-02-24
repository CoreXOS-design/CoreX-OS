@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Templates</h2>
            <div class="text-sm text-white/60">Manage document templates for your agents.</div>
        </div>
        <div class="flex items-center gap-3">
            @if($showArchived)
            <a href="{{ route('docuperfect.templates.index') }}" class="text-sm text-white/70 hover:text-white">Show Active</a>
            @else
            <a href="{{ route('docuperfect.templates.index', ['archived' => 1]) }}" class="text-sm text-white/70 hover:text-white">Show Archived</a>
            @endif
            <form method="POST" action="{{ route('docuperfect.templates.upload') }}" enctype="multipart/form-data" class="flex items-center" id="tplUploadForm">
                @csrf
                <label class="nexus-btn-primary cursor-pointer text-sm" style="background:rgba(255,255,255,0.15);">
                    + Upload Template
                    <input type="file" name="pdf" accept=".pdf" class="hidden" onchange="document.getElementById('tplUploadForm').submit();">
                </label>
            </form>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($templates->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">{{ $showArchived ? 'No archived templates.' : 'No templates yet. Upload a PDF to create one.' }}</div>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Pages</th>
                        <th class="text-left px-4 py-3">Visibility</th>
                        <th class="text-left px-4 py-3">Owner</th>
                        <th class="text-left px-4 py-3">Created</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $tpl)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $tpl->name }}</td>
                        <td class="px-4 py-3"><span class="ds-badge ds-badge-info text-[10px]">{{ $tpl->template_type }}</span></td>
                        <td class="px-4 py-3 text-slate-600">{{ $tpl->page_count }}</td>
                        <td class="px-4 py-3 text-slate-600 text-xs">
                            @if($tpl->is_global)
                                <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                            @else
                                {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $tpl->owner->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $tpl->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            @if($showArchived)
                                <form method="POST" action="{{ route('docuperfect.templates.restore', $tpl->id) }}" class="inline">
                                    @csrf
                                    <button class="ds-link text-sm">Restore</button>
                                </form>
                            @else
                                <a href="{{ route('docuperfect.templates.edit', $tpl->id) }}" class="ds-link text-sm">Edit</a>
                                <form method="POST" action="{{ route('docuperfect.templates.copy', $tpl->id) }}" class="inline">
                                    @csrf
                                    <button class="ds-link text-sm">Copy</button>
                                </form>
                                <form method="POST" action="{{ route('docuperfect.templates.archive', $tpl->id) }}" class="inline" onsubmit="return confirm('Archive this template?');">
                                    @csrf
                                    <button class="text-sm text-slate-400 hover:text-amber-600">Archive</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline" onsubmit="return confirm('Permanently delete this template? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
@endsection
