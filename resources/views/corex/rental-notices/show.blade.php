@extends('layouts.corex')

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">{{ ucfirst(str_replace('_', ' ', $notice->notice_type)) }} notice</h1>
    <div class="rounded-md p-4 space-y-2 text-sm" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex justify-between"><span style="color:var(--text-muted);">Property</span><strong>{{ $notice->lease?->property?->buildDisplayAddress() }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Sent</span><strong>{{ $notice->sent_at?->format('d M Y H:i') }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Sent by</span><strong>{{ $notice->sentByUser?->name }}</strong></div>
        <div class="flex justify-between"><span style="color:var(--text-muted);">Recipients</span><strong>
            @if($notice->sent_to_tenant) Tenant @endif
            @if($notice->sent_to_landlord) Landlord @endif
        </strong></div>
        @if($notice->document)
            <div class="pt-2">
                <a href="{{ route('corex.rental-notices.download-document', $notice) }}" class="corex-btn-outline text-xs">Download document</a>
            </div>
        @endif
    </div>
    <a href="{{ route('corex.leases.show', $notice->lease) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">&larr; Back to Lease Hub</a>
</div>
@endsection
