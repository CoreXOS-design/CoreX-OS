@extends('layouts.corex')

@section('content')
<div class="p-6 space-y-4 max-w-3xl">
    <h1 class="text-lg font-semibold">Breach notice - {{ $lease->property?->buildDisplayAddress() ?: ('Lease #' . $lease->id) }}</h1>
    <p class="text-sm" style="color: var(--text-muted);">A breach of the lease other than non-payment. Quote the clause and say what the breach is; the notice is made from your own template, sent from your mailbox, and the other party is told. {{ $remedyDays }} business days are allowed to put it right.</p>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);" data-qa="breach-errors">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if($templates->isEmpty())
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-qa="no-templates">
            Your agency has not added a breach notice (other) template yet. Breach notices are your own documents - upload yours (a Word document with placeholders) under
            <a href="{{ route('corex.rental-notice-templates.create', ['notice_type' => 'breach_other']) }}" style="color: var(--brand-icon, #0ea5e9);">Settings &rarr; Rental Notice Templates</a>, then come back here.
        </div>
    @else
        @feature('rental-leases')
        <form method="POST" action="{{ route('corex.leases.breaches.store', $lease) }}" class="space-y-3" data-qa="breach-form">
            @csrf
            <div>
                <label class="text-xs font-medium">Who is in breach</label>
                <select name="breach_party" required class="prop-input mt-1" style="max-width: 20rem;">
                    <option value="tenant" @selected(old('breach_party', 'tenant') === 'tenant')>The tenant ({{ $lease->tenantNames() }})</option>
                    <option value="landlord" @selected(old('breach_party') === 'landlord')>The landlord ({{ $lease->landlordNames() }})</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-medium">Lease clause breached (quote it)</label>
                <textarea name="clause" rows="3" maxlength="3000" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. 9.2 The tenant shall keep the swimming pool clean and in working order">{{ old('clause') }}</textarea>
            </div>
            <div>
                <label class="text-xs font-medium">What the breach is</label>
                <textarea name="reason" rows="3" maxlength="3000" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);" placeholder="e.g. The pool has not been maintained and is green">{{ old('reason') }}</textarea>
            </div>
            <div>
                <label class="text-xs font-medium">Template</label>
                <select name="rental_notice_template_id" required class="prop-input mt-1" style="max-width: 28rem;">
                    @foreach($templates as $t)<option value="{{ $t->id }}" @selected((int) old('rental_notice_template_id') === $t->id)>{{ $t->name }}</option>@endforeach
                </select>
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
