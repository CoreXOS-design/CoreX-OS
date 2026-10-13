{{-- AT-454 - one height, weight, border and corner for every control on the Road to Sold screens, so buttons, fields and dropdowns line up
     (the standard primary button has no border and a heavier weight than the outline one; here they are the same size and weight). --}}
<style>
    .rts-page select,
    .rts-page input:not([type="radio"]):not([type="checkbox"]),
    .rts-page .corex-btn-xs {
        height: 2rem;
        box-sizing: border-box;
    }
    .rts-page .corex-btn-xs {
        padding: 0 0.75rem;
        justify-content: center;
        white-space: nowrap;
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1;
        border-width: 1px;
        border-style: solid;
        border-radius: 0.375rem;
    }
    .rts-page .corex-btn-outline.corex-btn-xs { border-color: var(--border); background: var(--surface); color: var(--text-primary); }
    .rts-page .corex-btn-primary.corex-btn-xs { border-color: var(--brand-button, #0ea5e9); }
    .rts-page .rts-actions .corex-btn-xs { min-width: 5.5rem; }
    .rts-page select, .rts-page input:not([type="radio"]):not([type="checkbox"]) { padding-top: 0; padding-bottom: 0; }
</style>
