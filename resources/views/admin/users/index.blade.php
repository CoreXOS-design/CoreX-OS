@extends('layouts.corex')

@section('content')
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        @php
            $uCol = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
            $totalUsers = is_countable($uCol) ? count($uCol) : 0;
            $activeUsers = method_exists($uCol, 'where') ? $uCol->where('is_active', true)->count() : 0;
        @endphp

        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">User Management</h2>
                    <div class="text-sm text-white/60">Manage roles, branch assignment, designation (prints), and default commission/PAYE settings.</div>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-wide text-white/60">Users</div>
                    <div class="text-2xl font-bold text-white">{{ number_format((int)$totalUsers) }}</div>
                    <div class="text-xs text-white/60">Active: {{ number_format((int)$activeUsers) }}</div>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3 dark:border-rose-900/40 dark:bg-rose-900/20 dark:text-rose-100">
                <ul class="list-disc pl-5 text-sm space-y-1">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="space-y-3">
            @foreach(($users instanceof \Illuminate\Pagination\AbstractPaginator ? $users : $users) as $user)
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">
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
                                    <span>{{ optional(($branches ?? collect())->firstWhere('id', $user->branch_id))->name ?? '(none)' }}</span>
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
                                <button class="px-3 py-1.5 rounded-lg text-sm border border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/40 dark:text-red-300 dark:hover:bg-red-900/20">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="p-4">
                        <form method="POST" action="{{ route('admin.users.role.update', $user) }}" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Role</label>
                                <select name="role" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="agent" {{ $user->role==='agent'?'selected':'' }}>agent</option>
                                    <option value="branch_manager" {{ $user->role==='branch_manager'?'selected':'' }}>branch_manager</option>
                                    <option value="admin" {{ $user->role==='admin'?'selected':'' }}>admin</option>
                                </select>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Branch</label>
                                <select name="branch_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="">(no branch)</option>
                                    @foreach(($branches ?? []) as $b)
                                        <option value="{{ $b->id }}" {{ (string)$user->branch_id === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="md:col-span-5">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Designation (prints on certificates)</label>
                                <select name="designation" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    @php $des = old('designation', $user->designation ?? ''); @endphp

                                    <option value="" {{ $des==='' ? 'selected' : '' }}>(blank)</option>
                                    @foreach(($designations ?? []) as $d)
                                        <option value="{{ $d->name }}" {{ $des===$d->name ? 'selected' : '' }}>{{ $d->name }}</option>
                                    @endforeach

                                </select>
                                <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Used on Commission Summary + Current Market Analysis prints.</div>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="{{ old('agent_cut_percent', $user->agent_cut_percent ?? 50) }}"
                                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">PAYE Method</label>
                                @php $pm = old('paye_method', $user->paye_method ?? 'percentage'); @endphp
                                <select name="paye_method" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm">
                                    <option value="percentage" {{ $pm==='percentage'?'selected':'' }}>percentage</option>
                                    <option value="fixed" {{ $pm==='fixed'?'selected':'' }}>fixed</option>
                                </select>
                            </div>

                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="{{ old('paye_value', $user->paye_value ?? 0) }}"
                                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-2 py-2 text-sm"/>
                            </div>

                            <div class="md:col-span-2 flex items-center gap-2 pt-6">
                                <input type="hidden" name="sliding_enabled" value="0" />
                                <input type="checkbox" name="sliding_enabled" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                       {{ old('sliding_enabled', (int)($user->sliding_enabled ?? 0)) ? 'checked' : '' }} />
                                <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Sliding</span>
                            </div>


                            <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                <input type="hidden" name="can_capture_rentals" value="0" />
                                <input type="checkbox" name="can_capture_rentals" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                       {{ old('can_capture_rentals', (int)($user->can_capture_rentals ?? 0)) ? 'checked' : '' }} />
                                <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Can Capture Rentals</span>
                            </div>


                              <div class="md:col-span-3 flex items-center gap-2 pt-6">
                                  <input type="hidden" name="counts_for_branch_split" value="0" />
                                  <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded border-slate-300 dark:border-slate-700"
                                         {{ old('counts_for_branch_split', (int)($user->counts_for_branch_split ?? 1)) ? 'checked' : '' }} />
                                  <span class="text-sm text-slate-700 dark:text-slate-200 font-medium">Counts for Branch Split</span>
                              </div>

                            <div class="md:col-span-12 flex items-center justify-between gap-3 pt-2">
                                <div class="text-xs text-slate-500 dark:text-slate-400">One save updates role/branch/designation + defaults.</div>
                                <button type="submit" class="corex-btn-primary text-sm">
                                    Save User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($users instanceof \Illuminate\Pagination\AbstractPaginator)
            <div class="pt-2">
                {{ $users->links() }}
            </div>
        @endif

    </div>
@endsection
