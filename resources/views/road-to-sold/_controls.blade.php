{{-- AT-454 - one height for every control on the Road to Sold screens, so buttons, fields and dropdowns line up. --}}
<style>
    .rts-page select,
    .rts-page input:not([type="radio"]):not([type="checkbox"]),
    .rts-page .corex-btn-xs {
        height: 2rem;
        box-sizing: border-box;
    }
    .rts-page .corex-btn-xs { padding-top: 0; padding-bottom: 0; justify-content: center; white-space: nowrap; }
    .rts-page select, .rts-page input:not([type="radio"]):not([type="checkbox"]) { padding-top: 0; padding-bottom: 0; }
</style>
