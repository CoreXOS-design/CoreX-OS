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
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div class="rounded-2xl px-6 py-5 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex items-center gap-4">
            @if($isEdit && $user->agent_photo_path)
                <img src="{{ asset('storage/'.$user->agent_photo_path) }}" alt=""
                     class="w-12 h-12 rounded-xl object-cover flex-shrink-0" style="border:2px solid rgba(255,255,255,0.2);">
            @elseif($isEdit)
                <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 text-base font-bold"
                     style="background:rgba(255,255,255,0.15); color:#fff;">
                    {{ strtoupper(substr($user->name,0,1)) }}{{ strtoupper(substr(strstr($user->name,' ') ?: '',1,1)) }}
                </div>
            @endif
            <div>
                <h2 class="text-xl font-bold text-white">{{ $pageTitle }}</h2>
                <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.55);">
                    @if($isEdit)
                        {{ $user->email }} &middot; {{ ucwords(str_replace('_',' ',$user->role ?? 'agent')) }}
                        @if($user->is_active && !$user->email_verified_at)
                            <span class="inline-block ml-1 px-2 py-0.5 rounded-full text-[11px] font-semibold" style="background:rgba(245,158,11,0.2); color:#fbbf24; vertical-align:middle;">Pending Setup</span>
                        @endif
                    @else
                        Complete all required fields to create a new user account.
                    @endif
                </div>
            </div>
        </div>
        <a href="{{ route('admin.users') }}"
           class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2"
           style="background:rgba(255,255,255,0.12); color:#fff;"
           onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Back
        </a>
    </div>

    @if(session('status'))
        <div class="rounded-xl px-4 py-3 text-sm font-medium" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl px-4 py-3 text-sm" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $isEdit ? route('admin.users.update', $user) : route('admin.users.store') }}"
          enctype="multipart/form-data"
          autocomplete="off">
        @csrf
        @if($isEdit) @method('PUT') @endif

        {{-- Hidden honeypot to absorb browser autofill --}}
        <input type="text" name="_autocomplete_trap" style="display:none;" tabindex="-1" autocomplete="username">
        <input type="password" name="_autocomplete_trap_pw" style="display:none;" tabindex="-1" autocomplete="new-password">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- ═══════════ LEFT COLUMN (2/3) ═══════════ --}}
            <div class="lg:col-span-2 space-y-5">

                {{-- Card: Personal Details --}}
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
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

                {{-- Card: Role & Access --}}
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Role & Access</h3>
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

                    {{-- Candidate Practitioner Info (PPRA compliance) --}}
                    @php
                        $isCandidateDesignation = stripos($des, 'Candidate') !== false;
                    @endphp
                    <div x-data="{ isCandidate: {{ $isCandidateDesignation ? 'true' : 'false' }} }"
                         x-init="document.querySelector('[name=designation]')?.addEventListener('change', e => isCandidate = (e.target.value || '').toLowerCase().includes('candidate'))"
                         class="mt-3" x-show="isCandidate" x-cloak>
                        <div class="rounded-lg p-3" style="background:var(--surface-2); border:1px solid var(--border);">
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
                    </div>
                </div>

                {{-- Card: Contact Details --}}
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
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
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Cell <span style="color:#ef4444;">*</span></label>
                            <input type="tel" name="cell" value="{{ old('cell', $isEdit ? $user->cell : '') }}" placeholder="Mobile" required
                                   autocomplete="off"
                                   class="w-full rounded-md px-3 py-2.5 text-sm outline-none transition-colors"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                                   onfocus="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onblur="this.style.borderColor='var(--border)'">
                            @error('cell')
                                <p class="text-xs mt-1" style="color:#f87171;">{{ $message }}</p>
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
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">FFC Number</label>
                            <input type="text" name="ffc_number" value="{{ old('ffc_number', $isEdit ? $user->ffc_number : '') }}" placeholder="Certificate number"
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

            {{-- ═══════════ RIGHT COLUMN (1/3) ═══════════ --}}
            <div class="space-y-5">

                {{-- Card: Finance --}}
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Finance</h3>
                    </div>
                    <div class="space-y-4">
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
                        <div class="pt-2" style="border-top:1px solid var(--border);">
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

                {{-- Card: Files --}}
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-5">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Files</h3>
                    </div>
                    <div class="space-y-5">
                        {{-- Agent Photo --}}
                        <div>
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                                Agent Photo
                            </label>
                            <div class="text-[11px] mb-2" style="color:var(--text-muted);">jpg/png/webp, max 2MB</div>
                            @if($isEdit && $user->agent_photo_path)
                            <div class="flex items-center gap-3 mb-3 p-2.5 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border);">
                                <img src="{{ asset('storage/'.$user->agent_photo_path) }}" alt="Photo"
                                     class="w-10 h-10 rounded-lg object-cover flex-shrink-0" style="border:1px solid var(--border);">
                                <span class="text-xs flex-1 truncate" style="color:var(--text-secondary);">Current photo</span>
                                <button type="button" class="text-xs font-medium px-2 py-1 rounded-md transition-colors"
                                        style="color:#ef4444; background:rgba(239,68,68,0.1);"
                                        onclick="if(confirm('Remove agent photo?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML='@csrf<input name=field value=agent_photo>';document.body.appendChild(f);f.submit();}">Remove</button>
                            </div>
                            @endif
                            <input type="file" name="agent_photo" accept="image/jpeg,image/png,image/webp"
                                   class="block w-full text-sm rounded-md px-3 py-2"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                        </div>
                        {{-- FFC Certificate --}}
                        <div class="pt-4" style="border-top:1px solid var(--border);">
                            <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">
                                FFC Certificate
                            </label>
                            <div class="text-[11px] mb-2" style="color:var(--text-muted);">pdf/jpg/png, max 5MB</div>
                            @if($isEdit && $user->ffc_certificate_path)
                            <div class="flex items-center gap-3 mb-3 p-2.5 rounded-lg" style="background:var(--surface-2); border:1px solid var(--border);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                <a href="{{ asset('storage/'.$user->ffc_certificate_path) }}" target="_blank"
                                   class="text-xs flex-1 truncate" style="color:var(--brand-icon, #0ea5e9);">
                                    {{ basename($user->ffc_certificate_path) }}
                                </a>
                                <button type="button" class="text-xs font-medium px-2 py-1 rounded-md transition-colors"
                                        style="color:#ef4444; background:rgba(239,68,68,0.1);"
                                        onclick="if(confirm('Remove FFC certificate?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $user) }}';f.innerHTML='@csrf<input name=field value=ffc_certificate>';document.body.appendChild(f);f.submit();}">Remove</button>
                            </div>
                            @endif
                            <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                                   class="block w-full text-sm rounded-md px-3 py-2"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                        </div>
                    </div>
                </div>

                {{-- Card: Pending Invite (only for users who haven't set up yet) --}}
                @if($isEdit && $user->is_active && !$user->email_verified_at)
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid rgba(245,158,11,0.25);">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:#d97706;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Invitation Pending</h3>
                    </div>
                    <p class="text-xs mb-3" style="color:var(--text-muted);">This user has not yet set up their password. You can resend the invitation email.</p>
                    <form method="POST" action="{{ route('admin.users.resend-invite', $user) }}">
                        @csrf
                        <button type="submit"
                                class="px-4 py-2 rounded-md text-sm font-medium transition-colors"
                                style="background:rgba(245,158,11,0.12); color:#d97706; border:1px solid rgba(245,158,11,0.3);">
                            Resend Invitation Email
                        </button>
                    </form>
                </div>
                @endif

                {{-- Card: Actions (edit only) --}}
                @if($isEdit)
                <div class="rounded-xl p-6" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:#ef4444;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider" style="color:var(--text-primary);">Danger Zone</h3>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline">
                            @csrf
                            <button type="submit"
                                    class="px-4 py-2 rounded-md text-sm font-medium transition-colors w-full sm:w-auto"
                                    style="{{ $user->is_active ? 'background:rgba(245,158,11,0.1); color:#d97706; border:1px solid rgba(245,158,11,0.25);' : 'background:rgba(34,197,94,0.1); color:#16a34a; border:1px solid rgba(34,197,94,0.25);' }}">
                                {{ $user->is_active ? 'Deactivate User' : 'Activate User' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.users.delete', $user) }}" class="inline"
                              onsubmit="return confirm('Delete {{ addslashes($user->name) }}? This cannot be undone.');">
                            @csrf
                            <button type="submit"
                                    class="px-4 py-2 rounded-md text-sm font-medium w-full sm:w-auto"
                                    style="background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.25);">
                                Delete User
                            </button>
                        </form>
                    </div>
                </div>
                @endif

            </div>
        </div>

        {{-- Sticky bottom action bar --}}
        <div class="sticky bottom-0 z-10 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-4 mt-5"
             style="background:linear-gradient(to top, var(--bg) 60%, transparent);">
            <div class="max-w-7xl mx-auto flex items-center justify-end gap-3">
                <a href="{{ route('admin.users') }}"
                   class="px-5 py-2.5 rounded-md text-sm font-medium transition-colors"
                   style="color:var(--text-secondary); border:1px solid var(--border);"
                   onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                    Cancel
                </a>
                <button type="submit"
                        class="px-6 py-2.5 rounded-md text-sm font-semibold text-white transition-colors"
                        style="background:var(--brand-button, #0ea5e9);"
                        onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                    {{ $isEdit ? 'Save Changes' : 'Create User' }}
                </button>
            </div>
        </div>
    </form>

</div>
@endsection
