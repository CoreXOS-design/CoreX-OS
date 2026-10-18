{{--
    The alert bell button (spec notification-system-overview.md section 16). Sits in the left menu's top bar, left of the Ellie button (moved there
    from the user block, Johan 2026-10-18), and looks like it: no border, no shaded box, same size, muted icon; a soft hover, and the brand colour
    while its panel is open (Johan 2026-10-18). Only the button lives here: the count badge, the panel and the toasts are all driven by
    components/alert-popups.blade.php, which is included once per staff layout (layouts/partials/_staff-alerts.blade.php).
--}}
@once
<style>
    .corex-alert-bell { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: .5rem; border: 0; background: transparent; color: var(--text-muted); cursor: pointer; transition: background-color .15s ease, color .15s ease; }
    .corex-alert-bell:hover { background: var(--surface-2, rgba(128,128,128,.12)); color: var(--text-primary); }
    .corex-alert-bell:focus-visible { outline: 2px solid var(--brand-button); outline-offset: 2px; }
    .corex-alert-bell[aria-expanded="true"] { background: var(--brand-button); color: #fff; }
</style>
@endonce
<button type="button" class="corex-alert-bell" data-alert-bell aria-label="Alerts" aria-haspopup="true" aria-expanded="false" title="Alerts">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
    </svg>
    <span class="cxa-badge" data-alert-bell-badge></span>
</button>
