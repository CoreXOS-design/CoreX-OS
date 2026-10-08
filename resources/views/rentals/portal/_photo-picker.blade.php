{{-- .ai/specs/rental-portal-access.md §22 — one photo picker for every portal form that takes photos (tenant fault, owner request,
     "not complete" answer). $bind is the Alpine object that holds `photos` (an array), `photoError` and `photoBusy`.
     Camera and gallery are two separate controls (a phone camera hands over ONE photo per tap; the gallery can multi-select) and every
     pick ADDS to the list — nothing replaces what is already there. Photos are shrunk in the browser (compressPhoto) before they are kept. --}}
<div data-photo-picker>
    <div class="photo-actions">
        <label class="btn btn-outline pick" data-photo-camera>
            <input type="file" class="file-hidden" accept="image/*" capture="environment" @change="addPhotos({{ $bind }}, $event)">
            Take photo
        </label>
        <label class="btn btn-outline pick" data-photo-gallery>
            <input type="file" class="file-hidden" accept="image/*" multiple @change="addPhotos({{ $bind }}, $event)">
            From gallery
        </label>
    </div>
    <div class="photo-grid" x-show="{{ $bind }}.photos.length">
        <template x-for="p in {{ $bind }}.photos" :key="p.id">
            <div class="thumb" data-photo-thumb>
                <img :src="p.url" :alt="p.name">
                <button type="button" class="thumb-x" aria-label="Remove photo" @click="removePhoto({{ $bind }}, p.id)">&times;</button>
            </div>
        </template>
    </div>
    <p class="muted" style="margin:6px 0 0;" x-show="{{ $bind }}.photos.length || {{ $bind }}.photoBusy" x-text="photoCountLabel({{ $bind }})"></p>
    <p class="error" x-show="{{ $bind }}.photoError" x-text="{{ $bind }}.photoError"></p>
</div>
