{{--
    §45.7a — one photo strip for one side of one row (or one room). Each thumbnail carries everything the
    viewer needs as data-* attributes, so the viewer script never reaches back to the server and nothing
    user-supplied is ever concatenated into HTML.
    In: $scope (e.g. "item-12" / "room-3"), $side ("in"|"out"), $photos (arrays from the service).
--}}
<div class="cmp-strip">
    @foreach($photos as $photo)
        <button type="button" class="cmp-thumb"
                data-cmp-thumb
                data-scope="{{ $scope }}"
                data-side="{{ $side }}"
                data-group="{{ $photo['group'] ?? '' }}"
                data-src="{{ $photo['src'] }}"
                data-caption="{{ $photo['caption'] }}"
                data-note="{{ trim(($photo['note_class'] ? $photo['note_class'] . ': ' : '') . ($photo['note'] ?? '')) }}"
                data-label="{{ $side === 'in' ? 'Move-in' : 'Move-out' }} photo"
                title="{{ $photo['caption'] }}">
            <img src="{{ $photo['src'] }}" alt="" loading="lazy">
            @if($photo['paired'])<span class="cmp-paired">Paired</span>@endif
            <span class="cmp-cap">{{ $photo['caption_short'] }}</span>
        </button>
    @endforeach
</div>
