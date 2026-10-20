@extends('layouts.corex')

@section('content')
@php
    $address = $lease->property?->buildDisplayAddress() ?: ('Lease #' . $lease->id);
    $badge = $breach->status === 'issued' ? 'ds-badge-warning' : ($breach->status === 'remedied' ? 'ds-badge-success' : 'ds-badge-danger');
@endphp
<div class="p-6 space-y-5 max-w-5xl" data-qa="breach-screen">
    <div class="flex flex-wrap items-center justify-between gap-3" data-tour="rops-bd-head">
        <div>
            <h1 class="text-lg font-semibold">{{ $breach->kind === 'payment' ? 'Payment breach' : 'Breach' }} - {{ $address }}</h1>
            <p class="text-xs" style="color: var(--text-muted);">
                <span class="ds-badge {{ $badge }}" data-breach-status>{{ $breach->statusLabel() }}</span>
                @if($breach->trashed()) <span class="ds-badge ds-badge-muted">Archived</span> @endif
                Lease #{{ $lease->id }} - {{ $breach->partyLabel() }} is in breach
            </p>
        </div>
        <div class="flex gap-2">
@feature('rental-leases')
            <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">Open lease</a>
@endfeature
            <a href="{{ route('corex.breaches.index') }}" class="corex-btn-outline text-xs">Breach register</a>
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border: 1px solid var(--ds-crimson);" data-qa="breach-errors">
            <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rops-bd-details">
        <dl class="grid grid-cols-3 gap-y-1 text-xs">
            <dt style="color: var(--text-muted);">Issued</dt><dd class="col-span-2">{{ $breach->issued_on->format('j F Y') }} by {{ $breach->issuedBy?->name ?? 'an agent' }}</dd>
            @if($breach->amount !== null)<dt style="color: var(--text-muted);">Outstanding amount</dt><dd class="col-span-2">{{ \App\Services\Rentals\RentalNoticeService::money((float) $breach->amount) }}</dd>@endif
            @if($breach->clause)<dt style="color: var(--text-muted);">Lease clause</dt><dd class="col-span-2">{{ $breach->clause }}</dd>@endif
            @if($breach->reason)<dt style="color: var(--text-muted);">The breach</dt><dd class="col-span-2">{{ $breach->reason }}</dd>@endif
            <dt style="color: var(--text-muted);">Remedy by</dt>
            <dd class="col-span-2" data-qa="remedy-deadline" data-tour="rops-bd-deadline">{{ $breach->remedy_deadline?->format('j F Y') ?? '—' }} ({{ $breach->remedyWindowLabel() }})
                @if($breach->daysLeftText()) - <strong data-qa="days-left" style="color: {{ $breach->isOverdue() ? '#b91c1c' : ($breach->daysToRemedy() === 0 ? '#b45309' : 'inherit') }};">{{ $breach->daysLeftText() }}</strong>@endif
                @if($breach->isIssued() && $breach->remedyWindowPassed()) - <strong style="color:#b91c1c;">date reached - was it fixed?</strong>@endif</dd>
            <dt style="color: var(--text-muted);">Documents</dt>
            <dd class="col-span-2" data-tour="rops-bd-docs">
                @if($breach->notice?->document)<a href="{{ route('corex.breaches.document', [$breach->id, 'breach']) }}" style="color: var(--brand-icon, #0ea5e9);">Breach notice (PDF)</a>@endif
                @if($breach->vacateNotice?->document) &middot; <a href="{{ route('corex.breaches.document', [$breach->id, 'vacate']) }}" style="color: var(--brand-icon, #0ea5e9);">Notice to vacate (PDF)</a>@endif
            </dd>
            @if($breach->status === 'remedied')<dt style="color: var(--text-muted);">Remedied</dt><dd class="col-span-2">{{ $breach->remedied_at?->format('j M Y') }} by {{ $breach->remediedBy?->name ?? 'an agent' }}{{ $breach->remedied_note ? ' - ' . $breach->remedied_note : '' }}</dd>@endif
            @if($breach->vacate_issued_at)<dt style="color: var(--text-muted);">Notice to vacate</dt><dd class="col-span-2" data-qa="vacate-issued">Issued {{ $breach->vacate_issued_at->format('j F Y') }}@if($breach->vacate_date) - tenant to be out by {{ \App\Models\LeaseBreach::vacateTimeText($breach->vacate_time) }} on {{ $breach->vacate_date->format('j F Y') }}@endif</dd>@endif
        </dl>
        <p class="text-xs" style="color: var(--text-muted);">Both the tenant and the landlord can read the notice in their portal under Documents; the {{ $breach->otherParty() }} was told by email.</p>
    </div>

    @if(!$breach->trashed())
        @permission('rental_breaches.manage')
        @if($breach->isIssued())
            <div class="grid gap-4 md:grid-cols-2" data-qa="breach-actions" data-tour="rops-bd-actions">
                <form method="POST" action="{{ route('corex.breaches.remedy', $breach->id) }}" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="remedy-form" data-tour="rops-bd-remedy">
                    @csrf
                    <h2 class="text-sm font-semibold">Breach remedied</h2>
                    <p class="text-xs" style="color: var(--text-muted);">The breach has been put right. It can then no longer be escalated to a notice to vacate.</p>
                    <div>
                        <label class="text-xs font-medium" for="remedied_on">Put right on</label>
                        <input type="date" id="remedied_on" name="remedied_on" value="{{ old('remedied_on', now()->toDateString()) }}" min="{{ $breach->issued_on->toDateString() }}" max="{{ now()->toDateString() }}" class="prop-input mt-1" data-qa="remedied-on">
                    </div>
                    <textarea name="note" rows="2" maxlength="1000" class="w-full rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);" placeholder="Note (optional) - e.g. paid in full on 20 Oct"></textarea>
                    <button type="submit" class="corex-btn-primary text-xs" data-tour="rops-bd-remedy-btn">Breach remedied</button>
                </form>

                <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="vacate-box" data-qa="vacate-box" data-tour="rops-bd-vacate">
                    <h2 class="text-sm font-semibold">Notice to vacate</h2>
                    @if($breach->breach_party !== 'tenant')
                        <p class="text-xs" style="color: var(--text-muted);">A notice to vacate is only for a tenant who is in breach.</p>
                    @elseif(!$canVacate)
                        <p class="text-xs" style="color: var(--text-muted);" data-qa="vacate-not-yet">Not yet available. The tenant has until <strong>{{ $breach->remedy_deadline?->format('j F Y') }}</strong> to put this right; a notice to vacate can be issued from that date if the breach is still not remedied.</p>
                    @elseif($vacateTemplates->isEmpty())
                        <p class="text-xs" data-qa="no-templates">Your agency has not added a notice to vacate template yet - upload yours under <a href="{{ route('corex.rental-notice-templates.create', ['notice_type' => 'notice_to_vacate']) }}" style="color: var(--brand-icon, #0ea5e9);">Settings &rarr; Rental Notice Templates</a>.</p>
                    @else
                        <form method="POST" action="{{ route('corex.breaches.vacate', $breach->id) }}" class="space-y-2" data-qa="vacate-form">
                            @csrf
                            <p class="text-xs" style="color: var(--text-muted);">The remedy-by date has been reached and the breach is not remedied. The notice is made from your template, sent from your mailbox to the tenant, and the landlord is told. The date starts {{ $vacateNoticeDays }} days from today (your "Days for notice to vacate"); change it if this tenant is different.</p>
                            <select name="rental_notice_template_id" required class="prop-input" style="max-width: 24rem;">
                                @foreach($vacateTemplates as $t)<option value="{{ $t->id }}" @selected((int) old('rental_notice_template_id') === $t->id)>{{ $t->name }}</option>@endforeach
                            </select>
                            <div class="flex flex-wrap gap-3">
                                <div>
                                    <label class="text-xs font-medium" for="vacate_date">Tenant must be out by (date)</label>
                                    <input type="date" id="vacate_date" name="vacate_date" value="{{ old('vacate_date', $vacateDateDefault) }}" min="{{ now()->toDateString() }}" required class="prop-input mt-1" data-qa="vacate-date">
                                </div>
                                <div>
                                    <label class="text-xs font-medium" for="vacate_time">Time</label>
                                    <input type="time" id="vacate_time" name="vacate_time" value="{{ old('vacate_time', $vacateTimeDefault) }}" required class="prop-input mt-1" data-qa="vacate-time">
                                </div>
                            </div>
                            <div>
                                <label class="text-xs font-medium" for="breach_summary">Breaches the cancellation is for</label>
                                <textarea id="breach_summary" name="breach_summary" rows="2" maxlength="3000" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);" data-qa="vacate-summary">{{ old('breach_summary', $breachSummaryDefault) }}</textarea>
                                <p class="text-xs" style="color: var(--text-muted);">Filled from the breach notices on this lease (date and reason). Change it if the letter should say something different.</p>
                            </div>
                            <details class="text-xs" style="color: var(--text-muted);"><summary class="cursor-pointer">Extra figures (name=value per line) and placeholders</summary>
                                <textarea name="figures_raw" rows="2" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">{{ old('figures_raw') }}</textarea>
                                <ul class="list-disc list-inside mt-1">@foreach($placeholders as $k => $label)<li><code>&#123;&#123;{{ $k }}&#125;&#125;</code> - {{ $label }}</li>@endforeach</ul>
                            </details>
                            <div class="flex gap-2">
                                <button type="submit" name="intent" value="preview" class="corex-btn-outline text-xs">Preview</button>
                                <button type="submit" name="intent" value="send" class="corex-btn-primary text-xs" onclick="return confirm('Send the notice to vacate now?')">Send notice to vacate</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif
        @endpermission
    @endif

    @if($preview)
        <div class="rounded-md p-4 text-sm" style="border: 1px dashed var(--border); background: #fff; color: #111;" data-qa="breach-preview">
            <p class="text-xs mb-2" style="color: #666;">Preview - {{ $preview['template'] }}</p>
            @if(!empty($preview['missing']))
                <p class="text-xs mb-2" style="color: #b91c1c;" data-qa="breach-preview-missing">Still to fill in before this can be sent: {{ implode(', ', $preview['missing']) }}</p>
            @endif
            {!! $preview['html'] !!}
        </div>
    @endif

    @permission('rental_breaches.manage')
    <div class="flex gap-2" data-tour="rops-bd-archive">
        @if($breach->trashed())
            <form method="POST" action="{{ route('corex.breaches.restore', $breach->id) }}">@csrf<button type="submit" class="corex-btn-outline text-xs">Restore</button></form>
        @else
            <form method="POST" action="{{ route('corex.breaches.archive', $breach->id) }}" onsubmit="return confirm('Archive this breach record? It can be restored.')">@csrf<button type="submit" class="corex-btn-outline text-xs">Archive</button></form>
        @endif
    </div>
    @endpermission
</div>
@endsection
