{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $isEdit      = $user !== null;
    $pageTitle   = $isEdit ? 'Edit User' : 'Add New User';
    $branchList  = $branches ?? collect();
    $designList  = $designations ?? collect();
    $roleList    = $roles ?? collect();

    $nameParts = $isEdit ? explode(' ', $user->name, 2) : [];
    $firstName = old('name', $nameParts[0] ?? '');
    $surname   = old('surname', $nameParts[1] ?? '');

    // Admin Multi-Branch Manager — current managed-branch assignments + the
    // role's admin-ness (drives the "Branches Managed" section in the Role tab).
    $managedRows = ($isEdit && $user->id)
        ? \DB::table('user_managed_branches')->where('user_id', $user->id)->get(['branch_id', 'is_default'])
        : collect();
    $managedIds = $managedRows->pluck('branch_id')->map(fn ($v) => (int) $v);
    $managedDefaultId = optional($managedRows->firstWhere('is_default', 1))->branch_id;
    $managedDefaultId = $managedDefaultId !== null ? (int) $managedDefaultId : null;
    $currentRoleSel = old('role', $isEdit ? ($user->role ?? 'agent') : 'agent');
    $isAdminRoleSel = in_array($currentRoleSel, ['admin', 'super_admin'], true);
@endphp

<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex items-center gap-4">
            @if($isEdit && $user->profilePhotoUrl())
                <img src="{{ $user->profilePhotoUrl() }}" alt=""
                     class="w-12 h-12 rounded-md object-cover flex-shrink-0" style="border:2px solid rgba(255,255,255,0.2);">
            @elseif($isEdit)
                <div class="w-12 h-12 rounded-md flex items-center justify-center flex-shrink-0 text-base font-bold"
                     style="background:rgba(255,255,255,0.15); color:#fff;">
                    {{ $user->initials() }}
                </div>
            @endif
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $pageTitle }}</h1>
                <p class="text-sm mt-0.5 text-white/60">
                    @if($isEdit)
                        {{ $user->email }} &middot; {{ ucwords(str_replace('_',' ',$user->role ?? 'agent')) }}
                        @if($user->is_active && !$user->email_verified_at)
                            <span class="inline-block ml-1 px-2 py-0.5 rounded-full text-[0.6875rem] font-semibold" style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 22%, transparent); color:var(--ds-amber, #f59e0b); vertical-align:middle;">Pending Setup</span>
                        @endif
                    @else
                        Complete all required fields to create a new user account.
                    @endif
                </p>
            </div>
        </div>
        <a href="{{ route('admin.users') }}"
           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300 self-start md:self-auto"
           style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color:var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color:var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="user-main-form"
          method="POST"
          action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}"
          enctype="multipart/form-data"
          novalidate
          x-data="{ activeTab: ['profile','role','finance','compliance'@if($isEdit),'actions'@endif].includes((window.location.hash || '').replace('#','')) ? (window.location.hash || '').replace('#','') : 'profile' }"
          x-init="$watch('activeTab', t => history.replaceState(null, '', '#' + t))"
          autocomplete="off">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- Hidden honeypot to absorb browser autofill --}}
        <input type="text" name="_autocomplete_trap" style="display:none;" tabindex="-1" autocomplete="username">
        <input type="password" name="_autocomplete_trap_pw" style="display:none;" tabindex="-1" autocomplete="new-password">

        {{-- Tab nav (matches the agency edit page) --}}
        <div class="flex gap-1 rounded-md p-1 flex-wrap mb-5" style="background:var(--surface); border:1px solid var(--border);">
            @php
                $userTabs = ['profile' => 'Profile', 'role' => 'Role & Access', 'finance' => 'Finance', 'compliance' => 'Compliance'];
                if ($isEdit) { $userTabs['actions'] = 'Actions'; }
            @endphp
            @foreach($userTabs as $tabKey => $tabLabel)
                <button type="button" @click="activeTab = '{{ $tabKey }}'"
                        class="flex-1 sm:flex-none px-4 py-2 text-sm font-medium rounded-md transition-colors"
                        :style="activeTab === '{{ $tabKey }}' ? 'background:var(--brand-button, #0ea5e9); color:#fff;' : 'color:var(--text-secondary);'">
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>

        {{-- ====================== PROFILE TAB ====================== --}}
        <div x-show="activeTab === 'profile'" x-cloak class="space-y-5">

            {{-- Card: Personal Details --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Personal Details</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">First Name <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ $firstName }}" required
                               autocomplete="off" placeholder="First name"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Surname <span class="text-red-500">*</span></label>
                        <input type="text" name="surname" value="{{ $surname }}" required
                               autocomplete="off" placeholder="Surname"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Email Address <span class="text-red-500">*</span></label>
                        <input type="email" name="email" value="{{ old('email', $isEdit ? $user->email : '') }}" required
                               autocomplete="off" placeholder="user@example.com"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                            Display Email <span style="color:var(--text-muted); font-weight:400;">(optional — shown to clients instead of the login email)</span>
                        </label>
                        <input type="email" name="display_email" value="{{ old('display_email', $isEdit ? $user->display_email : '') }}"
                               autocomplete="off" placeholder="Leave blank to use the login email"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        <p class="mt-1 text-xs" style="color:var(--text-muted);">Used on presentations, e-sign documents, outreach &amp; portals. Login &amp; password reset always use the real email.</p>
                    </div>
                    @if($isEdit)
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                            Password <span style="color:var(--text-muted); font-weight:400;">(leave blank to keep)</span>
                        </label>
                        <input type="password" name="password"
                               autocomplete="new-password" placeholder="********"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    @else
                    <div class="flex items-end">
                        <div class="rounded-md px-3 py-2.5 text-xs w-full" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">
                            An invitation email will be sent so the user can set their own password.
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Card: Contact Details --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Contact Details</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Phone</label>
                        <input type="tel" name="phone" value="{{ old('phone', $isEdit ? $user->phone : '') }}" placeholder="Landline"
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Cell <span class="text-red-500">*</span></label>
                        <input type="tel" name="cell" value="{{ old('cell', $isEdit ? $user->cell : '') }}" placeholder="Mobile" required
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        @error('cell')
                            <p class="text-xs mt-1" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Fax</label>
                        <input type="tel" name="fax" value="{{ old('fax', $isEdit ? $user->fax : '') }}" placeholder="Fax number"
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">ID Number</label>
                        <input type="text" name="id_number" value="{{ old('id_number', $isEdit ? $user->id_number : '') }}" placeholder="SA ID number"
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Website</label>
                        <input type="url" name="website" value="{{ old('website', $isEdit ? $user->website : '') }}" placeholder="https://..."
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== ROLE & ACCESS TAB ====================== --}}
        <div x-show="activeTab === 'role'" x-cloak class="space-y-5">

            {{-- Card: Role & Access --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Role &amp; Access</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Role</label>
                        <select name="role" class="w-full rounded-md px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            @foreach($roleList as $role)
                                @if(!$role->is_owner)
                                <option value="{{ $role->name }}" {{ old('role', $isEdit ? $user->role : 'agent') === $role->name ? 'selected' : '' }}>{{ $role->label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Branch</label>
                        <select name="branch_id" class="w-full rounded-md px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="">(no branch)</option>
                            @foreach($branchList as $b)
                            <option value="{{ $b->id }}" {{ (string) old('branch_id', $isEdit ? $user->branch_id : '') === (string) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Designation</label>
                        <select name="designation" class="w-full rounded-md px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            @php $des = old('designation', $isEdit ? ($user->designation ?? '') : ''); @endphp
                            <option value="" {{ $des === '' ? 'selected' : '' }}>(none)</option>
                            @foreach($designList as $d)
                            <option value="{{ $d->name }}" {{ $des === $d->name ? 'selected' : '' }}>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Admin Multi-Branch Manager — only shown for admin roles.
                     Assign which branches this admin manages and which one they
                     log in as (default). Identity only; does not change scope. --}}
                <div class="mt-5 pt-5" style="border-top:1px solid var(--border);"
                     x-data="{ adminRole: {{ $isAdminRoleSel ? 'true' : 'false' }} }"
                     x-init="document.querySelector('[name=role]')?.addEventListener('change', e => adminRole = ['admin','super_admin'].includes(e.target.value))"
                     x-show="adminRole" x-cloak>
                    <label class="block text-xs font-semibold uppercase tracking-wider mb-1.5" style="color:var(--text-secondary);">Branches Managed</label>
                    <p class="text-xs mb-3" style="color:var(--text-muted);">Admins can manage several branches and act as each one's branch manager. Tick every branch this user manages, then mark the <strong>default</strong> — the branch they log in as.</p>
                    @if($branchList->isEmpty())
                        <p class="text-xs" style="color:var(--text-muted);">No branches available.</p>
                    @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($branchList as $b)
                            @php $bid = (int) $b->id; $isManaged = $managedIds->contains($bid); $isDef = $managedDefaultId === $bid; @endphp
                            <div class="flex items-center justify-between gap-3 rounded-md px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border);">
                                <label class="flex items-center gap-2 cursor-pointer text-sm" style="color:var(--text-primary);">
                                    <input type="checkbox" name="managed_branches[]" value="{{ $bid }}" {{ $isManaged ? 'checked' : '' }}>
                                    {{ $b->name }}
                                </label>
                                <label class="flex items-center gap-1.5 cursor-pointer text-xs" style="color:var(--text-muted);">
                                    <input type="radio" name="default_branch_id" value="{{ $bid }}" {{ $isDef ? 'checked' : '' }}>
                                    Default
                                </label>
                            </div>
                        @endforeach
                    </div>
                    @endif
                </div>

                {{-- Candidate Practitioner Info (PPRA compliance) --}}
                @php
                    $isCandidateDesignation = stripos($des, 'Candidate') !== false;
                @endphp
                <div x-data="{ isCandidate: {{ $isCandidateDesignation ? 'true' : 'false' }} }"
                     x-init="document.querySelector('[name=designation]')?.addEventListener('change', e => isCandidate = (e.target.value || '').toLowerCase().includes('candidate'))"
                     class="mt-3" x-show="isCandidate" x-cloak>
                    <div class="rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <p class="text-xs" style="color:var(--text-muted);">
                            Candidate practitioner documents require authorisation before processing.
                            All full-status agents, principals, admins, and owners in the same branch
                            can authorise — shared queue, no assigned supervisor.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-5 mt-4 pt-4" style="border-top:1px solid var(--border);">
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="hidden" name="can_capture_rentals" value="0">
                        <input type="checkbox" name="can_capture_rentals" value="1" class="rounded"
                               style="accent-color:var(--brand-icon, #0ea5e9);"
                               {{ old('can_capture_rentals', $isEdit ? (int)($user->can_capture_rentals ?? 0) : 0) ? 'checked' : '' }}>
                        Can Capture Rentals
                    </label>
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="hidden" name="counts_for_branch_split" value="0">
                        <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded"
                               style="accent-color:var(--brand-icon, #0ea5e9);"
                               {{ old('counts_for_branch_split', $isEdit ? (int)($user->counts_for_branch_split ?? 1) : 1) ? 'checked' : '' }}>
                        Counts for Branch Split
                    </label>
                    {{-- Agency Public API — agent appears on the agency website(s). Spec §2 (layer 3).
                         Only shown once the agency has a website (≥1 API key). --}}
                    @php
                        $agentAgencyId = $isEdit ? $user->agency_id : auth()->user()?->effectiveAgencyId();
                        $agencyHasWebsite = $agentAgencyId && \App\Models\AgencyApiKey::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                            ->where('agency_id', $agentAgencyId)->whereNull('revoked_at')->exists();
                    @endphp
                    @if($agencyHasWebsite)
                        @if($isEdit)
                        {{-- Quick on/off toggle (immediate, fires the agent webhook) --}}
                        <div class="flex items-center gap-2.5 text-sm"
                             x-data="{
                                on: {{ (int)($user->show_on_website ?? 0) ? 'true' : 'false' }},
                                loading: false, err: '',
                                csrf: '{{ csrf_token() }}',
                                url: '{{ route('admin.users.toggle-website', $user) }}',
                                async toggle() {
                                    if (this.loading) return;
                                    this.loading = true; this.err = '';
                                    const fd = new FormData(); fd.append('_token', this.csrf);
                                    try {
                                        const r = await fetch(this.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                                        const j = await r.json().catch(() => ({}));
                                        if (r.ok && j.success) { this.on = j.show_on_website; } else { this.err = j.message || ('HTTP ' + r.status); }
                                    } catch (e) { this.err = e.message || 'Network error'; }
                                    this.loading = false;
                                }
                             }">
                            <button type="button" @click.stop="toggle()" :disabled="loading"
                                    class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                    :style="on ? 'background:var(--ds-green)' : 'background:var(--surface-3)'"
                                    role="switch" :aria-checked="on">
                                <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                      style="background:#fff; margin-top:2px;"
                                      :style="on ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                            </button>
                            <span style="color:var(--text-secondary);">Show on website</span>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase"
                                  :style="on ? 'background:rgba(34,197,94,0.15); color:var(--ds-green);' : 'background:var(--surface-3); color:var(--text-muted);'"
                                  x-text="on ? 'On' : 'Off'"></span>
                            <span x-show="err" x-cloak class="text-[0.6875rem]" style="color:var(--ds-crimson);" x-text="err"></span>
                        </div>
                        @else
                        {{-- New user: form checkbox (no id yet to toggle) --}}
                        <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                            <input type="hidden" name="show_on_website" value="0">
                            <input type="checkbox" name="show_on_website" value="1" class="rounded"
                                   style="accent-color:var(--brand-icon, #0ea5e9);"
                                   {{ old('show_on_website') ? 'checked' : '' }}>
                            Show on website
                        </label>
                        @endif
                    @endif

                    {{-- Property24 opt-out. When excluded the agent is unpublished on
                         P24 (published=false / status=Inactive) and never attached to
                         newly-syndicated listings. The edit-mode switch pushes the
                         change to P24 immediately and reports P24's actual result;
                         the create-mode checkbox applies on save. --}}
                    @if($isEdit)
                    <div class="flex items-center gap-2.5 text-sm mt-3 flex-wrap"
                         x-data="{
                            excluded: {{ (int)($user->exclude_from_p24 ?? 0) ? 'true' : 'false' }},
                            loading: false, note: '', noteErr: false,
                            csrf: '{{ csrf_token() }}',
                            url: '{{ route('admin.users.toggle-p24', $user) }}',
                            async toggle() {
                                if (this.loading) return;
                                this.loading = true; this.note = '';
                                const fd = new FormData(); fd.append('_token', this.csrf);
                                try {
                                    const r = await fetch(this.url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                                    const j = await r.json().catch(() => ({}));
                                    if (r.ok && j.success) {
                                        this.excluded = j.exclude_from_p24;
                                        this.note = j.message || '';
                                        this.noteErr = (j.p24_ok === false);
                                    } else {
                                        this.note = j.message || ('HTTP ' + r.status);
                                        this.noteErr = true;
                                    }
                                } catch (e) { this.note = e.message || 'Network error'; this.noteErr = true; }
                                this.loading = false;
                            }
                         }">
                        <button type="button" @click.stop="toggle()" :disabled="loading"
                                class="relative inline-flex h-5 w-9 flex-shrink-0 rounded-full transition-colors duration-200"
                                :style="excluded ? 'background:var(--ds-crimson)' : 'background:var(--surface-3)'"
                                role="switch" :aria-checked="excluded">
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full shadow-sm transition-transform duration-200"
                                  style="background:#fff; margin-top:2px;"
                                  :style="excluded ? 'transform:translateX(18px); margin-left:1px;' : 'transform:translateX(2px); margin-left:1px;'"></span>
                        </button>
                        <span style="color:var(--text-secondary);">Exclude from Property24</span>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[0.6875rem] font-bold uppercase"
                              :style="excluded ? 'background:rgba(220,38,38,0.15); color:var(--ds-crimson);' : 'background:var(--surface-3); color:var(--text-muted);'"
                              x-text="excluded ? 'Hidden' : 'On P24'"></span>
                        <span x-show="loading" x-cloak class="text-[0.6875rem]" style="color:var(--text-muted);">syncing…</span>
                        <span x-show="!loading && note" x-cloak class="text-[0.6875rem]"
                              :style="noteErr ? 'color:var(--ds-crimson);' : 'color:var(--ds-green);'" x-text="note"></span>
                    </div>
                    @else
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer mt-3" style="color:var(--text-secondary);">
                        <input type="hidden" name="exclude_from_p24" value="0">
                        <input type="checkbox" name="exclude_from_p24" value="1" class="rounded"
                               style="accent-color:var(--brand-icon, #0ea5e9);"
                               {{ old('exclude_from_p24') ? 'checked' : '' }}>
                        Exclude from Property24 <span style="color:var(--text-muted);">(hide this agent on P24)</span>
                    </label>
                    @endif
                </div>
            </div>
        </div>

        {{-- ====================== FINANCE TAB ====================== --}}
        <div x-show="activeTab === 'finance'" x-cloak class="space-y-5">

            {{-- Card: Finance --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Finance</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Agent Cut %</label>
                        <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                               value="{{ old('agent_cut_percent', $isEdit ? ($user->agent_cut_percent ?? 50) : 50) }}"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">PAYE Method</label>
                            @php $pm = old('paye_method', $isEdit ? ($user->paye_method ?? 'percentage') : 'percentage'); @endphp
                            <select name="paye_method" class="w-full rounded-md px-3 py-2.5 text-sm outline-none"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="percentage" {{ $pm === 'percentage' ? 'selected' : '' }}>Percentage</option>
                                <option value="fixed"      {{ $pm === 'fixed' ? 'selected' : '' }}>Fixed</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">PAYE Value</label>
                            <input type="number" step="0.01" min="0" name="paye_value"
                                   value="{{ old('paye_value', $isEdit ? ($user->paye_value ?? 0) : 0) }}"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                        </div>
                    </div>
                    <div class="pt-2 sm:col-span-2" style="border-top:1px solid var(--border);">
                        <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                            <input type="hidden" name="sliding_enabled" value="0">
                            <input type="checkbox" name="sliding_enabled" value="1" class="rounded"
                                   style="accent-color:var(--brand-icon, #0ea5e9);"
                                   {{ old('sliding_enabled', $isEdit ? (int)($user->sliding_enabled ?? 0) : 0) ? 'checked' : '' }}>
                            Sliding Scale
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== COMPLIANCE TAB ====================== --}}
        <div x-show="activeTab === 'compliance'" x-cloak class="space-y-5">

            {{-- Card: FFC & PPRA --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">FFC &amp; PPRA</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">FFC Number</label>
                        <input type="text" name="ffc_number" value="{{ old('ffc_number', $isEdit ? $user->ffc_number : '') }}" placeholder="Certificate number"
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">FFC Expiry Date</label>
                        <input type="date" name="ffc_expiry_date" value="{{ old('ffc_expiry_date', $isEdit ? ($user->ffc_expiry_date?->format('Y-m-d') ?? '') : '') }}"
                               autocomplete="off"
                               class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                               onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                    </div>
                </div>
                @if($isEdit && auth()->user()->hasPermission('edit_user_ppra_status'))
                <div class="mt-5 pt-5" style="border-top:1px solid var(--border);" id="ppra">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        <h4 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-primary);">PPRA Registration Status</h4>
                    </div>
                    <p class="text-[11px] mb-3" style="color:var(--text-muted);">
                        Verify at PPRA public register:
                        <a href="https://theppra.org.za/agent_agency_search" target="_blank" style="color:var(--brand-icon); text-decoration:underline;">theppra.org.za</a>.
                        FFCs are valid 3 years — annual re-verification recommended.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">PPRA Status</label>
                            @php $ppraVal = old('ppra_status', $user->ppra_status ?? ''); @endphp
                            <select name="ppra_status"
                                    class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                    onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                                <option value="" {{ $ppraVal === '' ? 'selected' : '' }}>-- Not set --</option>
                                <option value="active" {{ $ppraVal === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="pending" {{ $ppraVal === 'pending' ? 'selected' : '' }}>Pending</option>
                                <option value="expired" {{ $ppraVal === 'expired' ? 'selected' : '' }}>Expired</option>
                                <option value="suspended" {{ $ppraVal === 'suspended' ? 'selected' : '' }}>Suspended</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <div>
                                <span class="text-xs" style="color:var(--text-muted);">Last verified:</span>
                                @if($user->ppra_last_verified_at)
                                    @php $ppraVerified = \Carbon\Carbon::parse($user->ppra_last_verified_at); @endphp
                                    <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $ppraVerified->format('d M Y') }}</span>
                                    @if($ppraVerified->lt(now()->subYear()))
                                    <span class="text-[10px] font-semibold" style="color:var(--ds-amber, #f59e0b);"> (overdue — over 12 months)</span>
                                    @endif
                                @else
                                    <span class="text-xs font-medium" style="color:var(--ds-crimson);">Never</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Card: Files --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Files</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    {{-- Agent Photo --}}
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                            Agent Photo
                        </label>
                        <x-agent-photo-cropper name="agent_photo"
                            :current="$isEdit ? $user->profilePhotoUrl() : null" />
                        @error('agent_photo')
                            <p class="text-[11px] mt-1.5" style="color:var(--ds-crimson);">{{ $message }}</p>
                        @enderror
                        @if($isEdit && $user->profilePhotoUrl())
                        <button type="button" class="text-[11px] font-medium mt-2 px-2 py-1 rounded-md transition-colors"
                                style="color:var(--ds-crimson); background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);"
                                onclick="if(confirm('Remove agent photo?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML='<input type=hidden name=_token value='+document.querySelector('meta[name=csrf-token]').getAttribute('content')+'><input name=field value=agent_photo>';document.body.appendChild(f);f.submit();}">Remove current photo</button>
                        @endif
                    </div>
                    {{-- FFC Certificate --}}
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                            FFC Certificate
                        </label>
                        <div class="text-[11px] mb-2" style="color:var(--text-muted);">pdf/jpg/png, max 5MB</div>
                        @if($isEdit && $user->ffc_certificate_path)
                        <div class="flex items-center gap-3 mb-3 p-2.5 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                            <a href="{{ asset('storage/'.$user->ffc_certificate_path) }}" target="_blank"
                               class="text-xs flex-1 truncate" style="color:var(--brand-icon, #0ea5e9);">
                                {{ basename($user->ffc_certificate_path) }}
                            </a>
                            <button type="button" class="text-xs font-medium px-2 py-1 rounded-md transition-colors"
                                    style="color:var(--ds-crimson); background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);"
                                    onclick="if(confirm('Remove FFC certificate?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML=document.querySelector('meta[name=csrf-token]').content?'<input type=hidden name=_token value='+document.querySelector('meta[name=csrf-token]').getAttribute('content')+'><input name=field value=ffc_certificate>':'';;document.body.appendChild(f);f.submit();}">Remove</button>
                        </div>
                        @endif
                        <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm rounded-md px-3 py-2"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                    </div>
                </div>
            </div>

            {{-- Card: Compliance Documents (edit only) --}}
            @if($isEdit)
            @php
                $compDocTypes = \App\Models\UserDocument::$documentTypeLabels;
                unset($compDocTypes['other'], $compDocTypes['profile_photo']);
                $userDocs = $user->documents()->orderByDesc('created_at')->get()->groupBy('document_type')->map(fn ($g) => $g->first());
                $userOverrides = \App\Models\Compliance\UserComplianceOverride::where('user_id', $user->id)->active()->get()->keyBy('compliance_item');
                $agencyProvisions = [];
                foreach (\App\Models\Compliance\AgencyComplianceProvision::TYPES as $pt) {
                    $agencyProvisions[$pt] = \App\Models\Compliance\AgencyComplianceProvision::coversUser($user, $pt);
                }
            @endphp
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);"
                 x-data="{ overrideModal: false, overrideItem: '', overrideLabel: '', overrideType: 'not_applicable', revokeModal: false, revokeId: null, revokeLabel: '' }">
                <div class="flex items-center gap-2 mb-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Compliance Documents</h3>
                </div>
                <div class="space-y-1.5">
                    @foreach($compDocTypes as $docType => $docLabel)
                    @php
                        $doc = $userDocs->get($docType);
                        $override = $userOverrides->get($docType);
                        $provision = $agencyProvisions[$docType] ?? null;
                        if ($override) {
                            $dotColor = 'var(--text-muted, #64748b)'; $statusText = ucfirst(str_replace('_', ' ', $override->override_type)) . ': ' . \Illuminate\Support\Str::limit($override->reason, 40);
                        } elseif ($provision) {
                            $dotColor = 'var(--ds-green, #059669)'; $statusText = 'Covered by agency' . ($provision->policy_reference ? ': ' . $provision->policy_reference : '');
                        } elseif ($doc && $doc->status === 'verified') {
                            $dotColor = 'var(--ds-green, #059669)'; $statusText = 'Verified' . ($doc->uploaded_by_admin ? ' (admin upload)' : '');
                        } elseif ($doc && $doc->status === 'pending') {
                            $dotColor = 'var(--ds-amber, #f59e0b)'; $statusText = 'Pending verification';
                        } else {
                            $dotColor = 'var(--ds-crimson, #c41e3a)'; $statusText = 'Not uploaded';
                        }
                    @endphp
                    <div class="flex items-center gap-2 py-1.5">
                        <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColor }};"></span>
                        <span class="text-xs font-medium flex-1" style="color:var(--text-primary);">{{ $docLabel }}</span>
                        <span class="text-[10px] mr-2" style="color:var(--text-muted);">{{ $statusText }}</span>

                        @if($override)
                        {{-- Show revoke button for active overrides --}}
                        <button type="button" @click="revokeModal=true; revokeId={{ $override->id }}; revokeLabel='{{ addslashes($docLabel) }}'"
                                class="text-[10px] font-medium px-2 py-0.5 rounded-md" style="color:var(--ds-crimson, #c41e3a); border:1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);">Revoke</button>
                        @else
                        {{-- Action buttons --}}
                        <a href="{{ route('admin.user.documents.upload', ['user' => $user, 'type' => $docType]) }}"
                           class="text-[10px] font-medium px-2 py-0.5 rounded-md" style="color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);">Upload</a>
                        <button type="button" @click="overrideModal=true; overrideItem='{{ $docType }}'; overrideLabel='{{ addslashes($docLabel) }}'; overrideType='not_applicable'"
                                class="text-[10px] font-medium px-2 py-0.5 rounded-md" style="color:var(--text-muted); border:1px solid var(--border);">N/A</button>
                        <button type="button" @click="overrideModal=true; overrideItem='{{ $docType }}'; overrideLabel='{{ addslashes($docLabel) }}'; overrideType='exempt'"
                                class="text-[10px] font-medium px-2 py-0.5 rounded-md" style="color:var(--text-muted); border:1px solid var(--border);">Exempt</button>
                        @endif
                    </div>
                    @endforeach
                </div>

                {{-- Override Modal --}}
                <div x-show="overrideModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);">
                    <div class="rounded-md p-6 w-full max-w-md mx-4" style="background:var(--surface); border:1px solid var(--border);" @click.outside="overrideModal=false">
                        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">
                            Set Override: <span x-text="overrideLabel"></span>
                        </h3>
                        <form method="POST" action="{{ route('admin.user.overrides.store', $user) }}">
                            @csrf
                            <input type="hidden" name="compliance_item" :value="overrideItem">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Override Type</label>
                                    <select name="override_type" x-model="overrideType" class="w-full rounded-md px-3 py-2 text-sm"
                                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                        <option value="not_applicable">Not Applicable</option>
                                        <option value="exempt">Exempt</option>
                                        <option value="waived">Waived</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Reason <span class="text-red-500">*</span></label>
                                    <textarea name="reason" :required="overrideModal" minlength="15" rows="3" placeholder="Minimum 15 characters - explain why this item is exempt/not applicable"
                                              class="w-full rounded-md px-3 py-2 text-sm"
                                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Expires (optional)</label>
                                    <input type="date" name="expires_at" class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                            </div>
                            <div class="flex items-center gap-3 mt-4">
                                <button type="submit" class="corex-btn-primary">Save Override</button>
                                <button type="button" @click="overrideModal=false" class="corex-btn-outline">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                {{-- Revoke Modal --}}
                <div x-show="revokeModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);">
                    <div class="rounded-md p-6 w-full max-w-md mx-4" style="background:var(--surface); border:1px solid var(--border);" @click.outside="revokeModal=false">
                        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">
                            Revoke Override: <span x-text="revokeLabel"></span>
                        </h3>
                        <form method="POST" :action="'/corex/admin/compliance-overrides/' + revokeId + '/revoke'">
                            @csrf
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Reason for Revocation <span class="text-red-500">*</span></label>
                                <textarea name="revoke_reason" required minlength="10" rows="3" placeholder="Minimum 10 characters"
                                          class="w-full rounded-md px-3 py-2 text-sm"
                                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                            </div>
                            <div class="flex items-center gap-3 mt-4">
                                <button type="submit" class="corex-btn-danger">Revoke</button>
                                <button type="button" @click="revokeModal=false" class="corex-btn-outline">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- ====================== ACTIONS TAB (edit only) ====================== --}}
        @if($isEdit)
        <div x-show="activeTab === 'actions'" x-cloak class="space-y-5">

            {{-- Card: Pending Invite (only for users who haven't set up yet) --}}
            @if($user->is_active && !$user->email_verified_at)
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);">
                <div class="flex items-center gap-2 mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--ds-amber, #f59e0b);"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Invitation Pending</h3>
                </div>
                <p class="text-xs mb-3" style="color:var(--text-muted);">This user has not yet set up their password. You can resend the invitation email.</p>
                <form method="POST" action="{{ route('admin.users.resend-invite', $user) }}">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2 rounded-md text-sm font-medium transition-colors"
                            style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent);">
                        Resend Invitation Email
                    </button>
                </form>
            </div>
            @endif

            {{-- Card: Danger Zone --}}
            <div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
                <div class="flex items-center gap-2 mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--ds-crimson);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Danger Zone</h3>
                </div>
                <div class="flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2 rounded-md text-sm font-medium transition-colors w-full sm:w-auto"
                                style="{{ $user->is_active ? 'background:color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);' : 'background:color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); color:var(--ds-green, #059669); border:1px solid color-mix(in srgb, var(--ds-green, #059669) 25%, transparent);' }}">
                            {{ $user->is_active ? 'Deactivate User' : 'Activate User' }}
                        </button>
                    </form>
                    <button type="button"
                            data-agent-delete
                            data-user-id="{{ $user->id }}"
                            data-user-name="{{ $user->name }}"
                            class="px-4 py-2 rounded-md text-sm font-medium w-full sm:w-auto"
                            style="background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); color:var(--ds-crimson, #c41e3a); border:1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 25%, transparent);">
                        Delete User
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- Sticky bottom action bar --}}
        <div class="sticky bottom-0 z-10 -mx-4 lg:-mx-6 px-4 lg:px-6 py-4 mt-5"
             style="background:linear-gradient(to top, var(--bg) 60%, transparent);">
            <div class="flex items-center justify-end gap-3 flex-wrap">
                <a href="{{ route('admin.users') }}" class="corex-btn-outline">
                    Cancel
                </a>
                @if(!$isEdit)
                <button type="submit" form="user-main-form"
                        name="test_agent" value="1"
                        class="px-5 py-2.5 rounded-md text-sm font-semibold transition-colors"
                        style="background:color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color:var(--ds-amber, #f59e0b); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent);"
                        title="Create without sending an invite email. Registers the agent on Property24 immediately.">
                    Test Agent
                </button>
                @endif
                <button type="submit" form="user-main-form" class="corex-btn-primary">
                    {{ $isEdit ? 'Save Changes' : 'Create User' }}
                </button>
            </div>
        </div>
    </form>

    {{-- Communication Capture (AT-37) — rendered OUTSIDE the main user form (forms
         cannot nest). Reuses the Settings → Email Setup per-user component so the
         same management lives on the user record. Edit-only: a mailbox links to an
         existing user. --}}
    @if($isEdit)
        @permission('manage_communication_mailboxes')
        <div class="mt-6 rounded-md p-4 lg:p-6" style="background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb);">
            <h3 class="text-sm font-bold uppercase tracking-wider mb-1" style="color:var(--text-primary, #1f2937);">Communication Capture</h3>
            <p class="text-xs mb-4" style="color: var(--text-muted, #6b7280);">Link this user's mailbox to feed the Communication Archive. The password is stored encrypted and never shown — retrieving it is a separate, logged action.</p>
            @include('settings.email-setup._user-mailbox', ['user' => $user])
        </div>
        @endpermission
    @endif

</div>

@isset($user)
@include('admin.users._delete-modal')
@endisset
@endsection
