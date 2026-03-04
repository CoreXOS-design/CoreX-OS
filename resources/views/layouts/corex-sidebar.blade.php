@php
    $currentPath = request()->path();
    $corexSection = 'agency-tracker'; // default for all existing routes

    if (
        str_starts_with($currentPath, 'admin/performance') ||
        str_starts_with($currentPath, 'bm/performance') ||
        str_starts_with($currentPath, 'agent/dashboard')
    ) {
        $corexSection = 'dashboard';
    } elseif (str_starts_with($currentPath, 'filing-register')) {
        $corexSection = 'filing-register';
    } elseif (str_starts_with($currentPath, 'rental')) {
        $corexSection = 'rental-division';
    } elseif (str_starts_with($currentPath, 'docuperfect/sales')) {
        $corexSection = 'sales-documents';
    } elseif (str_starts_with($currentPath, 'docuperfect')) {
        $corexSection = 'docuperfect';
    } elseif (str_starts_with($currentPath, 'documents/library')) {
        $corexSection = 'document-library';
    } elseif ($currentPath === 'corex' || $currentPath === 'corex/') {
        $corexSection = 'dashboard';
    } elseif (str_starts_with($currentPath, 'corex/compliance')) {
        $corexSection = 'compliance';
    } elseif (str_starts_with($currentPath, 'corex/supervision')) {
        $corexSection = 'supervision';
    } elseif (str_starts_with($currentPath, 'corex/training')) {
        $corexSection = 'training';
    } elseif (str_starts_with($currentPath, 'corex/communication')) {
        $corexSection = 'communication';
    } elseif (str_starts_with($currentPath, 'corex/client-portal')) {
        $corexSection = 'client-portal';
    } elseif (str_starts_with($currentPath, 'corex/franchise-admin')) {
        $corexSection = 'franchise-admin';
    } elseif (str_starts_with($currentPath, 'corex/role-manager')) {
        $corexSection = 'role-manager';
    } elseif (str_starts_with($currentPath, 'corex/properties')) {
        $corexSection = 'properties';
    } elseif (str_starts_with($currentPath, 'corex/settings/agencies')) {
        $corexSection = 'agencies';
    } elseif (str_starts_with($currentPath, 'corex/settings')) {
        $corexSection = 'settings';
    } elseif (str_starts_with($currentPath, 'admin/finance')) {
        $corexSection = 'finance-engine';
    }

    $user = auth()->user();
    $userInitials = $user ? collect(explode(' ', $user->name))->map(fn($w) => strtoupper(substr($w, 0, 1)))->take(2)->join('') : '??';
    $userRole = $user ? str_replace('_', ' ', $user->effectiveRole()) : '';

    // Agency Tracker role helpers
    $effectiveRole = $user ? strtolower(trim((string)($user->effectiveRole() ?? ($user->role ?? '')))) : '';
    $navIsAdmin = in_array($effectiveRole, ['admin', 'super_admin']) || (bool)($user->is_admin ?? 0);
    $navIsBM = ($effectiveRole === 'branch_manager');
    $navIsAgent = ($effectiveRole === 'agent');
    $effectiveBranchId = $user?->effectiveBranchId();
    $atExpanded = ($corexSection === 'agency-tracker');

    // Super Admin & Agency Switcher
    $isSuperAdmin = $user && $user->isSuperAdmin();
    $activeAgencyId = session('active_agency_id');
    $agencies = $isSuperAdmin ? \App\Models\Agency::orderBy('name')->get() : collect();
    $activeAgency = ($isSuperAdmin && $activeAgencyId) ? $agencies->find($activeAgencyId) : null;

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

<div class="corex-sidebar">
    {{-- Logo --}}
    <div class="corex-sidebar-logo">
        CoreX <span>Os</span>
    </div>

    {{-- Agency Switcher (super_admin only) --}}
    @if($isSuperAdmin)
    <div x-data="{ agencyOpen: false }" class="px-3 pb-2">
        <button type="button" @click="agencyOpen = !agencyOpen"
                class="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium transition-colors"
                style="background:rgba(0,180,216,0.12); color:var(--brand-secondary, #00b4d8); border:1px solid rgba(0,180,216,0.25);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3.5 h-3.5 flex-shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
            </svg>
            <span class="flex-1 text-left truncate">{{ $activeAgency ? $activeAgency->name : 'All Agencies' }}</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 flex-shrink-0 transition-transform duration-150" :class="agencyOpen && 'rotate-90'"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>
        <div x-show="agencyOpen" x-cloak @click.outside="agencyOpen = false" x-transition
             class="mt-1 rounded-lg overflow-hidden shadow-lg"
             style="background:var(--brand-tertiary, #1a4a73); border:1px solid rgba(255,255,255,0.1);">
            {{-- All Agencies option --}}
            <form method="POST" action="{{ route('agency.switch.clear') }}">
                @csrf
                <button type="submit" class="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-white/10 {{ !$activeAgencyId ? 'text-[#00b4d8] font-semibold' : 'text-white/70' }}">
                    All Agencies
                </button>
            </form>
            @foreach($agencies as $ag)
            <form method="POST" action="{{ route('agency.switch', $ag) }}">
                @csrf
                <button type="submit" class="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-white/10 {{ (int)$activeAgencyId === $ag->id ? 'text-[#00b4d8] font-semibold' : 'text-white/70' }}">
                    {{ $ag->name }}
                </button>
            </form>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Navigation --}}
    <nav class="flex-1 py-2 overflow-y-auto min-h-0">
        {{-- Dashboard --}}
        <a href="{{ route('corex.dashboard') }}" class="corex-nav-item {{ $corexSection === 'dashboard' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25a2.25 2.25 0 0 1-2.25-2.25v-2.25Z" />
            </svg>
            <span>Dashboard</span>
        </a>

        {{-- Compliance --}}
        @if(!$user || $user->canAccessCorexSection('compliance'))
        <a href="{{ route('corex.compliance') }}" class="corex-nav-item {{ $corexSection === 'compliance' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Compliance</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Supervision --}}
        @if(!$user || $user->canAccessCorexSection('supervision'))
        <a href="{{ route('corex.supervision') }}" class="corex-nav-item {{ $corexSection === 'supervision' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Supervision</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Training (LMS) --}}
        @if(!$user || $user->canAccessCorexSection('training'))
        <a href="{{ route('corex.training') }}" class="corex-nav-item {{ $corexSection === 'training' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
            </svg>
            <span>Training (LMS)</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Communication --}}
        @if(!$user || $user->canAccessCorexSection('communication'))
        <a href="{{ route('corex.communication') }}" class="corex-nav-item {{ $corexSection === 'communication' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
            </svg>
            <span>Communication</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Client Portal --}}
        @if(!$user || $user->canAccessCorexSection('client-portal'))
        <a href="{{ route('corex.client-portal') }}" class="corex-nav-item {{ $corexSection === 'client-portal' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
            </svg>
            <span>Client Portal</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Docuperfect (expandable group) --}}
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.dashboard') && (!$user || $user->canAccessCorexSection('docuperfect')))
        @php $dpExpanded = ($corexSection === 'docuperfect'); @endphp
        <div x-data="{ dpOpen: {{ $dpExpanded ? 'true' : 'false' }} }">
            <button type="button" @click="dpOpen = !dpOpen" class="corex-nav-item corex-nav-group-toggle {{ $dpExpanded ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0" />
                </svg>
                <span>Documents</span>
                <svg class="corex-chevron transition-transform duration-200" :class="dpOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="dpOpen" @unless($dpExpanded) x-cloak @endunless x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="corex-nav-children">
                <a href="{{ route('docuperfect.create') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.create') ? 'active' : '' }}">Create Document</a>
                <a href="{{ route('docuperfect.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.dashboard') ? 'active' : '' }}">My Documents</a>
                <a href="{{ route('docuperfect.clauses.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.clauses.*') ? 'active' : '' }}">Clause Library</a>
                @if($navIsAdmin || $navIsBM)
                <a href="{{ route('docuperfect.templates.index') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.templates.*') ? 'active' : '' }}">Template Management</a>
                @endif
                @if($navIsAdmin)
                <a href="{{ route('docuperfect.settings.types') }}" class="corex-nav-subitem {{ request()->routeIs('docuperfect.settings.*') ? 'active' : '' }}">Settings</a>
                @endif
            </div>
        </div>
        @endif

        {{-- Sales Documents (top-level link into Docuperfect sales) --}}
        @if(\Illuminate\Support\Facades\Route::has('docuperfect.sales'))
        <a href="{{ route('docuperfect.sales') }}" class="corex-nav-item {{ $corexSection === 'sales-documents' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v7.5M12 12.75h3m-3 0h-3m-2.25 0H5.625c-.621 0-1.125-.504-1.125-1.125V4.125c0-.621.504-1.125 1.125-1.125h5.25a2.25 2.25 0 0 1 2.25 2.25v1.5m-6 9V21m0-6.75h9" />
            </svg>
            <span>Sales Documents</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Filing Register (top-level) --}}
        <a href="{{ route('filing-register.index') }}" class="corex-nav-item {{ $corexSection === 'filing-register' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" />
            </svg>
            <span>Filing Register</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>

        {{-- Rental Division (expandable group) --}}
        @php $rentalExpanded = ($corexSection === 'rental-division'); @endphp
        <div x-data="{ rentalOpen: {{ $rentalExpanded ? 'true' : 'false' }} }">
            <button type="button" @click="rentalOpen = !rentalOpen" class="corex-nav-item corex-nav-group-toggle {{ $rentalExpanded ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
                </svg>
                <span>Rentals</span>
                <svg class="corex-chevron transition-transform duration-200" :class="rentalOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="rentalOpen" @unless($rentalExpanded) x-cloak @endunless x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="corex-nav-children">
                <a href="{{ route('rental.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('rental.dashboard') ? 'active' : '' }}">Dashboard</a>
                <a href="{{ route('rental.signatures') }}" class="corex-nav-subitem {{ request()->routeIs('rental.signatures') ? 'active' : '' }}">Electronic Signatures</a>
                <a href="{{ route('rental.active-leases') }}" class="corex-nav-subitem {{ request()->routeIs('rental.active-leases') ? 'active' : '' }}">Active Leases</a>
                <a href="{{ route('rental.expired-leases') }}" class="corex-nav-subitem {{ request()->routeIs('rental.expired-leases') ? 'active' : '' }}">Expired Leases</a>
                <a href="{{ route('rental.settings') }}" class="corex-nav-subitem {{ request()->routeIs('rental.settings') ? 'active' : '' }}">Settings</a>
            </div>
        </div>

        {{-- Franchise Admin --}}
        @if(!$user || $user->canAccessCorexSection('franchise-admin'))
        <a href="{{ route('corex.franchise-admin') }}" class="corex-nav-item {{ $corexSection === 'franchise-admin' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <span>Franchise Admin</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Agency Tracker (expandable group) --}}
        <div x-data="{ atOpen: {{ $atExpanded ? 'true' : 'false' }} }">
            <button type="button" @click="atOpen = !atOpen" class="corex-nav-item corex-nav-group-toggle {{ $atExpanded ? 'active' : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
                <span>Agency Tracker</span>
                <svg class="corex-chevron transition-transform duration-200" :class="atOpen && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>

            <div x-show="atOpen" @unless($atExpanded) x-cloak @endunless x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="corex-nav-children">

                {{-- Common items (all roles) --}}
                <a href="{{ route('worksheet.index') }}" class="corex-nav-subitem {{ request()->routeIs('worksheet.*') ? 'active' : '' }}">Worksheet</a>
                <a href="{{ route('agent.listings') }}" class="corex-nav-subitem {{ request()->routeIs('agent.listings*') ? 'active' : '' }}">My Listing Stock</a>
                @if($user && ($user->can_capture_rentals || in_array($effectiveRole, ['admin','super_admin','branch_manager'])))
                <a href="{{ route('rentals.index') }}" class="corex-nav-subitem {{ request()->routeIs('rentals.*') ? 'active' : '' }}">Rentals</a>
                @endif

                {{-- Agent section --}}
                @if($navIsAgent)
                <div class="corex-nav-sublabel">My Performance</div>
                <a href="{{ route('agent.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('agent.dashboard') ? 'active' : '' }}">Agent Dashboard</a>
                <a href="{{ route('agent.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('agent.daily') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily') ? 'active' : '' }}">My Daily Activity</a>
                <a href="{{ route('agent.deals.index') }}" class="corex-nav-subitem {{ request()->routeIs('agent.deals.*') ? 'active' : '' }}">My Deals</a>
                @endif

                {{-- Branch Manager section --}}
                @if($navIsBM)
                <div class="corex-nav-sublabel">Branch</div>
                <a href="{{ route('bm.performance') }}" class="corex-nav-subitem {{ request()->routeIs('bm.performance*') ? 'active' : '' }}">Branch Performance</a>
                <a href="{{ route('bm.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('bm.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('bm.listings') }}" class="corex-nav-subitem {{ request()->routeIs('bm.listings*') ? 'active' : '' }}">Branch Listing Stock</a>
                <a href="{{ route('bm.my.dashboard') }}" class="corex-nav-subitem {{ request()->routeIs('bm.my.dashboard') ? 'active' : '' }}">My Agent Dashboard</a>
                <a href="{{ route('admin.deals') }}" class="corex-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                <div class="corex-nav-sublabel">Setup</div>
                <a href="{{ route('bm.worksheet.market') }}" class="corex-nav-subitem {{ request()->routeIs('bm.worksheet.market*') ? 'active' : '' }}">Worksheet Market</a>
                <a href="{{ route('admin.targets') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets*') ? 'active' : '' }}">Daily Activity Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                <a href="{{ route('admin.targets.activity.setup') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.setup*') ? 'active' : '' }}">Activity Setup</a>
                <a href="{{ route('bm.tv-messages') }}" class="corex-nav-subitem {{ request()->routeIs('bm.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @if($effectiveBranchId)
                <a href="{{ route('agent.daily') }}" class="corex-nav-subitem {{ request()->routeIs('agent.daily') ? 'active' : '' }}">Daily Activity Capture</a>
                @endif
                @endif

                {{-- Admin section --}}
                @if($navIsAdmin)
                <div class="corex-nav-sublabel">Admin</div>
                <a href="{{ route('admin.performance') }}" class="corex-nav-subitem {{ request()->routeIs('admin.performance') ? 'active' : '' }}">Performance</a>
                @if(\Illuminate\Support\Facades\Route::has('admin.listings.stock'))
                <a href="{{ route('admin.listings.stock') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.stock*') ? 'active' : '' }}">Company Listing Stock</a>
                @endif
                <a href="{{ route('admin.performance-settings.edit') }}" class="corex-nav-subitem {{ request()->routeIs('admin.performance-settings*') ? 'active' : '' }}">Company Settings</a>
                <a href="{{ route('admin.designations.index') }}" class="corex-nav-subitem {{ request()->routeIs('admin.designations*') ? 'active' : '' }}">Designations</a>
                <a href="{{ route('admin.deals') }}" class="corex-nav-subitem {{ request()->routeIs('admin.deals*') ? 'active' : '' }}">Deal Register</a>
                <a href="{{ route('admin.listings.agents') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.agents*') ? 'active' : '' }}">Listing Stock</a>
                <a href="{{ route('admin.listings.import') }}" class="corex-nav-subitem {{ request()->routeIs('admin.listings.import*') ? 'active' : '' }}">Import Listings</a>
                <a href="{{ route('admin.daily.summary') }}" class="corex-nav-subitem {{ request()->routeIs('admin.daily.summary*') ? 'active' : '' }}">Daily Activity Summary</a>
                <a href="{{ route('admin.targets') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets') ? 'active' : '' }}">Targets</a>
                <a href="{{ route('admin.targets.activity.definitions') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.definitions*') ? 'active' : '' }}">Activity Definitions</a>
                <a href="{{ route('admin.targets.activity.setup') }}" class="corex-nav-subitem {{ request()->routeIs('admin.targets.activity.setup*') ? 'active' : '' }}">Activity Setup</a>
                <a href="{{ route('admin.worksheet-market') }}" class="corex-nav-subitem {{ request()->routeIs('admin.worksheet-market*') ? 'active' : '' }}">Worksheet Market</a>
                <a href="{{ route('admin.branch-assignments') }}" class="corex-nav-subitem {{ request()->routeIs('admin.branch-assignments') ? 'active' : '' }}">Branch Assignments</a>
                <a href="{{ route('admin.users') }}" class="corex-nav-subitem {{ request()->routeIs('admin.users') ? 'active' : '' }}">Users</a>
                <a href="{{ route('admin.tv-messages') }}" class="corex-nav-subitem {{ request()->routeIs('admin.tv-messages*') ? 'active' : '' }}">TV Messages</a>
                @endif

                {{-- Tools (all roles) --}}
                <div class="corex-nav-sublabel">Tools</div>
                <a href="{{ route('tools.commission') }}" class="corex-nav-subitem {{ request()->routeIs('tools.commission') && !request()->query('section') ? 'active' : '' }}">Commission Calculator</a>
                <a href="{{ route('tools.cma') }}" class="corex-nav-subitem {{ request()->routeIs('tools.cma') ? 'active' : '' }}">CMA Certificate Generator</a>
                <a href="{{ route('tools.commission') }}?section=history" class="corex-nav-subitem {{ request()->routeIs('tools.commission') && request()->query('section') === 'history' ? 'active' : '' }}">History & Logs</a>
            </div>
        </div>


        {{-- Commercial Evaluations --}}
        @if(\Illuminate\Support\Facades\Route::has('commercial-evaluations.index'))
        <a href="{{ route('commercial-evaluations.index') }}" class="corex-nav-item {{ request()->is('commercial-evaluations*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <path d="M9 22V12h6v10"/>
            </svg>
            <span>Commercial Evaluations</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Calculators --}}
        @if(\Illuminate\Support\Facades\Route::has('calculators.index'))
        <a href="{{ route('calculators.index') }}" class="corex-nav-item {{ request()->is('calculators*') ? 'active' : '' }}">
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
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Ellie AI --}}
        @if(\Illuminate\Support\Facades\Route::has('ellie.index'))
        <a href="{{ route('ellie.index') }}" class="corex-nav-item {{ request()->is('ai-buddy*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.582a.5.5 0 0 1 0 .962L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>
            </svg>
            <span>Ellie AI</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- P24 Alerts --}}
        @if(\Illuminate\Support\Facades\Route::has('admin.p24.index'))
        <a href="{{ route('admin.p24.index') }}" class="corex-nav-item {{ request()->is('admin/p24*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
            </svg>
            <span>P24 Alerts</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- PDF Splitter --}}
        @if(\Illuminate\Support\Facades\Route::has('tools.pdf_splitter.index') && (!$user || $user->canAccessCorexSection('pdf-splitter')))
        <a href="{{ route('tools.pdf_splitter.index') }}" class="corex-nav-item {{ request()->is('tools/pdf-splitter*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M8 13h8"/>
                <path d="M8 17h8"/>
                <path d="M8 9h2"/>
            </svg>
            <span>PDF Splitter</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif


        {{-- Presentations --}}
        @if(config('features.presentations') && \Illuminate\Support\Facades\Route::has('presentations.index') && (!$user || $user->canAccessCorexSection('presentations')))
        <a href="{{ route('presentations.index') }}" class="corex-nav-item {{ request()->is('presentations*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16v10H4z"/>
                <path d="M8 20h8"/>
                <path d="M12 14v6"/>
            </svg>
            <span>Presentations</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Document Library --}}
        @if(config('features.document_library_v1') && (!$user || $user->canAccessCorexSection('document-library')))
        <a href="{{ route('documents.library.index') }}" class="corex-nav-item {{ request()->is('documents/library*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>
            </svg>
            <span>Document Library</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 18 6-6-6-6"/>
            </svg>
        </a>
        @endif

        {{-- Properties --}}
        @if(config('features.properties') && \Illuminate\Support\Facades\Route::has('corex.properties.index') && (!$user || $user->canAccessCorexSection('properties')))
        <a href="{{ route('corex.properties.index') }}" class="corex-nav-item {{ $corexSection === 'properties' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
            </svg>
            <span>Properties</span>
        </a>
        @endif

<div class="corex-nav-divider"></div>

        {{-- Role Manager --}}
        @if(!$user || $user->canAccessCorexSection('role-manager'))
        <a href="{{ route('corex.role-manager') }}" class="corex-nav-item {{ $corexSection === 'role-manager' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
            </svg>
            <span>Role Manager</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif

        {{-- Knowledge Base --}}
        @if(!$user || $user->canAccessCorexSection('knowledge-base'))
        <a href="{{ route('admin.knowledge.index') }}" class="corex-nav-item {{ request()->is('admin/knowledge*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
            </svg>
            <span>Knowledge Base</span>
        </a>
        @endif

        {{-- Finance Engine --}}
        @if(!$user || $user->canAccessCorexSection('finance-engine'))
        <div class="corex-nav-divider"></div>
        <div class="px-4 py-1">
            <span class="text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500 font-semibold">Finance Engine</span>
        </div>
        <a href="{{ route('admin.finance.definitions') }}" class="corex-nav-item {{ $corexSection === 'finance-engine' && request()->is('admin/finance/definitions') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z" />
            </svg>
            <span>Definitions</span>
        </a>
        <a href="{{ route('admin.finance.audit.index') }}" class="corex-nav-item {{ $corexSection === 'finance-engine' && request()->is('admin/finance/audit*') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
            </svg>
            <span>Audit History</span>
        </a>
        @endif

        {{-- Settings --}}
        @if(!$user || $user->canAccessCorexSection('settings'))
        <a href="{{ route('corex.settings') }}" class="corex-nav-item {{ $corexSection === 'settings' ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span>Settings</span>
            <svg class="corex-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </a>
        @endif
    </nav>

    {{-- User Profile + Impersonation --}}
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
