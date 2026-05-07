@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:var(--brand-default, #0b2a4a);">
        <h2 class="text-xl font-bold text-white">{{ $agency ? 'Edit Agency' : 'Create Agency' }}</h2>
        <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">
            {{ $agency ? "Editing: {$agency->name}" : 'Add a new agency to the platform.' }}
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $agency ? route('agencies.update', $agency) : route('agencies.store') }}"
          enctype="multipart/form-data"
          class="space-y-5"
          x-data="{ isDemo: {{ old('is_demo') ? 'true' : 'false' }} }">
        @csrf
        @if($agency)
            @method('PUT')
        @endif

        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6">

            {{-- Name --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Agency Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $agency?->name) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                       placeholder="e.g. HFC Coastal" required>
            </div>

            {{-- Slug (create only) --}}
            @if(!$agency)
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Slug</label>
                <input type="text" name="slug" value="{{ old('slug') }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none"
                       placeholder="auto-generated if blank">
                <p class="text-xs text-slate-400 mt-1">Used in URLs. Must be unique. Leave blank to auto-generate from name.</p>
            </div>
            @endif

            {{-- Brand Colours — 4 semantic roles --}}
            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Brand Colours</label>
                <p class="text-xs text-slate-400 mb-3">Four semantic colour roles control the entire platform look for this agency.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Sidebar --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Sidebar</div>
                        <div class="text-xs text-slate-400">Sidebar hover & active highlight</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="sidebar_color" id="sidebar_color_picker"
                                   value="{{ old('sidebar_color', $agency?->sidebar_color ?? '#0ea5e9') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="sidebar_color_text"
                                   value="{{ old('sidebar_color', $agency?->sidebar_color ?? '#0ea5e9') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#0ea5e9">
                        </div>
                    </div>

                    {{-- Icons --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Icons</div>
                        <div class="text-xs text-slate-400">Icons, active states, links, accents</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="icon_color" id="icon_color_picker"
                                   value="{{ old('icon_color', $agency?->icon_color ?? '#0ea5e9') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="icon_color_text"
                                   value="{{ old('icon_color', $agency?->icon_color ?? '#0ea5e9') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#0ea5e9">
                        </div>
                    </div>

                    {{-- Default --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Default</div>
                        <div class="text-xs text-slate-400">Profiles, headers, general branding</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="default_color" id="default_color_picker"
                                   value="{{ old('default_color', $agency?->default_color ?? '#0b2a4a') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="default_color_text"
                                   value="{{ old('default_color', $agency?->default_color ?? '#0b2a4a') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#0b2a4a">
                        </div>
                    </div>

                    {{-- Buttons --}}
                    <div class="rounded-xl border border-slate-200 p-4 space-y-2">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Buttons</div>
                        <div class="text-xs text-slate-400">Primary buttons, CTAs</div>
                        <div class="flex items-center gap-2 mt-2">
                            <input type="color" name="button_color" id="button_color_picker"
                                   value="{{ old('button_color', $agency?->button_color ?? '#0ea5e9') }}"
                                   class="h-9 w-14 rounded border border-slate-300 cursor-pointer p-0.5 flex-shrink-0">
                            <input type="text" id="button_color_text"
                                   value="{{ old('button_color', $agency?->button_color ?? '#0ea5e9') }}"
                                   class="flex-1 rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-mono focus:outline-none"
                                   maxlength="7" placeholder="#0ea5e9">
                        </div>
                    </div>
                </div>

                {{-- Live Preview — Dual Theme --}}
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Dark theme preview --}}
                    <div class="rounded-xl border border-slate-200 p-4" style="background:#0d0f14; color:#eef0f5;">
                        <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#8890a4;">Dark Preview</div>
                        <div class="flex gap-2 mb-3">
                            <div class="rounded-lg p-2 w-24 space-y-1" style="background:#13161d;">
                                <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Menu</div>
                                <div id="dark-sidebar-hover" class="rounded px-2 py-1 text-[10px] font-medium" style="background:color-mix(in srgb, #0ea5e9 12%, transparent); color:#0ea5e9;">Active</div>
                                <div class="rounded px-2 py-1 text-[10px]" style="color:#8890a4;">Item</div>
                            </div>
                            <div class="flex-1 space-y-2">
                                <div id="dark-default-bar" class="rounded-lg px-3 py-1.5 text-[10px] font-semibold text-white" style="background:#0b2a4a;">Header</div>
                                <div id="dark-button-preview" class="rounded-lg px-3 py-1.5 text-[10px] font-semibold text-white text-center" style="background:#0ea5e9;">Button</div>
                                <div class="flex items-center gap-1.5">
                                    <div id="dark-avatar" class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white" style="background:#0b2a4a;">AB</div>
                                    <span id="dark-icon-link" class="text-[10px] font-medium" style="color:#0ea5e9;">Link text</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span id="dark-swatch-s" class="inline-block w-4 h-4 rounded-full border border-white/20" style="background:#0ea5e9;" title="Sidebar"></span>
                            <span id="dark-swatch-i" class="inline-block w-4 h-4 rounded-full border border-white/20" style="background:#0ea5e9;" title="Icons"></span>
                            <span id="dark-swatch-d" class="inline-block w-4 h-4 rounded-full border border-white/20" style="background:#0b2a4a;" title="Default"></span>
                            <span id="dark-swatch-b" class="inline-block w-4 h-4 rounded-full border border-white/20" style="background:#0ea5e9;" title="Buttons"></span>
                        </div>
                    </div>

                    {{-- Light theme preview --}}
                    <div class="rounded-xl border border-slate-200 p-4" style="background:#f4f6fb; color:#111827;">
                        <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:#9ca3af;">Light Preview</div>
                        <div class="flex gap-2 mb-3">
                            <div class="rounded-lg p-2 w-24 space-y-1" style="background:#ffffff; border:1px solid rgba(0,0,0,0.07);">
                                <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Menu</div>
                                <div id="light-sidebar-hover" class="rounded px-2 py-1 text-[10px] font-medium" style="background:color-mix(in srgb, #0ea5e9 12%, transparent); color:#0ea5e9;">Active</div>
                                <div class="rounded px-2 py-1 text-[10px]" style="color:#9ca3af;">Item</div>
                            </div>
                            <div class="flex-1 space-y-2">
                                <div id="light-default-bar" class="rounded-lg px-3 py-1.5 text-[10px] font-semibold text-white" style="background:#0b2a4a;">Header</div>
                                <div id="light-button-preview" class="rounded-lg px-3 py-1.5 text-[10px] font-semibold text-white text-center" style="background:#0ea5e9;">Button</div>
                                <div class="flex items-center gap-1.5">
                                    <div id="light-avatar" class="w-6 h-6 rounded-full flex items-center justify-center text-[8px] font-bold text-white" style="background:#0b2a4a;">AB</div>
                                    <span id="light-icon-link" class="text-[10px] font-medium" style="color:#0ea5e9;">Link text</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span id="light-swatch-s" class="inline-block w-4 h-4 rounded-full border border-slate-200" style="background:#0ea5e9;" title="Sidebar"></span>
                            <span id="light-swatch-i" class="inline-block w-4 h-4 rounded-full border border-slate-200" style="background:#0ea5e9;" title="Icons"></span>
                            <span id="light-swatch-d" class="inline-block w-4 h-4 rounded-full border border-slate-200" style="background:#0b2a4a;" title="Default"></span>
                            <span id="light-swatch-b" class="inline-block w-4 h-4 rounded-full border border-slate-200" style="background:#0ea5e9;" title="Buttons"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Active status --}}
            <div class="flex items-center gap-3">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" id="is_active"
                       {{ old('is_active', $agency?->is_active ?? true) ? 'checked' : '' }}
                       class="w-4 h-4 rounded border-slate-300 cursor-pointer"
                       style="accent-color:var(--brand-icon, #0ea5e9);">
                <label for="is_active" class="text-sm font-medium cursor-pointer" style="color:var(--brand-default, #0b2a4a);">Agency is active</label>
            </div>
        </div>

        {{-- Company Details --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6">
            <div>
                <div class="text-sm font-bold uppercase tracking-wider mb-1" style="color:var(--brand-primary, #0b2a4a);">Company Details</div>
                <p class="text-xs text-slate-400">These details appear on legal documents and letterheads.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Trading Name</label>
                    <input type="text" name="trading_name" value="{{ old('trading_name', $agency?->trading_name) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. Johan and Elize Properties T/A">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Tagline</label>
                    <input type="text" name="tagline" value="{{ old('tagline', $agency?->tagline) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. THE MANDATE COMPANY">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Address</label>
                <textarea name="address" rows="2"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                          placeholder="Physical address">{{ old('address', $agency?->address) }}</textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $agency?->phone) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 071 351 0291">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Secondary Cell</label>
                    <input type="text" name="phone_secondary" value="{{ old('phone_secondary', $agency?->phone_secondary) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 079 495 5994">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Fax</label>
                    <input type="text" name="fax" value="{{ old('fax', $agency?->fax) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 086 233 2395">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Email</label>
                    <input type="text" name="email" value="{{ old('email', $agency?->email) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. admin@hfcoastal.co.za">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Registration No</label>
                    <input type="text" name="reg_no" value="{{ old('reg_no', $agency?->reg_no) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 2009/228978/23">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">VAT No</label>
                    <input type="text" name="vat_no" value="{{ old('vat_no', $agency?->vat_no) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 4870264498">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">FFC No</label>
                    <input type="text" name="ffc_no" value="{{ old('ffc_no', $agency?->ffc_no) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. FFC40/43916/5">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">FIC No</label>
                    <input type="text" name="fic_no" value="{{ old('fic_no', $agency?->fic_no) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. 58538">
                </div>
            </div>
        </div>

        {{-- Syndication (Property24) --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-4">
            <div>
                <div class="text-sm font-bold uppercase tracking-wider mb-1" style="color:var(--brand-primary, #0b2a4a);">Syndication</div>
                <p class="text-xs text-slate-400">Where this agency's listings are published on Property24. Each branch can override this default.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Property24 Agency ID</label>
                    <input type="text" name="p24_agency_id" value="{{ old('p24_agency_id', $agency?->p24_agency_id) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none"
                           placeholder="e.g. 31357">
                    <p class="text-xs text-slate-400 mt-1">The numeric ID P24 assigns to your agency profile. Leave blank if this agency does not syndicate to P24.</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">Property24 Label</label>
                    <input type="text" name="p24_agency_label" value="{{ old('p24_agency_label', $agency?->p24_agency_label) }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. Home Finders Coastal — HFC1">
                    <p class="text-xs text-slate-400 mt-1">Human-readable label shown in the admin UI only. Not sent to Property24.</p>
                </div>
            </div>
        </div>

        {{-- Logo --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-6" x-data="{ removelogo: false }">
            <div>
                <div class="text-sm font-bold uppercase tracking-wider mb-1" style="color:var(--brand-primary, #0b2a4a);">Logo</div>
                <p class="text-xs text-slate-400">Appears on documents and letterheads. JPG, PNG, or WebP — max 2 MB.</p>
            </div>

            @if($agency?->logo_path)
                <div class="flex items-center gap-4">
                    <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="Current logo"
                         class="h-16 rounded border border-slate-200 bg-slate-50 p-1">
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="remove_logo" value="1" id="remove_logo" x-model="removelogo"
                               class="w-4 h-4 rounded border-slate-300 cursor-pointer"
                               style="accent-color:var(--brand-secondary, #00b4d8);">
                        <label for="remove_logo" class="text-sm text-slate-600 cursor-pointer">Remove current logo</label>
                    </div>
                </div>
            @endif

            <div x-show="!removelogo">
                <label class="block text-sm font-semibold mb-1" style="color:var(--brand-primary, #0b2a4a);">
                    {{ $agency?->logo_path ? 'Replace Logo' : 'Upload Logo' }}
                </label>
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                       class="w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:text-white file:cursor-pointer"
                       style="file:background:var(--brand-primary, #0b2a4a);">
            </div>
        </div>

        {{-- ── Demo Agency toggle (create flow only) ──
             Demo agencies skip the First Admin requirement entirely. --}}
        @if(!$agency)
        <div class="rounded-2xl border border-slate-200 bg-white p-6">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="is_demo" value="1" x-model="isDemo" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="block text-sm font-semibold" style="color:var(--brand-default, #0b2a4a);">Demo agency</span>
                    <span class="block text-xs text-slate-500 mt-0.5">For showcasing, training, or sales demos. No first Admin required — the agency is created empty.</span>
                </span>
            </label>
        </div>
        @endif

        {{-- ── First Admin (create flow only, hidden for demo agencies) ──
             Every live agency must have ≥1 Admin. On create, the System Owner
             registers the first Admin user inline. See .ai/specs/agency-admin-rule.md. --}}
        @if(!$agency)
        <div x-show="!isDemo" x-cloak class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5">
            <div>
                <h3 class="text-base font-bold" style="color:var(--brand-default, #0b2a4a);">First Admin <span class="text-red-500">*</span></h3>
                <p class="text-xs text-slate-500 mt-0.5">Every agency must have at least one Admin. They are created together — this user becomes the agency's first Admin and gets full permissions.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Full name <span class="text-red-500">*</span></label>
                    <input type="text" name="admin_name" value="{{ old('admin_name') }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="e.g. Jane Smith" :required="!isDemo" :disabled="isDemo">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="admin_email" value="{{ old('admin_email') }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="admin@agency.co.za" :required="!isDemo" :disabled="isDemo">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="admin_password"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="min 8 characters" minlength="8" :required="!isDemo" :disabled="isDemo">
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1" style="color:var(--brand-default, #0b2a4a);">Mobile</label>
                    <input type="text" name="admin_cell" value="{{ old('admin_cell') }}"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none"
                           placeholder="optional">
                </div>
            </div>
        </div>
        @endif

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
                    style="background:var(--brand-button, #0ea5e9);"
                    onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                {{ $agency ? 'Update Agency' : 'Create Agency' }}
            </button>
            @php $cancelUrl = auth()->user()?->isOwnerRole() ? route('agencies.index') : route('admin.company-settings'); @endphp
            <a href="{{ $cancelUrl }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 border border-slate-300 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function hexToRgb(hex) {
        const r = parseInt(hex.slice(1,3), 16);
        const g = parseInt(hex.slice(3,5), 16);
        const b = parseInt(hex.slice(5,7), 16);
        return { r, g, b };
    }

    function colorMix(hex, pct) {
        const { r, g, b } = hexToRgb(hex);
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

    function updatePreviews() {
        const sidebar = document.getElementById('sidebar_color_picker').value;
        const icon    = document.getElementById('icon_color_picker').value;
        const def     = document.getElementById('default_color_picker').value;
        const button  = document.getElementById('button_color_picker').value;

        // Dark preview
        const dsh = document.getElementById('dark-sidebar-hover');
        if (dsh) { dsh.style.background = colorMix(sidebar, 0.12); dsh.style.color = sidebar; }
        const ddb = document.getElementById('dark-default-bar');
        if (ddb) ddb.style.background = def;
        const dbp = document.getElementById('dark-button-preview');
        if (dbp) dbp.style.background = button;
        const da = document.getElementById('dark-avatar');
        if (da) da.style.background = def;
        const dil = document.getElementById('dark-icon-link');
        if (dil) dil.style.color = icon;
        document.getElementById('dark-swatch-s').style.background = sidebar;
        document.getElementById('dark-swatch-i').style.background = icon;
        document.getElementById('dark-swatch-d').style.background = def;
        document.getElementById('dark-swatch-b').style.background = button;

        // Light preview
        const lsh = document.getElementById('light-sidebar-hover');
        if (lsh) { lsh.style.background = colorMix(sidebar, 0.12); lsh.style.color = sidebar; }
        const ldb = document.getElementById('light-default-bar');
        if (ldb) ldb.style.background = def;
        const lbp = document.getElementById('light-button-preview');
        if (lbp) lbp.style.background = button;
        const la = document.getElementById('light-avatar');
        if (la) la.style.background = def;
        const lil = document.getElementById('light-icon-link');
        if (lil) lil.style.color = icon;
        document.getElementById('light-swatch-s').style.background = sidebar;
        document.getElementById('light-swatch-i').style.background = icon;
        document.getElementById('light-swatch-d').style.background = def;
        document.getElementById('light-swatch-b').style.background = button;
    }

    syncPair('sidebar_color_picker', 'sidebar_color_text');
    syncPair('icon_color_picker',    'icon_color_text');
    syncPair('default_color_picker', 'default_color_text');
    syncPair('button_color_picker',  'button_color_text');

    updatePreviews();
});
</script>
@endsection
