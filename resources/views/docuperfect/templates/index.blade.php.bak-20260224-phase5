@extends('layouts.nexus')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

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
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($templates as $tpl)
            <div class="ds-status-card p-4 flex flex-col">
                <div class="flex items-start justify-between mb-1">
                    <div class="font-semibold text-slate-900 text-sm leading-tight">{{ $tpl->name }}</div>
                    @if($tpl->template_type)
                    <span class="ds-badge ds-badge-info text-[10px] ml-2 flex-shrink-0">{{ $tpl->template_type }}</span>
                    @endif
                </div>
                <div class="text-xs text-slate-500 mb-1">
                    @if($tpl->is_global)
                        <span class="ds-badge ds-badge-success text-[10px]">Global</span>
                    @else
                        {{ $tpl->branches->pluck('name')->join(', ') ?: 'No branches' }}
                    @endif
                </div>
                <div class="text-[11px] text-slate-400 mb-3">{{ $tpl->page_count }} page{{ $tpl->page_count !== 1 ? 's' : '' }} &middot; {{ $tpl->owner->name ?? '—' }} &middot; {{ $tpl->created_at->format('d M Y') }}</div>

                @if($tpl->page_count > 0)
                <div class="flex-1 flex items-center justify-center mb-3">
                    <img src="{{ route('docuperfect.page.image', ['id' => $tpl->id, 'page' => 0]) }}"
                         alt="{{ $tpl->name }}"
                         style="max-height: 200px; width: auto;"
                         loading="lazy"
                         class="rounded shadow-sm mx-auto block" />
                </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 mt-auto pt-3 border-t border-slate-100">
                    @if($showArchived)
                        <form method="POST" action="{{ route('docuperfect.templates.restore', $tpl->id) }}" class="inline">
                            @csrf
                            <button class="nexus-btn-primary text-xs px-3 py-1.5">Restore</button>
                        </form>
                    @else
                        <a href="{{ route('docuperfect.templates.edit', $tpl->id) }}" class="nexus-btn-outline text-xs px-3 py-1.5">Edit</a>
                        <form method="POST" action="{{ route('docuperfect.templates.copy', $tpl->id) }}" class="inline">
                            @csrf
                            <button class="nexus-btn-outline text-xs px-3 py-1.5">Copy</button>
                        </form>
                        <form method="POST" action="{{ route('docuperfect.templates.archive', $tpl->id) }}" class="inline" onsubmit="return confirm('Archive this template?');">
                            @csrf
                            <button class="text-xs px-2 py-1.5 text-slate-400 hover:text-amber-600">Archive</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('docuperfect.templates.destroy', $tpl->id) }}" class="inline ml-auto" onsubmit="return confirm('Permanently delete this template? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs text-red-400 hover:text-red-600">Delete</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    @endif

</div>
@endsection
