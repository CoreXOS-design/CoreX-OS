@extends('layouts.nexus')

@section('nexus-content')
<link rel="stylesheet" href="{{ asset('css/docuperfect-editor.css') }}">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="{{ $document->pack_instance_id ? route('docuperfect.documents.index', ['pack_instance' => $document->pack_instance_id]) : route('docuperfect.documents.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">{{ $document->name }}</h2>
        </x-slot>
        <x-slot name="right">
            <button type="button" onclick="document.getElementById('dpSaveBtn').click()" class="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700">Save</button>
            <button type="button" onclick="document.getElementById('dpDownloadBtn').click()" class="px-3 py-1.5 text-sm font-medium bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200">PDF</button>
        </x-slot>
    </x-sticky-action-bar>

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

    {{-- Flash messages handled by global toast system --}}

    {{-- Editor canvas area --}}
    <div class="ds-status-card p-4">
        <div id="docuperfect-editor"></div>
    </div>

    {{-- Document Disposition Actions --}}
    <div class="ds-status-card p-4">
        <h3 class="font-semibold text-slate-700 mb-3">What would you like to do with this document?</h3>
        <div class="flex flex-wrap gap-3">
            {{-- Download PDF (always available) --}}
            <button type="button" onclick="document.getElementById('dpDownloadBtn').click()"
                    class="flex items-center gap-2 px-4 py-2 border rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Download PDF
            </button>

            {{-- Send to Rental E-Signatures --}}
            @if($template->template_type === 'rental')
            <div x-data="{ showSendModal: false }">
                <button type="button" @click="showSendModal = true"
                        class="flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                    </svg>
                    Send to Rental E-Signatures
                </button>

                {{-- Send to Rentals Modal --}}
                <div x-show="showSendModal" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
                    <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md" @click.away="showSendModal = false">
                        <h3 class="text-lg font-bold mb-4">Send to Rental E-Signatures</h3>

                        <form method="POST" action="{{ route('docuperfect.documents.sendToRentals', $document->id) }}">
                            @csrf

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type *</label>
                                <select name="document_type" required class="w-full border rounded-lg px-3 py-2 text-sm">
                                    <option value="">-- Select type --</option>
                                    @foreach(\App\Models\Rental\RentalDocumentType::active()->orderBy('sort_order')->get() as $dt)
                                        <option value="{{ $dt->slug }}" {{ ($document->document_type ?? '') === $dt->slug ? 'selected' : '' }}>
                                            {{ $dt->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Property *</label>
                                <select name="property_id" required class="w-full border rounded-lg px-3 py-2 text-sm">
                                    <option value="">-- Select Property --</option>
                                    @foreach(\App\Models\Rental\RentalProperty::active()->orderBy('full_address')->get() as $prop)
                                        <option value="{{ $prop->id }}" {{ ($document->property_id ?? '') == $prop->id ? 'selected' : '' }}>
                                            {{ $prop->full_address }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-gray-400 mt-1">
                                    Property not listed? <a href="{{ route('rental.settings.properties.create') }}" class="text-blue-500 hover:underline" target="_blank">Add it first</a>
                                </p>
                            </div>

                            <div class="flex justify-end gap-3 mt-6">
                                <button type="button" @click="showSendModal = false"
                                        class="px-4 py-2 text-sm text-gray-600">Cancel</button>
                                <button type="submit"
                                        class="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm font-medium hover:bg-teal-700">
                                    Send to Rental E-Signatures
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endif

            {{-- Set Up Signatures (generic — for any document) --}}
            <a href="{{ route('docuperfect.signatures.setup', $document->id) }}"
               class="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Set Up Signatures
            </a>
        </div>
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
