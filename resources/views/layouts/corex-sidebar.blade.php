@php
    $user = auth()->user();
    $effectiveRole = $user ? $user->effectiveRole() : 'agent';
    $effectiveBranchId = $user?->effectiveBranchId();

    $userInitials = $user ? collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') : '??';
    $userRoleModel = $user ? \App\Models\Role::allRoles($user->effectiveAgencyId())->firstWhere('name', $effectiveRole) : null;
    $userRole = $userRoleModel?->label ?? ($user ? ucfirst(str_replace('_', ' ', $effectiveRole)) : '');

    // Owner role & Agency Switcher
    $isOwner = $user && $user->isOwnerRole();
    $activeAgencyId = session('active_agency_id');
    $agencies = $isOwner ? \App\Models\Agency::orderBy('name')->get() : collect();
    $activeAgency = ($isOwner && $activeAgencyId) ? $agencies->find($activeAgencyId) : null;
    // Live 24h grants for this owner — keyed by agency_id → ISO expires-at
    $accessGrants = $isOwner
        ? \App\Models\AgencyAccessRequest::query()
            ->byRequester($user->id)
            ->where('status', \App\Models\AgencyAccessRequest::STATUS_APPROVED)
            ->where('granted_session_expires_at', '>', now())
            ->get(['target_agency_id', 'granted_session_expires_at'])
            ->groupBy('target_agency_id')
            ->map(fn ($rows) => $rows->max('granted_session_expires_at')->toIso8601String())
            ->all()
        : [];

    // Current user's agency (for all users)
    $_userAgencyId = $user?->effectiveAgencyId();
    $_userAgency = $_userAgencyId ? \App\Models\Agency::find($_userAgencyId) : null;

    // Branch-isolation Phase 2: header tag + switcher state
    $_splitBranchesOn = (bool) ($_userAgency?->split_branches_enabled ?? false);
    $_branchViewAll   = $user && $user->hasPermission('branches.view_all');
    $_branchCanSwitch = $user && $user->hasPermission('branches.switch');
    $_viewAsBranchId  = session('view_as_branch_id');
    $_activeBranch    = $user ? ($_viewAsBranchId
        ? \App\Models\Branch::find($_viewAsBranchId)
        : ($user->branch_id ? \App\Models\Branch::find($user->branch_id) : null)
    ) : null;
    $_agencyBranches  = $_userAgencyId
        ? \App\Models\Branch::where('agency_id', $_userAgencyId)->orderBy('name')->get()
        : collect();

    // Admin Multi-Branch Manager — "Acting as" switcher state. Separate from
    // the branch-isolation switcher above: this writes acting_branch_manager_id
    // (identity only) and NEVER changes data scope. See
    // .ai/specs/admin-multi-branch-manager.md.
    $_canSelfAssignManaged = $user && $user->hasPermission('branches.self_assign_managed');
    $_managedBranches = $_canSelfAssignManaged
        ? $user->managedBranches()->orderBy('branches.name')->get()
        : collect();
    $_actingBranchId = $user ? $user->actingBranchManagerId() : null;
    $_actingBranch   = $_actingBranchId ? $_managedBranches->firstWhere('id', $_actingBranchId) : null;

    // Impersonation state
    $impersonatorId  = (int) session('impersonator_id', 0);
    $isImpersonating = $impersonatorId > 0;
    // Switch User is only available while inside an agency context. An owner
    // who has not selected an active agency has no agency scope, so there is
    // nobody to switch to — hide the control rather than list every user
    // across all agencies. effectiveAgencyId() resolves the active-agency
    // switcher override → branch → user agency; null = not in an agency.
    $canSwitchUsers  = !$isImpersonating
        && $user
        && $user->hasPermission('impersonate_users')
        && $user->effectiveAgencyId() !== null;
    $impersonatorName = null;
    if ($isImpersonating) {
        $impersonatorName = \Illuminate\Support\Facades\DB::table('users')->where('id', $impersonatorId)->value('name');
    }
    $switchUsers = collect();
    if ($canSwitchUsers) {
        $agencyFilterId = $user?->effectiveAgencyId();
        // Owner-role users are platform identities and must never appear in
        // the impersonation picker — that's the privilege-escalation path
        // closed by ImpersonateController::start() and codified in
        // .ai/specs/multi-tenancy.md.
        $ownerRoleNames = \App\Models\User::ownerRoleNames();
        $query = \Illuminate\Support\Facades\DB::table('users')
            ->where('is_active', 1)
            ->when(!empty($ownerRoleNames), fn($q) => $q->whereNotIn('role', $ownerRoleNames));

        if ($agencyFilterId) {
            $branchIds = \Illuminate\Support\Facades\DB::table('branches')
                ->where('agency_id', $agencyFilterId)
                ->pluck('id')
                ->all();
            $query->where(function ($q) use ($agencyFilterId, $branchIds) {
                $q->where('agency_id', $agencyFilterId);
                if (!empty($branchIds)) {
                    $q->orWhereIn('branch_id', $branchIds);
                }
            });
        }

        $switchUsers = $query->orderBy('name')->get(['id','name','email','role']);
    }

    // ── Active group detection (ONE mechanism: routeIs) ──
    $activeGroup = null;
    if (request()->routeIs(
        'worksheet.*', 'agent.listings*', 'rentals.*',
        'agent.dashboard', 'agent.daily*', 'agent.deals.*',
        'bm.performance*', 'bm.daily*', 'bm.listings*', 'bm.my.dashboard',
        'bm.worksheet.market*', 'bm.tv-messages*', 'bm.agent.performance*',
        'admin.performance', 'admin.agent.performance*', 'admin.branch.performance*',
        'admin.listings.*',
        'admin.deals*', 'admin.daily*', 'admin.targets*', 'admin.worksheet-market*',
        'admin.tv-messages*',
        'admin.monthly-goals*', 'admin.listing-targets*', 'admin.expenses*',
        'tools.commission', 'tools.cma', 'tools.history.*',
        'commission.index', 'commission.principal', 'commission.confirm', 'commission.pay'
    )) {
        $activeGroup = 'agency-tracker';
    } elseif (request()->routeIs('evaluation.*')) {
        $activeGroup = 'evaluation';
    } elseif ((request()->routeIs('docuperfect.*') && !request()->routeIs('docuperfect.sales*', 'docuperfect.rental*')) || request()->routeIs('my-portal.agency-documents*') || request()->routeIs('documents.shared-drive.*')) {
        $activeGroup = 'documents';
    } elseif (request()->routeIs('rental.*')) {
        $activeGroup = 'rentals';
    } elseif (request()->routeIs('compliance.*')) {
        $activeGroup = 'compliance';
    } elseif (request()->routeIs('command-center.*') && !request()->routeIs('command-center.buyers.*')) {
        // AT-108 — Buyer Pipeline (command-center.buyers.*) lives in REAL ESTATE
        // (AT-76 move), so it must NOT resolve to the Dashboard group. Excluded
        // here and added to the real-estate matcher below.
        $activeGroup = 'command-center';
    } elseif (request()->routeIs('corex.dashboard', 'corex.dashboard.oversight')) {
        // Today / Oversight live in the Command Center submenu but are also
        // landing pages. Only auto-expand the submenu if the user navigated
        // here from another Command Center page — otherwise show the main bar.
        $_ref = request()->headers->get('referer');
        if ($_ref) {
            try {
                $_refPath = parse_url($_ref, PHP_URL_PATH) ?: '/';
                $_refRoute = app('router')->getRoutes()->match(
                    \Illuminate\Http\Request::create($_refPath, 'GET')
                );
                $_refName = $_refRoute?->getName();
                if ($_refName && (
                    \Illuminate\Support\Str::startsWith($_refName, 'command-center.')
                    || in_array($_refName, ['corex.dashboard', 'corex.dashboard.oversight'], true)
                )) {
                    $activeGroup = 'command-center';
                }
            } catch (\Throwable $e) {
                // Referer didn't match a route — leave $activeGroup null
            }
        }
    } elseif (request()->routeIs(
        'prospecting.*',
        'market-intelligence.*',
        'corex.properties.*',
        'corex.map.*',
        'admin.p24.*',
        'corex.contacts.*',
        'corex.core-matches.*',
        'corex.portal-leads.*',
        'presentations.*',
        'corex.presentations.*',
        'commercial-evaluations.*',
        'command-center.buyers.*',   // AT-76 — Buyer Pipeline lives in Real Estate
        'corex.viewing-packs.*'      // AT-107 — Viewing Packs live in Real Estate
    )) {
        $activeGroup = 'real-estate';
    } elseif (request()->routeIs('payroll.leave.*')) {
        $activeGroup = 'leave';
    } elseif (request()->routeIs('payroll.*')) {
        $activeGroup = 'payroll';
    } elseif (request()->routeIs('admin.importer.*') || request()->routeIs('admin.pp.*')) {
        $activeGroup = 'importer';
    } elseif (request()->routeIs('deals-v2.*')) {
        $activeGroup = 'deals-v2';
    } elseif (request()->routeIs('admin.integrations.*')) {
        $activeGroup = 'integration';
    } elseif (request()->routeIs(
        'admin.deposit-trust-interest.*',
        'deposit-interest-calculator.*',
        'calculators.*'
    )) {
        $activeGroup = 'trust-interest';
    } elseif (request()->routeIs(
        'communications.wa-devices.*',
        'communications.triage.*',
        'my-portal.comm-capture.*'
    )) {
        $activeGroup = 'communication';
    }

    // ── Demo sidebar curation (presentation-only) ──
    // Hide curated sidebar items for demo-agency members only. Owners and all
    // real users are never affected. The saved key list is always exposed so
    // the Dev Settings curator can pre-check it; the live hide pass only runs
    // when $_demoNavApply is true. See .ai/specs/demo-sidebar-curation.md.
    $_demoHiddenNav = \App\Models\DevSetting::demoHiddenSidebar();
    $_demoNavApply  = $user && $_userAgency && $_userAgency->is_demo && !$user->isEffectiveOwner();
@endphp

<div class="corex-sidebar">
    {{-- Logo + Help icon --}}
    <div class="corex-sidebar-logo" style="display:flex; align-items:center; justify-content:space-between;">
        <span>CoreX <span class="corex-logo-accent">Os</span></span>
        <div style="display:flex; align-items:center; gap:0.5rem; flex-shrink:0;">
            @auth
            {{-- AT-41: the tour "?" launcher now lives in each page's HEADER via
                 layouts/partials/tour-header-launcher.blade.php (Johan + Andre's
                 decision), not here in the sidebar. If a tour-enabled page omits
                 that header partial, the engine degrades to a floating button. --}}
            <div id="help-widget-slot" style="flex-shrink:0;"></div>
            @endauth
            {{-- Mobile-only: close sidebar for a full-screen page --}}
            <button type="button" @click="sidebarOpen = false" class="lg:hidden"
                    aria-label="Close menu" title="Close menu"
                    style="display:flex; align-items:center; justify-content:center; width:2rem; height:2rem; border-radius:6px; color:var(--text-secondary); background:transparent;">
                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>
    @if($_userAgency)
    <div class="px-4 -mt-1 pb-2">
        <div class="text-[0.6875rem] font-semibold uppercase tracking-widest text-center truncate" style="color:var(--text-muted); opacity:0.6;">
            {{ $_userAgency->name }}@if($_activeBranch || ($_branchViewAll && $_agencyBranches->count() > 0)) <span style="opacity:0.5;">—</span>
                @if($_branchViewAll && !$_viewAsBranchId)
                    <span>All Branches</span>
                @elseif($_activeBranch)
                    <span>{{ $_activeBranch->name }}</span>@if($_viewAsBranchId) <span style="color:var(--brand-icon, #0ea5e9); text-transform:none; letter-spacing:normal; font-weight:500;">(viewing as)</span>@endif
                @endif
            @endif
        </div>
    </div>
    @endif

    {{-- Agency Switcher (owner role only) — with consent flow.
         See .ai/specs/agency-access-authorization-spec.md --}}
    @if($isOwner)
    @include('partials.agency-access-consent', ['agencies' => $agencies, 'activeAgencyId' => $activeAgencyId, 'activeAgency' => $activeAgency, 'accessGrants' => $accessGrants])
    @endif

    {{-- Remote Access Inbox (agency admins only, and only when the agency
         requires consent for remote access). When the toggle is OFF there is
         nothing to authorize, so the inbox is hidden entirely. --}}
    @if(auth()->check() && auth()->user()->role === 'admin' && $_userAgency?->require_external_access_authorization)
    <div class="px-3 pb-2">
        @include('partials.agency-access-inbox')
    </div>
    @endif

    {{-- Branch switcher (Split Branches Phase 2) --}}
    @if($_userAgency && $_branchCanSwitch && $_agencyBranches->count() > 1)
    <div class="px-4 pb-2">
        <div x-data="{ branchOpen: false }" class="px-0">
            <button type="button" @click="branchOpen = !branchOpen"
                    class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-[11px] font-medium transition-colors"
                    style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15" />
                </svg>
                <span class="flex-1 text-left truncate">
                    @if($_viewAsBranchId && $_activeBranch)
                        {{ $_activeBranch->name }}
                    @else
                        Switch Branch
                    @endif
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 transition-transform" :class="branchOpen && 'rotate-90'">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </button>
            <div x-show="branchOpen" x-cloak @click.outside="branchOpen = false" x-transition
                 class="mt-1 rounded-md overflow-hidden shadow-lg"
                 style="background:var(--surface-2); border:1px solid var(--border);">
                @if($_viewAsBranchId)
                <form method="POST" action="{{ route('branch.switch.clear') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-3 py-2 text-xs hover:bg-[color:var(--surface)]"
                            style="color:var(--brand-icon, #0ea5e9);">
                        ← All Branches
                    </button>
                </form>
                @endif
                @foreach($_agencyBranches as $_b)
                <form method="POST" action="{{ route('branch.switch', $_b) }}">
                    @csrf
                    <button type="submit"
                            class="w-full text-left px-3 py-2 text-xs hover:bg-[color:var(--surface)] {{ (int) $_viewAsBranchId === (int) $_b->id ? 'font-semibold' : '' }}"
                            style="color: @if((int) $_viewAsBranchId === (int) $_b->id) var(--brand-icon, #0ea5e9) @else var(--text-secondary) @endif;">
                        {{ $_b->name }}
                    </button>
                </form>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Acting-as branch manager (Admin Multi-Branch Manager). Identity only —
         lets an admin who manages several branches present as the manager of a
         chosen one for deal registration. Does NOT change what they can see. --}}
    @if($_canSelfAssignManaged && $_managedBranches->count() > 0)
    <div class="px-4 pb-2">
        <div x-data="{ actingOpen: false }" class="px-0">
            <button type="button" @click="actingOpen = !actingOpen"
                    class="w-full flex items-center gap-2 px-2 py-1.5 rounded-md text-[11px] font-medium transition-colors"
                    style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 flex-shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                <span class="flex-1 text-left truncate">
                    @if($_actingBranch)
                        Acting: {{ $_actingBranch->name }}
                    @else
                        Act as branch manager
                    @endif
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 transition-transform" :class="actingOpen && 'rotate-90'">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </button>
            <div x-show="actingOpen" x-cloak @click.outside="actingOpen = false" x-transition
                 class="mt-1 rounded-md overflow-hidden shadow-lg"
                 style="background:var(--surface-2); border:1px solid var(--border);">
                <form method="POST" action="{{ route('branch.acting.clear') }}">
                    @csrf
                    <button type="submit"
                            class="w-full text-left px-3 py-2 text-xs hover:bg-[color:var(--surface)] {{ !$_actingBranch ? 'font-semibold' : '' }}"
                            style="color: @if(!$_actingBranch) var(--brand-icon, #0ea5e9) @else var(--text-secondary) @endif;">
                        Administrator (all branches)
                    </button>
                </form>
                @foreach($_managedBranches as $_mb)
                <form method="POST" action="{{ route('branch.acting', $_mb) }}">
                    @csrf
                    <button type="submit"
                            class="w-full text-left px-3 py-2 text-xs hover:bg-[color:var(--surface)] {{ (int) $_actingBranchId === (int) $_mb->id ? 'font-semibold' : '' }}"
                            style="color: @if((int) $_actingBranchId === (int) $_mb->id) var(--brand-icon, #0ea5e9) @else var(--text-secondary) @endif;">
                        {{ $_mb->name }} Manager
                    </button>
                </form>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Navigation — sliding-panel drill-down (root + per-group overlay panels) --}}
    <nav class="flex-1 min-h-0 corex-nav-viewport"
         x-data="{
            stack: @js($activeGroup ? [$activeGroup] : []),
            push(g) { if (this.stack[this.stack.length - 1] !== g) this.stack.push(g) },
            pop() { this.stack.pop() },
            inStack(g) { return this.stack.includes(g) },
            openGroup: @js($activeGroup),
            toggle(g) { this.openGroup = (this.openGroup === g) ? null : g },
            searchQ: '',
            searchResults: [],
            searchSel: 0,
            runSearch() { this.searchResults = window.CorexNavSearch.search(this.searchQ); this.searchSel = 0 },
            clearSearch() { this.searchQ = ''; this.searchResults = []; this.searchSel = 0 },
            moveSel(d) { if (this.searchResults.length) this.searchSel = (this.searchSel + d + this.searchResults.length) % this.searchResults.length },
            goResult(e, r) { if (r && r.group) { e.preventDefault(); this.stack = [r.group]; this.clearSearch() } },
            goSel() {
                const r = this.searchResults[this.searchSel];
                if (!r) return;
                if (r.group) { this.stack = [r.group]; this.clearSearch() }
                else if (r.href) { window.location.href = r.href }
            }
         }">

        {{-- ═══════════════════════════════════════════
             SIDEBAR SEARCH — indexes the rendered nav (respects per-user
             permissions automatically) and jumps to any heading or sub-section.
             ═══════════════════════════════════════════ --}}
        <div class="corex-nav-search" @keydown.escape="clearSearch()">
            <div class="corex-nav-search-field">
                <svg class="corex-nav-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input type="text" x-model="searchQ" @input="runSearch()"
                       @keydown.down.prevent="moveSel(1)" @keydown.up.prevent="moveSel(-1)"
                       @keydown.enter.prevent="goSel()"
                       placeholder="Search menu…" autocomplete="off" spellcheck="false"
                       class="corex-nav-search-input" aria-label="Search sidebar">
                <button type="button" x-show="searchQ.length" x-cloak @click="clearSearch()"
                        class="corex-nav-search-clear" aria-label="Clear search">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <div x-show="searchQ.length > 0" x-cloak @click.outside="clearSearch()" class="corex-nav-search-results">
                <template x-if="searchResults.length === 0">
                    <div class="corex-nav-search-empty">No matches for “<span x-text="searchQ"></span>”</div>
                </template>
                <template x-for="(r, i) in searchResults" :key="r.key">
                    <a :href="r.href || '#'" @click="goResult($event, r)" @mouseenter="searchSel = i"
                       class="corex-nav-search-result" :class="{ 'is-active': i === searchSel }">
                        <span class="corex-nav-search-result-label" x-text="r.label"></span>
                        <span x-show="r.parent" class="corex-nav-search-result-parent" x-text="r.parent"></span>
                    </a>
                </template>
            </div>
        </div>

        <div class="corex-nav-root"
             x-init="$el.scrollTop = sessionStorage.getItem('sidebarScroll') || 0"
             @scroll.debounce.100ms="sessionStorage.setItem('sidebarScroll', $el.scrollTop)">

        @permission('sidebar.section.agents')
        <div class="corex-nav-section-label">Agents</div>

        {{-- ═══════════════════════════════════════════
             DASHBOARD (expandable — Calendar & Tasks as sub-items)
             ═══════════════════════════════════════════ --}}
        @permission('view_dashboard')
        <div>
            <button type="button" @click="push('command-center')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'command-center' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
                </svg>
                <span>Dashboard</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'command-center' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('command-center') }" data-manual-order>
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Dashboard</div>

                <a href="{{ route('command-center.today') }}" class="corex-nav-subitem {{ request()->routeIs('corex.dashboard', 'command-center.today') ? 'active' : '' }}">Today</a>
                <a href="{{ route('command-center.calendar') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.calendar') ? 'active' : '' }}">Calendar</a>
                <a href="{{ route('command-center.tasks') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.tasks*') ? 'active' : '' }}">Tasks</a>
                <a href="{{ route('command-center.reporting.agent') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.reporting.agent') ? 'active' : '' }}">My Performance</a>
                @php $pendingInvites = auth()->check() ? \App\Models\CommandCenter\CalendarEventInvitation::forUser(auth()->id())->pending()->count() : 0; @endphp
                <a href="{{ route('command-center.calendar.invitations') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.calendar.invitations*') ? 'active' : '' }}">
                    Invitations @if($pendingInvites > 0) <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold" style="background:#ef444420; color:#ef4444;">{{ $pendingInvites }}</span> @endif
                </a>
                @permission('dashboard.oversight.view')
                <a href="{{ route('command-center.reporting.branch') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.reporting.branch') ? 'active' : '' }}">Branch Report</a>
                @endpermission
                @if(auth()->user() && in_array(auth()->user()->role, ['admin', 'super_admin', 'owner']))
                <a href="{{ route('command-center.reporting.agency') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.reporting.agency') ? 'active' : '' }}">Agency Report</a>
                @endif
                @if(auth()->user() && in_array(auth()->user()->role, ['admin', 'super_admin', 'owner']))
                <a href="{{ route('command-center.lost-deals') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.lost-deals') ? 'active' : '' }}">Lost Deals</a>
                @endif
                @permission('dashboard.oversight.view')
                    <a href="{{ route('corex.dashboard.oversight') }}" class="corex-nav-subitem {{ request()->routeIs('corex.dashboard.oversight') ? 'active' : '' }}">Oversight</a>
                @endpermission
                <a href="{{ route('command-center.performance') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.performance*') ? 'active' : '' }}">Performance</a>
                <a href="{{ route('command-center.user-settings') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.user-settings*') ? 'active' : '' }}">User Settings</a>
            </div>
        </div>
        @endpermission

        {{-- ═══════════════════════════════════════════
             MY PORTAL
             ═══════════════════════════════════════════ --}}
        @permission('access_my_portal')
        @php
            $portalNeedsAttention = false;
            if ($user) {
                $portalNeedsAttention = empty($user->ffc_number) || empty($user->ffc_certificate_path)
                    || \App\Models\TrainingCourse::where('is_required', true)->published()
                        ->whereDoesntHave('completions', fn($q) => $q->where('user_id', $user->id))
                        ->exists();
            }
        @endphp
        <a href="{{ route('agent.portal') }}"
           class="corex-nav-item {{ request()->routeIs('agent.portal*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>My Portal</span>
            @if($portalNeedsAttention)
            <span class="ml-auto w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
            @endif
        </a>
        @endpermission

        {{-- ═══════════════════════════════════════════
             REAL ESTATE (expandable group)
             ═══════════════════════════════════════════ --}}
        <div>
            <button type="button" @click="push('real-estate')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'real-estate' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                <span>Real Estate</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'real-estate' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('real-estate') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Real Estate</div>

                @permission('access_prospecting')
                {{-- F.1: relabelled from "Prospecting" to "Market intelligence" + retargeted at the
                     new route. The active-state class also matches the legacy prospecting.* names so
                     the old route group (still mounted during the F.1 migration window) keeps the
                     sidebar entry highlighted if anything internal still routes there.

                     F.2: count badge — canvass-pool size (matched_property_id IS NULL).
                     Cached 60s per agency. Mirrors the pendingVerificationCount / faultNewCount
                     precedents elsewhere in this sidebar. --}}
                @if(\Illuminate\Support\Facades\Route::has('market-intelligence.work'))
                @php
                    $miAgencyId = auth()->user()->effectiveAgencyId() ?? auth()->user()->agency_id ?? null;
                    $miCount = $miAgencyId ? cache()->remember(
                        'mi.sidebar_count.' . $miAgencyId,
                        60,
                        fn () => \App\Models\ProspectingListing::where('agency_id', $miAgencyId)
                            ->where('is_active', true)
                            ->whereNull('matched_property_id')
                            ->whereNull('deleted_at')
                            ->count(),
                    ) : 0;
                @endphp
                <a href="{{ route('market-intelligence.work') }}" class="corex-nav-subitem {{ request()->routeIs('market-intelligence.*') || request()->routeIs('prospecting.*') ? 'active' : '' }}">
                    <span>Market intelligence</span>
                    @if($miCount > 0)
                    <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold"
                          style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">{{ number_format($miCount) }}</span>
                    @endif
                </a>
                {{-- Bulk Import Reports moved into the Market Intelligence tab bar
                     as the "Importer" tab (see partials/tabs.blade.php). No
                     separate sidebar entry. --}}
                {{-- Q4/D1 — Portal alerts awaiting address moved into the Market
                     Intelligence tab bar as the "Portal Alerts" tab (see
                     partials/tabs.blade.php). No separate sidebar entry. --}}
                @endif
                {{-- Phase D1 — Tracked Properties folded into the MIC
                     Opportunities tab. Sidebar entry removed; the legacy
                     route /corex/tracked-properties now 301-redirects to
                     /corex/market-intelligence/opportunities. --}}
                @endpermission

                @permission('access_properties')
                @if(config('features.properties') && \Illuminate\Support\Facades\Route::has('corex.properties.index'))
                <a href="{{ route('corex.properties.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.properties.*') ? 'active' : '' }}">Properties</a>
                @endif
                {{-- Phase 3g — Map module. Same permission as Properties; agency-scoped. --}}
                @if(\Illuminate\Support\Facades\Route::has('corex.map.index'))
                <a href="{{ route('corex.map.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.map.*') ? 'active' : '' }}">Map</a>
                @endif
                @endpermission

                @permission('access_contacts')
                @if(\Illuminate\Support\Facades\Route::has('corex.contacts.index'))
                <a href="{{ route('corex.contacts.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.contacts.*') && !request()->routeIs('corex.core-matches.*') ? 'active' : '' }}">Contacts</a>
                @endif
                @endpermission

                {{-- Part 4 — unified Outreach & Canvassing board (Activity Feed + AT-91 funnel). --}}
                @permission('outreach.summary.view')
                @if(\Illuminate\Support\Facades\Route::has('corex.outreach-canvassing.index'))
                <a href="{{ route('corex.outreach-canvassing.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.outreach-canvassing.*') ? 'active' : '' }}">Outreach &amp; Canvassing</a>
                @endif
                {{-- AT-91 — WhatsApp Outreach Summary board (agents × outreach states). --}}
                @if(\Illuminate\Support\Facades\Route::has('corex.outreach-summary.index'))
                <a href="{{ route('corex.outreach-summary.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.outreach-summary.*') ? 'active' : '' }}">WhatsApp Outreach</a>
                @endif
                @endpermission

                {{-- AT-117 §6 / AT-120 — Outreach Queue (gated by the scoped view capability). --}}
                @permission('outreach_queue.view')
                @if(\Illuminate\Support\Facades\Route::has('corex.outreach-queue.index'))
                <a href="{{ route('corex.outreach-queue.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.outreach-queue.*') ? 'active' : '' }}">Outreach Queue</a>
                @endif
                @endpermission

                @permission('access_core_matches')
                @if(\Illuminate\Support\Facades\Route::has('corex.core-matches.index') && \App\Models\PerformanceSetting::get('matches_enabled', 1))
                <a href="{{ route('corex.core-matches.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.core-matches.*') ? 'active' : '' }}">Core Matches</a>
                @endif
                @endpermission

                {{-- AT-76 — Buyer Pipeline lives in Real Estate (was under Dashboard/Command Center). Route unchanged. --}}
                <a href="{{ route('command-center.buyers.pipeline') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.buyers*') ? 'active' : '' }}">Buyer Pipeline</a>

                {{-- AT-XX — Viewing Packs (buyer-facing property packs). --}}
                <a href="{{ route('corex.viewing-packs.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.viewing-packs.*') ? 'active' : '' }}">Viewing Packs</a>

                @permission('access_portal_leads')
                @if(\Illuminate\Support\Facades\Route::has('corex.portal-leads.index'))
                <a href="{{ route('corex.portal-leads.index') }}" class="corex-nav-subitem {{ request()->routeIs('corex.portal-leads.*') ? 'active' : '' }}">Portal Leads</a>
                @endif
                @endpermission

                @permission('access_presentations')
                @if(config('features.presentations') && \Illuminate\Support\Facades\Route::has('presentations.index'))
                <a href="{{ route('presentations.index') }}" class="corex-nav-subitem {{ request()->routeIs('presentations.*') ? 'active' : '' }}">Presentations</a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('corex.presentations.analytics.index'))
                    <a href="{{ route('corex.presentations.analytics.index') }}"
                       class="corex-nav-subitem {{ request()->routeIs('corex.presentations.analytics.*') ? 'active' : '' }}">
                        Analytics
                    </a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('corex.presentations.outcomes.index'))
                    @php
                        // Phase 8 — count of presentations >30d old with no outcome (in current user's scope).
                        $outcomePendingCount = 0;
                        try {
                            $user = auth()->user();
                            $agencyId = $user?->effectiveAgencyId();
                            if ($agencyId) {
                                $q = \App\Models\Presentation::where('agency_id', $agencyId)
                                    ->where('created_at', '<=', now()->subDays(30))
                                    ->whereDoesntHave('outcome');
                                if (!in_array((string) $user->role, ['branch_manager','principal','super_admin','admin'], true)) {
                                    $q->where('created_by_user_id', $user->id);
                                }
                                $outcomePendingCount = $q->count();
                            }
                        } catch (\Throwable $e) { /* sidebar must never blow up */ }
                    @endphp
                    <a href="{{ route('corex.presentations.outcomes.index') }}"
                       class="corex-nav-subitem {{ request()->routeIs('corex.presentations.outcomes.*') ? 'active' : '' }}">
                        <span>Outcomes</span>
                        @if($outcomePendingCount > 0)
                            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold"
                                  style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">{{ $outcomePendingCount > 99 ? '99+' : $outcomePendingCount }}</span>
                        @endif
                    </a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('corex.presentations.refresh-requests.index'))
                    @php
                        // Phase 7 — count of open refresh requests, scoped to the user's effective agency.
                        $refreshOpenCount = 0;
                        try {
                            $agencyId = auth()->user()?->effectiveAgencyId();
                            if ($agencyId) {
                                $refreshOpenCount = \App\Models\PresentationRefreshRequest::where('agency_id', $agencyId)
                                    ->whereIn('status', ['pending', 'acknowledged'])
                                    ->count();
                            }
                        } catch (\Throwable $e) { /* sidebar must never blow up */ }
                    @endphp
                    <a href="{{ route('corex.presentations.refresh-requests.index') }}"
                       class="corex-nav-subitem {{ request()->routeIs('corex.presentations.refresh-requests.*') ? 'active' : '' }}">
                        <span>Refresh Requests</span>
                        @if($refreshOpenCount > 0)
                            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold"
                                  style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b);">{{ $refreshOpenCount > 99 ? '99+' : $refreshOpenCount }}</span>
                        @endif
                    </a>
                @endif
                @endpermission

                @permission('access_commercial_evaluations')
                @if(\Illuminate\Support\Facades\Route::has('commercial-evaluations.index'))
                <a href="{{ route('commercial-evaluations.index') }}" class="corex-nav-subitem {{ request()->routeIs('commercial-evaluations.*') ? 'active' : '' }}">Commercial Evaluations</a>
                @endif
                @endpermission

                {{-- Legacy "P24 Alerts" link removed: admin.p24.index is a 301 redirect into
                     Market Intelligence → Market Pulse (MIC redesign Phase D1/D6). Market Pulse
                     is reachable from the Market Intelligence tab bar — single entry per spec. --}}
            </div>
        </div>

        {{-- ═══════════════════════════════════════════
             COMMUNICATION (expandable group)
             WhatsApp Capture + Message Triage + Communication Capture
             ═══════════════════════════════════════════ --}}
        @php
            $canSeeCommunication = auth()->check() && (
                auth()->user()->hasPermission('access_communication')
                || auth()->user()->hasPermission('triage_communications')
            );
        @endphp
        @if($canSeeCommunication)
        <div>
            <button type="button" @click="push('communication')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'communication' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                </svg>
                <span>Communication</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'communication' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('communication') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Communication</div>

                @permission('triage_communications')
                <a href="{{ route('communications.triage.index') }}" class="corex-nav-subitem {{ request()->routeIs('communications.triage.*') ? 'active' : '' }}">Message Triage</a>
                @endpermission

                {{-- AT-118 — Communications Access Gate: approver inbox (owning agents + grant_access holders) --}}
                @permission('communications.view')
                <a href="{{ route('corex.comms-access.inbox') }}" class="corex-nav-subitem {{ request()->routeIs('corex.comms-access.inbox') ? 'active' : '' }}">Access Requests</a>
                @endpermission

                @permission('access_communication')
                <a href="{{ route('communications.wa-devices.index') }}" class="corex-nav-subitem {{ request()->routeIs('communications.wa-devices.*') ? 'active' : '' }}">WhatsApp Capture</a>
                {{-- AT-136 — per-agent WhatsApp capture consent (which contacts I archive). --}}
                <a href="{{ route('communications.capture.my') }}" class="corex-nav-subitem {{ request()->routeIs('communications.capture.my') ? 'active' : '' }}">My WhatsApp Capture</a>
                {{-- Communication Capture (AT-39) — user email self-service --}}
                <a href="{{ route('my-portal.comm-capture.index') }}" class="corex-nav-subitem {{ request()->routeIs('my-portal.comm-capture.*') ? 'active' : '' }}">Communication Capture</a>
                @endpermission
                {{-- AT-136 — admin/CO review of capture opt-outs (declaration, never content). --}}
                @permission('communications.capture_review')
                <a href="{{ route('communications.capture.review') }}" class="corex-nav-subitem {{ request()->routeIs('communications.capture.review') ? 'active' : '' }}">Capture Opt-outs (Review)</a>
                @endpermission
            </div>
        </div>
        @endif

        {{-- ═══════════════════════════════════════════
             MY EARNINGS
             ═══════════════════════════════════════════ --}}
        <a href="{{ route('commission.dashboard') }}"
           class="corex-nav-item {{ request()->routeIs('commission.dashboard') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />
            </svg>
            <span>My Earnings</span>
        </a>

        {{-- AT-41 — Guided Tours: self-serve interactive walkthroughs. Visible to
             every authenticated user; the directory filters to their own tours. --}}
        <a href="{{ route('corex.guided-tours.index') }}"
           class="corex-nav-item {{ request()->routeIs('corex.guided-tours.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
            </svg>
            <span>Guided Tours</span>
        </a>

        {{-- ═══════════════════════════════════════════
             AGENCY TRACKER (expandable group)
             ═══════════════════════════════════════════ --}}
        @permission('access_agency_tracker')
        <div>
            <button type="button" @click="push('agency-tracker')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'agency-tracker' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                <span>Agency Tracker</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'agency-tracker' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('agency-tracker') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Agency Tracker</div>

                {{-- Common items (all roles) --}}
                @permission('view_worksheet')
                <a href="{{ route('worksheet.index') }}" class="corex-nav-subitem {{ request()->routeIs('worksheet.*') ? 'active' : '' }}">Worksheet</a>
                @endpermission

                @permission('view_listings')
                <a href="{{ route('agent.listings') }}" class="corex-nav-subitem {{ request()->routeIs('agent.listings*') ? 'active' : '' }}">My Listing Stock</a>
                @endpermission

                {{-- Agent section (view own stats) --}}
                @permission('view_own_stats')
                <div class="corex-nav-sublabel">My Performance</div>
                @permission('view_daily_activity')
                <a href="{{ route('agent.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('agent.daily') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily') ? 'active' : '' }}">My Daily Activity</a>
                @endpermission
                @permission('view_deals')
                <a href="{{ route('agent.deals.index') }}" class="corex-nav-subitem {{ request()->routeIs('agent.deals.*') ? 'active' : '' }}">My Deals</a>
                @endpermission
                @endpermission

                {{-- Branch Manager section (view branch stats) --}}
                @permission('view_branch_stats')
                <div class="corex-nav-sublabel">Branch</div>
                @permission('view_performance')
                <a href="{{ route('bm.performance') }}" class="corex-nav-subitem {{ request()->routeIs('bm.performance*') ? 'active' : '' }}">Branch Performance</a>
                @endpermission
                @permission('view_daily_activity')
                <a href="{{ route('bm.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('bm.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                @endpermission
                @permission('view_listings')
                <a href="{{ route('bm.listings') }}" class="corex-nav-subitem {{ request()->routeIs('bm.listings*') ? 'active' : '' }}">Branch Listing Stock</a>
                @endpermission
                @permission('view_dashboard')
                <a href="{{ route('bm.my.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('bm.my.dashboard') ? 'active' : '' }}">My Agent Dashboard</a>
                @endpermission
                @permission('view_deals')
                <a href="{{ route('admin.deals') }}" class="corex-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                @endpermission

                @if(auth()->user() && in_array((string) auth()->user()->role, ['admin', 'super_admin', 'branch_manager', 'principal'], true) && \Illuminate\Support\Facades\Route::has('corex.compliance.rcr.index'))
                    @php
                        // Phase 9d — open RCR submissions for current agency.
                        $rcrOpenCount = 0; $rcrNextDeadline = null;
                        try {
                            $agencyId = auth()->user()?->effectiveAgencyId();
                            if ($agencyId) {
                                $open = \App\Models\Compliance\Rcr\RcrSubmission::where('agency_id', $agencyId)
                                    ->whereIn('status', ['draft', 'in_review', 'approved_for_submission'])
                                    ->orderBy('submission_deadline')
                                    ->first();
                                if ($open) {
                                    $rcrOpenCount = 1;
                                    $rcrNextDeadline = (int) round(now()->diffInDays($open->submission_deadline, false));
                                }
                            }
                        } catch (\Throwable $e) { /* sidebar must never blow up */ }
                    @endphp
                    <a href="{{ route('corex.compliance.rcr.index') }}"
                       class="corex-nav-subitem {{ request()->routeIs('corex.compliance.rcr.*') ? 'active' : '' }}"
                       style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                        <span>RCR · FIC 2026</span>
                        @if($rcrOpenCount > 0)
                            <span style="display:inline-block;min-width:18px;padding:1px 6px;background:{{ $rcrNextDeadline !== null && $rcrNextDeadline <= 7 ? '#dc2626' : '#0ea5e9' }};color:#fff;border-radius:99px;font-size:0.625rem;font-weight:700;text-align:center;line-height:1.4;">
                                {{ $rcrNextDeadline !== null ? ($rcrNextDeadline < 0 ? 'OVERDUE' : $rcrNextDeadline . 'd') : '!' }}
                            </span>
                        @endif
                    </a>
                @endif

                @if(auth()->user() && in_array((string) auth()->user()->role, ['admin', 'super_admin', 'branch_manager', 'principal'], true) && \Illuminate\Support\Facades\Route::has('corex.admin.deal-link-review.index'))
                    @php
                        // Phase 3i — pending deal-link review count.
                        $dealLinkPendingCount = 0;
                        try {
                            $agencyId = auth()->user()?->effectiveAgencyId();
                            if ($agencyId) {
                                $dealLinkPendingCount = \App\Models\DealLinkReviewQueue::where('agency_id', $agencyId)
                                    ->where('match_status', 'pending')
                                    ->count();
                            }
                        } catch (\Throwable $e) { /* sidebar must never blow up */ }
                    @endphp
                    <a href="{{ route('corex.admin.deal-link-review.index') }}"
                       class="corex-nav-subitem {{ request()->routeIs('corex.admin.deal-link-review.*') ? 'active' : '' }}"
                       style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                        <span>Deal Link Review</span>
                        @if($dealLinkPendingCount > 0)
                            <span style="display:inline-block;min-width:18px;padding:1px 6px;background:#dc2626;color:#fff;border-radius:99px;font-size:0.625rem;font-weight:700;text-align:center;line-height:1.4;">
                                {{ $dealLinkPendingCount > 99 ? '99+' : $dealLinkPendingCount }}
                            </span>
                        @endif
                    </a>
                @endif

                <div class="corex-nav-sublabel">Setup</div>
                @permission('edit_worksheet')
                <a href="{{ route('bm.worksheet.market') }}" class="corex-nav-subitem {{ request()->routeIs('bm.worksheet.market*') ? 'active' : '' }}">Worksheet Market</a>
                @endpermission
                @permission('manage_targets')
                <a href="{{ route('admin.targets') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets') ? 'active' : '' }}">Daily Activity Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                @endpermission
                @permission('manage_tv_messages')
                <a href="{{ route('bm.tv-messages') }}" class="corex-nav-subitem {{ request()->routeIs('bm.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @endpermission
                @permission('view_daily_activity')
                @if($effectiveBranchId)
                <a href="{{ route('agent.daily') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily') && !request()->routeIs('agent.daily.summary*') ? 'active' : '' }}">Daily Activity Capture</a>
                @endif
                @endpermission
                @endpermission

                {{-- Admin section (view company stats) --}}
                @permission('view_company_stats')
                <div class="corex-nav-sublabel">Admin</div>
                @permission('view_performance')
                <a href="{{ route('admin.performance') }}" class="corex-nav-subitem {{ request()->routeIs('admin.performance') ? 'active' : '' }}">Performance</a>
                @endpermission
                @permission('view_listings')
                @if(\Illuminate\Support\Facades\Route::has('admin.listings.stock'))
                <a href="{{ route('admin.listings.stock') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.stock*') ? 'active' : '' }}">Company Listing Stock</a>
                @endif
                @endpermission
                @permission('view_deals')
                <a href="{{ route('admin.deals') }}" class="corex-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                @endpermission
                @permission('view_listings')
                <a href="{{ route('admin.listings.agents') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.agents*') ? 'active' : '' }}">Listing Stock</a>
                @endpermission
                @permission('access_import_listings')
                <a href="{{ route('admin.listings.import') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.import*') ? 'active' : '' }}">Import Listings</a>
                @endpermission
                @permission('view_daily_activity')
                <a href="{{ route('admin.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('admin.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                @endpermission
                @permission('manage_targets')
                <a href="{{ route('admin.targets') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets') ? 'active' : '' }}">Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                @endpermission
                @permission('manage_activity_mappings')
                <a href="{{ route('admin.activity-mappings.index') }}" class="corex-nav-subitem {{ request()->routeIs('admin.activity-mappings.*') ? 'active' : '' }}">Activity Scoring</a>
                @endpermission
                @permission('edit_worksheet')
                <a href="{{ route('admin.worksheet-market') }}" class="corex-nav-subitem {{ request()->routeIs('admin.worksheet-market*') ? 'active' : '' }}">Worksheet Market</a>
                @endpermission
                @permission('manage_tv_messages')
                <a href="{{ route('admin.tv-messages') }}" class="corex-nav-subitem {{ request()->routeIs('admin.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @endpermission
                @endpermission

                {{-- Commission Management (admin/owner only) --}}
                @if($isOwner || $effectiveRole === 'super_admin')
                <div class="corex-nav-sublabel">Commission</div>
                <a href="{{ route('commission.principal') }}" class="corex-nav-subitem {{ request()->routeIs('commission.principal') ? 'active' : '' }}">Commission Overview</a>
                <a href="{{ route('commission.index') }}" class="corex-nav-subitem {{ request()->routeIs('commission.index') ? 'active' : '' }}">Commission Management</a>
                @endif

                {{-- Tools (all roles within AT) --}}
                @permission('access_calculators')
                <div class="corex-nav-sublabel">Tools</div>
                <a href="{{ route('tools.commission') }}" class="corex-nav-subitem {{ request()->routeIs('tools.commission') && !request()->query('section') ? 'active' : '' }}">Commission Calculator</a>
                <a href="{{ route('tools.cma') }}" class="corex-nav-subitem {{ request()->routeIs('tools.cma') ? 'active' : '' }}">CMA Certificate Generator</a>
                <a href="{{ route('tools.commission') }}?section=history" class="corex-nav-subitem {{ request()->routeIs('tools.commission') && request()->query('section') === 'history' ? 'active' : '' }}">History & Logs</a>
                @endpermission
            </div>
        </div>
        @endpermission

        {{-- ═══════════════════════════════════════════
             DOCUMENTS (DocuPerfect — expandable group)
             ═══════════════════════════════════════════ --}}
        @if(auth()->check() && (auth()->user()->hasPermission('access_docuperfect') || auth()->user()->hasPermission('view_agency_documents') || auth()->user()->hasPermission('access_shared_drive')))
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.dashboard'))
        <div>
            <button type="button" @click="push('documents')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'documents' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" />
                </svg>
                <span>Documents</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'documents' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('documents') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Documents</div>
                @permission('create_docuperfect_docs')
                <a href="{{ route('docuperfect.create') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.create') ? 'active' : '' }}">Create Document</a>
                <a href="{{ route('docuperfect.esign.create') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.esign.create') ? 'active' : '' }}">E-Sign Document</a>
                <a href="{{ route('docuperfect.esign.myDocuments') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.esign.myDocuments') && request()->query('filter') !== 'authorisation' ? 'active' : '' }}">My E-Sign Documents</a>
                @if(app(\App\Services\CandidatePractitionerService::class)->canAuthorise(auth()->user()))
                <a href="{{ route('docuperfect.esign.myDocuments', ['filter' => 'authorisation']) }}" class="corex-nav-subitem {{ request()->query('filter') === 'authorisation' ? 'active' : '' }}">Authorise Documents</a>
                @endif
                @endpermission
                @permission('access_docuperfect')
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.dashboard') ? 'active' : '' }}">My Documents</a>
                @endpermission
                @permission('access_docuperfect_packs')
                <a href="{{ route('docuperfect.packs.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.packs.*') ? 'active' : '' }}">Packs</a>
                <a href="{{ route('docuperfect.web-packs.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.web-packs.*') ? 'active' : '' }}">Web Packs</a>
                @endpermission
                @permission('access_clause_library')
                <a href="{{ route('docuperfect.clauses.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.clauses.*') ? 'active' : '' }}">Clause Library</a>
                @endpermission
                @permission('manage_templates')
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.templates.*') ? 'active' : '' }}">Template Management</a>
                <a href="{{ route('docuperfect.field-groups.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.field-groups.*') ? 'active' : '' }}">Field Groups</a>
                <a href="{{ route('docuperfect.settings.namedFields') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.settings.namedFields*') ? 'active' : '' }}">Named Fields</a>
                <a href="{{ route('docuperfect.settings.types') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.settings.types*') ? 'active' : '' }}">Document Types</a>
                <a href="{{ route('docuperfect.import.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.import.*') ? 'active' : '' }}">Import Document</a>
                @endpermission
                @permission('view_agency_documents')
                <a href="{{ route('my-portal.agency-documents') }}" class="corex-nav-subitem {{ request()->routeIs('my-portal.agency-documents*') ? 'active' : '' }}">Agency Documents</a>
                @endpermission
                @permission('access_shared_drive')
                <a href="{{ route('documents.shared-drive.index') }}" class="corex-nav-subitem {{ request()->routeIs('documents.shared-drive.*') ? 'active' : '' }}">Shared Drive</a>
                @endpermission
            </div>
        </div>
        @endif
        @endif


        {{-- ═══════════════════════════════════════════
             NON-GROUPED TOP-LEVEL ITEMS
             ═══════════════════════════════════════════ --}}

        {{-- Compliance (expandable group) --}}
        @permission('access_compliance')
        <div>
            <button type="button" @click="push('compliance')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'compliance' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                <span>Compliance</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'compliance' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('compliance') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Compliance</div>
                <a href="{{ route('compliance.fica.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.fica.*') ? 'active' : '' }}">FICA</a>
                @permission('access_rmcp')
                <a href="{{ route('compliance.rmcp.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.rmcp.*') && !request()->routeIs('compliance.rmcp.dashboard.*') ? 'active' : '' }}">RMCP</a>
                @endpermission
                @permission('access_compliance_dashboard')
                <a href="{{ route('compliance.rmcp.dashboard.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.rmcp.dashboard.*') ? 'active' : '' }}">RMCP Dashboard</a>
                @endpermission
                @permission('access_policy')
                <a href="{{ route('compliance.policy.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.policy.*') && !request()->routeIs('compliance.policy.dashboard.*') ? 'active' : '' }}">Policies</a>
                @endpermission
                @permission('access_compliance_dashboard')
                <a href="{{ route('compliance.policy.dashboard.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.policy.dashboard.*') ? 'active' : '' }}">Policy Register</a>
                @endpermission
                @permission('manage_employee_screenings')
                <a href="{{ route('compliance.screening.dashboard.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.screening.*') || request()->routeIs('compliance.screenings.*') ? 'active' : '' }}">Staff Screening</a>
                @endpermission
                @if($isOwner || $effectiveRole === 'super_admin')
                @php $nonCompliantAgents = \App\Models\User::where('is_active', true)->whereNull('deleted_at')->whereNull('ffc_number')->count(); @endphp
                <a href="{{ route('compliance.agents') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.agents') ? 'active' : '' }}">
                    Agent Compliance
                    @if($nonCompliantAgents > 0)
                    <span class="ml-auto w-2 h-2 rounded-full bg-amber-500 flex-shrink-0 inline-block"></span>
                    @endif
                </a>
                @endif
                @permission('verify_user_documents')
                @php $pendingVerificationCount = cache()->remember('pending-verification-count-' . (auth()->user()->agency_id ?? 'all'), 60, fn() => \App\Models\UserDocument::pending()->count()); @endphp
                <a href="{{ route('compliance.verification.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.verification.*') ? 'active' : '' }}">
                    Verification Queue
                    @if($pendingVerificationCount > 0)
                    <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center rounded-full text-[0.6875rem] font-bold px-1.5" style="min-width:18px; height:18px; background:color-mix(in srgb, var(--ds-amber) 15%, transparent); color:var(--ds-amber);">{{ number_format($pendingVerificationCount) }}</span>
                    @endif
                </a>
                @endpermission
                @permission('manage_agency_compliance')
                <a href="{{ route('compliance.document-types.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.document-types.*') ? 'active' : '' }}">Document Types</a>
                @endpermission
                @if(auth()->user()->hasPermission('manage_agency_compliance') || auth()->user()->hasPermission('manage_branch_compliance'))
                <a href="{{ route('compliance.agency-settings.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.agency-settings.*') ? 'active' : '' }}">Agency Documents</a>
                @endif
                @permission('compliance.whistleblow.view')
                @php
                    $wbPendingCount = cache()->remember('wb-pending-' . (auth()->user()->agency_id ?? 'all'), 60, function () {
                        $q = \App\Models\Compliance\WhistleblowComplaint::where('status', 'pending_approval');
                        $u = auth()->user();
                        if (!$u->hasPermission('compliance.whistleblow.view_all_agency')) {
                            $q->where('reported_by_user_id', $u->id);
                        }
                        return $q->count();
                    });
                @endphp
                <a href="{{ route('compliance.whistleblow.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.whistleblow.*') ? 'active' : '' }}">
                    Compliance Reporting
                    @if($wbPendingCount > 0)
                    <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center rounded-full text-[0.6875rem] font-bold px-1.5" style="min-width:18px; height:18px; background:color-mix(in srgb, var(--ds-amber) 15%, transparent); color:var(--ds-amber);">{{ $wbPendingCount }}</span>
                    @endif
                </a>
                @endpermission
                @permission('compliance.whistleblow.view')
                <a href="{{ route('compliance.communications.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.communications.*') ? 'active' : '' }}">Communications Log</a>
                <a href="{{ route('compliance.seller-info.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.seller-info.*') ? 'active' : '' }}">Send Standalone Info Pack</a>
                @endpermission
                @permission('access_communication_archive')
                <a href="{{ route('compliance.comm-archive.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.comm-archive.*') ? 'active' : '' }}">Communication Archive</a>
                @endpermission
                @permission('view_communication_flag_register')
                <a href="{{ route('compliance.comm-flags.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.comm-flags.*') ? 'active' : '' }}" style="font-size:0.75rem; color:var(--text-muted);">Flag Register</a>
                @endpermission
                @permission('manage_communication_mailboxes')
                <a href="{{ route('compliance.comm-mailboxes.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.comm-mailboxes.*') ? 'active' : '' }}" style="font-size:0.75rem; color:var(--text-muted);">Archive Mailboxes</a>
                @endpermission
            </div>
        </div>
        @endpermission

        {{-- Training (LMS) — moved to agent section above as "Training" --}}

        @endpermission {{-- /sidebar.section.agents --}}

        {{-- ═══════════════════════════════════════════
             BRANCH MANAGER SECTION (placeholder)
             ═══════════════════════════════════════════ --}}
        @permission('sidebar.section.branch_manager')
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Branch Manager</div>

        {{-- Payroll (slide-panel group) --}}
        @if($user && $user->hasAnyPermission(['manage_payroll', 'run_payroll', 'view_payroll_reports']))
        <div>
            <button type="button" @click="push('payroll')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'payroll' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
                </svg>
                <span>Payroll</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'payroll' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('payroll') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Payroll</div>

                @permission('manage_payroll')
                <a href="{{ route('payroll.employees.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.employees.*') ? 'active' : '' }}">Employees</a>
                <a href="{{ route('payroll.earning-types.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.earning-types.*') ? 'active' : '' }}">Earning Types</a>
                <a href="{{ route('payroll.deduction-types.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.deduction-types.*') ? 'active' : '' }}">Deduction Types</a>
                @endpermission

                @permission('run_payroll')
                <a href="{{ route('payroll.runs.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.runs.*') ? 'active' : '' }}">Runs</a>
                @endpermission
            </div>
        </div>
        @endif

        {{-- Leave Management (slide-panel group) --}}
        @if($user && $user->hasAnyPermission(['manage_leave', 'approve_leave', 'view_leave_reports', 'manage_leave_types', 'adjust_leave_balances']))
        <div>
            <button type="button" @click="push('leave')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'leave' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" />
                </svg>
                <span>Leave Management</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'leave' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('leave') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Leave Management</div>

                @permission('manage_leave')
                <a href="{{ route('payroll.leave.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.dashboard') ? 'active' : '' }}">Dashboard</a>
                @endpermission

                @permission('approve_leave')
                <a href="{{ route('payroll.leave.applications.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.applications.*') ? 'active' : '' }}">Applications</a>
                @endpermission

                @permission('manage_leave')
                <a href="{{ route('payroll.leave.balances.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.balances.*') ? 'active' : '' }}">Balances</a>
                @endpermission

                @permission('manage_leave_types')
                <a href="{{ route('payroll.leave.types.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.types.*') ? 'active' : '' }}">Leave Types</a>
                @endpermission

                @permission('view_leave_reports')
                <a href="{{ route('payroll.leave.reports.register') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.reports.*') ? 'active' : '' }}">Reports</a>
                @endpermission

                @permission('manage_leave_types')
                <a href="{{ route('payroll.leave.public-holidays.index') }}" class="corex-nav-subitem {{ request()->routeIs('payroll.leave.public-holidays.*') ? 'active' : '' }}">Public Holidays</a>
                @endpermission
            </div>
        </div>
        @endif
        @endpermission {{-- /sidebar.section.branch_manager --}}

        {{-- ═══════════════════════════════════════════
             TOOLS SECTION
             ═══════════════════════════════════════════ --}}
        @permission('sidebar.section.tools')
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Tools</div>

        {{-- Training Help --}}
        @php
            $trainingUnreadCount = 0;
            if ($user) {
                $trainingRole = $user->effectiveRole();
                $trainingRequired = \App\Models\Training\TrainingDoc::required()->forRole($trainingRole)->pluck('id');
                if ($trainingRequired->isNotEmpty()) {
                    $trainingReadDocIds = \App\Models\Training\TrainingDocRead::where('user_id', $user->id)
                        ->whereIn('doc_id', $trainingRequired)
                        ->whereNotNull('completed_at')
                        ->whereNull('is_outdated_since')
                        ->pluck('doc_id');
                    $trainingUnreadCount = $trainingRequired->diff($trainingReadDocIds)->count();
                }
            }
        @endphp
        @if(\Illuminate\Support\Facades\Route::has('training-help.index'))
        <a href="{{ route('training-help.index') }}" class="corex-nav-item {{ request()->routeIs('training-help.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Training</span>
            @if($trainingUnreadCount > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b);">{{ $trainingUnreadCount }}</span>
            @endif
        </a>
        @endif

        {{-- Ellie AI --}}
        @permission('access_ellie')
        @if(\Illuminate\Support\Facades\Route::has('ellie.index'))
        <a href="{{ route('ellie.index') }}" class="corex-nav-item {{ request()->routeIs('ellie.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.582a.5.5 0 0 1 0 .962L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
            </svg>
            <span>Ellie AI</span>
        </a>
        @endif
        @endpermission

        {{-- Ad Manager --}}
        @permission('access_ad_manager')
        @if(\Illuminate\Support\Facades\Route::has('tools.ad-manager'))
        <a href="{{ route('tools.ad-manager') }}" class="corex-nav-item {{ request()->routeIs('tools.ad-manager') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m3 11 18-5v12L3 14v-3z"/>
                <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>
            </svg>
            <span>Ad Manager</span>
        </a>
        @endif
        @endpermission

        {{-- Trust Interest (slide-panel group) --}}
        @if($user && $user->hasAnyPermission(['access_trust_interest', 'access_deposit_calculator', 'access_deposit_calc_history', 'access_calculators']))
        <div>
            <button type="button" @click="push('trust-interest')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'trust-interest' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 17a5 5 0 0 0 10 0c0-2.76-2.24-5-5-5s-5 2.24-5 5Z"/>
                    <path d="M7 17v-2"/>
                    <path d="M12 17a5 5 0 0 0 10 0c0-2.76-2.24-5-5-5s-5 2.24-5 5Z"/>
                    <path d="M17 17v-2"/>
                    <path d="M7 7h10"/>
                    <path d="M12 3v4"/>
                </svg>
                <span>Trust Interest</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'trust-interest' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('trust-interest') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Trust Interest</div>

                @permission('access_trust_interest')
                @if(\Illuminate\Support\Facades\Route::has('admin.deposit-trust-interest.index'))
                <a href="{{ route('admin.deposit-trust-interest.index') }}" class="corex-nav-subitem {{ request()->routeIs('admin.deposit-trust-interest.*') ? 'active' : '' }}">Trust Interest Register</a>
                @endif
                @endpermission

                @permission('access_deposit_calculator')
                @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.index'))
                <a href="{{ route('deposit-interest-calculator.index') }}" class="corex-nav-subitem {{ request()->routeIs('deposit-interest-calculator.index') || request()->routeIs('deposit-interest-calculator.calculate') || request()->routeIs('deposit-interest-calculator.show') ? 'active' : '' }}">Deposit Interest Calc</a>
                @endif
                @endpermission

                @permission('access_deposit_calc_history')
                @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.history'))
                <a href="{{ route('deposit-interest-calculator.history') }}" class="corex-nav-subitem {{ request()->routeIs('deposit-interest-calculator.history') ? 'active' : '' }}">Calculation History</a>
                @endif
                @endpermission

                @permission('access_calculators')
                @if(\Illuminate\Support\Facades\Route::has('calculators.index'))
                <a href="{{ route('calculators.index') }}" class="corex-nav-subitem {{ request()->routeIs('calculators.*') ? 'active' : '' }}">Calculators</a>
                @endif
                @endpermission
            </div>
        </div>
        @endif

        {{-- PDF Suite --}}
        @if(auth()->check() && (auth()->user()->hasPermission('access_pdf_suite') || auth()->user()->hasPermission('access_pdf_splitter')))
        @if(\Illuminate\Support\Facades\Route::has('tools.pdf_suite.hub'))
        <a href="{{ route('tools.pdf_suite.hub') }}" class="corex-nav-item {{ (request()->routeIs('tools.pdf_suite.*') || request()->routeIs('tools.pdf_splitter.*')) ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M8 13h8"/>
                <path d="M8 17h8"/>
                <path d="M8 9h2"/>
            </svg>
            <span>PDF Suite</span>
        </a>
        @endif
        @endif

        {{-- Image Converter --}}
        @permission('access_image_converter')
        @if(\Illuminate\Support\Facades\Route::has('tools.image_converter.index'))
        <a href="{{ route('tools.image_converter.index') }}" class="corex-nav-item {{ request()->routeIs('tools.image_converter.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <circle cx="9" cy="9" r="2"/>
                <path d="m21 15-3.1-3.1a2 2 0 0 0-2.81.01L6 21"/>
            </svg>
            <span>Image Converter</span>
        </a>
        @endif
        @endpermission

        {{-- Document Library --}}
        @permission('access_document_library')
        @if(config('features.document_library_v1'))
        <a href="{{ route('documents.library.index') }}" class="corex-nav-item {{ request()->routeIs('documents.library.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
            </svg>
            <span>Document Library</span>
        </a>
        @endif
        @endpermission

        {{-- Filing Register --}}
        @permission('access_filing_register')
        <a href="{{ route('filing-register.index') }}" class="corex-nav-item {{ request()->routeIs('filing-register.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
            </svg>
            <span>Filing Register</span>
        </a>
        @endpermission

        @endpermission {{-- /sidebar.section.tools --}}

        {{-- ═══════════════════════════════════════════
             ADMIN SECTION (agency-level admins — BMs, super_admin)
             ═══════════════════════════════════════════ --}}
        @permission('sidebar.section.admin')
        @if($user && $user->hasAnyPermission(['access_knowledge_base', 'access_role_manager', 'access_finance_engine', 'access_settings', 'access_soft_deletes', 'marketing_suppressions.view', 'manage_payroll', 'run_payroll', 'view_payroll_reports']))
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Admin</div>

        {{-- Company Settings --}}
        <a href="{{ route('admin.company-settings') }}" class="corex-nav-item {{ request()->routeIs('admin.company-settings*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Company Settings</span>
        </a>

        {{-- Knowledge Base --}}
        @permission('access_knowledge_base')
        <a href="{{ route('admin.knowledge.index') }}" class="corex-nav-item {{ request()->routeIs('admin.knowledge.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Knowledge Base</span>
        </a>
        @endpermission

        {{-- Role Manager --}}
        @permission('access_role_manager')
        <a href="{{ route('corex.role-manager') }}" class="corex-nav-item {{ request()->routeIs('corex.role-manager*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Role Manager</span>
        </a>
        @endpermission

        {{-- Soft Deletes Register --}}
        @permission('access_soft_deletes')
        <a href="{{ route('admin.soft-deletes.index') }}" class="corex-nav-item {{ request()->routeIs('admin.soft-deletes.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5 4.5 19.5a1.5 1.5 0 0 0 1.5 1.4h12a1.5 1.5 0 0 0 1.5-1.4L21 7.5M3 7.5h18M3 7.5l1-3h16l1 3M9.75 11.25l4.5 4.5m0-4.5-4.5 4.5" />
            </svg>
            <span>Soft Deletes</span>
        </a>
        @endpermission

        {{-- Marketing Suppressions (AT-49) --}}
        @permission('marketing_suppressions.view')
        <a href="{{ route('admin.marketing-suppressions.index') }}" class="corex-nav-item {{ request()->routeIs('admin.marketing-suppressions.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75M3 3l18 18" />
            </svg>
            <span>Marketing Suppressions</span>
        </a>
        @endpermission

        {{-- Training Management (admin/owner only) --}}
        @if($isOwner || $effectiveRole === 'super_admin')
        <a href="{{ route('training.manage') }}" class="corex-nav-item {{ request()->routeIs('training.manage', 'training.create-course', 'training.edit-course', 'training.create-lesson', 'training.edit-lesson', 'training.store-course', 'training.update-course') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Training Mgmt</span>
        </a>
        @endif

        {{-- Onboarding (admin/owner only) --}}
        @if($isOwner || $effectiveRole === 'super_admin')
        @php $onboardingCount = \App\Models\AgentApplication::pending()->count(); @endphp
        <a href="{{ route('onboarding.index') }}" class="corex-nav-item {{ request()->routeIs('onboarding.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
            </svg>
            <span>Onboarding</span>
            @if($onboardingCount > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold" style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color:var(--brand-icon, #0ea5e9);">{{ number_format($onboardingCount) }}</span>
            @endif
        </a>
        @endif

        {{-- Finance Engine --}}
        @permission('access_finance_engine')
        <a href="{{ route('admin.finance.definitions') }}" class="corex-nav-item {{ request()->routeIs('admin.finance.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z" />
            </svg>
            <span>Finance Engine</span>
        </a>
        @endpermission

        {{-- Contact Governance + Leave Visibility (admin/super_admin/owner) --}}
        @if($user && in_array($user->role, ['admin', 'super_admin', 'owner']))
        <a href="{{ route('command-center.settings.contact-governance') }}" class="corex-nav-item {{ request()->routeIs('command-center.settings.contact-governance*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
            </svg>
            <span>Contact Governance</span>
        </a>
        @endif

        {{-- Staff Take-On --}}
        @permission('manage_staff_take_on')
        <a href="{{ route('staff-take-on.index') }}" class="corex-nav-item {{ request()->routeIs('staff-take-on.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
            </svg>
            <span>Staff Take-On</span>
        </a>
        @endpermission


        {{-- Deals (slide-panel group) --}}
        @if($user && $user->hasAnyPermission(['access_deal_register_v2', 'deals_v2.create', 'deals_v2.manage_pipeline']))
        <div>
            <button type="button" @click="push('deals-v2')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'deals-v2' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
                <span>Deals</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'deals-v2' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('deals-v2') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Deals</div>

                @permission('deals_v2.create')
                @if(\Illuminate\Support\Facades\Route::has('deals-v2.create'))
                <a href="{{ route('deals-v2.create') }}" class="corex-nav-subitem {{ request()->routeIs('deals-v2.create') ? 'active' : '' }}">New Deal</a>
                @endif
                @endpermission

                @permission('access_deal_register_v2')
                @if(\Illuminate\Support\Facades\Route::has('deals-v2.index'))
                <a href="{{ route('deals-v2.index') }}" class="corex-nav-subitem {{ request()->routeIs('deals-v2.index') || request()->routeIs('deals-v2.show') ? 'active' : '' }}">Deal Register</a>
                @endif
                @endpermission

                @permission('deals_v2.manage_pipeline')
                @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
                <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-nav-subitem {{ request()->routeIs('deals-v2.pipeline.*') ? 'active' : '' }}">Pipeline Setup</a>
                @endif
                @endpermission
                @permission('deals_v2.manage_suppliers')
                @if(\Illuminate\Support\Facades\Route::has('deals-v2.suppliers.index'))
                <a href="{{ route('deals-v2.suppliers.index') }}" class="corex-nav-subitem {{ request()->routeIs('deals-v2.suppliers.*') ? 'active' : '' }}">Supplier Directory</a>
                @endif
                @endpermission
            </div>
        </div>
        @endif

        {{-- Settings --}}
        @permission('access_settings')
        <a href="{{ route('corex.settings') }}" class="corex-nav-item {{ (request()->routeIs('corex.settings*') || request()->routeIs('admin.settings.document-types.*') || request()->routeIs('admin.p24-suburbs.*')) ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Settings</span>
        </a>
        @endpermission

        {{-- Email Setup (AT-37) — per-user Communication Archive capture --}}
        @permission('manage_communication_mailboxes')
        <a href="{{ route('settings.email-setup.index') }}" class="corex-nav-item {{ request()->routeIs('settings.email-setup.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
            </svg>
            <span>Email Setup</span>
        </a>
        @endpermission
        @endif
        @endpermission {{-- /sidebar.section.admin --}}

        {{-- ═══════════════════════════════════════════
             SYSTEM DEVELOPER (System Owners only — placeholder)
             ═══════════════════════════════════════════ --}}
        @if($isOwner)
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">System Developer</div>

        {{-- Agency Management --}}
        <a href="{{ route('agencies.index') }}" class="corex-nav-item {{ request()->routeIs('agencies.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
            </svg>
            <span>Agency Management</span>
        </a>

        {{-- PP Agents now lives under the Importer slide-panel group. --}}

        {{-- Duplicate Cleanup --}}
        <a href="{{ route('command-center.admin.duplicate-cleanup') }}" class="corex-nav-item {{ request()->routeIs('command-center.admin.duplicate-cleanup*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" />
            </svg>
            <span>Duplicate Cleanup</span>
        </a>

        {{-- API Catalog --}}
        <a href="{{ route('admin.api.catalog') }}" class="corex-nav-item {{ request()->routeIs('admin.api.catalog') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0021 18V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v12a2.25 2.25 0 002.25 2.25z" />
            </svg>
            <span>API</span>
        </a>

        {{-- AI Usage (MIC Phase B2) --}}
        @permission('mic.view_ai_costs')
        <a href="{{ route('admin.ai-usage.index') }}" class="corex-nav-item {{ request()->routeIs('admin.ai-usage.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5l4.5-4.5 4.5 4.5L21 6m0 0v6m0-6h-6" />
            </svg>
            <span>AI Usage</span>
        </a>
        @endpermission

        {{-- Dev Settings --}}
        <a href="{{ route('admin.dev-settings.index') }}" class="corex-nav-item {{ request()->routeIs('admin.dev-settings.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3" />
            </svg>
            <span>Dev Settings</span>
        </a>

        {{-- Integration (slide-panel group: Meta config + public legal pages) --}}
        <div>
            <button type="button" @click="push('integration')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'integration' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                </svg>
                <span>Integration</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'integration' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('integration') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Integration</div>
                <a href="{{ route('admin.integrations.index') }}" class="corex-nav-subitem {{ request()->routeIs('admin.integrations.*') ? 'active' : '' }}">Meta (Facebook &amp; Instagram)</a>
                <a href="{{ route('public.platform-privacy') }}" target="_blank" rel="noopener" class="corex-nav-subitem">Privacy Policy ↗</a>
                <a href="{{ route('public.data-deletion') }}" target="_blank" rel="noopener" class="corex-nav-subitem">Data Deletion ↗</a>
            </div>
        </div>

        {{-- Developer Users --}}
        <a href="{{ route('admin.developer-users.index') }}" class="corex-nav-item {{ request()->routeIs('admin.developer-users.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
            <span>Developer Users</span>
        </a>

{{-- Feedback Reports --}}
        @php $feedbackCount = DB::table('feedback_reports')->where('agency_id', auth()->user()->effectiveAgencyId() ?? 1)->where('status', 'new')->count(); @endphp
        <a href="{{ route('command-center.feedback-reports') }}" class="corex-nav-item {{ request()->routeIs('command-center.feedback-reports*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
            <span>Feedback Reports</span>
            @if($feedbackCount > 0)<span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold" style="background:#ef444420;color:#ef4444;">{{ $feedbackCount }}</span>@endif
        </a>

        {{-- Importer (slide-panel group: P24 Importer + Property Review) --}}
        <div>
            <button type="button" @click="push('importer')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'importer' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
                </svg>
                <span>Importer</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'importer' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('importer') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Importer</div>
                <a href="{{ route('admin.importer.index') }}" class="corex-nav-subitem {{ request()->routeIs('admin.importer.index') ? 'active' : '' }}">P24 Importer</a>
                <a href="{{ route('admin.importer.review') }}" class="corex-nav-subitem {{ request()->routeIs('admin.importer.review') ? 'active' : '' }}">Property Review</a>
                <a href="{{ route('admin.importer.p24-locations') }}" class="corex-nav-subitem {{ request()->routeIs('admin.importer.p24-locations') ? 'active' : '' }}">P24 Locations</a>
                <a href="{{ route('admin.importer.pp-locations') }}" class="corex-nav-subitem {{ request()->routeIs('admin.importer.pp-locations') ? 'active' : '' }}">PP Locations</a>
                @if(\Illuminate\Support\Facades\Route::has('admin.pp.agent-mapping'))
                <a href="{{ route('admin.pp.agent-mapping') }}" class="corex-nav-subitem {{ request()->routeIs('admin.pp.*') ? 'active' : '' }}">PP Agents</a>
                @endif
            </div>
        </div>

        {{-- Fault Reports --}}
        @php $faultNewCount = \App\Models\FaultReport::whereIn('status', ['new', 'investigating'])->count(); @endphp
        <a href="{{ route('admin.fault-reports') }}" class="corex-nav-item {{ request()->routeIs('admin.fault-reports*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <span>Fault Reports</span>
            @if($faultNewCount > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold" style="background:color-mix(in srgb, var(--ds-crimson) 15%, transparent); color:var(--ds-crimson);">{{ number_format($faultNewCount) }}</span>
            @endif
        </a>

        {{-- Sales Documents — hidden from agency users, visible to system owners only --}}
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.sales'))
        <a href="{{ route('docuperfect.sales') }}" class="corex-nav-item {{ request()->routeIs('docuperfect.sales*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v7.5M12 12.75h3m-3 0h-3m-2.25 0H5.625c-.621 0-1.125-.504-1.125-1.125V4.125c0-.621.504-1.125 1.125-1.125h5.25a2.25 2.25 0 0 1 2.25 2.25v1.5m-6 9V21m0-6.75h9" />
            </svg>
            <span>Sales Documents</span>
            <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-semibold uppercase tracking-wider flex-shrink-0"
                  style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); letter-spacing:0.06em;"
                  title="Hidden from agency users — visible to system owners only">Hidden</span>
        </a>
        @endif

        {{-- Revenue Share — hidden from agency users, visible to system owners only --}}
        <a href="{{ route('revenue-share.calculator') }}"
           class="corex-nav-item {{ request()->routeIs('revenue-share.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
            </svg>
            <span>Revenue Share</span>
            <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-semibold uppercase tracking-wider flex-shrink-0"
                  style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); letter-spacing:0.06em;"
                  title="Hidden from agency users — visible to system owners only">Hidden</span>
        </a>

        {{-- Training — hidden from agency users, visible to system owners only --}}
        <a href="{{ route('training.index') }}"
           class="corex-nav-item {{ request()->routeIs('training.index', 'training.show') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
            </svg>
            <span>Training</span>
            <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-semibold uppercase tracking-wider flex-shrink-0"
                  style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); letter-spacing:0.06em;"
                  title="Hidden from agency users — visible to system owners only">Hidden</span>
        </a>

        {{-- Rentals — hidden from agency users, visible to system owners only --}}
        @permission('view_rentals')
        <div>
            <button type="button" @click="push('rentals')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'rentals' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
                </svg>
                <span>Rentals</span>
                <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-semibold uppercase tracking-wider flex-shrink-0"
                      style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); letter-spacing:0.06em;"
                      title="Hidden from agency users — visible to system owners only">Hidden</span>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'rentals' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('rentals') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Rentals</div>
                <a href="{{ route('rentals.index') }}" class="corex-nav-subitem {{ request()->routeIs('rentals.*') ? 'active' : '' }}">Rentals</a>
                @permission('view_rentals')
                <a href="{{ route('rental.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('rental.dashboard') ? 'active' : '' }}">Dashboard</a>
                @endpermission
                @permission('access_rental_signatures')
                <a href="{{ route('rental.signatures') }}" class="corex-nav-subitem {{ request()->routeIs('rental.signatures*') ? 'active' : '' }}">Electronic Signatures</a>
                @endpermission
                @permission('view_rentals')
                <a href="{{ route('rental.active-leases') }}" class="corex-nav-subitem {{ request()->routeIs('rental.active-leases') ? 'active' : '' }}">Active Leases</a>
                <a href="{{ route('rental.expired-leases') }}" class="corex-nav-subitem {{ request()->routeIs('rental.expired-leases') ? 'active' : '' }}">Expired Leases</a>
                @endpermission
            </div>
        </div>
        @endpermission

        {{-- Evaluation — hidden from agency users, visible to system owners only --}}
        <div>
            <button type="button" @click="push('evaluation')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'evaluation' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
                </svg>
                <span>Evaluation</span>
                <span class="ml-auto inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-semibold uppercase tracking-wider flex-shrink-0"
                      style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); letter-spacing:0.06em;"
                      title="Hidden from agency users — visible to system owners only">Hidden</span>
                <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div class="corex-nav-panel {{ $activeGroup === 'evaluation' ? 'is-open' : '' }}" :class="{ 'is-open': inStack('evaluation') }">
                <button type="button" @click="pop()" class="corex-nav-back">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    <span>Back</span>
                </button>
                <div class="corex-nav-panel-title">Evaluation</div>
                <a href="{{ route('evaluation.index') }}#tab=property" class="corex-nav-subitem {{ request()->routeIs('evaluation.*') ? 'active' : '' }}">Property Report</a>
                <a href="{{ route('evaluation.index') }}#tab=suburb" class="corex-nav-subitem">Suburb Report</a>
                <a href="{{ route('evaluation.index') }}#tab=town" class="corex-nav-subitem">Town Report</a>
                <a href="{{ route('evaluation.index') }}#tab=street" class="corex-nav-subitem">Street Report</a>
                <a href="{{ route('evaluation.index') }}#tab=transfer" class="corex-nav-subitem">Transfer Report</a>
                {{-- Phase D1 — Prospecting evaluation tab removed; the new
                     MIC Analyse tab is now the canonical surface for that data. --}}
            </div>
        </div>
        @endif
        </div>{{-- /corex-nav-root --}}
    </nav>

    {{-- ═══════════════════════════════════════════
         USER PROFILE + IMPERSONATION
         ═══════════════════════════════════════════ --}}
    @auth
    <div class="corex-user-section" x-data="{ userMenu: false, switchPanel: false }">
        {{-- Impersonation banner --}}
        @if($isImpersonating)
        <div class="corex-impersonate-banner">
            <div class="text-[11px]" style="color:var(--ds-amber);">Viewing as <strong>{{ $user->name ?? 'User' }}</strong></div>
            <form method="POST" action="{{ route('impersonate.stop') }}" class="mt-1">
                @csrf
                <button type="submit" class="corex-impersonate-btn">Switch back to {{ $impersonatorName ?? 'admin' }}</button>
            </form>
        </div>
        @endif

        <div class="corex-user-profile">
            <div class="corex-user-avatar">{{ $userInitials }}</div>
            <div class="flex-1 min-w-0">
                <div class="corex-user-name">{{ $user->name }}</div>
                <div class="corex-user-role">{{ $userRole }}</div>
            </div>
            {{-- Theme Toggle --}}
            <button type="button" class="corex-theme-toggle" id="corexThemeToggle" title="Toggle light/dark theme" onclick="(function(){var d=document.documentElement,dark=d.classList.toggle('dark');var t=dark?'dark':'light';localStorage.setItem('corex-theme',t);fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:t})});})()">
                <svg class="corex-icon-moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                </svg>
                <svg class="corex-icon-sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                </svg>
            </button>
            <button type="button" @click="userMenu = !userMenu" class="corex-user-menu-btn" title="User menu">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:1rem;height:1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" /></svg>
            </button>
        </div>

        {{-- Dropdown menu --}}
        <div x-show="userMenu" x-cloak @click.outside="userMenu = false" x-transition class="corex-user-dropdown">
            <a href="{{ route('agent.portal') }}#profile" class="corex-user-dropdown-item">Profile</a>
            @if($canSwitchUsers)
            <button type="button" @click="switchPanel = !switchPanel; userMenu = false" class="corex-user-dropdown-item w-full text-left">Switch User</button>
            @endif
            @if(\App\Http\Controllers\Auth\DemoLoginController::isEnabled())
            <a href="{{ route('demo.owner.login') }}" class="corex-user-dropdown-item w-full text-left block">System Owner</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="corex-user-dropdown-item w-full text-left">Log Out</button>
            </form>
        </div>

        {{-- Switch user panel --}}
        @if($canSwitchUsers)
        <div x-show="switchPanel" x-cloak @click.outside="switchPanel = false" x-transition class="corex-switch-panel">
            <div class="text-[0.6875rem] uppercase tracking-wider font-semibold px-2 py-1" style="color:var(--text-muted);">Switch User</div>
            <div class="corex-switch-list">
                @foreach($switchUsers as $su)
                    @if((int)$su->id !== (int)($user->id ?? 0))
                    <form method="POST" action="{{ route('impersonate.start', ['user' => $su->id]) }}">
                        @csrf
                        <button type="submit" class="corex-switch-item">
                            <div class="text-xs" style="color:var(--text-primary);">{{ $su->name }}</div>
                            <div class="text-[0.6875rem]" style="color:var(--text-muted);">{{ $su->email }} · {{ $su->role }}</div>
                        </button>
                    </form>
                    @endif
                @endforeach
            </div>
        </div>
        @endif
    </div>
    @endauth
</div>

<script>
// ── Demo sidebar curation (presentation-only) ──────────────────────────
// Exposed always so the Dev Settings curator can pre-check; the removal pass
// below only acts for demo-agency members. See .ai/specs/demo-sidebar-curation.md
window.__demoNavApply  = @json($_demoNavApply);
window.__demoNavHidden = @json($_demoHiddenNav);
(function () {
    if (!window.__demoNavApply) return;
    var hidden = window.__demoNavHidden || [];
    if (!hidden.length) return;

    function run() {
        var root = document.querySelector('.corex-sidebar .corex-nav-root');
        if (!root) return;

        var groupKeys = {}, pathKeys = {};
        hidden.forEach(function (k) {
            if (typeof k !== 'string') return;
            if (k.indexOf('g:') === 0) groupKeys[k.slice(2)] = true;
            else if (k.indexOf('p:') === 0) pathKeys[k.slice(2)] = true;
        });

        // Remove whole expandable sections (g:<groupKey>). The toggle button's
        // Alpine @click carries push('<groupKey>'); its wrapper <div> holds both
        // the button and its slide panel.
        root.querySelectorAll('button.corex-nav-group-toggle').forEach(function (btn) {
            var click = btn.getAttribute('@click') || btn.getAttribute('x-on:click') || '';
            var m = click.match(/push\(\s*'([^']+)'\s*\)/);
            if (m && groupKeys[m[1]]) {
                var wrap = btn.closest('div');
                if (wrap) wrap.remove();
            }
        });

        // Remove individual pages / sub-pages (p:<pathname>), tracking which
        // panels we emptied so only those can collapse.
        var touchedPanels = [];
        root.querySelectorAll('a.corex-nav-item, a.corex-nav-subitem').forEach(function (a) {
            var href = a.getAttribute('href');
            if (!href) return;
            var path;
            try { path = new URL(href, location.origin).pathname; } catch (e) { return; }
            if (!pathKeys[path]) return;
            var panel = a.closest('.corex-nav-panel');
            if (panel && touchedPanels.indexOf(panel) === -1) touchedPanels.push(panel);
            a.remove();
        });

        // Collapse a section only if WE removed its last sub-item.
        touchedPanels.forEach(function (panel) {
            if (!panel.querySelector('a.corex-nav-subitem')) {
                var wrap = panel.parentElement; // group wrapper (button + panel)
                if (wrap) wrap.remove();
            }
        });

        // Drop the stale search cache so search mirrors the curated nav.
        if (window.CorexNavSearch && window.CorexNavSearch.refresh) window.CorexNavSearch.refresh();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
})();
(function () {
    function sortKey(el) {
        // Use the first text node's content so a trailing badge (e.g. "Invitations [3]")
        // doesn't poison the sort key. Fall back to trimmed textContent.
        for (const node of el.childNodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                const t = node.textContent.trim();
                if (t) return t.toLowerCase();
            }
        }
        return (el.textContent || '').trim().toLowerCase();
    }

    function sortPanels() {
        document.querySelectorAll('.corex-sidebar .corex-nav-panel').forEach(panel => {
            if (panel.hasAttribute('data-manual-order')) return;
            // Sort runs of .corex-nav-subitem siblings, treating .corex-nav-sublabel
            // (and any other element type) as a section boundary so grouped items
            // remain under their heading.
            const children = Array.from(panel.children);
            let group = [];
            const flush = () => {
                if (group.length < 2) { group = []; return; }
                const sorted = group.slice().sort((a, b) => sortKey(a).localeCompare(sortKey(b)));
                const anchor = group[group.length - 1].nextSibling;
                sorted.forEach(el => panel.insertBefore(el, anchor));
                group = [];
            };
            for (const child of children) {
                if (child.classList && child.classList.contains('corex-nav-subitem')) {
                    group.push(child);
                } else {
                    flush();
                }
            }
            flush();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sortPanels);
    } else {
        sortPanels();
    }
})();

// ── Sidebar search index ──────────────────────────────────────────────
// Builds a searchable index straight from the rendered nav so it always
// mirrors exactly what THIS user can see (permissions, feature flags, route
// availability are all already baked into the DOM). Headings (group toggles)
// open their slide-panel; every link jumps to its page.
window.CorexNavSearch = (function () {
    let cache = null;

    function firstText(el) {
        for (const node of el.childNodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                const t = node.textContent.trim();
                if (t) return t;
            }
        }
        return (el.textContent || '').trim();
    }

    function build() {
        const root = document.querySelector('.corex-sidebar .corex-nav-root');
        if (!root) return [];
        const items = [];
        const seen = new Set();
        const add = (label, href, parent, group) => {
            label = (label || '').trim();
            if (!label) return;
            const key = (group ? 'g:' + group : 'h:' + (href || '')) + '|' + label + '|' + (parent || '');
            if (seen.has(key)) return;
            seen.add(key);
            items.push({ label: label, href: href || null, parent: parent || '', group: group || null });
        };

        // Top-level page links (My Portal, My Earnings, Training, …)
        root.querySelectorAll('a.corex-nav-item').forEach(function (a) {
            add(firstText(a), a.getAttribute('href'), '', null);
        });

        // Group headings — buttons that slide open a panel (Real Estate, Agency
        // Tracker, …). The group key lives in the Alpine @click="push('key')".
        root.querySelectorAll('button.corex-nav-group-toggle').forEach(function (btn) {
            const click = btn.getAttribute('@click') || btn.getAttribute('x-on:click') || '';
            const m = click.match(/push\(\s*'([^']+)'\s*\)/);
            if (m) add(firstText(btn), null, '', m[1]);
        });

        // Sub-section links inside every panel, tagged with their heading.
        root.querySelectorAll('.corex-nav-panel').forEach(function (panel) {
            const titleEl = panel.querySelector('.corex-nav-panel-title');
            const parent = titleEl ? firstText(titleEl) : '';
            panel.querySelectorAll('a.corex-nav-subitem').forEach(function (a) {
                add(firstText(a), a.getAttribute('href'), parent, null);
            });
        });

        return items;
    }

    function search(q) {
        q = (q || '').trim().toLowerCase();
        if (!q) return [];
        if (!cache) cache = build();
        const scored = [];
        for (const it of cache) {
            const label = it.label.toLowerCase();
            const parent = (it.parent || '').toLowerCase();
            let score = -1;
            if (label === q) score = 0;
            else if (label.startsWith(q)) score = 1;
            else if (label.indexOf(q) >= 0) score = 2;
            else if (parent.startsWith(q)) score = 3;
            else if (parent.indexOf(q) >= 0) score = 4;
            if (score >= 0) scored.push({ it: it, score: score });
        }
        scored.sort(function (a, b) { return a.score - b.score || a.it.label.localeCompare(b.it.label); });
        return scored.slice(0, 10).map(function (s, i) {
            return {
                label: s.it.label,
                href: s.it.href,
                parent: s.it.parent,
                group: s.it.group,
                key: (s.it.href || s.it.group || s.it.label) + '#' + i,
            };
        });
    }

    return { build: build, search: search, refresh: function () { cache = null; } };
})();
</script>

