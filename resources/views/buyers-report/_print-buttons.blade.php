{{-- Print / Download PDF (Johan, 2026-08-20 — meeting tomorrow, urgent).
     Deliberately NOT server-rendered static hrefs: window.location.search
     already carries scope/period/compare/type on the index page (how the
     page itself got its current state), and _demand-analysis.blade.php
     keeps it in sync with the property-type ticks + price slider via
     history.replaceState() as the user changes them -- so reading
     location.search AT CLICK TIME, not at page-load time, is what makes
     the PDF respect whatever filters are active right now.

     The agent/branch pages identify their scope via a PATH segment
     (/buyers-report/agent/{user}, /branch/{branch}), not a query param, so
     location.search alone would be missing it there -- $extraQuery (passed
     by agent.blade.php/branch.blade.php) supplies exactly that, merged
     ahead of the live location.search by buyersReportExportUrl() below. --}}
@php $extraQuery = $extraQuery ?? []; @endphp
<div class="flex items-center gap-2">
    <button type="button" onclick="window.open(buyersReportExportUrl('{{ route('buyers-report.print') }}', @js($extraQuery)), '_blank')"
            class="text-xs font-medium px-3 py-1.5 rounded-md" style="border: 1px solid var(--border); color: var(--text-primary); background: var(--surface);">
        Print
    </button>
    <button type="button" onclick="window.location.href = buyersReportExportUrl('{{ route('buyers-report.pdf') }}', @js($extraQuery))"
            class="text-xs font-medium px-3 py-1.5 rounded-md" style="border: 1px solid var(--border); color: #fff; background: var(--brand-icon, #0ea5e9);">
        Download PDF
    </button>
</div>
<script>
    // Merges this page's own path-scope params (agent/branch pages only)
    // with whatever is currently in the URL's query string -- which
    // _demand-analysis.blade.php keeps live-synced to the type ticks/price
    // slider -- so the export always reflects exactly what's on screen.
    function buyersReportExportUrl(base, extraQuery) {
        const params = new URLSearchParams(window.location.search);
        Object.keys(extraQuery || {}).forEach(k => params.set(k, extraQuery[k]));
        const qs = params.toString();
        return base + (qs ? '?' + qs : '');
    }
</script>
