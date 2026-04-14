@php
    $user = auth()->user();
    $effectiveRole = $user ? $user->effectiveRole() : 'agent';
    $effectiveBranchId = $user?->effectiveBranchId();

    $userInitials = $user ? collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') : '??';
    $userRoleModel = $user ? \App\Models\Role::allRoles()->firstWhere('name', $effectiveRole) : null;
    $userRole = $userRoleModel?->label ?? ($user ? ucfirst(str_replace('_', ' ', $effectiveRole)) : '');

    // Owner role & Agency Switcher
    $isOwner = $user && $user->isOwnerRole();
    $activeAgencyId = session('active_agency_id');
    $agencies = $isOwner ? \App\Models\Agency::orderBy('name')->get() : collect();
    $activeAgency = ($isOwner && $activeAgencyId) ? $agencies->find($activeAgencyId) : null;

    // Current user's agency (for all users)
    $_userAgencyId = $user?->effectiveAgencyId();
    $_userAgency = $_userAgencyId ? \App\Models\Agency::find($_userAgencyId) : null;

    // Impersonation state
    $impersonatorId  = (int) session('impersonator_id', 0);
    $isImpersonating = $impersonatorId > 0;
    $canSwitchUsers  = !$isImpersonating && ($user && $user->hasPermission('impersonate_users'));
    $impersonatorName = null;
    if ($isImpersonating) {
        $impersonatorName = \Illuminate\Support\Facades\DB::table('users')->where('id', $impersonatorId)->value('name');
    }
    $switchUsers = collect();
    if ($canSwitchUsers) {
        $switchUsers = \Illuminate\Support\Facades\DB::table('users')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id','name','email','role']);
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
    } elseif (request()->routeIs('docuperfect.*') && !request()->routeIs('docuperfect.sales*', 'docuperfect.rental*')) {
        $activeGroup = 'documents';
    } elseif (request()->routeIs('rental.*')) {
        $activeGroup = 'rentals';
    } elseif (request()->routeIs('compliance.*')) {
        $activeGroup = 'compliance';
    } elseif (request()->routeIs('command-center.*', 'corex.dashboard')) {
        $activeGroup = 'command-center';
    }
@endphp

<div class="corex-sidebar">
    {{-- Logo --}}
    <div class="corex-sidebar-logo">
        CoreX <span>Os</span>
    </div>
    @if($_userAgency)
    <div class="px-4 -mt-1 pb-2">
        <div class="text-[10px] font-semibold uppercase tracking-widest text-center truncate" style="color:var(--text-muted); opacity:0.6;">
            {{ $_userAgency->name }}
        </div>
    </div>
    @endif

    {{-- Agency Switcher (owner role only) --}}
    @if($isOwner)
    <div x-data="{ agencyOpen: false }" class="px-3 pb-2">
        <button type="button" @click="agencyOpen = !agencyOpen"
                class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-colors"
                style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 flex-shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
            </svg>
            <span class="flex-1 text-left truncate">{{ $activeAgency ? $activeAgency->name : 'All Agencies' }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 flex-shrink-0 transition-transform duration-150" :class="agencyOpen && 'rotate-90'"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>
        <div x-show="agencyOpen" x-cloak @click.outside="agencyOpen = false" x-transition
             class="mt-1 rounded-lg overflow-hidden shadow-lg"
             style="background:var(--surface-2, #1a1e28); border:1px solid var(--border);">
            <form method="POST" action="{{ route('agency.switch.clear') }}">
                @csrf
                <button type="submit" class="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-white/10 {{ !$activeAgencyId ? 'font-semibold' : 'text-white/70' }}" @if(!$activeAgencyId) style="color:var(--brand-icon, #0ea5e9);" @endif>
                    All Agencies
                </button>
            </form>
            @foreach($agencies as $ag)
            <form method="POST" action="{{ route('agency.switch', $ag) }}">
                @csrf
                <button type="submit" class="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-white/10 {{ (int)$activeAgencyId === $ag->id ? 'font-semibold' : 'text-white/70' }}" @if((int)$activeAgencyId === $ag->id) style="color:var(--brand-icon, #0ea5e9);" @endif>
                    {{ $ag->name }}
                </button>
            </form>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Navigation — single Alpine state for group toggling --}}
    <nav class="flex-1 py-2 overflow-y-auto min-h-0"
         x-data="{ openGroup: '{{ $activeGroup }}', toggle(g) { this.openGroup = this.openGroup === g ? null : g } }"
         x-init="$el.scrollTop = sessionStorage.getItem('sidebarScroll') || 0"
         @scroll.debounce.100ms="sessionStorage.setItem('sidebarScroll', $el.scrollTop)">

        {{-- ═══════════════════════════════════════════
             DASHBOARD (expandable — Calendar & Tasks as sub-items)
             ═══════════════════════════════════════════ --}}
        @permission('view_dashboard')
        <div>
            <button type="button" @click="toggle('command-center')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'command-center' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
                </svg>
                <span>Dashboard</span>
                <svg class="corex-chevron transition-transform duration-200" :class="(openGroup === 'command-center') && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'command-center'" @unless($activeGroup === 'command-center') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">

                <a href="{{ route('corex.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('corex.dashboard') ? 'active' : '' }}">Overview</a>
                <a href="{{ route('command-center.calendar') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.calendar*') ? 'active' : '' }}">Calendar</a>
                <a href="{{ route('command-center.tasks') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.tasks*') ? 'active' : '' }}">Tasks</a>
                <a href="{{ route('command-center.user-settings') }}" class="corex-nav-subitem {{ request()->routeIs('command-center.user-settings*') ? 'active' : '' }}">User Settings</a>
            </div>
        </div>
        @endpermission

        {{-- ═══════════════════════════════════════════
             MY PORTAL
             ═══════════════════════════════════════════ --}}
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

        {{-- ═══════════════════════════════════════════
             REVENUE SHARE
             ═══════════════════════════════════════════ --}}
        <a href="{{ route('revenue-share.calculator') }}"
           class="corex-nav-item {{ request()->routeIs('revenue-share.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
            </svg>
            <span>Revenue Share</span>
        </a>

        {{-- ═══════════════════════════════════════════
             TRAINING
             ═══════════════════════════════════════════ --}}
        @php
            $trainingIncomplete = 0;
            if ($user) {
                $trainingIncomplete = \App\Models\TrainingCourse::where('is_required', true)
                    ->published()
                    ->whereDoesntHave('completions', fn($q) => $q->where('user_id', $user->id))
                    ->count();
            }
        @endphp
        <a href="{{ route('training.index') }}"
           class="corex-nav-item {{ request()->routeIs('training.index', 'training.show') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
            </svg>
            <span>Training</span>
            @if($trainingIncomplete > 0)
            <span class="ml-auto w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
            @endif
        </a>

        {{-- ═══════════════════════════════════════════
             AGENCY TRACKER (expandable group)
             ═══════════════════════════════════════════ --}}
        @permission('access_agency_tracker')
        <div>
            <button type="button" @click="toggle('agency-tracker')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'agency-tracker' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                <span>Agency Tracker</span>
                <svg class="corex-chevron transition-transform duration-200" :class="openGroup === 'agency-tracker' && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'agency-tracker'" @unless($activeGroup === 'agency-tracker') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">

                {{-- Common items (all roles) --}}
                @permission('view_worksheet')
                <a href="{{ route('worksheet.index') }}" class="corex-nav-subitem {{ request()->routeIs('worksheet.*') ? 'active' : '' }}">Worksheet</a>
                @endpermission

                @permission('view_listings')
                <a href="{{ route('agent.listings') }}" class="corex-nav-subitem {{ request()->routeIs('agent.listings*') ? 'active' : '' }}">My Listing Stock</a>
                @endpermission

                @permission('view_rentals')
                <a href="{{ route('rentals.index') }}" class="corex-nav-subitem {{ request()->routeIs('rentals.*') ? 'active' : '' }}">Rentals</a>
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
             PROSPECTING
             ═══════════════════════════════════════════ --}}
        @permission('access_prospecting')
        @if(\Illuminate\Support\Facades\Route::has('prospecting.index'))
        <a href="{{ route('prospecting.index') }}"
           class="corex-nav-item {{ request()->routeIs('prospecting.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <span>Prospecting</span>
        </a>
        @endif
        @endpermission

        {{-- ═══════════════════════════════════════════
             DOCUMENTS (DocuPerfect — expandable group)
             ═══════════════════════════════════════════ --}}
        @permission('access_docuperfect')
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.dashboard'))
        <div>
            <button type="button" @click="toggle('documents')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'documents' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" />
                </svg>
                <span>Documents</span>
                <svg class="corex-chevron transition-transform duration-200" :class="openGroup === 'documents' && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'documents'" @unless($activeGroup === 'documents') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">
                @permission('create_docuperfect_docs')
                <a href="{{ route('docuperfect.create') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.create') ? 'active' : '' }}">Create Document</a>
                <a href="{{ route('docuperfect.esign.create') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.esign.create') ? 'active' : '' }}">E-Sign Document</a>
                <a href="{{ route('docuperfect.esign.myDocuments') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.esign.myDocuments') ? 'active' : '' }}">My E-Sign Documents</a>
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
                <a href="{{ route('docuperfect.import.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.import.*') ? 'active' : '' }}">Import Document</a>
                @endpermission
            </div>
        </div>
        @endif
        @endpermission

        {{-- ═══════════════════════════════════════════
             RENTALS (expandable group)
             ═══════════════════════════════════════════ --}}
        @permission('view_rentals')
        <div>
            <button type="button" @click="toggle('rentals')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'rentals' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
                </svg>
                <span>Rentals</span>
                <svg class="corex-chevron transition-transform duration-200" :class="openGroup === 'rentals' && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'rentals'" @unless($activeGroup === 'rentals') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">
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

        {{-- ═══════════════════════════════════════════
             NON-GROUPED TOP-LEVEL ITEMS
             ═══════════════════════════════════════════ --}}

        {{-- Compliance (expandable group) --}}
        @permission('access_compliance')
        <div>
            <button type="button" @click="toggle('compliance')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'compliance' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
                <span>Compliance</span>
                <svg class="corex-chevron transition-transform duration-200" :class="openGroup === 'compliance' && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'compliance'" @unless($activeGroup === 'compliance') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">
                <a href="{{ route('compliance.fica.index') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.fica.*') ? 'active' : '' }}">FICA</a>
                <a href="{{ route('compliance.rmcp') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.rmcp') ? 'active' : '' }}">RMCP</a>
                @if($isOwner || $effectiveRole === 'super_admin')
                @php $nonCompliantAgents = \App\Models\User::where('is_active', true)->whereNull('deleted_at')->whereNull('ffc_number')->count(); @endphp
                <a href="{{ route('compliance.agents') }}" class="corex-nav-subitem {{ request()->routeIs('compliance.agents') ? 'active' : '' }}">
                    Agent Compliance
                    @if($nonCompliantAgents > 0)
                    <span class="ml-auto w-2 h-2 rounded-full bg-red-500 flex-shrink-0 inline-block"></span>
                    @endif
                </a>
                @endif
            </div>
        </div>
        @endpermission

        {{-- Supervision --}}
        @permission('access_supervision')
        <a href="{{ route('corex.supervision') }}" class="corex-nav-item {{ request()->routeIs('corex.supervision') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Supervision</span>
        </a>
        @endpermission

        {{-- Training (LMS) — moved to agent section above as "Training" --}}

        {{-- Communication --}}
        @permission('access_communication')
        <a href="{{ route('corex.communication') }}" class="corex-nav-item {{ request()->routeIs('corex.communication') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
            </svg>
            <span>Communication</span>
        </a>
        @endpermission

        {{-- Client Portal --}}
        @permission('access_client_portal')
        <a href="{{ route('corex.client-portal') }}" class="corex-nav-item {{ request()->routeIs('corex.client-portal') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
            <span>Client Portal</span>
        </a>
        @endpermission

        {{-- Sales Documents --}}
        @permission('access_sales_documents')
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.sales'))
        <a href="{{ route('docuperfect.sales') }}" class="corex-nav-item {{ request()->routeIs('docuperfect.sales*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v7.5M12 12.75h3m-3 0h-3m-2.25 0H5.625c-.621 0-1.125-.504-1.125-1.125V4.125c0-.621.504-1.125 1.125-1.125h5.25a2.25 2.25 0 0 1 2.25 2.25v1.5m-6 9V21m0-6.75h9" />
            </svg>
            <span>Sales Documents</span>
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

        {{-- Presentations --}}
        @permission('access_presentations')
        @if(config('features.presentations') && \Illuminate\Support\Facades\Route::has('presentations.index'))
        <a href="{{ route('presentations.index') }}" class="corex-nav-item {{ request()->routeIs('presentations.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16v10H4z"/>
                <path d="M8 20h8"/>
                <path d="M12 14v6"/>
            </svg>
            <span>Presentations</span>
        </a>
        @endif
        @endpermission

        {{-- Commercial Evaluations --}}
        @permission('access_commercial_evaluations')
        @if(\Illuminate\Support\Facades\Route::has('commercial-evaluations.index'))
        <a href="{{ route('commercial-evaluations.index') }}" class="corex-nav-item {{ request()->routeIs('commercial-evaluations.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <path d="M9 22V12h6v10"/>
            </svg>
            <span>Commercial Evaluations</span>
        </a>
        @endif
        @endpermission

        {{-- Evaluation --}}
        @permission('access_evaluation')
        <div>
            <button type="button" @click="toggle('evaluation')"
                    class="corex-nav-item corex-nav-group-toggle {{ $activeGroup === 'evaluation' ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
                </svg>
                <span>Evaluation</span>
                <svg class="corex-chevron transition-transform duration-200" :class="openGroup === 'evaluation' && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="openGroup === 'evaluation'" @unless($activeGroup === 'evaluation') x-cloak @endunless
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="corex-nav-children">
                <a href="{{ route('evaluation.index') }}#tab=property" class="corex-nav-subitem {{ request()->routeIs('evaluation.*') ? 'active' : '' }}">Property Report</a>
                <a href="{{ route('evaluation.index') }}#tab=suburb" class="corex-nav-subitem">Suburb Report</a>
                <a href="{{ route('evaluation.index') }}#tab=town" class="corex-nav-subitem">Town Report</a>
                <a href="{{ route('evaluation.index') }}#tab=street" class="corex-nav-subitem">Street Report</a>
                <a href="{{ route('evaluation.index') }}#tab=transfer" class="corex-nav-subitem">Transfer Report</a>
                <a href="{{ route('evaluation.index') }}#tab=prospecting" class="corex-nav-subitem">Prospecting</a>
            </div>
        </div>
        @endpermission

        {{-- Properties --}}
        @permission('access_properties')
        @if(config('features.properties') && \Illuminate\Support\Facades\Route::has('corex.properties.index'))
        <a href="{{ route('corex.properties.index') }}" class="corex-nav-item {{ request()->routeIs('corex.properties.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
            <span>Properties</span>
        </a>
        @endif
        @endpermission

        {{-- Contacts --}}
        @permission('access_contacts')
        @if(\Illuminate\Support\Facades\Route::has('corex.contacts.index'))
        <a href="{{ route('corex.contacts.index') }}" class="corex-nav-item {{ request()->routeIs('corex.contacts.*') && !request()->routeIs('corex.core-matches.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Contacts</span>
        </a>
        @endif
        @endpermission

        {{-- Core Matches --}}
        @permission('access_core_matches')
        @if(\Illuminate\Support\Facades\Route::has('corex.core-matches.index') && \App\Models\PerformanceSetting::get('matches_enabled', 1))
        <a href="{{ route('corex.core-matches.index') }}" class="corex-nav-item {{ request()->routeIs('corex.core-matches.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" />
            </svg>
            <span>Core Matches</span>
        </a>
        @endif
        @endpermission

        {{-- P24 Importer (Admin) --}}
        @permission('access_importer')
        <a href="{{ route('admin.importer.index') }}" class="corex-nav-item {{ request()->routeIs('admin.importer.index') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            <span>P24 Importer</span>
        </a>
        <a href="{{ route('admin.importer.review') }}" class="corex-nav-item {{ request()->routeIs('admin.importer.review') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span>Property Review</span>
        </a>
        @endpermission

        {{-- Franchise Admin --}}
        @permission('access_franchise_admin')
        <a href="{{ route('corex.franchise-admin') }}" class="corex-nav-item {{ request()->routeIs('corex.franchise-admin') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Franchise Admin</span>
        </a>
        @endpermission

        {{-- ═══════════════════════════════════════════
             TOOLS SECTION
             ═══════════════════════════════════════════ --}}
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Tools</div>

        {{-- Calculators --}}
        @if(\Illuminate\Support\Facades\Route::has('calculators.index'))
        @permission('access_calculators')
        <a href="{{ route('calculators.index') }}" class="corex-nav-item {{ request()->routeIs('calculators.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="2" width="16" height="20" rx="2"/>
                <line x1="8" y1="6" x2="16" y2="6"/>
                <line x1="8" y1="10" x2="10" y2="10"/>
                <line x1="14" y1="10" x2="16" y2="10"/>
                <line x1="8" y1="14" x2="10" y2="14"/>
                <line x1="14" y1="14" x2="16" y2="14"/>
                <line x1="8" y1="18" x2="10" y2="18"/>
                <line x1="14" y1="18" x2="16" y2="18"/>
            </svg>
            <span>Calculators</span>
        </a>
        @endpermission
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

        {{-- P24 Alerts (admin only) --}}
        @permission('manage_p24')
        @if(\Illuminate\Support\Facades\Route::has('admin.p24.index'))
        <a href="{{ route('admin.p24.index') }}" class="corex-nav-item {{ request()->routeIs('admin.p24.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
            </svg>
            <span>P24 Alerts</span>
        </a>
        @endif
        @endpermission

        {{-- Trust Interest Register (admin) --}}
        @permission('access_trust_interest')
        @if(\Illuminate\Support\Facades\Route::has('admin.deposit-trust-interest.index'))
        <a href="{{ route('admin.deposit-trust-interest.index') }}" class="corex-nav-item {{ request()->routeIs('admin.deposit-trust-interest.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 17a5 5 0 0 0 10 0c0-2.76-2.24-5-5-5s-5 2.24-5 5Z"/>
                <path d="M7 17v-2"/>
                <path d="M12 17a5 5 0 0 0 10 0c0-2.76-2.24-5-5-5s-5 2.24-5 5Z"/>
                <path d="M17 17v-2"/>
                <path d="M7 7h10"/>
                <path d="M12 3v4"/>
            </svg>
            <span>Trust Interest Register</span>
        </a>
        @endif
        @endpermission

        {{-- Deposit Interest Calculator (BM + Admin) --}}
        @permission('access_deposit_calculator')
        @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.index'))
        <a href="{{ route('deposit-interest-calculator.index') }}" class="corex-nav-item {{ request()->routeIs('deposit-interest-calculator.index') || request()->routeIs('deposit-interest-calculator.calculate') || request()->routeIs('deposit-interest-calculator.show') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            <span>Deposit Interest Calc</span>
        </a>
        @endif
        @endpermission

        {{-- Calculation History (BM + Admin) --}}
        @permission('access_deposit_calc_history')
        @if(\Illuminate\Support\Facades\Route::has('deposit-interest-calculator.history'))
        <a href="{{ route('deposit-interest-calculator.history') }}" class="corex-nav-item {{ request()->routeIs('deposit-interest-calculator.history') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <span>Calculation History</span>
        </a>
        @endif
        @endpermission

        {{-- PDF Splitter --}}
        @permission('access_pdf_splitter')
        @if(\Illuminate\Support\Facades\Route::has('tools.pdf_splitter.index'))
        <a href="{{ route('tools.pdf_splitter.index') }}" class="corex-nav-item {{ request()->routeIs('tools.pdf_splitter.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M8 13h8"/>
                <path d="M8 17h8"/>
                <path d="M8 9h2"/>
            </svg>
            <span>PDF Splitter</span>
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

        {{-- ═══════════════════════════════════════════
             PLATFORM ADMIN (System Owners only)
             Kept separate from the regular Admin section because System
             Owners are platform identities, not agency members.
             ═══════════════════════════════════════════ --}}
        @if($isOwner)
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Platform Admin</div>

        {{-- Agency Management --}}
        <a href="{{ route('agencies.index') }}" class="corex-nav-item {{ request()->routeIs('agencies.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
            </svg>
            <span>Agency Management</span>
        </a>

        {{-- Company Settings — per-agency identity, lives here because
             Owners toggle between agencies and edit each one. --}}
        <a href="{{ route('admin.company-settings') }}" class="corex-nav-item {{ request()->routeIs('admin.company-settings*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Company Settings</span>
        </a>
        @endif

        {{-- ═══════════════════════════════════════════
             ADMIN SECTION (agency-level admins — BMs, super_admin)
             ═══════════════════════════════════════════ --}}
        @if($user && $user->hasAnyPermission(['access_knowledge_base', 'access_role_manager', 'access_finance_engine', 'access_settings']))
        <div class="corex-nav-divider"></div>
        <div class="corex-nav-section-label">Admin</div>

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
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold bg-blue-500 text-white">{{ $onboardingCount }}</span>
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

        {{-- Fault Reports (super_admin / owner only) --}}
        @if($isOwner || $effectiveRole === 'super_admin')
        @php $faultNewCount = \App\Models\FaultReport::where('status','new')->count(); @endphp
        <a href="{{ route('admin.fault-reports') }}" class="corex-nav-item {{ request()->routeIs('admin.fault-reports*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0 1 12 12.75Zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 0 1-1.152-6.135c-.117-1.427-.245-2.88-.465-4.305-.074-.477-.513-.826-.998-.826H6.408c-.485 0-.924.35-.998.826-.22 1.424-.348 2.878-.465 4.305A23.91 23.91 0 0 1 3.793 14.19 24.467 24.467 0 0 1 12 12.75ZM2.695 18.678a25.411 25.411 0 0 1 .122-2.428c.24-.84.598-1.628 1.058-2.347M21.305 18.678a25.12 25.12 0 0 0-.122-2.428 7.667 7.667 0 0 0-1.058-2.347" />
            </svg>
            <span>Fault Reports</span>
            @if($faultNewCount > 0)
            <span class="ml-auto inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold bg-red-500 text-white">{{ $faultNewCount }}</span>
            @endif
        </a>
        @endif

        {{-- Deal Register V2 --}}
        @permission('access_deal_register_v2')
        @if(\Illuminate\Support\Facades\Route::has('deals-v2.index'))
        <a href="{{ route('deals-v2.index') }}" class="corex-nav-item {{ request()->routeIs('deals-v2.index') || request()->routeIs('deals-v2.show') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <span>Deal Register V2</span>
        </a>
        @endif
        @endpermission

        {{-- New Deal V2 --}}
        @permission('deals_v2.create')
        @if(\Illuminate\Support\Facades\Route::has('deals-v2.create'))
        <a href="{{ route('deals-v2.create') }}" class="corex-nav-item {{ request()->routeIs('deals-v2.create') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            <span>New Deal</span>
        </a>
        @endif
        @endpermission

        {{-- Pipeline Setup (Deal V2 — admin/BM) --}}
        @permission('deals_v2.manage_pipeline')
        @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
        <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-nav-item {{ request()->routeIs('deals-v2.pipeline.*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
            </svg>
            <span>Pipeline Setup</span>
        </a>
        @endif
        @endpermission

        {{-- Settings --}}
        @permission('access_settings')
        <a href="{{ route('corex.settings') }}" class="corex-nav-item {{ request()->routeIs('corex.settings*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Settings</span>
        </a>
        @endpermission
        @endif
    </nav>

    {{-- ═══════════════════════════════════════════
         USER PROFILE + IMPERSONATION
         ═══════════════════════════════════════════ --}}
    @auth
    <div class="corex-user-section" x-data="{ userMenu: false, switchPanel: false }">
        {{-- Impersonation banner --}}
        @if($isImpersonating)
        <div class="corex-impersonate-banner">
            <div class="text-[11px] text-amber-200">Viewing as <strong>{{ $user->name ?? 'User' }}</strong></div>
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
            <a href="{{ route('profile.edit') }}" class="corex-user-dropdown-item">Profile</a>
            @if($canSwitchUsers)
            <button type="button" @click="switchPanel = !switchPanel; userMenu = false" class="corex-user-dropdown-item w-full text-left">Switch User</button>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="corex-user-dropdown-item w-full text-left">Log Out</button>
            </form>
        </div>

        {{-- Switch user panel --}}
        @if($canSwitchUsers)
        <div x-show="switchPanel" x-cloak @click.outside="switchPanel = false" x-transition class="corex-switch-panel">
            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold px-2 py-1">Switch User</div>
            <div class="corex-switch-list">
                @foreach($switchUsers as $su)
                    @if((int)$su->id !== (int)($user->id ?? 0))
                    <form method="POST" action="{{ route('impersonate.start', ['user' => $su->id]) }}">
                        @csrf
                        <button type="submit" class="corex-switch-item">
                            <div class="text-xs text-white/90">{{ $su->name }}</div>
                            <div class="text-[10px] text-white/50">{{ $su->email }} · {{ $su->role }}</div>
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

