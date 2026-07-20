{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Person Completing Form --}}
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Person Completing Form</h3>
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Full Name</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $personal['full_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">ID / Passport</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $personal['id_number'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">SA Citizen/Resident</dt><dd style="color:var(--text-primary);">{{ ucfirst($personal['sa_citizen'] ?? '—') }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">Phone</dt><dd style="color:var(--text-primary);">{{ $personal['phone'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">Email</dt><dd style="color:var(--text-primary);">{{ $personal['email'] ?? '—' }}</dd></div>
        @if(!empty($personal['tax_number']))
        <div><dt class="text-xs" style="color:var(--text-muted);">Tax Number</dt><dd style="color:var(--text-primary);">{{ $personal['tax_number'] }}</dd></div>
        @endif
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Residential Address</dt><dd style="color:var(--text-primary);">{{ $personal['residential_address'] ?? '—' }}</dd></div>
    </dl>
</div>

{{-- Entity Details --}}
@if($submission->entity_type !== 'natural')
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">
        {{ ['company' => 'Company / CC', 'trust' => 'Trust', 'partnership' => 'Partnership'][$submission->entity_type] ?? 'Entity' }} Details
    </h3>

    @if($submission->entity_type === 'company')
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Company Name</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $entity['company_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">Registration No</dt><dd style="color:var(--text-primary);">{{ $entity['company_reg_number'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">SA Presence</dt><dd style="color:var(--text-primary);">{{ $entity['company_sa_presence'] ?? '—' }}</dd></div>
        @if(!empty($entity['company_stock_exchange']))<div><dt class="text-xs" style="color:var(--text-muted);">Stock Exchange</dt><dd style="color:var(--text-primary);">{{ $entity['company_stock_exchange'] }}</dd></div>@endif
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Registered Address</dt><dd style="color:var(--text-primary);">{{ $entity['company_address'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Authority to Act</dt><dd style="color:var(--text-primary);">{{ $entity['company_authority_source'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Business Description</dt><dd style="color:var(--text-primary);">{{ $entity['company_business_description'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Ownership Structure</dt><dd style="color:var(--text-primary);">{{ $entity['company_ownership_structure'] ?? '—' }}</dd></div>
    </dl>
    @if(!empty($entity['beneficial_owners']))
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border);">
            <p class="text-xs font-semibold mb-2" style="color:var(--text-secondary);">Beneficial Owners:</p>
            @foreach($entity['beneficial_owners'] as $bo)
            <div class="text-xs mb-1" style="color:var(--text-secondary);">{{ $bo['name'] ?? '' }} — ID: {{ $bo['id_number'] ?? '' }} — {{ $bo['address'] ?? '' }}</div>
            @endforeach
        </div>
    @endif
    @endif

    @if($submission->entity_type === 'trust')
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Trust Name</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $entity['trust_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">Master's Ref</dt><dd style="color:var(--text-primary);">{{ $entity['trust_master_ref'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Authority to Act</dt><dd style="color:var(--text-primary);">{{ $entity['trust_authority_source'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Trust Purpose</dt><dd style="color:var(--text-primary);">{{ $entity['trust_purpose'] ?? '—' }}</dd></div>
    </dl>
    <div class="mt-3 pt-3 text-xs" style="border-top:1px solid var(--border);">
        <p class="font-semibold mb-1" style="color:var(--text-secondary);">Donor: {{ $entity['donor_name'] ?? '' }} — ID: {{ $entity['donor_id_number'] ?? '' }}</p>
    </div>
    @if(!empty($entity['trustees']))
        <div class="mt-2 pt-2" style="border-top:1px solid var(--border);"><p class="text-xs font-semibold mb-1" style="color:var(--text-secondary);">Trustees:</p>
        @foreach($entity['trustees'] as $tr)<div class="text-xs mb-1" style="color:var(--text-secondary);">{{ $tr['name'] ?? '' }} — ID: {{ $tr['id_number'] ?? '' }}</div>@endforeach</div>
    @endif
    @if(!empty($entity['beneficiaries']))
        <div class="mt-2 pt-2" style="border-top:1px solid var(--border);"><p class="text-xs font-semibold mb-1" style="color:var(--text-secondary);">Beneficiaries:</p>
        @foreach($entity['beneficiaries'] as $bn)<div class="text-xs mb-1" style="color:var(--text-secondary);">{{ $bn['name'] ?? '' }} — ID: {{ $bn['id_number'] ?? '' }}</div>@endforeach</div>
    @endif
    @endif

    @if($submission->entity_type === 'partnership')
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Partnership Name</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $entity['partnership_name'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Business Description</dt><dd style="color:var(--text-primary);">{{ $entity['partnership_business_description'] ?? '—' }}</dd></div>
    </dl>
    @if(!empty($entity['partners']))
        <div class="mt-3 pt-3" style="border-top:1px solid var(--border);"><p class="text-xs font-semibold mb-1" style="color:var(--text-secondary);">Partners:</p>
        @foreach($entity['partners'] as $pt)<div class="text-xs mb-1" style="color:var(--text-secondary);">{{ $pt['name'] ?? '' }} — ID: {{ $pt['id_number'] ?? '' }}</div>@endforeach</div>
    @endif
    @endif
</div>
@endif

{{-- Principal & Representative --}}
@if($submission->entity_type === 'natural' && ($principalData['acting_on_behalf'] ?? '') === 'yes')
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Principal (Acting on Behalf)</h3>
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Full Name</dt><dd class="font-medium" style="color:var(--text-primary);">{{ $principalData['full_name'] ?? '—' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">ID / Passport</dt><dd style="color:var(--text-primary);">{{ $principalData['id_number'] ?? '—' }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Address</dt><dd style="color:var(--text-primary);">{{ $principalData['residential_address'] ?? '—' }}</dd></div>
    </dl>
</div>
@endif

{{-- Service & Payment --}}
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Service &amp; Payment</h3>
    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
        <div><dt class="text-xs" style="color:var(--text-muted);">Purpose</dt><dd style="color:var(--text-primary);">{{ $service['transaction_purpose'] ?? '—' }}{{ !empty($service['purpose_other']) ? ': ' . $service['purpose_other'] : '' }}</dd></div>
        <div><dt class="text-xs" style="color:var(--text-muted);">Cash Over R50,000</dt><dd class="font-semibold" style="color:{{ ($service['cash_over_50k'] ?? '') === 'yes' ? 'var(--ds-crimson,#c41e3a)' : 'var(--text-primary)' }};">{{ ucfirst($service['cash_over_50k'] ?? '—') }}</dd></div>
        <div class="col-span-2"><dt class="text-xs" style="color:var(--text-muted);">Payment Method</dt><dd style="color:var(--text-primary);">{{ $service['payment_method'] ?? '—' }}</dd></div>
    </dl>
</div>

{{-- PEP --}}
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Politically Exposed Person</h3>
    @php
        $foreignPep = $pepData['foreign_pep'] ?? [];
        $domesticPep = $pepData['domestic_pep'] ?? [];
        $hasPep = !empty($foreignPep) || !empty($domesticPep) || ($pepData['is_family_associate'] ?? '') === 'yes';
    @endphp
    @if(!empty($foreignPep))
    <div class="mb-2"><p class="text-xs font-semibold" style="color:var(--ds-crimson,#c41e3a);">Foreign PEP:</p>
    <div class="flex flex-wrap gap-1 mt-1">@foreach($foreignPep as $pos)<span class="ds-badge ds-badge-danger">{{ str_replace('_', ' ', ucfirst($pos)) }}</span>@endforeach</div></div>
    @endif
    @if(!empty($domesticPep))
    <div class="mb-2"><p class="text-xs font-semibold" style="color:var(--ds-crimson,#c41e3a);">Domestic PEP:</p>
    <div class="flex flex-wrap gap-1 mt-1">@foreach($domesticPep as $pos)<span class="ds-badge ds-badge-danger">{{ str_replace('_', ' ', ucfirst($pos)) }}</span>@endforeach</div></div>
    @endif
    @if(!empty($pepData['source_of_wealth']))
    <div class="mt-2 text-sm"><dt class="text-xs" style="color:var(--text-muted);">Source of Wealth</dt><dd style="color:var(--text-primary);">{{ $pepData['source_of_wealth'] }}</dd></div>
    @endif
    @if(!$hasPep)<p class="text-sm font-medium" style="color:var(--ds-green,#059669);">No PEP indicators</p>@endif
</div>

{{-- Documents --}}
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Uploaded Documents</h3>
    @forelse($submission->documents as $doc)
        {{-- AT-311 — wrap-safe row. Left column is `min-w-0 flex-1` so a long
             filename WRAPS (break-words) instead of overflowing its flex item and
             shoving the View control off the clickable area (the higher-res bug
             where CO/RO couldn't click View on the FICA form / ID copy). The View
             is a `shrink-0`, top-aligned, padded button — a stable, large hit
             target that never displaces or floats mid-text, at ANY resolution. --}}
        <div class="flex items-start justify-between gap-3 py-2" style="{{ !$loop->last ? 'border-bottom:1px solid var(--border);' : '' }}">
            <div class="min-w-0 flex-1">
                <span class="text-xs font-semibold uppercase" style="color:var(--text-secondary);">{{ $doc->document_type_label }}</span>
                <p class="text-sm break-words" style="color:var(--text-primary);">{{ $doc->file_name }}</p>
                <p class="text-xs" style="color:var(--text-muted);">{{ number_format($doc->file_size / 1024) }} KB</p>
            </div>
            <a href="{{ Storage::url($doc->file_path) }}" target="_blank" rel="noopener"
               class="shrink-0 inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-semibold text-white transition-colors"
               style="background:var(--brand-button,#0ea5e9);"
               aria-label="View {{ $doc->document_type_label }} document">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                View
            </a>
        </div>
    @empty
        <p class="text-sm" style="color:var(--text-muted);">No documents uploaded.</p>
    @endforelse
</div>

{{-- Signature --}}
@if($submission->signature_data)
<div class="rounded-md p-5" style="background:var(--surface); border:1px solid var(--border);">
    <h3 class="text-sm font-bold mb-3 pb-2" style="color:var(--text-primary); border-bottom:1px solid var(--border);">Electronic Signature</h3>
    <img src="{{ $submission->signature_data }}" alt="Recipient Signature" class="rounded-md" style="max-height:120px; border:1px solid var(--border); padding:0.5rem; background:#fff;">
    <p class="text-xs mt-1" style="color:var(--text-muted);">Signed at: {{ $declData['signed_at_location'] ?? '' }} — {{ $submission->signed_at?->format('d M Y H:i') }}</p>
</div>
@endif
