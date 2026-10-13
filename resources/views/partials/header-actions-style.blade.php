{{-- ONE size for every button in a page header's action row (fault page, work order page): same width, same height, text centred - never "115 / 77 / 121 / 138". --}}
@once
<style>
    [data-header-actions] { gap: .5rem; }
    [data-header-actions] a.corex-btn-outline, [data-header-actions] button.corex-btn-outline, [data-header-actions] a.corex-btn-primary, [data-header-actions] button.corex-btn-primary {
        width: 10rem; min-height: 36px; display: inline-flex; align-items: center; justify-content: center; text-align: center; white-space: nowrap;
    }
</style>
@endonce
