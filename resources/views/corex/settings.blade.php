@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5"
     x-data="settingsHub('{{ $activeSection }}')"
     x-init="$watch('activeSection', v => { const u = new URL(window.location); u.searchParams.set('s', v); u.searchParams.delete('tab'); u.searchParams.delete('fsec'); window.history.replaceState({}, '', u); })">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Settings</h1>
                <p class="text-sm text-white/60">System configuration and preferences.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Hub shell: left rail + right pane --}}
    @php
        $u = auth()->user();
        $can = fn($p) => $u && $u->hasPermission($p);
        $railGroups = [
            [
                'label' => 'My Preferences',
                'items' => [
                    ['key'=>'user', 'label'=>'Profile & Account', 'type'=>'section'],
                    ['key'=>'my-portal', 'label'=>'My Portal', 'type'=>'section', 'keywords'=>'api token chrome extension social media facebook instagram visibility'],
                ],
            ],
            [
                'label' => 'Agency',
                'items' => array_values(array_filter([
                    ['key'=>'agency',                'label'=>'Agency Settings',       'type'=>'section', 'keywords'=>'company branding logo signature'],
                    ['key'=>'company',               'label'=>'Company Settings',      'type'=>'link', 'href'=>route('admin.company-settings'), 'keywords'=>'trading name address logo branches assignments performance vat'],
                    ($u && $u->hasPermission('agency.manage_access_authorization'))
                        ? ['key'=>'remote-access', 'label'=>'Remote Access', 'type'=>'section', 'keywords'=>'system owner consent authorization cross-agency switch']
                        : null,
                ])),
            ],
            [
                'label' => 'Modules',
                'items' => [
                    ['key'=>'feature-documents',     'label'=>'Documents',             'type'=>'section', 'keywords'=>'docuperfect named fields'],
                    ['key'=>'feature-rentals',       'label'=>'Rentals',               'type'=>'section', 'keywords'=>'rental document types reminders'],
                    ['key'=>'feature-contacts',      'label'=>'Contacts',              'type'=>'section', 'keywords'=>'contact types sources tags'],
                    ['key'=>'feature-properties',    'label'=>'Properties & Listings', 'type'=>'section', 'keywords'=>'syndication portals marketing'],
                    ['key'=>'feature-presentations', 'label'=>'Presentations',         'type'=>'section', 'keywords'=>'cma coverage thresholds comps period rich moderate thin comparable selection price band radius erf percentile range widen anchor'],
                    ['key'=>'feature-matches',       'label'=>'Matches',               'type'=>'section', 'keywords'=>'whatsapp message'],
                    ['key'=>'feature-dashboard',     'label'=>'Dashboard',             'type'=>'section', 'keywords'=>'cockpit widgets'],
                    ['key'=>'notifications',         'label'=>'Notifications',         'type'=>'section', 'keywords'=>'reminders push email alerts overdue'],
                    ['key'=>'doc-types',             'label'=>'Document Types',        'type'=>'link', 'href'=>route('admin.settings.document-types.index'), 'keywords'=>'splitter filing'],
                    ['key'=>'docuperfect-types',    'label'=>'DocuPerfect — Types',   'type'=>'link', 'href'=>route('docuperfect.settings.types'), 'keywords'=>'document templates'],
                    ['key'=>'docuperfect-fields',   'label'=>'DocuPerfect — Named Fields','type'=>'link', 'href'=>route('docuperfect.settings.namedFields'), 'keywords'=>'merge fields'],
                ],
            ],
            [
                'label' => 'Operations',
                'items' => array_values(array_filter([
                    ['key'=>'commission',            'label'=>'Commission & Revenue Share','type'=>'section', 'keywords'=>'splits caps fees tiers revenue share mentor flqa'],
                    $can('command_center.settings')
                        ? ['key'=>'command-center', 'label'=>'Command Center Rules', 'type'=>'section', 'keywords'=>'expectations reminders automation rules document event classes thresholds']
                        : null,
                    $can('prospecting_setup.manage')
                        ? ['key'=>'prospecting-setup', 'label'=>'Prospecting Setup', 'type'=>'section', 'keywords'=>'towns suburbs property types bedroom segments price bands prospecting buyer match tiers']
                        : null,
                    $can('outreach_templates.manage')
                        ? ['key'=>'outreach-templates', 'label'=>'Outreach Templates', 'type'=>'section', 'keywords'=>'seller outreach whatsapp email template merge fields pitch tracking link']
                        : null,
                    ['key'=>'leave-visibility',      'label'=>'Leave Visibility',      'type'=>'section', 'keywords'=>'leave calendar matrix roles branch'],
                    $can('compliance.whistleblow.configure') ? ['key'=>'whistleblow-settings', 'label'=>'Compliance Reporting', 'type'=>'section', 'keywords'=>'whistleblower ppra approver complaints'] : null,
                ])),
            ],
            [
                'label' => 'PDF Suite',
                'items' => array_values(array_filter([
                    $can('calculators.manage')
                        ? ['key'=>'pdf-suite-labels', 'label'=>'Manage Labels', 'type'=>'link', 'href'=>route('admin.splitter.doc-types.index'), 'keywords'=>'pdf splitter document types labels ocr']
                        : null,
                ])),
            ],
            [
                'label' => 'System',
                'items' => array_values(array_filter([
                    ['key'=>'system',                'label'=>'System Info & Tools',   'type'=>'section', 'keywords'=>'environment debug'],
                    $can('manage_p24')
                        ? ['key'=>'p24-suburbs', 'label'=>'P24 Suburbs', 'type'=>'link', 'href'=>route('admin.p24-suburbs.index'), 'keywords'=>'property24 mapping suburb id region surrounding']
                        : null,
                ])),
            ],
        ];
    @endphp

    <div class="flex flex-col lg:flex-row gap-5">

        {{-- Left rail --}}
        <aside class="w-full lg:w-72 flex-shrink-0">
            <div class="rounded-md sticky top-4" style="background:var(--surface); border:1px solid var(--border);">
                <div class="p-3" style="border-bottom:1px solid var(--border);">
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input type="text" x-model="search" placeholder="Search settings…"
                               class="w-full rounded-md pl-8 pr-3 py-2 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
                <nav class="p-2 max-h-[70vh] overflow-y-auto" aria-label="Settings sections">
                    @foreach($railGroups as $group)
                        @php $groupId = 'rg-' . \Illuminate\Support\Str::slug($group['label']); @endphp
                        <div class="mt-2 first:mt-0" x-show="anyVisible(@js($group['items']))">
                            <div class="px-2 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">
                                {{ $group['label'] }}
                            </div>
                            @foreach($group['items'] as $item)
                                @php
                                    $matchExpr = "matchesSearch(" . json_encode(strtolower($item['label'] . ' ' . ($item['keywords'] ?? ''))) . ")";
                                @endphp
                                @if($item['type'] === 'section')
                                    <button type="button"
                                            @click="activeSection = '{{ $item['key'] }}'; $nextTick(() => window.scrollTo({top:0, behavior:'smooth'}))"
                                            x-show="{{ $matchExpr }}"
                                            :class="activeSection === '{{ $item['key'] }}' ? 'font-semibold' : ''"
                                            :style="activeSection === '{{ $item['key'] }}' ? 'background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color:var(--brand-icon, #0ea5e9);' : 'color:var(--text-secondary);'"
                                            class="w-full text-left px-3 py-2 rounded-md text-sm transition-colors duration-150 hover:bg-white/5 outline-none focus:outline-none">
                                        {{ $item['label'] }}
                                    </button>
                                @else
                                    <a href="{{ $item['href'] }}"
                                       x-show="{{ $matchExpr }}"
                                       class="flex items-center justify-between gap-2 px-3 py-2 rounded-md text-sm no-underline transition-colors duration-150 hover:bg-white/5"
                                       style="color:var(--text-secondary);">
                                        <span>{{ $item['label'] }}</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- Right pane --}}
        <div class="flex-1 min-w-0" style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">

        {{-- ============================================================
             AGENCY SETTINGS TAB
             Contains: Branch Assignments, Company Settings, Agency Mgmt
             ============================================================ --}}
        <div x-show="activeSection === 'agency'" x-cloak class="p-6 space-y-6">

            {{-- Company Settings — moved to its own admin page (now contains Branches & Performance tabs) --}}
            @if(isset($agency) && $agency)
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Company</h3>
                <a href="{{ route('admin.company-settings') }}"
                   class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline group hover:bg-white/5"
                   style="border:1px solid var(--border);">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Company Settings</div>
                        <div class="text-xs" style="color:var(--text-secondary);">Trading name, contact block, logo, email signature</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
            @endif

            {{-- Data Isolation moved to Company Settings → Branches tab --}}
            @if(false)
            <div>
                <form method="POST" action="{{ route('corex.settings.agency.update') }}" enctype="multipart/form-data"
                      class="space-y-5 p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);"
                      x-data="agencySettingsForm()" x-init="scheduleRefresh()">
                    @csrf
                    @method('PUT')

                    {{-- Company Identity --}}
                    <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Identity</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Trading Name</label>
                            <input type="text" name="trading_name" value="{{ old('trading_name', $agency->trading_name) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. Johan and Elize Properties T/A">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Tagline</label>
                            <input type="text" name="tagline" value="{{ old('tagline', $agency->tagline) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. THE MANDATE COMPANY">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Registration No</label>
                            <input type="text" name="reg_no" value="{{ old('reg_no', $agency->reg_no) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 2017/431318/07">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">VAT No</label>
                            <input type="text" name="vat_no" value="{{ old('vat_no', $agency->vat_no) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 4870264498">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC No</label>
                            <input type="text" name="ffc_no" value="{{ old('ffc_no', $agency->ffc_no) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. FFC40/43916/5">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FIC No</label>
                            <input type="text" name="fic_no" value="{{ old('fic_no', $agency->fic_no) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 58538">
                        </div>
                    </div>

                    {{-- Contact Details --}}
                    <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Contact Details</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address</label>
                            <textarea name="address" rows="2"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                      placeholder="Physical address">{{ old('address', $agency->address) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Cell Number</label>
                            <input type="text" name="phone" value="{{ old('phone', $agency->phone) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 071 351 0291">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Primary Cell Label (on header)</label>
                            <input type="text" name="phone_label" value="{{ old('phone_label', $agency->phone_label) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. Elize Reichel Cell:">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Secondary Cell Number</label>
                            <input type="text" name="phone_secondary" value="{{ old('phone_secondary', $agency->phone_secondary) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 079 495 5994">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Secondary Cell Label (on header)</label>
                            <input type="text" name="phone_secondary_label" value="{{ old('phone_secondary_label', $agency->phone_secondary_label) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. Johan Reichel Cell:">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Fax</label>
                            <input type="text" name="fax" value="{{ old('fax', $agency->fax) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. 086 514 7632">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email</label>
                            <input type="text" name="email" value="{{ old('email', $agency->email) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. admin@hfcoastal.co.za">
                        </div>
                    </div>

                    {{-- Email Signature --}}
                    <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Email Signature</div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email Disclaimer</label>
                            <textarea name="email_disclaimer" rows="4"
                                      class="w-full rounded-md px-3 py-2 text-sm"
                                      style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                      placeholder="Email disclaimer text shown at bottom of all outgoing emails">{{ old('email_disclaimer', $agency->email_disclaimer) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">POPI Policy URL</label>
                            <input type="text" name="popi_url" value="{{ old('popi_url', $agency->popi_url) }}"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                                   placeholder="e.g. https://hfcoastal.co.za/popi-policy">
                        </div>
                    </div>

                    {{-- Logo --}}
                    <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Company Logo</div>
                    <div>
                        @if($agency->logo_path)
                            <div class="mb-2 flex items-center gap-3">
                                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white p-1">
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

                    {{-- Live Previews --}}
                    <div class="text-xs font-bold uppercase tracking-wider pb-1" style="color:var(--text-muted); border-bottom:1px solid var(--border);">Live Previews</div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {{-- Document Header Preview --}}
                        <div>
                            <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Document Header</div>
                            <div class="rounded-md overflow-hidden" style="border:1px solid var(--border); background:#fff;">
                                <iframe x-ref="headerPreview" style="width:100%; height:240px; border:0;" sandbox="allow-same-origin"></iframe>
                            </div>
                        </div>

                        {{-- Email Signature Preview --}}
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-xs font-semibold" style="color:var(--text-secondary);">Email Signature</span>
                                <select x-model="sigPreviewUserId" @change="refreshSignaturePreview()"
                                        class="rounded text-xs px-2 py-1"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach($agents ?? [] as $a)
                                        <option value="{{ $a->id }}" {{ $a->id === auth()->id() ? 'selected' : '' }}>{{ $a->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rounded-md overflow-hidden" style="border:1px solid var(--border); background:#fff;">
                                <iframe x-ref="sigPreview" style="width:100%; height:300px; border:0;" sandbox="allow-same-origin"></iframe>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-1">
                        <button type="submit" class="corex-btn-primary text-sm">Save Company Settings</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Performance Settings moved to Company Settings → Performance tab --}}

            {{-- Agency Management (owner role only) --}}
            @if(auth()->user()?->isOwnerRole())
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Super Admin</h3>
                <a href="{{ route('agencies.index') }}"
                   class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                   style="border:1px solid var(--border);">
                    <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Agency Management</div>
                        <div class="text-xs" style="color:var(--text-secondary);">Create and manage agencies on the platform</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
            @endif

        </div>

        {{-- ============================================================
             REMOTE ACCESS TAB — agency-side consent toggle
             See .ai/specs/agency-access-authorization-spec.md
             ============================================================ --}}
        @if($u && $u->hasPermission('agency.manage_access_authorization') && isset($agency) && $agency)
        <div x-show="activeSection === 'remote-access'" x-cloak class="p-6 space-y-5">
            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Remote Access</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">
                    Control whether system owners can switch into <strong>{{ $agency->name }}</strong> without asking.
                </p>
            </div>

            <form method="POST" action="{{ route('corex.settings.remote-access') }}"
                  x-data="{ on: {{ $agency->require_external_access_authorization ? 'true' : 'false' }} }"
                  class="rounded-md p-5 space-y-4"
                  style="background:var(--surface-2); border:1px solid var(--border);">
                @csrf

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="require_external_access_authorization" value="1"
                           x-model="on"
                           class="mt-1 h-4 w-4 rounded">
                    <span class="flex-1">
                        <span class="block text-sm font-semibold" style="color:var(--text-primary);">
                            Require system owner consent for remote access
                        </span>
                        <span class="block text-xs mt-1" style="color:var(--text-secondary);">
                            <strong>OFF</strong> (default): a system owner can switch into this agency at any time, no questions asked.<br>
                            <strong>ON</strong>: every cross-agency switch attempt by a system owner triggers an approval request. The system owner picks which admin(s) to ask; only those admins receive the popup. Access lasts 24h once approved.
                        </span>
                    </span>
                </label>

                <div class="flex items-center justify-between gap-3 pt-2"
                     style="border-top:1px solid var(--border);">
                    <div class="text-xs" style="color:var(--text-muted);">
                        Currently:
                        <span class="font-semibold" :style="on ? 'color:var(--ds-amber, #f59e0b);' : 'color:var(--ds-green, #059669);'"
                              x-text="on ? 'ON — consent required' : 'OFF — open access'"></span>
                    </div>
                    <button type="submit" class="corex-btn-primary text-sm">
                        Save
                    </button>
                </div>
            </form>
        </div>
        @endif

        {{-- ============================================================
             COMMISSION & REVENUE SHARE — embedded Operations section
             Mirrors the standalone /settings/commission page, rendered
             inline in the hub like the other settings sections.
             ============================================================ --}}
        <div x-show="activeSection === 'commission'" x-cloak class="p-6 space-y-5"
             x-data="commissionSettings()">

            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Commission &amp; Revenue Share</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">Configure commission splits, caps, fees, and revenue share tiers.</p>
            </div>

            <form method="POST" action="{{ route('corex.settings.commission.update') }}" class="space-y-5">
                @csrf

                {{-- Commission Split --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Commission Split</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">How gross commission (excl. VAT) is divided between agent and agency before the annual cap is reached.</div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agent Split %</label>
                            <input type="number" name="commission_split_agent" x-model="agentSplit" min="0" max="100" step="1"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Agency Split %</label>
                            <input type="text" :value="100 - agentSplit" disabled
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Annual Cap (R)</label>
                            <input type="number" name="annual_cap" value="{{ old('annual_cap', $commissionSettings->annual_cap) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                    <div class="text-xs" style="color:var(--text-secondary);">
                        Agent receives <span class="font-bold" style="color:var(--brand-icon);" x-text="agentSplit + '%'"></span>
                        of commission excl. VAT before capping. After cap, agent receives 100% minus transaction fees.
                    </div>
                </div>

                {{-- Post-Cap Fees --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Post-Cap Fees</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Fees charged once an agent has reached their annual cap.</div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Transaction Fee (R)</label>
                            <input type="number" name="post_cap_transaction_fee" value="{{ old('post_cap_transaction_fee', $commissionSettings->post_cap_transaction_fee) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Post-Cap Fee Cap (R)</label>
                            <input type="number" name="post_cap_fee_cap" value="{{ old('post_cap_fee_cap', $commissionSettings->post_cap_fee_cap) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Reduced Fee After Cap (R)</label>
                            <input type="number" name="post_cap_reduced_fee" value="{{ old('post_cap_reduced_fee', $commissionSettings->post_cap_reduced_fee) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Monthly Fees --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Monthly Fees</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Recurring platform and risk-management charges.</div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Platform Fee (R/month)</label>
                            <input type="number" name="monthly_platform_fee" value="{{ old('monthly_platform_fee', $commissionSettings->monthly_platform_fee) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Risk Management Fee (R/tx)</label>
                            <input type="number" name="risk_management_fee" value="{{ old('risk_management_fee', $commissionSettings->risk_management_fee) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Risk Mgmt Annual Cap (R)</label>
                            <input type="number" name="risk_management_cap" value="{{ old('risk_management_cap', $commissionSettings->risk_management_cap) }}" min="0" step="0.01"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- Mentor Program --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Mentor Program</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Extra split applied while a new agent is being mentored, and how long it lasts.</div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Extra Split % (mentored transactions)</label>
                            <input type="number" name="mentor_extra_split" value="{{ old('mentor_extra_split', $commissionSettings->mentor_extra_split) }}" min="0" max="100" step="1"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Transactions Before Graduation</label>
                            <input type="number" name="mentor_transactions" value="{{ old('mentor_transactions', $commissionSettings->mentor_transactions) }}" min="1" max="50" step="1"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                    </div>
                    <div class="text-xs" style="color:var(--text-secondary);">
                        New agents under a mentor pay an extra {{ $commissionSettings->mentor_extra_split }}% on their first {{ $commissionSettings->mentor_transactions }} transactions. Split between mentor and agency.
                    </div>
                </div>

                {{-- Revenue Share --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Revenue Share</div>
                            <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Distribute a pool of the company dollar across sponsorship tiers.</div>
                        </div>
                        <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;">
                            <input type="hidden" name="revenue_share_enabled" value="0">
                            <input type="checkbox" name="revenue_share_enabled" value="1" x-model="revShareEnabled" class="sr-only">
                            <span class="block w-full h-full rounded-full transition-colors duration-200"
                                  style="background:var(--border-hover);"
                                  :style="revShareEnabled ? 'background: var(--brand-button, #0ea5e9)' : ''"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                  :style="revShareEnabled ? 'transform:translateX(20px)' : ''"></span>
                        </label>
                    </div>

                    <div x-show="revShareEnabled" x-cloak class="space-y-4 pt-1" style="border-top:1px solid var(--border);">
                        {{-- Pool percentage --}}
                        <div class="max-w-xs pt-3">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Pool % (of Company Dollar)</label>
                            <input type="number" name="revenue_share_pool_percent" value="{{ old('revenue_share_pool_percent', $commissionSettings->revenue_share_pool_percent) }}" min="0" max="100" step="1"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>

                        {{-- Tier table --}}
                        <div class="overflow-x-auto rounded-md" style="border:1px solid var(--border);">
                            <table class="w-full text-sm" style="border-collapse:separate; border-spacing:0;">
                                <thead>
                                    <tr style="background:var(--surface); border-bottom:1px solid var(--border);">
                                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Tier</th>
                                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Relationship</th>
                                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Share %</th>
                                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">FLQA Required</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $tierLabels = [
                                            1 => 'Directly sponsored',
                                            2 => 'Tier 1 recruits',
                                            3 => 'Tier 2 recruits',
                                            4 => 'Tier 3 recruits',
                                            5 => 'Tier 4 recruits',
                                            6 => 'Tier 5 recruits',
                                            7 => 'Tier 6 recruits',
                                        ];
                                    @endphp
                                    @for($t = 1; $t <= 7; $t++)
                                    <tr style="border-bottom:1px solid var(--border);">
                                        <td class="px-3 py-2 font-semibold" style="color:var(--text-primary);">Tier {{ $t }}</td>
                                        <td class="px-3 py-2" style="color:var(--text-secondary);">{{ $tierLabels[$t] }}</td>
                                        <td class="px-3 py-2">
                                            <input type="number" name="tier_{{ $t }}_percent"
                                                   value="{{ old("tier_{$t}_percent", $commissionSettings->{"tier_{$t}_percent"}) }}"
                                                   min="0" max="100" step="0.01"
                                                   class="w-24 rounded-md px-2 py-1 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </td>
                                        <td class="px-3 py-2">
                                            @if($t <= 3)
                                                <span class="text-xs font-medium px-2 py-1 rounded" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">Automatic</span>
                                            @else
                                                <input type="number" name="tier_{{ $t }}_flqa_requirement"
                                                       value="{{ old("tier_{$t}_flqa_requirement", $commissionSettings->{"tier_{$t}_flqa_requirement"}) }}"
                                                       min="0" step="1"
                                                       class="w-24 rounded-md px-2 py-1 text-sm"
                                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            @endif
                                        </td>
                                    </tr>
                                    @endfor
                                </tbody>
                            </table>
                        </div>

                        <div class="text-xs" style="color:var(--text-secondary);">
                            FLQA = First Line Qualifying Agent: a Tier 1 agent with 2+ transactions or R50,000+ GCI in the last 6 months.
                        </div>
                    </div>
                </div>

                {{-- Save button --}}
                <div class="flex justify-end">
                    <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5">
                        Save Commission Settings
                    </button>
                </div>
            </form>
        </div>

        {{-- ============================================================
             COMMAND CENTER RULES — embedded Operations section
             Mirrors the standalone /command-center/settings page, rendered
             inline in the hub like the other settings sections.
             ============================================================ --}}
        @permission('command_center.settings')
        <div x-show="activeSection === 'command-center'" x-cloak class="p-6 space-y-5"
             x-data="{ showAddExpectation: false }">

            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Command Center Rules</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">Automation rules, document expectations, and calendar event-class thresholds.</p>
            </div>

            {{-- Automation Rules --}}
            <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Automation Rules</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Toggle the system rules that drive Command Center automations.</div>
                    </div>
                    <span class="text-xs flex-shrink-0" style="color:var(--text-muted);">{{ $automationRules->where('is_active', true)->count() }} active / {{ $automationRules->count() }} total</span>
                </div>

                @if($automationRules->isEmpty())
                    <div class="py-6 text-center rounded-md" style="background:var(--surface);">
                        <p class="text-sm" style="color:var(--text-muted);">No automation rules configured. Run the seeder to load defaults.</p>
                        <p class="text-xs mt-1" style="color:var(--text-muted);">
                            <code class="font-mono text-[11px]" style="background:var(--surface-2); padding:2px 6px; border-radius:4px;">php artisan db:seed --class=CommandCenterAutomationSeeder</code>
                        </p>
                    </div>
                @else
                    <div class="rounded-md divide-y" style="background:var(--surface); border:1px solid var(--border); border-color:var(--border);">
                        @foreach($automationRules as $rule)
                            <div class="flex items-center gap-4 px-3 py-3">
                                <form method="POST" action="{{ route('command-center.settings.toggle-rule', $rule) }}" class="flex-shrink-0">
                                    @csrf @method('PATCH')
                                    <button type="submit"
                                            class="w-10 h-5 rounded-full relative transition-colors duration-200"
                                            style="background:{{ $rule->is_active ? 'var(--brand-button, #0ea5e9)' : 'var(--border-hover)' }};"
                                            title="{{ $rule->is_active ? 'Active — click to deactivate' : 'Inactive — click to activate' }}">
                                        <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                                              style="{{ $rule->is_active ? 'transform:translateX(1.25rem);' : 'transform:translateX(0.125rem);' }}"></span>
                                    </button>
                                </form>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium" style="color:var(--text-primary);">
                                        {{ $rule->name }}
                                        @if($rule->is_system)
                                            <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background:var(--surface-2); color:var(--text-muted);">System</span>
                                        @endif
                                    </p>
                                    @if($rule->description)
                                        <p class="text-xs mt-0.5 truncate" style="color:var(--text-muted);">{{ $rule->description }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 text-xs flex-shrink-0" style="color:var(--text-muted);">
                                    <span class="px-2 py-0.5 rounded" style="background:var(--surface-2);">{{ $rule->trigger_model }}</span>
                                    <span class="px-2 py-0.5 rounded" style="background:var(--surface-2);">{{ $rule->trigger_event }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Document Expectations --}}
            <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Document Expectations</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Which documents are expected when a property of each type is listed. Tasks are auto-created for the listing agent.</div>
                    </div>
                    <button @click="showAddExpectation = true" class="corex-btn-primary text-sm px-4 py-2 flex-shrink-0">+ Add</button>
                </div>

                @if($docExpectations->isEmpty())
                    <div class="py-4 text-center text-sm rounded-md" style="background:var(--surface); color:var(--text-muted);">
                        No document expectations configured. Default tasks will be created for new listings.
                    </div>
                @else
                    <div class="overflow-x-auto rounded-md" style="border:1px solid var(--border);">
                        <table class="w-full text-sm" style="border-collapse:separate; border-spacing:0;">
                            <thead>
                                <tr style="background:var(--surface); border-bottom:1px solid var(--border);">
                                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property Type</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Document</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Due</th>
                                    <th class="text-left py-2 px-3 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Required</th>
                                    <th class="py-2 px-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($docExpectations as $exp)
                                    <tr style="border-bottom:1px solid var(--border);">
                                        <td class="py-2 px-3" style="color:var(--text-primary);">{{ ucfirst($exp->property_type) }}</td>
                                        <td class="py-2 px-3" style="color:var(--text-primary);">{{ $exp->label }}</td>
                                        <td class="py-2 px-3 text-xs" style="color:var(--text-muted);">{{ $exp->due_offset_hours }}h</td>
                                        <td class="py-2 px-3">
                                            @if($exp->required)
                                                <span class="text-xs px-1.5 py-0.5 rounded" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson);">Required</span>
                                            @else
                                                <span class="text-xs" style="color:var(--text-muted);">Optional</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right">
                                            <form method="POST" action="{{ route('command-center.settings.destroy-expectation', $exp) }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs px-2 py-1 rounded hover:bg-red-500/10" style="color:var(--ds-crimson);" onclick="return confirm('Remove this expectation?')">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Event Classes --}}
            <div class="p-4 rounded-md flex items-center justify-between gap-4" style="background:var(--surface-2); border:1px solid var(--border);">
                <div>
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Event Classes</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Configure thresholds, visibility, and notifications for the 38 calendar event classes.</div>
                </div>
                <a href="{{ route('command-center.settings.event-classes') }}"
                   class="corex-btn-primary text-sm px-4 py-2 no-underline flex-shrink-0">Configure</a>
            </div>

            {{-- Add Expectation modal --}}
            <div x-show="showAddExpectation" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.5);" @keydown.escape.window="showAddExpectation = false">
                <div class="w-full max-w-md rounded-md shadow-xl" style="background:var(--surface); border:1px solid var(--border);" @click.outside="showAddExpectation = false">
                    <form method="POST" action="{{ route('command-center.settings.store-expectation') }}">
                        @csrf
                        <div class="px-6 py-4" style="border-bottom:1px solid var(--border);">
                            <h3 class="text-base font-bold" style="color:var(--text-primary);">Add Document Expectation</h3>
                        </div>
                        <div class="px-6 py-4 space-y-4">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Property Type</label>
                                <select name="property_type" required class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="sale">Sale</option>
                                    <option value="rental">Rental</option>
                                    <option value="commercial">Commercial</option>
                                    <option value="vacant_land">Vacant Land</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Document Label</label>
                                <input type="text" name="label" required placeholder="e.g. Signed Mandate" class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Due (hours)</label>
                                    <input type="number" name="due_offset_hours" value="72" min="1" required class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="flex items-end pb-2">
                                    <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                        <input type="checkbox" name="required" value="1" checked class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                                        Required
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="px-6 py-4 flex justify-end gap-2" style="border-top:1px solid var(--border);">
                            <button type="button" @click="showAddExpectation = false" class="px-4 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-secondary);">Cancel</button>
                            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Add</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endpermission

        {{-- ============================================================
             PROSPECTING SETUP — embedded Operations section
             Mirrors the standalone /settings/prospecting page by including
             the shared _panel partial inline in the hub.
             ============================================================ --}}
        @permission('prospecting_setup.manage')
        <div x-show="activeSection === 'prospecting-setup'" x-cloak class="p-6 space-y-5">
            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Prospecting Setup</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">Towns, property types, bedroom segments and price bands for your agency's prospecting.</p>
            </div>

            @include('settings.prospecting._panel', [
                'activeTab'         => $prospectingActiveTab ?? 'towns',
                'towns'             => $towns ?? collect(),
                'propertyTypes'     => $propertyTypes ?? collect(),
                'bedroomSegments'   => $bedroomSegments ?? collect(),
                'priceBandsSale'    => $priceBandsSale ?? collect(),
                'priceBandsRental'  => $priceBandsRental ?? collect(),
                'suggestionRegions' => $suggestionRegions ?? [],
                'unmappedSuburbs'   => $unmappedSuburbs ?? collect(),
                'buyerMatchTier'    => $buyerMatchTier ?? null,
                'context'           => 'page',
            ])
        </div>
        @endpermission

        {{-- ============================================================
             OUTREACH TEMPLATES — embedded Operations section
             Mirrors the standalone /settings/outreach-templates page,
             rendering the shared channel panels inline in the hub.
             ============================================================ --}}
        @permission('outreach_templates.manage')
        <div x-show="activeSection === 'outreach-templates'" x-cloak class="p-6 space-y-5"
             x-data="{ outreachTab: '{{ $outreachActiveTab ?? 'whatsapp' }}' }">
            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Seller Outreach Templates</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">
                    Pre-written templates agents use to pitch sellers via WhatsApp and email. Every template must include the
                    <code class="font-mono text-[11px]" style="background:var(--surface-2); padding:1px 4px; border-radius:4px;">{{ '{tracking_link}' }}</code> merge field and the opt-out word
                    <code class="font-mono text-[11px]" style="background:var(--surface-2); padding:1px 4px; border-radius:4px;">STOP</code>.
                </p>
            </div>

            {{-- Tabs --}}
            <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);">
                <button type="button" @click="outreachTab = 'whatsapp'"
                        :class="outreachTab === 'whatsapp' ? 'border-b-2' : 'border-b-2 border-transparent'"
                        :style="outreachTab === 'whatsapp' ? 'color: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-secondary);'"
                        class="px-4 py-3 text-xs font-semibold whitespace-nowrap">
                    WhatsApp Templates ({{ ($whatsappTemplates ?? collect())->count() }})
                </button>
                <button type="button" @click="outreachTab = 'email'"
                        :class="outreachTab === 'email' ? 'border-b-2' : 'border-b-2 border-transparent'"
                        :style="outreachTab === 'email' ? 'color: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-secondary);'"
                        class="px-4 py-3 text-xs font-semibold whitespace-nowrap">
                    Email Templates ({{ ($emailTemplates ?? collect())->count() }})
                </button>
            </div>

            {{-- WhatsApp panel --}}
            <div x-show="outreachTab === 'whatsapp'" x-cloak>
                @include('settings.outreach-templates._channel-panel', [
                    'channel'     => 'whatsapp',
                    'templates'   => $whatsappTemplates ?? collect(),
                    'mergeFields' => $mergeFields ?? [],
                ])
            </div>

            {{-- Email panel --}}
            <div x-show="outreachTab === 'email'" x-cloak>
                @include('settings.outreach-templates._channel-panel', [
                    'channel'     => 'email',
                    'templates'   => $emailTemplates ?? collect(),
                    'mergeFields' => $mergeFields ?? [],
                ])
            </div>
        </div>
        @endpermission

        {{-- ============================================================
             USER SETTINGS TAB
             Contains: User Management, Roles & Permissions, Designations
             ============================================================ --}}
        {{-- ============================================================
             MY PORTAL — per-user visibility prefs for /my-portal sections
             ============================================================ --}}
        <div x-show="activeSection === 'my-portal'" x-cloak class="p-6 space-y-5">
            <div>
                <h3 class="text-base font-bold" style="color:var(--text-primary);">My Portal</h3>
                <p class="text-xs mt-1" style="color:var(--text-muted);">Choose which sections appear on your <a href="{{ route('agent.portal') }}#profile" style="color:var(--brand-icon);">My Portal → Profile</a> page. Turning a section off hides it from view but does not delete any data.</p>
            </div>

            <form method="POST" action="{{ route('corex.settings.my-portal.update') }}" class="space-y-3">
                @csrf

                @php
                    $portalToggles = [
                        [
                            'name'  => 'portal_show_api_token',
                            'title' => 'API Token & CoreX Chrome Extension',
                            'desc'  => 'Used by the CoreX Chrome extension to authenticate with CoreX. Includes the generate-token button and extension download links.',
                            'value' => (bool) auth()->user()->portal_show_api_token,
                        ],
                        [
                            'name'  => 'portal_show_social_accounts',
                            'title' => 'Social Media Accounts',
                            'desc'  => 'Connect or disconnect your Facebook account for marketing posts.',
                            'value' => (bool) auth()->user()->portal_show_social_accounts,
                        ],
                    ];
                @endphp

                @foreach($portalToggles as $t)
                <label class="flex items-start justify-between gap-4 rounded-md p-4 cursor-pointer"
                       style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $t['title'] }}</div>
                        <div class="text-xs mt-1" style="color:var(--text-muted);">{{ $t['desc'] }}</div>
                    </div>
                    <span class="relative inline-flex flex-shrink-0" style="width:38px; height:22px;">
                        <input type="checkbox" name="{{ $t['name'] }}" value="1" {{ $t['value'] ? 'checked' : '' }}
                               class="peer sr-only">
                        <span class="absolute inset-0 rounded-full transition-colors peer-checked:bg-[var(--brand-button)]"
                              style="background:var(--border);"></span>
                        <span class="absolute top-0.5 left-0.5 w-[18px] h-[18px] rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
                    </span>
                </label>
                @endforeach

                <div class="pt-2">
                    <button type="submit" class="corex-btn-primary text-sm">Save Preferences</button>
                </div>
            </form>
        </div>

        <div x-show="activeSection === 'user'" x-cloak class="p-6 space-y-6">

            {{-- Links: User Mgmt + Roles --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Management</h3>
                <div class="space-y-2">
                    <a href="{{ route('admin.users') }}"
                       class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-green);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">User Management</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Activate, deactivate, or remove users</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                    <a href="{{ route('corex.role-manager') }}"
                       class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Role &amp; Permissions Manager</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Manage role-based access and user roles</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
            </div>

            {{-- FICA Officers — Primary CO + MLROs --}}
            @permission('manage_compliance_officer')
            @php
                $settingsAgencyId = auth()->user()->effectiveAgencyId();
                $agencyUsers = \App\Models\User::where('agency_id', $settingsAgencyId)
                    ->where('is_active', true)->whereNull('deleted_at')
                    ->orderBy('name')->get(['id', 'name', 'email', 'role', 'branch_id']);
                $currentPrimary = \App\Models\Compliance\FicaOfficerAppointment::currentPrimary($settingsAgencyId);
                $primaryHistory = \App\Models\Compliance\FicaOfficerAppointment::where('agency_id', $settingsAgencyId)
                    ->primary()->whereNotNull('ended_on')->orderByDesc('ended_on')->get();
                $activeMlros = \App\Models\Compliance\FicaOfficerAppointment::where('agency_id', $settingsAgencyId)
                    ->mlro()->active()->get();
                $activeMlroUserIds = $activeMlros->pluck('user_id')->filter()->toArray();
            @endphp

            {{-- Section A: Primary Compliance Officer --}}
            <div x-data="{ open: false }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <button type="button" @click="open = !open"
                        class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold transition-colors hover:opacity-80"
                        style="background:var(--surface-2); color:var(--text-primary);">
                    <span>Primary Compliance Officer (Section 43)</span>
                    <svg class="w-4 h-4 transition-transform duration-150" :class="open && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="p-4" style="border-top:1px solid var(--border); background:var(--surface);">
                    @if($currentPrimary)
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $currentPrimary->full_name }}</div>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                                {{ $currentPrimary->title }} — appointed {{ $currentPrimary->appointed_on->format('d M Y') }}
                                @if($currentPrimary->id_number) | ID: {{ $currentPrimary->id_number }} @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green); border-radius:6px;">Active</span>
                    </div>
                    @else
                    <div class="mb-3 px-3 py-2 text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); border-radius:6px; color: var(--ds-crimson);">
                        No primary compliance officer appointed. Appoint one to remain FICA compliant.
                    </div>
                    @endif

                    <div class="mb-2 px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); border-radius:6px; color: var(--text-primary);">
                        This person is named in RMCP Section 26 and registered with the FIC. Changing requires a new RMCP version to be issued.
                    </div>

                    <form method="POST" action="{{ route('corex.settings.fica-officers.primary') }}" enctype="multipart/form-data" class="space-y-3 mt-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Link to user</label>
                                <select name="user_id" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                                    <option value="">-- External person --</option>
                                    @foreach($agencyUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Full Name *</label>
                                <input type="text" name="full_name" required class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">ID Number</label>
                                <input type="text" name="id_number" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Cell</label>
                                <input type="text" name="cell" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Email</label>
                                <input type="email" name="email" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Appointed On *</label>
                                <input type="date" name="appointed_on" value="{{ now()->format('Y-m-d') }}" required class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Appointment Letter (PDF)</label>
                                <input type="file" name="appointment_letter" accept=".pdf" class="w-full px-2 py-1.5 text-xs border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Notes</label>
                            <textarea name="notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        </div>
                        <button type="submit" class="corex-btn-primary text-xs">Appoint Primary CO</button>
                    </form>

                    @if($primaryHistory->isNotEmpty())
                    <div x-data="{ showHistory: false }" class="mt-3">
                        <button @click="showHistory = !showHistory" class="text-xs font-semibold flex items-center gap-1" style="color:var(--text-muted);">
                            <svg class="w-3 h-3 transition-transform" :class="showHistory && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                            Previous appointments ({{ $primaryHistory->count() }})
                        </button>
                        <div x-show="showHistory" x-cloak class="mt-2 space-y-1">
                            @foreach($primaryHistory as $prev)
                            <div class="flex items-center justify-between px-2 py-1 text-xs" style="background:var(--surface); border-radius:6px;">
                                <span style="color:var(--text-primary);">{{ $prev->full_name }}</span>
                                <span style="color:var(--text-muted);">{{ $prev->appointed_on->format('d M Y') }} — {{ $prev->ended_on->format('d M Y') }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Section B: MLROs / Reporting Officers --}}
            <div x-data="{ open: false }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <button type="button" @click="open = !open"
                        class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold transition-colors hover:opacity-80"
                        style="background:var(--surface-2); color:var(--text-primary);">
                    <span>MLROs / Reporting Officers (PCC 5C)</span>
                    <svg class="w-4 h-4 transition-transform duration-150" :class="open && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="p-4" style="border-top:1px solid var(--border); background:var(--surface);">
                    <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Select users who can perform FICA compliance reviews and approvals</div>
                    <form method="POST" action="{{ route('corex.settings.fica-officers.mlros') }}">
                        @csrf
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3 rounded-md p-2" style="border:1px solid var(--border); background:var(--surface);">
                            @foreach($agencyUsers as $u)
                            <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-white/5 rounded">
                                <input type="checkbox" name="mlro_user_ids[]" value="{{ $u->id }}" {{ in_array($u->id, $activeMlroUserIds) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                                <span style="color:var(--text-primary);">{{ $u->name }}</span>
                                <span class="text-xs" style="color:var(--text-muted);">{{ $u->role }}</span>
                            </label>
                            @endforeach
                        </div>
                        <button type="submit" class="corex-btn-primary text-xs">Save MLROs</button>
                    </form>
                </div>
            </div>
            @endpermission

            {{-- Phase 9c-2: Information Officers (POPIA s55) — mirrors FICA pattern --}}
            @permission('manage_information_officer')
            @php
                $currentInfoPrimary = \App\Models\Compliance\InformationOfficerAppointment::currentPrimary($settingsAgencyId);
                $infoPrimaryHistory = \App\Models\Compliance\InformationOfficerAppointment::where('agency_id', $settingsAgencyId)
                    ->primary()->whereNotNull('ended_on')->orderByDesc('ended_on')->get();
                $activeDeputyIOs = \App\Models\Compliance\InformationOfficerAppointment::where('agency_id', $settingsAgencyId)
                    ->deputies()->active()->get();
                $activeDeputyIOUserIds = $activeDeputyIOs->pluck('user_id')->filter()->toArray();
            @endphp

            {{-- Primary Information Officer --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Primary Information Officer (POPIA s55)</h3>
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    @if($currentInfoPrimary)
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $currentInfoPrimary->full_name }}</div>
                            <div class="text-xs mt-0.5" style="color:var(--text-muted);">
                                {{ $currentInfoPrimary->title }} — appointed {{ $currentInfoPrimary->appointed_on->format('d M Y') }}
                                @if($currentInfoPrimary->id_number) | ID: {{ $currentInfoPrimary->id_number }} @endif
                            </div>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green); border-radius:6px;">Active</span>
                    </div>
                    @else
                    <div class="mb-3 px-3 py-2 text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); border-radius:6px; color: var(--ds-crimson);">
                        No Information Officer appointed. POPIA s55 requires every responsible party to designate an IO.
                    </div>
                    @endif

                    <div class="mb-2 px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); border-radius:6px; color: var(--text-primary);">
                        The Information Officer is the contact for all data-subject queries + Information Regulator submissions. Their details appear on the published privacy policy.
                    </div>

                    <form method="POST" action="{{ route('corex.settings.information-officers.primary') }}" enctype="multipart/form-data" class="space-y-3 mt-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Link to user</label>
                                <select name="user_id" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                                    <option value="">-- External person --</option>
                                    @foreach($agencyUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Full Name *</label>
                                <input type="text" name="full_name" required class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">ID Number</label>
                                <input type="text" name="id_number" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Cell</label>
                                <input type="text" name="cell" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Email</label>
                                <input type="email" name="email" class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Appointed On *</label>
                                <input type="date" name="appointed_on" value="{{ now()->format('Y-m-d') }}" required class="w-full px-2 py-1.5 text-sm border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Appointment Letter (PDF)</label>
                                <input type="file" name="appointment_letter" accept=".pdf" class="w-full px-2 py-1.5 text-xs border rounded" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Notes</label>
                            <textarea name="notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"></textarea>
                        </div>
                        <button type="submit" class="corex-btn-primary text-xs">Appoint Information Officer</button>
                    </form>

                    @if($infoPrimaryHistory->isNotEmpty())
                    <div x-data="{ showInfoHistory: false }" class="mt-3">
                        <button @click="showInfoHistory = !showInfoHistory" class="text-xs font-semibold flex items-center gap-1" style="color:var(--text-muted);">
                            <svg class="w-3 h-3 transition-transform" :class="showInfoHistory && 'rotate-90'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                            Previous appointments ({{ $infoPrimaryHistory->count() }})
                        </button>
                        <div x-show="showInfoHistory" x-cloak class="mt-2 space-y-1">
                            @foreach($infoPrimaryHistory as $prev)
                            <div class="flex items-center justify-between px-2 py-1 text-xs" style="background:var(--surface); border-radius:6px;">
                                <span style="color:var(--text-primary);">{{ $prev->full_name }}</span>
                                <span style="color:var(--text-muted);">{{ $prev->appointed_on->format('d M Y') }} — {{ $prev->ended_on->format('d M Y') }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Deputy Information Officers --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Deputy Information Officers (POPIA s56)</h3>
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Designate deputies who may handle POPIA requests when the primary IO is unavailable</div>
                    <form method="POST" action="{{ route('corex.settings.information-officers.deputies') }}">
                        @csrf
                        <div class="space-y-1 max-h-48 overflow-y-auto mb-3 rounded-md p-2" style="border:1px solid var(--border); background:var(--surface);">
                            @foreach($agencyUsers as $u)
                            <label class="flex items-center gap-2 py-1 px-1 text-sm cursor-pointer hover:bg-white/5 rounded">
                                <input type="checkbox" name="deputy_user_ids[]" value="{{ $u->id }}" {{ in_array($u->id, $activeDeputyIOUserIds) ? 'checked' : '' }} style="accent-color: var(--brand-button, #0ea5e9);">
                                <span style="color:var(--text-primary);">{{ $u->name }}</span>
                                <span class="text-xs" style="color:var(--text-muted);">{{ $u->role }}</span>
                            </label>
                            @endforeach
                        </div>
                        <button type="submit" class="corex-btn-primary text-xs">Save Deputy IOs</button>
                    </form>
                </div>
            </div>
            @endpermission

            {{-- Designations (inline) --}}
            @permission('manage_designations')
            <div x-data="{ open: false }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                <button type="button" @click="open = !open"
                        class="w-full flex items-center justify-between px-4 py-3 text-sm font-semibold transition-colors hover:opacity-80"
                        style="background:var(--surface-2); color:var(--text-primary);">
                    <span>Designations</span>
                    <svg class="w-4 h-4 transition-transform duration-150" :class="open && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
                <div x-show="open" x-cloak x-transition class="p-4" style="border-top:1px solid var(--border); background:var(--surface);">

                {{-- Add designation --}}
                <div class="p-4 rounded-md mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Designation</div>
                    <form method="POST" action="{{ url('/admin/designations') }}"
                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        <div class="md:col-span-6">
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                            <input name="name" required placeholder="e.g. Property Practitioner"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                   class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" checked class="rounded">
                            <span class="text-sm" style="color:var(--text-secondary);">Enabled</span>
                        </div>
                        <div class="md:col-span-1">
                            <button class="w-full corex-btn-primary text-sm">Add</button>
                        </div>
                    </form>
                </div>

                {{-- Designations list --}}
                <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Designations</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ count($designations) }} total</div>
                    </div>
                    <div>
                        @forelse($designations as $d)
                        <div class="p-4" style="border-bottom:1px solid var(--border);">
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf
                                <div class="md:col-span-6">
                                    <input name="name" value="{{ $d->name }}" required
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-3">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$d->sort_order }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2 flex items-center gap-2">
                                    <input type="hidden" name="is_enabled" value="0">
                                    <input type="checkbox" name="is_enabled" value="1" {{ $d->is_enabled ? 'checked' : '' }} class="rounded">
                                    <span class="text-sm" style="color:var(--text-secondary);">Enabled</span>
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id.'/delete') }}"
                                  onsubmit="return confirm('Delete this designation?');" class="mt-2">
                                @csrf
                                <button class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:var(--text-muted);">No designations yet.</div>
                        @endforelse
                    </div>
                </div>
                </div>
            </div>
            @endpermission

        </div>

        {{-- ============================================================
             FEATURE SETTINGS TAB
             Contains: Documents (Docuperfect), Rentals
             ============================================================ --}}
        {{-- Feature sections — each one shows when its rail key is active --}}
        <div class="p-6 space-y-8"
             x-show="activeSection.startsWith('feature-')" x-cloak>

            {{-- DOCUMENTS section --}}
            <div x-show="activeSection === 'feature-documents'" x-cloak class="space-y-6">

                {{-- Named Fields --}}
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Named Fields</h3>
                    <div class="p-4 rounded-md mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Named Field</div>
                        <form method="POST" action="{{ route('docuperfect.settings.namedFields.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-4">
                                <input name="name" required placeholder="e.g. Seller Name"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <select name="field_type"
                                        class="w-full rounded-md px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="text">Text</option>
                                    <option value="date">Date</option>
                                    <option value="selection">Selection</option>
                                </select>
                            </div>
                            <div class="md:col-span-3">
                                <input name="default_options" placeholder="Options (comma-separated)"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <input name="sort_order" type="number" step="1" min="0" placeholder="Sort order"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-1">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Named Fields</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ count($namedFields) }} total</div>
                        </div>
                        @forelse($namedFields as $field)
                        <div class="p-4" style="border-bottom:1px solid var(--border);">
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.update', $field->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf @method('PUT')
                                <div class="md:col-span-4">
                                    <input name="name" value="{{ $field->name }}" required
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2">
                                    <select name="field_type"
                                            class="w-full rounded-md px-3 py-2 text-sm"
                                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <option value="text" {{ $field->field_type === 'text' ? 'selected' : '' }}>Text</option>
                                        <option value="date" {{ $field->field_type === 'date' ? 'selected' : '' }}>Date</option>
                                        <option value="selection" {{ $field->field_type === 'selection' ? 'selected' : '' }}>Selection</option>
                                    </select>
                                </div>
                                <div class="md:col-span-3">
                                    <input name="default_options"
                                           value="{{ is_array($field->default_options) ? implode(', ', $field->default_options) : '' }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$field->sort_order }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.destroy', $field->id) }}"
                                  onsubmit="return confirm('Delete this named field?');" class="mt-2">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:var(--text-muted);">No named fields yet.</div>
                        @endforelse
                    </div>
                </div>

            </div>{{-- /documents --}}

            {{-- RENTALS section --}}
            <div x-show="activeSection === 'feature-rentals'" x-cloak class="space-y-6">

                {{-- Rental Properties link (has sub-pages) --}}
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Properties</h3>
                    <a href="{{ route('rental.settings.properties.index') }}"
                       class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-green);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Rental Properties</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Add and manage rental property listings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

                {{-- Rental Document Types (inline) --}}
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Rental Document Types</h3>
                    <div class="space-y-2 mb-3" x-data="{ showAdd: false, editId: null }">
                        @foreach($rentalDocTypes as $rType)
                        <div class="flex items-center justify-between p-3 rounded-md {{ !$rType->is_active ? 'opacity-50' : '' }}"
                             style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $rType->color }}"></span>
                                <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $rType->name }}</span>
                                @if($rType->is_system)<span class="text-xs ml-1" style="color:var(--text-muted);">(system)</span>@endif
                                @if($rType->is_lease)<span class="ds-badge ds-badge-success ml-2">Lease</span>@endif
                                @if(!$rType->is_active)<span class="ds-badge ds-badge-default ml-2">Inactive</span>@endif
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="editId = editId === {{ $rType->id }} ? null : {{ $rType->id }}"
                                        class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">Edit</button>
                                @if(!$rType->is_system)
                                <form method="POST" action="{{ route('rental.settings.document-types.toggle', $rType) }}">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold" style="color: {{ $rType->is_active ? 'var(--ds-amber)' : 'var(--ds-green)' }};">
                                        {{ $rType->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        {{-- Inline edit --}}
                        <div x-show="editId === {{ $rType->id }}" x-cloak class="rounded-md p-3"
                             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 20%, transparent);">
                            <form method="POST" action="{{ route('rental.settings.document-types.update', $rType) }}"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf @method('PUT')
                                <div class="flex-1 min-w-[180px]">
                                    <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                    <input type="text" name="name" value="{{ $rType->name }}" required
                                           class="w-full rounded px-3 py-1.5 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="w-20">
                                    <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                    <input type="color" name="color" value="{{ $rType->color }}"
                                           class="w-full h-8 rounded cursor-pointer border"
                                           style="border-color:var(--border);">
                                </div>
                                <label class="flex items-center gap-1.5 text-sm" style="color:var(--text-secondary);">
                                    <input type="checkbox" name="is_lease" value="1" {{ $rType->is_lease ? 'checked' : '' }} class="rounded">
                                    Lease
                                </label>
                                <button type="submit" class="corex-btn-primary text-sm">Save</button>
                                <button type="button" @click="editId = null" class="corex-btn-outline text-sm">Cancel</button>
                            </form>
                        </div>
                        @endforeach

                        {{-- Add new --}}
                        <div class="mt-2">
                            <button @click="showAdd = !showAdd"
                                    class="text-sm font-semibold" style="color: var(--brand-icon, #0ea5e9);">+ Add Document Type</button>
                            <div x-show="showAdd" x-cloak class="rounded-md p-3 mt-2"
                                 style="background: color-mix(in srgb, var(--ds-green) 6%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 20%, transparent);">
                                <form method="POST" action="{{ route('rental.settings.document-types.store') }}"
                                      class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="flex-1 min-w-[180px]">
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Name *</label>
                                        <input type="text" name="name" required placeholder="e.g. Deposit Receipt"
                                               class="w-full rounded px-3 py-1.5 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                        <input type="color" name="color" value="#6B7280" class="w-full h-8 rounded cursor-pointer border"
                                               style="border-color:var(--border);">
                                    </div>
                                    <label class="flex items-center gap-1.5 text-sm" style="color:var(--text-secondary);">
                                        <input type="checkbox" name="is_lease" value="1" class="rounded">
                                        Lease
                                    </label>
                                    <button type="submit" class="corex-btn-primary text-sm">Add</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Rental Reminders (inline) --}}
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted);">Email Reminders</h3>
                    <form method="POST" action="{{ route('rental.settings.reminders.update') }}"
                          x-data="{
                              mode: '{{ old('mode', $rentalReminderSettings->mode) }}',
                              enabled: {{ old('enabled', $rentalReminderSettings->enabled) ? 'true' : 'false' }}
                          }"
                          class="space-y-4">
                        @csrf @method('PUT')

                        <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Automatic Reminders</div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Send automatic email reminders for unsigned documents</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only peer" {{ $rentalReminderSettings->enabled ? 'checked' : '' }}>
                                    <div class="w-10 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"
                                         style="background:var(--border-hover);"
                                         :style="enabled ? 'background:var(--brand-button, #0ea5e9)' : 'background:var(--border-hover)'"></div>
                                </label>
                            </div>
                        </div>

                        <div x-show="enabled" x-cloak class="space-y-4">
                            <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Reminder Mode</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <label :style="mode === 'escalating' ? 'border-color:var(--brand-button, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent);' : 'border-color:var(--border); background:var(--surface);'"
                                           class="border rounded-md p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="escalating" x-model="mode" class="sr-only">
                                        <div class="font-medium text-sm" style="color:var(--text-primary);">Escalating</div>
                                        <div class="text-xs mt-1" style="color:var(--text-secondary);">Gentle → Firm → Team Alert → Final</div>
                                    </label>
                                    <label :style="mode === 'simple' ? 'border-color:var(--brand-button, #0ea5e9); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent);' : 'border-color:var(--border); background:var(--surface);'"
                                           class="border rounded-md p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="simple" x-model="mode" class="sr-only">
                                        <div class="font-medium text-sm" style="color:var(--text-primary);">Simple Interval</div>
                                        <div class="text-xs mt-1" style="color:var(--text-secondary);">Same reminder every N days</div>
                                    </label>
                                </div>
                            </div>

                            <div x-show="mode === 'escalating'" x-cloak class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Escalation Schedule</div>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach([
                                        ['key'=>'gentle_after_days','label'=>'Gentle reminder after (days)'],
                                        ['key'=>'firm_after_days','label'=>'Firm reminder after (days)'],
                                        ['key'=>'team_alert_after_days','label'=>'Team alert after (days)'],
                                        ['key'=>'final_after_days','label'=>'Final reminder after (days)'],
                                    ] as $rf)
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">{{ $rf['label'] }}</label>
                                        <input type="number" name="{{ $rf['key'] }}"
                                               value="{{ old($rf['key'], $rentalReminderSettings->{$rf['key']}) }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    @endforeach
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Max reminders per signer</label>
                                        <input type="number" name="max_escalating_reminders"
                                               value="{{ old('max_escalating_reminders', $rentalReminderSettings->max_escalating_reminders) }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                </div>
                            </div>

                            <div x-show="mode === 'simple'" x-cloak class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Simple Interval</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Send every (days)</label>
                                        <input type="number" name="interval_days"
                                               value="{{ old('interval_days', $rentalReminderSettings->interval_days) }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Max reminders per signer</label>
                                        <input type="number" name="max_simple_reminders"
                                               value="{{ old('max_simple_reminders', $rentalReminderSettings->max_simple_reminders) }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Custom Email Template</div>
                                <div class="flex flex-wrap gap-1 text-xs">
                                    @foreach(['{signer_name}','{document_name}','{agent_name}','{signing_link}','{days_waiting}'] as $ph)
                                    <code class="px-1.5 py-0.5 rounded font-mono" style="background:rgba(0,0,0,0.05); color:var(--text-secondary);">{{ $ph }}</code>
                                    @endforeach
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Subject</label>
                                    <input type="text" name="email_subject"
                                           value="{{ old('email_subject', $rentalReminderSettings->email_subject) }}"
                                           placeholder="e.g. Reminder: Please sign {document_name}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Body</label>
                                    <textarea name="email_body" rows="5"
                                              class="w-full rounded-md px-3 py-2 text-sm"
                                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('email_body', $rentalReminderSettings->email_body) }}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <button type="submit" class="corex-btn-primary text-sm">Save Reminder Settings</button>
                            @if($rentalReminderSettings->updatedByUser ?? null)
                            <span class="text-xs" style="color:var(--text-muted);">
                                Last updated by {{ $rentalReminderSettings->updatedByUser->name }}
                                on {{ $rentalReminderSettings->updated_at->format('d M Y H:i') }}
                            </span>
                            @endif
                        </div>
                    </form>
                </div>

            </div>{{-- /rentals --}}

            {{-- CONTACTS section --}}
            <div x-show="activeSection === 'feature-contacts'" x-cloak class="space-y-3">

                {{-- Contacts per page --}}
                <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Contacts per page</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">How many contacts to show on each page of the Contacts list before paging to the next. Any number from 1 to 200.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.contacts-per-page') }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="number" name="contacts_per_page" min="1" max="200" step="1" required
                               value="{{ old('contacts_per_page', $contactsPerPage) }}"
                               class="w-24 rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Save</button>
                    </form>
                </div>

                {{-- ── Contact Types (accordion) ── --}}
                <div x-data="{ open: false }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-3 transition-colors"
                            style="background:var(--surface-2);"
                            onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent)'" onmouseout="this.style.background='var(--surface-2)'">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);">Contact Types</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">{{ count($contactTypes) }}</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">
                    <div class="p-4 space-y-4">
                    <p class="text-xs" style="color:var(--text-muted);">The four signing roles are fixed. Add custom <span style="color:var(--text-secondary);">sub-tags</span> under each to categorise contacts — they flow into the contact form and e-sign.</p>

                    {{-- Four fixed parents, each with nested sub-tags --}}
                    <div class="space-y-3">
                        @foreach($contactTypes as $parent)
                        <div x-data="{ openP: false, addOpen: false, editTagId: null }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                            <button type="button" @click="openP = !openP"
                                    class="w-full flex items-center justify-between px-4 py-3 transition-colors"
                                    style="background:var(--surface-2);">
                                <div class="flex items-center gap-3">
                                    <span class="w-3.5 h-3.5 rounded-full flex-shrink-0" style="background-color: {{ $parent->color }}"></span>
                                    <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $parent->name }}</span>
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ ucfirst($parent->esign_role) }}</span>
                                    <span class="text-xs" style="color:var(--text-muted);">{{ $parent->subTags->count() }} sub-tag{{ $parent->subTags->count() !== 1 ? 's' : '' }}</span>
                                </div>
                                <svg class="w-4 h-4 transition-transform" :class="openP ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="openP" x-cloak style="border-top:1px solid var(--border);" class="p-4 space-y-2">
                                {{-- Sub-tag list --}}
                                @forelse($parent->subTags as $tag)
                                <div style="border-bottom:1px solid var(--border);">
                                    <div x-show="editTagId !== {{ $tag->id }}" class="py-2 flex items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $tag->color }}"></span>
                                            <span class="text-sm" style="color:var(--text-primary);">{{ $tag->name }}</span>
                                            <span class="text-xs" style="color:var(--text-muted);">{{ $tag->contacts()->count() }} contact{{ $tag->contacts()->count() !== 1 ? 's' : '' }}</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button type="button" @click="editTagId = {{ $tag->id }}" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">Edit</button>
                                            <form method="POST" action="{{ route('corex.settings.contact-tags.destroy', $tag) }}" onsubmit="return confirm('Delete this sub-tag?');">
                                                @csrf @method('DELETE')
                                                <button class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                    <div x-show="editTagId === {{ $tag->id }}" x-cloak class="py-3">
                                        <form method="POST" action="{{ route('corex.settings.contact-tags.update', $tag) }}" class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="contact_type_id" value="{{ $parent->id }}">
                                            <div class="md:col-span-6">
                                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                                <input name="name" value="{{ $tag->name }}" required class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                                <input type="color" name="color" value="{{ $tag->color }}" class="w-full h-9 rounded-md cursor-pointer border" style="border-color:var(--border); background:var(--surface);">
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort</label>
                                                <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$tag->sort_order }}" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            </div>
                                            <div class="md:col-span-2 flex gap-2">
                                                <button type="submit" class="flex-1 corex-btn-primary text-sm">Save</button>
                                                <button type="button" @click="editTagId = null" class="flex-1 text-sm rounded-md" style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                @empty
                                <p class="text-xs py-1" style="color:var(--text-muted);">No sub-tags under {{ $parent->name }} yet.</p>
                                @endforelse

                                {{-- Add sub-tag --}}
                                <div class="pt-1">
                                    <button type="button" x-show="!addOpen" @click="addOpen = true" class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);">+ Add sub-tag</button>
                                    <form x-show="addOpen" x-cloak method="POST" action="{{ route('corex.settings.contact-tags.store') }}" class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end mt-2">
                                        @csrf
                                        <input type="hidden" name="contact_type_id" value="{{ $parent->id }}">
                                        <div class="md:col-span-6">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sub-tag name</label>
                                            <input name="name" required placeholder="e.g. Cash seller, First-time buyer" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                            <input type="color" name="color" value="{{ $parent->color }}" class="w-full h-9 rounded-md cursor-pointer border" style="border-color:var(--border); background:var(--surface);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort</label>
                                            <input name="sort_order" type="number" step="1" min="0" placeholder="0" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2 flex gap-2">
                                            <button type="submit" class="flex-1 corex-btn-primary text-sm">Add</button>
                                            <button type="button" @click="addOpen = false" class="flex-1 text-sm rounded-md" style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    {{-- Legacy tags awaiting a parent (re-home so nothing is lost) --}}
                    @if(isset($unassignedTags) && $unassignedTags->isNotEmpty())
                    <div class="p-4 rounded-md mt-3" style="background: color-mix(in srgb, var(--ds-amber) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
                        @php $unsortedTotal = $unassignedTagsCount ?? $unassignedTags->count(); @endphp
                        <div class="text-xs font-semibold mb-1" style="color:var(--text-primary);">Unsorted tags — assign each to a signing role <span style="color:var(--text-muted); font-weight:400;">({{ $unsortedTotal }} total)</span></div>
                        @if($unsortedTotal > $unassignedTags->count())
                        <p class="text-[11px] mb-2" style="color:var(--text-muted);">Showing the first {{ $unassignedTags->count() }}. Run <code>php artisan contacts:normalise-types</code> to auto-sort the rest under their closest signing role.</p>
                        @endif
                        @foreach($unassignedTags as $tag)
                        <form method="POST" action="{{ route('corex.settings.contact-tags.update', $tag) }}" class="flex items-center gap-2 mb-2">
                            @csrf @method('PUT')
                            <input type="hidden" name="name" value="{{ $tag->name }}">
                            <input type="hidden" name="color" value="{{ $tag->color }}">
                            <span class="text-sm flex-1" style="color:var(--text-primary);">{{ $tag->name }}</span>
                            <select name="contact_type_id" required class="rounded-md px-2 py-1 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="">— role —</option>
                                @foreach($contactTypes as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="corex-btn-primary text-xs">Save</button>
                        </form>
                        @endforeach
                    </div>
                    @endif
                </div>
                    </div>{{-- /x-show open (Contact Types) --}}
                </div>{{-- /x-data accordion (Contact Types) --}}

                {{-- ── Contact Sources (accordion) ── --}}
                <div x-data="{ open: false }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-3 transition-colors"
                            style="background:var(--surface-2);"
                            onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent)'" onmouseout="this.style.background='var(--surface-2)'">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);">Contact Sources</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">{{ count($contactSources) }}</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">
                    <div class="p-4 space-y-4">
                    <p class="text-xs" style="color:var(--text-muted);">Sources track where contacts come from (e.g. Property24, Walk-in, Referral). New sources are auto-created during imports.</p>

                    {{-- Add Source --}}
                    <div class="p-4 rounded-md mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Contact Source</div>
                        <form method="POST" action="{{ route('corex.settings.contact-sources.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-6">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                <input name="name" required placeholder="e.g. Property24, Referral, Walk-in"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                <input type="color" name="color" value="#6366f1"
                                       class="w-full h-9 rounded-md cursor-pointer border"
                                       style="border-color:var(--border); background:var(--surface);">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                                <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>

                    {{-- Sources list --}}
                    <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Sources</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ count($contactSources) }} total</div>
                        </div>
                        <div x-data="{ editCSId: null }">
                            @forelse($contactSources as $cSource)
                            <div style="border-bottom:1px solid var(--border);">
                                <div x-show="editCSId !== {{ $cSource->id }}"
                                     class="p-4 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-4 h-4 rounded-full flex-shrink-0"
                                              style="background-color: {{ $cSource->color }}"></span>
                                        <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $cSource->name }}</span>
                                        <span class="text-xs" style="color:var(--text-muted);">{{ $cSource->contacts()->count() }} contact{{ $cSource->contacts()->count() !== 1 ? 's' : '' }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button @click="editCSId = {{ $cSource->id }}"
                                                class="text-xs font-semibold font-semibold" style="color: var(--brand-icon, #0ea5e9);">Edit</button>
                                        <form method="POST" action="{{ route('corex.settings.contact-sources.destroy', $cSource) }}"
                                              onsubmit="return confirm('Delete this contact source?');">
                                            @csrf @method('DELETE')
                                            <button class="text-xs font-semibold" style="color: var(--ds-crimson);">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div x-show="editCSId === {{ $cSource->id }}" x-cloak
                                     class="p-4" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent); border-top:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent);">
                                    <form method="POST" action="{{ route('corex.settings.contact-sources.update', $cSource) }}"
                                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                        @csrf @method('PUT')
                                        <div class="md:col-span-6">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                            <input name="name" value="{{ $cSource->name }}" required
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                            <input type="color" name="color" value="{{ $cSource->color }}"
                                                   class="w-full h-9 rounded-md cursor-pointer border"
                                                   style="border-color:var(--border); background:var(--surface);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$cSource->sort_order }}"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2 flex gap-2">
                                            <button type="submit" class="flex-1 corex-btn-primary text-sm">Save</button>
                                            <button type="button" @click="editCSId = null"
                                                    class="flex-1 text-sm rounded-md"
                                                    style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @empty
                            <div class="p-5 text-sm" style="color:var(--text-muted);">No contact sources yet. Add one above or import contacts to auto-create them.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
                    </div>{{-- /x-show open (Contact Sources) --}}
                </div>{{-- /x-data accordion (Contact Sources) --}}

            </div>{{-- /contacts --}}

            {{-- PROPERTIES section --}}
            <div x-show="activeSection === 'feature-properties'" x-cloak class="space-y-3">

                @php
                $propGroups = [
                    ['key' => 'category',        'label' => 'Categories',        'items' => $propCategories,   'placeholder' => 'e.g. Residential, Commercial'],
                    ['key' => 'property_type',   'label' => 'Property Types',    'items' => $propTypes,        'placeholder' => 'e.g. House, Flat, Townhouse'],
                    ['key' => 'property_status', 'label' => 'Property Statuses', 'items' => $propStatuses,     'placeholder' => 'e.g. Active, Draft, Sold'],
                    ['key' => 'mandate_type',    'label' => 'Mandate Types',     'items' => $propMandateTypes, 'placeholder' => 'e.g. Sole, Joint, Open'],
                    // Build 3 — condition levels with adjustment_pct.
                    ['key' => 'condition_level', 'label' => 'Condition Levels',  'items' => $propConditionLevels ?? collect(), 'placeholder' => 'e.g. To Renovate, Excellent'],
                ];
                $conditionBaselineName = \App\Models\PropertySettingItem::CONDITION_BASELINE_NAME;
                $reorderUrl  = route('corex.settings.property-items.reorder');
                $itemBaseUrl = url('corex/settings/property-items');
                $csrfToken   = csrf_token();
                @endphp

                {{-- Properties per page --}}
                <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Properties per page</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">How many properties to show on each page of the Properties list before paging to the next. Any number from 1 to 200.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.properties-per-page') }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        <input type="number" name="properties_per_page" min="1" max="200" step="1" required
                               value="{{ old('properties_per_page', $propertiesPerPage) }}"
                               class="w-24 rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Save</button>
                    </form>
                </div>

                {{-- Marketing Toggle --}}
                <div class="p-4 rounded-md flex items-center justify-between gap-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Property Marketing</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">When disabled, the "Market Property" button will not appear on property pages.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.marketing-enabled') }}" class="flex items-center gap-3 flex-shrink-0">
                        @csrf
                        <input type="hidden" name="marketing_enabled" value="0">
                        <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;"
                               title="{{ $marketingEnabled ? 'Enabled — click to disable' : 'Disabled — click to enable' }}">
                            <input type="checkbox" name="marketing_enabled" value="1"
                                   {{ $marketingEnabled ? 'checked' : '' }}
                                   class="sr-only"
                                   onchange="this.closest('form').submit()">
                            <span class="block w-full h-full rounded-full transition-colors duration-200"
                                  style="background:{{ $marketingEnabled ? 'var(--brand-button, #0ea5e9)' : 'var(--border-hover)' }}"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                  style="transform:translateX({{ $marketingEnabled ? '20px' : '0' }})"></span>
                        </label>
                        <span class="text-sm font-semibold" style="color:{{ $marketingEnabled ? 'var(--brand-button, #0ea5e9)' : 'var(--text-muted)' }};">
                            {{ $marketingEnabled ? 'On' : 'Off' }}
                        </span>
                    </form>
                </div>

                {{-- Syndication portals — controls which portals appear in the property syndication panel --}}
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="mb-3">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Syndication Portals</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Choose which portals appear in the Syndication panel on each property. Disabled portals are hidden everywhere. Agency websites are managed per-key in Admin → Agencies → API Access.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.syndication-portals') }}" class="space-y-2"
                          x-data="{
                            pp:  {{ $syndicationPpEnabled      ? 'true' : 'false' }},
                            p24: {{ $syndicationP24Enabled     ? 'true' : 'false' }},
                            submit() { this.$refs.frm.submit(); }
                          }" x-ref="frm">
                        @csrf
                        <input type="hidden" name="syndication_pp_enabled"      :value="pp  ? 1 : 0">
                        <input type="hidden" name="syndication_p24_enabled"     :value="p24 ? 1 : 0">

                        @foreach([
                            ['key' => 'pp',  'label' => 'Private Property', 'desc' => 'Submit listings to Private Property'],
                            ['key' => 'p24', 'label' => 'Property24',       'desc' => 'Submit listings to Property24'],
                        ] as $row)
                        <div class="flex items-center justify-between gap-3 px-3 py-2 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
                            <div>
                                <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $row['label'] }}</div>
                                <div class="text-xs" style="color:var(--text-muted);">{{ $row['desc'] }}</div>
                            </div>
                            <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;">
                                <input type="checkbox" class="sr-only" x-model="{{ $row['key'] }}" @change="submit()">
                                <span class="block w-full h-full rounded-full transition-colors duration-200"
                                      :style="{{ $row['key'] }} ? 'background:var(--brand-button, #0ea5e9)' : 'background:var(--border-hover)'"></span>
                                <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                      :style="{{ $row['key'] }} ? 'transform:translateX(20px)' : 'transform:translateX(0)'"></span>
                            </label>
                        </div>
                        @endforeach
                    </form>
                </div>

                @foreach($propGroups as $pg)
                @php
                    $defaultItems    = $pg['items']->where('is_default', true)->values();
                    $customItems     = $pg['items']->where('is_default', false)->values();
                    $hasDefaults     = $defaultItems->isNotEmpty();
                    $totalCount      = $pg['items']->count();
                    $batchToggleUrl  = route('corex.settings.property-items.batch-toggle', $pg['key']);

                    // Build 1 — title_type only meaningful on category rows; we
                    // still serialise the field for every group so the Alpine
                    // template can render uniformly, then hide the dropdown
                    // when pg.key !== 'category' below.
                    $defsJson = $defaultItems->map(fn($i) => [
                        'id'             => $i->id,
                        'name'           => $i->name,
                        'sort_order'     => (int)$i->sort_order,
                        'active'         => (bool)$i->active,
                        'title_type'     => $i->title_type ?? 'other',
                        // Build 3 — condition rows carry adjustment_pct;
                        // for other groups this is null and the UI hides it.
                        'adjustment_pct' => $i->adjustment_pct !== null ? (float)$i->adjustment_pct : null,
                    ])->toJson();
                    $custsJson = $customItems->map(fn($i) => [
                        'id'             => $i->id,
                        'name'           => $i->name,
                        'sort_order'     => (int)$i->sort_order,
                        'title_type'     => $i->title_type ?? 'other',
                        'adjustment_pct' => $i->adjustment_pct !== null ? (float)$i->adjustment_pct : null,
                    ])->toJson();
                    $isCategoryGroup  = $pg['key'] === 'category';
                    $isConditionGroup = $pg['key'] === 'condition_level';
                    $titleTypeOptions = \App\Models\PropertySettingItem::TITLE_TYPES;
                @endphp

                <div x-data="{
                    open: false, addOpen: false,
                    editId: null, editName: '', editSort: 0,
                    defs:  {{ $defsJson }},
                    custs: {{ $custsJson }},
                    dragFrom: null, dragTarget: null,
                    reorderUrl:     '{{ $reorderUrl }}',
                    batchToggleUrl: '{{ $batchToggleUrl }}',
                    itemBaseUrl:    '{{ $itemBaseUrl }}',
                    csrf:           '{{ $csrfToken }}',
                    startDrag(idx, list) { this.dragFrom = { idx, list }; },
                    onOver(idx, list)    { if (this.dragFrom?.list === list) this.dragTarget = { idx, list }; },
                    drop(toIdx, list) {
                        if (!this.dragFrom || this.dragFrom.list !== list) { this.resetDrag(); return; }
                        const arr = list === 'd' ? this.defs : this.custs;
                        const fromIdx = this.dragFrom.idx;
                        if (fromIdx === toIdx) { this.resetDrag(); return; }
                        const a = [...arr];
                        const [m] = a.splice(fromIdx, 1);
                        a.splice(toIdx, 0, m);
                        list === 'd' ? (this.defs = a) : (this.custs = a);
                        this.resetDrag();
                        fetch(this.reorderUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            body: JSON.stringify({ items: a.map((it, i) => ({ id: it.id, sort_order: i })) })
                        });
                    },
                    resetDrag() { this.dragFrom = null; this.dragTarget = null; },
                    editTitleType: 'other',
                    editAdjustmentPct: 0,
                    startEdit(item) {
                        this.editId = item.id; this.editName = item.name; this.editSort = item.sort_order;
                        this.editTitleType = item.title_type || 'other';
                        this.editAdjustmentPct = (item.adjustment_pct === null || item.adjustment_pct === undefined) ? 0 : item.adjustment_pct;
                    },
                    saveDefaults() {
                        const f = document.createElement('form');
                        f.method = 'POST'; f.action = this.batchToggleUrl;
                        const t = document.createElement('input'); t.type='hidden'; t.name='_token'; t.value=this.csrf; f.appendChild(t);
                        this.defs.filter(d => d.active).forEach(d => {
                            const i = document.createElement('input'); i.type='hidden'; i.name='enabled_ids[]'; i.value=d.id; f.appendChild(i);
                        });
                        document.body.appendChild(f); f.submit();
                    },
                    isDragTarget(idx, list) {
                        return this.dragTarget?.idx === idx && this.dragTarget?.list === list && this.dragFrom?.list === list && this.dragFrom?.idx !== idx;
                    }
                }" class="rounded-md overflow-hidden" style="border:1px solid var(--border);">

                    {{-- Accordion header --}}
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-3 transition-colors"
                            style="background:var(--surface-2);"
                            onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent)'"
                            onmouseout="this.style.background='var(--surface-2)'">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $pg['label'] }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">{{ $totalCount }}</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>

                    {{-- Panel --}}
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">

                        {{-- Add New --}}
                        <div style="border-bottom:1px solid var(--border);">
                            <button type="button" @click="addOpen = !addOpen"
                                    class="w-full flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors"
                                    style="color: var(--brand-icon, #0ea5e9); background:var(--surface);"
                                    onmouseover="this.style.background='color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent)'"
                                    onmouseout="this.style.background='var(--surface)'">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                                Add New
                                <svg class="w-3.5 h-3.5 ml-auto transition-transform" :class="addOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="addOpen" x-cloak class="px-4 pb-4 pt-3" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 3%, transparent); border-top:1px solid var(--border);">
                                @if($isConditionGroup)
                                {{-- Build 3 — agency-configurable condition adjustments. --}}
                                <div class="px-1 pb-3 text-xs" style="color:var(--text-muted);">
                                    Adjustments compound on the CMA Middle band when an agent selects this condition.
                                    Defaults mirror industry CMA conventions — adjust to match your market.
                                    The <strong>{{ $conditionBaselineName }}</strong> baseline is locked at 0% and cannot be deleted.
                                </div>
                                @endif
                                <form method="POST" action="{{ route('corex.settings.property-items.store') }}"
                                      class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                    @csrf
                                    <input type="hidden" name="group" value="{{ $pg['key'] }}">
                                    <div class="md:col-span-{{ ($isCategoryGroup || $isConditionGroup) ? '5' : '7' }}">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Name</label>
                                        <input name="name" required placeholder="{{ $pg['placeholder'] }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    @if($isCategoryGroup)
                                    {{-- Build 1 — title_type discipline. See
                                         .ai/specs/presentation-data-lineage.md §3-A. --}}
                                    <div class="md:col-span-3" title="Drives comp-selection — houses won't be compared to apartments.">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Title Type</label>
                                        <select name="title_type"
                                                class="w-full rounded-md px-3 py-2 text-sm"
                                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            @foreach($titleTypeOptions as $tt => $ttLabel)
                                                <option value="{{ $tt }}" {{ $tt === 'other' ? 'selected' : '' }}>{{ $ttLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @endif
                                    @if($isConditionGroup)
                                    <div class="md:col-span-3" title="% applied to the CMA Middle band when this condition is selected.">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Adjustment %</label>
                                        <input name="adjustment_pct" type="number" step="0.01" min="-99.99" max="200" required placeholder="e.g. 12.00"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    @endif
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Sort Order</label>
                                        <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div class="md:col-span-2">
                                        <button class="w-full corex-btn-primary text-sm">Add</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- Defaults list --}}
                        @if($hasDefaults)
                        <template x-for="(item, idx) in defs" :key="item.id">
                            <div :style="isDragTarget(idx,'d') ? 'border-top:2px solid var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent);' : 'border-bottom:1px solid var(--border); background:var(--surface);'"
                                 @dragover.prevent="onOver(idx,'d')"
                                 @drop.prevent="drop(idx,'d')"
                                 @dragleave="dragTarget=null">
                                <div class="flex items-center gap-3 px-4 py-2.5 transition-opacity"
                                     :class="dragFrom?.idx===idx && dragFrom?.list==='d' ? 'opacity-30' : ''"
                                     draggable="true"
                                     @dragstart="startDrag(idx,'d')"
                                     @dragend="resetDrag()">
                                    {{-- Drag handle --}}
                                    <span class="cursor-grab flex-shrink-0" style="color:var(--text-muted);">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="7" cy="4" r="1.5"/><circle cx="13" cy="4" r="1.5"/><circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/><circle cx="7" cy="16" r="1.5"/><circle cx="13" cy="16" r="1.5"/></svg>
                                    </span>
                                    {{-- Toggle switch --}}
                                    <label class="relative flex-shrink-0 cursor-pointer" style="width:36px; height:20px; display:block;">
                                        <input type="checkbox" :checked="item.active" @change="item.active = !item.active" class="sr-only">
                                        <span class="block w-full h-full rounded-full transition-colors duration-200"
                                              :style="item.active ? 'background: var(--brand-button, #0ea5e9)' : 'background:var(--border-hover)'"></span>
                                        <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-all duration-200"
                                              :style="item.active ? 'transform:translateX(16px)' : 'transform:translateX(0)'"></span>
                                    </label>
                                    <span class="flex-1 text-sm font-medium" x-text="item.name" style="color:var(--text-primary);"></span>
                                    @if($isCategoryGroup)
                                    {{-- Build 1 — inline title_type select. Persists on change via
                                         a small fetch PATCH. Reload is via the next page render. --}}
                                    <select :value="item.title_type"
                                            @change="
                                                item.title_type = $event.target.value;
                                                fetch(itemBaseUrl + '/' + item.id, {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf },
                                                    body: '_method=PUT&name=' + encodeURIComponent(item.name) + '&sort_order=' + item.sort_order + '&title_type=' + item.title_type,
                                                });"
                                            class="text-xs rounded px-2 py-1 flex-shrink-0"
                                            style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                        @foreach($titleTypeOptions as $tt => $ttLabel)
                                            <option value="{{ $tt }}">{{ $ttLabel }}</option>
                                        @endforeach
                                    </select>
                                    @endif
                                    @if($isConditionGroup)
                                    {{-- Build 3 — inline adjustment_pct numeric input. The Average
                                         baseline is locked at 0 (controller enforces). --}}
                                    <span x-show="item.name === '{{ $conditionBaselineName }}'"
                                          class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded flex-shrink-0"
                                          style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent); color: var(--brand-icon, #0ea5e9);">
                                        Baseline
                                    </span>
                                    <input type="number" step="0.01" min="-99.99" max="200"
                                           :value="item.adjustment_pct"
                                           :disabled="item.name === '{{ $conditionBaselineName }}'"
                                           @change="
                                                item.adjustment_pct = parseFloat($event.target.value);
                                                fetch(itemBaseUrl + '/' + item.id, {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf },
                                                    body: '_method=PUT&name=' + encodeURIComponent(item.name) + '&sort_order=' + item.sort_order + '&adjustment_pct=' + item.adjustment_pct,
                                                });"
                                           class="w-20 text-xs rounded px-2 py-1 flex-shrink-0 tabular-nums text-right"
                                           style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                                    <span class="text-xs flex-shrink-0" style="color:var(--text-muted);">%</span>
                                    @endif
                                    <span class="text-[10px] uppercase tracking-wide font-semibold" style="color:var(--text-muted);">Default</span>
                                </div>
                            </div>
                        </template>
                        {{-- Defaults save button --}}
                        <div class="flex items-center justify-between px-4 py-3" style="background:var(--surface-2); border-top:1px solid var(--border);">
                            <span class="text-xs" style="color:var(--text-muted);">Toggle on/off then save. Drag to reorder.</span>
                            <button type="button" @click="saveDefaults()" class="corex-btn-primary text-sm px-5">Save Changes</button>
                        </div>
                        @endif

                        {{-- Custom items (all groups) --}}
                        <template x-for="(item, idx) in custs" :key="item.id">
                            <div :style="isDragTarget(idx,'c') ? 'border-top:2px solid var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent);' : 'border-bottom:1px solid var(--border);'"
                                 @dragover.prevent="onOver(idx,'c')"
                                 @drop.prevent="drop(idx,'c')"
                                 @dragleave="dragTarget=null">
                                {{-- View row --}}
                                <div x-show="editId !== item.id"
                                     class="flex items-center gap-3 px-4 py-2.5 transition-opacity"
                                     :class="dragFrom?.idx===idx && dragFrom?.list==='c' ? 'opacity-30' : ''"
                                     style="background:var(--surface);"
                                     draggable="true"
                                     @dragstart="startDrag(idx,'c')"
                                     @dragend="resetDrag()">
                                    <span class="cursor-grab flex-shrink-0" style="color:var(--text-muted);">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="7" cy="4" r="1.5"/><circle cx="13" cy="4" r="1.5"/><circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/><circle cx="7" cy="16" r="1.5"/><circle cx="13" cy="16" r="1.5"/></svg>
                                    </span>
                                    <span class="flex-1 text-sm font-medium" x-text="item.name" style="color:var(--text-primary);"></span>
                                    @if($isCategoryGroup)
                                    <span class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded"
                                          x-text="({{ \Illuminate\Support\Js::from(\App\Models\PropertySettingItem::TITLE_TYPES) }})[item.title_type] || item.title_type"
                                          style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);"></span>
                                    @endif
                                    @if($isConditionGroup)
                                    <span class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded tabular-nums"
                                          x-text="(item.adjustment_pct >= 0 ? '+' : '') + item.adjustment_pct + '%'"
                                          :style="item.adjustment_pct >= 0
                                                ? 'background:color-mix(in srgb, var(--ds-green, #059669) 12%, transparent); color: var(--ds-green, #059669);'
                                                : 'background:color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); color: var(--ds-amber, #f59e0b);'"></span>
                                    @endif
                                    <span class="text-xs tabular-nums" x-text="'#' + (idx+1)" style="color:var(--text-muted);"></span>
                                    <button type="button" @click="startEdit(item)"
                                            class="text-xs font-semibold" style="color: var(--brand-icon, #0ea5e9);"
                                            onmouseout="this.style.color='var(--brand-icon, #0ea5e9)'">Edit</button>
                                    <form :action="itemBaseUrl + '/' + item.id" method="POST"
                                          @submit.prevent="if(confirm('Delete \'' + item.name + '\'?')) $el.submit()">
                                        <input type="hidden" name="_token" :value="csrf">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="text-xs font-semibold" style="color:var(--text-muted);"
                                                onmouseover="this.style.color='var(--ds-crimson)'" onmouseout="this.style.color='var(--text-muted)'">Delete</button>
                                    </form>
                                </div>
                                {{-- Edit row --}}
                                <div x-show="editId === item.id" x-cloak
                                     class="px-4 py-3" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent); border-top:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent);">
                                    <form :action="itemBaseUrl + '/' + item.id" method="POST"
                                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                        <input type="hidden" name="_token" :value="csrf">
                                        <input type="hidden" name="_method" value="PUT">
                                        <div class="md:col-span-{{ ($isCategoryGroup || $isConditionGroup) ? '5' : '7' }}">
                                            <input name="name" :value="editName" required
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        @if($isCategoryGroup)
                                        <div class="md:col-span-3">
                                            <select name="title_type"
                                                    x-init="$el.value = editTitleType"
                                                    @change="editTitleType = $event.target.value"
                                                    class="w-full rounded-md px-3 py-2 text-sm"
                                                    style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                                @foreach($titleTypeOptions as $tt => $ttLabel)
                                                    <option value="{{ $tt }}">{{ $ttLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        @endif
                                        @if($isConditionGroup)
                                        <div class="md:col-span-3">
                                            <input name="adjustment_pct" type="number" step="0.01" min="-99.99" max="200" required
                                                   :value="editAdjustmentPct"
                                                   @input="editAdjustmentPct = parseFloat($event.target.value)"
                                                   class="w-full rounded-md px-3 py-2 text-sm tabular-nums text-right"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        @endif
                                        <div class="md:col-span-2">
                                            <input name="sort_order" type="number" step="1" min="0" :value="editSort"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2 flex gap-2">
                                            <button type="submit" class="flex-1 corex-btn-primary text-sm">Save</button>
                                            <button type="button" @click="editId = null"
                                                    class="flex-1 text-sm rounded-md"
                                                    style="border:1px solid var(--border); color:var(--text-secondary);">✕</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>

                        {{-- Empty state --}}
                        <div x-show="custs.length === 0" class="px-4 py-5 text-sm" style="color:var(--text-muted);">
                            @if($hasDefaults)
                            No custom {{ strtolower($pg['label']) }} yet — add one above.
                            @else
                            No {{ strtolower($pg['label']) }} yet — add one above.
                            @endif
                        </div>

                    </div>{{-- /panel --}}
                </div>
                @endforeach

            </div>{{-- /properties --}}

            {{-- PRESENTATIONS section (V2 Phase 2) --}}
            <div x-show="activeSection === 'feature-presentations'" x-cloak class="space-y-3">
                @php
                    $presAgency = $agency ?? null;
                    $presRich     = (int) ($presAgency->presentations_coverage_rich_threshold     ?? 6);
                    $presModerate = (int) ($presAgency->presentations_coverage_moderate_threshold ?? 3);
                    $presThin     = (int) ($presAgency->presentations_coverage_thin_threshold     ?? 1);
                    $presPeriod   = (int) ($presAgency->presentations_default_period_months       ?? 12);
                    $presScope    = (string) ($presAgency->presentations_default_comp_scope        ?? 'radius_all');
                    $presRadius   = (int) ($presAgency->presentations_default_radius_m             ?? 1000);
                    $presSsComplex = (bool) ($presAgency->ss_show_complex_section ?? true);
                    $presFreshness = (int) ($presAgency->presentations_freshness_days              ?? 90);
                    $cmaRecency    = (int) ($presAgency->cma_compute_recency_months                ?? 36);
                    $cmaIqr        = (float) ($presAgency->cma_compute_iqr_multiplier              ?? 1.5);
                    // AT-22 §0.1 — comp-selection + range thresholds. Defaults
                    // mirror App\Services\Presentations\CompPoolBuilder DEF_*.
                    $compPriceBand = (float)  ($presAgency->comp_price_band_pct      ?? 25);
                    $compErfBand   = (float)  ($presAgency->comp_erf_band_pct        ?? 30);
                    $compRadius    = (int)    ($presAgency->comp_radius_m            ?? 300);
                    $compWiden     = (string) ($presAgency->comp_radius_widen_steps  ?? '300,600,1000,1500,3000');
                    $compRadiusMax = (int)    ($presAgency->comp_radius_max_m        ?? 3000);
                    $compMinCount  = (int)    ($presAgency->comp_min_count           ?? 10);
                    $compMaxCount  = (int)    ($presAgency->comp_max_count           ?? 15);
                    $anchorDiv     = (float)  ($presAgency->anchor_divergence_pct    ?? 25);
                    $rangeLower    = (int)    ($presAgency->range_lower_pct          ?? 25);
                    $rangeUpper    = (int)    ($presAgency->range_upper_pct          ?? 75);
                    // PRES-CMA-REALFIX — recommended-band half-widths (± % around
                    // the indicated value). ASYMMETRIC market norm 10% below /
                    // 13% above. Distinct from the percentile fields above.
                    $cmaBandLower  = (float)  ($presAgency->cma_band_lower_pct       ?? 10);
                    $cmaBandUpper  = (float)  ($presAgency->cma_band_upper_pct       ?? 13);
                    // AT-22 item 3 — holding-cost Tier-2 agency defaults.
                    $hcOppPct    = (float) ($presAgency->presentations_default_opportunity_cost_pct      ?? 8);
                    $hcRatesPM   = (int)   ($presAgency->presentations_default_rates_per_million_zar      ?? 800);
                    $hcInsPM     = (int)   ($presAgency->presentations_default_insurance_per_million_zar  ?? 200);
                    $hcUtil      = (int)   ($presAgency->presentations_default_utilities_zar              ?? 1200);
                    $hcGarden    = (int)   ($presAgency->presentations_default_garden_zar                 ?? 800);
                    $hcPool      = (int)   ($presAgency->presentations_default_pool_zar                   ?? 600);
                    $hcSecurity  = (int)   ($presAgency->presentations_default_security_zar               ?? 1500);
                @endphp

                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="mb-3">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">CMA Coverage Thresholds</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Controls the data-quality badge shown on the property page above the Generate Presentation button. Counts are sold (registered) deals in the property's suburb within the rolling window.</div>
                    </div>

                    @if($presAgency)
                    <form method="POST" action="{{ route('corex.settings.presentations.update') }}" class="space-y-3">
                        @csrf

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Rich (default 6)</label>
                                <input type="number" min="1" max="999" name="presentations_coverage_rich_threshold"
                                       value="{{ $presRich }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <div class="text-[11px] mt-1" style="color:var(--text-muted);">≥ this many comps → green badge "Strong data".</div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Moderate (default 3)</label>
                                <input type="number" min="1" max="999" name="presentations_coverage_moderate_threshold"
                                       value="{{ $presModerate }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <div class="text-[11px] mt-1" style="color:var(--text-muted);">≥ this many comps → amber badge "Moderate data".</div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Thin (default 1)</label>
                                <input type="number" min="1" max="999" name="presentations_coverage_thin_threshold"
                                       value="{{ $presThin }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <div class="text-[11px] mt-1" style="color:var(--text-muted);">≥ this many comps → amber badge "Thin data". 0 comps → red badge.</div>
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Default period (months)</label>
                                <input type="number" min="1" max="60" name="presentations_default_period_months"
                                       value="{{ $presPeriod }}"
                                       class="w-full rounded-md px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <div class="text-[11px] mt-1" style="color:var(--text-muted);">Rolling window for the comp count (default 12).</div>
                            </div>
                        </div>

                        {{-- Phase 3b — comp scope + radius default --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <div class="mb-3">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Comparable Scope</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">How CoreX picks comparable sales from the agency-wide market data pool. Agents can override per presentation at generation time.</div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Default scope</label>
                                    <div class="space-y-1.5">
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="radio" name="presentations_default_comp_scope" value="radius_all"
                                                   {{ $presScope === 'radius_all' ? 'checked' : '' }}
                                                   class="mt-0.5">
                                            <div>
                                                <div class="text-sm font-medium" style="color:var(--text-primary);">Radius (recommended)</div>
                                                <div class="text-[11px]" style="color:var(--text-muted);">Match by great-circle distance from the subject property. Honest evaluation default — distance is the strongest single predictor.</div>
                                            </div>
                                        </label>
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="radio" name="presentations_default_comp_scope" value="suburb_only"
                                                   {{ $presScope === 'suburb_only' ? 'checked' : '' }}
                                                   class="mt-0.5">
                                            <div>
                                                <div class="text-sm font-medium" style="color:var(--text-primary);">Suburb only</div>
                                                <div class="text-[11px]" style="color:var(--text-muted);">Match by suburb name only. Use when scheme character or municipal boundary effects matter more than raw distance.</div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Default radius (metres)</label>
                                    <input type="number" min="50" max="5000" step="50" name="presentations_default_radius_m"
                                           value="{{ $presRadius }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Used when scope is "Radius". Range 50–5000m. Default 1000m. Suburb-only ignores this.</div>
                                </div>
                            </div>
                        </div>

                        {{-- SS presentations — dedicated complex/sectional sales section.
                             When a CMA carries sectional ("sales within the scheme") comps,
                             they render as their own "Recent sales in {complex}" section
                             instead of being folded into the vicinity table. Data-presence
                             driven — no sectional comps means no section. This toggle lets
                             an agency suppress it entirely. --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="ss_show_complex_section" value="1"
                                       {{ $presSsComplex ? 'checked' : '' }}
                                       class="mt-0.5">
                                <div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Show "Complex sales" section for sectional title</div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                                        When on, presentations show a separate section listing other recent sales
                                        within the same complex / scheme (alongside the wider vicinity sales),
                                        whenever the CMA includes sectional-title sales. When off, those sales
                                        fold into the vicinity table. Default on.
                                    </div>
                                </div>
                            </label>
                        </div>

                        {{-- Build 5 — snapshot freshness window. When a seller opens a
                             share link older than this many days, the public view shows
                             a one-click CTA asking if they'd like a revised analysis. --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <div class="mb-3">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Snapshot Freshness Window</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                                    Days before the public share view nudges the seller to ask for a refreshed analysis.
                                    Sellers see "Prepared X days ago" between 30 days and this threshold, and a CTA panel beyond it.
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Freshness window (days)</label>
                                    <input type="number" min="7" max="365" name="presentations_freshness_days"
                                           value="{{ $presFreshness }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Default 90. Range 7–365.</div>
                                </div>
                            </div>
                        </div>

                        {{-- Build 8b — CMA compute engine cleaning controls. Internal
                             this build (the cleaned numbers ride in the cma_computed
                             payload only — no seller surface yet). Decoupled from
                             presentations_default_period_months so this setting only
                             affects what the compute engine processes; the hydrator
                             pool + coverage badge are unaffected. --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <div class="mb-3">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">CMA Compute Engine — Pool Cleaning</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                                    How aggressively the independent CMA compute engine cleans the comp pool before deriving its median / mean / R-per-m² values.
                                    Affects the engine's output only — the review-screen comp table still shows the full unfiltered pool.
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Recency window (months)</label>
                                    <input type="number" min="1" max="600" name="cma_compute_recency_months"
                                           value="{{ $cmaRecency }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Drop comps older than this. Default 36. Range 1–600.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">IQR multiplier (lower fence)</label>
                                    <input type="number" min="0.5" max="5.0" step="0.1" name="cma_compute_iqr_multiplier"
                                           value="{{ number_format($cmaIqr, 2, '.', '') }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Drop R/m² &lt; Q1 − (multiplier × IQR). Default 1.5. Range 0.5–5.0.</div>
                                </div>
                            </div>
                        </div>

                        {{-- AT-22 §0.1/§1/§1.5/§5 — Comparable Selection Engine.
                             The gate-then-rank pipeline (CompPoolBuilder) that
                             builds the sold-comp pool and the recommended range.
                             Every field falls back to the locked service default
                             when blank, so legacy agencies keep working. --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <div class="mb-3">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Comparable Selection Engine</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                                    Controls how CoreX gates and ranks sold comparables for the CMA, and how the recommended price range is derived.
                                    Type is always a hard gate (freehold never mixes with sectional). Leave any field blank to use the locked default.
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Price band (± %)</label>
                                    <input type="number" min="5" max="100" step="0.5" name="comp_price_band_pct"
                                           value="{{ $compPriceBand }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Comps must fall within ± this % of the cleaned-pool CMA anchor (not asking). Default 25.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Erf-size proximity (± %)</label>
                                    <input type="number" min="5" max="200" step="0.5" name="comp_erf_band_pct"
                                           value="{{ $compErfBand }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Ranking factor (not a hard drop) — closer erf scores higher. Default 30.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Initial radius (metres)</label>
                                    <input type="number" min="50" max="10000" step="50" name="comp_radius_m"
                                           value="{{ $compRadius }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Tight default so comps cluster to the subject. Default 300.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Radius widen ladder (metres, CSV)</label>
                                    <input type="text" name="comp_radius_widen_steps"
                                           value="{{ $compWiden }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">When too few comps resolve, the radius steps up through these. Default 300,600,1000,1500,3000.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Radius hard ceiling (metres)</label>
                                    <input type="number" min="100" max="10000" step="100" name="comp_radius_max_m"
                                           value="{{ $compRadiusMax }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">The widen ladder never exceeds this. Default 3000 (rural mandates must resolve).</div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Min comps</label>
                                        <input type="number" min="1" max="100" name="comp_min_count"
                                               value="{{ $compMinCount }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <div class="text-[11px] mt-1" style="color:var(--text-muted);">Widen the radius until this many comps resolve. Default 10.</div>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Max comps</label>
                                        <input type="number" min="1" max="100" name="comp_max_count"
                                               value="{{ $compMaxCount }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <div class="text-[11px] mt-1" style="color:var(--text-muted);">Shortlist cap. Default 15.</div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Anchor divergence trigger (± %)</label>
                                    <input type="number" min="5" max="100" step="1" name="anchor_divergence_pct"
                                           value="{{ $anchorDiv }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">If the cleaned-pool estimate is more than this % off the vicinity average, widen the radius. Default 25.</div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Range lower (percentile)</label>
                                        <input type="number" min="1" max="49" name="range_lower_pct"
                                               value="{{ $rangeLower }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <div class="text-[11px] mt-1" style="color:var(--text-muted);">Lower bound. Default 25 (P25).</div>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Range upper (percentile)</label>
                                        <input type="number" min="51" max="99" name="range_upper_pct"
                                               value="{{ $rangeUpper }}"
                                               class="w-full rounded-md px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <div class="text-[11px] mt-1" style="color:var(--text-muted);">Upper bound. Default 75 (P75). Asking never widens the band.</div>
                                    </div>
                                </div>

                                {{-- PRES-CMA-REALFIX — recommended-band half-widths.
                                     DISTINCT from the percentile fields above: these
                                     set how far the seller-facing price range sits
                                     below / above the indicated value. Asymmetric
                                     market norm (from 105 evidenced CMA reports):
                                     10% below, 13% above. --}}
                                <div class="mt-3 pt-3" style="border-top:1px dashed var(--border);">
                                    <div class="text-[11px] font-semibold uppercase tracking-wider mb-2" style="color:var(--text-secondary);">Valuation band (recommended range around the indicated value)</div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Valuation band — below middle (%)</label>
                                            <input type="number" min="0" max="40" step="0.5" name="cma_band_lower_pct"
                                                   value="{{ number_format($cmaBandLower, 2, '.', '') }}"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            <div class="text-[11px] mt-1" style="color:var(--text-muted);">How far the recommended price range sits below the indicated value. CMA market norm ≈ 10% below.</div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Valuation band — above middle (%)</label>
                                            <input type="number" min="0" max="40" step="0.5" name="cma_band_upper_pct"
                                                   value="{{ number_format($cmaBandUpper, 2, '.', '') }}"
                                                   class="w-full rounded-md px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                            <div class="text-[11px] mt-1" style="color:var(--text-muted);">How far the recommended price range sits above the indicated value. CMA market norm ≈ 13% above.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- AT-22 item 3 — Holding Cost defaults. These drive the
                             per-presentation holding-cost panel (the figures the
                             seller sees). They are Tier-2 fallbacks: a captured
                             per-property value or a learned average wins first;
                             these apply when neither exists. Surfaced here so the
                             figures are transparent + tunable, not opaque. --}}
                        <div class="pt-4 mt-4" style="border-top:1px solid var(--border);">
                            <div class="mb-3">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Holding Cost Defaults</div>
                                <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                                    Default monthly holding-cost components used when a property has no captured value. Opportunity cost = asking × this % ÷ 12. Rates/Insurance scale with value (per R1m); the rest are flat monthly rand.
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Opportunity cost (% of asking, annual)</label>
                                    <input type="number" min="0" max="30" step="0.25" name="presentations_default_opportunity_cost_pct"
                                           value="{{ $hcOppPct }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Monthly = asking × this% ÷ 12. Default 8.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Rates (R per R1m of value, monthly)</label>
                                    <input type="number" min="0" max="100000" name="presentations_default_rates_per_million_zar"
                                           value="{{ $hcRatesPM }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">× (value ÷ R1,000,000). Default 800.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Insurance (R per R1m of value, monthly)</label>
                                    <input type="number" min="0" max="100000" name="presentations_default_insurance_per_million_zar"
                                           value="{{ $hcInsPM }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">× (value ÷ R1,000,000). Default 200.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Utilities (flat monthly R)</label>
                                    <input type="number" min="0" max="1000000" name="presentations_default_utilities_zar"
                                           value="{{ $hcUtil }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Default 1200.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Garden (flat monthly R, freehold)</label>
                                    <input type="number" min="0" max="1000000" name="presentations_default_garden_zar"
                                           value="{{ $hcGarden }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Default 800.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Pool (flat monthly R, freehold)</label>
                                    <input type="number" min="0" max="1000000" name="presentations_default_pool_zar"
                                           value="{{ $hcPool }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Default 600.</div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Security (flat monthly R, freehold)</label>
                                    <input type="number" min="0" max="1000000" name="presentations_default_security_zar"
                                           value="{{ $hcSecurity }}"
                                           class="w-full rounded-md px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <div class="text-[11px] mt-1" style="color:var(--text-muted);">Default 1500.</div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center gap-2">
                            <button type="submit" class="corex-btn-primary text-sm">Save Thresholds</button>
                            <p class="text-[11px]" style="color:var(--text-muted);">Thresholds must satisfy: rich ≥ moderate ≥ thin ≥ 1; comp min ≤ max; range lower &lt; upper.</p>
                        </div>
                    </form>
                    @else
                    <p class="text-xs" style="color:var(--text-muted);">No agency context — cannot edit thresholds.</p>
                    @endif
                </div>

                {{-- Build 4 — agency defaults for which report sections render.
                     Per-presentation overrides happen on the review screen. --}}
                @if($presAgency)
                @php
                    $sectionsCatalogue = \App\Models\PresentationVersion::SECTIONS_CATALOGUE;
                    $sectionFloor      = \App\Models\PresentationVersion::SECTION_FLOOR;
                    $sectionDeps       = \App\Models\PresentationVersion::SECTION_DEPENDENCIES;
                    $sectionDescriptions = [
                        'executive_summary'  => 'High-level summary of asking price vs CMA and key insights. Recommended to always include.',
                        'market_overview'    => 'Suburb-level market trends, median pricing and absorption.',
                        'recent_sales'       => 'Comparable sales table with per-row distance, size and price.',
                        'spatial_view'       => 'Inline map showing the subject and comparable pins.',
                        'cma_analysis'       => 'Lower / Middle / Upper valuation bands with condition adjustment.',
                        'active_competition' => 'Live listings competing for the same buyer pool.',
                        'inflow_absorption'  => 'New listings from P24 alerts and stock absorption rate.',
                        'holding_cost'       => 'Monthly cost of staying on market — rates, bond, levies, opportunity cost.',
                        'pricing_strategy'   => 'Recommended price band and overpricing analysis. Requires CMA Analysis.',
                    ];
                @endphp
                <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="mb-3">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Presentation Sections</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                            Which sections are pre-ticked when an agent opens the review screen.
                            Agents can override per-presentation. Sections marked "always" form the report's floor and render every time.
                        </div>
                    </div>

                    <form method="POST" action="{{ route('corex.settings.presentations.sections.update') }}" class="space-y-2">
                        @csrf
                        @foreach($sectionsCatalogue as $sectionKey => $sectionLabel)
                            @php
                                $col       = 'presentations_default_show_' . $sectionKey;
                                $isFloor   = in_array($sectionKey, $sectionFloor, true);
                                $isOn      = $isFloor ? true : (bool) ($presAgency->{$col} ?? true);
                                $depsList  = [];
                                foreach ($sectionDeps[$sectionKey] ?? [] as $depKey) {
                                    $depsList[] = $sectionsCatalogue[$depKey] ?? $depKey;
                                }
                            @endphp
                            <div class="flex items-start justify-between gap-4 px-3 py-2 rounded-md"
                                 style="background:var(--surface); border:1px solid var(--border);">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium" style="color:var(--text-primary);">
                                        {{ $sectionLabel }}
                                        @if($isFloor)
                                            <span class="ml-1 text-[10px] uppercase tracking-wide font-semibold px-1.5 py-0.5 rounded"
                                                  style="background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent); color: var(--brand-icon, #0ea5e9);">
                                                Always shown
                                            </span>
                                        @endif
                                        @if(!empty($depsList))
                                            <span class="ml-1 text-[10px] uppercase tracking-wide font-semibold" style="color:var(--text-muted);">
                                                requires {{ implode(', ', $depsList) }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                                        {{ $sectionDescriptions[$sectionKey] ?? '' }}
                                    </div>
                                </div>
                                <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;">
                                    @if($isFloor)
                                        <input type="hidden" name="{{ $col }}" value="1">
                                        <input type="checkbox" checked disabled class="sr-only">
                                    @else
                                        <input type="hidden" name="{{ $col }}" value="0">
                                        <input type="checkbox" name="{{ $col }}" value="1" {{ $isOn ? 'checked' : '' }} class="sr-only">
                                    @endif
                                    <span class="block w-full h-full rounded-full transition-colors duration-200"
                                          style="background:{{ $isOn ? 'var(--brand-button, #0ea5e9)' : 'var(--border-hover)' }};{{ $isFloor ? 'opacity:0.6;' : '' }}"></span>
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                          style="transform:translateX({{ $isOn ? '20px' : '0' }});"></span>
                                </label>
                            </div>
                        @endforeach
                        <div class="pt-2">
                            <button type="submit" class="corex-btn-primary text-sm">Save Section Defaults</button>
                        </div>
                    </form>
                </div>
                @endif
            </div>{{-- /presentations --}}

            {{-- MATCHES section --}}
            <div x-show="activeSection === 'feature-matches'" x-cloak class="space-y-5">

                {{-- Enable / Disable toggle --}}
                <div class="p-4 rounded-md flex items-center justify-between gap-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Core Matches</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">When disabled, the Core Matches tab is hidden on contacts and properties, and the sidebar link is removed.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.matches-enabled') }}" class="flex items-center gap-3 flex-shrink-0">
                        @csrf
                        <input type="hidden" name="matches_enabled" value="0">
                        <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;"
                               title="{{ $matchesEnabled ? 'Enabled — click to disable' : 'Disabled — click to enable' }}">
                            <input type="checkbox" name="matches_enabled" value="1"
                                   {{ $matchesEnabled ? 'checked' : '' }}
                                   class="sr-only"
                                   onchange="this.closest('form').submit()">
                            <span class="block w-full h-full rounded-full transition-colors duration-200"
                                  style="background:{{ $matchesEnabled ? 'var(--brand-button, #0ea5e9)' : 'var(--border-hover)' }}"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                  style="transform:translateX({{ $matchesEnabled ? '20px' : '0' }})"></span>
                        </label>
                        <span class="text-sm font-semibold" style="color:{{ $matchesEnabled ? 'var(--brand-button, #0ea5e9)' : 'var(--text-muted)' }};">
                            {{ $matchesEnabled ? 'On' : 'Off' }}
                        </span>
                    </form>
                </div>

                {{-- Show on Properties toggle --}}
                <div class="p-4 rounded-md flex items-center justify-between gap-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Show Core Matches on Properties</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">When disabled, the Core Matches tab is hidden on individual property pages only. Contacts and the sidebar are unaffected.</div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.matches-show-on-properties') }}" class="flex items-center gap-3 flex-shrink-0">
                        @csrf
                        <input type="hidden" name="matches_show_on_properties" value="0">
                        <label class="relative cursor-pointer flex-shrink-0" style="width:44px; height:24px; display:block;"
                               title="{{ $matchesShowOnProperties ? 'Enabled — click to disable' : 'Disabled — click to enable' }}">
                            <input type="checkbox" name="matches_show_on_properties" value="1"
                                   {{ $matchesShowOnProperties ? 'checked' : '' }}
                                   class="sr-only"
                                   onchange="this.closest('form').submit()">
                            <span class="block w-full h-full rounded-full transition-colors duration-200"
                                  style="background:{{ $matchesShowOnProperties ? 'var(--brand-button, #0ea5e9)' : 'var(--border-hover)' }}"></span>
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-all duration-200"
                                  style="transform:translateX({{ $matchesShowOnProperties ? '20px' : '0' }})"></span>
                        </label>
                        <span class="text-sm font-semibold" style="color:{{ $matchesShowOnProperties ? 'var(--brand-button, #0ea5e9)' : 'var(--text-muted)' }};">
                            {{ $matchesShowOnProperties ? 'On' : 'Off' }}
                        </span>
                    </form>
                </div>

                {{-- Match visibility scope (agent / branch / agency) --}}
                <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Match results visibility</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                            Controls whose stock is searched when an agent opens a Core Match (web and mobile, and the client-shared page).
                            <strong>Agent</strong> shows only the match owner's listings,
                            <strong>Branch</strong> shows the owner's branch stock,
                            <strong>Agency</strong> shows the agency's full stock.
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.matches-visibility-scope') }}" class="flex items-center gap-2">
                        @csrf
                        @foreach (['agent' => 'Agent', 'branch' => 'Branch', 'agency' => 'Agency'] as $val => $label)
                            @php $isActive = $matchesVisibilityScope === $val; @endphp
                            <button type="submit" name="matches_visibility_scope" value="{{ $val }}"
                                    class="px-4 py-2 rounded-md text-sm font-semibold border transition-colors"
                                    style="background:{{ $isActive ? 'var(--brand-button, #0ea5e9)' : 'transparent' }};
                                           color:{{ $isActive ? '#fff' : 'var(--text-secondary)' }};
                                           border-color:{{ $isActive ? 'var(--brand-button, #0ea5e9)' : 'var(--border)' }};">
                                {{ $label }}
                            </button>
                        @endforeach
                    </form>
                </div>

                {{-- WhatsApp message template --}}
                <div class="p-4 rounded-md space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">WhatsApp Message Template</div>
                        <div class="text-xs mt-0.5" style="color:var(--text-secondary);">
                            This is the default message pre-filled when sending matches via WhatsApp. Use <code class="rounded font-mono text-[11px]" style="background:var(--surface); padding:1px 4px;">{name}</code> for the client's first name and <code class="rounded font-mono text-[11px]" style="background:var(--surface); padding:1px 4px;">{link}</code> for the matches page link.
                        </div>
                    </div>
                    <form method="POST" action="{{ route('corex.settings.matches-wa-message') }}" class="space-y-3">
                        @csrf
                        <textarea name="matches_wa_message" rows="8" maxlength="1000"
                                  class="w-full rounded-md px-3 py-2 text-sm font-mono"
                                  style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); resize:vertical; line-height:1.6;">{{ old('matches_wa_message', $matchesWaMessage) }}</textarea>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px]" style="color:var(--text-muted);">Max 1000 characters.</span>
                            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Save Template</button>
                        </div>
                    </form>
                </div>

            </div>{{-- /matches --}}

            {{-- ════════════════════════════════════════════════════════════════
                 DASHBOARD SETTINGS section
                 Controls whether agents use individual or agency-wide settings
                 ════════════════════════════════════════════════════════════════ --}}
            <div x-show="activeSection === 'feature-dashboard'" x-cloak class="space-y-6"
                 x-data="{ settingsMode: '{{ $dashboardSettingsMode ?? 'user' }}' }">

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">Dashboard & Command Center</h3>
                    <p class="text-xs" style="color:var(--text-muted);">Control how dashboard reminder and alert settings work for agents in this agency.</p>
                </div>

                {{-- Mode selector --}}
                <div class="p-5 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Settings Mode</h4>
                    <p class="text-xs" style="color:var(--text-muted);">Choose whether each agent can personalise their own dashboard settings, or if the agency enforces one set of settings for everyone.</p>

                    <form method="POST" action="{{ route('corex.settings.dashboard.mode') }}">
                        @csrf @method('PUT')
                        <div class="flex flex-col sm:flex-row gap-4 mt-3">
                            <label class="flex-1 p-4 rounded-md cursor-pointer transition-all border-2"
                                   :class="settingsMode === 'user' ? 'border-sky-500' : 'border-transparent'"
                                   style="background:var(--surface);"
                                   @click="settingsMode = 'user'">
                                <input type="radio" name="dashboard_settings_mode" value="user" class="sr-only"
                                       {{ ($dashboardSettingsMode ?? 'user') === 'user' ? 'checked' : '' }}>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-md flex items-center justify-center" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent);">
                                        <svg class="w-4 h-4" style="color: var(--brand-icon, #0ea5e9);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold" style="color:var(--text-primary);">Individual Settings</p>
                                        <p class="text-xs mt-0.5" style="color:var(--text-muted);">Each agent customises their own reminders, alerts, and calendar preferences</p>
                                    </div>
                                </div>
                            </label>

                            <label class="flex-1 p-4 rounded-md cursor-pointer transition-all border-2"
                                   :class="settingsMode === 'agency' ? 'border-sky-500' : 'border-transparent'"
                                   style="background:var(--surface);"
                                   @click="settingsMode = 'agency'">
                                <input type="radio" name="dashboard_settings_mode" value="agency" class="sr-only"
                                       {{ ($dashboardSettingsMode ?? 'user') === 'agency' ? 'checked' : '' }}>
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-md flex items-center justify-center" style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent);">
                                        <svg class="w-4 h-4" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold" style="color:var(--text-primary);">Agency Settings</p>
                                        <p class="text-xs mt-0.5" style="color:var(--text-muted);">All agents follow one set of settings managed by admin. User settings page is hidden.</p>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <button type="submit" class="corex-btn-primary text-sm">Save Mode</button>
                        </div>
                    </form>
                </div>

                {{-- Agency-wide settings form (only visible when mode = agency) --}}
                <div x-show="settingsMode === 'agency'" x-cloak>
                    <div class="p-5 rounded-md space-y-5" style="background:var(--surface-2); border:1px solid var(--border);">
                        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Agency-Wide Dashboard Settings</h4>
                        <p class="text-xs" style="color:var(--text-muted);">These settings apply to all agents in this agency when Agency mode is active.</p>

                        <form method="POST" action="{{ route('corex.settings.dashboard.agency') }}">
                            @csrf @method('PUT')

                            <div class="space-y-4">
                                {{-- Idle alerts --}}
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="flex items-center gap-2 text-sm mb-2" style="color:var(--text-secondary);">
                                            <input type="hidden" name="idle_alerts_enabled" value="0">
                                            <input type="checkbox" name="idle_alerts_enabled" value="1" {{ ($agencyDashboardSettings->idle_alerts_enabled ?? true) ? 'checked' : '' }} class="rounded">
                                            Property idle alerts
                                        </label>
                                        <input type="number" name="idle_threshold_days" value="{{ $agencyDashboardSettings->idle_threshold_days ?? 14 }}" min="1"
                                               class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);"
                                               placeholder="Days">
                                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">Days idle before alert</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Alert day</label>
                                        <select name="idle_alert_day" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                            <option value="">Every day</option>
                                            @foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day)
                                                <option value="{{ $day }}" {{ ($agencyDashboardSettings->idle_alert_day ?? '') === $day ? 'selected' : '' }}>{{ ucfirst($day) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Alert time</label>
                                        <input type="time" name="idle_alert_time" value="{{ $agencyDashboardSettings->idle_alert_time ?? '08:00' }}"
                                               class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                    </div>
                                </div>

                                {{-- Toggles --}}
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                    @foreach([
                                        'doc_reminders_enabled'  => 'Document reminders',
                                        'lease_expiry_reminders' => 'Lease expiry reminders',
                                        'fica_reminders'         => 'FICA reminders',
                                        'ffc_reminders'          => 'FFC reminders',
                                        'task_due_reminders'     => 'Task due reminders',
                                        'overdue_daily_digest'   => 'Daily overdue digest',
                                        'notify_in_app'          => 'In-app notifications',
                                        'notify_email'           => 'Email notifications',
                                    ] as $field => $label)
                                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                            <input type="hidden" name="{{ $field }}" value="0">
                                            <input type="checkbox" name="{{ $field }}" value="1" {{ ($agencyDashboardSettings->$field ?? true) ? 'checked' : '' }} class="rounded">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Digest time</label>
                                        <input type="time" name="digest_time" value="{{ $agencyDashboardSettings->digest_time ?? '08:00' }}"
                                               class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Default calendar view</label>
                                        <select name="default_calendar_view" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface); border-color:var(--border-default); color:var(--text-primary);">
                                            @foreach(['month' => 'Month', 'week' => 'Week', 'day' => 'Day', 'agenda' => 'Agenda'] as $v => $l)
                                                <option value="{{ $v }}" {{ ($agencyDashboardSettings->default_calendar_view ?? 'month') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="flex items-end pb-2">
                                        <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                            <input type="hidden" name="weekend_visible" value="0">
                                            <input type="checkbox" name="weekend_visible" value="1" {{ ($agencyDashboardSettings->weekend_visible ?? false) ? 'checked' : '' }} class="rounded">
                                            Show weekends
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex justify-end">
                                <button type="submit" class="corex-btn-primary text-sm">Save Agency Settings</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>{{-- /dashboard --}}

        </div>

        {{-- ============================================================
             LEAVE VISIBILITY (Operations)
             ============================================================ --}}
        <div x-show="activeSection === 'leave-visibility'" x-cloak class="p-6 space-y-5">
            @if(isset($leaveVisibilityRoles) && isset($leaveVisibilityGrid))
                @if(session('success'))
                    <div class="px-4 py-2.5 rounded-md text-sm font-medium"
                         style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
                        {{ session('success') }}
                    </div>
                @endif

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">Leave Visibility Matrix</h3>
                    <p class="text-xs mt-2" style="color:var(--text-secondary);">
                        Controls which roles can see whose leave entries on the calendar. Access to the leave calendar feature itself is controlled by
                        <a href="{{ route('corex.role-manager') }}" class="font-medium hover:underline" style="color: var(--brand-icon, #0ea5e9);">Role Manager</a>. The most restrictive rule wins. Own leave is always visible.
                    </p>
                </div>

                <form method="POST" action="{{ route('command-center.settings.leave-visibility.update') }}"
                      class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    @csrf @method('PUT')

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b" style="border-color:var(--border);">
                                    <th class="text-left py-2 px-3 text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Viewer ↓ / Owner →</th>
                                    @foreach($leaveVisibilityRoles as $ownerRole)
                                        <th class="text-center py-2 px-3 text-[11px] font-semibold uppercase tracking-wider capitalize" style="color:var(--text-muted);">
                                            {{ str_replace('_', ' ', $ownerRole) }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($leaveVisibilityRoles as $viewingRole)
                                    <tr class="border-b" style="border-color:var(--border);">
                                        <td class="py-2 px-3 text-sm font-medium capitalize" style="color:var(--text-primary);">
                                            {{ str_replace('_', ' ', $viewingRole) }}
                                        </td>
                                        @foreach($leaveVisibilityRoles as $ownerRole)
                                            <td class="py-2 px-3">
                                                <div class="inline-flex flex-col items-start gap-1">
                                                    <label class="flex items-center gap-1.5 text-[11px]" style="color:var(--text-muted);">
                                                        <input type="checkbox" name="matrix[{{ $viewingRole }}][{{ $ownerRole }}][same_branch]" value="1"
                                                               {{ ($leaveVisibilityGrid[$viewingRole][$ownerRole]['same_branch'] ?? false) ? 'checked' : '' }}>
                                                        Branch
                                                    </label>
                                                    <label class="flex items-center gap-1.5 text-[11px]" style="color:var(--text-muted);">
                                                        <input type="checkbox" name="matrix[{{ $viewingRole }}][{{ $ownerRole }}][cross_branch]" value="1"
                                                               {{ ($leaveVisibilityGrid[$viewingRole][$ownerRole]['cross_branch'] ?? false) ? 'checked' : '' }}>
                                                        All
                                                    </label>
                                                </div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="text-[11px]" style="color:var(--text-muted);">
                        <strong>Branch</strong> = same branch only. <strong>All</strong> = entire agency.
                    </p>

                    <div class="flex justify-end pt-2 border-t" style="border-color:var(--border);">
                        <button type="submit" class="corex-btn-primary text-sm">
                            Save Matrix
                        </button>
                    </div>
                </form>
            @else
                <p class="text-sm" style="color:var(--text-muted);">You don't have permission to configure leave visibility.</p>
            @endif
        </div>

        {{-- ============================================================
             NOTIFICATIONS TAB
             Per-event notification preferences (notification-preferences spec)
             ============================================================ --}}
        <div x-show="activeSection === 'notifications'" x-cloak class="p-6">
            @include('corex.settings._notifications', [
                'notificationSnapshot' => $notificationSnapshot ?? null,
            ])
        </div>

        {{-- ============================================================
             SYSTEM SETTINGS TAB
             Contains: General, P24 Suburbs, System Info
             ============================================================ --}}
        <div x-show="activeSection === 'system'" x-cloak class="p-6 space-y-6">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- General --}}
                <div class="p-4 rounded-md space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <h3 class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">General</h3>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Application Name</label>
                        <input type="text" value="{{ config('app.name') }}" disabled
                               class="w-full rounded-md px-3 py-2 text-sm cursor-not-allowed"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                        <p class="text-xs mt-1" style="color:var(--text-muted);">Configured in environment settings.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Environment</label>
                        <span class="ds-badge {{ config('app.env') === 'production' ? 'ds-badge-danger' : 'ds-badge-success' }}">
                            {{ config('app.env') }}
                        </span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Debug Mode</label>
                        <span class="ds-badge {{ config('app.debug') ? 'ds-badge-warning' : 'ds-badge-success' }}">
                            {{ config('app.debug') ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                </div>

                {{-- P24 Suburbs + Document Types --}}
                <div class="space-y-2">
                    <a href="{{ route('admin.p24-suburbs.index') }}"
                       class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">P24 Suburbs</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Manage Property24 suburb mappings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>

                    <a href="{{ route('admin.settings.document-types.index') }}"
                       class="flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Document Types</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Manage document categories for filing and compliance</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>

                    <button type="button"
                       @click="activeSection = 'commission'; $nextTick(() => window.scrollTo({top:0, behavior:'smooth'}))"
                       class="w-full text-left flex items-center gap-3 p-3 rounded-md transition-all duration-300 no-underline hover:bg-white/5"
                       style="border:1px solid var(--border); background:transparent;">
                        <div class="w-9 h-9 rounded-md flex items-center justify-center flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--brand-icon, #0ea5e9);" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Commission & Revenue Share</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Agent splits, caps, fees, and revenue share tiers</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </button>
                </div>

            </div>

            {{-- System Information --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-muted); border-left:3px solid var(--brand-icon, #0ea5e9); padding-left:10px;">System Information</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach([
                        ['label'=>'Laravel','value'=>app()->version()],
                        ['label'=>'PHP','value'=>PHP_VERSION],
                        ['label'=>'Database','value'=>config('database.default')],
                        ['label'=>'Users','value'=>\App\Models\User::count()],
                    ] as $stat)
                    <div class="p-4 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">{{ $stat['label'] }}</div>
                        <div class="text-xl font-bold" style="color:var(--text-primary);">{{ $stat['value'] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>

        {{-- ============================================================
             WHISTLEBLOWER COMPLIANCE REPORTING SETTINGS
             ============================================================ --}}
        @if(auth()->user()?->hasPermission('compliance.whistleblow.configure'))
        <div x-show="activeSection === 'whistleblow-settings'" x-cloak class="p-6 space-y-6">
            <div>
                <h2 class="text-lg font-bold" style="color:var(--text-primary);">Whistleblower Compliance Reporting</h2>
                <p class="text-sm mt-1" style="color:var(--text-secondary);">Configure who can approve PPRA complaints and where complaints are sent.</p>
            </div>

            <form method="POST" action="{{ route('corex.settings.whistleblow.save') }}" class="space-y-6">
                @csrf

                {{-- Demo mode status (read-only) --}}
                <div class="rounded-md p-4" style="border:1px solid var(--border); background:var(--surface-2);">
                    <h3 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted);">Email Routing Mode</h3>
                    @if(!config('compliance.whistleblow.ppra_live_send', false))
                    <span class="ds-badge ds-badge-warning">DEMO MODE ACTIVE</span>
                    <p class="text-xs mt-2" style="color:var(--text-secondary);">
                        Complaints are currently in demo mode. Emails route to
                        <strong>{{ config('compliance.whistleblow.demo_recipient', 'johan@hfcoastal.co.za') }}</strong>
                        with a [DEMO] subject prefix. Real PPRA emails will not be sent until the system administrator switches to live mode.
                    </p>
                    @else
                    <span class="ds-badge ds-badge-success">LIVE MODE</span>
                    <p class="text-xs mt-2" style="color:var(--text-secondary);">
                        Complaints are being sent to the live PPRA recipient address. All submissions are final.
                    </p>
                    @endif
                </div>

                {{-- Approvers --}}
                <div>
                    <label class="text-sm font-semibold" style="color:var(--text-primary);">Approval Authority</label>
                    <p class="text-xs mb-3" style="color:var(--text-muted);">These users can approve and send PPRA complaints. If none selected, all users with role Admin or Branch Manager can approve by default.</p>
                    @php
                        $agencyUsers = \App\Models\User::where('agency_id', auth()->user()->agency_id ?? 0)->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get();
                        $currentApprovers = $agency->whistleblow_approver_user_ids ?? [];
                    @endphp
                    <div class="space-y-1.5 max-h-48 overflow-y-auto rounded-md p-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        @foreach($agencyUsers as $au)
                        <label class="flex items-center gap-2 cursor-pointer text-sm" style="color:var(--text-primary);">
                            <input type="checkbox" name="whistleblow_approver_user_ids[]" value="{{ $au->id }}"
                                   {{ in_array($au->id, $currentApprovers) ? 'checked' : '' }}>
                            {{ $au->name }}
                            <span class="text-xs" style="color:var(--text-muted);">({{ $au->role ?? 'agent' }})</span>
                        </label>
                        @endforeach
                    </div>
                </div>

                {{-- Compliance officer email --}}
                <div>
                    <label class="text-sm font-semibold" style="color:var(--text-primary);">Compliance Officer (CC on all submissions)</label>
                    <p class="text-xs mb-2" style="color:var(--text-muted);">This email address is copied on every complaint sent to PPRA, providing your agency with an internal audit record.</p>
                    <input type="email" name="whistleblow_compliance_officer_email"
                           value="{{ $agency->whistleblow_compliance_officer_email ?? '' }}"
                           class="w-full rounded-md text-sm px-3 py-2"
                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                           placeholder="compliance@youragency.co.za">
                </div>

                {{-- Per-tier PPRA recipients --}}
                @php $tierRecipients = $agency->whistleblow_tier_recipients ?? []; @endphp
                <div>
                    <label class="text-sm font-semibold" style="color:var(--text-primary);">PPRA Recipients Per Tier</label>
                    <p class="text-xs mb-3" style="color:var(--text-muted);">One email per line. Recipients receive the complaint as primary To. Compliance officer + approver are CC'd separately.</p>
                    <div class="space-y-3">
                        @foreach(['tier_1' => 'Tier 1 (paperwork breach)', 'tier_2' => 'Tier 2 (no FFC displayed)', 'tier_3' => 'Tier 3 (unregistered)'] as $tKey => $tLabel)
                        <div>
                            <label class="text-xs font-medium mb-1 block" style="color:var(--text-secondary);">{{ $tLabel }}</label>
                            <textarea name="tier_recipients[{{ $tKey }}]" rows="2" class="w-full rounded-md text-sm px-3 py-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);" placeholder="complaints@theppra.org.za">{{ implode("\n", $tierRecipients[$tKey] ?? []) }}</textarea>
                        </div>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="corex-btn-primary">
                    Save Settings
                </button>
            </form>

            {{-- Lawyer review pack --}}
            <div class="mt-6 rounded-md p-4" style="border:1px solid var(--border); background:var(--surface-2);">
                <h3 class="text-sm font-semibold mb-1" style="color:var(--text-primary);">Legal Review</h3>
                <p class="text-xs mb-3" style="color:var(--text-secondary);">
                    Generate a review pack containing the three tier complaint PDF templates and cover email body for your lawyer to review before live submissions begin.
                </p>
                <a href="{{ route('compliance.whistleblow.lawyer-pack') }}" target="_blank"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-semibold no-underline" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Download Lawyer Review Pack
                </a>
            </div>
        </div>
        @endif

        </div>{{-- /right pane --}}
    </div>{{-- /hub flex --}}

</div>

<script>
function settingsHub(initial) {
    return {
        activeSection: initial || 'agency',
        search: '',
        matchesSearch(haystack) {
            const q = (this.search || '').trim().toLowerCase();
            if (!q) return true;
            return (haystack || '').indexOf(q) !== -1;
        },
        anyVisible(items) {
            if (!items || !items.length) return false;
            const q = (this.search || '').trim().toLowerCase();
            if (!q) return true;
            return items.some(it => {
                const hay = ((it.label || '') + ' ' + (it.keywords || '')).toLowerCase();
                return hay.indexOf(q) !== -1;
            });
        },
    };
}

function commissionSettings() {
    return {
        agentSplit: {{ old('commission_split_agent', $commissionSettings->commission_split_agent) }},
        revShareEnabled: {{ old('revenue_share_enabled', $commissionSettings->revenue_share_enabled) ? 'true' : 'false' }},
    };
}

function agencySettingsForm() {
    return {
        removelogo: false,
        sigPreviewUserId: '{{ auth()->id() }}',
        _debounce: null,

        scheduleRefresh() {
            this.refreshHeaderPreview();
            this.refreshSignaturePreview();

            // Watch all inputs inside the form for changes
            this.$el.querySelectorAll('input, textarea, select').forEach(el => {
                el.addEventListener('input', () => this.debouncedRefresh());
            });
        },

        debouncedRefresh() {
            clearTimeout(this._debounce);
            this._debounce = setTimeout(() => {
                this.refreshHeaderPreview();
                this.refreshSignaturePreview();
            }, 400);
        },

        refreshHeaderPreview() {
            const form = this.$el;
            const params = new URLSearchParams();
            const fields = [
                'trading_name', 'tagline', 'address', 'phone', 'phone_label',
                'phone_secondary', 'phone_secondary_label', 'fax', 'email',
                'reg_no', 'vat_no', 'ffc_no', 'fic_no',
            ];
            fields.forEach(f => {
                const el = form.querySelector('[name="' + f + '"]');
                if (el) params.set(f, el.value);
            });
            const url = @json(route('corex.settings.preview-header')) + '?' + params.toString();
            if (this.$refs.headerPreview) {
                this.$refs.headerPreview.src = url;
            }
        },

        refreshSignaturePreview() {
            const form = this.$el;
            const params = new URLSearchParams();
            params.set('user_id', this.sigPreviewUserId);
            const disc = form.querySelector('[name="email_disclaimer"]');
            if (disc) params.set('email_disclaimer', disc.value);
            const popi = form.querySelector('[name="popi_url"]');
            if (popi) params.set('popi_url', popi.value);
            const url = @json(route('corex.settings.preview-signature')) + '?' + params.toString();
            if (this.$refs.sigPreview) {
                this.$refs.sigPreview.src = url;
            }
        },
    };
}
</script>
@endsection
