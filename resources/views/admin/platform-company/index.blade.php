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
    $cfg = [
        'url'        => route('admin.platform-company.preview'),
        'vat'        => (bool) old('vat_registered', $company->vat_registered),
        'directors'  => array_values(old('directors', $company->directors ?: [])) ?: [['name' => '', 'title' => '']],
        'phones'     => array_values(old('phones', $company->phones ?: [])) ?: [['label' => '', 'number' => '']],
        'websites'   => array_values(old('websites', $company->websites ?: [])) ?: [''],
        'sig'        => (string) old('email_signature_html', $company->email_signature_html),
        'preview'    => ['web' => $previewWeb, 'pdf' => $previewPdf, 'footer' => $previewFooter, 'signature' => $previewSignature, 'standard' => $standardSignature],
    ];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Company &mdash; RR Technologies</h1>
        <p class="text-sm text-white/60">The one company record behind CoreX's own contracts and emails: logo, details, letterhead and email signature. Not an agency &mdash; no agency can see it.</p>
    </div>
    @include('admin.partials.platform-flash')

    <div class="flex flex-wrap gap-1 text-sm" style="border-bottom: 1px solid var(--border);">
        @foreach(['pc-logo' => 'Logo', 'pc-details' => 'Company details', 'pc-letterhead' => 'Letterhead', 'pc-signature' => 'Email signature', 'pc-bank' => 'Bank details', 'pc-history' => 'History'] as $anchor => $label)
            <a href="#{{ $anchor }}" class="px-4 py-2 font-medium -mb-px" style="color: var(--text-muted);">{{ $label }}</a>
        @endforeach
    </div>

    {{-- ── Logo (own forms: applies immediately) ── --}}
    <div id="pc-logo" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);" x-data="{ dirty: false }" @pc-dirty.window="dirty = true">
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
                </div>
            </div>
            <div class="space-y-4">
                <form method="POST" action="{{ route('admin.platform-company.logo.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3"
                      @submit="if (dirty && ! confirm('You have unsaved changes in the form below. Uploading a logo reloads the page and they will be lost. Continue?')) $event.preventDefault()">
                    @csrf
                    <div>
                        <label class="ds-label block mb-1" for="pc-logo-file">Upload or replace logo</label>
                        <input id="pc-logo-file" type="file" name="logo" required accept="image/png,image/jpeg,image/svg+xml,.png,.jpg,.jpeg,.svg" class="ds-field">
                    </div>
                    <button type="submit" class="corex-btn-primary">Upload logo</button>
                </form>

                <div>
                    <div class="ds-label mb-2">Versions</div>
                    <div class="flex flex-wrap gap-3">
                        <div class="rounded-md p-2 text-center" style="border:1px solid {{ $company->logo_id ? 'var(--border)' : 'var(--brand-icon)' }}; width:11rem;">
                            <div class="flex items-center justify-center" style="background:#fff; height:3.5rem;"><img src="{{ asset('images/corex-os-logo.svg') }}" alt="Built-in logo" style="max-height:44px; max-width:100%;"></div>
                            <div class="text-xs mt-1 font-semibold" style="color: var(--text-primary);">Built-in CoreX OS</div>
                            @if($company->logo_id)
                                <form method="POST" action="{{ route('admin.platform-company.logo.restore', 'built-in') }}" class="mt-1">@csrf<button type="submit" class="corex-btn-outline text-xs">Use this</button></form>
                            @else<div class="text-xs" style="color: var(--ds-green);">In use</div>@endif
                        </div>
                        @foreach($logos as $logo)
                            <div class="rounded-md p-2 text-center" style="border:1px solid {{ $company->logo_id === $logo->id ? 'var(--brand-icon)' : 'var(--border)' }}; width:11rem;">
                                <div class="flex items-center justify-center" style="background:#fff; height:3.5rem;"><img src="{{ route('admin.platform-company.logo.version', $logo->id) }}" alt="Logo version {{ $logo->id }}" style="max-height:44px; max-width:100%;"></div>
                                <div class="text-xs mt-1 truncate" style="color: var(--text-primary);" title="{{ $logo->original_name }}">{{ $logo->original_name ?: 'Logo #' . $logo->id }}</div>
                                <div class="text-xs" style="color: var(--text-muted);">{{ $logo->created_at?->format('j M Y H:i') }}{{ $logo->uploader ? ' · ' . $logo->uploader->name : '' }}</div>
                                @if($company->logo_id === $logo->id)
                                    <div class="text-xs" style="color: var(--ds-green);">In use</div>
                                @else
                                    <form method="POST" action="{{ route('admin.platform-company.logo.restore', $logo->id) }}" class="mt-1">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore this one</button></form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Main form: details + letterhead + signature + bank, one Save ── --}}
    <form method="POST" action="{{ route('admin.platform-company.update') }}" x-ref="form" x-data="platformCompanyForm(@js($cfg))"
          @input="queue()" @change="queue()" class="grid xl:grid-cols-[minmax(0,1fr)_minmax(0,34rem)] gap-5 items-start">
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $company->version }}">

        <div class="space-y-5 min-w-0">
            {{-- Company details --}}
            <div id="pc-details" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
            <div id="pc-letterhead" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
            <div id="pc-signature" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
            <div id="pc-bank" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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

            <div class="flex items-center gap-3">
                <button type="submit" class="corex-btn-primary">Save company profile</button>
                <span class="text-xs" style="color: var(--text-muted);" x-show="dirty" x-cloak>Unsaved changes</span>
            </div>
        </div>

        {{-- Live preview --}}
        <div class="space-y-5 min-w-0 xl:sticky xl:top-4">
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
                <div class="p-4" x-data="{ z: 1 }" x-init="const fit = () => { z = Math.min(1, ($el.clientWidth - 32) / 718); }; fit(); new ResizeObserver(fit).observe($el)">
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
    </form>

    {{-- ── History ── --}}
    <div id="pc-history" class="rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
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
        @if($history->hasPages())<div class="px-5 py-3" style="border-top: 1px solid var(--border);">{{ $history->links() }}</div>@endif
    </div>
</div>

<script>
function platformCompanyForm(cfg) {
    return {
        directors: cfg.directors, phones: cfg.phones, websites: cfg.websites, vat: cfg.vat, sig: cfg.sig,
        preview: cfg.preview, busy: false, dirty: false, timer: null, seq: 0,
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
