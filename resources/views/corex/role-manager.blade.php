@extends('layouts.corex')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6" x-data="{ activeTab: 'permissions' }">

    {{-- Page header --}}
    <div class="rounded-2xl px-6 py-4" style="background:#0b2a4a;">
        <h2 class="text-xl font-bold text-white leading-tight">Role &amp; Permissions Manager</h2>
        <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">Manage role-based access for all features. Super Admin always has full access.</div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
            {{ session('success') }}
        </div>
    @endif

    {{-- Tabs --}}
    <div class="flex gap-1 rounded-xl p-1 w-fit" style="background:#f1f5f9;">
        <button type="button" @click="activeTab = 'permissions'"
                :style="activeTab === 'permissions' ? 'background:#0b2a4a;color:#fff;' : 'background:transparent;color:#64748b;'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
            Permissions Matrix
        </button>
        <button type="button" @click="activeTab = 'users'"
                :style="activeTab === 'users' ? 'background:#0b2a4a;color:#fff;' : 'background:transparent;color:#64748b;'"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
            User Roles
        </button>
    </div>

    {{-- ─────────────────────────────────────────────
         TAB 1: Permissions Matrix
    ───────────────────────────────────────────── --}}
    <div x-show="activeTab === 'permissions'" x-cloak>
        <form method="POST" action="{{ route('corex.role-manager.save') }}">
            @csrf
            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between" style="background:#f8fafc;">
                    <h3 class="font-semibold text-sm" style="color:#0b2a4a;">Section Permissions</h3>
                    <button type="submit"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-white transition-colors"
                            style="background:#0b2a4a;"
                            onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                        Save Changes
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b" style="background:#f8fafc;">
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider sticky left-0 z-10 min-w-[240px]"
                                    style="background:#f8fafc;color:#0b2a4a;">
                                    Permission
                                </th>
                                @foreach($roles as $role)
                                    @php
                                        $isSA = $role === 'super_admin';
                                        $roleLabel = match($role) {
                                            'super_admin'    => 'Super Admin',
                                            'admin'          => 'Admin',
                                            'branch_manager' => 'Branch Manager',
                                            'agent'          => 'Agent',
                                            'viewer'         => 'Viewer',
                                            default          => ucfirst($role),
                                        };
                                        $badgeBg = match($role) {
                                            'super_admin'    => '#0b2a4a',
                                            'admin'          => '#00b4d8',
                                            'branch_manager' => '#0891b2',
                                            'agent'          => '#64748b',
                                            'viewer'         => '#94a3b8',
                                        };
                                    @endphp
                                    <th class="text-center py-3 px-3 min-w-[110px]">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold text-white"
                                              style="background:{{ $badgeBg }};">
                                            {{ $roleLabel }}
                                        </span>
                                        @if($isSA)
                                            <div class="text-[10px] mt-0.5" style="color:#94a3b8;">always granted</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @php $lastSection = ''; @endphp
                            @foreach($permissions as $perm)
                                @if($perm->section !== $lastSection)
                                    @php $lastSection = $perm->section; @endphp
                                    <tr>
                                        <td colspan="{{ count($roles) + 1 }}" class="py-2 px-4" style="background:#0b2a4a;">
                                            <span class="text-xs font-bold uppercase tracking-wider text-white">
                                                {{ str_replace('-', ' ', $perm->section) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endif
                                <tr class="border-b border-slate-100 hover:bg-slate-50 transition-colors">
                                    <td class="py-2.5 px-4 font-medium text-slate-800 sticky left-0 bg-white z-10">
                                        {{ $perm->label }}
                                    </td>
                                    @foreach($roles as $role)
                                        @php $isSA = $role === 'super_admin'; @endphp
                                        <td class="py-2.5 px-3 text-center">
                                            <label class="inline-flex items-center justify-center {{ $isSA ? '' : 'cursor-pointer' }}">
                                                @if($isSA)
                                                    {{-- Super Admin: always checked, not editable --}}
                                                    <input type="hidden"
                                                           name="permissions[{{ $perm->key }}][{{ $role }}]"
                                                           value="1">
                                                    <input type="checkbox" checked disabled
                                                           class="w-4 h-4 rounded border-slate-300 opacity-50 cursor-not-allowed"
                                                           style="accent-color:#0b2a4a;">
                                                @else
                                                    <input type="hidden"
                                                           name="permissions[{{ $perm->key }}][{{ $role }}]"
                                                           value="0">
                                                    <input type="checkbox"
                                                           name="permissions[{{ $perm->key }}][{{ $role }}]"
                                                           value="1"
                                                           {{ isset($granted[$perm->key][$role]) ? 'checked' : '' }}
                                                           class="w-4 h-4 rounded border-slate-300 cursor-pointer"
                                                           style="accent-color:#00b4d8;">
                                                @endif
                                            </label>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach

                            @if($permissions->isEmpty())
                                <tr>
                                    <td colspan="{{ count($roles) + 1 }}" class="py-12 text-center text-sm text-slate-400">
                                        No permissions defined. Run: <code class="font-mono bg-slate-100 px-1 rounded">php artisan db:seed --class=NexusPermissionSeeder</code>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    {{-- ─────────────────────────────────────────────
         TAB 2: User Roles
    ───────────────────────────────────────────── --}}
    <div x-show="activeTab === 'users'" x-cloak>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between" style="background:#f8fafc;">
                <h3 class="font-semibold text-sm" style="color:#0b2a4a;">User Roles</h3>
                <span class="text-xs text-slate-400">{{ $users->count() }} users</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b" style="background:#f8fafc;">
                            <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Name</th>
                            <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Email</th>
                            <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Agency</th>
                            <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Branch</th>
                            <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Current Role</th>
                            @if(auth()->user()->isEffectiveAdmin())
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Change Role</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($users as $u)
                            @php
                                $badgeBg = match($u->role) {
                                    'super_admin'    => '#0b2a4a',
                                    'admin'          => '#00b4d8',
                                    'branch_manager' => '#0891b2',
                                    'agent'          => '#64748b',
                                    'viewer'         => '#94a3b8',
                                    default          => '#64748b',
                                };
                                $roleLabel = match($u->role) {
                                    'super_admin'    => 'Super Admin',
                                    'admin'          => 'Admin',
                                    'branch_manager' => 'Branch Manager',
                                    'agent'          => 'Agent',
                                    'viewer'         => 'Viewer',
                                    default          => ucfirst($u->role ?? 'agent'),
                                };
                                $branchName  = $branches->firstWhere('id', $u->branch_id)?->name ?? '—';
                                $agencyName  = $agencies->firstWhere('id', $u->agency_id)?->name ?? '—';
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-2.5 px-4 font-medium text-slate-800">{{ $u->name }}</td>
                                <td class="py-2.5 px-4 text-slate-500">{{ $u->email }}</td>
                                <td class="py-2.5 px-4 text-slate-500 text-xs">{{ $agencyName }}</td>
                                <td class="py-2.5 px-4 text-slate-500 text-xs">{{ $branchName }}</td>
                                <td class="py-2.5 px-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold text-white"
                                          style="background:{{ $badgeBg }};">
                                        {{ $roleLabel }}
                                    </span>
                                </td>
                                @if(auth()->user()->isEffectiveAdmin())
                                    <td class="py-2.5 px-4">
                                        <form method="POST" action="{{ route('corex.role-manager.user-role') }}"
                                              class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $u->id }}">
                                            <select name="role"
                                                    class="text-xs rounded-lg border border-slate-300 bg-white text-slate-800 py-1.5 px-2 focus:outline-none focus:border-[#00b4d8]"
                                                    style="max-width:160px;">
                                                <option value="super_admin" {{ $u->role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                                                <option value="admin"          {{ $u->role === 'admin'          ? 'selected' : '' }}>Admin</option>
                                                <option value="branch_manager" {{ $u->role === 'branch_manager' ? 'selected' : '' }}>Branch Manager</option>
                                                <option value="agent"          {{ $u->role === 'agent'          ? 'selected' : '' }}>Agent</option>
                                                <option value="viewer"         {{ $u->role === 'viewer'         ? 'selected' : '' }}>Viewer</option>
                                            </select>
                                            <button type="submit"
                                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-colors"
                                                    style="background:#0b2a4a;"
                                                    onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                                                Save
                                            </button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
@endsection
