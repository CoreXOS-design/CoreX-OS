@php
    $currentPath = request()->path();
    $nexusSection = 'agency-tracker'; // default for all existing routes

    if (str_starts_with($currentPath, 'docuperfect')) {
        $nexusSection = 'docuperfect';
    } elseif (str_starts_with($currentPath, 'documents/library')) {
        $nexusSection = 'document-library';
    } elseif ($currentPath === 'nexus' || $currentPath === 'nexus/') {
        $nexusSection = 'dashboard';
    } elseif (str_starts_with($currentPath, 'nexus/documents')) {
        $nexusSection = 'documents';
    } elseif (str_starts_with($currentPath, 'nexus/compliance')) {
        $nexusSection = 'compliance';
    } elseif (str_starts_with($currentPath, 'nexus/supervision')) {
        $nexusSection = 'supervision';
    } elseif (str_starts_with($currentPath, 'nexus/training')) {
        $nexusSection = 'training';
    } elseif (str_starts_with($currentPath, 'nexus/communication')) {
        $nexusSection = 'communication';
    } elseif (str_starts_with($currentPath, 'nexus/client-portal')) {
        $nexusSection = 'client-portal';
    } elseif (str_starts_with($currentPath, 'nexus/franchise-admin')) {
        $nexusSection = 'franchise-admin';
    } elseif (str_starts_with($currentPath, 'nexus/role-manager')) {
        $nexusSection = 'role-manager';
    } elseif (str_starts_with($currentPath, 'nexus/settings')) {
        $nexusSection = 'settings';
    } elseif (str_starts_with($currentPath, 'admin/finance')) {
        $nexusSection = 'finance-engine';
    } elseif (str_starts_with($currentPath, 'commercial-evaluations')) {
        $nexusSection = 'commercial-evaluations';
    } elseif (str_starts_with($currentPath, 'calculators')) {
        $nexusSection = 'calculators';
    }

    $user = auth()->user();
    $userInitials = $user ? collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') : '??';
    $userRole = $user ? str_replace('_', ' ', $user->effectiveRole()) : '';

    // Agency Tracker role helpers
    $effectiveRole = $user ? strtolower(trim((string)($user->effectiveRole() ?? ($user->role ?? '')))) : '';
    $navIsAdmin = ($effectiveRole === 'admin') || (bool)($user->is_admin ?? 0);
    $navIsBM = ($effectiveRole === 'branch_manager');
    $navIsAgent = ($effectiveRole === 'agent');
    $effectiveBranchId = $user?->effectiveBranchId();
    $atExpanded = ($nexusSection === 'agency-tracker');

    // Impersonation state
    $impersonatorId  = (int) session('impersonator_id', 0);
    $isImpersonating = $impersonatorId > 0;
    $canSwitchUsers  = !$isImpersonating && ($navIsAdmin || (bool)($user->is_admin ?? 0));
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
@endphp

<div class="nexus-sidebar">
    {{-- Logo --}}
    <div class="nexus-sidebar-logo">
        nexus <span>os</span>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 py-2 overflow-y-auto min-h-0">
        {{-- Dashboard --}}
        <a href="{{ route('nexus.dashboard') }}" class="nexus-nav-item {{ $nexusSection === 'dashboard' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
            </svg>
            <span>Dashboard</span>
        </a>

        {{-- Documents --}}
        @if(!$user || $user->canAccessNexusSection('documents'))
        <a href="{{ route('nexus.documents') }}" class="nexus-nav-item {{ $nexusSection === 'documents' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <span>Documents</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Compliance --}}
        @if(!$user || $user->canAccessNexusSection('compliance'))
        <a href="{{ route('nexus.compliance') }}" class="nexus-nav-item {{ $nexusSection === 'compliance' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Compliance</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Supervision --}}
        @if(!$user || $user->canAccessNexusSection('supervision'))
        <a href="{{ route('nexus.supervision') }}" class="nexus-nav-item {{ $nexusSection === 'supervision' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Supervision</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Training (LMS) --}}
        @if(!$user || $user->canAccessNexusSection('training'))
        <a href="{{ route('nexus.training') }}" class="nexus-nav-item {{ $nexusSection === 'training' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
            </svg>
            <span>Training (LMS)</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Communication --}}
        @if(!$user || $user->canAccessNexusSection('communication'))
        <a href="{{ route('nexus.communication') }}" class="nexus-nav-item {{ $nexusSection === 'communication' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
            </svg>
            <span>Communication</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Client Portal --}}
        @if(!$user || $user->canAccessNexusSection('client-portal'))
        <a href="{{ route('nexus.client-portal') }}" class="nexus-nav-item {{ $nexusSection === 'client-portal' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
            <span>Client Portal</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Docuperfect (expandable group) --}}
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.dashboard'))
        @php $dpExpanded = ($nexusSection === 'docuperfect'); @endphp
        <div x-data="{ dpOpen: {{ $dpExpanded ? 'true' : 'false' }} }">
            <button type="button" @click="dpOpen = !dpOpen" class="nexus-nav-item nexus-nav-group-toggle {{ $dpExpanded ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" />
                </svg>
                <span>Documents</span>
                <svg class="nexus-chevron transition-transform duration-200" :class="dpOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="dpOpen" @unless($dpExpanded) x-cloak @endunless x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="nexus-nav-children">
                <a href="{{ route('docuperfect.create') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.create') ? 'active' : '' }}">Create Document</a>
                <a href="{{ route('docuperfect.dashboard') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.dashboard') ? 'active' : '' }}">My Documents</a>
                <a href="{{ route('docuperfect.clauses.index') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.clauses.*') ? 'active' : '' }}">Clause Library</a>
                @if($navIsAdmin || $navIsBM)
                <a href="{{ route('docuperfect.templates.index') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.templates.*') ? 'active' : '' }}">Template Management</a>
                @endif
                <a href="{{ route('docuperfect.packs.index') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.packs.*') ? 'active' : '' }}">Packs</a>
                <a href="{{ route('docuperfect.rental') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.rental') || request()->routeIs('docuperfect.signatures.*') || request()->routeIs('docuperfect.leases.*') ? 'active' : '' }}">Rental Documents</a>
                <a href="{{ route('docuperfect.sales') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.sales*') ? 'active' : '' }}">Sales Documents</a>
                @if($navIsAdmin)
                <a href="{{ route('docuperfect.settings.types') }}" class="nexus-nav-subitem {{ request()->routeIs('docuperfect.settings.*') ? 'active' : '' }}">Settings</a>
                @endif
            </div>
        </div>
        @endif

        {{-- Franchise Admin --}}
        @if(!$user || $user->canAccessNexusSection('franchise-admin'))
        <a href="{{ route('nexus.franchise-admin') }}" class="nexus-nav-item {{ $nexusSection === 'franchise-admin' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Franchise Admin</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Agency Tracker (expandable group) --}}
        <div x-data="{ atOpen: {{ $atExpanded ? 'true' : 'false' }} }">
            <button type="button" @click="atOpen = !atOpen" class="nexus-nav-item nexus-nav-group-toggle {{ $atExpanded ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                <span>Agency Tracker</span>
                <svg class="nexus-chevron transition-transform duration-200" :class="atOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="atOpen" @unless($atExpanded) x-cloak @endunless x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="nexus-nav-children">

                {{-- Common items (all roles) --}}
                <a href="{{ route('worksheet.index') }}" class="nexus-nav-subitem {{ request()->routeIs('worksheet.*') ? 'active' : '' }}">Worksheet</a>
                <a href="{{ route('agent.listings') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.listings*') ? 'active' : '' }}">My Listing Stock</a>
                @if($user && ($user->can_capture_rentals || in_array($effectiveRole, ['admin','branch_manager'])))
                <a href="{{ route('rentals.index') }}" class="nexus-nav-subitem {{ request()->routeIs('rentals.*') ? 'active' : '' }}">Rentals</a>
                @endif

                {{-- Agent section --}}
                @if($navIsAgent)
                <div class="nexus-nav-sublabel">My Performance</div>
                <a href="{{ route('agent.dashboard') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.dashboard') ? 'active' : '' }}">Agent Dashboard</a>
                <a href="{{ route('agent.daily.summary') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('agent.daily') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.daily') ? 'active' : '' }}">My Daily Activity</a>
                <a href="{{ route('agent.deals.index') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.deals.*') ? 'active' : '' }}">My Deals</a>
                @endif

                {{-- Branch Manager section --}}
                @if($navIsBM)
                <div class="nexus-nav-sublabel">Branch</div>
                <a href="{{ route('bm.performance') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.performance*') ? 'active' : '' }}">Branch Performance</a>
                <a href="{{ route('bm.daily.summary') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('bm.listings') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.listings*') ? 'active' : '' }}">Branch Listing Stock</a>
                <a href="{{ route('bm.my.dashboard') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.my.dashboard') ? 'active' : '' }}">My Agent Dashboard</a>
                <a href="{{ route('admin.deals') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                <a href="{{ route('filing-register.index') }}" class="nexus-nav-subitem {{ request()->routeIs('filing-register.*') ? 'active' : '' }}">Filing Register</a>
                <div class="nexus-nav-sublabel">Setup</div>
                <a href="{{ route('bm.worksheet.market') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.worksheet.market*') ? 'active' : '' }}">Worksheet Market</a>
                <a href="{{ route('admin.targets') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets*') ? 'active' : '' }}">Daily Activity Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                <a href="{{ route('admin.targets.activity.setup') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets.activity.setup*') ? 'active' : '' }}">Activity Setup</a>
                <a href="{{ route('bm.tv-messages') }}" class="nexus-nav-subitem {{ request()->routeIs('bm.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @if($effectiveBranchId)
                <a href="{{ route('agent.daily') }}" class="nexus-nav-subitem {{ request()->routeIs('agent.daily') ? 'active' : '' }}">Daily Activity Capture</a>
                @endif
                @endif

                {{-- Admin section --}}
                @if($navIsAdmin)
                <div class="nexus-nav-sublabel">Admin</div>
                <a href="{{ route('admin.performance') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.performance') ? 'active' : '' }}">Performance</a>
                @if(\Illuminate\Support\Facades\Route::has('admin.listings.stock'))
                <a href="{{ route('admin.listings.stock') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.listings.stock*') ? 'active' : '' }}">Company Listing Stock</a>
                @endif
                <a href="{{ route('admin.performance-settings.edit') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.performance-settings*') ? 'active' : '' }}">Company Settings</a>
                <a href="{{ route('admin.designations.index') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.designations*') ? 'active' : '' }}">Designations</a>
                <a href="{{ route('admin.deals') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                <a href="{{ route('filing-register.index') }}" class="nexus-nav-subitem {{ request()->routeIs('filing-register.*') ? 'active' : '' }}">Filing Register</a>
                <a href="{{ route('admin.listings.agents') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.listings.agents*') ? 'active' : '' }}">Listing Stock</a>
                <a href="{{ route('admin.listings.import') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.listings.import*') ? 'active' : '' }}">Import Listings</a>
                <a href="{{ route('admin.daily.summary') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('admin.targets') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets') ? 'active' : '' }}">Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                <a href="{{ route('admin.targets.activity.setup') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.targets.activity.setup*') ? 'active' : '' }}">Activity Setup</a>
                <a href="{{ route('admin.worksheet-market') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.worksheet-market*') ? 'active' : '' }}">Worksheet Market</a>
                <a href="{{ route('admin.branch-assignments') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.branch-assignments') ? 'active' : '' }}">Branch Assignments</a>
                <a href="{{ route('admin.users') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.users') ? 'active' : '' }}">Users</a>
                <a href="{{ route('admin.tv-messages') }}" class="nexus-nav-subitem {{ request()->routeIs('admin.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @endif

                {{-- Tools (all roles) --}}
                <div class="nexus-nav-sublabel">Tools</div>
                <a href="{{ route('tools.commission') }}" class="nexus-nav-subitem {{ request()->routeIs('tools.commission') && !request()->query('section') ? 'active' : '' }}">Commission Calculator</a>
                <a href="{{ route('tools.cma') }}" class="nexus-nav-subitem {{ request()->routeIs('tools.cma') ? 'active' : '' }}">CMA Certificate Generator</a>
                <a href="{{ route('tools.commission') }}?section=history" class="nexus-nav-subitem {{ request()->routeIs('tools.commission') && request()->query('section') === 'history' ? 'active' : '' }}">History & Logs</a>
            </div>
        </div>

        
        {{-- PDF Splitter (route-guarded) --}}
        @if(\Illuminate\Support\Facades\Route::has('tools.pdf_splitter.index'))
        <a href="{{ route('tools.pdf_splitter.index') }}" class="nexus-nav-item {{ request()->is('tools/pdf-splitter*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M8 13h8"/>
                <path d="M8 17h8"/>
                <path d="M8 9h2"/>
            </svg>
            <span>PDF Splitter</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif


        {{-- Presentations (feature-flag + route-guarded) --}}
        @if(config('features.presentations') && \Illuminate\Support\Facades\Route::has('presentations.index'))
        <a href="{{ route('presentations.index') }}" class="nexus-nav-item {{ request()->is('presentations*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16v10H4z"/>
                <path d="M8 20h8"/>
                <path d="M12 14v6"/>
            </svg>
            <span>Presentations</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Commercial Evaluations (route-guarded) --}}
        @if(\Illuminate\Support\Facades\Route::has('commercial-evaluations.index'))
        <a href="{{ route('commercial-evaluations.index') }}" class="nexus-nav-item {{ request()->is('commercial-evaluations*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Commercial Evaluations</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Document Library (feature-flagged) --}}
        @if(config('features.document_library_v1'))
        <a href="{{ route('documents.library.index') }}" class="nexus-nav-item {{ request()->is('documents/library*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
            </svg>
            <span>Document Library</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Calculators (all roles) --}}
        <a href="{{ route('calculators.index') }}" class="nexus-nav-item {{ $nexusSection === 'calculators' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z" />
            </svg>
            <span>Calculators</span>
        </a>

<div class="nexus-nav-divider"></div>

        {{-- Role Manager --}}
        @if(!$user || $user->canAccessNexusSection('role-manager'))
        <a href="{{ route('nexus.role-manager') }}" class="nexus-nav-item {{ $nexusSection === 'role-manager' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Role Manager</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Knowledge Base (admin only) --}}
        @if($navIsAdmin)
        <a href="{{ route('admin.knowledge.index') }}" class="nexus-nav-item {{ request()->is('admin/knowledge*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Knowledge Base</span>
        </a>
        @endif

        {{-- P24 Intelligence (admin only) --}}
        @if($navIsAdmin)
        <a href="{{ route('admin.p24.index') }}" class="nexus-nav-item {{ request()->is('admin/p24*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
            </svg>
            <span>P24 Intelligence</span>
        </a>
        @endif

        {{-- Finance Engine (admin only) --}}
        @if($user && $user->role === 'admin')
        <div class="nexus-nav-divider"></div>
        <div class="px-4 py-1">
            <span class="text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500 font-semibold">Finance Engine</span>
        </div>
        <a href="{{ route('admin.finance.definitions') }}" class="nexus-nav-item {{ $nexusSection === 'finance-engine' && request()->is('admin/finance/definitions') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z" />
            </svg>
            <span>Definitions</span>
        </a>
        <a href="{{ route('admin.finance.audit.index') }}" class="nexus-nav-item {{ $nexusSection === 'finance-engine' && request()->is('admin/finance/audit*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
            </svg>
            <span>Audit History</span>
        </a>
        @endif

        {{-- Settings --}}
        @if(!$user || $user->canAccessNexusSection('settings'))
        <a href="{{ route('nexus.settings') }}" class="nexus-nav-item {{ $nexusSection === 'settings' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Settings</span>
            <svg class="nexus-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif
    </nav>

    {{-- User Profile + Impersonation --}}
    @auth
    <div class="nexus-user-section" x-data="{ userMenu: false, switchPanel: false }">
        {{-- Impersonation banner --}}
        @if($isImpersonating)
        <div class="nexus-impersonate-banner">
            <div class="text-[11px] text-amber-200">Viewing as <strong>{{ $user->name ?? 'User' }}</strong></div>
            <form method="POST" action="{{ route('impersonate.stop') }}" class="mt-1">
                @csrf
                <button type="submit" class="nexus-impersonate-btn">Switch back to {{ $impersonatorName ?? 'admin' }}</button>
            </form>
        </div>
        @endif

        <div class="nexus-user-profile">
            <div class="nexus-user-avatar">{{ $userInitials }}</div>
            <div class="flex-1 min-w-0">
                <div class="nexus-user-name">{{ $user->name }}</div>
                <div class="nexus-user-role">{{ $userRole }}</div>
            </div>
            <button type="button" @click="userMenu = !userMenu" class="nexus-user-menu-btn" title="User menu">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:1rem;height:1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 12.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5ZM12 18.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" /></svg>
            </button>
        </div>

        {{-- Dropdown menu --}}
        <div x-show="userMenu" @click.outside="userMenu = false" x-transition class="nexus-user-dropdown">
            <a href="{{ route('profile.edit') }}" class="nexus-user-dropdown-item">Profile</a>
            @if($canSwitchUsers)
            <button type="button" @click="switchPanel = !switchPanel; userMenu = false" class="nexus-user-dropdown-item w-full text-left">Switch User</button>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="nexus-user-dropdown-item w-full text-left">Log Out</button>
            </form>
        </div>

        {{-- Switch user panel --}}
        @if($canSwitchUsers)
        <div x-show="switchPanel" @click.outside="switchPanel = false" x-transition class="nexus-switch-panel">
            <div class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold px-2 py-1">Switch User</div>
            <div class="nexus-switch-list">
                @foreach($switchUsers as $su)
                    @if((int)$su->id !== (int)($user->id ?? 0))
                    <form method="POST" action="{{ route('impersonate.start', ['user' => $su->id]) }}">
                        @csrf
                        <button type="submit" class="nexus-switch-item">
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
