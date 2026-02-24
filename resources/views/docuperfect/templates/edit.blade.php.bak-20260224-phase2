@extends('layouts.nexus')

@section('nexus-content')
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
    <div class="ds-status-card p-6">
        <div id="docuperfect-editor" class="min-h-[400px] flex items-center justify-center">
            <div class="text-center text-slate-400">
                <div class="text-lg font-semibold mb-2">Editor loading...</div>
                <div class="text-sm">The interactive field editor will be built in Phase 2.</div>
                @if($template->page_count === 0)
                <div class="mt-4 text-sm text-amber-600">No page images yet. Upload a PDF to render pages.</div>
                @endif
            </div>
        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    window.dpTemplate = @json($template);
    window.dpPageImageBaseUrl = @json(route('docuperfect.page.image', ['id' => $template->id, 'page' => '__PAGE__']));
    window.dpSaveFieldsUrl = @json(route('docuperfect.templates.saveFields', $template->id));
    window.dpUploadPagesUrl = @json(route('docuperfect.templates.uploadPages', $template->id));
    window.dpCsrfToken = @json(csrf_token());
</script>
@endsection
