{{--
    Rental inspection section body — shared by In Inspection, Out Inspection and
    each custom section. Rendered inside an Alpine `rentalImages()` component.
    Spec: .ai/specs/rental-images.md

    Required include vars (all are JS expressions evaluated by Alpine):
      $section  — section param literal, e.g. "'in_inspection'" or "'custom'"
      $cid      — custom-id expression, e.g. "null" or "sec.id"
      $key      — unique key expression, e.g. "'in_inspection'" or "sec.id"
      $images   — images array expression, e.g. "data.in_inspection.images" or "sec.images"
      $date     — date expression, e.g. "data.in_inspection.date" or "sec.date"
      $showRename — bool (custom sections only)
--}}

{{-- Date (+ optional rename) --}}
<div class="flex flex-wrap items-end gap-4">
    <div>
        <label class="prop-label">Inspection date</label>
        <input type="date" class="prop-input" style="max-width:14rem;"
               :value="{{ $date }}"
               @change="setDate({{ $section }}, {{ $cid }}, $event)">
    </div>
    @if(!empty($showRename))
    <button type="button" class="text-xs font-semibold px-3 py-2 rounded-md"
            style="color:var(--brand-icon);background:var(--surface-2);"
            @click="openRename(sec.id)">Rename</button>
    @endif
</div>

{{-- Select / download toolbar (only when the section has photos) --}}
<div class="flex flex-wrap items-center gap-2" x-show="{{ $images }}.length">
    <button type="button" class="text-xs font-semibold px-3 py-1.5 rounded-md"
            style="color:var(--text-secondary);background:var(--surface-2);"
            @click="toggleSelect({{ $key }})"
            x-text="selecting[{{ $key }}] ? 'Cancel' : 'Select'"></button>
    <button type="button" x-show="selecting[{{ $key }}]"
            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white"
            style="background:var(--brand-button,#0ea5e9);"
            :disabled="!(sel[{{ $key }}] && sel[{{ $key }}].length)"
            @click="downloadSelected({{ $section }}, {{ $cid }})"
            x-text="'Download selected' + ((sel[{{ $key }}] && sel[{{ $key }}].length) ? ' (' + sel[{{ $key }}].length + ')' : '')"></button>
    {{-- Select all / Clear / Delete selected — mirror of the Gallery bulk controls. --}}
    <button type="button" x-show="selecting[{{ $key }}]"
            class="text-xs font-semibold px-3 py-1.5 rounded-md"
            style="color:var(--brand-icon);background:var(--surface-2);border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent);"
            @click="selectAllInSection({{ $key }}, {{ $section }}, {{ $cid }})">Select all</button>
    <button type="button" x-show="selecting[{{ $key }}] && sel[{{ $key }}] && sel[{{ $key }}].length"
            class="text-xs font-semibold px-3 py-1.5 rounded-md"
            style="color:var(--text-muted);background:var(--surface-2);border:1px solid var(--border);"
            @click="clearSection({{ $key }})">Clear</button>
    <span x-show="selecting[{{ $key }}]" class="text-xs font-semibold" style="color:var(--text-secondary);"
          x-text="(sel[{{ $key }}] && sel[{{ $key }}].length ? sel[{{ $key }}].length : 0) + ' selected'"></span>
    {{-- AT-267 — assistants may never delete listing images (any listing). --}}
    @unless(auth()->user()?->is_assistant)
    <button type="button" x-show="selecting[{{ $key }}]"
            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white inline-flex items-center gap-1"
            :disabled="!(sel[{{ $key }}] && sel[{{ $key }}].length)"
            :style="(sel[{{ $key }}] && sel[{{ $key }}].length) ? 'background:var(--ds-crimson);' : 'background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);opacity:.55;cursor:not-allowed;'"
            @click="deleteSelected({{ $section }}, {{ $cid }}, {{ $key }})">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
        <span x-text="'Delete selected' + ((sel[{{ $key }}] && sel[{{ $key }}].length) ? ' (' + sel[{{ $key }}].length + ')' : '')"></span>
    </button>
    @endunless
    <button type="button" x-show="!selecting[{{ $key }}]"
            class="text-xs font-semibold px-3 py-1.5 rounded-md inline-flex items-center gap-1.5"
            style="color:var(--brand-icon);background:var(--surface-2);"
            @click="downloadAll({{ $section }}, {{ $cid }})">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        Download all
    </button>
</div>

{{-- Empty state --}}
<p x-show="!{{ $images }}.length" class="text-xs" style="color:var(--text-muted);">No photos yet.</p>

{{-- Thumbnail grid --}}
<div x-show="{{ $images }}.length" class="grid grid-cols-3 sm:grid-cols-5 gap-3">
    <template x-for="(url, i) in {{ $images }}" :key="url + i">
        <div class="relative group rounded-md overflow-hidden" style="aspect-ratio:1/1;background:var(--surface-3);"
             :style="(selecting[{{ $key }}] && isPicked({{ $key }}, i)) ? 'outline:2px solid var(--brand-button,#0ea5e9);outline-offset:-2px;' : ''">
            <img :src="url" class="w-full h-full object-cover cursor-pointer" loading="lazy"
                 @click="selecting[{{ $key }}] ? togglePick({{ $key }}, i) : openViewer({{ $section }}, {{ $cid }}, i)">

            {{-- Select checkbox --}}
            <button type="button" x-show="selecting[{{ $key }}]" @click.stop="togglePick({{ $key }}, i)"
                    class="absolute top-1 left-1 w-5 h-5 rounded flex items-center justify-center"
                    :style="isPicked({{ $key }}, i) ? 'background:var(--brand-button,#0ea5e9);' : 'background:rgba(0,0,0,0.5);'">
                <svg x-show="isPicked({{ $key }}, i)" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
            </button>

            {{-- Hover actions: download + delete --}}
            <div x-show="!selecting[{{ $key }}]" class="absolute top-1 right-1 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button type="button" title="Download" @click.stop="downloadOne(url)"
                        class="w-6 h-6 rounded-full flex items-center justify-center" style="background:rgba(0,0,0,0.6);color:#fff;">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                </button>
                {{-- AT-267 — assistants may never delete listing images (any listing). --}}
                @unless(auth()->user()?->is_assistant)
                <button type="button" title="Delete" @click.stop="deleteImage({{ $section }}, {{ $cid }}, i)"
                        class="w-6 h-6 rounded-full flex items-center justify-center" style="background:rgba(0,0,0,0.6);color:#fff;">&times;</button>
                @endunless
            </div>
        </div>
    </template>
</div>

{{-- Upload control --}}
<label class="flex items-center gap-3 px-4 py-3 rounded-md border border-dashed cursor-pointer text-sm transition-colors"
       style="border-color:var(--border-hover); color:var(--text-secondary);">
    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
    <span x-text="uploadingKey === ({{ $key }}) ? 'Uploading…' : 'Add photos (multiple allowed)'"></span>
    <input type="file" multiple accept="image/*" class="hidden" :disabled="busy"
           @change="uploadTo({{ $section }}, {{ $cid }}, $event)">
</label>

{{-- Upload progress bar --}}
<div x-show="uploadingKey === ({{ $key }})" x-cloak>
    <div class="flex items-center justify-between mb-1 text-xs" style="color:var(--text-secondary);">
        <span>Uploading…</span><span x-text="percent + '%'"></span>
    </div>
    <div class="w-full h-2 rounded-full overflow-hidden" style="background:var(--surface-3);">
        <div class="h-full transition-all duration-200" :style="'width:' + percent + '%; background:var(--brand-button,#0ea5e9);'"></div>
    </div>
</div>
