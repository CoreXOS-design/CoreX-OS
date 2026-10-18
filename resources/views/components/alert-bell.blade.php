{{--
    The alert bell button (spec notification-system-overview.md section 16). Sits in the left menu's top bar, left of the Ellie button (moved there
    from the user block, Johan 2026-10-18), and looks like it: no border, no shaded box, same size, muted icon; the menu links' hover (agency menu colour tint, light and dark), and the brand colour
    while its panel is open (Johan 2026-10-18). Only the button lives here: the count badge, the panel and the toasts are all driven by
    components/alert-popups.blade.php, which is included once per staff layout (layouts/partials/_staff-alerts.blade.php).
--}}
@once
<style>
    /* Hover matches the menu's own links (corex.css .corex-nav-item:hover): the agency's menu colour as a clear tint and the icon in
       that colour, a small lift - visible in light and dark mode alike. */
    .corex-alert-bell { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: .5rem; border: 0; background: transparent; color: var(--text-muted); cursor: pointer; transition: background-color 180ms ease, color 180ms ease, transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 180ms ease; }
    .corex-alert-bell svg { transition: transform 220ms cubic-bezier(0.34, 1.56, 0.64, 1); }
    .corex-alert-bell:hover { background: color-mix(in srgb, var(--corex-sidebar-hover, #0ea5e9) 18%, transparent); color: var(--corex-sidebar-hover, #0ea5e9); transform: translateY(-1px); box-shadow: 0 10px 20px -12px color-mix(in srgb, var(--corex-sidebar-hover, #0ea5e9) 55%, transparent); }
    .corex-alert-bell:hover svg { transform: scale(1.12); }
    .corex-alert-bell:focus-visible { outline: 2px solid var(--corex-sidebar-hover, #0ea5e9); outline-offset: 2px; }
    .corex-alert-bell[aria-expanded="true"] { background: var(--brand-button); color: #fff; }
    @media (prefers-reduced-motion: reduce) {
        .corex-alert-bell, .corex-alert-bell svg { transition: none; }
        .corex-alert-bell:hover, .corex-alert-bell:hover svg { transform: none; }
    }
</style>
@endonce
<button type="button" class="corex-alert-bell" data-alert-bell aria-label="Alerts" aria-haspopup="true" aria-expanded="false" title="Alerts">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
    </svg>
    <span class="cxa-badge" data-alert-bell-badge></span>
</button>
