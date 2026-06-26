{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $uCol      = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
    $totalUsers = is_countable($uCol) ? count($uCol) : 0;
    $roles      = $uCol->pluck('role')->filter()->unique()->sort()->values();
    $branchList = $branches ?? collect();
@endphp

<div class="w-full space-y-5"
     x-data="{ search: '', roleFilter: '', branchFilter: '', activeFilter: '' }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">User Management</h1>
                <p class="text-sm text-white/60">{{ number_format($totalUsers) }} users — manage roles, access &amp; agent profiles.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.users', ['refresh_p24' => 1]) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);"
                   title="Re-fetch the Property24 agent list">
                    Refresh P24
                </a>
                @if(\Illuminate\Support\Facades\Route::has('admin.pp.agents'))
                <a href="{{ route('admin.pp.agents') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                   style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.18);"
                   title="View all agent profiles on Private Property and clean up duplicates">
                    PP Agents
                </a>
                @endif
                <a href="{{ route('admin.users.create') }}" class="corex-btn-primary text-sm inline-flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add User
                </a>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- PPRA verification due banner --}}
    @if(($ppraDueCount ?? 0) > 0 && auth()->user()->hasPermission('edit_user_ppra_status'))
    <div class="flex items-center justify-between gap-3 rounded-md px-4 py-3"
         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" style="stroke: var(--ds-amber);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <span class="text-xs font-semibold" style="color: var(--ds-amber);">{{ number_format($ppraDueCount) }} agent(s) need PPRA re-verification (over 12 months or never verified)</span>
        </div>
        <a href="https://theppra.org.za/agent_agency_search" target="_blank"
           class="text-xs font-semibold px-2 py-1 rounded-md flex-shrink-0"
           style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber); text-decoration:none; border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">Check PPRA Register</a>
    </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2">
        <input type="text" x-model="search" placeholder="Search name or email…"
               class="flex-1 min-w-[200px] rounded-md px-3 py-2 text-sm outline-none"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        <select x-model="roleFilter"
                class="rounded-md px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All roles</option>
            @foreach($roles as $r)
            <option value="{{ $r }}">{{ str_replace('_',' ',$r) }}</option>
            @endforeach
        </select>
        <select x-model="branchFilter"
                class="rounded-md px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All branches</option>
            @foreach($branchList as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <select x-model="activeFilter"
                class="rounded-md px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
    </div>

    {{-- User list --}}
    @if($totalUsers === 0)
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border:1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No users yet</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first team member to start managing roles and access.</p>
        <a href="{{ route('admin.users.create') }}" class="corex-btn-primary text-sm">Add User</a>
    </div>
    @endif
    <div class="space-y-2">
        @foreach($uCol as $u)
        <div x-data="{ open: false }"
             x-show="
                 (search === '' || '{{ strtolower(addslashes($u->name)) }}'.includes(search.toLowerCase()) || '{{ strtolower(addslashes($u->email)) }}'.includes(search.toLowerCase()))
                 && (roleFilter === '' || '{{ $u->role }}' === roleFilter)
                 && (branchFilter === '' || '{{ $u->branch_id }}' === branchFilter)
                 && (activeFilter === '' || '{{ $u->is_active ? '1' : '0' }}' === activeFilter)
             "
             x-transition
             class="rounded-md overflow-hidden"
             style="background:var(--surface); border:1px solid var(--border);">

            {{-- ── Collapsed row ── --}}
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer select-none"
                 @click="open = !open">

                {{-- Avatar --}}
                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold"
                     style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 18%, transparent); color:var(--brand-icon, #0ea5e9);">
                    {{ strtoupper(substr($u->name,0,1)) }}{{ strtoupper(substr(strstr($u->name,' '),1,1)) }}
                </div>

                {{-- Name + email --}}
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $u->name }}</span>
                        {{-- Role --}}
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
                              style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                            {{ str_replace('_',' ',$u->role) }}
                        </span>
                        {{-- Status badge --}}
                        @if($u->is_active && !$u->email_verified_at)
                        <span class="ds-badge ds-badge-warning">Pending</span>
                        @elseif($u->is_active)
                        <span class="ds-badge ds-badge-success">Active</span>
                        @else
                        <span class="ds-badge ds-badge-default">Inactive</span>
                        @endif
                        @if(!empty($p24AgentMap[$u->id]))
                        <span class="px-2 py-0.5 rounded-full text-xs font-mono font-medium whitespace-nowrap"
                              style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);"
                              title="Property24 Agent ID">
                            P24: {{ $p24AgentMap[$u->id] }}
                        </span>
                        @else
                        <form method="POST" action="{{ route('admin.users.sync-p24', $u) }}" class="inline" onclick="event.stopPropagation();">
                            @csrf
                            <button type="submit"
                                    class="px-2 py-0.5 rounded-full text-xs font-medium transition-colors whitespace-nowrap"
                                    style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);"
                                    title="Push this agent to Property24 to get an agent ID">
                                Sync to P24
                            </button>
                        </form>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3 mt-0.5">
                        <span class="text-xs" style="color:var(--text-muted);">{{ $u->email }}</span>
                        @if($branchList->firstWhere('id',$u->branch_id))
                        <span class="text-xs" style="color:var(--text-muted);">
                            · {{ $branchList->firstWhere('id',$u->branch_id)->name }}
                        </span>
                        @endif
                        @if($u->designation)
                        <span class="text-xs" style="color:var(--text-muted);">· {{ $u->designation }}</span>
                        @endif
                    </div>
                </div>

                {{-- Edit link --}}
                <a href="{{ route('admin.users.edit', $u) }}"
                   class="px-2.5 py-1 rounded-md text-xs font-semibold transition-colors flex-shrink-0"
                   style="color:var(--brand-icon, #0ea5e9);"
                   onclick="event.stopPropagation();"
                   onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                    Edit
                </a>

                {{-- Chevron --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                     style="stroke:var(--text-muted);"
                     class="w-4 h-4 flex-shrink-0 transition-transform duration-200"
                     :class="open && 'rotate-90'">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </div>

            {{-- ── Expanded edit panel ── --}}
            <div x-show="open" x-cloak x-transition
                 style="border-top:1px solid var(--border); background:var(--surface-2);">
                <form id="roleForm-{{ $u->id }}" method="POST" action="{{ route('admin.users.role.update', $u) }}"
                      enctype="multipart/form-data"
                      class="p-4 space-y-5">
                    @csrf

                    {{-- Section: Role & Access --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Role &amp; Access
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Role</label>
                                <select name="role" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(\App\Models\Role::allRoles(auth()->user()?->effectiveAgencyId()) as $role)
                                        @if(!$role->is_owner)
                                        <option value="{{ $role->name }}" {{ $u->role===$role->name?'selected':'' }}>{{ $role->label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Branch</label>
                                <select name="branch_id" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">(no branch)</option>
                                    @foreach($branchList as $b)
                                    <option value="{{ $b->id }}" {{ (string)$u->branch_id===(string)$b->id?'selected':'' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Designation</label>
                                <select name="designation" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @php $des = old('designation', $u->designation ?? ''); @endphp
                                    <option value="" {{ $des===''?'selected':'' }}>(none)</option>
                                    @foreach(($designations ?? []) as $d)
                                    <option value="{{ $d->name }}" {{ $des===$d->name?'selected':'' }}>{{ $d->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-4 mt-3">
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                <input type="hidden" name="can_capture_rentals" value="0">
                                <input type="checkbox" name="can_capture_rentals" value="1" class="rounded"
                                       {{ old('can_capture_rentals',(int)($u->can_capture_rentals??0)) ? 'checked' : '' }}>
                                Can Capture Rentals
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                <input type="hidden" name="counts_for_branch_split" value="0">
                                <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded"
                                       {{ old('counts_for_branch_split',(int)($u->counts_for_branch_split??1)) ? 'checked' : '' }}>
                                Counts for Branch Split
                            </label>
                        </div>
                    </div>

                    {{-- Section: Finance --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Finance
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="{{ old('agent_cut_percent', $u->agent_cut_percent ?? 50) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Method</label>
                                @php $pm = old('paye_method', $u->paye_method ?? 'percentage'); @endphp
                                <select name="paye_method" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="percentage" {{ $pm==='percentage'?'selected':'' }}>Percentage</option>
                                    <option value="fixed"      {{ $pm==='fixed'?'selected':'' }}>Fixed</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="{{ old('paye_value', $u->paye_value ?? 0) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="flex items-center gap-2 pb-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                    <input type="hidden" name="sliding_enabled" value="0">
                                    <input type="checkbox" name="sliding_enabled" value="1" class="rounded"
                                           {{ old('sliding_enabled',(int)($u->sliding_enabled??0)) ? 'checked' : '' }}>
                                    Sliding Scale
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Section: Contact Details --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Contact Details
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Phone</label>
                                <input type="tel" name="phone" value="{{ old('phone', $u->phone) }}" placeholder="Landline"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Cell</label>
                                <input type="tel" name="cell" value="{{ old('cell', $u->cell) }}" placeholder="Mobile"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Fax</label>
                                <input type="tel" name="fax" value="{{ old('fax', $u->fax) }}" placeholder="Fax number"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">FFC Number</label>
                                <input type="text" name="ffc_number" value="{{ old('ffc_number', $u->ffc_number) }}" placeholder="Certificate number"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Website</label>
                                <input type="url" name="website" value="{{ old('website', $u->website) }}" placeholder="https://…"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Section: Files --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Files
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- Agent Photo --}}
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">
                                    Agent Photo <span style="color:var(--text-muted);">(jpg/png/webp, max 2MB)</span>
                                </label>
                                @if($u->profilePhotoUrl())
                                <div class="flex items-center gap-3 mb-2">
                                    <img src="{{ $u->profilePhotoUrl() }}" alt="Photo"
                                         class="w-10 h-10 rounded-md object-cover flex-shrink-0"
                                         style="border:1px solid var(--border);">
                                    <form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove agent photo?')">
                                        @csrf
                                        <input type="hidden" name="field" value="agent_photo">
                                        <button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson, #c41e3a);">Remove</button>
                                    </form>
                                </div>
                                @endif
                                <input type="file" name="agent_photo" accept="image/jpeg,image/png,image/webp"
                                       class="block w-full text-sm rounded-md px-3 py-2"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                            </div>
                            {{-- FFC Certificate --}}
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">
                                    FFC Certificate <span style="color:var(--text-muted);">(pdf/jpg/png, max 5MB)</span>
                                </label>
                                @if($u->ffc_certificate_path)
                                <div class="flex items-center gap-3 mb-2">
                                    <a href="{{ asset('storage/'.$u->ffc_certificate_path) }}" target="_blank"
                                       class="text-xs truncate flex-1" style="color:var(--brand-icon, #0ea5e9);">
                                        {{ basename($u->ffc_certificate_path) }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove FFC certificate?')">
                                        @csrf
                                        <input type="hidden" name="field" value="ffc_certificate">
                                        <button type="submit" class="text-xs font-semibold flex-shrink-0" style="color:var(--ds-crimson, #c41e3a);">Remove</button>
                                    </form>
                                </div>
                                @endif
                                <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                                       class="block w-full text-sm rounded-md px-3 py-2"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                            </div>
                        </div>
                    </div>

                </form>

                    {{-- Actions (outside main form to avoid nesting) --}}
                    <div class="flex items-center justify-between gap-3 pt-1 px-4 pb-4"
                         style="border-top:1px solid var(--border); padding-top:16px;">
                        <div class="flex items-center gap-3">
                            <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                @csrf
                                <button type="submit"
                                        class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                                        style="{{ $u->is_active
                                            ? 'background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);'
                                            : 'background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);' }}">
                                    {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                            <button type="button"
                                    data-agent-delete
                                    data-user-id="{{ $u->id }}"
                                    data-user-name="{{ $u->name }}"
                                    class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                                    style="background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                                Delete
                            </button>
                        </div>
                        <button type="submit" form="roleForm-{{ $u->id }}" class="corex-btn-primary text-sm">Save Changes</button>
                    </div>
            </div>

        </div>
        @endforeach
    </div>

</div>

@include('admin.users._delete-modal')
@endsection
