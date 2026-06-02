@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="{
        activeTab: ['company','branding','branches','syndication','api-access','admin'].includes((window.location.hash || '').replace('#','')) ? (window.location.hash || '').replace('#','') : 'company',
        isDemo: {{ old('is_demo') ? 'true' : 'false' }}
     }"
     x-init="$watch('activeTab', t => history.replaceState(null, '', '#' + t))">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">{{ $agency ? 'Edit Agency' : 'Create Agency' }}</h1>
        <p class="text-sm text-white/60">
            {{ $agency ? "Editing: {$agency->name}" : 'Add a new agency to the platform.' }}
        </p>
    </div>

    {{-- Flash --}}
    @if(session('success') || session('status'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') ?? session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Tab nav --}}
    @php
        $tabs = ['company' => 'Company', 'branding' => 'Branding'];
        if ($agency) { $tabs['branches'] = 'Branches'; }
        $tabs['syndication'] = 'Syndication';
        if ($agency && auth()->user()?->hasPermission('agency_api.view')) { $tabs['api-access'] = 'API Access'; }
        if (!$agency) { $tabs['admin'] = 'First Admin'; }
    @endphp
    <div class="flex gap-1 rounded-md p-1 flex-wrap" style="background: var(--surface); border:1px solid var(--border);">
        @foreach($tabs as $key => $label)
            <button type="button" @click="activeTab = '{{ $key }}'"
                    data-tab-btn="{{ $key }}"
                    class="flex-1 sm:flex-none px-4 py-2 text-sm font-medium rounded-md transition-colors"
                    :style="activeTab === '{{ $key }}'
                        ? 'background: var(--brand-button, #0ea5e9); color: #fff;'
                        : 'color: var(--text-secondary);'">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ============================================================
         MAIN AGENCY FORM (Company / Branding / Syndication / Admin tabs)
         ============================================================ --}}
    <form method="POST"
          id="agency-form"
          action="{{ $agency ? route('agencies.update', $agency) : route('agencies.store') }}"
          enctype="multipart/form-data"
          novalidate
          class="space-y-5">
        @csrf
        @if($agency) @method('PUT') @endif

        {{-- ── COMPANY TAB ── --}}
        <div x-show="activeTab === 'company'" x-cloak data-tab-panel="company" class="ds-status-card p-4 space-y-5">
            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Agency Identity</div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Agency Name <span style="color:var(--ds-crimson);">*</span></label>
                    <input type="text" name="name" value="{{ old('name', $agency?->name) }}" required
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. HFC Coastal">
                </div>
                @if(!$agency)
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Slug</label>
                    <input type="text" name="slug" value="{{ old('slug') }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="auto-generated if blank">
                </div>
                @endif
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Trading Name</label>
                    <input type="text" name="trading_name" value="{{ old('trading_name', $agency?->trading_name) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. Johan and Elize Properties T/A">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Tagline</label>
                    <input type="text" name="tagline" value="{{ old('tagline', $agency?->tagline) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. THE MANDATE COMPANY">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Registration No</label>
                    <input type="text" name="reg_no" value="{{ old('reg_no', $agency?->reg_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 2009/228978/23">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">VAT No</label>
                    <input type="text" name="vat_no" value="{{ old('vat_no', $agency?->vat_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 4870264498">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">FFC No</label>
                    <input type="text" name="ffc_no" value="{{ old('ffc_no', $agency?->ffc_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. FFC40/43916/5">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">FIC No</label>
                    <input type="text" name="fic_no" value="{{ old('fic_no', $agency?->fic_no) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 58538">
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Contact Details</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full rounded-md px-3 py-2 text-sm"
                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                              placeholder="Physical address">{{ old('address', $agency?->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $agency?->phone) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 071 351 0291">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Secondary Cell</label>
                    <input type="text" name="phone_secondary" value="{{ old('phone_secondary', $agency?->phone_secondary) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 079 495 5994">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Fax</label>
                    <input type="text" name="fax" value="{{ old('fax', $agency?->fax) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 086 233 2395">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Email</label>
                    <input type="text" name="email" value="{{ old('email', $agency?->email) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. admin@hfcoastal.co.za">
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Logo &amp; Status</div>
            <div x-data="{ removelogo: false }" class="space-y-4">
                @if($agency?->logo_path)
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="Current logo"
                             class="h-14 rounded-md p-1"
                             style="background: var(--surface-2); border: 1px solid var(--border);">
                        <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded" x-model="removelogo">
                            Remove current logo
                        </label>
                    </div>
                @endif
                <div x-show="!removelogo">
                    <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                           class="block w-full text-sm rounded-md px-3 py-2"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">JPG, PNG, or WebP — max 2 MB.</p>
                </div>

                <label class="flex items-center gap-3 pt-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           {{ old('is_active', $agency?->is_active ?? true) ? 'checked' : '' }}
                           class="w-4 h-4 rounded cursor-pointer"
                           style="accent-color:var(--brand-icon, #0ea5e9);">
                    <span class="text-sm font-medium cursor-pointer" style="color:var(--text-primary);">Agency is active</span>
                </label>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="corex-btn-primary">{{ $agency ? 'Update Agency' : 'Create Agency' }}</button>
            </div>
        </div>

        {{-- ── BRANDING TAB ── --}}
        <div x-show="activeTab === 'branding'" x-cloak data-tab-panel="branding" class="ds-status-card p-4 space-y-5">
            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Brand Colours</div>
            <p class="text-xs" style="color:var(--text-muted);">Four semantic colour roles control the entire platform look for this agency.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach([
                    ['key'=>'sidebar_color', 'label'=>'Sidebar', 'desc'=>'Sidebar hover &amp; active highlight', 'default'=>'#0ea5e9'],
                    ['key'=>'icon_color',    'label'=>'Icons',   'desc'=>'Icons, active states, links, accents', 'default'=>'#0ea5e9'],
                    ['key'=>'default_color', 'label'=>'Default', 'desc'=>'Profiles, headers, general branding',  'default'=>'#0b2a4a'],
                    ['key'=>'button_color',  'label'=>'Buttons', 'desc'=>'Primary buttons, CTAs',                'default'=>'#0ea5e9'],
                ] as $c)
                    @php $val = old($c['key'], $agency?->{$c['key']} ?? $c['default']); @endphp
                    <div class="rounded-md p-4 space-y-2" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-primary);">{!! $c['label'] !!}</div>
                        <div class="text-xs" style="color:var(--text-muted);">{!! $c['desc'] !!}</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="{{ $c['key'] }}" id="{{ $c['key'] }}_picker"
                                   value="{{ $val }}"
                                   class="h-9 w-14 rounded cursor-pointer p-0.5 flex-shrink-0"
                                   style="background:var(--surface); border:1px solid var(--border);">
                            <input type="text" id="{{ $c['key'] }}_text"
                                   value="{{ $val }}"
                                   class="flex-1 rounded-md px-2 py-1.5 text-xs font-mono outline-none"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   maxlength="7" placeholder="{{ $c['default'] }}">
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="rounded-md p-4" style="background:#0d0f14; color:#eef0f5; border:1px solid var(--border);">
                    <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#8890a4;">Dark Preview</div>
                    <div class="flex gap-2 mb-3">
                        <div class="rounded-md p-2 w-24 space-y-1" style="background:#13161d;">
                            <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Menu</div>
                            <div id="dark-sidebar-hover" class="rounded px-2 py-1 text-[10px] font-medium">Active</div>
                            <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Item</div>
                        </div>
                        <div class="flex-1 space-y-2">
                            <div id="dark-default-bar" class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white">Header</div>
                            <div id="dark-button-preview" class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white text-center">Button</div>
                            <div class="flex items-center gap-1.5">
                                <div id="dark-avatar" class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white">AB</div>
                                <span id="dark-icon-link" class="text-[10px] font-medium">Link text</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span id="dark-swatch-s" class="inline-block w-4 h-4 rounded-full border border-white/20"></span>
                        <span id="dark-swatch-i" class="inline-block w-4 h-4 rounded-full border border-white/20"></span>
                        <span id="dark-swatch-d" class="inline-block w-4 h-4 rounded-full border border-white/20"></span>
                        <span id="dark-swatch-b" class="inline-block w-4 h-4 rounded-full border border-white/20"></span>
                    </div>
                </div>
                <div class="rounded-md p-4" style="background:#f4f6fb; color:#111827; border:1px solid var(--border);">
                    <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#9ca3af;">Light Preview</div>
                    <div class="flex gap-2 mb-3">
                        <div class="rounded-md p-2 w-24 space-y-1" style="background:#ffffff; border:1px solid rgba(0,0,0,0.07);">
                            <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Menu</div>
                            <div id="light-sidebar-hover" class="rounded px-2 py-1 text-[10px] font-medium">Active</div>
                            <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Item</div>
                        </div>
                        <div class="flex-1 space-y-2">
                            <div id="light-default-bar" class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white">Header</div>
                            <div id="light-button-preview" class="rounded-md px-3 py-1.5 text-[10px] font-semibold text-white text-center">Button</div>
                            <div class="flex items-center gap-1.5">
                                <div id="light-avatar" class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white">AB</div>
                                <span id="light-icon-link" class="text-[10px] font-medium">Link text</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span id="light-swatch-s" class="inline-block w-4 h-4 rounded-full border border-slate-200"></span>
                        <span id="light-swatch-i" class="inline-block w-4 h-4 rounded-full border border-slate-200"></span>
                        <span id="light-swatch-d" class="inline-block w-4 h-4 rounded-full border border-slate-200"></span>
                        <span id="light-swatch-b" class="inline-block w-4 h-4 rounded-full border border-slate-200"></span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="corex-btn-primary">{{ $agency ? 'Update Agency' : 'Create Agency' }}</button>
            </div>
        </div>

        {{-- ── SYNDICATION TAB ── --}}
        <div x-show="activeTab === 'syndication'" x-cloak data-tab-panel="syndication" class="ds-status-card p-4 space-y-5">
            <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Property24 — Default Agency ID</div>
            <p class="text-xs" style="color:var(--text-muted);">Where this agency's listings are published on Property24. Each branch can override this default in the Branches tab.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Property24 Agency ID</label>
                    <input type="text" name="p24_agency_id" value="{{ old('p24_agency_id', $agency?->p24_agency_id) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 31357">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">The numeric ID P24 assigns to your agency profile. Leave blank if this agency does not syndicate to P24.</p>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Property24 Label</label>
                    <input type="text" name="p24_agency_label" value="{{ old('p24_agency_label', $agency?->p24_agency_label) }}"
                           class="w-full rounded-md px-3 py-2 text-sm"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. Home Finders Coastal — HFC1">
                    <p class="text-xs mt-1" style="color:var(--text-muted);">Human-readable label shown in the admin UI only. Not sent to Property24.</p>
                </div>
            </div>

            <div class="text-xs font-bold uppercase tracking-wider pb-1 pt-3" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Advanced AI Features</div>
            <p class="text-xs" style="color:var(--text-muted);">Opt-in to advanced AI capabilities. These are billable mobile-app features — only enable for agencies on the relevant plan.</p>

            <label class="flex items-start gap-3 p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                <input type="hidden" name="ai_voice_enabled" value="0">
                <input type="checkbox" name="ai_voice_enabled" value="1"
                       {{ old('ai_voice_enabled', $agency->ai_voice_enabled ?? false) ? 'checked' : '' }}
                       class="w-4 h-4 mt-0.5 rounded cursor-pointer"
                       style="accent-color:var(--brand-icon, #0ea5e9);">
                <span class="text-sm" style="color:var(--text-primary);">
                    <span class="font-medium">Ellie Voice Commands</span>
                    <span class="block text-xs mt-0.5" style="color:var(--text-muted);">Push-to-talk voice input on mobile. "Hey Ellie, schedule a viewing at 11" creates a calendar entry, tagged as AI-created.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                <input type="hidden" name="ai_image_recognition_enabled" value="0">
                <input type="checkbox" name="ai_image_recognition_enabled" value="1"
                       {{ old('ai_image_recognition_enabled', $agency->ai_image_recognition_enabled ?? false) ? 'checked' : '' }}
                       class="w-4 h-4 mt-0.5 rounded cursor-pointer"
                       style="accent-color:var(--brand-icon, #0ea5e9);">
                <span class="text-sm" style="color:var(--text-primary);">
                    <span class="font-medium">AI Property Image Recognition</span>
                    <span class="block text-xs mt-0.5" style="color:var(--text-muted);">Mobile-only. When agents upload property photos, the system detects features (pool, sea view, garden, etc.) and pre-ticks the feature checklist for confirmation.</span>
                </span>
            </label>

            @if($agency)
            <div class="text-xs font-bold uppercase tracking-wider pb-1 pt-3" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Property24 API Credentials</div>
            <p class="text-xs" style="color:var(--text-muted);">Used to authenticate against the Property24 Listing Service. Leave blank to use the global default. Saving changed credentials triggers an auto-sync.</p>

            <label class="flex items-center gap-3">
                <input type="hidden" name="p24_enabled" value="0">
                <input type="checkbox" name="p24_enabled" value="1"
                       {{ old('p24_enabled', $agency->p24_enabled ?? false) ? 'checked' : '' }}
                       class="w-4 h-4 rounded cursor-pointer"
                       style="accent-color:var(--brand-icon, #0ea5e9);">
                <span class="text-sm font-medium" style="color:var(--text-primary);">Enable Property24 integration for this agency</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Username</label>
                    <input type="text" name="p24_username" value="{{ old('p24_username', $agency->p24_username) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 31357@hfcoastal.co.za" autocomplete="off">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Password</label>
                    <input type="password" name="p24_password"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="{{ $agency->p24_password ? '•••••••• (leave blank to keep)' : 'Enter P24 password' }}"
                           autocomplete="new-password">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">User Group ID</label>
                    <input type="text" name="p24_user_group_id" value="{{ old('p24_user_group_id', $agency->p24_user_group_id) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="optional — sent as P24-UserGroupId header">
                </div>
            </div>

            <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);"
                 x-data="p24Actions({ testUrl: '{{ route('agencies.p24.test', $agency) }}', refreshUrl: '{{ route('agencies.p24.refresh', $agency) }}', csrf: '{{ csrf_token() }}' })">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-xs space-y-1">
                        @if($agency->p24_locations_synced_at)
                            <div style="color:var(--text-secondary);"><span class="font-semibold">Last synced:</span> {{ $agency->p24_locations_synced_at->diffForHumans() }} ({{ $agency->p24_locations_synced_at->format('Y-m-d H:i') }})</div>
                        @else
                            <div style="color:var(--text-muted);" class="italic">Never synced.</div>
                        @endif
                        @if($agency->p24_last_sync_error)
                            <div class="break-all" style="color:var(--ds-crimson);"><span class="font-semibold">Last error:</span> {{ Str::limit($agency->p24_last_sync_error, 300) }}</div>
                        @endif
                        <template x-if="message">
                            <div :class="ok ? 'font-medium' : 'font-medium'"
                                 :style="ok ? 'color:var(--ds-green);' : 'color:var(--ds-crimson);'"
                                 x-text="message"></div>
                        </template>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="test()" :disabled="busy"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold disabled:opacity-60"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <span x-text="busy && action==='test' ? 'Testing…' : 'Test Connection'"></span>
                        </button>
                        <button type="button" @click="refresh()" :disabled="busy"
                                class="corex-btn-primary text-xs disabled:opacity-60">
                            <span x-text="busy && action==='refresh' ? 'Refreshing…' : 'Refresh Locations'"></span>
                        </button>
                    </div>
                </div>
            </div>
            @endif

            @if($agency)
            {{-- ── Private Property credentials ── --}}
            <div class="text-xs font-bold uppercase tracking-wider pb-1 pt-5" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Private Property — Credentials</div>
            <p class="text-xs" style="color:var(--text-muted);">Per-agency PP SOAP credentials. Leave blank to fall back to the global <code>.env</code> defaults. Stored encrypted.</p>

            <label class="flex items-center gap-3">
                <input type="hidden" name="pp_enabled" value="0">
                <input type="checkbox" name="pp_enabled" value="1"
                       {{ old('pp_enabled', $agency->pp_enabled ?? false) ? 'checked' : '' }}
                       class="w-4 h-4 rounded cursor-pointer"
                       style="accent-color:var(--brand-icon, #0ea5e9);">
                <span class="text-sm font-medium" style="color:var(--text-primary);">Enable Private Property integration for this agency</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Username</label>
                    <input type="text" name="pp_username" value="{{ old('pp_username', $agency->pp_username) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. agency@hfcoastal.co.za" autocomplete="off">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Password</label>
                    <input type="password" name="pp_password"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="{{ $agency->pp_password ? '•••••••• (leave blank to keep)' : 'Enter PP password' }}"
                           autocomplete="new-password">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Branch GUID</label>
                    <input type="text" name="pp_branch_guid" value="{{ old('pp_branch_guid', $agency->pp_branch_guid) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="e.g. 6f0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">WSDL URL</label>
                    <input type="text" name="pp_wsdl" value="{{ old('pp_wsdl', $agency->pp_wsdl) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="{{ config('services.private_property.wsdl') }}">
                </div>
                <div class="flex items-center pt-5">
                    <label class="flex items-center gap-3">
                        <input type="hidden" name="pp_sandbox" value="0">
                        <input type="checkbox" name="pp_sandbox" value="1"
                               {{ old('pp_sandbox', $agency->pp_sandbox ?? true) ? 'checked' : '' }}
                               class="w-4 h-4 rounded cursor-pointer"
                               style="accent-color:var(--brand-icon, #0ea5e9);">
                        <span class="text-sm font-medium" style="color:var(--text-primary);">Sandbox mode</span>
                    </label>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Image Base URL</label>
                    <input type="text" name="pp_image_base_url" value="{{ old('pp_image_base_url', $agency->pp_image_base_url) }}"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="optional — overrides APP_URL for image hosts">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Webhook Secret</label>
                    <input type="password" name="pp_webhook_secret"
                           class="w-full rounded-md px-3 py-2 text-sm font-mono"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="{{ $agency->pp_webhook_secret ? '•••••••• (leave blank to keep)' : 'HMAC secret registered in PP Admin Portal' }}"
                           autocomplete="new-password">
                </div>
            </div>

            <div class="rounded-md p-4" style="background:var(--surface-2); border:1px solid var(--border);"
                 x-data="ppActions({ testUrl: '{{ route('agencies.pp.test', $agency) }}', csrf: '{{ csrf_token() }}' })">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-xs space-y-1">
                        @if($agency->pp_last_sync_error)
                            <div class="break-all" style="color:var(--ds-crimson);"><span class="font-semibold">Last error:</span> {{ Str::limit($agency->pp_last_sync_error, 300) }}</div>
                        @endif
                        <template x-if="message">
                            <div :style="ok ? 'color:var(--ds-green);' : 'color:var(--ds-crimson);'"
                                 class="font-medium" x-text="message"></div>
                        </template>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="test()" :disabled="busy"
                                class="px-3 py-1.5 rounded-md text-xs font-semibold disabled:opacity-60"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <span x-text="busy ? 'Testing…' : 'Test Connection'"></span>
                        </button>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex justify-end pt-1">
                <button type="submit" class="corex-btn-primary">{{ $agency ? 'Update Agency' : 'Create Agency' }}</button>
            </div>
        </div>

        {{-- ── FIRST ADMIN TAB (create only) ── --}}
        @if(!$agency)
        <div x-show="activeTab === 'admin'" x-cloak data-tab-panel="admin" class="ds-status-card p-4 space-y-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="is_demo" value="1" x-model="isDemo" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="block text-sm font-semibold" style="color:var(--text-primary);">Demo agency</span>
                    <span class="block text-xs mt-0.5" style="color:var(--text-muted);">For showcasing, training, or sales demos. No first Admin required — the agency is created empty.</span>
                </span>
            </label>

            <div x-show="!isDemo" x-cloak class="space-y-4 pt-2" style="border-top: 1px solid var(--border);">
                <div>
                    <h3 class="text-sm font-bold pt-3" style="color:var(--text-primary);">First Admin <span style="color:var(--ds-crimson);">*</span></h3>
                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Every live agency must have at least one Admin. They are created together — this user becomes the agency's first Admin and gets full permissions.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Full name <span style="color:var(--ds-crimson);">*</span></label>
                        <input type="text" name="admin_name" value="{{ old('admin_name') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. Jane Smith" :required="!isDemo" :disabled="isDemo">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Email <span style="color:var(--ds-crimson);">*</span></label>
                        <input type="email" name="admin_email" value="{{ old('admin_email') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="admin@agency.co.za" :required="!isDemo" :disabled="isDemo">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Password <span style="color:var(--ds-crimson);">*</span></label>
                        <input type="password" name="admin_password"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="min 8 characters" minlength="8" :required="!isDemo" :disabled="isDemo">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Mobile</label>
                        <input type="text" name="admin_cell" value="{{ old('admin_cell') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="optional">
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="corex-btn-primary">Create Agency</button>
            </div>
        </div>
        @endif
    </form>

    {{-- ============================================================
         API ACCESS TAB (edit mode only — website API keys + master switch)
         Spec: .ai/specs/agency-public-api.md §7.1
         ============================================================ --}}
    @if($agency && auth()->user()?->hasPermission('agency_api.view'))
    <div x-show="activeTab === 'api-access'" x-cloak id="api-access" class="space-y-6">

        {{-- One-time secret reveal (after create / regenerate) --}}
        @if(session('new_api_key'))
            <div class="ds-status-card p-4 space-y-2" style="border-color: var(--brand-button, #0ea5e9);">
                <h3 class="ds-section-header">Copy your API key now</h3>
                <p class="text-xs" style="color:var(--ds-crimson);">This secret is shown only once. Store it in “{{ session('new_api_key')['name'] }}”’s environment config — you will not be able to see it again.</p>
                <div class="flex items-center gap-2">
                    <input type="text" readonly value="{{ session('new_api_key')['plaintext'] }}" id="new-key-secret"
                           class="flex-1 rounded-md px-3 py-2 text-sm font-mono"
                           style="background: var(--surface); border:1px solid var(--border); color: var(--text-primary);">
                    <button type="button" class="corex-btn-outline text-xs"
                            onclick="navigator.clipboard.writeText(document.getElementById('new-key-secret').value); this.innerText='Copied ✓'; setTimeout(()=>this.innerText='Copy',1500);">Copy</button>
                </div>
            </div>
        @endif

        {{-- Master "website is live" switch --}}
        <div class="ds-status-card p-4 space-y-3">
            <h3 class="ds-section-header">Website</h3>
            <p class="text-xs" style="color:var(--text-muted);">Master switch for this agency's website integration. When off, no website receives data and no webhooks fire — even with valid, active keys.</p>
            @if(auth()->user()?->hasPermission('agency_api.manage'))
            <form method="POST" action="{{ route('agencies.website.toggle', $agency) }}" class="flex items-center gap-3">
                @csrf
                <input type="hidden" name="website_enabled" value="{{ $agency->website_enabled ? 0 : 1 }}">
                <button type="submit" class="corex-btn-{{ $agency->website_enabled ? 'outline' : 'primary' }} text-sm">
                    {{ $agency->website_enabled ? 'Take website offline' : 'Make website live' }}
                </button>
                <span class="text-xs font-semibold px-2 py-1 rounded-md"
                      style="background: var(--surface-2); color: {{ $agency->website_enabled ? 'var(--ds-emerald, #10b981)' : 'var(--text-muted)' }};">
                    {{ $agency->website_enabled ? 'LIVE' : 'OFFLINE' }}
                </span>
            </form>
            @else
                <span class="text-xs font-semibold">{{ $agency->website_enabled ? 'LIVE' : 'OFFLINE' }}</span>
            @endif
        </div>

        {{-- Existing keys --}}
        <div class="ds-status-card p-4 space-y-4">
            <h3 class="ds-section-header">Websites / API keys</h3>
            <p class="text-xs" style="color:var(--text-muted);">Each website gets its own key. The key's name is the label shown as its Syndication Portal on every property.</p>

            @forelse($agency->apiKeys as $key)
                <div class="rounded-md p-3 space-y-2" style="background: var(--surface); border:1px solid var(--border);" x-data="{ editing: false, showSecret: false }">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $key->name }}</div>
                            <div class="text-xs font-mono" style="color:var(--text-muted);">{{ $key->key_prefix }}…</div>
                        </div>
                        <span class="text-xs font-semibold px-2 py-1 rounded-md" style="background: var(--surface-2);
                              color: {{ $key->statusLabel() === 'active' ? 'var(--ds-emerald,#10b981)' : 'var(--ds-crimson)' }};">
                            {{ strtoupper($key->statusLabel()) }}
                        </span>
                    </div>
                    <div class="text-xs" style="color:var(--text-muted);">
                        Scopes: {{ count($key->scopes ?? []) ? implode(', ', $key->scopes) : '—' }}
                        · Last used: {{ $key->last_used_at ? $key->last_used_at->diffForHumans() : 'never' }}
                        @if($key->webhook_url) · Webhook: {{ \Illuminate\Support\Str::limit($key->webhook_url, 40) }} @endif
                    </div>
                    @if(auth()->user()?->hasPermission('agency_api.manage'))
                    {{-- Webhook signing secret — needed for COREX_WEBHOOK_SECRET on the website to verify X-CoreX-Signature --}}
                    @if($key->webhook_secret && in_array(\App\Models\AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE, $key->scopes ?? [], true))
                    <div class="text-xs" style="color:var(--text-muted);">
                        <button type="button" @click="showSecret = !showSecret" class="underline" x-text="showSecret ? 'Hide webhook secret' : 'Reveal webhook signing secret'"></button>
                        <div x-show="showSecret" x-cloak class="flex items-center gap-2 mt-1">
                            <input type="text" readonly value="{{ $key->webhook_secret }}" id="whsec-{{ $key->id }}"
                                   class="flex-1 rounded-md px-2 py-1 text-xs font-mono"
                                   style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                            <button type="button" class="corex-btn-outline text-xs"
                                    onclick="navigator.clipboard.writeText(document.getElementById('whsec-{{ $key->id }}').value); this.innerText='Copied ✓'; setTimeout(()=>this.innerText='Copy',1200);">Copy</button>
                        </div>
                        <p x-show="showSecret" x-cloak class="mt-0.5" style="color:var(--text-muted);">Put this in the website's <span class="font-mono">COREX_WEBHOOK_SECRET</span>.</p>
                    </div>
                    @endif
                    <div class="flex gap-2 flex-wrap pt-1">
                        <button type="button" @click="editing = !editing" class="corex-btn-outline text-xs" x-text="editing ? 'Close' : 'Edit scopes / webhook'"></button>
                        <form method="POST" action="{{ route('agencies.api-keys.regenerate', [$agency, $key]) }}" onsubmit="return confirm('Regenerate the secret? The old secret stops working immediately.');">
                            @csrf
                            <button class="corex-btn-outline text-xs">Regenerate secret</button>
                        </form>
                        @if(!$key->isRevoked())
                        <form method="POST" action="{{ route('agencies.api-keys.revoke', [$agency, $key]) }}" onsubmit="return confirm('Revoke this key? The website will stop receiving data until you regenerate.');">
                            @csrf
                            <button class="corex-btn-outline text-xs" style="color:var(--ds-crimson);">Revoke</button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('agencies.api-keys.destroy', [$agency, $key]) }}" onsubmit="return confirm('Delete this key? It is archived (soft-deleted) and recoverable by an admin.');">
                            @csrf @method('DELETE')
                            <button class="corex-btn-outline text-xs" style="color:var(--ds-crimson);">Delete</button>
                        </form>
                    </div>

                    {{-- Inline edit: change scopes / webhook on an existing key (no new key needed) --}}
                    <form x-show="editing" x-cloak method="POST" action="{{ route('agencies.api-keys.update', [$agency, $key]) }}"
                          class="space-y-3 pt-2 mt-1" style="border-top:1px solid var(--border);">
                        @csrf @method('PUT')
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Website name</label>
                            <input type="text" name="name" required maxlength="100" value="{{ $key->name }}"
                                   class="rounded-md px-3 py-2 text-sm w-full max-w-md"
                                   style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Scopes</label>
                            <div class="flex flex-col gap-1">
                                @foreach(\App\Models\AgencyApiKey::SCOPES as $scopeKey => $scopeLabel)
                                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                        <input type="checkbox" name="scopes[]" value="{{ $scopeKey }}"
                                               {{ in_array($scopeKey, $key->scopes ?? [], true) ? 'checked' : '' }}>
                                        {{ $scopeLabel }} <span class="text-xs font-mono" style="color:var(--text-muted);">{{ $scopeKey }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Webhook URL (optional)</label>
                            <input type="url" name="webhook_url" value="{{ $key->webhook_url }}"
                                   class="rounded-md px-3 py-2 text-sm w-full max-w-md"
                                   style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);"
                                   placeholder="https://your-site.co.za/api/corex-webhook">
                        </div>
                        <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
                    </form>
                    @endif
                </div>
            @empty
                <p class="text-xs" style="color:var(--text-muted);">No API keys yet. Generate one below to connect a website.</p>
            @endforelse
        </div>

        {{-- Generate a new key --}}
        @if(auth()->user()?->hasPermission('agency_api.manage'))
        <div class="ds-status-card p-4 space-y-4">
            <h3 class="ds-section-header">Generate API key</h3>
            <form method="POST" action="{{ route('agencies.api-keys.store', $agency) }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">What is this website called? <span style="color:var(--ds-crimson);">*</span></label>
                    <input type="text" name="name" required maxlength="100" value="{{ old('name') }}"
                           class="rounded-md px-3 py-2 text-sm w-full max-w-md"
                           style="background: var(--surface); border:1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Home Finders Coastal">
                    @error('name')<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Scopes</label>
                    <div class="flex flex-col gap-1">
                        @foreach(\App\Models\AgencyApiKey::SCOPES as $scopeKey => $scopeLabel)
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                <input type="checkbox" name="scopes[]" value="{{ $scopeKey }}"
                                       {{ collect(old('scopes', ['listings:read','agency:read']))->contains($scopeKey) ? 'checked' : '' }}>
                                {{ $scopeLabel }} <span class="text-xs font-mono" style="color:var(--text-muted);">{{ $scopeKey }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Webhook URL (optional)</label>
                    <input type="url" name="webhook_url" value="{{ old('webhook_url') }}"
                           class="rounded-md px-3 py-2 text-sm w-full max-w-md"
                           style="background: var(--surface); border:1px solid var(--border); color: var(--text-primary);"
                           placeholder="https://your-site.co.za/api/corex-webhook">
                    @error('webhook_url')<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="corex-btn-primary text-sm">Generate API Key</button>
            </form>
        </div>
        @endif

        {{-- Connecting a website — setup & operations note --}}
        <div class="ds-status-card p-4 space-y-3" x-data="{ open: false }">
            <button type="button" @click="open = !open" class="flex items-center justify-between w-full text-left">
                <h3 class="ds-section-header" style="margin:0;">Connecting a website — setup &amp; operations</h3>
                <span class="text-xs" style="color:var(--text-muted);" x-text="open ? 'Hide' : 'Show'"></span>
            </button>
            <div x-show="open" x-cloak class="space-y-3 text-xs" style="color:var(--text-secondary);">
                <div>
                    <p class="font-semibold" style="color:var(--text-primary);">1. On the website you're building (per site)</p>
                    <ul class="list-disc pl-4 space-y-0.5 mt-1">
                        <li>Put the key in <span class="font-mono">that site's</span> environment — <span class="font-mono">CONFIG/.env</span> of the website, never in CoreX:
                            <div class="mt-1 font-mono rounded-md p-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                COREX_API_BASE={{ rtrim(config('app.url'), '/') }}/api/v1/website<br>
                                COREX_API_KEY=cx_live_…&lt;the secret shown once&gt;<br>
                                COREX_WEBHOOK_SECRET=&lt;shown when you set a webhook URL&gt;
                            </div>
                        </li>
                        <li>Call the API with header <span class="font-mono">Authorization: Bearer &lt;COREX_API_KEY&gt;</span>.</li>
                        <li>Verify webhooks with <span class="font-mono">X-CoreX-Signature</span> = HMAC-SHA256(raw body, COREX_WEBHOOK_SECRET).</li>
                    </ul>
                </div>
                <div>
                    <p class="font-semibold" style="color:var(--text-primary);">2. On the CoreX server (one-time, whole platform — already running here)</p>
                    <ul class="list-disc pl-4 space-y-0.5 mt-1">
                        <li><span class="font-mono">.env</span>: <span class="font-mono">QUEUE_CONNECTION=database</span> (so webhooks queue instead of blocking requests). No per-website keys go in CoreX's .env.</li>
                        <li>Scheduler cron (drives webhook retries):<br>
                            <span class="font-mono">* * * * * cd /hfc &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</span></li>
                        <li>A queue worker delivers the webhooks (systemd/supervisor):<br>
                            <span class="font-mono">php artisan queue:work --queue=default --tries=1</span></li>
                    </ul>
                    <p class="mt-1" style="color:var(--text-muted);">The master switch above must be ON, and a key needs the “Receive webhook events” scope + a webhook URL, for pushes to fire.</p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ============================================================
         BRANCHES TAB (edit mode only — separate forms per branch)
         ============================================================ --}}
    @if($agency)
    <div x-show="activeTab === 'branches'" x-cloak class="space-y-6">

        {{-- Add branch --}}
        <div class="ds-status-card p-4 space-y-4">
            <h3 class="ds-section-header">Add Branch</h3>
            <p class="text-xs" style="color:var(--text-muted);">Branches added here also appear in Company Settings → Branches for this agency.</p>

            <form method="POST" action="{{ route('admin.branches.store') }}" class="flex flex-wrap gap-3 items-end">
                @csrf
                <input type="hidden" name="agency_id" value="{{ $agency->id }}">
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                    <input class="rounded-md px-3 py-2 text-sm" name="name" required
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Seabreeze Bay">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Code</label>
                    <input class="rounded-md px-3 py-2 text-sm" name="code" required
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. SBB">
                </div>
                <button type="submit" class="corex-btn-primary text-sm">Add Branch</button>
            </form>
        </div>

        {{-- Existing branches (collapsed by default) --}}
        <div class="ds-status-card p-4 space-y-4">
            <div>
                <h3 class="ds-section-header">Existing Branches ({{ $branches->count() }})</h3>
                <div class="text-xs mt-1" style="color: var(--text-muted);">Click a branch to expand. Leave overrides blank to inherit from agency settings.</div>
            </div>

            @forelse($branches as $branch)
                @php $parentP24 = $agency->p24_agency_id; @endphp
                <form method="POST" action="{{ route('admin.branch-settings.update', $branch) }}"
                      enctype="multipart/form-data"
                      class="rounded-md p-4 space-y-4"
                      style="background: var(--surface-2); border: 1px solid var(--border);"
                      x-data="{ removelogo: false, open: false }">
                    @csrf

                    <div class="flex items-center justify-between gap-4 cursor-pointer select-none" @click="open = !open">
                        <div class="flex items-center gap-2 font-semibold" style="color: var(--text-primary);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform"
                                 :class="open ? 'rotate-90' : ''"
                                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                            {{ $branch->name }} <span style="color: var(--text-muted);">({{ $branch->code }})</span>
                        </div>
                        <div class="flex items-center gap-3" @click.stop>
                            <button type="submit" x-show="open" class="corex-btn-primary text-sm">Save</button>
                        </div>
                    </div>

                    <div x-show="open" x-cloak class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Trading Name Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="trading_name" value="{{ old('trading_name', $branch->trading_name) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Tagline Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="tagline" value="{{ old('tagline', $branch->tagline) }}">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Address Override</label>
                            <textarea name="address" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ old('address', $branch->address) }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="phone" value="{{ old('phone', $branch->phone) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Secondary Cell Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="phone_secondary" value="{{ old('phone_secondary', $branch->phone_secondary) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Fax</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="fax" value="{{ old('fax', $branch->fax) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="email" value="{{ old('email', $branch->email) }}">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Registration No Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="reg_no" value="{{ old('reg_no', $branch->reg_no) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">VAT No Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="vat_no" value="{{ old('vat_no', $branch->vat_no) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FFC No Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="ffc_no" value="{{ old('ffc_no', $branch->ffc_no) }}">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FIC No Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="fic_no" value="{{ old('fic_no', $branch->fic_no) }}">
                            </div>
                        </div>

                        {{-- Property24 Agency ID Override (moved here from Company Settings) --}}
                        <div class="pt-4" style="border-top: 1px solid var(--border);">
                            <div class="text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-secondary);">Property24 Syndication</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">P24 Agency ID Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm font-mono"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="p24_agency_id" value="{{ old('p24_agency_id', $branch->p24_agency_id) }}"
                                           placeholder="{{ $parentP24 ? 'inherits ' . $parentP24 : 'e.g. 12345' }}">
                                    <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                        @if($parentP24)
                                            Leave blank to use agency default: <span class="font-mono">{{ $parentP24 }}</span>.
                                        @else
                                            Agency has no default set. Enter this branch's P24 agency ID.
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Logo --}}
                        <div class="pt-4 space-y-3" style="border-top: 1px solid var(--border);">
                            <div class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);">Branch Logo</div>
                            <p class="text-xs" style="color: var(--text-muted);">JPG, PNG, or WebP — max 2 MB. Leave blank to inherit Agency logo.</p>

                            @if($branch->logo_path)
                                <div class="flex items-center gap-4">
                                    <img src="{{ asset('storage/' . $branch->logo_path) }}" alt="Branch logo"
                                         class="h-14 rounded-md p-1"
                                         style="background: var(--surface); border: 1px solid var(--border);">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" name="remove_logo" value="1" id="remove_logo_{{ $branch->id }}" x-model="removelogo"
                                               class="w-4 h-4 rounded cursor-pointer">
                                        <label for="remove_logo_{{ $branch->id }}" class="text-xs cursor-pointer" style="color: var(--text-secondary);">Remove current logo</label>
                                    </div>
                                </div>
                            @endif

                            <div x-show="!removelogo">
                                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                                       class="w-full text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:text-white file:cursor-pointer"
                                       style="color: var(--text-secondary);">
                            </div>
                        </div>

                        {{-- Delete --}}
                        <div class="pt-4 flex justify-end" style="border-top: 1px solid var(--border);">
                            <button type="button"
                                    onclick="if(confirm('Delete branch &quot;{{ $branch->name }}&quot;? This cannot be undone.')) { document.getElementById('delete-branch-{{ $branch->id }}').submit(); }"
                                    class="text-xs font-semibold" style="color: var(--ds-crimson);">
                                Delete this branch
                            </button>
                        </div>
                    </div>
                </form>

                <form id="delete-branch-{{ $branch->id }}" method="POST" action="{{ route('admin.branches.delete', $branch) }}" class="hidden">
                    @csrf
                </form>
            @empty
                <div class="rounded-md py-8 px-6 text-center text-sm" style="background: var(--surface-2); color: var(--text-muted);">
                    No branches yet. Add the first one above.
                </div>
            @endforelse
        </div>
    </div>
    @endif

    <div>
        @php $cancelUrl = auth()->user()?->isOwnerRole() ? route('agencies.index') : route('admin.company-settings'); @endphp
        <a href="{{ $cancelUrl }}" class="text-sm font-medium" style="color: var(--text-secondary);">← Back</a>
    </div>

</div>

<script>
function p24Actions(cfg) {
    return {
        busy: false, action: null, message: '', ok: false,
        async _post(url) {
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
            });
            let body = {};
            try { body = await r.json(); } catch (e) {}
            return { ok: r.ok && body.success, msg: body.message || ('HTTP ' + r.status) };
        },
        async test() {
            this.busy = true; this.action = 'test'; this.message = '';
            const { ok, msg } = await this._post(cfg.testUrl);
            this.ok = ok; this.message = msg; this.busy = false; this.action = null;
        },
        async refresh() {
            this.busy = true; this.action = 'refresh'; this.message = '';
            const { ok, msg } = await this._post(cfg.refreshUrl);
            this.ok = ok; this.message = msg; this.busy = false; this.action = null;
        },
    };
}

function ppActions(cfg) {
    return {
        busy: false, message: '', ok: false,
        async test() {
            this.busy = true; this.message = '';
            const r = await fetch(cfg.testUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
            });
            let body = {};
            try { body = await r.json(); } catch (e) {}
            this.ok = r.ok && body.success;
            this.message = body.message || ('HTTP ' + r.status);
            this.busy = false;
        },
    };
}

document.addEventListener('DOMContentLoaded', function () {
    function hexToRgba(hex, pct) {
        if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return 'transparent';
        const r = parseInt(hex.slice(1,3), 16);
        const g = parseInt(hex.slice(3,5), 16);
        const b = parseInt(hex.slice(5,7), 16);
        return `rgba(${r},${g},${b},${pct})`;
    }
    function syncPair(pickerId, textId) {
        const picker = document.getElementById(pickerId);
        const text   = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', () => { text.value = picker.value; updatePreviews(); });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                picker.value = text.value;
                updatePreviews();
            }
        });
    }
    function setStyle(id, prop, val) { const el = document.getElementById(id); if (el) el.style[prop] = val; }
    function updatePreviews() {
        const sb = document.getElementById('sidebar_color_picker'); if (!sb) return;
        const sidebar = sb.value;
        const icon    = document.getElementById('icon_color_picker').value;
        const def     = document.getElementById('default_color_picker').value;
        const button  = document.getElementById('button_color_picker').value;

        ['dark','light'].forEach(theme => {
            const sh = document.getElementById(`${theme}-sidebar-hover`);
            if (sh) { sh.style.background = hexToRgba(sidebar, 0.12); sh.style.color = sidebar; }
            setStyle(`${theme}-default-bar`, 'background', def);
            setStyle(`${theme}-button-preview`, 'background', button);
            setStyle(`${theme}-avatar`, 'background', def);
            setStyle(`${theme}-icon-link`, 'color', icon);
            setStyle(`${theme}-swatch-s`, 'background', sidebar);
            setStyle(`${theme}-swatch-i`, 'background', icon);
            setStyle(`${theme}-swatch-d`, 'background', def);
            setStyle(`${theme}-swatch-b`, 'background', button);
        });
    }
    syncPair('sidebar_color_picker', 'sidebar_color_text');
    syncPair('icon_color_picker',    'icon_color_text');
    syncPair('default_color_picker', 'default_color_text');
    syncPair('button_color_picker',  'button_color_text');
    updatePreviews();
});

// ── Cross-tab validation guard ──────────────────────────────────────────────
// The agency form is split across tabbed panels (Company / Branding /
// Syndication / First Admin). A native `required` field on a hidden
// (display:none) tab can't be focused, so the browser silently aborts the
// submit with no feedback — e.g. ticking "Demo agency" on the Admin tab and
// hitting Create while the required Name field on the Company tab is empty made
// the button appear to do nothing. The form carries `novalidate`, so the submit
// event always fires; here we find the first invalid control, switch to its
// tab, and surface the native message on the now-visible field.
document.addEventListener('DOMContentLoaded', function () {
    var agencyForm = document.getElementById('agency-form');
    if (!agencyForm) return;

    function switchToTab(tabKey) {
        var btns = document.querySelectorAll('[data-tab-btn]');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-tab-btn') === tabKey) { btns[i].click(); return; }
        }
    }

    agencyForm.addEventListener('submit', function (e) {
        var controls = agencyForm.querySelectorAll('input, select, textarea');
        var firstInvalid = null;
        for (var i = 0; i < controls.length; i++) {
            var c = controls[i];
            if (c.disabled || c.type === 'hidden') continue;
            if (typeof c.checkValidity === 'function' && !c.checkValidity()) { firstInvalid = c; break; }
        }
        if (firstInvalid) {
            e.preventDefault();
            var panel = firstInvalid.closest('[data-tab-panel]');
            if (panel) switchToTab(panel.getAttribute('data-tab-panel'));
            // Let Alpine reveal the tab before asking the browser to report.
            setTimeout(function () {
                try { firstInvalid.reportValidity(); firstInvalid.focus(); } catch (err) {}
            }, 60);
        }
    });
});
</script>
@endsection
