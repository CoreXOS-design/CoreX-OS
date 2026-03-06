@extends('layouts.corex')

@section('corex-content')
@php
    $uCol      = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
    $totalUsers = is_countable($uCol) ? count($uCol) : 0;
    $roles      = $uCol->pluck('role')->filter()->unique()->sort()->values();
    $branchList = $branches ?? collect();
@endphp

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ search: '', roleFilter: '', branchFilter: '', activeFilter: '' }">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">User Management</h2>
                <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">{{ $totalUsers }} users</div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl px-4 py-3 text-sm font-medium" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl px-4 py-3 text-sm" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-2">
        <input type="text" x-model="search" placeholder="Search name or email…"
               class="flex-1 min-w-[200px] rounded-lg px-3 py-2 text-sm outline-none"
               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
        <select x-model="roleFilter"
                class="rounded-lg px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All roles</option>
            @foreach($roles as $r)
            <option value="{{ $r }}">{{ str_replace('_',' ',$r) }}</option>
            @endforeach
        </select>
        <select x-model="branchFilter"
                class="rounded-lg px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All branches</option>
            @foreach($branchList as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <select x-model="activeFilter"
                class="rounded-lg px-3 py-2 text-sm outline-none"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="">All</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </select>
    </div>

    {{-- User list --}}
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
             class="rounded-xl overflow-hidden"
             style="background:var(--surface); border:1px solid var(--border);">

            {{-- ── Collapsed row ── --}}
            <div class="flex items-center gap-3 px-4 py-3 cursor-pointer select-none"
                 @click="open = !open">

                {{-- Avatar --}}
                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold"
                     style="background:rgba(0,180,216,0.2); color:#00b4d8;">
                    {{ strtoupper(substr($u->name,0,1)) }}{{ strtoupper(substr(strstr($u->name,' '),1,1)) }}
                </div>

                {{-- Name + email --}}
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $u->name }}</span>
                        {{-- Role --}}
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                              style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
                            {{ str_replace('_',' ',$u->role) }}
                        </span>
                        {{-- Active badge --}}
                        @if($u->is_active)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                              style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">Active</span>
                        @else
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                              style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;">Inactive</span>
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
                             style="color:var(--text-muted); border-left:2px solid #00b4d8; padding-left:8px;">
                            Role &amp; Access
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Role</label>
                                <select name="role" class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(\App\Models\Role::orderBy('sort_order')->get() as $role)
                                        @if(!$role->is_owner)
                                        <option value="{{ $role->name }}" {{ $u->role===$role->name?'selected':'' }}>{{ $role->label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Branch</label>
                                <select name="branch_id" class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">(no branch)</option>
                                    @foreach($branchList as $b)
                                    <option value="{{ $b->id }}" {{ (string)$u->branch_id===(string)$b->id?'selected':'' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Designation</label>
                                <select name="designation" class="w-full rounded-lg px-3 py-2 text-sm outline-none"
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
                             style="color:var(--text-muted); border-left:2px solid #00b4d8; padding-left:8px;">
                            Finance
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="{{ old('agent_cut_percent', $u->agent_cut_percent ?? 50) }}"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Method</label>
                                @php $pm = old('paye_method', $u->paye_method ?? 'percentage'); @endphp
                                <select name="paye_method" class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="percentage" {{ $pm==='percentage'?'selected':'' }}>Percentage</option>
                                    <option value="fixed"      {{ $pm==='fixed'?'selected':'' }}>Fixed</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="{{ old('paye_value', $u->paye_value ?? 0) }}"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
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
                             style="color:var(--text-muted); border-left:2px solid #00b4d8; padding-left:8px;">
                            Contact Details
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Phone</label>
                                <input type="tel" name="phone" value="{{ old('phone', $u->phone) }}" placeholder="Landline"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Cell</label>
                                <input type="tel" name="cell" value="{{ old('cell', $u->cell) }}" placeholder="Mobile"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Fax</label>
                                <input type="tel" name="fax" value="{{ old('fax', $u->fax) }}" placeholder="Fax number"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">FFC Number</label>
                                <input type="text" name="ffc_number" value="{{ old('ffc_number', $u->ffc_number) }}" placeholder="Certificate number"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Website</label>
                                <input type="url" name="website" value="{{ old('website', $u->website) }}" placeholder="https://…"
                                       class="w-full rounded-lg px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Section: Files --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid #00b4d8; padding-left:8px;">
                            Files
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- Agent Photo --}}
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">
                                    Agent Photo <span style="color:var(--text-muted);">(jpg/png/webp, max 2MB)</span>
                                </label>
                                @if($u->agent_photo_path)
                                <div class="flex items-center gap-3 mb-2">
                                    <img src="{{ asset('storage/'.$u->agent_photo_path) }}" alt="Photo"
                                         class="w-10 h-10 rounded-lg object-cover flex-shrink-0"
                                         style="border:1px solid var(--border);">
                                    <button type="button" class="text-xs text-red-600 hover:text-red-700"
                                            onclick="if(confirm('Remove agent photo?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $u) }}';f.innerHTML='@csrf<input name=field value=agent_photo>';document.body.appendChild(f);f.submit();}">Remove</button>
                                </div>
                                @endif
                                <input type="file" name="agent_photo" accept="image/jpeg,image/png,image/webp"
                                       class="block w-full text-sm rounded-lg px-3 py-2"
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
                                       class="text-xs text-blue-400 hover:text-blue-300 truncate flex-1">
                                        {{ basename($u->ffc_certificate_path) }}
                                    </a>
                                    <button type="button" class="text-xs text-red-600 hover:text-red-700 flex-shrink-0"
                                            onclick="if(confirm('Remove FFC certificate?')){let f=document.createElement('form');f.method='POST';f.action='{{ route('admin.users.remove-file', $u) }}';f.innerHTML='@csrf<input name=field value=ffc_certificate>';document.body.appendChild(f);f.submit();}">Remove</button>
                                </div>
                                @endif
                                <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                                       class="block w-full text-sm rounded-lg px-3 py-2"
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
                                        class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                                        style="{{ $u->is_active ? 'background:#fee2e2; color:#991b1b; border:1px solid #fecaca;' : 'background:#dcfce7; color:#166534; border:1px solid #bbf7d0;' }}">
                                    {{ $u->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.users.delete', $u) }}"
                                  onsubmit="return confirm('Delete {{ addslashes($u->name) }}? This cannot be undone.');">
                                @csrf
                                <button type="submit"
                                        class="px-3 py-1.5 rounded-lg text-sm font-medium"
                                        style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;">
                                    Delete
                                </button>
                            </form>
                        </div>
                        <button type="submit" form="roleForm-{{ $u->id }}" class="corex-btn-primary text-sm">Save Changes</button>
                    </div>
            </div>

        </div>
        @endforeach
    </div>

</div>
@endsection
