@extends('layouts.nexus')

@section('nexus-content')
<link rel="stylesheet" href="{{ asset('css/docuperfect-editor.css') }}">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between"
         x-data="{ editingName: false, docName: @js($document->name), saving: false }">
        <div class="flex-1 min-w-0 mr-4">
            <div x-show="!editingName" class="flex items-center gap-2">
                <h2 class="text-xl font-bold text-white leading-tight truncate">Document &mdash; <span x-text="docName">{{ $document->name }}</span></h2>
                <button @click="editingName = true" class="text-white/40 hover:text-white/80 flex-shrink-0" title="Rename document">
                    <i class="fas fa-pencil-alt text-xs"></i>
                </button>
            </div>
            <div x-show="editingName" x-cloak class="flex items-center gap-2">
                <input type="text" x-model="docName"
                       x-ref="nameInput"
                       x-init="$watch('editingName', v => { if(v) $nextTick(() => $refs.nameInput.select()) })"
                       @keydown.enter="saving = true; fetch(@js(route('docuperfect.documents.rename', $document->id)), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ name: docName }) }).then(() => { saving = false; editingName = false; }).catch(() => { saving = false; })"
                       @keydown.escape="editingName = false"
                       class="text-lg font-bold bg-white/10 text-white border border-white/30 rounded px-2 py-0.5 w-full max-w-md focus:ring-1 focus:ring-white/50"
                       maxlength="255">
                <button @click="saving = true; fetch(@js(route('docuperfect.documents.rename', $document->id)), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) }, body: JSON.stringify({ name: docName }) }).then(() => { saving = false; editingName = false; }).catch(() => { saving = false; })"
                        class="text-white/70 hover:text-white text-xs font-medium flex-shrink-0" :disabled="saving">
                    <span x-show="!saving">Save</span><span x-show="saving" x-cloak>...</span>
                </button>
                <button @click="editingName = false; docName = @js($document->name)" class="text-white/40 hover:text-white/70 text-xs flex-shrink-0">Cancel</button>
            </div>
            <div class="text-sm text-white/60">Template: {{ $template->name }} &middot; {{ $template->page_count }} page{{ $template->page_count !== 1 ? 's' : '' }}</div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <button type="button" id="dpSaveBtn" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">Save</button>
            <button type="button" id="dpDownloadBtn" class="nexus-btn-primary text-sm" style="background:rgba(255,255,255,0.15);">Download PDF</button>
            <a href="{{ $document->pack_instance_id ? route('docuperfect.documents.index', ['pack_instance' => $document->pack_instance_id]) : route('docuperfect.documents.index') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

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
        mode: 'document',
        templateId: @json($template->id),
        documentId: @json($document->id),
        pageImages: @json($pageImageUrls),
        fields: @json($document->fields_json ?? []),
        saveUrl: @json(route('docuperfect.documents.saveFields', $document->id)),
        clauseApiUrl: @json(route('docuperfect.clauses.json')),
        csrfToken: @json(csrf_token()),
        templateName: @json($template->name),
        documentName: @json($document->name),
        namedFields: @json($namedFields),
        packInstanceId: @json($document->pack_instance_id),
        packInstanceValuesUrl: @json($document->pack_instance_id ? route('docuperfect.api.packInstanceValues', ['instanceId' => $document->pack_instance_id]) : null),
        packInstanceSaveUrl: @json(route('docuperfect.api.packInstanceValuesSave'))
    };
</script>
<script src="{{ asset('js/docuperfect-editor.js') }}"></script>
@endsection
