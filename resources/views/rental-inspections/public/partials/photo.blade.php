{{-- §45.3 (Build I-1) — one photo on the public report: the thumbnail (storage_path is already a full URL),
     when it was taken (or, honestly, only uploaded — RentalInspectionPhoto::captionLabel()), and the photo's
     own note when it has one. --}}
<div class="w-24">
    <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener">
        <img src="{{ $photo->storage_path }}" alt="" class="w-24 h-20 object-cover rounded-md border border-slate-200">
    </a>
    @if($photo->captionLabel() !== '')
        <p class="text-[10px] leading-tight text-slate-500 mt-1" data-qa="photo-caption">{{ $photo->captionLabel() }}</p>
    @endif
    @if($photo->note)
        <p class="text-[11px] leading-tight text-slate-600 mt-1">
            @if($photo->note->classification_key)
                <span class="font-semibold">{{ $photoNoteLabels->get($photo->note->classification_key, ucfirst(str_replace('_', ' ', $photo->note->classification_key))) }}:</span>
            @endif
            {{ $photo->note->note }}
        </p>
    @endif
</div>
