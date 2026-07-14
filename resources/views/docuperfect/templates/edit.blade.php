@extends('layouts.corex')

@section('corex-content')
<link rel="stylesheet" href="{{ asset('css/docuperfect-editor.css') }}">

{{-- Full-bleed wrapper: negates <main>'s padding so sticky elements span full width --}}
<div class="-m-4 lg:-m-6">

    {{-- Page header — fixed-positioned by docuperfect-editor.js --}}
    <div id="dp-page-header">
        <x-page-header title="Template: {{ $template->name }}" :back-route="route('docuperfect.templates.index')" :flush="true" :sticky="false">
            <x-slot:actions>
                <button type="button" id="dpSaveBtn" class="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
            </x-slot:actions>
        </x-page-header>
    </div>

    {{-- Scrollable content (padding restored) --}}
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <h2 class="text-xl font-bold text-white leading-tight">Edit Template &mdash; {{ $template->name }}</h2>
            <div class="text-sm text-white/60">{{ $template->page_count }} page{{ $template->page_count !== 1 ? 's' : '' }} &middot; {{ $template->template_type }}</div>
        </div>

        {{-- Flash messages handled by global toast system --}}

        {{-- Template metadata editor --}}
        <div class="ds-status-card p-4 space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="ds-label block mb-1">Template Name</label>
                    <input type="text" id="dpTemplateName" value="{{ $template->name }}" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="ds-label block mb-1">Category</label>
                    <select id="dpCategory" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                        <option value="">Select category...</option>
                        <option value="sales" {{ ($template->category ?? '') === 'sales' ? 'selected' : '' }}>Sales</option>
                        <option value="rentals" {{ ($template->category ?? '') === 'rentals' ? 'selected' : '' }}>Rentals</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Document Type</label>
                    <select id="dpDocumentType" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                        <option value="">Select document type...</option>
                        @foreach($documentTypes as $dt)
                        <option value="{{ $dt->id }}" {{ $template->document_type_id == $dt->id ? 'selected' : '' }}>{{ $dt->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Type</label>
                    <select id="dpTemplateType" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-3 py-2 text-sm">
                        <option value="sales" {{ $template->template_type === 'sales' ? 'selected' : '' }}>Sales</option>
                        <option value="rental" {{ $template->template_type === 'rental' ? 'selected' : '' }}>Rental</option>
                        <option value="compliance" {{ $template->template_type === 'compliance' ? 'selected' : '' }}>Compliance</option>
                    </select>
                </div>
                <div>
                    <label class="ds-label block mb-1">Visibility</label>
                    <div class="flex items-center gap-2 mt-1">
                        <input type="checkbox" id="dpGlobal" {{ $template->is_global ? 'checked' : '' }} class="rounded border-slate-300">
                        <span class="text-sm text-slate-700">Global (all branches)</span>
                    </div>
                    <div class="flex items-center gap-2 mt-2">
                        <input type="checkbox" id="dpEsign" {{ $template->is_esign ? 'checked' : '' }} class="rounded border-slate-300"
                               onchange="document.getElementById('dpPartyModeGroup').style.display = this.checked ? 'block' : 'none';">
                        <span class="text-sm text-slate-700">Eligible for E-Signature</span>
                    </div>
                    <div id="dpPartyModeGroup" class="mt-2 ml-5 space-y-1" style="{{ $template->is_esign ? '' : 'display:none;' }}">
                        <label class="ds-label text-xs block mb-1">Signing Mode</label>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="radio" name="party_mode" id="dpPartyModeShared" value="shared" {{ ($template->party_mode ?? 'shared') === 'shared' ? 'checked' : '' }} class="border-slate-300">
                            Shared Document — all parties sign the same document
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="radio" name="party_mode" id="dpPartyModePerParty" value="per_party" {{ ($template->party_mode ?? 'shared') === 'per_party' ? 'checked' : '' }} class="border-slate-300">
                            Per Party — one copy per signing party (e.g. FICA)
                        </label>
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

        {{-- Editor canvas area (toolbar sticks below page header) --}}
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
        isEsign: @json($template->is_esign),
        partyMode: @json($template->party_mode ?? 'shared'),
        allowedBranches: @json($template->branches->pluck('id')),
        saveUrl: @json(route('docuperfect.templates.saveFields', $template->id)),
        uploadPagesUrl: @json(route('docuperfect.templates.uploadPages', $template->id)),
        clauseApiUrl: @json(route('docuperfect.clauses.json')),
        csrfToken: @json(csrf_token()),
        templateName: @json($template->name),
        templateType: @json($template->template_type),
        category: @json($template->category),
        documentTypeId: @json($template->document_type_id),
        namedFields: @json($namedFields),
        systemFields: @json($systemFields ?? []),
        signatureZones: @json($signatureZones ?? [])
    };
</script>
<script src="{{ asset('js/corex-connection-guard.js') }}"></script>
<script src="{{ asset('js/docuperfect-editor.js') }}"></script>
@endsection
