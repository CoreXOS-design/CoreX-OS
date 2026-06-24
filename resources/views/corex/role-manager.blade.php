@extends('layouts.corex-app')

@section('corex-content')
<style>
/* ── Role Manager: scoped component styles ── */
#rm-root .rm-scope-btn { transition: all 300ms; }
#rm-root .rm-scope-btn[data-active="true"] { color: #fff; font-weight: 600; }
#rm-root .rm-scope-btn[data-scope="none"][data-active="true"] { background: var(--text-secondary); }
#rm-root .rm-scope-btn[data-scope="own"][data-active="true"] { background: var(--brand-button, #0ea5e9); }
#rm-root .rm-scope-btn[data-scope="branch"][data-active="true"] { background: var(--ds-amber, #f59e0b); }
#rm-root .rm-scope-btn[data-scope="all"][data-active="true"] { background: var(--ds-green, #059669); }
</style>
<div id="rm-root" x-data="roleManager()">

    <div x-cloak class="px-4 lg:px-6 space-y-5 pb-6">

        {{-- Page header --}}
        <div class="rounded-md px-6 py-5 flex items-center justify-between" style="background:var(--brand-default,#0b2a4a);">
            <div>
                <h1 class="text-xl font-bold text-white tracking-tight leading-tight">Role Manager</h1>
                <p class="text-sm text-white/60 mt-0.5">Manage roles, permissions & user assignments.</p>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex gap-1 rounded-md p-1 w-fit" style="background:var(--surface-2);">
            <button type="button" @click="activeTab = 'permissions'"
                    class="px-4 py-2 rounded-md text-sm font-medium transition-all duration-300"
                    :style="activeTab === 'permissions' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:transparent;color:var(--text-secondary);'">
                Permissions Matrix
            </button>
            <button type="button" @click="activeTab = 'users'"
                    class="px-4 py-2 rounded-md text-sm font-medium transition-all duration-300"
                    :style="activeTab === 'users' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:transparent;color:var(--text-secondary);'">
                User Roles
            </button>
            <button type="button" @click="activeTab = 'roles'"
                    class="px-4 py-2 rounded-md text-sm font-medium transition-all duration-300"
                    :style="activeTab === 'roles' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:transparent;color:var(--text-secondary);'">
                Roles
            </button>
        </div>

        @if(session('success'))
            <div class="rounded-md px-4 py-3 text-sm font-medium"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-md px-4 py-3 text-sm font-medium"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);">
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
                    <label class="text-xs font-semibold" style="color:var(--text-muted);">Editing role:</label>
                    <div class="flex gap-1 flex-wrap">
                        @foreach($roles as $role)
                        <button type="button"
                                @click="switchRole('{{ $role->name }}')"
                                :style="selectedRole === '{{ $role->name }}'
                                    ? 'background:{{ $role->color }};color:#fff;'
                                    : 'background:var(--surface-2);color:var(--text-secondary);'"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300">
                            {{ $role->label }}
                            @if($role->is_owner)
                                <span class="text-[10px] opacity-75">(all)</span>
                            @endif
                        </button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2 ml-auto">
                        <label class="text-xs" style="color:var(--text-muted);">Copy from:</label>
                        <select x-model="copyFromRole"
                                class="text-xs rounded-md py-1.5 px-2 transition-all duration-300"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                            <option value="">— select role —</option>
                            @foreach($roles as $role)
                                @if(!$role->is_owner)
                                <option value="{{ $role->name }}"
                                        x-bind:disabled="selectedRole === '{{ $role->name }}'">
                                    {{ $role->label }}
                                </option>
                                @endif
                            @endforeach
                        </select>
                        <button type="button" @click="copyPermissions()"
                                :disabled="!copyFromRole"
                                class="corex-btn-primary px-3 py-1.5 text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
                            Copy
                        </button>
                        <div class="mx-1" style="width:1px;height:20px;background:var(--border);"></div>
                        <button type="button" @click="showCopyModal = true"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold transition-all duration-300"
                                style="border:1px solid var(--border); color:var(--text-secondary);"
                                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            Bulk Copy & Save
                        </button>
                    </div>
                </div>

                {{-- Two-column layout: Feature tabs (left) + Permission detail (right) --}}
                <div class="flex gap-4 items-start">

                    {{-- LEFT: Vertical feature tabs --}}
                    <div class="w-52 flex-shrink-0 rounded-md overflow-y-auto sticky top-4" style="background:var(--surface); border:1px solid var(--border); max-height:calc(100vh - 8rem);">
                        <div class="px-2 pt-2 pb-2 sticky top-0 z-10" style="background:var(--surface); border-bottom:1px solid var(--border);">
                            <input type="text" x-model="featureSearch" placeholder="Search features…"
                                   class="w-full text-xs rounded-md px-2 py-1.5"
                                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        </div>
                        @foreach($matrixSections as $sectionLabel => $modules)
                            @php
                                $moduleKeysInSection = array_keys($modules);
                                $sectionMatchExpr = collect($modules)->map(fn($d, $k) => "matchesFeature('{$k}', '" . addslashes($d['label']) . "')")->implode(' || ');
                            @endphp
                            <div x-show="{{ $sectionMatchExpr }}">
                                <div class="px-3 pt-3 pb-1">
                                    <p class="text-[10px] font-bold uppercase tracking-wider" style="color:var(--brand-icon,#0ea5e9);">{{ $sectionLabel }}</p>
                                </div>
                                @foreach($modules as $moduleKey => $moduleData)
                                <div class="px-2 pb-1" x-show="matchesFeature('{{ $moduleKey }}', '{{ addslashes($moduleData['label']) }}')">
                                    <button type="button"
                                            @click="selectedFeature = '{{ $moduleKey }}'; syncUrl()"
                                            :style="selectedFeature === '{{ $moduleKey }}' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'color:var(--text-secondary);'"
                                            class="w-full text-left px-3 py-2 rounded-md text-xs font-medium transition-all duration-300"
                                            :class="selectedFeature !== '{{ $moduleKey }}' ? 'hover:opacity-80' : ''">
                                        {{ $moduleData['label'] }}
                                    </button>
                                </div>
                                @endforeach
                                @if(!$loop->last)
                                <div class="mx-3 my-1" style="border-top:1px solid var(--border);"></div>
                                @endif
                            </div>
                        @endforeach
                        <div class="h-2"></div>
                    </div>

                    {{-- RIGHT: Permission detail for selected feature --}}
                    <div class="flex-1 min-w-0 overflow-y-auto sticky top-4" style="max-height:calc(100vh - 8rem);">
                        @if($permissions->isEmpty())
                            <div class="rounded-md px-5 py-12 text-center text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                                No permissions defined. Run: <code class="font-mono px-1 rounded-md" style="background:var(--surface-2);">php artisan corex:sync-permissions --seed-defaults</code>
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
                                <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                                    <div class="px-5 py-3 flex items-center justify-between" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                        <div>
                                            <h3 class="font-semibold text-sm" style="color:var(--text-primary);">{{ $moduleData['label'] }}</h3>
                                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Editing permissions for: <span x-text="selectedRoleLabel()" style="color:var(--brand-icon,#0ea5e9);" class="font-medium"></span></p>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-xs" style="color:var(--text-muted);" x-text="saveStatusText()"></span>
                                            <button type="button" @click="saveMatrix(true)"
                                                    :disabled="saving"
                                                    class="corex-btn-primary px-3 py-1.5 text-xs font-semibold disabled:opacity-50">
                                                Save now
                                            </button>
                                        </div>
                                    </div>
                                    <div>

                                        {{-- Access permissions (menu / section visibility) --}}
                                        @foreach($moduleData['access'] as $perm)
                                        <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                            <div>
                                                <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $perm->label }}</p>
                                                <p class="text-xs mt-0.5" style="color:var(--text-muted);">Menu / section visibility</p>
                                            </div>
                                            <div class="flex-shrink-0">
                                                @foreach($roles as $role)
                                                <template x-if="selectedRole === '{{ $role->name }}'">
                                                    <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                        @if($role->is_owner)
                                                            <input type="checkbox" checked disabled
                                                                   class="w-5 h-5 rounded-md opacity-50 cursor-not-allowed"
                                                                   style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                        @else
                                                            <input type="checkbox"
                                                                   x-model="matrix['{{ $perm->key }}']['{{ $role->name }}']"
                                                                   @change="scheduleSave()"
                                                                   class="w-5 h-5 rounded-md cursor-pointer"
                                                                   style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                        @endif
                                                        <span class="text-xs" style="color:var(--text-muted);" x-text="matrix['{{ $perm->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
                                                    </label>
                                                </template>
                                                @endforeach
                                            </div>
                                        </div>
                                        @endforeach

                                        {{-- Action permissions --}}
                                        @if(count($moduleData['actions']) > 0)

                                            {{-- Data Scope --}}
                                            @if($fViewKey && in_array($moduleKey, ['properties', 'contacts']))
                                            {{-- Simplified on/off toggle. OFF stores scope='own' (user sees only their own records, no agent picker, no My/All toggle). ON stores scope='all'; the effective breadth (branch vs agency) is dictated at request time by the agency's Data Isolation setting in Company Settings. --}}
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">Data Scope</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Off: user sees only their own records — no agent picker, no My/All toggle. On: user can see other agents' records (limited to their branch when Data Isolation is enabled in Company Settings, otherwise agency-wide).</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <div>
                                                            @if($role->is_owner)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold" style="background:var(--surface-2); color:var(--text-muted);">On (Owner)</span>
                                                            @elseif($fIsShared)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold"
                                                                      style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">Shared — all users</span>
                                                            @else
                                                                <label class="inline-flex items-center gap-2 cursor-pointer">
                                                                    <input type="checkbox"
                                                                           :checked="['branch','all'].includes(scopeMatrix['{{ $fViewKey }}']?.['{{ $role->name }}'])"
                                                                           @change="
                                                                               const next = $event.target.checked ? 'all' : 'own';
                                                                               scopeMatrix['{{ $fViewKey }}']['{{ $role->name }}'] = next;
                                                                               handleScopeChange('{{ $moduleKey }}', '{{ $role->name }}', next);
                                                                           "
                                                                           class="w-5 h-5 rounded-md cursor-pointer"
                                                                           style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                                    <span class="text-xs" style="color:var(--text-muted);"
                                                                          x-text="['branch','all'].includes(scopeMatrix['{{ $fViewKey }}']?.['{{ $role->name }}']) ? 'On' : 'Off'"></span>
                                                                </label>
                                                            @endif
                                                        </div>
                                                    </template>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @elseif($fViewKey)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">Data Scope</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">None = no access. Own = locked to own records. Branch / All = user can toggle between their own records and other users' records on the list page.</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <div>
                                                            @if($role->is_owner)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold" style="background:var(--surface-2); color:var(--text-muted);">All (Owner)</span>
                                                            @elseif($fIsShared)
                                                                <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold"
                                                                      style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">Shared — all users</span>
                                                            @else
                                                                <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);">
                                                                    @foreach(['none','own','branch','all'] as $scopeVal)
                                                                    <label class="rm-scope-btn inline-flex items-center cursor-pointer px-3 py-1.5 text-xs whitespace-nowrap"
                                                                           style="border-right:1px solid var(--border);"
                                                                           :data-active="scopeMatrix['{{ $fViewKey }}']?.['{{ $role->name }}'] === '{{ $scopeVal }}' ? 'true' : 'false'"
                                                                           data-scope="{{ $scopeVal }}"
                                                                           :style="scopeMatrix['{{ $fViewKey }}']?.['{{ $role->name }}'] === '{{ $scopeVal }}'
                                                                               ? ''
                                                                               : 'background:var(--surface);color:var(--text-muted);'">
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
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">{{ ucfirst($action) }}</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Can {{ strtolower($action) }} records in this module</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                            @if($role->is_owner)
                                                                <input type="checkbox" checked disabled
                                                                       class="w-5 h-5 rounded-md opacity-50 cursor-not-allowed"
                                                                       style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                            @else
                                                                <input type="checkbox"
                                                                       x-model="matrix['{{ $fAp->key }}']['{{ $role->name }}']"
                                                                       @change="handleActionChange('{{ $moduleKey }}', '{{ $action }}', '{{ $role->name }}')"
                                                                       class="w-5 h-5 rounded-md cursor-pointer"
                                                                       style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);"
                                                                       :disabled="scopeMatrix['{{ $fViewKey ?? '' }}']?.['{{ $role->name }}'] === 'none'">
                                                            @endif
                                                            <span class="text-xs" style="color:var(--text-muted);" x-text="matrix['{{ $fAp->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
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
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::headline($otherKey) }}</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $fOp->label }}</p>
                                                </div>
                                                <div class="flex-shrink-0">
                                                    @foreach($roles as $role)
                                                    <template x-if="selectedRole === '{{ $role->name }}'">
                                                        <label class="inline-flex items-center gap-2 {{ $role->is_owner ? '' : 'cursor-pointer' }}">
                                                            @if($role->is_owner)
                                                                <input type="checkbox" checked disabled
                                                                       class="w-5 h-5 rounded-md opacity-50 cursor-not-allowed"
                                                                       style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                            @else
                                                                <input type="checkbox"
                                                                       x-model="matrix['{{ $fOp->key }}']['{{ $role->name }}']"
                                                                       @change="scheduleSave()"
                                                                       class="w-5 h-5 rounded-md cursor-pointer"
                                                                       style="accent-color:var(--brand-button,#0ea5e9); border-color:var(--border);">
                                                            @endif
                                                            <span class="text-xs" style="color:var(--text-muted);" x-text="matrix['{{ $fOp->key }}']?.['{{ $role->name }}'] ? 'Enabled' : 'Disabled'"></span>
                                                        </label>
                                                    </template>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @endforeach

                                        @endif

                                        @if(count($moduleData['access']) === 0 && count($moduleData['actions']) === 0)
                                        <div class="px-5 py-8 text-center text-sm" style="color:var(--text-muted);">
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
            <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                <div class="px-5 py-3 flex items-center justify-between" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                    <h3 class="font-semibold text-sm" style="color:var(--text-primary);">User Roles</h3>
                    <span class="text-xs" style="color:var(--text-muted);">{{ $users->count() }} users</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Name</th>
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Email</th>
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Agency</th>
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Branch</th>
                                <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Current Role</th>
                                @if(auth()->user()->hasPermission('change_user_roles'))
                                    <th class="text-left py-3 px-4 font-semibold text-xs uppercase tracking-wider" style="color:var(--text-muted);">Change Role</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $u)
                                @php
                                    $userRoleModel = $roles->firstWhere('name', $u->role);
                                    $badgeBg = $userRoleModel?->color ?? '#64748b';
                                    $roleLabel = $userRoleModel?->label ?? ucfirst(str_replace('_', ' ', $u->role ?? 'agent'));
                                    $branchName  = $branches->firstWhere('id', $u->branch_id)?->name ?? '—';
                                    $agencyName  = $agencies->firstWhere('id', $u->agency_id)?->name ?? '—';
                                @endphp
                                <tr class="transition-all duration-300" style="border-bottom:1px solid var(--border);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <td class="py-2.5 px-4 font-medium" style="color:var(--text-primary);">{{ $u->name }}</td>
                                    <td class="py-2.5 px-4" style="color:var(--text-secondary);">{{ $u->email }}</td>
                                    <td class="py-2.5 px-4 text-xs" style="color:var(--text-secondary);">{{ $agencyName }}</td>
                                    <td class="py-2.5 px-4 text-xs" style="color:var(--text-secondary);">{{ $branchName }}</td>
                                    <td class="py-2.5 px-4">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold text-white user-role-badge"
                                              data-user-id="{{ $u->id }}"
                                              style="background:{{ $badgeBg }};">
                                            {{ $roleLabel }}
                                        </span>
                                    </td>
                                    @if(auth()->user()->hasPermission('change_user_roles'))
                                        <td class="py-2.5 px-4">
                                            <form method="POST" action="{{ route('corex.role-manager.user-role') }}"
                                                  class="flex items-center gap-2 user-role-form"
                                                  @submit.prevent="saveUserRole($event, {{ $u->id }})">
                                                @csrf
                                                <input type="hidden" name="user_id" value="{{ $u->id }}">
                                                <select name="role"
                                                        class="text-xs rounded-md py-1.5 px-2 transition-all duration-300"
                                                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none; max-width:160px;">
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
                                                        class="corex-btn-primary px-3 py-1.5 text-xs font-semibold">
                                                    Save
                                                </button>
                                                <span class="text-xs user-role-status" style="color:var(--text-muted);"></span>
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
            <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
                <div class="px-5 py-3 flex items-center justify-between" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                    <div>
                        <h3 class="font-semibold text-sm" style="color:var(--text-primary);">Roles</h3>
                        <p class="text-xs mt-0.5" style="color:var(--text-muted);">{{ $roles->count() }} roles defined</p>
                    </div>
                    <button type="button" @click="openAddRole()"
                            class="corex-btn-primary px-3 py-1.5 text-xs font-semibold">
                        + Add New Role
                    </button>
                </div>
                <div>
                    @foreach($roles as $role)
                    <div class="px-5 py-4 flex items-center gap-4 transition-all duration-300" style="border-bottom:1px solid var(--border);"
                         onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold text-white min-w-[100px] justify-center"
                              style="background:{{ $role->color }};">
                            {{ $role->label }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-mono" style="color:var(--text-muted);">{{ $role->name }}</span>
                                @if($role->is_owner)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase whitespace-nowrap"
                                          style="background: color-mix(in srgb, var(--ds-amber) 14%, transparent); color: var(--ds-amber);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                        OWNER
                                    </span>
                                @endif
                            </div>
                            @if($role->description)
                                <p class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $role->description }}</p>
                            @endif
                        </div>
                        <div class="text-center px-3">
                            <div class="text-lg font-bold" style="color:var(--brand-icon,#0ea5e9);">{{ $role->users_count }}</div>
                            <div class="text-[10px] uppercase tracking-wider" style="color:var(--text-muted);">Users</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    @click="openEditRole({{ $role->id }}, {{ Js::from($role->only('name','label','description','color','sort_order','is_owner','can_be_deleted','oversight_scope')) }})"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                                    style="border:1px solid var(--border); color:var(--text-secondary);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                Edit
                            </button>
                            @if(!$role->is_owner && $role->can_be_deleted)
                            <button type="button"
                                    @click="openDeleteRole({{ $role->id }}, {{ Js::from($role->label) }}, {{ $role->users_count }})"
                                    class="px-3 py-1.5 rounded-md text-xs font-medium transition-all duration-300"
                                    style="border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--ds-crimson);"
                                    onmouseover="this.style.background='color-mix(in srgb, var(--ds-crimson) 8%, transparent)'" onmouseout="this.style.background='transparent'">
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
        <div class="rounded-md shadow-xl w-full max-w-lg mx-4 overflow-hidden" style="background:var(--surface);" @click.stop>
            <div class="px-6 py-4" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                <h3 class="font-semibold text-sm" style="color:var(--text-primary);" x-text="editRoleId ? 'Edit Role' : 'Add New Role'"></h3>
            </div>
            <form :action="editRoleId ? '{{ url('corex/role-manager/roles') }}/' + editRoleId : '{{ route('corex.role-manager.roles.store') }}'"
                  method="POST" class="p-6 space-y-4">
                @csrf
                <template x-if="editRoleId">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Label <span class="text-red-500">*</span></label>
                        <input type="text" name="label" x-model="roleForm.label"
                               @input="if(!editRoleId) roleForm.name = roleForm.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')"
                               class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;"
                               placeholder="Office Admin" required>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Slug</label>
                        <input type="text" name="name" x-model="roleForm.name"
                               :disabled="editRoleId !== null"
                               class="w-full rounded-md px-3 py-2 text-sm font-mono transition-all duration-300 disabled:opacity-50"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;"
                               placeholder="office_admin">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Description</label>
                    <textarea name="description" x-model="roleForm.description" rows="2"
                              class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                              style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;"
                              placeholder="Can manage office operations..."></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Colour</label>
                        <div class="flex gap-2 flex-wrap">
                            @php
                                $colorPalette = ['#0d9488','#0b2a4a','#00b4d8','#0891b2','#16a34a','#dc2626','#9333ea','#ea580c','#64748b','#475569'];
                            @endphp
                            @foreach($colorPalette as $c)
                            <button type="button"
                                    @click="roleForm.color = '{{ $c }}'"
                                    :class="roleForm.color === '{{ $c }}' ? 'ring-2 ring-offset-1' : ''"
                                    class="w-7 h-7 rounded-md transition-all duration-300"
                                    :style="roleForm.color === '{{ $c }}' ? 'background:{{ $c }};ring-color:var(--text-primary);' : 'background:{{ $c }};'"
                                    style="background:{{ $c }};"></button>
                            @endforeach
                        </div>
                        <input type="hidden" name="color" x-model="roleForm.color">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Sort Order</label>
                        <input type="number" name="sort_order" x-model="roleForm.sort_order"
                               class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;"
                               placeholder="0">
                    </div>
                </div>

                <div x-show="editRoleId">
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Oversight Scope</label>
                    <p class="text-xs mb-2" style="color:var(--text-muted);">If this role has the Manager Oversight permission, choose whose data they can see.</p>
                    <div class="flex gap-3">
                        <label class="text-xs flex items-center gap-1" style="color:var(--text-secondary);">
                            <input type="radio" name="oversight_scope" value="" x-model="roleForm.oversight_scope"> None
                        </label>
                        <label class="text-xs flex items-center gap-1" style="color:var(--text-secondary);">
                            <input type="radio" name="oversight_scope" value="branch" x-model="roleForm.oversight_scope"> Branch only
                        </label>
                        <label class="text-xs flex items-center gap-1" style="color:var(--text-secondary);">
                            <input type="radio" name="oversight_scope" value="agency" x-model="roleForm.oversight_scope"> Entire agency
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Preview</label>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold text-white"
                          :style="'background:' + roleForm.color"
                          x-text="roleForm.label || 'New Role'"></span>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showRoleModal = false"
                            class="px-4 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                            style="border:1px solid var(--border); color:var(--text-secondary);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        Cancel
                    </button>
                    <button type="submit"
                            class="corex-btn-primary px-4 py-2 text-xs font-semibold">
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
        <div class="rounded-md shadow-xl w-full max-w-md mx-4 overflow-hidden" style="background:var(--surface);" @click.stop>
            <div class="px-6 py-4"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border-bottom:1px solid var(--border);">
                <h3 class="font-semibold text-sm" style="color: var(--ds-crimson);">Delete Role</h3>
            </div>
            <form :action="'{{ url('corex/role-manager/roles') }}/' + deleteRoleId"
                  method="POST" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="_method" value="DELETE">

                <p class="text-sm" style="color:var(--text-primary);">
                    Are you sure you want to delete <strong x-text="deleteRoleLabel"></strong>?
                </p>

                <template x-if="deleteRoleUserCount > 0">
                    <div class="rounded-md px-4 py-3"
                         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                                border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                        <p class="text-sm font-medium" style="color: var(--text-primary);">
                            <span x-text="deleteRoleUserCount"></span> active user(s) have this role.
                        </p>
                        <p class="text-xs mt-1" style="color: var(--text-secondary);">Reassign them to:</p>
                        <select name="reassign_to"
                                class="mt-2 w-full rounded-md px-3 py-2 text-sm focus:outline-none"
                                style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
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
                            class="px-4 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                            style="border:1px solid var(--border); color:var(--text-secondary);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 rounded-md text-xs font-semibold text-white transition-all duration-300"
                            style="background: var(--ds-crimson, #c41e3a);"
                            onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                        Delete Role
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────
         MODAL: Bulk Copy Permissions
    ───────────────────────────────────────────── --}}
    <div x-show="showCopyModal" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
         @click.self="showCopyModal = false"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="rounded-md shadow-xl w-full max-w-lg mx-4 overflow-hidden" style="background:var(--surface);" @click.stop>
            <div class="px-6 py-4" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                <h3 class="font-semibold text-sm" style="color:var(--text-primary);">Bulk Copy Permissions</h3>
                <p class="text-xs mt-0.5" style="color:var(--text-muted);">Copy all permissions from one role to one or more target roles. This saves immediately.</p>
            </div>
            <form method="POST" action="{{ route('corex.role-manager.copy') }}" class="p-6 space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Copy from (source)</label>
                    <select name="source_role" x-model="copySource"
                            class="w-full rounded-md px-3 py-2 text-sm transition-all duration-300"
                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); outline:none;">
                        <option value="">— select source role —</option>
                        @foreach($roles as $role)
                            @if(!$role->is_owner)
                            <option value="{{ $role->name }}">{{ $role->label }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold mb-2" style="color:var(--text-muted);">Copy to (targets)</label>
                    <div class="space-y-2 rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        @foreach($roles as $role)
                            @if(!$role->is_owner)
                            <label class="flex items-center gap-3 cursor-pointer py-1"
                                   :class="copySource === '{{ $role->name }}' ? 'opacity-30 pointer-events-none' : ''">
                                <input type="checkbox" name="target_roles[]" value="{{ $role->name }}"
                                       :disabled="copySource === '{{ $role->name }}'"
                                       class="w-4 h-4 rounded-md cursor-pointer"
                                       style="accent-color:var(--brand-button,#0ea5e9);">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold text-white"
                                      style="background:{{ $role->color }};">
                                    {{ $role->label }}
                                </span>
                                <span class="text-xs" style="color:var(--text-muted);">{{ $role->users_count }} user(s)</span>
                            </label>
                            @endif
                        @endforeach
                    </div>
                </div>

                <div class="rounded-md px-4 py-3"
                     style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                    <p class="text-xs font-medium" style="color: var(--text-primary);">This will overwrite all existing permissions on the target role(s). This action cannot be undone.</p>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showCopyModal = false"
                            class="px-4 py-2 rounded-md text-xs font-semibold transition-all duration-300"
                            style="border:1px solid var(--border); color:var(--text-secondary);"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        Cancel
                    </button>
                    <button type="submit" :disabled="!copySource"
                            class="corex-btn-primary px-4 py-2 text-xs font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
                        Copy & Save
                    </button>
                </div>
            </form>
        </div>
    </div>

{{-- Toast notification --}}
<div x-show="copyToast" x-cloak
     x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
     class="fixed bottom-6 right-6 z-50 px-4 py-3 rounded-md shadow-lg text-sm font-medium text-white"
     style="background:var(--brand-default,#0b2a4a);">
    <span x-text="copyToastMsg"></span>
</div>

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

    const urlParams = new URLSearchParams(window.location.search);
    const firstFeature = @json(collect($matrixSections)->flatMap(fn($m) => array_keys($m))->first() ?? '');

    return {
        dark: document.documentElement.classList.contains('dark'),
        activeTab: urlParams.get('tab') || 'permissions',
        selectedRole: urlParams.get('role') || rolesData.find(r => !r.is_owner)?.name || rolesData[0]?.name || 'admin',
        matrix: matrix,
        scopeMatrix: scopeMatrix,
        dirty: false,
        saving: false,
        lastSavedAt: null,
        saveTimer: null,
        autoSaveDelay: 800,
        featureSearch: '',
        selectedFeature: urlParams.get('feature') || firstFeature,

        // Copy from role
        copyFromRole: '',
        copyToast: false,
        copyToastMsg: '',

        // Bulk copy modal
        showCopyModal: false,
        copySource: '',

        // Role modals
        showRoleModal: false,
        showDeleteModal: false,
        editRoleId: null,
        deleteRoleId: null,
        deleteRoleLabel: '',
        deleteRoleUserCount: 0,
        roleForm: { label: '', name: '', description: '', color: '#0d9488', sort_order: 0, oversight_scope: '' },

        selectedRoleLabel() {
            const r = rolesData.find(r => r.name === this.selectedRole);
            return r ? r.label : this.selectedRole;
        },

        handleScopeChange(moduleKey, roleName, scopeVal) {
            this.scheduleSave();
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
            this.scheduleSave();
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

            this.copyFromRole = '';
            this.scheduleSave();

            // Show toast
            const srcLabel = rolesData.find(r => r.name === src)?.label || src;
            this.copyToastMsg = `Copied permissions from ${srcLabel} — review and save`;
            this.copyToast = true;
            setTimeout(() => { this.copyToast = false; }, 4000);
        },

        scheduleSave() {
            this.dirty = true;
            if (this.saveTimer) clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.saveMatrix(false), this.autoSaveDelay);
        },

        async saveMatrix(manual) {
            if (this.saveTimer) { clearTimeout(this.saveTimer); this.saveTimer = null; }
            if (this.saving) return;
            if (!this.dirty && !manual) return;

            this.saving = true;
            const form = this.$refs.permForm;
            const fd = new FormData(form);
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]')?.value;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                this.dirty = false;
                this.lastSavedAt = new Date();
                this.copyToastMsg = manual ? 'Saved.' : `Auto-saved · ${this.selectedRoleLabel()}`;
                this.copyToast = true;
                clearTimeout(this._toastT);
                this._toastT = setTimeout(() => { this.copyToast = false; }, 2000);
            } catch (e) {
                this.copyToastMsg = 'Save failed — try again';
                this.copyToast = true;
                clearTimeout(this._toastT);
                this._toastT = setTimeout(() => { this.copyToast = false; }, 4000);
            } finally {
                this.saving = false;
            }
        },

        saveStatusText() {
            if (this.saving) return 'Saving…';
            if (this.dirty) return 'Unsaved changes';
            if (this.lastSavedAt) {
                const t = this.lastSavedAt;
                const hh = String(t.getHours()).padStart(2,'0');
                const mm = String(t.getMinutes()).padStart(2,'0');
                return `Saved at ${hh}:${mm}`;
            }
            return '';
        },

        switchRole(roleName) {
            if (this.dirty) {
                this.saveMatrix(false);
            }
            this.selectedRole = roleName;
            this.syncUrl();
        },

        syncUrl() {
            const p = new URLSearchParams(window.location.search);
            p.set('role', this.selectedRole);
            p.set('feature', this.selectedFeature);
            p.set('tab', this.activeTab);
            history.replaceState(null, '', window.location.pathname + '?' + p.toString());
        },

        matchesFeature(key, label) {
            const q = (this.featureSearch || '').trim().toLowerCase();
            if (!q) return true;
            return key.toLowerCase().includes(q) || label.toLowerCase().includes(q);
        },

        async saveUserRole(ev, userId) {
            const form = ev.target;
            const status = form.querySelector('.user-role-status');
            const fd = new FormData(form);
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]')?.value;
            if (status) status.textContent = 'Saving…';
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                const badge = document.querySelector(`.user-role-badge[data-user-id="${userId}"]`);
                if (badge) {
                    badge.textContent = data.role_label;
                    badge.style.background = data.role_color;
                }
                if (status) { status.textContent = 'Saved'; setTimeout(() => status.textContent = '', 1500); }
            } catch (e) {
                if (status) status.textContent = 'Failed';
            }
        },

        init() {
            window.addEventListener('beforeunload', (e) => {
                if (this.dirty) { e.preventDefault(); e.returnValue = ''; }
            });
            this.$watch('activeTab', () => this.syncUrl());
            this.$watch('selectedFeature', () => this.syncUrl());
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
                oversight_scope: data.oversight_scope || '',
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
