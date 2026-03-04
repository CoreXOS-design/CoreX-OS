@extends('layouts.corex')

@section('content')
    @php
        $uCol = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
        $totalUsers = is_countable($uCol) ? count($uCol) : 0;
        $activeUsers = method_exists($uCol, 'where') ? $uCol->where('is_active', true)->count() : 0;
        $roles = $uCol->pluck('role')->filter()->unique()->sort()->values();
        $branchList = $branches ?? collect();
    @endphp

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
         x-data="{
             search: '',
             roleFilter: '',
             branchFilter: '',
             activeFilter: '',
         }">

        <x-list-header
            title="User Management"
            :count="$totalUsers"
            search-placeholder="Search name, email..."
            search-model="search"
        >
            <x-slot:filters>
                <select x-model="roleFilter" class="list-header-filter">
                    <option value="">All roles</option>
                    @foreach($roles as $r)
                    <option value="{{ $r }}">{{ str_replace('_', ' ', $r) }}</option>
                    @endforeach
                </select>

                <select x-model="branchFilter" class="list-header-filter">
                    <option value="">All branches</option>
                    @foreach($branchList as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>

                <select x-model="activeFilter" class="list-header-filter">
                    <option value="">All</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </x-slot:filters>
        </x-list-header>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">
                <ul class="list-disc pl-5 text-sm space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div id="user-visible-count" class="text-sm text-gray-400"></div>

        <div class="space-y-3">
            @foreach($uCol as $user)
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden user-card"
                     data-name="{{ strtolower($user->name) }}"
                     data-email="{{ strtolower($user->email) }}"
                     data-role="{{ $user->role }}"
                     data-branch="{{ $user->branch_id }}"
                     data-active="{{ $user->is_active ? '1' : '0' }}"
                     x-show="
                         (search === '' || '{{ strtolower(addslashes($user->name)) }}'.includes(search.toLowerCase()) || '{{ strtolower(addslashes($user->email)) }}'.includes(search.toLowerCase()))
                         && (roleFilter === '' || '{{ $user->role }}' === roleFilter)
                         && (branchFilter === '' || '{{ $user->branch_id }}' === branchFilter)
                         && (activeFilter === '' || '{{ $user->is_active ? '1' : '0' }}' === activeFilter)
                     "
                     x-transition>
                    <div class="px-4 py-3 bg-[#0b2a4a] text-white flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-white truncate">{{ $user->name }}</div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-white/15 bg-white/10 text-white">
                                    {{ str_replace('_', ' ', (string)$user->role) }}
                                </span>

                                @if($user->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-emerald-300/40 bg-emerald-400/15 text-emerald-100">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs border border-red-300/40 bg-red-400/15 text-red-100">
                                        Inactive
                                    </span>
                                @endif
                            </div>

                            <div class="mt-1 text-sm text-white/60 truncate">{{ $user->email }}</div>

                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-white/15 bg-white/10 text-white/80">
                                    <span class="font-semibold text-white">Branch:</span>
                                    <span>{{ optional($branchList->firstWhere('id', $user->branch_id))->name ?? '(none)' }}</span>
                                </span>

                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-white/15 bg-white/10 text-white/80">
                                    <span class="font-semibold text-white">Designation:</span>
                                    <span>{{ $user->designation ?: '(blank)' }}</span>
                                </span>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-2">
                            <form method="POST" action="{{ route('admin.users.toggle', $user) }}">
                                @csrf
                                <button class="corex-btn-outline text-sm">
                                    Toggle Active
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.users.delete', $user) }}" onsubmit="return confirm('Delete this user? This cannot be undone.');">
                                @csrf
                                <button class="px-3 py-1.5 rounded-lg text-sm border border-red-200 text-red-700 hover:bg-red-50">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="p-4">
                        <form method="POST" action="{{ route('admin.users.role.update', $user) }}" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">Role</label>
                                <select name="role" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm">
                                    <option value="agent" {{ $user->role==='agent'?'selected':'' }}>agent</option>
                                    <option value="branch_manager" {{ $user->role==='branch_manager'?'selected':'' }}>branch_manager</option>
                                    <option value="admin" {{ $user->role==='admin'?'selected':'' }}>admin</option>
                                </select>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 mb-1">Branch</label>
                                <select name="branch_id" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm">
                                    <option value="">(no branch)</option>
                                    @foreach($branchList as $b)
                                        <option value="{{ $b->id }}" {{ (string)$user->branch_id === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="md:col-span-5">
                                <label class="block text-xs text-slate-600 mb-1">Designation (prints on certificates)</label>
                                <select name="designation" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm">
                                    @php $des = old('designation', $user->designation ?? ''); @endphp
                                    <option value="" {{ $des==='' ? 'selected' : '' }}>(blank)</option>
                                    @foreach(($designations ?? []) as $d)
                                        <option value="{{ $d->name }}" {{ $des===$d->name ? 'selected' : '' }}>{{ $d->name }}</option>
                                    @endforeach
                                </select>
                                <div class="mt-1 text-[11px] text-slate-500">Used on Commission Summary + Current Market Analysis prints.</div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="{{ old('agent_cut_percent', $user->agent_cut_percent ?? 50) }}"
                                       class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 mb-1">PAYE Method</label>
                                @php $pm = old('paye_method', $user->paye_method ?? 'percentage'); @endphp
                                <select name="paye_method" class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm">
                                    <option value="percentage" {{ $pm==='percentage'?'selected':'' }}>percentage</option>
                                    <option value="fixed" {{ $pm==='fixed'?'selected':'' }}>fixed</option>
                                </select>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="{{ old('paye_value', $user->paye_value ?? 0) }}"
                                       class="w-full rounded-lg border border-slate-300 bg-white text-slate-900 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-2 flex items-center gap-2 pt-6">
                                <input type="hidden" name="sliding_enabled" value="0" />
                                <input type="checkbox" name="sliding_enabled" value="1" class="rounded border-slate-300"
                                       {{ old('sliding_enabled', (int)($user->sliding_enabled ?? 0)) ? 'checked' : '' }} />
                                <span class="text-sm text-slate-700 font-medium">Sliding</span>
                            </div>

                            <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                <input type="hidden" name="can_capture_rentals" value="0" />
                                <input type="checkbox" name="can_capture_rentals" value="1" class="rounded border-slate-300"
                                       {{ old('can_capture_rentals', (int)($user->can_capture_rentals ?? 0)) ? 'checked' : '' }} />
                                <span class="text-sm text-slate-700 font-medium">Can Capture Rentals</span>
                            </div>

                            <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                <input type="hidden" name="counts_for_branch_split" value="0" />
                                <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded border-slate-300"
                                       {{ old('counts_for_branch_split', (int)($user->counts_for_branch_split ?? 1)) ? 'checked' : '' }} />
                                <span class="text-sm text-slate-700 font-medium">Counts for Branch Split</span>
                            </div>

                            {{-- Agent Photo --}}
                            <div class="md:col-span-6 pt-2">
                                <label class="block text-xs text-slate-600 mb-1">Agent Photo <span class="text-slate-400">(jpg, png, webp — max 2 MB)</span></label>
                                @if($user->agent_photo_path)
                                    <div class="flex items-center gap-3 mb-2">
                                        <img src="{{ asset('storage/' . $user->agent_photo_path) }}" alt="Agent photo"
                                             style="width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;">
                                        <form method="POST" action="{{ route('admin.users.remove-file', $user) }}" class="inline" onsubmit="return confirm('Remove agent photo?');">
                                            @csrf
                                            <input type="hidden" name="field" value="agent_photo">
                                            <button type="submit" class="text-xs text-red-600 hover:text-red-800 underline">Remove</button>
                                        </form>
                                    </div>
                                @endif
                                <input type="file" name="agent_photo" accept="image/jpeg,image/png,image/webp"
                                       class="w-full text-sm text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-slate-300 file:text-sm file:font-medium file:bg-white file:text-slate-700">
                            </div>

                            {{-- FFC Certificate --}}
                            <div class="md:col-span-6 pt-2">
                                <label class="block text-xs text-slate-600 mb-1">FFC Certificate <span class="text-slate-400">(pdf, jpg, png — max 5 MB)</span></label>
                                @if($user->ffc_certificate_path)
                                    <div class="flex items-center gap-3 mb-2">
                                        <a href="{{ asset('storage/' . $user->ffc_certificate_path) }}" target="_blank"
                                           class="text-sm text-blue-600 hover:underline truncate">{{ basename($user->ffc_certificate_path) }}</a>
                                        <form method="POST" action="{{ route('admin.users.remove-file', $user) }}" class="inline" onsubmit="return confirm('Remove FFC certificate?');">
                                            @csrf
                                            <input type="hidden" name="field" value="ffc_certificate">
                                            <button type="submit" class="text-xs text-red-600 hover:text-red-800 underline">Remove</button>
                                        </form>
                                    </div>
                                @endif
                                <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                                       class="w-full text-sm text-slate-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border file:border-slate-300 file:text-sm file:font-medium file:bg-white file:text-slate-700">
                            </div>

                            <div class="md:col-span-12 flex items-center justify-between gap-3 pt-2">
                                <div class="text-xs text-slate-500">One save updates role/branch/designation + defaults.</div>
                                <button type="submit" class="corex-btn-primary text-sm">
                                    Save User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
@endsection
