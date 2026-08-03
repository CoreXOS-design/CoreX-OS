{{-- AT-361 — "or link an existing document already on this contact" selector for one
     FICA slot (reference, no re-upload). Expects $slot (fica_form|id_copy|proof_of_address).
     Uses the parent create-wet-ink Alpine scope: selected, contactDocs, docsLoading,
     docsLoaded, links. --}}
<div class="mt-1.5" x-show="selected" x-cloak>
    <div class="text-[11px] mb-1" style="color:var(--text-muted);">…or link an existing document already on this contact (no re-upload):</div>
    <select class="block w-full text-sm rounded-md px-2 py-1.5"
            style="border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);"
            x-model.number="links.{{ $slot }}">
        <option :value="null">— none —</option>
        <template x-for="d in contactDocs" :key="'{{ $slot }}-' + d.id">
            <option :value="d.id" x-text="(d.type ? '[' + d.type + '] ' : '') + d.name + (d.date ? ' · ' + d.date : '')"></option>
        </template>
    </select>
    <input type="hidden" name="linked_{{ $slot }}_document_id" :value="links.{{ $slot }} || ''">
    <div x-show="docsLoading" class="text-[11px] mt-1" style="color:var(--text-muted);">Loading contact documents…</div>
    <div x-show="docsLoaded && contactDocs.length === 0" class="text-[11px] mt-1" style="color:var(--text-muted);">No existing documents on this contact.</div>
</div>
