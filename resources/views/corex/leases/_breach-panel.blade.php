{{--
    .ai/specs/rental-notices.md §6 - the lease screen's Breach panel: every breach notice on this lease, its status and the remedy deadline.
--}}
@php
    $leaseBreaches = $lease->breaches()->limit(5)->get();
    $canSeeBreaches = auth()->user()?->hasPermission('rental_breaches.view');
@endphp
@if($leaseBreaches->isNotEmpty())
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="lease-breach-panel">
    <h3 class="text-sm font-semibold">Breach notices</h3>
    @foreach($leaseBreaches as $b)
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs rounded-md px-3 py-2" style="background: {{ $b->isOverdue() ? 'color-mix(in srgb, #dc2626 10%, transparent)' : ($b->status === 'issued' ? 'color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent)' : 'var(--surface-2)') }}; border: 1px solid {{ $b->isOverdue() ? '#dc2626' : 'var(--border)' }};" data-breach-row="{{ $b->id }}" @if($b->isOverdue()) data-overdue="1" @endif>
            <div>
                <strong>{{ $b->statusLabel() }}</strong> - {{ strtolower($b->partyLabel()) }} in breach ({{ strtolower($b->kindLabel()) }}{{ $b->amount !== null ? ', ' . \App\Services\Rentals\RentalNoticeService::money((float) $b->amount) : '' }}),
                issued {{ $b->issued_on->format('j M Y') }}
                @if($b->isIssued()) - remedy by <strong>{{ $b->remedy_deadline?->format('j M Y') }}</strong>
                    @if($b->daysLeftText()) <strong data-qa="days-left" style="color: {{ $b->isOverdue() ? '#b91c1c' : ($b->daysToRemedy() === 0 ? '#b45309' : 'inherit') }};">({{ $b->daysLeftText() }})</strong>@endif
                @endif
            </div>
            @feature('rental-notices')@if($canSeeBreaches)
                <div class="flex flex-wrap gap-2">
                    @if($b->notice?->document_id)<a href="{{ route('corex.breaches.document', [$b->id, 'breach']) }}" class="corex-btn-outline text-xs" data-qa="breach-pdf-link">Breach notice (PDF)</a>@endif
                    @if($b->vacateNotice?->document_id)<a href="{{ route('corex.breaches.document', [$b->id, 'vacate']) }}" class="corex-btn-outline text-xs" data-qa="vacate-pdf-link">Notice to vacate (PDF)</a>@endif
                    <a href="{{ route('corex.breaches.show', $b->id) }}" class="corex-btn-outline text-xs">Open</a>
                </div>
            @endif @endfeature
        </div>
    @endforeach
</div>
@endif
