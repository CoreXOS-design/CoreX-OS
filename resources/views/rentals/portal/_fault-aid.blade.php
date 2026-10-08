{{-- .ai/specs/rental-portal-access.md §21 / rentals-faults-work-orders.md §4.3 — "Before you report": what the person sees as soon as they
     pick a fault type, ahead of the form. $w is the Alpine wizard object; $w.ftype is the chosen fault type as the fault-types endpoint
     returned it (steps worked out for THIS property, urgency, the property's valve / board photos, the agency's documents and video links). --}}
<div data-fault-aid>
    <div class="aid-urgent" x-show="{{ $w }}.ftype && {{ $w }}.ftype.urgency === 'emergency'" data-fault-aid-emergency>
        This is an emergency. Follow the steps first, then call your agent now.
    </div>
    <p class="aid-steps" x-text="{{ $w }}.ftype ? {{ $w }}.ftype.first_aid_steps : ''"></p>

    <div class="photo-grid" x-show="{{ $w }}.ftype && {{ $w }}.ftype.photos.length">
        <template x-for="ph in ({{ $w }}.ftype ? {{ $w }}.ftype.photos : [])" :key="ph.url">
            <a :href="ph.url" target="_blank" rel="noopener" class="aid-photo"><img :src="ph.url" :alt="ph.label" loading="lazy"><span class="muted" x-text="ph.label"></span></a>
        </template>
    </div>

    <div x-show="{{ $w }}.ftype && {{ $w }}.ftype.documents.length" data-fault-aid-docs>
        <template x-for="d in ({{ $w }}.ftype ? {{ $w }}.ftype.documents : [])" :key="d.url">
            <div class="aid-doc">
                <template x-if="d.type === 'image'">
                    <a :href="d.url" target="_blank" rel="noopener"><img :src="d.url" :alt="d.caption || 'Photo'" loading="lazy" class="aid-doc-img"></a>
                </template>
                <template x-if="d.type !== 'image'">
                    <a class="link" :href="d.url" target="_blank" rel="noopener" x-text="(d.type === 'video_link' ? 'Watch: ' : 'Open: ') + (d.caption || (d.type === 'video_link' ? 'video' : 'document'))"></a>
                </template>
                <span class="muted" x-show="d.type === 'image' && d.caption" x-text="d.caption"></span>
            </div>
        </template>
    </div>
</div>
