@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-6">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">{{ $auction->title }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">{{ $auction->reference }} · {{ ucwords(str_replace('_', ' ', $auction->status)) }} · {{ $auction->starts_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="flex gap-2">
            @if($auction->isCataloguePublished())
            <a href="{{ route('public.auctions.show', $auction->id) }}" target="_blank" class="corex-btn-outline">View public page</a>
            @endif
            @if(! $advertisingOnly)
            @permission('auctions.bidders.view')
            <a href="{{ route('corex.auctions.bidders.index', $auction) }}" class="corex-btn-outline">Bidder Register</a>
            @endpermission
            @permission('auctions.room.operate')
            <a href="{{ route('corex.auctions.room.show', $auction) }}" class="corex-btn-outline">Sale Room</a>
            @endpermission
            @endif
            @permission('auctions.edit')
            <a href="{{ route('corex.auctions.edit', $auction) }}" class="corex-btn-outline">Edit</a>
            @endpermission
            @if($canPublish && $auction->lots->where('status', 'draft')->isNotEmpty())
            <form method="POST" action="{{ route('corex.auctions.publish', $auction) }}" onsubmit="return confirm('Publish the catalogue? Every draft lot goes On Auction and becomes publicly marketable.')">
                @csrf
                <button type="submit" class="corex-btn-primary">Publish Catalogue</button>
            </form>
            @endif
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-crimson,#dc2626) 12%,transparent);color:var(--ds-crimson,#dc2626);">{{ $errors->first() }}
        @if(session('publish_blockers'))
        <ul class="list-disc ml-5 mt-1">@foreach(session('publish_blockers') as $b)<li>{{ $b }}</li>@endforeach</ul>
        @endif
    </div>@endif

    @php
        $lbl = 'text-xs uppercase tracking-wider';
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $docs = [
            'rules' => ['Rules of Auction', $auction->rules_file_path, $auction->rules_file_name],
            'conditions' => ['Conditions of Sale', $auction->conditions_file_path, $auction->conditions_file_name],
        ];
        $venue = trim(($auction->venue_name ?? '').($auction->venue_name && $auction->venue_address ? ', ' : '').($auction->venue_address ?? ''));
    @endphp
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 grid grid-cols-2 lg:grid-cols-3 gap-4 text-sm rounded-md p-4 content-start" style="background:var(--surface);border:1px solid var(--border);">
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Auction date</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->starts_at?->format('D, d M Y \a\t H:i') ?? '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Venue</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $venue ?: '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Bidding mode</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $auction->bidding_mode)) }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Auctioneer</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->isInternal() ? ($auction->auctioneerUser?->name ?? '—') : ($auction->auctioneer_company ?: ($auction->auctioneerContact?->name ?? '—')) }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Licence no.</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->auctioneer_licence_no ?: '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Auctioneer phone</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->auctioneer_phone ?: '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Auctioneer email</div><div class="font-medium mt-0.5 break-all" style="color:var(--text-primary);">{{ $auction->auctioneer_email ?: '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Registration opens</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->registration_opens_at?->format('d M Y, H:i') ?? '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Registration closes</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->registration_closes_at?->format('d M Y, H:i') ?? '—' }}</div></div>
            <div class="col-span-2 lg:col-span-3"><div class="{{ $lbl }}" style="color:var(--text-muted);">Register-to-bid link</div><div class="font-medium mt-0.5 break-all" style="color:var(--text-primary);">@if($auction->external_registration_url)<a href="{{ $auction->external_registration_url }}" target="_blank" rel="noopener" class="underline">{{ $auction->external_registration_url }}</a>@else — @endif</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Branch</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->branch?->name ?? '—' }}</div></div>
            <div><div class="{{ $lbl }}" style="color:var(--text-muted);">Catalogue</div><div class="font-medium mt-0.5" style="color:var(--text-primary);">{{ $auction->isCataloguePublished() ? 'Published '.$auction->catalogue_published_at->format('d M Y') : 'Not published' }}</div></div>
            @if($auction->notes)
            <div class="col-span-2 lg:col-span-3"><div class="{{ $lbl }}" style="color:var(--text-muted);">Notes</div><div class="mt-0.5 whitespace-pre-line" style="color:var(--text-primary);">{{ $auction->notes }}</div></div>
            @endif
        </div>

        <div class="rounded-md overflow-hidden content-start" style="background:var(--surface);border:1px solid var(--border);">
            <div class="px-4 py-3 text-sm font-bold" style="color:var(--text-primary);">Documents</div>
            @foreach($docs as $kind => [$title, $path, $name])
                @php $exists = filled($path) && $disk->exists($path); @endphp
                <div class="px-4 py-3 text-sm" style="border-top:1px solid var(--border);">
                    <div class="font-medium" style="color:var(--text-primary);">{{ $title }}</div>
                    @if(! filled($path))
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">Not uploaded.</div>
                    @elseif(! $exists)
                        <div class="text-xs mt-0.5" style="color:var(--ds-crimson,#dc2626);">{{ $name }} — the file is missing. Upload it again on the Edit page.</div>
                    @else
                        <div class="text-xs mt-0.5 break-all" style="color:var(--text-muted);">{{ $name }}</div>
                        <div class="flex gap-2 mt-2">
                            <a href="{{ route('corex.auctions.document', [$auction, $kind]) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">View</a>
                            <a href="{{ route('corex.auctions.document', [$auction, $kind, 'download' => 1]) }}" class="corex-btn-outline text-xs">Download</a>
                        </div>
                    @endif
                </div>
            @endforeach
            @permission('auctions.edit')
            <div class="px-4 py-3" style="border-top:1px solid var(--border);"><a href="{{ route('corex.auctions.edit', $auction) }}" class="text-xs underline" style="color:var(--text-muted);">Replace documents</a></div>
            @endpermission
        </div>
    </div>

    <div>
        <h2 class="font-medium mb-2">Lots</h2>
        @if($auction->lots->isEmpty())
            <p class="text-muted text-sm">No lots yet — attach a property below.</p>
        @else
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead>
                <tr style="background:var(--surface-2);">
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Lot #</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Property</th>
                    @if($canSeeReserve)<th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reserve</th>@endif
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Guide</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Status</th>
                    <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($auction->lots as $lot)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $lot->lot_number }}</td>
                    <td class="px-4 py-2.5">
                        @if($lot->property)
                            <a href="{{ route('corex.properties.show', $lot->property_id) }}" target="_blank" rel="noopener" class="underline" style="color:var(--text-primary);" title="Open property in a new tab">{{ $lot->property->buildDisplayAddress() }}</a>
                        @else
                            {{ 'Property #'.$lot->property_id }}
                        @endif
                    </td>
                    @if($canSeeReserve)<td class="px-4 py-2.5">{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</td>@endif
                    <td class="px-4 py-2.5">
                        @if($lot->guide_price_min || $lot->guide_price_max)
                            R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }}
                        @else — @endif
                    </td>
                    <td class="px-4 py-2.5">{{ $statusLabels[$lot->status] ?? $lot->status }}</td>
                    <td class="px-4 py-2.5"><div class="flex gap-2 items-center">
                        <a href="{{ route('corex.auctions.lots.show', $lot) }}" class="corex-btn-outline text-xs">Open</a>
                        @if($lot->status === 'draft')
                        @permission('auctions.edit')
                        <form method="POST" action="{{ route('corex.auctions.lots.remove', [$auction, $lot]) }}" onsubmit="return confirm('Remove this lot?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="corex-btn-outline text-xs">Remove</button>
                        </form>
                        @endpermission
                        @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
        @endif
    </div>

    @permission('auctions.create')
    <div class="w-full rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);"
         x-data="{
            q: '', results: [], picked: null, open: false, busy: false, t: null,
            search() {
                clearTimeout(this.t);
                if (this.q.trim().length < 2) { this.results = []; this.open = false; return; }
                this.t = setTimeout(async () => {
                    this.busy = true;
                    try {
                        const r = await fetch('{{ route('api.v1.auctions.property-search', $auction) }}?q=' + encodeURIComponent(this.q.trim()), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        this.results = r.ok ? await r.json() : [];
                    } catch (e) { this.results = []; }
                    this.busy = false; this.open = true;
                }, 250);
            },
            pick(p) { this.picked = p; this.q = ''; this.results = []; this.open = false; },
            clear() { this.picked = null; }
         }" @click.outside="open = false">
        <h2 class="font-medium mb-3">Attach a Property</h2>
        <form method="POST" action="{{ route('corex.auctions.lots.add', $auction) }}" class="flex flex-col gap-3 text-sm">
            @csrf
            <input type="hidden" name="property_id" :value="picked ? picked.id : ''">

            <div class="flex flex-wrap items-end gap-3">
                <div class="relative flex-1" style="min-width:16rem;">
                    <label class="prop-label">Property *</label>
                    <input type="text" x-model="q" @input="search()" @focus="open = results.length > 0" autocomplete="off"
                           placeholder="Search by address or title…" class="prop-input w-full">
                    <div x-show="open" x-cloak class="absolute left-0 right-0 z-50 mt-1 rounded-md border shadow-lg overflow-y-auto"
                         style="max-height:16rem;background:var(--surface-1, var(--surface));border-color:var(--border);">
                        <template x-for="p in results" :key="p.id">
                            <button type="button" @click="pick(p)" class="w-full text-left px-3 py-2 hover:opacity-80" style="border-bottom:1px solid var(--border);color:var(--text-primary);">
                                <span x-text="p.label" class="block text-sm font-medium"></span>
                                <span class="block text-xs" style="color:var(--text-muted);"><span x-text="p.title || ''"></span><span x-show="p.status"> · <span x-text="p.status"></span></span></span>
                            </button>
                        </template>
                        <div x-show="!busy && results.length === 0" class="px-3 py-2 text-xs" style="color:var(--text-muted);">No matching properties.</div>
                    </div>
                </div>
                @if($canSeeReserve)
                <div style="width:9rem;">
                    <label class="prop-label">Reserve Price</label>
                    <input type="number" name="reserve_price" step="0.01" class="prop-input w-full">
                </div>
                @endif
                <div style="width:9rem;">
                    <label class="prop-label">Opening Bid</label>
                    <input type="number" name="opening_bid" step="0.01" class="prop-input w-full">
                </div>
                <div style="width:9rem;">
                    <label class="prop-label">Guide Price Min</label>
                    <input type="number" name="guide_price_min" step="0.01" class="prop-input w-full">
                </div>
                <div style="width:9rem;">
                    <label class="prop-label">Guide Price Max</label>
                    <input type="number" name="guide_price_max" step="0.01" class="prop-input w-full">
                </div>
                <button type="submit" class="corex-btn-primary" :disabled="!picked" :style="!picked ? 'opacity:.5;cursor:not-allowed;' : ''">Add Lot</button>
            </div>

            {{-- Chosen property, shown beneath the search --}}
            <div x-show="picked" x-cloak class="flex items-center justify-between gap-3 rounded-md px-3 py-2" style="border:1px solid var(--border);background:var(--surface-2, var(--surface));">
                <div>
                    <div class="text-sm font-medium" style="color:var(--text-primary);" x-text="picked && picked.label"></div>
                    <div class="text-xs" style="color:var(--text-muted);"><span x-text="picked && picked.title"></span><span x-show="picked && picked.status"> · <span x-text="picked && picked.status"></span></span></div>
                </div>
                <button type="button" @click="clear()" class="corex-btn-outline text-xs">Remove</button>
            </div>
        </form>
    </div>
    @endpermission
</div>
@endsection
