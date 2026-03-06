@extends('layouts.corex')

@section('corex-content')
<style>
/* ── Role Manager: dark-mode colour fixes ── */
html.dark #rm-root .text-slate-800 { color: #eef0f5 !important; }
html.dark #rm-root .text-slate-700 { color: #eef0f5 !important; }
html.dark #rm-root .text-slate-600 { color: #8890a4 !important; }
html.dark #rm-root .text-slate-500 { color: #8890a4 !important; }
html.dark #rm-root .text-slate-400 { color: #545b6e !important; }
html.dark #rm-root [style*="color:#0b2a4a"] { color: #eef0f5 !important; }
html.dark #rm-root .bg-white { background: #13161d !important; }
html.dark #rm-root [style*="background:#f8fafc"] { background: #1a1e28 !important; }
html.dark #rm-root [style*="background:#f1f5f9"] { background: #1a1e28 !important; }
html.dark #rm-root [style*="background:#fef2f2"] { background: rgba(220,38,38,0.12) !important; }
html.dark #rm-root .border-slate-200 { border-color: rgba(255,255,255,0.06) !important; }
html.dark #rm-root .border-slate-100 { border-color: rgba(255,255,255,0.04) !important; }
html.dark #rm-root .divide-slate-100 > * + * { border-color: rgba(255,255,255,0.04) !important; }
html.dark #rm-root .hover\:bg-slate-50:hover { background: #1a1e28 !important; }
html.dark #rm-root .hover\:bg-slate-100:hover { background: #1a1e28 !important; }
html.dark #rm-root input[type="text"],
html.dark #rm-root input[type="number"],
html.dark #rm-root textarea,
html.dark #rm-root select { background: #1a1e28 !important; color: #eef0f5 !important; border-color: rgba(255,255,255,0.12) !important; }
html.dark #rm-root option { background: #1a1e28; color: #eef0f5; }
html.dark #rm-root input::placeholder,
html.dark #rm-root textarea::placeholder { color: #545b6e !important; }
html.dark #rm-root input:disabled { background: #0d0f14 !important; color: #545b6e !important; }
html.dark #rm-root .text-slate-600.border-slate-200,
html.dark #rm-root .border-slate-200.text-slate-600 { border-color: rgba(255,255,255,0.08) !important; color: #8890a4 !important; }
html.dark #rm-root .bg-slate-100 { background: #1a1e28 !important; }
</style>
<div id="rm-root" x-data="roleManager()">

    <div x-cloak class="px-4 lg:px-6 space-y-4 pb-6">

        {{-- Tabs --}}
        <div class="flex gap-1 rounded-xl p-1 w-fit" style="background:#f1f5f9;">
            <button type="button" @click="activeTab = 'permissions'"
                    :style="activeTab === 'permissions' ? ('background:' + (dark ? '#4f7cff' : '#0b2a4a') + ';color:#fff;') : ('background:transparent;color:' + (dark ? '#eef0f5' : '#64748b'))"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Permissions Matrix
            </button>
            <button type="button" @click="activeTab = 'users'"
                    :style="activeTab === 'users' ? ('background:' + (dark ? '#4f7cff' : '#0b2a4a') + ';color:#fff;') : ('background:transparent;color:' + (dark ? '#eef0f5' : '#64748b'))"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
                User Roles
            </button>
            <button type="button" @click="activeTab = 'roles'"
                    :style="activeTab === 'roles' ? ('background:' + (dark ? '#4f7cff' : '#0b2a4a') + ';color:#fff;') : ('background:transparent;color:' + (dark ? '#eef0f5' : '#64748b'))"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all">
                Roles
            </button>
        </div>

        @if(session('success'))
            <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        {{-- ─────────────────────────────────────────────
             TAB 1: Permissions Matrix (Action-Level)
        ───────────────────────────────────────────── --}}
        <div x-show="activeTab === 'permissions'" x-cloak>
            <form id="perm-form" method="POST" action="{{ route('corex.role-manager.save') }}" x-ref="permForm">
                @csrf

                {{-- Role switcher --}}
                <div class="flex items-center gap-3 mb-4 flex-wrap">
                    <label class="text-xs font-semibold text-slate-600">Editing role:</label>
                    <div class="flex gap-1 flex-wrap">
                        @foreach($roles as $role)
                        <button type="button"
                                @click="selectedRole = '{{ $role->name }}'"
                                :style="selectedRole === '{{ $role->name }}'
                                    ? 'background:{{ $role->color }};color:#fff;'
                                    : ('background:' + (dark ? '#1a1e28' : '#f1f5f9') + ';color:' + (dark ? '#eef0f5' : '#64748b'))"
                                class="px-3 py-1.5 rounded-full text-xs font-semibold transition-all">
                            {{ $role->label }}
                            @if($role->is_owner)
                                <span class="text-[10px] opacity-75">(all)</span>
                            @endif
                        </button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2 ml-auto">
                        <label class="text-xs text-slate-500">Copy from:</label>
                        <select x-model="copyFromRole"
                                class="text-xs rounded-lg border border-slate-300 bg-white text-slate-800 py-1.5 px-2 focus:outline-none focus:border-[#00b4d8]">
                            <option value="">— select role —</option>
                            @foreach($roles as $role)
                                @if(!$role->is_owner)
                                <option value="{{ $role->name }}"
                                        x-bind:disabled="selectedRole === '{{ $role->name }}'"
                                        x-bind:class="selectedRole === '{{ $role->name }}' ? 'text-slate-300' : ''">
                                    {{ $role->label }}
                                </option>
                                @endif
                            @endforeach
                        </select>
                        <button type="button" @click="copyPermissions()"
                                :disabled="!copyFromRole"
                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                style="background:#0b2a4a;"
                                onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                            Copy
                        </button>
                    </div>
                </div>

                {{-- Two-column layout: Feature tabs (left) + Permission detail (right) --}}
                <div class="flex gap-4 items-start">

                    {{-- LEFT: Vertical feature tabs --}}
                    <div class="w-52 flex-shrink-0 rounded-xl border border-slate-200 bg-white overflow-y-auto sticky top-4" style="max-height:calc(100vh - 8rem);">
                        @foreach($matrixSections as $sectionLabel => $modules)
                            <div class="px-3 pt-3 pb-1">
                                <p class="text-[10px] font-bold uppercase tracking-wider" style="color:#0b2a4a;">{{ $sectionLabel }}</p>
                            </div>
                            @foreach($modules as $moduleKey => $moduleData)
                            <div class="px-2 pb-1">
                                <button type="button"
                                        @click="selectedFeature = '{{ $moduleKey }}'"
                                        :style="selectedFeature === '{{ $moduleKey }}' ? ('background:' + (dark ? '#4f7cff' : '#0b2a4a') + ';color:#fff;') : ('color:' + (dark ? '#eef0f5' : '#475569'))"
                                        class="w-full text-left px-3 py-2 rounded-lg text-xs font-medium transition-all hover:bg-slate-100"
                                        :class="selectedFeature === '{{ $moduleKey }}' ? '' : 'hover:bg-slate-100'">
                                    {{ $moduleData['label'] }}
                                </button>
                            </div>
                            @endforeach
                            @if(!$loop->last)
                            <div class="mx-3 my-1 border-t border-slate-100"></div>
                            @endif
                        @endforeach
                        <div class="h-2"></div>
                    </div>

                    {{-- RIGHT: Permission detail for selected feature --}}
                    <div class="flex-1 min-w-0 overflow-y-auto sticky top-4" style="max-height:calc(100vh - 8rem);">
                        @if($permissions->isEmpty())
                            <div class="rounded-xl border border-slate-200 bg-white px-5 py-12 text-center text-sm text-slate-400">
                                No permissions defined. Run: <code class="font-mono bg-slate-100 px-1 rounded">php artisan db:seed --class=NexusPermissionSeeder</code>
                            </div>
                        @endif

                        @foreach($matrixSections as $sectionLabel => $modules)
                            @foreach($modules as $moduleKey => $moduleData)
                            @php
                                $fActionMap = [];
                                foreach ($moduleData['actions'] as $fap) {
                                    $fparts = explode('.', $fap->key);
                                    $fsuffix = end($fparts);
                                    $fActionMap[$fsuffix] = $fap;
                                }
                                $fStandardActions = ['create','edit','archive'];
                                $fOtherActions = array_diff(array_keys($fActionMap), array_merge(['view'], $fStandardActions));
                                $fIsShared = in_array($moduleKey, $sharedModules);
                                $fViewKey = $fActionMap['view']->key ?? null;
                            @endphp
                            <div x-show="selectedFeature === '{{ $moduleKey }}'">
                                <div class="rounded-xl border border-slate-200 bg-white overflow-hidden">
                                    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between" style="background:#f8fafc;">
                                        <div>
                                            <h3 class="font-semibold text-sm" style="color:#0b2a4a;">{{ $moduleData['label'] }}</h3>
                                            <p class="text-xs text-slate-400 mt-0.5">Editing permissions for: <span x-text="selectedRoleLabel()" style="color:#00b4d8;" class="font-medium"></span></p>
                                        </div>
                                        <button type="button" @click="saveMatrix()"
                                                class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-colors"
                                                style="background:#0b2a4a;"
                                                onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                                            Save Changes
                                        </button>
                                    </div>
                                    <div class="divide-y divide-slate-100">

                                        {{-- Access permissions (menu / section visibility) --}}
                                        @foreach($moduleData['access'] as $perm)
                                        <div class="px-5 py-4 flex items-center justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-medium text-slate-700">{{ $perm->label }}</p>
                                                <p class="text-xs text-slate-400 mt-0.5">Menu / section visibility</p>
                                            </div>
                                            <div class="flex-shrink-0">
                                                @foreach($roles as $role)
                                                <template x-if="selectedRole === '{{ $role->name }}'">
                                                    <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                        @if($role->is_owner)
                                                            <input type="checkbox" checked disabled
                                                                   class="w-5 h-5 rounded border-slate-300 opacity-50 cursor-not-allowed"
                                                                   style="accent-color:#0b2a4a;">
                                                        @else
                                                            <input type="checkbox"
                                                                   x-model="matrix['{{ $perm->key }}']['{{ $role->name }}']"
                                                                   @change="dirty = true"
                                                                   class="w-5 h-5 rounded border-slate-300 cursor-pointer"
                                                                   style="accent-color:#00b4d8;">
                                                        @endif
                                                        <span class="text-xs text-slate-500" x-text="matrix['{{ $perm->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
                                                    </label>
                                                </template>
                                                @endforeach
                                            </div>
                                        </div>
                                        @endforeach

                                        {{-- Action permissions --}}
                                        @if(count($moduleData['actions']) > 0)

                                            {{-- Data Scope --}}
                                            @if($fViewKey)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4">
                                                <div>
                                                    <p class="text-sm font-medium text-slate-700">Data Scope</p>
                                                    <p class="text-xs text-slate-400 mt-0.5">What records can this role see?</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <div>
                                                            @if($role->is_owner)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-500 bg-slate-100">All (Owner)</span>
                                                            @elseif($fIsShared)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-semibold text-emerald-600 bg-emerald-50">Shared — all users</span>
                                                            @else
                                                                <div class="inline-flex rounded-lg overflow-hidden border border-slate-200">
                                                                    @foreach(['none','own','branch','all'] as $scopeVal)
                                                                    <label class="inline-flex items-center cursor-pointer px-3 py-1.5 transition-colors text-xs border-r border-slate-200 last:border-r-0 whitespace-nowrap"
                                                                           :class="scopeMatrix['{{ $fViewKey }}']?.['{{ $role->name }}'] === '{{ $scopeVal }}'
                                                                               ? '{{ $scopeVal === 'none' ? 'bg-slate-700 text-white font-semibold' : ($scopeVal === 'own' ? 'bg-blue-600 text-white font-semibold' : ($scopeVal === 'branch' ? 'bg-amber-500 text-white font-semibold' : 'bg-emerald-600 text-white font-semibold')) }}'
                                                                               : 'bg-white text-slate-500 hover:bg-slate-50'">
                                                                        <input type="radio"
                                                                               name="scope_ui_{{ $fViewKey }}_{{ $role->name }}"
                                                                               value="{{ $scopeVal }}"
                                                                               x-model="scopeMatrix['{{ $fViewKey }}']['{{ $role->name }}']"
                                                                               @change="handleScopeChange('{{ $moduleKey }}', '{{ $role->name }}', '{{ $scopeVal }}')"
                                                                               class="sr-only">
                                                                        {{ $scopeVal === 'none' ? 'None' : ($scopeVal === 'branch' ? 'Branch' : ucfirst($scopeVal)) }}
                                                                    </label>
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </template>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @endif

                                            {{-- Create / Edit / Archive --}}
                                            @foreach($fStandardActions as $action)
                                            @if(isset($fActionMap[$action]))
                                            @php $fAp = $fActionMap[$action]; @endphp
                                            <div class="px-5 py-4 flex items-center justify-between gap-4">
                                                <div>
                                                    <p class="text-sm font-medium text-slate-700">{{ ucfirst($action) }}</p>
                                                    <p class="text-xs text-slate-400 mt-0.5">Can {{ strtolower($action) }} records in this module</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                            @if($role->is_owner)
                                                                <input type="checkbox" checked disabled
                                                                       class="w-5 h-5 rounded border-slate-300 opacity-50 cursor-not-allowed"
                                                                       style="accent-color:#0b2a4a;">
                                                            @else
                                                                <input type="checkbox"
                                                                       x-model="matrix['{{ $fAp->key }}']['{{ $role->name }}']"
                                                                       @change="handleActionChange('{{ $moduleKey }}', '{{ $action }}', '{{ $role->name }}')"
                                                                       class="w-5 h-5 rounded border-slate-300 cursor-pointer"
                                                                       style="accent-color:#00b4d8;"
                                                                       :disabled="scopeMatrix['{{ $fViewKey ?? '' }}']?.['{{ $role->name }}'] === 'none'">
                                                            @endif
                                                            <span class="text-xs text-slate-500" x-text="matrix['{{ $fAp->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
                                                        </label>
                                                    </template>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @endif
                                            @endforeach

                                            {{-- Other actions (manage, send, etc.) --}}
                                            @foreach($fOtherActions as $otherKey)
                                            @php $fOp = $fActionMap[$otherKey]; @endphp
                                            <div class="px-5 py-4 flex items-center justify-between gap-4">
                                                <div>
                                                    <p class="text-sm font-medium text-slate-700">{{ ucfirst($otherKey) }}</p>
                                                    <p class="text-xs text-slate-400 mt-0.5">{{ $fOp->label }}</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                            @if($role->is_owner)
                                                                <input type="checkbox" checked disabled
                                                                       class="w-5 h-5 rounded border-slate-300 opacity-50 cursor-not-allowed"
                                                                       style="accent-color:#0b2a4a;">
                                                            @else
                                                                <input type="checkbox"
                                                                       x-model="matrix['{{ $fOp->key }}']['{{ $role->name }}']"
                                                                       @change="dirty = true"
                                                                       class="w-5 h-5 rounded border-slate-300 cursor-pointer"
                                                                       style="accent-color:#00b4d8;">
                                                            @endif
                                                            <span class="text-xs text-slate-500" x-text="matrix['{{ $fOp->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
                                                        </label>
                                                    </template>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @endforeach

                                        @endif

                                        @if(count($moduleData['access']) === 0 && count($moduleData['actions']) === 0)
                                        <div class="px-5 py-8 text-center text-sm text-slate-400">
                                            No permissions defined for this module.
                                        </div>
                                        @endif

                                    </div>
                                </div>
                            </div>
                            @endforeach
                        @endforeach
                    </div>

                </div>

                {{-- Hidden inputs — SELECTED ROLE only to stay under PHP max_input_vars (1000) --}}
                {{-- savePermissions does per-role delete+insert; other roles are untouched --}}
                <div class="hidden">
                    <input type="hidden" name="role" :value="selectedRole">
                    @foreach($permissions as $perm)
                        @if($perm->type === 'action' && str_ends_with($perm->key, '.view'))
                            <input type="hidden"
                                   name="permissions[{{ $perm->key }}]"
                                   :value="scopeMatrix['{{ $perm->key }}']?.[selectedRole] && scopeMatrix['{{ $perm->key }}'][selectedRole] !== 'none' ? '1' : '0'">
                            <input type="hidden"
                                   name="scopes[{{ $perm->key }}]"
                                   :value="scopeMatrix['{{ $perm->key }}']?.[selectedRole] || 'none'">
                        @else
                            <input type="hidden"
                                   name="permissions[{{ $perm->key }}]"
                                   :value="matrix['{{ $perm->key }}']?.[selectedRole] ? '1' : '0'">
                        @endif
                    @endforeach
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
                                @if(auth()->user()->hasPermission('change_user_roles'))
                                    <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:#0b2a4a;">Change Role</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($users as $u)
                                @php
                                    $userRoleModel = $roles->firstWhere('name', $u->role);
                                    $badgeBg = $userRoleModel?->color ?? '#64748b';
                                    $roleLabel = $userRoleModel?->label ?? ucfirst(str_replace('_', ' ', $u->role ?? 'agent'));
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
                                    @if(auth()->user()->hasPermission('change_user_roles'))
                                        <td class="py-2.5 px-4">
                                            <form method="POST" action="{{ route('corex.role-manager.user-role') }}"
                                                  class="flex items-center gap-2">
                                                @csrf
                                                <input type="hidden" name="user_id" value="{{ $u->id }}">
                                                <select name="role"
                                                        class="text-xs rounded-lg border border-slate-300 bg-white text-slate-800 py-1.5 px-2 focus:outline-none focus:border-[#00b4d8]"
                                                        style="max-width:160px;">
                                                    @foreach($roles as $role)
                                                        @if($role->is_owner && !auth()->user()->isOwnerRole())
                                                            @continue
                                                        @endif
                                                        <option value="{{ $role->name }}" {{ $u->role === $role->name ? 'selected' : '' }}>
                                                            {{ $role->label }}
                                                        </option>
                                                    @endforeach
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

        {{-- ─────────────────────────────────────────────
             TAB 3: Roles
        ───────────────────────────────────────────── --}}
        <div x-show="activeTab === 'roles'" x-cloak>
            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between" style="background:#f8fafc;">
                    <div>
                        <h3 class="font-semibold text-sm" style="color:#0b2a4a;">Roles</h3>
                        <p class="text-xs text-slate-400 mt-0.5">{{ $roles->count() }} roles defined</p>
                    </div>
                    <button type="button" @click="openAddRole()"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-colors"
                            style="background:#0b2a4a;"
                            onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                        + Add New Role
                    </button>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach($roles as $role)
                    <div class="px-5 py-4 flex items-center gap-4 hover:bg-slate-50 transition-colors">
                        <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold text-white min-w-[100px] justify-center"
                              style="background:{{ $role->color }};">
                            {{ $role->label }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-mono text-slate-400">{{ $role->name }}</span>
                                @if($role->is_owner)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                        OWNER
                                    </span>
                                @endif
                            </div>
                            @if($role->description)
                                <p class="text-xs text-slate-500 mt-0.5">{{ $role->description }}</p>
                            @endif
                        </div>
                        <div class="text-center px-3">
                            <div class="text-lg font-bold" style="color:#0b2a4a;">{{ $role->users_count }}</div>
                            <div class="text-[10px] text-slate-400 uppercase tracking-wider">Users</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="openEditRole({{ $role->id }}, {{ Js::from($role->only('name','label','description','color','sort_order','is_owner','can_be_deleted')) }})"
                                    class="px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-200 text-slate-600 hover:bg-slate-100 transition-colors">
                                Edit
                            </button>
                            @if(!$role->is_owner && $role->can_be_deleted)
                            <button type="button"
                                    @click="openDeleteRole({{ $role->id }}, {{ Js::from($role->label) }}, {{ $role->users_count }})"
                                    class="px-3 py-1.5 rounded-lg text-xs font-medium border border-red-200 text-red-600 hover:bg-red-50 transition-colors">
                                Delete
                            </button>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>

    {{-- ─────────────────────────────────────────────
         MODAL: Add / Edit Role
    ───────────────────────────────────────────── --}}
    <div x-show="showRoleModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
         @click.self="showRoleModal = false"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>
            <div class="px-6 py-4 border-b border-slate-200" style="background:#f8fafc;">
                <h3 class="font-semibold text-sm" style="color:#0b2a4a;" x-text="editRoleId ? 'Edit Role' : 'Add New Role'"></h3>
            </div>
            <form :action="editRoleId ? '{{ url('corex/role-manager/roles') }}/' + editRoleId : '{{ route('corex.role-manager.roles.store') }}'"
                  method="POST" class="p-6 space-y-4">
                @csrf
                <template x-if="editRoleId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Label <span class="text-red-500">*</span></label>
                        <input type="text" name="label" x-model="roleForm.label"
                               @input="if(!editRoleId) roleForm.name = roleForm.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8]"
                               placeholder="Office Admin" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Slug</label>
                        <input type="text" name="name" x-model="roleForm.name"
                               :disabled="editRoleId !== null"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8] disabled:bg-slate-100 disabled:text-slate-400 font-mono"
                               placeholder="office_admin">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Description</label>
                    <textarea name="description" x-model="roleForm.description" rows="2"
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8]"
                              placeholder="Can manage office operations..."></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Colour</label>
                        <div class="flex gap-2 flex-wrap">
                            @php
                                $colorPalette = ['#0d9488','#0b2a4a','#00b4d8','#0891b2','#16a34a','#dc2626','#9333ea','#ea580c','#64748b','#475569'];
                            @endphp
                            @foreach($colorPalette as $c)
                            <button type="button"
                                    @click="roleForm.color = '{{ $c }}'"
                                    :class="roleForm.color === '{{ $c }}' ? 'ring-2 ring-offset-1 ring-slate-800' : ''"
                                    class="w-7 h-7 rounded-full transition-all"
                                    style="background:{{ $c }};"></button>
                            @endforeach
                        </div>
                        <input type="hidden" name="color" x-model="roleForm.color">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1">Sort Order</label>
                        <input type="number" name="sort_order" x-model="roleForm.sort_order"
                               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8]"
                               placeholder="0">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Preview</label>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold text-white"
                          :style="'background:' + roleForm.color"
                          x-text="roleForm.label || 'New Role'"></span>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showRoleModal = false"
                            class="px-4 py-2 rounded-lg text-xs font-semibold border border-slate-200 text-slate-600 hover:bg-slate-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-white transition-colors"
                            style="background:#0b2a4a;"
                            onmouseover="this.style.background='#00b4d8'" onmouseout="this.style.background='#0b2a4a'">
                        <span x-text="editRoleId ? 'Update Role' : 'Create Role'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────
         MODAL: Delete Role (with reassignment)
    ───────────────────────────────────────────── --}}
    <div x-show="showDeleteModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
         @click.self="showDeleteModal = false"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
            <div class="px-6 py-4 border-b border-slate-200" style="background:#fef2f2;">
                <h3 class="font-semibold text-sm text-red-700">Delete Role</h3>
            </div>
            <form :action="'{{ url('corex/role-manager/roles') }}/' + deleteRoleId"
                  method="POST" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="_method" value="DELETE">

                <p class="text-sm text-slate-700">
                    Are you sure you want to delete <strong x-text="deleteRoleLabel"></strong>?
                </p>

                <template x-if="deleteRoleUserCount > 0">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                        <p class="text-sm text-amber-800 font-medium">
                            <span x-text="deleteRoleUserCount"></span> active user(s) have this role.
                        </p>
                        <p class="text-xs text-amber-700 mt-1">Reassign them to:</p>
                        <select name="reassign_to"
                                class="mt-2 w-full rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm focus:outline-none focus:border-[#00b4d8]">
                            @foreach($roles as $role)
                                @if(!$role->is_owner || auth()->user()->isOwnerRole())
                                <option value="{{ $role->name }}">{{ $role->label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </template>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showDeleteModal = false"
                            class="px-4 py-2 rounded-lg text-xs font-semibold border border-slate-200 text-slate-600 hover:bg-slate-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-white bg-red-600 hover:bg-red-700 transition-colors">
                        Delete Role
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

{{-- Toast notification --}}
<div x-show="copyToast" x-cloak
     x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
     class="fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-lg text-sm font-medium text-white"
     style="background:#0b2a4a;">
    <span x-text="copyToastMsg"></span>
</div>

<script>
function roleManager() {
    const grantedData = @json($granted);
    const scopeData = @json($scopeGranted);
    const rolesData = @json($rolesJson);
    const allPermKeys = @json($allPermKeys);
    const sharedModules = @json($sharedModules);

    // Module -> action keys mapping for cascading logic
    const moduleActions = @json($moduleActionsMap);

    // All .view keys
    const viewKeys = allPermKeys.filter(k => k.endsWith('.view'));

    // Initialize matrix: matrix[permKey][roleName] = true/false
    let matrix = {};
    allPermKeys.forEach(key => {
        matrix[key] = {};
        rolesData.forEach(r => {
            matrix[key][r.name] = !!(grantedData[key] && grantedData[key][r.name]);
        });
    });

    // Initialize scopeMatrix: scopeMatrix[viewKey][roleName] = 'none'|'own'|'branch'|'all'
    let scopeMatrix = {};
    viewKeys.forEach(key => {
        scopeMatrix[key] = {};
        rolesData.forEach(r => {
            if (r.is_owner) {
                scopeMatrix[key][r.name] = 'all';
            } else if (scopeData[key] && scopeData[key][r.name]) {
                scopeMatrix[key][r.name] = scopeData[key][r.name];
            } else if (grantedData[key] && grantedData[key][r.name]) {
                // Has permission but no scope set — default to 'all'
                scopeMatrix[key][r.name] = 'all';
            } else {
                scopeMatrix[key][r.name] = 'none';
            }
        });
    });

    return {
        dark: document.documentElement.classList.contains('dark'),
        activeTab: 'permissions',
        selectedRole: rolesData.find(r => !r.is_owner)?.name || rolesData[0]?.name || 'admin',
        matrix: matrix,
        scopeMatrix: scopeMatrix,
        dirty: false,
        selectedFeature: @json(collect($matrixSections)->flatMap(fn($m) => array_keys($m))->first() ?? ''),

        // Copy from role
        copyFromRole: '',
        copyToast: false,
        copyToastMsg: '',

        // Role modals
        showRoleModal: false,
        showDeleteModal: false,
        editRoleId: null,
        deleteRoleId: null,
        deleteRoleLabel: '',
        deleteRoleUserCount: 0,
        roleForm: { label: '', name: '', description: '', color: '#0d9488', sort_order: 0 },

        selectedRoleLabel() {
            const r = rolesData.find(r => r.name === this.selectedRole);
            return r ? r.label : this.selectedRole;
        },

        handleScopeChange(moduleKey, roleName, scopeVal) {
            this.dirty = true;
            const actions = moduleActions[moduleKey];
            if (!actions) return;

            const viewKey = actions['view'];
            const createKey = actions['create'];
            const editKey = actions['edit'];
            const archiveKey = actions['archive'];

            if (scopeVal === 'none') {
                // Setting scope to None → untick create/edit/archive
                if (createKey && this.matrix[createKey]) this.matrix[createKey][roleName] = false;
                if (editKey && this.matrix[editKey]) this.matrix[editKey][roleName] = false;
                if (archiveKey && this.matrix[archiveKey]) this.matrix[archiveKey][roleName] = false;
            }
        },

        handleActionChange(moduleKey, action, roleName) {
            this.dirty = true;
            const actions = moduleActions[moduleKey];
            if (!actions) return;

            const viewKey = actions['view'];

            if (action !== 'view') {
                const thisKey = actions[action];
                // Ticking create/edit/archive when scope is 'none' → auto-set scope to 'own'
                if (thisKey && this.matrix[thisKey]?.[roleName] && viewKey) {
                    if (this.scopeMatrix[viewKey]?.[roleName] === 'none') {
                        this.scopeMatrix[viewKey][roleName] = 'own';
                    }
                }
            }
        },

        copyPermissions() {
            const src = this.copyFromRole;
            const dst = this.selectedRole;
            if (!src || !dst || src === dst) return;

            // Find if destination is owner (shouldn't copy to owner)
            const dstRole = rolesData.find(r => r.name === dst);
            if (dstRole?.is_owner) return;

            // Copy checkbox matrix
            allPermKeys.forEach(key => {
                if (this.matrix[key]) {
                    this.matrix[key][dst] = !!(this.matrix[key][src]);
                }
            });

            // Copy scope matrix
            viewKeys.forEach(key => {
                if (this.scopeMatrix[key]) {
                    this.scopeMatrix[key][dst] = this.scopeMatrix[key][src] || 'none';
                }
            });

            this.dirty = true;
            this.copyFromRole = '';

            // Show toast
            const srcLabel = rolesData.find(r => r.name === src)?.label || src;
            this.copyToastMsg = `Copied permissions from ${srcLabel} — review and save`;
            this.copyToast = true;
            setTimeout(() => { this.copyToast = false; }, 4000);
        },

        saveMatrix() {
            this.$refs.permForm.submit();
        },

        openAddRole() {
            this.editRoleId = null;
            this.roleForm = { label: '', name: '', description: '', color: '#0d9488', sort_order: 0 };
            this.showRoleModal = true;
        },
        openEditRole(id, data) {
            this.editRoleId = id;
            this.roleForm = {
                label: data.label || '',
                name: data.name || '',
                description: data.description || '',
                color: data.color || '#0d9488',
                sort_order: data.sort_order || 0,
            };
            this.showRoleModal = true;
        },
        openDeleteRole(id, label, userCount) {
            this.deleteRoleId = id;
            this.deleteRoleLabel = label;
            this.deleteRoleUserCount = userCount;
            this.showDeleteModal = true;
        },
    };
}
</script>
@endsection
