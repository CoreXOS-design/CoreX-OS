@extends('layouts.corex')

@section('content')
<div class="p-6 space-y-4 max-w-3xl">
    <h1 class="text-lg font-semibold">Breach notice - {{ $lease->property?->buildDisplayAddress() ?: ('Lease #' . $lease->id) }}</h1>
    {{-- rental-notices.md §7b - the agency's breach templates are listed here: a payment template asks for the amount outstanding, an ad hoc one for the party, the clause and the reason --}}
    <p class="text-sm" style="color: var(--text-muted);">Choose the notice to send. A <strong>payment</strong> template asks for the amount outstanding; an <strong>ad hoc</strong> one for the clause and what the breach is. The notice is made from your own template, sent from your mailbox, and the other party is told. <span data-qa="breach-window">{{ $remedyWindow }}</span> are allowed to put it right.</p>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);" data-qa="breach-errors">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($templates->isEmpty())
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-qa="no-templates">
            Your agency has not added a breach notice template yet. Breach notices are your own documents - add yours (a Word document or typed text with placeholders) under
            <a href="{{ route('corex.rental-notice-templates.create', ['notice_type' => 'breach_other']) }}" style="color: var(--brand-icon, #0ea5e9);">Settings &rarr; Rental Notice Templates</a>, then come back here.
        </div>
    @else
        @feature('rental-leases')
        @php
            $chosen = $templates->firstWhere('id', (int) old('rental_notice_template_id')) ?? $templates->first();
        @endphp
        <form method="POST" action="{{ route('corex.leases.breaches.store', $lease) }}" class="space-y-3" data-qa="breach-form">
            @csrf
            <div>
                <label class="text-xs font-medium" for="breach-template">Template</label>
                <select name="rental_notice_template_id" id="breach-template" required class="prop-input mt-1" style="max-width: 28rem;" data-qa="breach-template">
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}" data-kind="{{ $t->notice_type === 'breach_payment' ? 'payment' : 'other' }}" data-window="{{ $windowByTemplate[$t->id] ?? '' }}" data-default-clause="{{ $t->default_clause }}" @selected($chosen && $chosen->id === $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div id="grp-payment" data-qa="breach-payment-fields">
                <label class="text-xs font-medium" for="breach-amount">Amount outstanding (R)</label>
                <input type="text" name="amount" id="breach-amount" value="{{ old('amount') }}" inputmode="decimal" class="prop-input mt-1" style="max-width: 12rem;" placeholder="e.g. 9 500.00" data-qa="breach-amount">
                <p class="text-xs mt-1" style="color: var(--text-muted);">A payment breach is always against the tenant.</p>
            </div>
            <div id="grp-other-party" data-qa="breach-party-field">
                <label class="text-xs font-medium">Who is in breach</label>
                <select name="breach_party" class="prop-input mt-1" style="max-width: 20rem;">
                    <option value="tenant" @selected(old('breach_party', 'tenant') === 'tenant')>The tenant ({{ $lease->tenantNames() }})</option>
                    <option value="landlord" @selected(old('breach_party') === 'landlord')>The landlord ({{ $lease->landlordNames() }})</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Lease clause breached (quote it)</label>
                {{-- AT-462 - starts from the chosen template's saved clause block; the agent edits it to quote the clause actually breached --}}
                <textarea name="clause" id="breach-clause" rows="6" maxlength="3000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. 9.2 The tenant shall keep the swimming pool clean and in working order" data-qa="breach-clause">{{ old('clause', $chosen?->default_clause) }}</textarea>
            </div>
            <div id="grp-other-reason" data-qa="breach-reason-field">
                <label class="text-xs font-medium">What the breach is</label>
                <textarea name="reason" rows="3" maxlength="3000" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. The pool has not been maintained and is green">{{ old('reason') }}</textarea>
            </div>
            <details class="text-xs" style="color: var(--text-muted);">
                <summary class="cursor-pointer">Extra figures your template needs (one per line as name=value) and the placeholders filled for you</summary>
                <textarea name="figures_raw" rows="3" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('figures_raw') }}</textarea>
                <ul class="list-disc list-inside mt-1">@foreach($placeholders as $k => $label)<li><code>&#123;&#123;{{ $k }}&#125;&#125;</code> - {{ $label }}</li>@endforeach</ul>
            </details>
            <div class="flex gap-2">
                <button type="submit" name="intent" value="preview" class="corex-btn-outline text-xs">Preview</button>
                <button type="submit" name="intent" value="send" class="corex-btn-primary text-xs" onclick="return confirm('Send this breach notice now?')">Send breach notice</button>
                <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">Cancel</a>
            </div>
        </form>
        <script>
            // the chosen template decides which fields are asked: payment = amount; ad hoc = who, clause and reason. Hidden fields are disabled so they are not sent.
            (function () {
                var sel = document.getElementById('breach-template'), box = document.getElementById('breach-clause');
                if (!sel || !box) { return; }
                var last = null;
                function apply(initial) {
                    var opt = sel.options[sel.selectedIndex], payment = opt.getAttribute('data-kind') === 'payment';
                    [['grp-payment', payment], ['grp-other-party', !payment], ['grp-other-reason', !payment]].forEach(function (g) {
                        var el = document.getElementById(g[0]);
                        el.style.display = g[1] ? '' : 'none';
                        el.querySelectorAll('input,select,textarea').forEach(function (f) { f.disabled = !g[1]; });
                    });
                    var next = opt.getAttribute('data-default-clause') || '';
                    // swap in this template's clause block unless the agent has typed their own
                    if (!initial && (box.value.trim() === '' || box.value.trim() === (last || '').trim())) { box.value = next; }
                    last = next;
                    var w = document.querySelector('[data-qa="breach-window"]');
                    if (w && opt.getAttribute('data-window')) { w.textContent = opt.getAttribute('data-window'); }
                }
                sel.addEventListener('change', function () { apply(false); });
                apply(true);
            })();
        </script>
        @endfeature

        @if($preview)
            <div class="rounded-md p-4 text-sm" style="border: 1px dashed var(--border); background: #fff; color: #111;" data-qa="breach-preview">
                <p class="text-xs mb-2" style="color: #666;">Preview - {{ $preview['template'] }}</p>
                @if(!empty($preview['missing']))
                    <p class="text-xs mb-2" style="color: #b91c1c;" data-qa="breach-preview-missing">Still to fill in before this can be sent: {{ implode(', ', $preview['missing']) }}</p>
                @endif
                {!! $preview['html'] !!}
            </div>
        @endif
    @endif
</div>
@endsection
