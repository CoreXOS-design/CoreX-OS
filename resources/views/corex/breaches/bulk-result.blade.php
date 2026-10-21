@extends('layouts.corex')

@section('content')
<div class="mb-4">@include('corex.notices._tabs', ['active' => 'breaches'])</div>
<div class="p-6 space-y-4 max-w-4xl" data-qa="bulk-result">
    <h1 class="text-lg font-semibold">Breach notices sent</h1>
    <p class="text-sm"><strong data-issued-count>{{ count($result['issued']) }}</strong> breach {{ count($result['issued']) === 1 ? 'notice' : 'notices' }} sent (reference {{ $result['batch'] }}).
        @if($ignored > 0) {{ $ignored }} {{ $ignored === 1 ? 'lease' : 'leases' }} with no amount {{ $ignored === 1 ? 'was' : 'were' }} left alone. @endif
        Each was emailed from your own mailbox, the landlord was told, and both can read it in their portal.</p>

    @if(count($result['issued']))
        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-sm">
                <thead><tr style="background: var(--surface-2); color: var(--text-muted);"><th class="text-left px-4 py-2 font-medium">Tenant</th><th class="text-left px-4 py-2 font-medium">Property</th><th class="text-right px-4 py-2 font-medium">Amount</th><th class="text-left px-4 py-2 font-medium">Remedy by</th><th></th></tr></thead>
                <tbody>
                    @foreach($result['issued'] as $b)
                        <tr style="border-top: 1px solid var(--border);" data-issued-row="{{ $b->id }}">
                            <td class="px-4 py-2">{{ $b->lease->tenantNames() }}</td>
                            <td class="px-4 py-2">{{ $b->lease->property?->buildDisplayAddress() }}</td>
                            <td class="px-4 py-2 text-right">{{ \App\Services\Rentals\RentalNoticeService::money((float) $b->amount) }}</td>
                            <td class="px-4 py-2">{{ $b->remedy_deadline->format('j M Y') }}</td>
                            <td class="px-4 py-2 text-right"><a href="{{ route('corex.breaches.show', $b->id) }}" class="corex-btn-outline text-xs">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(count($result['skipped']))
        <div class="rounded-md p-4 text-sm" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); border: 1px solid var(--border);" data-qa="bulk-skipped">
            <strong>Not sent:</strong>
            <ul class="list-disc list-inside">@foreach($result['skipped'] as $s)<li>{{ $s['lease']->property?->buildDisplayAddress() }} - {{ $s['reason'] }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="flex gap-2">
        <a href="{{ route('corex.breaches.index') }}" class="corex-btn-primary text-xs">Open the breach register</a>
@feature('rental-leases')
        <a href="{{ route('corex.leases.index') }}" class="corex-btn-outline text-xs">Back to leases</a>
@endfeature
    </div>
</div>
@endsection
