<div x-data="{ editing: false }">
<div class="px-4 py-3 flex items-center gap-3" style="border-bottom:1px solid var(--border);">
    {{-- Source indicator --}}
    @php $src = $doc->source_type ?? 'upload'; @endphp
    <div class="w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0"
         style="background:rgba({{ $src === 'esign' ? '34,197,94' : ($src === 'pdf_splitter' ? '168,85,247' : '0,180,216') }},0.12);">
        @if($src === 'esign')
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#22c55e" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
        @elseif($src === 'pdf_splitter')
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#a855f7" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m7.848 8.25 1.536.887M7.848 8.25a3 3 0 1 1-5.196-3 3 3 0 0 1 5.196 3Zm1.536.887a2.165 2.165 0 0 1 1.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 1 1-5.196 3 3 3 0 0 1 5.196-3Zm1.536-.887a2.165 2.165 0 0 0 1.083-1.838c.005-.352.054-.695.14-1.025" /></svg>
        @else
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#0ea5e9" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" /></svg>
        @endif
    </div>

    {{-- Doc type badge + name --}}
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            @if($doc->documentType)
            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded" style="background:var(--brand-icon, #0ea5e9); color:#fff; opacity:0.85;">{{ $doc->documentType->label }}</span>
            @else
            <span class="inline-block text-[10px] font-medium px-1.5 py-0.5 rounded" style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Untagged</span>
            @endif
            <span class="text-sm font-medium truncate" style="color:var(--text-primary);">{{ $doc->original_name }}</span>
        </div>
        <div class="text-xs mt-0.5" style="color:var(--text-muted);">
            {{ $doc->human_size }} &middot; {{ $doc->uploader?->name ?? 'Unknown' }} &middot; {{ $doc->created_at->format('d M Y') }}
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-2 flex-shrink-0">
        <button type="button" @click="editing = !editing" class="text-xs px-1.5 py-1 rounded hover:bg-black/5" style="color:var(--text-muted);" title="Tag document">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
        </button>
        {{-- AT-267 — an assistant with the "download documents" toggle off may not download at all.
             Hide the affordance, incl. the direct public-disk URL the download middleware can't gate. --}}
        @if(auth()->user()?->canDownloadDocuments())
        @if($doc->disk === 'public')
        <a href="{{ $doc->url() }}" target="_blank"
           class="text-xs font-semibold no-underline px-3 py-1.5 rounded-md"
           style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
            Download
        </a>
        @else
        {{-- Local disk files need a contact context for download --}}
        @php $firstContact = $doc->contacts->first(); @endphp
        @if($firstContact)
        <a href="{{ route('corex.contacts.documents.download', [$firstContact, $doc]) }}"
           class="text-xs font-semibold no-underline px-3 py-1.5 rounded-md"
           style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-icon,#0ea5e9);">
            Download
        </a>
        @endif
        @endif
        @endif
        @if(auth()->id() === $doc->uploaded_by || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']))
        <form method="POST" action="{{ route('corex.properties.files.destroy', [$property, $doc]) }}"
              onsubmit="return confirm('Delete this file?')">
            @csrf @method('DELETE')
            <button type="submit" class="text-xs font-semibold text-red-500 hover:text-red-600 px-3 py-1.5 rounded-md hover:bg-red-500/10">Delete</button>
        </form>
        @endif
    </div>
</div>

{{-- Inline tag editor --}}
<div x-show="editing" x-cloak class="px-4 py-2" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
    <form method="POST" action="{{ route('corex.properties.files.tag', [$property, $doc]) }}" class="flex items-center gap-3">
        @csrf @method('PUT')
        <select name="document_type_id" class="text-xs rounded border px-2 py-1" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
            <option value="">No Type</option>
            @foreach($documentTypes as $dt)
            <option value="{{ $dt->id }}" {{ $doc->document_type_id == $dt->id ? 'selected' : '' }}>{{ $dt->label }}</option>
            @endforeach
        </select>
        <select name="contact_id" class="text-xs rounded border px-2 py-1" style="border-color:var(--border); background:var(--surface-1); color:var(--text-primary);">
            <option value="">No Contact</option>
            @foreach($property->contacts as $c)
            <option value="{{ $c->id }}" {{ $doc->contacts->contains('id', $c->id) ? 'selected' : '' }}>{{ $c->name ?? $c->first_name.' '.$c->last_name }}</option>
            @endforeach
        </select>
        <button type="submit" class="text-xs font-semibold px-3 py-1 rounded text-white" style="background:var(--brand-button, #0ea5e9);">Save</button>
        <button type="button" @click="editing = false" class="text-xs" style="color:var(--text-muted);">Cancel</button>
    </form>
</div>
</div>
