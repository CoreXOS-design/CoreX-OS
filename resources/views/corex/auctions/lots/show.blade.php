@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex-shrink-0">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('corex.auctions.show', $lot->auction) }}" class="text-xs underline" style="color:var(--text-muted);">&larr; {{ $lot->auction->title }}</a>
                <h1 class="text-base font-bold leading-tight mt-1" style="color:var(--text-primary);">
                    Lot {{ $lot->lot_number }} —
                    @if($lot->property)
                        <a href="{{ route('corex.properties.show', $lot->property_id) }}" target="_blank" rel="noopener" class="underline" title="Open property in a new tab">{{ $lot->property->buildDisplayAddress() }}</a>
                    @else
                        {{ 'Property #'.$lot->property_id }}
                    @endif
                </h1>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold self-start md:self-auto" style="background:color-mix(in srgb,var(--brand-button,#0ea5e9) 15%,transparent);color:var(--text-primary);border:1px solid var(--border);">{{ $statusLabels[$lot->status] ?? $lot->status }}</span>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-green,#10b981) 12%,transparent);color:var(--ds-green,#10b981);">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-crimson,#dc2626) 12%,transparent);color:var(--ds-crimson,#dc2626);">{{ $errors->first() }}</div>@endif

    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4 text-sm rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
        @if($canSeeReserve)<div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Reserve</span><br><span class="font-medium" style="color:var(--text-primary);">{{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : '—' }}</span></div>@endif
        <div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Guide</span><br><span class="font-medium" style="color:var(--text-primary);">
            @if($lot->guide_price_min || $lot->guide_price_max)
                R {{ number_format($lot->guide_price_min ?? 0, 0) }} – R {{ number_format($lot->guide_price_max ?? 0, 0) }}
            @else — @endif
        </span></div>
        <div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Hammer Price</span><br><span class="font-medium" style="color:var(--text-primary);">{{ $lot->hammer_price ? 'R '.number_format($lot->hammer_price, 0) : '—' }}</span></div>
        @if($lot->hammer_at)<div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Hammer At</span><br><span class="font-medium" style="color:var(--text-primary);">{{ $lot->hammer_at->format('d M Y H:i') }}</span></div>@endif
        @if(! is_null($lot->reserve_met))<div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Reserve Met</span><br><span class="font-medium" style="color:var(--text-primary);">{{ $lot->reserve_met ? 'Yes' : 'No' }}</span></div>@endif
        @if($lot->confirmation_deadline)<div><span class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">Confirmation Due</span><br><span class="font-medium" style="color:var(--text-primary);">{{ $lot->confirmation_deadline->format('d M Y H:i') }}</span></div>@endif
    </div>

    @if($lot->status === 'sold')
    @permission('auctions.edit')
    <div>
        @if($lot->deal_id)
        <a href="{{ route('deals-dr2.edit', $lot->deal_id) }}" class="corex-btn-outline text-sm">View Deal</a>
        @else
        <form method="POST" action="{{ route('corex.auctions.lots.open-deal', $lot) }}">
            @csrf
            <button type="submit" class="corex-btn-primary text-sm">Open Deal</button>
        </form>
        <p class="text-xs text-muted mt-1">Usually opens automatically on the fall of the hammer — use this if it didn't (e.g. the property has no linked seller yet).</p>
        @endif
    </div>
    @endpermission
    @endif

    @permission('auctions.edit')
    <div class="flex flex-wrap gap-2">
        @if($advertisingOnly && in_array($lot->status, ['catalogued', 'open_for_bids', 'under_the_hammer'], true))
        <form method="POST" action="{{ route('corex.auctions.lots.record-result', $lot) }}" class="flex flex-wrap gap-2 items-end rounded-md p-3 w-full" style="background:var(--surface);border:1px solid var(--border);">
            @csrf
            <div><label class="prop-label">Result of the sale</label>
                <select name="outcome" class="prop-input" onchange="this.form.hammer_price.disabled = this.value !== 'sold'">
                    <option value="sold">Sold</option><option value="passed_in">Passed in</option><option value="withdrawn">Withdrawn</option>
                </select></div>
            <div><label class="prop-label">Sold price (R)</label><input type="number" name="hammer_price" step="0.01" class="prop-input"></div>
            <div><label class="prop-label">Note (optional)</label><input type="text" name="reason" class="prop-input"></div>
            <button type="submit" class="corex-btn-primary">Record result</button>
            <p class="text-xs text-muted w-full">The sale was run elsewhere — record what happened and the public advert will show it.</p>
        </form>
        @endif
        @if(! $advertisingOnly && $lot->status === 'catalogued')
        <form method="POST" action="{{ route('corex.auctions.lots.open', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Open for Bids</button></form>
        @endif
        @if($lot->status === 'open_for_bids')
        @permission('auctions.hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.hammer.start', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Start Hammer</button></form>
        @endpermission
        @endif
        @if($lot->status === 'under_the_hammer')
        @permission('auctions.hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.hammer.record', $lot) }}" class="flex gap-2 items-end">
            @csrf
            <div>
                <label class="prop-label">Hammer Price *</label>
                <input type="number" name="hammer_price" step="0.01" required class="prop-input">
            </div>
            <button type="submit" class="corex-btn-primary">Record Hammer</button>
        </form>
        @endpermission
        @endif
        @if($lot->status === 'sold_subject_to_confirmation')
        <form method="POST" action="{{ route('corex.auctions.lots.confirm', $lot) }}">@csrf<button type="submit" class="corex-btn-primary">Seller Confirms Sale</button></form>
        <form method="POST" action="{{ route('corex.auctions.lots.decline', $lot) }}" class="flex gap-2 items-end">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="prop-input">
            <button type="submit" class="corex-btn-outline">Seller Declines</button>
        </form>
        @endif
        @if(! in_array($lot->status, ['sold', 'passed_in', 'withdrawn']))
        <form method="POST" action="{{ route('corex.auctions.lots.withdraw', $lot) }}" class="flex gap-2 items-end" onsubmit="return confirm('Withdraw this lot?')">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="prop-input">
            <button type="submit" class="corex-btn-outline">Withdraw</button>
        </form>
        @endif
        @if($lot->status === 'under_the_hammer')
        <form method="POST" action="{{ route('corex.auctions.lots.passed-in', $lot) }}" class="flex gap-2 items-end" onsubmit="return confirm('Mark this lot passed in — no bid reached the reserve?')">
            @csrf
            <input type="text" name="reason" placeholder="Reason (optional)" class="prop-input">
            <button type="submit" class="corex-btn-outline">Passed In</button>
        </form>
        @endif
    </div>
    @endpermission

    @if($lot->status === 'passed_in' && $topUnderBidders->isNotEmpty())
    <div class="rounded-md p-4" style="background:color-mix(in srgb,var(--ds-amber,#f59e0b) 12%,transparent);border:1px solid color-mix(in srgb,var(--ds-amber,#f59e0b) 40%,transparent);">
        <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Top Under-Bidders — §12.3</h2>
        <p class="text-sm text-muted mb-2">Every one of these bid but did not win — the strongest, most qualified leads this passed-in lot produced. Negotiate from the top down.</p>
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);"><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Paddle</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Bidder</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Highest Bid</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th></tr></thead>
            <tbody>
                @foreach($topUnderBidders as $bid)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $bid->bidder?->paddle_number }}</td>
                    <td class="px-4 py-2.5">{{ $bid->bidder?->contact?->full_name }}</td>
                    <td class="px-4 py-2.5">R {{ number_format($bid->amount, 0) }}</td>
                    <td class="px-4 py-2.5">
                        @if($bid->bidder?->contact)
                        <a href="{{ route('corex.contacts.show', $bid->bidder->contact) }}" class="corex-btn-outline text-xs">Open Contact</a>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
    </div>
    @endif

    <div>
        <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Viewings — §5.6</h2>
        <p class="text-sm text-muted mb-2">Scheduled viewing windows before the sale. Published viewings appear on the calendar and, once live, the public lot page.</p>
        <div class="rounded-md overflow-x-auto mb-3" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);"><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Starts</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Ends</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Type</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Notes</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Agent</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);"></th></tr></thead>
            <tbody>
                @forelse($lot->viewings as $viewing)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $viewing->starts_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $viewing->ends_at->format('H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $viewing->is_by_appointment ? 'By appointment' : 'Open' }}</td>
                    <td class="px-4 py-2.5">{{ $viewing->notes ?? '—' }}</td>
                    <td class="px-4 py-2.5">{{ $viewing->agent?->name ?? '—' }}</td>
                    <td class="px-4 py-2.5">
                        @permission('auctions.edit')
                        <form method="POST" action="{{ route('corex.auctions.lots.viewings.destroy', [$lot, $viewing]) }}" onsubmit="return confirm('Remove this viewing?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs underline" style="color:var(--ds-crimson,#dc2626);">Remove</button>
                        </form>
                        @endpermission
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-2 text-muted">No viewings scheduled yet.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
        @permission('auctions.edit')
        <form method="POST" action="{{ route('corex.auctions.lots.viewings.store', $lot) }}" class="flex flex-wrap gap-3 items-end rounded-md p-3" style="background:var(--surface);border:1px solid var(--border);">
            @csrf
            <div style="min-width:17rem;">
                <label class="prop-label">Starts *</label>
                @include('corex.auctions._datetime', ['name' => 'starts_at', 'label' => 'Starts', 'value' => null, 'required' => true])
            </div>
            <div style="min-width:17rem;">
                <label class="prop-label">Ends *</label>
                @include('corex.auctions._datetime', ['name' => 'ends_at', 'label' => 'Ends', 'value' => null, 'required' => true])
            </div>
            <label class="flex items-center gap-1 text-sm pb-2"><input type="checkbox" name="is_by_appointment" value="1"> By appointment</label>
            <div>
                <label class="prop-label">Notes</label>
                <input type="text" name="notes" class="prop-input" placeholder="Optional">
            </div>
            <button type="submit" class="corex-btn-outline text-sm">Add Viewing</button>
        </form>
        @endpermission
    </div>

    <div>
        <h2 class="text-sm font-bold mb-2" style="color:var(--text-primary);">History</h2>
        <div class="rounded-md overflow-x-auto" style="background:var(--surface);border:1px solid var(--border);">
<table class="min-w-full text-sm">
            <thead><tr style="background:var(--surface-2);"><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">When</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">From</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">To</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">By</th><th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Reason</th></tr></thead>
            <tbody>
                @foreach($lot->statusHistory as $row)
                <tr style="border-top:1px solid var(--border);">
                    <td class="px-4 py-2.5">{{ $row->created_at?->format('d M Y H:i') }}</td>
                    <td class="px-4 py-2.5">{{ $row->from_status ?? '—' }}</td>
                    <td class="px-4 py-2.5">{{ $row->to_status }}</td>
                    <td class="px-4 py-2.5">{{ $row->changedBy?->name ?? 'System' }}</td>
                    <td class="px-4 py-2.5">{{ $row->reason ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
</div>
    </div>
</div>
@endsection
