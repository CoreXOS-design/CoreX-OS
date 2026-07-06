{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Shared leave-report navigation tabs. Rendered identically on every report page
     so switching tabs never shifts the layout. Active tab is resolved from the
     current route — no parameter required. --}}
@php
    $reportTabs = [
        ['route' => 'payroll.leave.reports.register',       'label' => 'Register'],
        ['route' => 'payroll.leave.reports.branch-summary', 'label' => 'Branch Summary'],
        ['route' => 'payroll.leave.reports.audit-log',      'label' => 'Audit Log'],
    ];
@endphp
<div class="flex gap-1" style="border-bottom:1px solid var(--border);">
    @foreach($reportTabs as $tab)
        @php $isActive = request()->routeIs($tab['route']); @endphp
        <a href="{{ route($tab['route']) }}"
           class="px-3 py-1.5 text-xs font-semibold transition-colors"
           style="{{ $isActive
                ? 'border-bottom:2px solid var(--brand-icon, #0ea5e9); color:var(--brand-icon, #0ea5e9);'
                : 'color:var(--text-secondary);' }}">{{ $tab['label'] }}</a>
    @endforeach
</div>
