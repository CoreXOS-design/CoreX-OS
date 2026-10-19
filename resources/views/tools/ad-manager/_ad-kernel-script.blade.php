{{-- The ad render kernel (ad-manager.md §12), loaded once per page however many partials ask for it. --}}
@once
<script src="{{ asset_v('js/corex-ad-render.js') }}"></script>
@endonce
