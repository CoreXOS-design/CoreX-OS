{{--
    The alert bell button (spec notification-system-overview.md section 16). Sits beside the theme toggle in the left menu's user block, same size
    and class as it. Only the button lives here: the count badge, the panel and the toasts are all driven by components/alert-popups.blade.php,
    which is included once per staff layout (layouts/partials/_staff-alerts.blade.php).
--}}
<button type="button" class="corex-theme-toggle" data-alert-bell aria-label="Alerts" aria-haspopup="true" aria-expanded="false" title="Alerts" style="position:relative;">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
    </svg>
    <span class="cxa-badge" data-alert-bell-badge></span>
</button>
