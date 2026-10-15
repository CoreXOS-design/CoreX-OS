{{--
    AT-459 — the crew's receipt for a part line, as a small thumbnail that opens in the shared photo popup (never a new tab).
    Receives $receipt (a RentalWorkOrderPhoto, or null → renders nothing) and $label (the line's description, for the popup caption).
    The viewer partial guards itself against a second include, and the click handler is wired once per page.
--}}
@if(!empty($receipt))
    @include('partials.photo-viewer')
    <button type="button" data-receipt-thumb data-open-receipt="{{ $receipt->storage_path }}" data-receipt-label="{{ $label ?? 'Receipt' }}"
            title="Receipt — open" aria-label="Open the receipt{{ !empty($label) ? ' for ' . $label : '' }}"
            style="padding:0; border:1px solid var(--border); border-radius:6px; background:none; cursor:zoom-in; line-height:0; vertical-align:middle;">
        <img src="{{ $receipt->storage_path }}" alt="Receipt" loading="lazy" style="width:36px; height:36px; object-fit:cover; border-radius:5px;">
    </button>
    @once
        <script>
            (function () {
                document.addEventListener('click', function (e) {
                    var b = e.target.closest ? e.target.closest('[data-open-receipt]') : null;
                    if (!b || !window.CorexPhotoViewer) { return; }
                    window.CorexPhotoViewer.open({
                        photos: [{ id: 'receipt', url: b.getAttribute('data-open-receipt'), thumb_url: b.getAttribute('data-open-receipt'), alt: 'Receipt for ' + (b.getAttribute('data-receipt-label') || 'this line'), marks: [], note: null }],
                        index: 0, editable: false, returnFocus: b
                    });
                });
            })();
        </script>
    @endonce
@endif
