@extends('layouts.corex')

@section('corex-content')
@php
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $canReview = auth()->user()->hasPermission('auctions.bidders.review_documents');
    $canApprove = auth()->user()->hasPermission('auctions.bidders.approve');
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-5xl">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold" style="color:var(--text-primary);">{{ $reg->displayName() }}@if($reg->bidder_number) <span style="color:var(--text-muted);">· bidder #{{ $reg->bidder_number }}</span>@endif</h1>
            <p class="text-xs" style="color:var(--text-muted);">{{ $reg->auction?->reference }} — {{ $reg->auction?->title }} · Status: <strong>{{ $reg->trashed() ? 'Withdrawn' : $reg->statusLabel() }}</strong></p>
        </div>
        <a href="{{ route('corex.auctions.bidders.index') }}" class="corex-btn-outline text-xs px-3 py-2">← All bidders</a>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <section class="rounded-md p-5 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm" style="background:var(--surface);border:1px solid var(--border);">
        <div><span style="color:var(--text-muted);">Type</span><br>{{ \App\Models\AuctionRegistration::TYPES[$reg->bidder_type] ?? $reg->bidder_type }}@if($reg->company_reg_no) · reg. {{ $reg->company_reg_no }}@endif</div>
        <div><span style="color:var(--text-muted);">Email</span><br>{{ $reg->clientUser?->email }}</div>
        <div><span style="color:var(--text-muted);">Phone</span><br>{{ $reg->contact?->phone ?: '—' }}</div>
        <div><span style="color:var(--text-muted);">Contact</span><br>@if($reg->contact_id)<a href="{{ route('corex.contacts.show', $reg->contact_id) }}" class="underline">Open the contact</a>@else—@endif</div>
        <div><span style="color:var(--text-muted);">Registered</span><br>{{ $reg->created_at->format('d M Y H:i') }}</div>
        <div><span style="color:var(--text-muted);">Accepted the conditions</span><br>{{ $reg->accepted_conditions_at?->format('d M Y H:i') ?? '—' }}</div>
        @if($reg->status === 'declined' && $reg->declined_reason)<div class="md:col-span-2"><span style="color:var(--text-muted);">Declined because</span><br>{{ $reg->declined_reason }}</div>@endif
        @if($reg->approved_at)<div class="md:col-span-2"><span style="color:var(--text-muted);">Approved</span><br>{{ $reg->approved_at->format('d M Y H:i') }}@if($reg->approver) by {{ $reg->approver->name }}@endif</div>@endif
    </section>

    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-bidder-documents>
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Documents</h2>
            @if($canReview && ! $reg->trashed() && $reg->documents->where('status', 'submitted')->isNotEmpty())
            <form method="POST" action="{{ route('corex.auctions.bidders.documents.accept-all', $reg->id) }}">@csrf<button class="corex-btn-outline text-xs px-3 py-1.5">Accept all submitted</button></form>
            @endif
        </div>
        @forelse($reg->documents as $d)
        <div class="flex flex-wrap items-center gap-3 text-sm" style="border-top:1px solid var(--border);padding-top:.5rem;">
            <span class="flex-1">{{ $kinds[$d->kind] ?? $d->kind }} <span class="text-xs" style="color:var(--text-muted);">· {{ $d->document?->original_name }}</span></span>
            <a href="{{ route('corex.auctions.bidders.documents.download', [$reg->id, $d->id]) }}" class="text-xs underline">View</a>
            <span class="text-xs font-semibold" style="color:{{ $d->status === 'accepted' ? '#10b981' : ($d->status === 'rejected' ? '#ef4444' : 'var(--text-muted)') }};">{{ ucfirst($d->status) }}</span>
            @if($canReview && ! $reg->trashed())
            <form method="POST" action="{{ route('corex.auctions.bidders.documents.review', [$reg->id, $d->id]) }}" class="flex items-center gap-1">@csrf
                <input type="hidden" name="decision" value="accept"><button class="text-xs underline" style="color:#10b981;">Accept</button></form>
            <form method="POST" action="{{ route('corex.auctions.bidders.documents.review', [$reg->id, $d->id]) }}" class="flex items-center gap-1">@csrf
                <input type="hidden" name="decision" value="reject"><input name="reason" placeholder="Why?" required class="px-2 py-1 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="text-xs underline" style="color:#ef4444;">Reject</button></form>
            @endif
            @if($d->status === 'rejected' && $d->reject_reason)<span class="w-full text-xs" style="color:var(--text-muted);">Rejected: {{ $d->reject_reason }}</span>@endif
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">No documents were submitted.</p>
        @endforelse
        @if($missing)<p class="text-xs" style="color:#b45309;">Still needed before approval: {{ collect($missing)->map(fn ($k) => $kinds[$k] ?? $k)->implode(', ') }}.</p>@endif
    </section>

    @if($canApprove)
    <section class="rounded-md p-5 flex flex-wrap items-start gap-4" style="background:var(--surface);border:1px solid var(--border);" data-bidder-actions>
        @if($reg->trashed())
            <form method="POST" action="{{ route('corex.auctions.bidders.restore', $reg->id) }}">@csrf<button class="corex-btn-primary">Restore registration</button></form>
        @else
            @if($reg->status !== 'approved')
            <form method="POST" action="{{ route('corex.auctions.bidders.approve', $reg->id) }}">@csrf<button class="corex-btn-primary" @disabled($missing)>Approve and issue bidder number</button></form>
            @endif
            <form method="POST" action="{{ route('corex.auctions.bidders.decline', $reg->id) }}" class="flex items-center gap-2">@csrf
                <input name="reason" placeholder="Reason for declining" required class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline" style="color:#ef4444;">Decline</button></form>
            <form method="POST" action="{{ route('corex.auctions.bidders.withdraw', $reg->id) }}" onsubmit="return confirm('Withdraw and archive this registration?');">@csrf @method('DELETE')<button class="corex-btn-outline">Withdraw / archive</button></form>
        @endif
    </section>
    @endif

    <section class="rounded-md p-5 space-y-2" style="background:var(--surface);border:1px solid var(--border);">
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bids ({{ $bids->count() }})</h2>
        @forelse($bids as $b)
        <div class="flex items-center gap-3 text-sm" style="border-top:1px solid var(--border);padding-top:.4rem;">
            <span class="w-28 text-xs" style="color:var(--text-muted);">{{ $b->placed_at->format('d M H:i:s') }}</span>
            <span class="flex-1">Lot {{ $b->lot?->lot_number }} — {{ $b->lot?->displayTitle() }}</span>
            <strong>R {{ number_format((float) $b->amount, 0, '.', ' ') }}</strong>
            <span class="text-xs">{{ \App\Models\AuctionBid::SOURCES[$b->source] ?? $b->source }}{{ $b->status === 'retracted' ? ' · retracted' : '' }}</span>
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">No bids yet.</p>
        @endforelse
    </section>
</div>
@endsection
