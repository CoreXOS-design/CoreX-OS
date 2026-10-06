{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Platform Company Profile (RR Technologies). Owner-only.
     Spec: .ai/specs/platform-company-profile.md §5. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $fmt = function ($v) {
        if (is_bool($v)) return $v ? 'yes' : 'no';
        if ($v === null || $v === '' || $v === []) return '—';
        if (is_array($v)) {
            return collect($v)->map(fn ($x) => is_array($x) ? implode(' ', array_filter(array_map('strval', array_values($x)))) : (string) $x)->implode('; ');
        }
        return (string) $v;
    };
    $bank = old('bank_details', $company->bank_details ?? []);
    // Which section to open when the page loads with validation errors: the one holding the first bad field.
    $sectionOf = fn (string $k) => match (true) {
        str_starts_with($k, 'bank_details') => 'bank',
        $k === 'email_signature_html' => 'signature',
        in_array($k, ['strap_line', 'letterhead_footer'], true) => 'letterhead',
        default => 'details',
    };
    $startSection = $errors->any() ? $sectionOf((string) array_key_first($errors->messages())) : null;
    $navIcons = [
        'logo'        => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="M21 16l-5-5L5 21"/>',
        'details'     => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 8h2M13 8h2M9 12h2M13 12h2M10 21v-4h4v4"/>',
        'letterhead'  => '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'signature'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'bank'        => '<path d="M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18"/>',
        'history'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $navItems = ['logo' => 'Logo', 'details' => 'Company details', 'letterhead' => 'Letterhead', 'signature' => 'Email signature', 'bank' => 'Bank details', 'history' => 'History'];
    $cfg = [
        'url'        => route('admin.platform-company.preview'),
        'vat'        => (bool) old('vat_registered', $company->vat_registered),
        'directors'  => array_values(old('directors', $company->directors ?: [])) ?: [['name' => '', 'title' => '']],
        'phones'     => array_values(old('phones', $company->phones ?: [])) ?: [['label' => '', 'number' => '']],
        'websites'   => array_values(old('websites', $company->websites ?: [])) ?: [''],
        'sig'        => (string) old('email_signature_html', $company->email_signature_html),
        'start'      => $startSection,
        'preview'    => ['web' => $previewWeb, 'pdf' => $previewPdf, 'footer' => $previewFooter, 'signature' => $previewSignature, 'standard' => $standardSignature],
    ];
@endphp
<style>
    .pc-shell { display: grid; grid-template-columns: 13.5rem minmax(0, 1fr); gap: 1.25rem; align-items: start; }
    .pc-stack { display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
    .pc-stack > * { min-width: 0; }
    .pc-nav { position: sticky; top: 1rem; display: flex; flex-direction: column; gap: 2px; padding: 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .pc-nav a { display: flex; align-items: center; gap: .6rem; padding: .5rem .7rem; border-radius: 6px; border-left: 3px solid transparent; font-size: .8125rem; font-weight: 500; color: var(--text-secondary); text-decoration: none; white-space: nowrap; }
    .pc-nav a:hover { background: var(--surface-2); color: var(--text-primary); }
    .pc-nav a.pc-on { background: color-mix(in srgb, var(--brand-icon) 12%, transparent); border-left-color: var(--brand-icon); color: var(--text-primary); font-weight: 600; }
    .pc-nav svg { width: 16px; height: 16px; flex: none; stroke: var(--brand-icon); fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .pc-savebar { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: .6rem .9rem; padding: .65rem 1rem; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 -4px 16px rgba(0,0,0,.08); }
    .pc-vers { display: grid; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr)); gap: .75rem; }
    .pc-ver { display: flex; flex-direction: column; gap: .35rem; padding: .5rem; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); }
    .pc-ver.pc-ver-on { border-color: var(--brand-icon); box-shadow: 0 0 0 1px var(--brand-icon); }
    .pc-ver-img { display: flex; align-items: center; justify-content: center; background: #fff; height: 3.5rem; border-radius: 4px; }
    .pc-inuse { display: inline-flex; align-items: center; gap: .3rem; align-self: flex-start; font-size: .6875rem; font-weight: 600; padding: 2px 9px; border-radius: 999px; background: color-mix(in srgb, var(--ds-green) 14%, transparent); color: var(--ds-green); }
    .pc-drop { display: flex; flex-direction: column; align-items: center; gap: .3rem; text-align: center; padding: 1.25rem 1rem; border: 1.5px dashed var(--border-hover); border-radius: 8px; background: var(--surface-2); cursor: pointer; transition: border-color 150ms, background 150ms; }
    .pc-drop:hover, .pc-drop.pc-over { border-color: var(--brand-icon); background: color-mix(in srgb, var(--brand-icon) 8%, transparent); }
    .pc-drop input[type=file] { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .pc-back { position: fixed; inset: 0; z-index: 1000; background: rgba(5, 10, 20, .45); }
    .pc-drawer { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1001; width: min(36rem, 100%); display: flex; flex-direction: column; background: var(--bg); border-left: 1px solid var(--border); box-shadow: 0 12px 40px rgba(0,0,0,.35); }
    .pc-drawer-h { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .85rem 1.1rem; background: var(--surface); border-bottom: 1px solid var(--border); }
    .pc-drawer-b { flex: 1; overflow-y: auto; padding: 1.1rem; display: flex; flex-direction: column; gap: 1.1rem; }
    @media (max-width: 1023px) {
        .pc-shell { grid-template-columns: minmax(0, 1fr); }
        .pc-nav { top: 0; z-index: 6; flex-direction: row; overflow-x: auto; }
    }
</style>
<div class="w-full space-y-5" x-data="platformCompanyForm(@js($cfg))" @keydown.escape.window="drawer = false">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Company &mdash; RR Technologies</h1>
        <p class="text-sm text-white/60">The one company record behind CoreX's own contracts and emails: logo, details, letterhead and email signature. Not an agency &mdash; no agency can see it.</p>
    </div>
    @include('admin.partials.platform-flash')

    <div class="pc-shell">
    <nav class="pc-nav" aria-label="Company sections">
        @foreach($navItems as $id => $label)
            <a href="#pc-{{ $id }}" @click.prevent="go('{{ $id }}')" :class="sec === '{{ $id }}' ? 'pc-on' : ''" :aria-current="sec === '{{ $id }}' ? 'page' : null">
                <svg viewBox="0 0 24 24" aria-hidden="true">{!! $navIcons[$id] !!}</svg>{{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="pc-stack">

    {{-- ── Logo (own forms: applies immediately) ── --}}
    <div id="pc-logo" data-pc-section="logo" x-show="sec === 'logo'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ dirty: false, fileName: '', over: false }" @pc-dirty.window="dirty = true">
        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">Logo</div>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Used on the letterhead, the contract and platform emails. PNG, JPG or SVG, up to 2 MB. Replacing it keeps the old one &mdash; nothing is ever deleted. Logo changes apply straight away.</p>
        </div>
        <div class="p-5 grid lg:grid-cols-[18rem_1fr] gap-6">
            <div>
                <div class="rounded-md flex items-center justify-center p-4" style="background:#fff; border:1px solid var(--border); min-height:7rem;">
                    <img src="{{ $company->logoUrl() }}" alt="Current logo" style="max-height:72px; max-width:100%;">
                </div>
                <div class="text-xs mt-1" style="color: var(--text-muted);">
                    {{ $company->logo_id ? 'Uploaded logo in use.' : 'Using the built-in CoreX OS logo until one is uploaded.' }}
                    @php($lm = $company->logoMetrics())
                    @if($lm['nat_w'])<br>Image size: {{ $lm['nat_w'] }} × {{ $lm['nat_h'] }} px (shape {{ rtrim(rtrim(number_format($lm['ratio'], 2, '.', ''), '0'), '.') }} : 1)@endif
                </div>
            </div>
            <div class="space-y-4">
                <form method="POST" action="{{ route('admin.platform-company.logo.store') }}" enctype="multipart/form-data" class="space-y-2"
                      @submit="if (dirty && ! confirm('You have unsaved changes in the form below. Uploading a logo reloads the page and they will be lost. Continue?')) $event.preventDefault()">
                    @csrf
                    <label class="pc-drop" :class="over ? 'pc-over' : ''" for="pc-logo-file"
                           @dragover.prevent="over = true" @dragleave="over = false"
                           @drop.prevent="over = false; $refs.logoFile.files = $event.dataTransfer.files; fileName = $refs.logoFile.files[0]?.name ?? ''">
                        <input id="pc-logo-file" x-ref="logoFile" type="file" name="logo" required accept="image/png,image/jpeg,image/svg+xml,.png,.jpg,.jpeg,.svg" @change="fileName = $event.target.files[0]?.name ?? ''">
                        <svg viewBox="0 0 24 24" aria-hidden="true" style="width:26px;height:26px;stroke:var(--brand-icon);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>
                        <span class="text-sm font-semibold" style="color: var(--text-primary);" x-text="fileName || 'Drop a logo here or click to browse'"></span>
                        <span class="text-xs" style="color: var(--text-muted);">PNG, JPG or SVG, up to 2 MB. Replacing it keeps the old one.</span>
                    </label>
                    <button type="submit" class="corex-btn-primary" x-show="fileName" x-cloak>Upload logo</button>
                </form>
                <div class="text-xs rounded-md p-3" id="pc-logo-hint" style="background: var(--surface-2, #f1f5f9); border: 1px solid var(--border); color: var(--text-muted); max-width: 46rem;">
                    <strong style="color: var(--text-primary);">Recommended logo:</strong> the logo on its own — landscape, about <strong>3 : 1</strong> (for example <strong>900 × 300 px</strong>), trimmed tight to the mark with <strong>no empty space</strong> around it,
                    on a transparent (PNG) or white background — or an SVG. At least <strong>300 px tall</strong> so it prints crisply; PNG, JPG or SVG, up to 2 MB.
                    It is shown about <strong>60 px high</strong> on screen and in the PDFs (never larger than the image itself, and never wider than 45% of the letterhead).
                    Please do not upload a whole letterhead page with the address on it: the address and contact details are added from the fields below, and a tall image with empty margins makes the logo look tiny.
                </div>

                <div>
                    <div class="ds-label mb-2">Versions</div>
                    <div class="pc-vers">
                        <div class="pc-ver {{ $company->logo_id ? '' : 'pc-ver-on' }}">
                            <div class="pc-ver-img"><img src="{{ asset('images/corex-os-logo.svg') }}" alt="Built-in logo" style="max-height:44px; max-width:100%;"></div>
                            <div class="text-xs font-semibold" style="color: var(--text-primary);">Built-in CoreX OS</div>
                            @if($company->logo_id)
                                <form method="POST" action="{{ route('admin.platform-company.logo.restore', 'built-in') }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Use this</button></form>
                            @else<span class="pc-inuse">In use</span>@endif
                        </div>
                        @foreach($logos as $logo)
                            <div class="pc-ver {{ $company->logo_id === $logo->id ? 'pc-ver-on' : '' }}">
                                <div class="pc-ver-img"><img src="{{ route('admin.platform-company.logo.version', $logo->id) }}" alt="Logo version {{ $logo->id }}" style="max-height:44px; max-width:100%;"></div>
                                <div class="text-xs font-semibold truncate" style="color: var(--text-primary);" title="{{ $logo->original_name }}">{{ $logo->original_name ?: 'Logo #' . $logo->id }}</div>
                                <div class="text-xs" style="color: var(--text-muted);">{{ $logo->created_at?->format('j M Y H:i') }}{{ $logo->uploader ? ' · ' . $logo->uploader->name : '' }}</div>
                                @if($company->logo_id === $logo->id)
                                    <span class="pc-inuse">In use</span>
                                @else
                                    <form method="POST" action="{{ route('admin.platform-company.logo.restore', $logo->id) }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore this one</button></form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Main form: details + letterhead + signature + bank, one Save ── --}}
    <form method="POST" action="{{ route('admin.platform-company.update') }}" x-ref="form"
          @input="queue()" @change="queue()" @submit="onSubmit($event)">
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $company->version }}">

        <div class="pc-stack">
            {{-- Company details --}}
            <div id="pc-details" data-pc-section="details" x-show="sec === 'details'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Company details</div></div>
                <div class="p-5 space-y-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="ds-label block mb-1" for="pc-legal">Legal name <span style="color:var(--ds-crimson);">*</span></label>
                            <input id="pc-legal" name="legal_name" required maxlength="255" value="{{ old('legal_name', $company->legal_name) }}" class="ds-field w-full"></div>
                        <div><label class="ds-label block mb-1" for="pc-trading">Trading name / brand <span style="color:var(--ds-crimson);">*</span></label>
                            <input id="pc-trading" name="trading_name" required maxlength="255" value="{{ old('trading_name', $company->trading_name) }}" class="ds-field w-full"></div>
                        <div><label class="ds-label block mb-1" for="pc-reg">Registration number</label>
                            <input id="pc-reg" name="registration_number" maxlength="100" value="{{ old('registration_number', $company->registration_number) }}" class="ds-field w-full"></div>
                        <div>
                            <label class="flex items-center gap-2 text-sm mb-1 mt-6"><input type="hidden" name="vat_registered" value="0"><input type="checkbox" name="vat_registered" value="1" x-model="vat"> VAT registered</label>
                            <input name="vat_number" x-show="vat" x-cloak inputmode="numeric" maxlength="20" placeholder="VAT number (10 digits)" value="{{ old('vat_number', $company->vat_number) }}" class="ds-field w-full" aria-label="VAT number">
                            <p class="text-xs" style="color: var(--text-muted);" x-show="!vat">Not VAT registered &mdash; no VAT line is printed on the letterhead.</p>
                        </div>
                    </div>

                    <div>
                        <div class="ds-label mb-1">Directors</div>
                        <template x-for="(d, i) in directors" :key="i">
                            <div class="flex gap-2 mb-2">
                                <input :name="'directors['+i+'][name]'" x-model="d.name" maxlength="150" placeholder="Full name" class="ds-field flex-1" aria-label="Director name">
                                <input :name="'directors['+i+'][title]'" x-model="d.title" maxlength="100" placeholder="Title (optional)" class="ds-field" style="width:10rem;" aria-label="Director title">
                                <button type="button" class="corex-btn-outline text-xs" @click="directors.splice(i,1); queue()" aria-label="Remove director">Remove</button>
                            </div>
                        </template>
                        <button type="button" class="corex-btn-outline text-xs" x-show="directors.length < 10" @click="directors.push({name:'',title:''})">+ Add director</button>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="ds-label block mb-1" for="pc-phys">Physical address</label>
                            <textarea id="pc-phys" name="physical_address" rows="4" maxlength="500" class="ds-field w-full">{{ old('physical_address', $company->physical_address) }}</textarea></div>
                        <div><label class="ds-label block mb-1" for="pc-post">Postal address <span style="color: var(--text-muted);">(optional)</span></label>
                            <textarea id="pc-post" name="postal_address" rows="4" maxlength="500" class="ds-field w-full">{{ old('postal_address', $company->postal_address) }}</textarea></div>
                    </div>

                    <div class="grid sm:grid-cols-3 gap-4">
                        <div><label class="ds-label block mb-1" for="pc-email">General / admin email <span style="color:var(--ds-crimson);">*</span></label>
                            <input id="pc-email" type="email" name="email_general" required maxlength="255" value="{{ old('email_general', $company->email_general) }}" class="ds-field w-full"></div>
                        <div><label class="ds-label block mb-1" for="pc-support">Support email</label>
                            <input id="pc-support" type="email" name="email_support" maxlength="255" value="{{ old('email_support', $company->email_support) }}" class="ds-field w-full"></div>
                        <div><label class="ds-label block mb-1" for="pc-acc">Accounts email</label>
                            <input id="pc-acc" type="email" name="email_accounts" maxlength="255" value="{{ old('email_accounts', $company->email_accounts) }}" class="ds-field w-full"></div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div><label class="ds-label block mb-1" for="pc-from">Sending address <span style="color:var(--ds-crimson);">*</span></label>
                            <input id="pc-from" type="email" name="send_from_address" required maxlength="255" value="{{ old('send_from_address', $company->send_from_address) }}" class="ds-field w-full">
                            <p class="text-xs mt-1" style="color: var(--text-muted);">Every Platform E-Sign and Subscription Agreement email is sent from this address. Replies go to the person who sent the agreement.</p></div>
                        <div><label class="ds-label block mb-1" for="pc-from-name">Sender name <span style="color:var(--ds-crimson);">*</span></label>
                            <input id="pc-from-name" type="text" name="send_from_name" required maxlength="150" value="{{ old('send_from_name', $company->send_from_name) }}" class="ds-field w-full">
                            <p class="text-xs mt-1" style="color: var(--text-muted);">The name the recipient sees beside that address.</p></div>
                    </div>

                    <div>
                        <div class="ds-label mb-1">Telephone numbers</div>
                        <template x-for="(p, i) in phones" :key="i">
                            <div class="flex gap-2 mb-2">
                                <input :name="'phones['+i+'][label]'" x-model="p.label" maxlength="40" placeholder="Label (e.g. Telephone)" class="ds-field" style="width:12rem;" aria-label="Phone label">
                                <input :name="'phones['+i+'][number]'" x-model="p.number" maxlength="40" placeholder="Number" class="ds-field flex-1" aria-label="Phone number">
                                <button type="button" class="corex-btn-outline text-xs" @click="phones.splice(i,1); queue()" aria-label="Remove phone">Remove</button>
                            </div>
                        </template>
                        <button type="button" class="corex-btn-outline text-xs" x-show="phones.length < 8" @click="phones.push({label:'',number:''})">+ Add number</button>
                    </div>

                    <div>
                        <div class="ds-label mb-1">Websites</div>
                        <template x-for="(w, i) in websites" :key="i">
                            <div class="flex gap-2 mb-2">
                                <input :name="'websites['+i+']'" x-model="websites[i]" maxlength="255" placeholder="www.example.co.za" class="ds-field flex-1" aria-label="Website">
                                <button type="button" class="corex-btn-outline text-xs" @click="websites.splice(i,1); queue()" aria-label="Remove website">Remove</button>
                            </div>
                        </template>
                        <button type="button" class="corex-btn-outline text-xs" x-show="websites.length < 5" @click="websites.push('')">+ Add website</button>
                    </div>
                </div>
            </div>

            {{-- Letterhead --}}
            <div id="pc-letterhead" data-pc-section="letterhead" x-show="sec === 'letterhead'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                    <div class="ds-section-header">Letterhead</div>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Logo on the left, company block on the right &mdash; built from the details above. Contracts and PDFs use exactly this.</p>
                </div>
                <div class="p-5 space-y-4">
                    <div><label class="ds-label block mb-1" for="pc-strap">Strap line <span style="color: var(--text-muted);">(optional, under the logo)</span></label>
                        <input id="pc-strap" name="strap_line" maxlength="160" value="{{ old('strap_line', $company->strap_line) }}" class="ds-field w-full"></div>
                    <div><label class="ds-label block mb-1" for="pc-foot">Footer text <span style="color: var(--text-muted);">(leave empty to use: legal name · registration · VAT · directors)</span></label>
                        <textarea id="pc-foot" name="letterhead_footer" rows="2" maxlength="500" class="ds-field w-full">{{ old('letterhead_footer', $company->letterhead_footer) }}</textarea></div>
                </div>
            </div>

            {{-- Email signature --}}
            <div id="pc-signature" data-pc-section="signature" x-show="sec === 'signature'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                    <div class="ds-section-header">Email signature</div>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Added to every email Platform E-Sign sends. Leave empty to use the standard signature, which follows the details above automatically.</p>
                </div>
                <div class="p-5 space-y-3">
                    <label class="ds-label block" for="pc-sig">Signature (HTML)</label>
                    <textarea id="pc-sig" name="email_signature_html" rows="8" maxlength="20000" x-model="sig" class="ds-field w-full font-mono text-xs" spellcheck="false"></textarea>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button type="button" class="corex-btn-outline text-xs" @click="sig = preview.standard; queue()">Insert the standard signature to edit it</button>
                        <button type="button" class="corex-btn-outline text-xs" x-show="sig !== ''" @click="sig = ''; queue()">Clear (use standard)</button>
                        <span class="text-xs" style="color: var(--text-muted);">Scripts, forms and unsafe links are removed automatically when you save.</span>
                    </div>
                </div>
            </div>

            {{-- Bank details --}}
            <div id="pc-bank" data-pc-section="bank" x-show="sec === 'bank'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                    <div class="ds-section-header">Bank details <span class="text-xs font-normal" style="color: var(--text-muted);">(optional &mdash; for invoices)</span></div>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Stored encrypted. Never printed on the letterhead and never written to the history in clear.</p>
                </div>
                <div class="p-5 grid sm:grid-cols-2 gap-4">
                    <div><label class="ds-label block mb-1" for="pc-bn">Bank</label><input id="pc-bn" name="bank_details[bank_name]" maxlength="100" value="{{ $bank['bank_name'] ?? '' }}" class="ds-field w-full"></div>
                    <div><label class="ds-label block mb-1" for="pc-bh">Account holder</label><input id="pc-bh" name="bank_details[account_holder]" maxlength="150" value="{{ $bank['account_holder'] ?? '' }}" class="ds-field w-full"></div>
                    <div><label class="ds-label block mb-1" for="pc-ba">Account number</label><input id="pc-ba" name="bank_details[account_number]" inputmode="numeric" maxlength="24" value="{{ $bank['account_number'] ?? '' }}" class="ds-field w-full"></div>
                    <div><label class="ds-label block mb-1" for="pc-bb">Branch code</label><input id="pc-bb" name="bank_details[branch_code]" inputmode="numeric" maxlength="10" value="{{ $bank['branch_code'] ?? '' }}" class="ds-field w-full"></div>
                    <div><label class="ds-label block mb-1" for="pc-bt">Account type</label>
                        <select id="pc-bt" name="bank_details[account_type]" class="ds-field w-full">
                            <option value="">—</option>
                            @foreach(['cheque' => 'Cheque / current', 'savings' => 'Savings', 'transmission' => 'Transmission'] as $k => $l)
                                <option value="{{ $k }}" @selected(($bank['account_type'] ?? '') === $k)>{{ $l }}</option>
                            @endforeach
                        </select></div>
                    <div><label class="ds-label block mb-1" for="pc-br">Payment reference note</label><input id="pc-br" name="bank_details[reference_note]" maxlength="150" value="{{ $bank['reference_note'] ?? '' }}" class="ds-field w-full"></div>
                </div>
            </div>

            <div class="pc-savebar" x-show="['details', 'letterhead', 'signature', 'bank'].includes(sec)" x-cloak>
                <button type="submit" class="corex-btn-primary">Save company profile</button>
                <button type="button" class="corex-btn-outline" @click="drawer = true">Preview</button>
                <span class="text-xs" style="color: var(--text-muted);" x-show="dirty" x-cloak>Unsaved changes</span>
            </div>
        </div>

        {{-- Live preview (slides in from the right, opened from the save bar) --}}
        <div class="pc-back" x-show="drawer" x-cloak @click="drawer = false"></div>
        <aside class="pc-drawer" x-show="drawer" x-cloak aria-label="Live preview">
        <div class="pc-drawer-h">
            <div class="ds-section-header">Live preview</div>
            <button type="button" class="corex-btn-outline text-xs" @click="drawer = false">Close</button>
        </div>
        <div class="pc-drawer-b">
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
                    <div class="ds-section-header">Preview &mdash; on screen</div>
                    <span class="text-xs" style="color: var(--text-muted);" x-show="busy" x-cloak>updating…</span>
                </div>
                <div class="p-4"><div style="background:#fff; padding:18px 20px; border:1px solid var(--border);" id="pc-prev-web">
                    <div x-html="preview.web"></div><div x-html="preview.footer"></div></div></div>
            </div>
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Preview &mdash; PDF (A4)</div></div>
                {{-- Rendered at true A4 content width (190 mm ≈ 718 px) and zoomed down to fit, so what you see is what the PDF gets. --}}
                <div class="p-4" x-data="{ z: 1 }" x-init="const fit = () => { z = Math.max(0.2, Math.min(1, ($el.clientWidth - 32) / 718)); }; fit(); new ResizeObserver(fit).observe($el)">
                    <div style="background:#fff; color:#111; border:1px solid var(--border); box-shadow:0 1px 4px rgba(0,0,0,.12); width:fit-content; max-width:100%; overflow:hidden;">
                        <div id="pc-prev-pdf" style="width:718px; padding:18px 0 14px; box-sizing:border-box;" :style="'zoom:' + z" x-html="preview.pdf"></div>
                    </div>
                </div>
            </div>
            <div class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);"><div class="ds-section-header">Preview &mdash; email signature</div></div>
                <div class="p-4"><div style="background:#fff; padding:16px 18px; border:1px solid var(--border);" id="pc-prev-sig" x-html="preview.signature"></div></div>
            </div>
        </div>
        </aside>
    </form>

    {{-- ── History ── --}}
    <div id="pc-history" data-pc-section="history" x-show="sec === 'history'" x-cloak class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3" style="border-bottom: 1px solid var(--border);">
            <div class="ds-section-header">History &mdash; who changed what</div>
            <form method="GET" action="{{ route('admin.platform-company.index') }}#pc-history" class="flex items-center gap-2">
                <label class="ds-label" for="pc-hf">Show</label>
                <select id="pc-hf" name="action" class="ds-field" onchange="this.form.submit()">
                    @foreach(['' => 'All changes', 'updated' => 'Details edited', 'logo_uploaded' => 'Logo uploaded', 'logo_restored' => 'Logo restored'] as $k => $l)
                        <option value="{{ $k }}" @selected($action === $k)>{{ $l }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @forelse($history as $h)
            <div class="px-5 py-3" style="border-top: 1px solid var(--border);">
                <div class="flex flex-wrap items-baseline gap-x-3 text-sm">
                    <span class="font-semibold" style="color: var(--text-primary);">{{ $h->summary }}</span>
                    <span class="text-xs" style="color: var(--text-muted);">{{ $h->user?->name ?? 'System' }} · {{ $h->created_at?->format('j M Y H:i') }}</span>
                </div>
                @if($h->changes)
                    <details class="mt-1 text-xs" style="color: var(--text-secondary);">
                        <summary class="cursor-pointer" style="color: var(--text-muted);">What changed</summary>
                        <ul class="mt-1 space-y-0.5">
                            @foreach($h->changes as $field => $c)
                                <li><strong>{{ $labels[$field] ?? $field }}:</strong> {{ $fmt($c['from'] ?? null) }} <span style="color: var(--text-muted);">→</span> {{ $fmt($c['to'] ?? null) }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @empty
            <div class="px-5 py-8 text-sm text-center" style="color: var(--text-muted);">No changes recorded yet. Edits to the company profile and logo will be listed here.</div>
        @endforelse
        @if($history->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $history->fragment('pc-history')->links() }}</div>@endif
    </div>
    </div>{{-- /content column --}}
    </div>{{-- /pc-shell --}}
</div>

<script>
function platformCompanyForm(cfg) {
    return {
        directors: cfg.directors, phones: cfg.phones, websites: cfg.websites, vat: cfg.vat, sig: cfg.sig,
        preview: cfg.preview, busy: false, dirty: false, timer: null, seq: 0,
        sec: 'logo', drawer: false,
        sections: ['logo', 'details', 'letterhead', 'signature', 'bank', 'history'],
        init() {
            // Priority: a section with a server error > the #hash (history filter, old links) > the section we were on before a save.
            const fromHash = () => { const h = location.hash.replace('#pc-', ''); return this.sections.includes(h) ? h : null; };
            let remembered = null;
            try { remembered = sessionStorage.getItem('pc-section'); sessionStorage.removeItem('pc-section'); } catch (e) { /* storage blocked: start on Logo */ }
            this.sec = cfg.start || fromHash() || (this.sections.includes(remembered) ? remembered : 'logo');
            window.addEventListener('hashchange', () => { const h = fromHash(); if (h) { this.sec = h; } });
        },
        go(id) {
            this.sec = id;
            history.replaceState(null, '', '#pc-' + id);
            document.getElementById('appScroll')?.scrollTo({ top: 0 });
        },
        onSubmit(e) {
            const f = this.$refs.form;
            if (! f.checkValidity()) {
                // A required field in a hidden section would block the save silently — open that section first.
                e.preventDefault();
                const bad = f.querySelector('input:invalid, select:invalid, textarea:invalid');
                const sec = bad?.closest('[data-pc-section]')?.dataset.pcSection;
                if (sec) { this.sec = sec; }
                this.$nextTick(() => f.reportValidity());
                return;
            }
            try { sessionStorage.setItem('pc-section', this.sec); } catch (err) { /* ignore */ }
        },
        queue() {
            this.dirty = true;
            this.$dispatch('pc-dirty');
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), 450);
        },
        async refresh() {
            const mine = ++this.seq;
            this.busy = true;
            try {
                const fd = new FormData(this.$refs.form);
                fd.delete('_method');
                const res = await fetch(cfg.url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                if (res.ok && mine === this.seq) { this.preview = await res.json(); }
            } catch (e) { /* preview is a convenience — the saved values are what matter */ }
            finally { if (mine === this.seq) { this.busy = false; } }
        },
    };
}
</script>
@endsection
