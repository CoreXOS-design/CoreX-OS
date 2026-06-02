@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6"
     x-data="companySettingsPage({{ $agency?->id ?? 'null' }})">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Company Settings</h1>
        <p class="text-sm text-white/60">Agency identity, branches, and performance defaults.</p>
    </div>

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
            {{ $errors->first() }}
        </div>
    @endif

    @if($agencies->count() > 1)
        <div class="ds-status-card p-4">
            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Editing agency</label>
            <select x-model="selectedAgencyId" @change="switchAgency()"
                    class="w-full sm:w-80 rounded-md px-3 py-2 text-sm"
                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                @foreach($agencies as $a)
                    <option value="{{ $a->id }}" {{ $agency && $a->id === $agency->id ? 'selected' : '' }}>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if($agency)
        {{-- Tab nav --}}
        <div class="flex gap-1 rounded-md p-1" style="background: var(--surface); border:1px solid var(--border);">
            @foreach([
                'company'     => 'Company',
                'branding'    => 'Branding',
                'branches'    => 'Branches',
                'website'     => 'Website',
                'performance' => 'Performance',
            ] as $key => $label)
                <button type="button" @click="activeTab = '{{ $key }}'"
                        :class="activeTab === '{{ $key }}' ? 'corex-tab-active' : ''"
                        class="flex-1 sm:flex-none px-4 py-2 text-sm font-medium rounded-md transition-colors"
                        :style="activeTab === '{{ $key }}'
                            ? 'background: var(--brand-button, #0ea5e9); color: #fff;'
                            : 'color: var(--text-secondary);'">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- ============================================================
             COMPANY TAB
             ============================================================ --}}
        <div x-show="activeTab === 'company'" x-cloak>
            <form method="POST" action="{{ route('admin.company-settings.update', $agency) }}" enctype="multipart/form-data"
                  class="ds-status-card p-4 space-y-5"
                  x-data="{ removelogo: false }">
                @csrf
                @method('PUT')

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Identity</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Trading Name</label>
                        <input type="text" name="trading_name" value="{{ old('trading_name', $agency->trading_name) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. Coastal Crest Realty (Pty) Ltd">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Tagline</label>
                        <input type="text" name="tagline" value="{{ old('tagline', $agency->tagline) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. YOUR HORIZON HOME">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Registration No</label>
                        <input type="text" name="reg_no" value="{{ old('reg_no', $agency->reg_no) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 2020/123456/07">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">VAT No</label>
                        <input type="text" name="vat_no" value="{{ old('vat_no', $agency->vat_no) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 4123456789">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">PPRA Registration Number</label>
                        <input type="text" name="ppra_number" value="{{ old('ppra_number', $agency->ppra_number) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 2023116041">
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">The agency's per-entity registration with the Property Practitioners Regulatory Authority (separate from individual practitioner FFCs).</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">FFC No</label>
                        <input type="text" name="ffc_no" value="{{ old('ffc_no', $agency->ffc_no) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. FFC50/12345/3">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">FIC No</label>
                        <input type="text" name="fic_no" value="{{ old('fic_no', $agency->fic_no) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 12345">
                    </div>
                </div>

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Contact Details</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Address</label>
                        <textarea name="address" rows="2"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                  placeholder="e.g. 12 Marina Drive, Seabreeze Bay">{{ old('address', $agency->address) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Primary Cell Number</label>
                        <input type="text" name="phone" value="{{ old('phone', $agency->phone) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 081 234 5678">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Primary Cell Label</label>
                        <input type="text" name="phone_label" value="{{ old('phone_label', $agency->phone_label) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. Sales:">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Secondary Cell Number</label>
                        <input type="text" name="phone_secondary" value="{{ old('phone_secondary', $agency->phone_secondary) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 082 345 6789">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Secondary Cell Label</label>
                        <input type="text" name="phone_secondary_label" value="{{ old('phone_secondary_label', $agency->phone_secondary_label) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. Rentals:">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Fax</label>
                        <input type="text" name="fax" value="{{ old('fax', $agency->fax) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. 086 100 2000">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Email</label>
                        <input type="text" name="email" value="{{ old('email', $agency->email) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. info@coastalcrest.example">
                    </div>
                </div>

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Email Signature</div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Email Disclaimer</label>
                        <textarea name="email_disclaimer" rows="4"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                  placeholder="Email disclaimer text shown at bottom of all outgoing emails">{{ old('email_disclaimer', $agency->email_disclaimer) }}</textarea>
                    </div>
                </div>

                {{-- Phase 9c-3 rebuild — Privacy Policy lives next to Email Disclaimer. --}}
                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Privacy Policy</div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Privacy Policy Content</label>
                        <textarea name="privacy_policy_markdown" rows="4"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                  placeholder="# Privacy Policy&#10;&#10;Effective date: …">{{ old('privacy_policy_markdown', $agency->privacy_policy_markdown) }}</textarea>
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">Markdown supported. Will be rendered on a public page accessible to anyone with the link.</p>
                    </div>

                    @php
                        $hasContent  = !empty($agency->privacy_policy_markdown);
                        $isPublished = $hasContent && $agency->privacy_policy_published_at !== null;
                        $publicUrl   = $agency->privacyPolicyPublicUrl();
                    @endphp

                    <div class="flex items-center gap-3 flex-wrap">
                        @if($isPublished)
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded"
                                  style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);">Published</span>
                            <button type="button"
                                    onclick="navigator.clipboard.writeText('{{ $publicUrl }}'); this.innerText='Copied ✓'; setTimeout(()=>this.innerText='Copy public link',1500);"
                                    class="corex-btn-outline text-xs">Copy public link</button>
                            <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="text-xs" style="color:var(--text-secondary);">View public page →</a>
                            <button type="submit" name="privacy_policy_action" value="unpublish" class="corex-btn-outline text-xs ml-auto">Unpublish</button>
                        @elseif($hasContent)
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded"
                                  style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber);">Draft — not yet public</span>
                            <button type="submit" name="privacy_policy_action" value="publish" class="corex-btn-primary text-xs ml-auto">Publish</button>
                        @else
                            <p class="text-xs" style="color:var(--text-muted);">Enter content above and save to begin. You'll be able to publish on the next save.</p>
                        @endif
                    </div>

                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">External Privacy URL (Optional)</label>
                        <input type="text" name="popi_url" value="{{ old('popi_url', $agency->popi_url) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                               placeholder="e.g. https://coastalcrest.example/popi-policy">
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">If you host your privacy policy externally and prefer to use that URL instead of CoreX's internal hosting, paste the full URL here. CoreX's internal published version takes precedence when both are set.</p>
                    </div>
                </div>

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">WhatsApp Launch Behaviour</div>
                <div class="space-y-4">
                    <p class="text-xs" style="color:var(--text-muted);">
                        Controls how WhatsApp links open when an agent sends a pitch or a seller clicks "Reply on WhatsApp" on the public landing page.
                    </p>

                    {{-- Agent-side --}}
                    <div>
                        <label class="block text-xs font-medium mb-2" style="color:var(--text-secondary);">
                            Agent-side (Compose pitch → Send button)
                        </label>
                        <div class="space-y-2">
                            @foreach([
                                'whatsapp_app' => ['title' => 'Open app directly', 'desc' => 'Faster. No intermediate page. Recommended if all agents have WhatsApp installed.'],
                                'whatsapp_web' => ['title' => 'Open WhatsApp web (default)', 'desc' => 'Safer. Works regardless of whether the agent has WhatsApp installed.'],
                            ] as $value => $opt)
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="radio" name="whatsapp_launch_mode_agent" value="{{ $value }}"
                                           @checked(old('whatsapp_launch_mode_agent', $agency->whatsapp_launch_mode_agent ?? 'whatsapp_web') === $value)
                                           class="mt-0.5">
                                    <span class="text-sm" style="color:var(--text-primary);">
                                        <span class="font-medium">{{ $opt['title'] }}</span>
                                        <span class="block text-xs mt-0.5" style="color:var(--text-muted);">{{ $opt['desc'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Seller-side --}}
                    <div>
                        <label class="block text-xs font-medium mb-2" style="color:var(--text-secondary);">
                            Seller-side (Public landing → Reply on WhatsApp button)
                        </label>
                        <div class="space-y-2">
                            @foreach([
                                'whatsapp_app' => ['title' => 'Open app directly', 'desc' => 'Faster but requires seller to have WhatsApp installed.'],
                                'whatsapp_web' => ['title' => 'Open WhatsApp web (default)', 'desc' => 'Safer. Falls back gracefully if the seller does not have WhatsApp. Recommended.'],
                            ] as $value => $opt)
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="radio" name="whatsapp_launch_mode_seller" value="{{ $value }}"
                                           @checked(old('whatsapp_launch_mode_seller', $agency->whatsapp_launch_mode_seller ?? 'whatsapp_web') === $value)
                                           class="mt-0.5">
                                    <span class="text-sm" style="color:var(--text-primary);">
                                        <span class="font-medium">{{ $opt['title'] }}</span>
                                        <span class="block text-xs mt-0.5" style="color:var(--text-muted);">{{ $opt['desc'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Prospecting pitch lock duration — applies when an agent clicks
                     "Pitch Seller" on a prospecting listing. Prevents two agents
                     composing pitches on the same listing concurrently. --}}
                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Prospecting Coordination</div>
                <div>
                    <label class="block text-xs font-medium mb-2" style="color:var(--text-secondary);">
                        Pitch lock duration (minutes)
                    </label>
                    <input type="number" name="prospecting_pitch_temp_lock_minutes" min="5" max="240"
                           value="{{ old('prospecting_pitch_temp_lock_minutes', $agency->prospecting_pitch_temp_lock_minutes ?? 30) }}"
                           class="w-32 px-3 py-2 text-sm rounded"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        How long an agent's "Pitch Seller" click holds a temporary lock before auto-releasing.
                        Prevents two agents from pitching the same listing concurrently. Range 5–240. Default: 30.
                    </p>
                </div>

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Logo</div>
                <div>
                    @if($agency->logo_path)
                        <div class="mb-2 flex items-center gap-3">
                            <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="Company Logo"
                                 class="h-10 w-auto rounded-md p-1"
                                 style="background: var(--surface-2); border: 1px solid var(--border);">
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                <input type="checkbox" name="remove_logo" value="1" class="rounded" x-model="removelogo">
                                Remove logo
                            </label>
                        </div>
                    @endif
                    <div x-show="!removelogo">
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                               class="block w-full text-sm rounded-md px-3 py-2"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
                        <p class="text-xs mt-1" style="color:var(--text-muted);">JPG, PNG, or WebP — max 2 MB.</p>
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="submit" class="corex-btn-primary">Save Company Settings</button>
                </div>
            </form>
        </div>

        {{-- ============================================================
             BRANDING TAB — agency colour roles + live preview
             ============================================================ --}}
        <div x-show="activeTab === 'branding'" x-cloak>
            <form method="POST" action="{{ route('admin.company-settings.update', $agency) }}"
                  class="ds-status-card p-4 space-y-5">
                @csrf
                @method('PUT')

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Brand Colours</div>
                <p class="text-xs" style="color:var(--text-muted);">Four semantic colour roles control the entire platform look for this agency.</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach([
                        ['key'=>'sidebar_color', 'label'=>'Sidebar', 'desc'=>'Sidebar hover &amp; active highlight', 'default'=>'#0ea5e9'],
                        ['key'=>'icon_color',    'label'=>'Icons',   'desc'=>'Icons, active states, links, accents', 'default'=>'#0ea5e9'],
                        ['key'=>'default_color', 'label'=>'Default', 'desc'=>'Profiles, headers, general branding',  'default'=>'#0b2a4a'],
                        ['key'=>'button_color',  'label'=>'Buttons', 'desc'=>'Primary buttons, CTAs',                'default'=>'#0ea5e9'],
                    ] as $c)
                        @php $val = old($c['key'], $agency->{$c['key']} ?? $c['default']); @endphp
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

                {{-- Live Preview — Dual Theme --}}
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
                    <button type="submit" class="corex-btn-primary">Save Branding</button>
                </div>
            </form>
        </div>

        {{-- ============================================================
             BRANCHES TAB
             ============================================================ --}}
        <div x-show="activeTab === 'branches'" x-cloak class="space-y-6">

            {{-- Add / Delete Branches --}}
            <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="ds-section-header">Add Branch</h3>

                <form method="POST" action="{{ route('admin.branches.store') }}" class="flex flex-wrap gap-3 items-end">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                        <input class="w-full rounded-md px-3 py-2 text-sm" name="name" required
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                               placeholder="e.g. Seabreeze Bay">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Code</label>
                        <input class="w-full rounded-md px-3 py-2 text-sm" name="code" required
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                               placeholder="e.g. SBB">
                    </div>
                    <button type="submit" class="corex-btn-primary text-sm">Add Branch</button>
                </form>

                <div class="pt-4 space-y-2">
                    <h4 class="text-sm font-semibold" style="color: var(--text-primary);">Existing Branches</h4>

                    @forelse($branches as $branch)
                        <div class="flex items-center justify-between gap-4 pb-2" style="border-bottom: 1px solid var(--border);">
                            <div class="font-medium" style="color: var(--text-primary);">
                                {{ $branch->name }} <span style="color: var(--text-muted);">({{ $branch->code }})</span>
                            </div>

                            <form method="POST" action="{{ route('admin.branches.delete', $branch) }}"
                                  onsubmit="return confirm('Delete this branch? This cannot be undone.');">
                                @csrf
                                <button class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                            </form>
                        </div>
                    @empty
                        <div class="rounded-md py-8 px-6 text-center text-sm" style="color: var(--text-muted);">
                            No branches yet. Add the first one above.
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Branch Contact Details --}}
            <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
                <div>
                    <h3 class="ds-section-header">Branch Contact Details</h3>
                    <div class="text-sm mt-1" style="color: var(--text-muted);">
                        Leave blank to inherit from Agency settings.
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach($branches as $branch)
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
                                <button type="submit" x-show="open" @click.stop class="corex-btn-primary text-sm">Save</button>
                            </div>

                            <div x-show="open" x-cloak class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Trading Name Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="trading_name" value="{{ old('trading_name', $branch->trading_name) }}"
                                           placeholder="e.g. Coastal Crest Realty (Pty) Ltd">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Tagline Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="tagline" value="{{ old('tagline', $branch->tagline) }}"
                                           placeholder="e.g. YOUR HORIZON HOME">
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Address Override</label>
                                <textarea name="address" rows="2"
                                          class="w-full rounded-md px-3 py-2 text-sm"
                                          style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                          placeholder="e.g. 12 Marina Drive, Seabreeze Bay">{{ old('address', $branch->address) }}</textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="phone" value="{{ old('phone', $branch->phone) }}"
                                           placeholder="e.g. 081 234 5678">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Secondary Cell Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="phone_secondary" value="{{ old('phone_secondary', $branch->phone_secondary) }}"
                                           placeholder="e.g. 082 345 6789">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Fax</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="fax" value="{{ old('fax', $branch->fax) }}"
                                           placeholder="e.g. 086 100 2000">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="email" value="{{ old('email', $branch->email) }}"
                                           placeholder="e.g. info@coastalcrest.example">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Registration No Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="reg_no" value="{{ old('reg_no', $branch->reg_no) }}"
                                           placeholder="e.g. 2020/123456/07">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">VAT No Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="vat_no" value="{{ old('vat_no', $branch->vat_no) }}"
                                           placeholder="e.g. 4123456789">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">PPRA Number Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="ppra_number" value="{{ old('ppra_number', $branch->ppra_number) }}"
                                           placeholder="leave blank to inherit from agency">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FFC No Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="ffc_no" value="{{ old('ffc_no', $branch->ffc_no) }}"
                                           placeholder="e.g. FFC50/12345/3">
                                </div>
                                <div class="md:col-span-2 pt-3" style="border-top:1px dashed var(--border);">
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Privacy Policy Content Override</label>
                                    <textarea name="privacy_policy_markdown" rows="3"
                                              class="w-full rounded-md px-3 py-2 text-sm"
                                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                              placeholder="Leave blank to inherit the agency's privacy policy">{{ old('privacy_policy_markdown', $branch->privacy_policy_markdown) }}</textarea>
                                    @php
                                        $branchHasContent  = !empty($branch->privacy_policy_markdown);
                                        $branchIsPublished = $branchHasContent && $branch->privacy_policy_published_at !== null;
                                        $branchPublicUrl   = $branchIsPublished
                                            ? route('public.privacy-policy', ['token' => $branch->privacy_policy_token])
                                            : null;
                                    @endphp
                                    <div class="flex items-center gap-3 flex-wrap mt-2 text-xs">
                                        @if($branchIsPublished)
                                            <span class="inline-flex items-center px-2 py-0.5 font-semibold rounded"
                                                  style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);">Published — branch override active</span>
                                            <button type="button"
                                                    onclick="navigator.clipboard.writeText('{{ $branchPublicUrl }}'); this.innerText='Copied ✓'; setTimeout(()=>this.innerText='Copy branch link',1500);"
                                                    class="corex-btn-outline text-xs">Copy branch link</button>
                                            <button type="submit" name="privacy_policy_action" value="unpublish" class="corex-btn-outline text-xs ml-auto">Unpublish branch override</button>
                                        @elseif($branchHasContent)
                                            <span class="inline-flex items-center px-2 py-0.5 font-semibold rounded"
                                                  style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber);">Draft — branch override not yet public</span>
                                            <button type="submit" name="privacy_policy_action" value="publish" class="corex-btn-primary text-xs ml-auto">Publish branch override</button>
                                        @else
                                            <span style="color:var(--text-muted);">Inheriting agency policy. Type content to override.</span>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FIC No Override</label>
                                    <input class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                           name="fic_no" value="{{ old('fic_no', $branch->fic_no) }}"
                                           placeholder="e.g. 12345">
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
                            </div>
                        </form>
                    @endforeach
                </div>
            </div>

            {{-- Data Isolation — Split Branches toggle (moved from Settings hub) --}}
            @if(auth()->user()?->hasPermission('manage_performance_settings'))
            <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
                <h3 class="ds-section-header">Data Isolation</h3>
                <form method="POST" action="{{ route('corex.settings.split-branches') }}"
                      class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    @csrf
                    @method('PUT')

                    <div class="flex items-start gap-4">
                        <div class="flex-1">
                            <div class="text-sm font-semibold mb-1" style="color:var(--text-primary);">Split Branches</div>
                            <div class="text-xs leading-relaxed" style="color:var(--text-secondary);">
                                When ON, users only see data belonging to their own branch (contacts, properties, deals, documents, etc.).
                                Principals and users with <code>branches.view_all</code> continue to see everything across the agency.
                                Flip freely — no data loss.
                            </div>
                        </div>

                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0 mt-1">
                            <input type="hidden" name="split_branches_enabled" value="0">
                            <input type="checkbox" name="split_branches_enabled" value="1"
                                   {{ $agency->split_branches_enabled ? 'checked' : '' }}
                                   onchange="this.form.submit()"
                                   class="sr-only peer">
                            <div class="w-11 h-6 rounded-full transition-colors duration-300"
                                 style="background:var(--border);"></div>
                            <style>
                                input[type=checkbox]:checked + div { background: var(--brand-icon, #0ea5e9) !important; }
                                input[type=checkbox] + div::after {
                                    content:''; position:absolute; top:2px; left:2px; width:20px; height:20px;
                                    border-radius:50%; background:#fff; transition:transform .25s ease;
                                }
                                input[type=checkbox]:checked + div::after { transform: translateX(20px); }
                            </style>
                        </label>
                    </div>

                    <div class="mt-3 text-xs" style="color:var(--text-muted);">
                        Currently: <strong style="color:{{ $agency->split_branches_enabled ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-secondary)' }};">
                            {{ $agency->split_branches_enabled ? 'ON' : 'OFF' }}
                        </strong>
                        — {{ $agency->split_branches_enabled
                            ? 'Branch isolation is active.'
                            : 'All users see all agency data (current/default behaviour).' }}
                    </div>
                </form>
            </div>
            @endif
        </div>

        {{-- ============================================================
             WEBSITE TAB — public website settings (Agency Public API §3.7)
             ============================================================ --}}
        <div x-show="activeTab === 'website'" x-cloak>
            <form method="POST" action="{{ route('admin.company-settings.website.update', $agency) }}"
                  class="ds-status-card p-4 space-y-5">
                @csrf @method('PUT')
                <div>
                    <h3 class="ds-section-header">Website</h3>
                    <p class="text-xs" style="color:var(--text-muted);">Public details your agency website pulls via the API. Manage API keys + the live switch in <span class="font-semibold">Admin → Agencies → API Access</span>.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Website URL</label>
                        <input type="url" name="website_url" value="{{ old('website_url', $agency->website_url) }}" placeholder="https://www.youragency.co.za"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        @error('website_url')<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Tagline</label>
                        <input type="text" name="website_tagline" value="{{ old('website_tagline', $agency->website_tagline) }}" maxlength="255"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">About</label>
                    <textarea name="website_about" rows="4" maxlength="5000"
                              class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('website_about', $agency->website_about) }}</textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Public contact email</label>
                        <input type="email" name="website_contact_email" value="{{ old('website_contact_email', $agency->website_contact_email) }}"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        @error('website_contact_email')<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Public contact phone</label>
                        <input type="text" name="website_contact_phone" value="{{ old('website_contact_phone', $agency->website_contact_phone) }}"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube'] as $net => $label)
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">{{ $label }}</label>
                        <input type="text" name="website_social_{{ $net }}" value="{{ old('website_social_'.$net, $agency->{'website_social_'.$net}) }}" placeholder="handle or URL"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-5 pt-2" style="border-top:1px solid var(--border);">
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="hidden" name="website_show_agents" value="0">
                        <input type="checkbox" name="website_show_agents" value="1" class="rounded" style="accent-color:var(--brand-icon, #0ea5e9);"
                               {{ old('website_show_agents', (int)($agency->website_show_agents ?? 1)) ? 'checked' : '' }}>
                        Show agents on website
                    </label>
                    <label class="flex items-center gap-2.5 text-sm cursor-pointer" style="color:var(--text-secondary);">
                        <input type="hidden" name="website_show_listings" value="0">
                        <input type="checkbox" name="website_show_listings" value="1" class="rounded" style="accent-color:var(--brand-icon, #0ea5e9);"
                               {{ old('website_show_listings', (int)($agency->website_show_listings ?? 1)) ? 'checked' : '' }}>
                        Show listings on website
                    </label>
                </div>

                <button type="submit" class="corex-btn-primary">Save Website Settings</button>
            </form>
        </div>

        {{-- ============================================================
             PERFORMANCE TAB
             ============================================================ --}}
        <div x-show="activeTab === 'performance'" x-cloak>
            <form method="POST" action="{{ route('admin.performance-settings.update') }}"
                  class="ds-status-card p-4 space-y-5">
                @csrf
                {{-- Hidden fields to satisfy PerformanceSettingsController validation --}}
                <input type="hidden" name="company_name" value="">
                <input type="hidden" name="company_address" value="">
                <input type="hidden" name="company_tel" value="">
                <input type="hidden" name="company_ffc" value="">
                <input type="hidden" name="clear_company_logo" value="0">

                <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Performance Settings</div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">VAT Rate (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="vat_rate" value="{{ old('vat_rate', $vatRate) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <p class="text-xs mt-1" style="color:var(--text-muted);">Commission is stored as GROSS; we remove VAT using this rate.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Listings per Sale</label>
                        <input type="number" step="0.01" min="0.01" name="listings_per_sale" value="{{ old('listings_per_sale', $listingsPerSale) }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <p class="text-xs mt-1" style="color:var(--text-muted);">Used to calculate how many correctly-priced listings are needed for the target sales.</p>
                    </div>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="submit" class="corex-btn-primary">Save Performance Settings</button>
                </div>
            </form>
        </div>
    @else
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-6 h-6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 21V7.5l9-4.5 9 4.5V21M3 21h18M9 21V12h6v9"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No agency found</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Create an agency to configure its company settings.</p>
            <a href="{{ route('agencies.create') }}" class="corex-btn-primary">Create Agency</a>
        </div>
    @endif

</div>

<script>
function companySettingsPage(initialId) {
    return {
        selectedAgencyId: initialId,
        activeTab: 'company',
        switchAgency() {
            const url = new URL(window.location.href);
            url.searchParams.set('agency', this.selectedAgencyId);
            window.location.href = url.toString();
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
</script>
@endsection
