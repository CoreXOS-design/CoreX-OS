@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Document &mdash; {{ $document->name }}</h2>
            <div class="text-sm text-white/60">Template: {{ $template->name }} &middot; {{ $template->page_count }} page{{ $template->page_count !== 1 ? 's' : '' }}</div>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" id="dpSaveBtn" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">Save</button>
            <button type="button" id="dpDownloadBtn" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">Download PDF</button>
            <a href="{{ route('docuperfect.documents.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Editor canvas area --}}
    <div class="ds-status-card p-6">
        <div id="docuperfect-editor" class="min-h-[400px] flex items-center justify-center">
            <div class="text-center text-slate-400">
                <div class="text-lg font-semibold mb-2">Document editor loading...</div>
                <div class="text-sm">The interactive field editor will be built in Phase 2.</div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    window.dpDocument = @json($document);
    window.dpTemplate = @json($template);
    window.dpPageImageBaseUrl = @json(route('docuperfect.page.image', ['id' => $template->id, 'page' => '__PAGE__']));
    window.dpSaveFieldsUrl = @json(route('docuperfect.documents.saveFields', $document->id));
    window.dpClausesUrl = @json(route('docuperfect.clauses.json'));
    window.dpCsrfToken = @json(csrf_token());
</script>
@endsection
