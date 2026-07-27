{{-- Drive tab body — extracted as its own partial (pre-existing bug fix,
     found while verifying Phase 4 in a real browser — NOT a Phase 4 change):
     same class of Blade-compiler defect as the other _*.blade.php partials
     split out of show.blade.php today. This @foreach($driveLinkedGroups...)
     was the one losing its opening tag next. No logic changed here. --}}

{{-- Upload area --}}
<div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
    <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Upload File</div>
    <form method="POST" action="{{ route('corex.contacts.documents.store', $contact) }}"
          enctype="multipart/form-data" class="space-y-3">
        @csrf
        <div @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false"
             @drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files"
             :style="dragging ? 'border-color:var(--brand-icon, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);' : ''"
             class="border-2 border-dashed rounded-md p-8 text-center transition-all duration-300 cursor-pointer"
             style="border-color:var(--border);"
             @click="$refs.fileInput.click()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mx-auto mb-2 opacity-30"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
            <div class="text-sm" style="color:var(--text-secondary);">Drag & drop or click to upload</div>
            <div class="text-xs mt-1" style="color:var(--text-muted);">Max 20 MB — images, PDFs, documents</div>
            <input x-ref="fileInput" type="file" name="file" class="hidden"
                   @change="$el.closest('form').querySelector('.file-name').textContent = $el.files[0]?.name ?? ''">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <select name="document_type_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                <option value="">Document Type (optional)</option>
                @foreach($documentTypes as $dt)
                <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                @endforeach
            </select>
            <select name="property_id" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                <option value="">Link to Property (optional)</option>
                @foreach($contact->properties as $prop)
                <option value="{{ $prop->id }}">{{ trim(($prop->unit_number ? 'Unit '.$prop->unit_number.', ' : '').($prop->complex_name ? $prop->complex_name.', ' : '').($prop->address ? $prop->address.', ' : '').($prop->suburb ?? ''), ', ') ?: 'Property #'.$prop->id }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center justify-between gap-3">
            <span class="file-name text-xs truncate" style="color:var(--text-muted);"></span>
            <button type="submit" class="corex-btn-primary text-sm flex-shrink-0">Upload</button>
        </div>
    </form>
</div>

{{-- Grouped file list --}}
@if($contact->documents->isNotEmpty())
    <div class="text-xs" style="color:var(--text-muted);">{{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}</div>

    @foreach($driveLinkedGroups as $propId => $docs)
    @php $prop = $drivePropertyMap->get($propId); @endphp
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        <div class="px-4 py-2.5 flex items-center gap-2" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 opacity-50"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
            <span class="text-xs font-semibold" style="color:var(--text-primary);">{{ $prop ? (trim(($prop->unit_number ? 'Unit '.$prop->unit_number.', ' : '').($prop->complex_name ? $prop->complex_name.', ' : '').($prop->address ? $prop->address.', ' : '').($prop->suburb ?? ''), ', ') ?: 'Property #'.$prop->id) : 'Unknown Property' }}</span>
        </div>
        @foreach($docs as $doc)
        @include('corex.contacts._drive-row', ['doc' => $doc, 'contact' => $contact, 'documentTypes' => $documentTypes])
        @endforeach
    </div>
    @endforeach

    @if($driveUnlinkedDocs->isNotEmpty())
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
        <div class="px-4 py-2.5" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
            <span class="text-xs font-semibold" style="color:var(--text-muted);">Not Property-Linked</span>
        </div>
        @foreach($driveUnlinkedDocs as $doc)
        @include('corex.contacts._drive-row', ['doc' => $doc, 'contact' => $contact, 'documentTypes' => $documentTypes])
        @endforeach
    </div>
    @endif
@else
<div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" /></svg>
    </div>
    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No files uploaded</h3>
    <p class="text-sm" style="color: var(--text-muted);">Drop a file in the upload area above to attach it to this contact.</p>
</div>
@endif
