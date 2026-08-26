{{-- Linked properties list.

     Extracted as its own partial (pre-existing bug fix, found while verifying
     Phase 4 in a real browser — NOT a Phase 4 change): the forelse-loop
     opening tag here was losing its opening compile in the surrounding
     ~2300-line show.blade.php, 500ing every contact page. Splitting it out
     (same technique as _recent-sends.blade.php / _assigned-agents.blade.php)
     resolves it. Reported to Johan; no logic changed here. --}}
@php
    // Display dedupe (entity model 2026-08-14): a property that is
    // COMPANY-OWNED-VIA-DIRECTORSHIP shows ONLY in the flagged "Company
    // Properties" group (Properties & Core Matches tab), NOT also here as a
    // personal linked property. Exclude by canonical Property id AND normalized
    // street address so the tracked-vs-promoted split of the SAME physical
    // property is caught. The contact_property link itself is untouched —
    // outreach still needs it; this is display-only.
    $companyDedupe = $contact->companyPropertyDedupeKeys();
    $linkedProps = $contact->properties->reject(function ($p) use ($companyDedupe) {
        if (in_array((int) $p->id, $companyDedupe['ids'], true)) {
            return true;
        }
        $addr = \App\Models\Contact::normalizePropertyStreet($p->street_number ?? null, $p->street_name ?? null);
        return $addr !== '' && in_array($addr, $companyDedupe['addresses'], true);
    })->values();
@endphp
<div>
    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">
        Linked Properties ({{ $linkedProps->count() }})
    </h3>
    @forelse($linkedProps as $prop)
    @php
    $propThumb = $prop->thumbFor($prop->gallery_images_json[0] ?? ($prop->dawn_images_json[0] ?? null));
    $propSc = [
        'active' => 'var(--ds-green)',
        'draft' => 'var(--text-muted)',
        'sold' => 'var(--brand-icon)',
        'withdrawn' => 'var(--ds-amber)',
    ][$prop->status] ?? 'var(--text-muted)';
    @endphp
    <div class="flex items-center gap-3 px-4 py-3 rounded-md mb-2" style="background:var(--surface-2); border:1px solid var(--border);">
        {{-- Thumb --}}
        <div class="w-12 h-12 rounded-md overflow-hidden flex-shrink-0" style="background:var(--surface);">
            @if($propThumb)
            <img src="{{ $propThumb }}" alt="" class="w-full h-full object-cover">
            @else
            <div class="w-full h-full flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-6 h-6" style="color:var(--text-muted);opacity:.4;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
            </div>
            @endif
        </div>
        <div class="flex-1 min-w-0">
            <a href="{{ route('corex.properties.show', $prop) }}"
               class="text-sm font-semibold no-underline hover:underline"
               style="color:var(--text-primary);">{{ $prop->title }}</a>
            {{-- AT-243 — same derived truth, read from the other side: this contact is the
                 one who actually bought this property (buyer on its granted/registered deal). --}}
            @if(in_array((int) $contact->id, $prop->purchaserContactIds(), true))
                <span class="ds-badge ds-badge-success" style="margin-left:.4rem;"
                      title="This contact bought this property — they are the buyer on its granted deal.">Purchaser</span>
            @endif
            <div class="text-xs mt-0.5 flex flex-wrap gap-2" style="color:var(--text-muted);">
                <span style="color:{{ $propSc }};">{{ ucfirst($prop->status) }}</span>
                <span>{{ $prop->formattedPrice() }}</span>
                <span>{{ $prop->buildDisplayAddress() }}</span>
                @if($prop->pivot->role)<span class="font-semibold" style="color:var(--brand-icon, #0ea5e9);">{{ ucfirst($prop->pivot->role) }}</span>@endif
            </div>
        </div>
        <form method="POST" action="{{ route('corex.contacts.properties.unlink', [$contact, $prop]) }}"
              onsubmit="return confirm('Unlink this property from {{ addslashes($contact->full_name) }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md transition-all duration-300 flex-shrink-0"
                    style="color: var(--ds-crimson); border: 1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent);">Unlink</button>
        </form>
    </div>
    @if(in_array($prop->pivot->role, ['owner', 'seller', 'landlord', 'lessor']))
        @php
            $sellerLink = \App\Models\PropertySellerLink::ensureExists($prop->id, $contact->id);
            $sellerLinkUrl = url('/property/live/' . $sellerLink->token);
        @endphp
        <div class="flex items-center gap-2 px-4 pb-2 -mt-1 text-[10px]" style="color:var(--text-muted);">
            <span style="color:var(--brand-icon);">Seller Live Link</span>
            <span class="truncate max-w-[200px]" title="{{ $sellerLinkUrl }}">{{ $sellerLinkUrl }}</span>
            <button type="button" onclick="navigator.clipboard.writeText('{{ $sellerLinkUrl }}'); this.textContent='Copied!';"
                    class="font-medium px-1.5 py-0.5 rounded-md flex-shrink-0" style="color: var(--ds-green, #059669); background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);">Copy</button>
        </div>
    @endif
    @empty
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No properties linked</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Use the search below to link an existing property to this contact.</p>
    </div>
    @endforelse
</div>
