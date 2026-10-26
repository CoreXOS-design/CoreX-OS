{{-- Rentals detail / form pages moved off the legacy .hfc-card frame onto corex-content (Andre 2026-10-26).
     That frame's global rules used to dress every bare field; these pages still carry some hand-sized
     inputs ("w-full rounded-md px-3 py-2" + an inline border), so any field not already on the standard
     prop-* classes gets the same theme-token look here. Scoped to .rx-page; light + dark. --}}
<style>
  .rx-page :is(input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden]):not([type=range]):not([type=color]), select, textarea):not(.prop-input):not(.prop-select):not(.prop-textarea):not(.list-header-filter){
    font-family:inherit;font-size:.8125rem;color:var(--text-primary);background:var(--surface);
    border:1px solid var(--border);border-radius:6px;transition:border-color 150ms,box-shadow 150ms}
  .rx-page :is(input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden]):not([type=range]):not([type=color]), select, textarea):not(.prop-input):not(.prop-select):not(.prop-textarea):not(.list-header-filter):focus{
    outline:0;border-color:var(--brand-button,#0ea5e9);box-shadow:0 0 0 2px color-mix(in srgb,var(--brand-button,#0ea5e9) 15%,transparent)}
  .rx-page :is(input, textarea)::placeholder{color:var(--text-muted)}
  .rx-page select option{background:var(--surface);color:var(--text-primary)}
  .rx-page input[type=checkbox],.rx-page input[type=radio]{accent-color:var(--brand-button,#0ea5e9)}
  .rx-page input[type=file]{font-size:.75rem;color:var(--text-secondary)}
  .rx-page input[type=file]::file-selector-button{font-family:inherit;font-size:.75rem;font-weight:500;margin-right:.6rem;padding:.3rem .75rem;
    border-radius:6px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);cursor:pointer;transition:border-color 150ms}
  .rx-page input[type=file]::file-selector-button:hover{border-color:var(--border-hover)}
</style>
