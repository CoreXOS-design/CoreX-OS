{{-- .ai/specs/rental-portal-access.md §22 / rentals-faults-work-orders.md §4.3 — "Before you report": what the person sees as soon as they
     pick a fault type, ahead of the form. $w is the Alpine wizard object; $w.ftype is the chosen fault type as the fault-types endpoint
     returned it (steps worked out for THIS property, urgency, the property's valve / board photos, the agency's documents and video links). --}}
<div data-fault-aid>
    <div class="aid-urgent" x-show="{{ $w }}.ftype && {{ $w }}.ftype.urgency === 'emergency'" data-fault-aid-emergency>
        This is an emergency. Follow the steps first, then call your agent now.
    </div>
    @if(!empty($tenant))
        {{-- AT-452 item 8 - the tenant screen: the safety line in its own warning box, "Try this first" as a heading, the steps as a list. The wording is the agency's, unchanged. --}}
        <div class="aid-safety" x-show="{{ $w }}.ftype && {{ $w }}.ftype.safety_line" data-fault-aid-safety role="note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <p x-text="{{ $w }}.ftype ? {{ $w }}.ftype.safety_line : ''"></p>
        </div>
        <h2 class="aid-h">Try this first</h2>
        {{-- section 22.3 - the tenant ticks what they have done: the ticks travel with the report so the agent sees "what the tenant says they checked" --}}
        <p class="muted" style="font-size:13px;margin:0 0 6px;" x-show="{{ $w }}.ftype && {{ $w }}.ftype.steps && {{ $w }}.ftype.steps.length" data-fault-aid-tick-help>Tick each step you have done - it helps your agent.</p>
        <ol class="aid-list" x-show="{{ $w }}.ftype && {{ $w }}.ftype.steps && {{ $w }}.ftype.steps.length" data-fault-aid-steps>
            <template x-for="(st, i) in ({{ $w }}.ftype && {{ $w }}.ftype.steps ? {{ $w }}.ftype.steps : [])" :key="i"><li><label style="display:flex;gap:8px;align-items:flex-start;margin:0;font-weight:inherit;"><input type="checkbox" style="width:auto;margin-top:3px;flex:none;" x-model="{{ $w }}.checks[i]" data-fault-aid-tick><span x-text="st"></span></label></li></template>
        </ol>
    @else
        <p class="aid-steps" x-text="{{ $w }}.ftype ? {{ $w }}.ftype.first_aid_steps : ''"></p>
    @endif

    <div class="photo-grid" x-show="{{ $w }}.ftype && {{ $w }}.ftype.photos.length">
        <template x-for="ph in ({{ $w }}.ftype ? {{ $w }}.ftype.photos : [])" :key="ph.url">
            <a :href="ph.url" target="_blank" rel="noopener" class="aid-photo"><img :src="ph.thumb_url || ph.url" :alt="ph.label" loading="lazy" decoding="async"><span class="muted" x-text="ph.label"></span></a>
        </template>
    </div>

    <div x-show="{{ $w }}.ftype && {{ $w }}.ftype.documents.length" data-fault-aid-docs>
        <template x-for="d in ({{ $w }}.ftype ? {{ $w }}.ftype.documents : [])" :key="d.url">
            <div class="aid-doc">
                <template x-if="d.type === 'image'">
                    <a :href="d.url" target="_blank" rel="noopener"><img :src="d.thumb_url || d.url" :alt="d.caption || 'Photo'" loading="lazy" decoding="async" class="aid-doc-img"></a>
                </template>
                <template x-if="d.type !== 'image'">
                    <a class="link" :href="d.url" target="_blank" rel="noopener" @click="openFile($event, d.url, false)" x-text="(d.type === 'video_link' ? 'Watch: ' : 'Open: ') + (d.caption || (d.type === 'video_link' ? 'video' : 'document'))"></a>
                </template>
                <span class="muted" x-show="d.type === 'image' && d.caption" x-text="d.caption"></span>
            </div>
        </template>
    </div>
</div>
