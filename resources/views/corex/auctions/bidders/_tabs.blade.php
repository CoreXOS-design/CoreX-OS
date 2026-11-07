{{-- The Bidders tabs: the registration views, then "Signed up" (agency level, .ai/specs/auctions.md §19.1). $active = a view key or 'signed_up'. --}}
<div class="flex flex-wrap gap-2 text-sm" data-bidder-tabs>
    @foreach($views as $key => $label)
        <a href="{{ route('corex.auctions.bidders.index', array_merge($active === 'signed_up' ? [] : request()->except('view', 'page'), ['view' => $key])) }}"
           class="px-3 py-1.5 rounded-md" style="border:1px solid var(--border);{{ $active === $key ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);' }}" data-bidder-view="{{ $key }}">{{ $label }}</a>
    @endforeach
    @if($canSeeSignedUp ?? false)
        <a href="{{ route('corex.auctions.bidders.signed-up') }}"
           class="px-3 py-1.5 rounded-md" style="border:1px solid var(--border);{{ $active === 'signed_up' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);' }}" data-bidder-view="signed_up">Signed up</a>
    @endif
</div>
