{{--
    MIC Phase D1 — four-tab nav. Shared across Work / Opportunities /
    Analyse / Market Pulse. Settings affordance on the right is permission-
    gated (prospecting_setup.manage).

    Active state matches market-intelligence.{tab}.* so child URLs (filtered
    Work views, listing detail slide-overs, etc.) keep the parent tab lit.

    Spec: .ai/specs/mic-complete-spec.md §5.2.
--}}
@php
    $tabs = [
        ['key' => 'work',          'route' => 'market-intelligence.work',          'label' => 'Work'],
        ['key' => 'opportunities', 'route' => 'market-intelligence.opportunities', 'label' => 'Opportunities'],
        ['key' => 'analyse',       'route' => 'market-intelligence.analyse',       'label' => 'Analyse'],
        ['key' => 'market-pulse',  'route' => 'market-intelligence.market-pulse',  'label' => 'Market Pulse'],
        ['key' => 'portal-alerts', 'route' => 'market-intelligence.portal-alerts', 'label' => 'Portal Alerts'],
    ];
    // Phase G2 — managers + admins see the Team tab.
    if (auth()->user()?->hasPermission('mic.view_team')) {
        $tabs[] = ['key' => 'team', 'route' => 'market-intelligence.team', 'label' => 'Team'];
    }
    // Importer — bulk PDF report import, gated by mic.upload_reports. Lives
    // inside MIC as a tab (no separate sidebar entry). Stays lit across the
    // whole reports.* group (bulk-import, list, detail).
    if (auth()->user()?->hasPermission('mic.upload_reports')
        && \Illuminate\Support\Facades\Route::has('market-intelligence.reports.bulk-import')) {
        $tabs[] = [
            'key'   => 'importer',
            'route' => 'market-intelligence.reports.bulk-import',
            'label' => 'Importer',
            'match' => 'market-intelligence.reports.*',
        ];
    }
    $activeKey = collect($tabs)
        ->first(fn ($t) => request()->routeIs($t['match'] ?? $t['route']))['key'] ?? 'work';
@endphp
<nav class="mic-tabs"
     style="display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid var(--border); margin-bottom: 16px; padding: 0 4px;">
    <div style="display: flex; gap: 2px;">
        @foreach($tabs as $tab)
            @php
                $isActive = $activeKey === $tab['key'];
                $baseStyle = 'padding: 10px 16px; text-decoration: none; font-size: 0.875rem; font-weight: 500;
                              border-bottom: 2px solid transparent; color: var(--text-muted);
                              border-top-left-radius: 4px; border-top-right-radius: 4px;';
                $activeStyle = 'padding: 10px 16px; text-decoration: none; font-size: 0.875rem; font-weight: 600;
                                border-bottom: 2px solid var(--brand-button); color: var(--brand-button);
                                background: color-mix(in srgb, var(--brand-button) 8%, transparent);
                                border-top-left-radius: 4px; border-top-right-radius: 4px;';
            @endphp
            <a href="{{ route($tab['route']) }}"
               style="{{ $isActive ? $activeStyle : $baseStyle }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    @permission('prospecting_setup.manage')
        @if(\Illuminate\Support\Facades\Route::has('command-center.settings.market-intelligence'))
            <a href="{{ route('command-center.settings.market-intelligence') }}"
               style="text-decoration: none; font-size: 0.75rem; color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                Settings
            </a>
        @endif
    @endpermission
</nav>
