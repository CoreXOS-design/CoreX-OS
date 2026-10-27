@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
<h1 class="text-lg font-semibold">Send Notice — {{ $lease->property?->buildDisplayAddress() }}</h1>
@include('corex.notices._tabs', ['active' => 'sent'])
<div class="max-w-2xl space-y-4 rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($templates->isEmpty())
        <p class="text-sm" style="color: var(--text-muted);" data-qa="notice-no-templates">Your agency has not added any notice templates yet. Notices are your own documents - upload your breach notice, notice to vacate and any other notice (a Word document with placeholders) under <a href="{{ route('corex.rental-notice-templates.index') }}" style="color: var(--brand-icon, #0ea5e9);">Settings &rarr; Rental Notice Templates</a>, then come back here.</p>
    @else
        <form method="POST" action="{{ route('corex.leases.notices.store', $lease) }}" class="space-y-4" x-data="{ templateId: '{{ old('rental_notice_template_id', $templates->contains('id', $chosenTemplateId ?? 0) ? $chosenTemplateId : '') }}' }">
            @csrf
            <div>
                <label class="prop-label">Template</label>
                <select name="rental_notice_template_id" x-model="templateId" required class="prop-input">
                    <option value="">Choose…</option>
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}">{{ $t->name }} ({{ \App\Models\RentalNoticeTemplate::labelFor($t->notice_type) }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="prop-label">Breach description / arrears amount / vacate-by date (as applicable)</label>
                <p class="text-xs mb-1" style="color: var(--text-muted);">Fill in whichever figures the chosen template uses — enter the token name exactly as it appears in the template, one per line, as <code>token=value</code>.</p>
                <textarea name="figures_raw" rows="4" class="prop-input" style="font-family: monospace;" placeholder="arrears_amount=R 4,500&#10;vacate_by_date=2026-11-30&#10;breach_description=Rent unpaid for October and November"></textarea>
            </div>

            <div class="space-x-4">
                <label class="text-sm"><input type="checkbox" name="send_to_tenant" value="1" checked> Send to tenant</label>
                <label class="text-sm"><input type="checkbox" name="send_to_landlord" value="1"> Send to landlord</label>
            </div>

            <div class="flex gap-2">
                <button type="submit" name="intent" value="preview" class="corex-btn-outline text-sm">Preview</button>
                <button type="submit" name="intent" value="send" class="corex-btn-primary text-sm" onclick="return confirm('Send this notice now?')">Send notice</button>
            </div>
            <details class="text-xs" style="color: var(--text-muted);"><summary class="cursor-pointer">Placeholders filled from the lease for you</summary>
                <ul class="list-disc list-inside">@foreach($autoPlaceholders as $k => $label)<li><code>&#123;&#123;{{ $k }}&#125;&#125;</code> - {{ $label }}</li>@endforeach</ul>
            </details>
        </form>

        @if($preview)
            <div class="rounded-md p-4 text-sm" style="border: 1px dashed var(--border); background: #fff; color: #111;" data-qa="notice-preview">
                <p class="text-xs mb-2" style="color: #666;">Preview - {{ $preview['template'] }}</p>
                @if(!empty($preview['missing']))
                    <p class="text-xs mb-2" style="color: #b91c1c;" data-qa="notice-preview-missing">Still to fill in before this can be sent: {{ implode(', ', $preview['missing']) }}</p>
                @endif
                {!! $preview['html'] !!}
            </div>
        @endif
    @endif

    @feature('rental-leases')
    <a href="{{ route('corex.leases.show', $lease) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">&larr; Back to Lease Hub</a>
    @endfeature
</div>
</div>
@endsection
