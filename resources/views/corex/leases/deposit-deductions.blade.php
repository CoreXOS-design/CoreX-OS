@extends('layouts.corex')

{{--
    Audit 2026-10-19 B1/B3/B4 - the lease's Move-out charges / Deposit deductions summary (titled by the agency setting). What the move-out inspection recorded as charged to the tenant, with
    amounts only when the agency records them. CoreX only RECORDS these (Johan's ruling): the deposit itself is held, deducted and paid out in the agency's accounting system.
--}}
@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-4">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
    <div class="flex items-center justify-between gap-3 flex-wrap" data-tour="rops-dd-intro">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $summary['title'] }}</h1>
            <span class="text-xs" style="color: var(--text-muted);">{{ $lease->property?->buildDisplayAddress() ?? 'Unknown property' }} — {{ $lease->tenantNames() ?? 'Unknown tenant' }}</span>
        </div>
        <div class="flex items-center gap-2">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            <a href="{{ route('corex.leases.show', $lease) }}" class="corex-btn-outline text-xs">&larr; Lease</a>
        </div>
    </div>
    </div>
    <div class="max-w-4xl space-y-5">
    @if(session('success'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">{{ session('success') }}</div>
    @endif




    <p class="text-xs" style="color: var(--text-muted);" data-qa="dd-note" data-tour="rops-dd-note">
        @if($summary['record_amounts'])
            These are the amounts recorded against the tenant at the move-out inspection. CoreX only records them - the deposit itself is held, deducted
            and paid out in your accounting system, so carry these figures across there.
        @else
            These are the items charged to the tenant at the move-out inspection. CoreX does not track the money - the deposit is handled in your
            accounting system.
        @endif
    </p>

    @unless($summary['out_completed'])
        <div class="rounded-md p-3 text-xs" style="background: color-mix(in srgb, #d97706 10%, transparent); color: var(--ds-amber, #b45309);" data-qa="dd-out-open">
            The move-out inspection is not completed yet, so @if($summary['record_amounts'])these figures @else this list @endif can still change.
            <a href="{{ route('corex.rental-inspections.show', $summary['out']) }}" class="underline font-semibold">Open the move-out inspection</a>
        </div>
    @endunless

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="rops-dd-lines">
        @forelse($summary['lines'] as $line)
            <div class="flex items-start justify-between gap-3 px-4 py-3 text-sm" style="border-bottom: 1px solid var(--border);" data-qa="dd-line">
                <div class="min-w-0">
                    <div class="font-medium">{{ $line['item'] }}@if($line['room']) <span style="color: var(--text-muted);">— {{ $line['room'] }}</span>@endif</div>
                    <div class="text-xs" style="color: var(--text-muted);">{{ $line['note'] }} @if($line['by']) · {{ $line['by'] }} @endif @if($line['at']) · {{ $line['at']->format('j M Y') }} @endif</div>
                    @foreach($line['work_orders'] as $wo)
                        <div class="text-xs"><a href="{{ $wo['url'] }}" class="underline" style="color: var(--brand-icon, #2563eb);" data-qa="dd-work-order">Work order: {{ $wo['title'] }}</a></div>
                    @endforeach
                </div>
                @if($summary['record_amounts'])
                <div class="font-semibold whitespace-nowrap">
                    @if($line['amount'] !== null) R{{ number_format($line['amount'], 2) }} @else <span class="text-xs" style="color: var(--ds-amber, #b45309);">no amount recorded</span> @endif
                </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-6 text-sm text-center" style="color: var(--text-muted);" data-qa="dd-empty">Nothing was charged to the tenant at the move-out inspection.</div>
        @endforelse
        @if($summary['record_amounts'] && $summary['lines']->isNotEmpty())
            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm font-semibold" data-qa="dd-total" data-tour="rops-dd-total">
                <span>Total recorded</span>
                <span>R{{ number_format($summary['total'], 2) }}</span>
            </div>
        @endif
    </div>

    @if($summary['record_amounts'] && $summary['unpriced'] > 0)
        <p class="text-xs" style="color: var(--ds-amber, #b45309);" data-qa="dd-unpriced">{{ $summary['unpriced'] }} charge{{ $summary['unpriced'] === 1 ? ' has' : 's have' }} no amount recorded (classified before amounts were captured) - open the comparison to add one.</p>
    @endif
    @if($summary['record_amounts'] && $summary['deposit'] !== null)
        <p class="text-xs" style="color: var(--text-muted);" data-qa="dd-deposit">Deposit held on this lease: R{{ number_format($summary['deposit'], 2) }}.</p>
    @endif

    <div class="flex items-center gap-2 flex-wrap" data-tour="rops-dd-links">
        @if($summary['comparison_url'])
            <a href="{{ $summary['comparison_url'] }}" class="corex-btn-outline text-xs" data-qa="dd-comparison-link">Move-out comparison</a>
        @endif
        @if($summary['inventory_comparison_url'])
            <a href="{{ $summary['inventory_comparison_url'] }}" class="corex-btn-outline text-xs" data-qa="dd-inventory-link">Inventory comparison</a>
        @endif
        @if($summary['out_completed'])
            @if($summary['reviewed_at'])
                <span class="text-xs" style="color: var(--text-muted);" data-qa="dd-reviewed">Reviewed {{ $summary['reviewed_at']->format('j M Y') }}@if($summary['reviewed_by']) by {{ $summary['reviewed_by'] }}@endif.</span>
            @else
                @permission('rental_inspections.review_deposit_comparison')
                    <form method="POST" action="{{ route('corex.leases.deposit-deductions.reviewed', $lease) }}">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs" data-qa="dd-mark-reviewed" data-tour="rops-dd-reviewed">Mark as reviewed</button>
                    </form>
                @endpermission
            @endif
        @endif
    </div>
</div>
</div>
@endsection
