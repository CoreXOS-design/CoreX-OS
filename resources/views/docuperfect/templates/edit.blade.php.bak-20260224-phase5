@extends('layouts.nexus')

@section('nexus-content')
<link rel="stylesheet" href="{{ asset('css/docuperfect-editor.css') }}">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Edit Template &mdash; {{ $template->name }}</h2>
            <div class="text-sm text-white/60">{{ $template->page_count }} page{{ $template->page_count !== 1 ? 's' : '' }} &middot; {{ $template->template_type }}</div>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" id="dpSaveBtn" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">Save</button>
            <a href="{{ route('docuperfect.templates.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Template metadata editor --}}
    <div class="ds-status-card p-4 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="ds-label block mb-1">Template Name</label>
                <input type="text" id="dpTemplateName" value="{{ $template->name }}" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="ds-label block mb-1">Type</label>
                <select id="dpTemplateType" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                    <option value="sales" {{ $template->template_type === 'sales' ? 'selected' : '' }}>Sales</option>
                    <option value="rentals" {{ $template->template_type === 'rentals' ? 'selected' : '' }}>Rentals</option>
                    <option value="compliance" {{ $template->template_type === 'compliance' ? 'selected' : '' }}>Compliance</option>
                </select>
            </div>
            <div>
                <label class="ds-label block mb-1">Visibility</label>
                <div class="flex items-center gap-2 mt-1">
                    <input type="checkbox" id="dpGlobal" {{ $template->is_global ? 'checked' : '' }} class="rounded border-slate-300">
                    <span class="text-sm text-slate-700">Global (all branches)</span>
                </div>
            </div>
        </div>
        <div>
            <label class="ds-label block mb-1">Branch Access</label>
            <div class="flex flex-wrap gap-2">
                @foreach($branches as $branch)
                <label class="flex items-center gap-1 text-sm text-slate-700">
                    <input type="checkbox" class="dp-branch-cb rounded border-slate-300" value="{{ $branch->id }}" {{ $template->branches->contains('id', $branch->id) ? 'checked' : '' }}>
                    {{ $branch->name }}
                </label>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Editor canvas area --}}
    <div class="ds-status-card p-4">
        <div id="docuperfect-editor"></div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
@php
    $pageImageUrls = [];
    for ($n = 0; $n < $template->page_count; $n++) {
        $pageImageUrls[] = route('docuperfect.page.image', ['id' => $template->id, 'page' => $n]);
    }
@endphp
<script>
    window.DocuperfectConfig = {
        mode: 'template',
        templateId: @json($template->id),
        pageImages: @json($pageImageUrls),
        fields: @json($template->fields_json ?? []),
        isGlobal: @json($template->is_global),
        allowedBranches: @json($template->branches->pluck('id')),
        saveUrl: @json(route('docuperfect.templates.saveFields', $template->id)),
        uploadPagesUrl: @json(route('docuperfect.templates.uploadPages', $template->id)),
        clauseApiUrl: @json(route('docuperfect.clauses.json')),
        csrfToken: @json(csrf_token()),
        templateName: @json($template->name),
        templateType: @json($template->template_type)
    };
</script>
<script src="{{ asset('js/docuperfect-editor.js') }}"></script>
@endsection
