{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Send the Subscription Agreement (AT-447 follow-up, spec §11.9). --}}
@extends('layouts.corex')

@section('corex-content')
<style>
    /* Editable boxes on this form read as editable: white, normal text, a visible edge; placeholders and the empty "choose" state are clearly lighter.
       (The theme's input colour equals the card colour here, which made every box look disabled.) */
    .send-agreement-form .ds-field { background: #ffffff; color: #0f172a; border: 1px solid #94a3b8; border-radius: 6px; padding: .5rem .7rem; }
    .send-agreement-form .ds-field:focus { border-color: #0ea5e9; box-shadow: 0 0 0 2px rgba(14,165,233,.2); outline: none; }
    .send-agreement-form .ds-field::placeholder { color: #94a3b8; opacity: 1; }
    .send-agreement-form select.ds-field:invalid { color: #94a3b8; }
    .send-agreement-form select.ds-field option { color: #0f172a; }
    html.dark .send-agreement-form .ds-field, :root[data-theme="dark"] .send-agreement-form .ds-field { background: #0b1220; color: #f1f5f9; border-color: #64748b; }
    html.dark .send-agreement-form .ds-field::placeholder, :root[data-theme="dark"] .send-agreement-form .ds-field::placeholder { color: #64748b; }
    html.dark .send-agreement-form select.ds-field:invalid, :root[data-theme="dark"] .send-agreement-form select.ds-field:invalid { color: #64748b; }
    html.dark .send-agreement-form select.ds-field option, :root[data-theme="dark"] .send-agreement-form select.ds-field option { color: #f1f5f9; background: #0b1220; }
</style>
<div class="w-full space-y-5">
    @include('platform-esign._header', ['title' => 'Send Subscription Agreement', 'tab' => 'agreement',
        'sub' => 'The CoreX OS Subscription Agreement (' . $version->label() . ') with the debit order mandate — the recipient fills it in, initials every page and signs; you countersign.'])

    <form method="POST" action="{{ route('platform-esign.agreements.store') }}" class="send-agreement-form rounded-md p-5 space-y-4 max-w-3xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @error('send')<div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-crimson-bg, #fef2f2); color: var(--ds-crimson);">{{ $message }}</div>@enderror

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="ds-label block mb-1" for="name">Recipient full name <span style="color: var(--ds-crimson);">*</span></label>
                <input id="name" name="name" value="{{ old('name', $start['name']) }}" required maxlength="255" class="ds-field w-full" autocomplete="off" placeholder="e.g. Pat Principal">
                @error('name')<p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ds-label block mb-1" for="email">Email address <span style="color: var(--ds-crimson);">*</span></label>
                <input id="email" name="email" type="email" value="{{ old('email', $start['email']) }}" required maxlength="255" class="ds-field w-full" autocomplete="off" placeholder="name@agency.co.za">
                @error('email')<p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="ds-label block mb-1" for="cell">Cell number <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                <input id="cell" name="cell" value="{{ old('cell', $start['cell']) }}" maxlength="40" class="ds-field w-full" autocomplete="off" placeholder="e.g. 082 555 0123">
            </div>
            <div>
                <label class="ds-label block mb-1" for="agency_id">Existing agency <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                <select id="agency_id" name="agency_id" class="ds-field w-full" data-prefill="{{ json_encode($prefill) }}">
                    <option value="">None — a new client</option>
                    @foreach($agencies as $a)<option value="{{ $a->id }}" @selected((int) old('agency_id', $agencyId) === $a->id)>{{ $a->name }}</option>@endforeach
                </select>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Pre-fills what the agency record already holds (the recipient can correct it) and ties the signed agreement to its Agency Timeline.</p>
            </div>
        </div>

        <div>
            <label class="ds-label block mb-1" for="take_on_month">Take-on month <span style="color: var(--ds-crimson);">*</span></label>
            <select id="take_on_month" name="take_on_month" required class="ds-field" style="min-width: 16rem;">
                <option value="" disabled hidden @selected(old('take_on_month') === null || old('take_on_month') === '')>Choose the take-on month…</option>
                @foreach($takeOnOptions as $o)
                    <option value="{{ $o['value'] }}" data-start="{{ $o['start'] }}" data-billing="{{ $o['billing'] }}" @selected(old('take_on_month') === $o['value'])>{{ $o['label'] }}</option>
                @endforeach
            </select>
            @error('take_on_month')<p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
            <p class="text-sm mt-2" style="color: var(--text-primary);" id="take-on-dates" aria-live="polite"></p>
            <p class="text-xs mt-1" style="color: var(--text-muted);">The month the agency goes live is free. The agreement starts on the 1st of this month and billing (the first debit) starts on the 1st of the next month. The agency cannot change these dates.</p>
        </div>

        <div>
            <label class="ds-label block mb-1" for="note">Short note in the email <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
            <textarea id="note" name="note" rows="2" maxlength="490" class="ds-field w-full" placeholder="A line or two for the recipient (optional)">{{ old('note') }}</textarea>
        </div>

        <details class="rounded-md p-3" style="border: 1px solid var(--border);" @if(old('variation_text') || old('variation_amount')) open @endif>
            <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">Agreed variation or discount <span class="font-normal" style="color: var(--text-muted);">(optional — set now, the recipient sees it before signing)</span></summary>
            <div class="grid sm:grid-cols-3 gap-3 mt-3">
                <div class="sm:col-span-2">
                    <label class="ds-label block mb-1" for="variation_text">Describe it</label>
                    <input id="variation_text" name="variation_text" value="{{ old('variation_text') }}" maxlength="500" class="ds-field w-full" placeholder="e.g. Launch discount for the first 3 months">
                </div>
                <div>
                    <label class="ds-label block mb-1" for="variation_amount">Monthly discount (R)</label>
                    <input id="variation_amount" name="variation_amount" inputmode="decimal" value="{{ old('variation_amount') }}" maxlength="14" class="ds-field w-full" placeholder="e.g. 500">
                </div>
            </div>
            <p class="text-xs mt-2" style="color: var(--text-muted);">Set before sending, so the monthly total and the debit order amount are what the agency actually signs. To change it later, void this one and send again.</p>
        </details>

        <details class="rounded-md p-3" style="border: 1px solid var(--border);" @if(old('plan')) open @endif>
            <summary class="text-sm font-semibold cursor-pointer" style="color: var(--text-primary);">Fix the plan <span class="font-normal" style="color: var(--text-muted);">(optional — only for a negotiated case)</span></summary>
            <div class="mt-3">
                <label class="ds-label block mb-1" for="plan">Plan</label>
                <select id="plan" name="plan" class="ds-field">
                    <option value="">Automatic — CoreX Team up to 10 agents, CoreX Agency above that</option>
                    <option value="team" @selected(old('plan') === 'team')>Always CoreX Team</option>
                    <option value="agency" @selected(old('plan') === 'agency')>Always CoreX Agency</option>
                </select>
                <p class="text-xs mt-2" style="color: var(--text-muted);">Normally the plan follows the number of agents the recipient enters. Fixing it is recorded in the agreement's history and the recipient sees the plan fixed.</p>
            </div>
        </details>

        <div class="flex flex-wrap items-center gap-3 pt-1">
            <button class="corex-btn-primary" type="submit">Send Subscription Agreement</button>
            <span class="text-xs" style="color: var(--text-muted);">The link is valid for {{ $expiryDays }} days. You can resend it or copy the link afterwards.</span>
        </div>
    </form>
</div>
<script>
// Show the two dates the chosen take-on month gives, before sending.
(function () {
    const sel = document.getElementById('take_on_month'), out = document.getElementById('take-on-dates');
    if (!sel || !out) return;
    const show = () => { const o = sel.options[sel.selectedIndex]; out.innerHTML = (o && o.value) ? 'Take-on month <strong>' + o.text + '</strong> · billing starts <strong>' + o.dataset.billing + '</strong>' : ''; };
    sel.addEventListener('change', show); show();
})();
</script>
<script>
// Picking an agency fills the recipient's name / email / cell from the agency's principal (the owner can still correct them).
// Only fields the owner has not already typed into themselves are replaced.
(function () {
    const sel = document.getElementById('agency_id');
    if (!sel) return;
    const map = JSON.parse(sel.dataset.prefill || '{}');
    const fields = { name: document.getElementById('name'), email: document.getElementById('email'), cell: document.getElementById('cell') };
    Object.values(fields).forEach(f => { f.dataset.filled = f.value; });
    sel.addEventListener('change', () => {
        const p = map[sel.value];
        Object.entries(fields).forEach(([k, f]) => {
            if (f.value === f.dataset.filled) { f.value = p ? (p[k] || '') : ''; f.dataset.filled = f.value; }
        });
    });
})();
</script>
@endsection
