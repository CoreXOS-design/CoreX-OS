@extends('layouts.corex')

@section('corex-content')
@php
    $isNew = ! $auction->exists;
    $locked = ! $isNew && (! $auction->isEditable() || $auction->trashed());
    $dt = fn ($d) => $d ? $d->format('Y-m-d\TH:i') : '';
    $canEdit = auth()->user()->hasPermission($isNew ? 'auctions.create' : 'auctions.edit');
    $field = 'w-full px-3 py-2 text-sm rounded-md';
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $label = 'block text-xs font-semibold uppercase tracking-wider mb-1';
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-5xl">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-base font-bold" style="color:var(--text-primary);">{{ $isNew ? 'New auction' : $auction->reference . ' — ' . $auction->title }}</h1>
                @unless($isNew)<p class="text-xs" style="color:var(--text-muted);">Status: <strong>{{ $auction->trashed() ? 'Archived' : $auction->statusLabel() }}</strong>@if($auction->published_at) · published {{ $auction->published_at->format('d M Y H:i') }}@endif</p>@endunless
            </div>
            <a href="{{ route('corex.auctions.index') }}" class="corex-btn-outline text-xs px-3 py-2">← All auctions</a>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ $isNew ? route('corex.auctions.store') : route('corex.auctions.update', $auction->id) }}" class="flex flex-col gap-4">
        @csrf
        @unless($isNew) @method('PUT') @endunless
        <fieldset @disabled($locked || ! $canEdit) class="flex flex-col gap-4">

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Title *</label>
                    <input name="title" value="{{ old('title', $auction->title) }}" required maxlength="200" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Format</label>
                    <select name="format" class="{{ $field }}" style="{{ $fieldStyle }}">
                        @foreach(\App\Models\Auction::FORMATS as $k => $l)<option value="{{ $k }}" @selected(old('format', $auction->format) === $k)>{{ $l }}</option>@endforeach
                    </select></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Type</label>
                    <select name="type" class="{{ $field }}" style="{{ $fieldStyle }}">
                        @foreach(\App\Models\Auction::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type', $auction->type) === $k)>{{ $l }}</option>@endforeach
                    </select></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Auction starts</label>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $dt($auction->starts_at)) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Auction ends (timed auctions)</label>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $dt($auction->ends_at)) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Bidder registration closes</label>
                    <input type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $dt($auction->registration_closes_at)) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Currency</label>
                    <input name="currency" value="{{ old('currency', $auction->currency ?: 'ZAR') }}" maxlength="3" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                @if($branches->count() > 1)
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Branch</label>
                    <select name="branch_id" class="{{ $field }}" style="{{ $fieldStyle }}">
                        <option value="">—</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $auction->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach
                    </select></div>
                @endif
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Description</label>
                    <textarea name="description" rows="4" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('description', $auction->description) }}</textarea></div>
            </div>
        </section>

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Location & live streaming</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Venue name</label>
                    <input name="venue_name" value="{{ old('venue_name', $auction->venue_name) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Venue address</label>
                    <input name="venue_address" value="{{ old('venue_address', $auction->venue_address) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Latitude</label>
                    <input name="lat" value="{{ old('lat', $auction->lat) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Longitude</label>
                    <input name="lng" value="{{ old('lng', $auction->lng) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Live stream link</label>
                    <input name="stream_url" value="{{ old('stream_url', $auction->stream_url) }}" placeholder="https://" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
            </div>
        </section>

        <section class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);" x-data="{ rows: {{ json_encode(array_map(fn ($r) => ['from' => $r[0], 'inc' => $r[1]], old('inc_from') ? array_map(null, old('inc_from'), old('inc_amount', [])) : $rungs)) }} }">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Bid increments & options</h2>
            <p class="text-xs" style="color:var(--text-muted);">Each line says: from this price upward, bids go up by this much.</p>
            <template x-for="(r, i) in rows" :key="i">
                <div class="flex items-center gap-2">
                    <span class="text-xs" style="color:var(--text-muted);">From R</span>
                    <input type="number" min="0" step="1" name="inc_from[]" x-model="r.from" class="px-3 py-2 text-sm rounded-md w-40" style="{{ $fieldStyle }}">
                    <span class="text-xs" style="color:var(--text-muted);">goes up by R</span>
                    <input type="number" min="1" step="1" name="inc_amount[]" x-model="r.inc" class="px-3 py-2 text-sm rounded-md w-40" style="{{ $fieldStyle }}">
                    <button type="button" @click="rows.splice(i, 1)" class="text-xs underline" style="color:var(--text-muted);">Remove</button>
                </div>
            </template>
            <button type="button" @click="rows.push({from: '', inc: ''})" class="text-xs underline" style="color:var(--text-muted);">+ Add a line</button>
            <div class="max-w-xs"><label class="{{ $label }}" style="color:var(--text-secondary);">Soft close (minutes)</label>
                <input type="number" min="0" max="60" name="soft_close_minutes" value="{{ old('soft_close_minutes', $auction->soft_close_minutes) }}" placeholder="Agency default" class="{{ $field }}" style="{{ $fieldStyle }}">
                <p class="text-[11px] mt-1" style="color:var(--text-muted);">A bid placed in the last few minutes extends the closing time by the same amount. Blank uses your agency setting.</p></div>
        </section>

        </fieldset>
        @if(! $locked && $canEdit)
        <div><button type="submit" class="corex-btn-primary">{{ $isNew ? 'Create auction' : 'Save auction' }}</button></div>
        @endif
    </form>

    @unless($isNew)
    {{-- Documents --}}
    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-auction-documents>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Documents</h2>
        <p class="text-xs" style="color:var(--text-muted);">Conditions of Sale, the Bidder Registration Form, the auction Offer to Purchase and the Commission Breakdown. Buyers download them from the public auction page.</p>
        @forelse($auction->documents as $d)
        <div class="flex items-center gap-3 text-sm" style="border-top:1px solid var(--border);padding-top:.5rem;">
            <span class="flex-1">{{ $d->name }} <span class="text-[11px]" style="color:var(--text-muted);">· {{ \App\Models\AuctionDocument::KINDS[$d->kind] ?? $d->kind }} · {{ \App\Models\AuctionDocument::VISIBILITIES[$d->visibility] ?? $d->visibility }}</span></span>
            <a href="{{ route('corex.auctions.documents.download', [$auction->id, $d->id]) }}" class="text-xs underline">Download</a>
            @if(! $locked)@permission('auctions.edit')
            <form method="POST" action="{{ route('corex.auctions.documents.destroy', [$auction->id, $d->id]) }}" onsubmit="return confirm('Remove this document?');">@csrf @method('DELETE')<button class="text-xs underline" style="color:#ef4444;">Remove</button></form>
            @endpermission @endif
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">No documents yet.</p>
        @endforelse
        @if(! $locked)@permission('auctions.edit')
        <form method="POST" action="{{ route('corex.auctions.documents.store', $auction->id) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2 pt-2" style="border-top:1px solid var(--border);">
            @csrf
            <select name="kind" class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}">@foreach(\App\Models\AuctionDocument::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select name="visibility" class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><option value="">Default visibility</option>@foreach(\App\Models\AuctionDocument::VISIBILITIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <input type="file" name="file" required class="text-xs">
            <button class="corex-btn-outline text-xs px-3 py-2">Upload</button>
        </form>
        @endpermission @endif
    </section>

    {{-- Lots --}}
    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-auction-lots>
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-bold" style="color:var(--text-primary);">Lots ({{ $auction->lots->count() }})</h2>
            @permission('auctions.lots.manage')@if(! $locked)<a href="{{ route('corex.auctions.lots.create', ['auction_id' => $auction->id]) }}" class="corex-btn-outline text-xs px-3 py-2">+ Add a lot</a>@endif @endpermission
        </div>
        @forelse($auction->lots as $lot)
        <div class="flex items-center gap-3 text-sm" style="border-top:1px solid var(--border);padding-top:.5rem;">
            <span class="w-14 font-semibold">Lot {{ $lot->lot_number }}</span>
            <span class="flex-1">{{ $lot->displayTitle() }}</span>
            <span class="text-xs" style="color:var(--text-muted);">{{ $lot->opening_bid !== null ? 'Opens at R ' . number_format((float) $lot->opening_bid, 0, '.', ' ') : '' }}</span>
            <span class="text-xs">{{ $lot->statusLabel() }}</span>
            <a href="{{ route('corex.auctions.lots.edit', $lot->id) }}" class="text-xs underline">Open</a>
        </div>
        @empty
        <p class="text-xs" style="color:var(--text-muted);">No lots yet. Add the properties that will be sold in this auction.</p>
        @endforelse
    </section>

    {{-- Actions --}}
    <section class="rounded-md p-5 flex flex-wrap items-center gap-3" style="background:var(--surface);border:1px solid var(--border);" data-auction-actions>
        @if($auction->trashed())
            @permission('auctions.archive')
            <form method="POST" action="{{ route('corex.auctions.restore', $auction->id) }}">@csrf<button class="corex-btn-primary">Restore auction</button></form>
            @endpermission
        @else
            @permission('auctions.publish')
            @if($auction->status === 'draft')
            <form method="POST" action="{{ route('corex.auctions.publish', $auction->id) }}" onsubmit="return confirm('Publish this auction to your public auction page?');">@csrf<button class="corex-btn-primary">Publish auction</button></form>
            @endif
            @if(in_array($auction->status, ['published', 'open', 'closed'], true))
            <form method="POST" action="{{ route('corex.auctions.complete', $auction->id) }}" onsubmit="return confirm('Mark this auction as completed?');">@csrf<button class="corex-btn-outline">Complete auction</button></form>
            @endif
            @if($auction->isEditable())
            <form method="POST" action="{{ route('corex.auctions.cancel', $auction->id) }}" onsubmit="return confirm('Cancel this auction? All its lots are withdrawn.');" class="flex items-center gap-2">@csrf
                <input name="cancel_reason" placeholder="Reason (optional)" class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline" style="color:#ef4444;">Cancel auction</button></form>
            @endif
            @endpermission
            @permission('auctions.create')
            <form method="POST" action="{{ route('corex.auctions.clone', $auction->id) }}">@csrf<button class="corex-btn-outline">Clone</button></form>
            @endpermission
            @permission('auctions.archive')
            <form method="POST" action="{{ route('corex.auctions.archive', $auction->id) }}" onsubmit="return confirm('Archive this auction?');">@csrf @method('DELETE')<button class="corex-btn-outline">Archive</button></form>
            @endpermission
        @endif
    </section>
    @endunless
</div>
@endsection
