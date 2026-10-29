@extends('layouts.corex')

@section('corex-content')
@php
    $isNew = ! $lot->exists;
    $locked = ! $isNew && ($lot->isConcluded() || $lot->trashed());
    $user = auth()->user();
    $canReserve = $user->hasPermission('auctions.reserve.view');
    $canEditReserve = $user->hasPermission('auctions.reserve.edit');
    $dt = fn ($d) => $d ? $d->format('Y-m-d\TH:i') : '';
    $field = 'w-full px-3 py-2 text-sm rounded-md';
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $label = 'block text-xs font-semibold uppercase tracking-wider mb-1';
@endphp
<div class="corex-auctions w-full flex flex-col gap-4 max-w-4xl">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold" style="color:var(--text-primary);">{{ $isNew ? 'New lot' : 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle() }}</h1>
            @unless($isNew)<p class="text-xs" style="color:var(--text-muted);">{{ $lot->auction->reference }} · Status: <strong>{{ $lot->trashed() ? 'Archived' : $lot->statusLabel() }}</strong></p>@endunless
        </div>
        <a href="{{ route('corex.auctions.lots.index') }}" class="corex-btn-outline text-xs px-3 py-2">← All lots</a>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ $isNew ? route('corex.auctions.lots.store') : route('corex.auctions.lots.update', $lot->id) }}"
          class="rounded-md p-5 space-y-4" style="background:var(--surface);border:1px solid var(--border);"
          x-data="lotPicker({{ $property ? json_encode(['id' => $property->id, 'label' => $property->title . ' — ' . $property->buildDisplayAddress()]) : 'null' }})">
        @csrf
        @unless($isNew) @method('PUT') @endunless
        <fieldset @disabled($locked) class="space-y-4">
            <div>
                <label class="{{ $label }}" style="color:var(--text-secondary);">Property *</label>
                @if($isNew)
                <input type="hidden" name="property_id" :value="picked ? picked.id : ''">
                <div x-show="picked" class="flex items-center gap-2 text-sm"><strong x-text="picked?.label"></strong><button type="button" @click="picked = null" class="text-xs underline">Change</button></div>
                <div x-show="!picked" class="relative">
                    <input type="text" x-model="q" @input.debounce.300ms="search()" placeholder="Search address, title, erf or property number…" class="{{ $field }}" style="{{ $fieldStyle }}" autocomplete="off">
                    <div x-show="results.length" x-cloak class="absolute z-10 mt-1 w-full rounded-md shadow-lg" style="background:var(--surface);border:1px solid var(--border);">
                        <template x-for="r in results" :key="r.id"><button type="button" @click="pick(r)" class="block w-full text-left px-3 py-2 text-sm hover:opacity-80" x-text="r.label"></button></template>
                    </div>
                    <p x-show="searched && !results.length" x-cloak class="text-[11px] mt-1" style="color:var(--text-muted);">No available properties match. Rentals and properties already in a live lot are not listed.</p>
                </div>
                @else
                <div class="text-sm"><strong>{{ $property?->title }}</strong> — {{ $property?->buildDisplayAddress() }}
                    <a href="{{ route('corex.properties.show', $lot->property_id) }}?tab=auction" class="text-xs underline ml-2">Open property</a></div>
                @endif
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Auction *</label>
                    <select name="auction_id" class="{{ $field }}" style="{{ $fieldStyle }}" @disabled(! $isNew && $lot->status !== 'draft')>
                        <option value="">Select an auction…</option>
                        @foreach($auctions as $a)<option value="{{ $a->id }}" @selected((int) old('auction_id', $lot->auction_id) === $a->id)>{{ $a->reference }} — {{ $a->title }}@if($a->starts_at) ({{ $a->starts_at->format('d M Y') }})@endif</option>@endforeach
                    </select>
                    @if(! $isNew && $lot->status !== 'draft')<input type="hidden" name="auction_id" value="{{ $lot->auction_id }}">@endif</div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Lot number</label>
                    <input type="number" min="1" name="lot_number" value="{{ old('lot_number', $lot->lot_number) }}" placeholder="Next free" class="{{ $field }}" style="{{ $fieldStyle }}" @readonly(! $isNew)></div>
                <div class="md:col-span-2"><label class="{{ $label }}" style="color:var(--text-secondary);">Lot title</label>
                    <input name="title" value="{{ old('title', $lot->title) }}" placeholder="Defaults to the property title" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Reference</label>
                    <input name="reference" value="{{ old('reference', $lot->reference) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">External reference</label>
                    <input name="external_ref" value="{{ old('external_ref', $lot->external_ref) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Bidding opens</label>
                    <input type="datetime-local" name="opens_at" value="{{ old('opens_at', $dt($lot->opens_at)) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Bidding closes</label>
                    <input type="datetime-local" name="closes_at" value="{{ old('closes_at', $dt($lot->closes_at)) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Opening bid (R)</label>
                    <input type="number" min="0" step="1" name="opening_bid" value="{{ old('opening_bid', $lot->opening_bid !== null ? (float) $lot->opening_bid : '') }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Reserve (R) — staff only</label>
                    @if($canReserve)<input type="number" min="0" step="1" name="reserve_price" value="{{ old('reserve_price', $lot->reserve_price !== null ? (float) $lot->reserve_price : '') }}" class="{{ $field }}" style="{{ $fieldStyle }}" @readonly(! $canEditReserve)>
                    @else<input type="text" value="Hidden" disabled class="{{ $field }}" style="{{ $fieldStyle }}">@endif</div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Guide price from (R)</label>
                    <input type="number" min="0" step="1" name="guide_price_min" value="{{ old('guide_price_min', $lot->guide_price_min !== null ? (float) $lot->guide_price_min : '') }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Guide price to (R)</label>
                    <input type="number" min="0" step="1" name="guide_price_max" value="{{ old('guide_price_max', $lot->guide_price_max !== null ? (float) $lot->guide_price_max : '') }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div class="md:col-span-3"><label class="{{ $label }}" style="color:var(--text-secondary);">Description</label>
                    <textarea name="description" rows="4" placeholder="Defaults to the property description" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('description', $lot->description) }}</textarea></div>
            </div>
        </fieldset>
        @if(! $locked)@permission('auctions.lots.manage')<button type="submit" class="corex-btn-primary">{{ $isNew ? 'Create lot' : 'Save lot' }}</button>@endpermission @endif
    </form>

    @if(! $isNew && in_array($lot->status, ['sold', 'sold_subject_to_confirmation', 'passed_in'], true))
    @php
        $winBid = $lot->winning_bid_id ? \App\Models\AuctionBid::withoutGlobalScopes()->find($lot->winning_bid_id) : null;
        $winReg = $winBid ? \App\Models\AuctionRegistration::withoutGlobalScopes()->with('contact')->find($winBid->registration_id) : null;
        $canHammer = $user->hasPermission('auctions.hammer');
    @endphp
    <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-lot-result>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Result</h2>
        <p class="text-sm"><strong>{{ $lot->statusLabel() }}</strong>@if($lot->hammer_price !== null) at <strong>R {{ number_format((float) $lot->hammer_price, 0, '.', ' ') }}</strong>@endif
            @if($winReg) · bidder #{{ $winReg->bidder_number }} {{ $winReg->displayName() }}@endif
            @if($lot->result_note)<br><span class="text-xs" style="color:var(--text-muted);">{{ $lot->result_note }}</span>@endif</p>

        @if($lot->status === 'sold_subject_to_confirmation')
            <p class="text-sm" style="color:#b45309;">The highest bid is below the reserve{{ $canReserve && $lot->reserve_price !== null ? ' (R ' . number_format((float) $lot->reserve_price, 0, '.', ' ') . ')' : '' }}. The seller must confirm or decline{!! $lot->confirmation_deadline ? ' by <strong>' . e($lot->confirmation_deadline->format('d M Y H:i')) . '</strong>' : '' !!}.</p>
            @if($canHammer)
            <div class="flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('corex.auctions.lots.confirm', $lot->id) }}" onsubmit="return confirm('Confirm the sale at this price?');">@csrf<button class="corex-btn-primary">Seller confirms the sale</button></form>
                <form method="POST" action="{{ route('corex.auctions.lots.decline', $lot->id) }}" class="flex items-center gap-2">@csrf
                    <input name="reason" placeholder="Why the seller declined" required class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline" style="color:#ef4444;">Seller declines</button></form>
            </div>
            @endif
        @endif

        @if($lot->status === 'sold')
            @if($lot->deal_id)
                <p class="text-sm">A deal was opened for this sale. <a href="{{ route('deals-dr2.index') }}" class="underline">Open the Deal Register</a></p>
            @elseif($canHammer)
                <p class="text-sm" style="color:#b45309;">No deal has been opened yet. A deal needs the seller to be linked to the property.</p>
                <form method="POST" action="{{ route('corex.auctions.lots.open-deal', $lot->id) }}">@csrf<button class="corex-btn-outline">Open the deal</button></form>
            @endif
        @endif
    </section>
    @endif

    @unless($isNew)
    <section class="rounded-md p-5 flex flex-wrap items-center gap-3" style="background:var(--surface);border:1px solid var(--border);">
        @permission('auctions.lots.manage')
        @if($lot->trashed())
            <form method="POST" action="{{ route('corex.auctions.lots.restore', $lot->id) }}">@csrf<button class="corex-btn-primary">Restore lot</button></form>
        @else
            @unless($lot->isConcluded())
            <form method="POST" action="{{ route('corex.auctions.lots.withdraw', $lot->id) }}" onsubmit="return confirm('Withdraw this lot?');" class="flex items-center gap-2">@csrf
                <input name="reason" placeholder="Reason (optional)" class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline">Withdraw lot</button></form>
            @endunless
            @if(in_array($lot->status, ['draft', 'published', 'withdrawn'], true))
            <form method="POST" action="{{ route('corex.auctions.lots.archive', $lot->id) }}" onsubmit="return confirm('Archive this lot?');">@csrf @method('DELETE')<button class="corex-btn-outline">Archive</button></form>
            @endif
        @endif
        @endpermission
        <a href="{{ route('corex.auctions.edit', $lot->auction_id) }}" class="text-xs underline ml-auto">Open the auction</a>
    </section>
    @endunless
</div>
<script>
    function lotPicker(initial) {
        return {
            picked: initial, q: '', results: [], searched: false,
            pick(r) { this.picked = r; this.results = []; this.q = ''; },
            async search() {
                if (this.q.trim().length < 2) { this.results = []; this.searched = false; return; }
                const res = await fetch('{{ route('api.v1.auctions.property-search') }}?q=' + encodeURIComponent(this.q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                this.results = res.ok ? (await res.json()).data : [];
                this.searched = true;
            },
        };
    }
</script>
@endsection
