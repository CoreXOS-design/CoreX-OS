{{-- Contact header — TYPE + COMMUNICATION-STATUS badges.
     $commMeta / $commTint are computed once in show.blade.php.
     Params: $justify (string, optional) — flex justification.

     AT-392, 2026-09-11 (cc2's re-report) — a contact holds MANY types, not
     one (e.g. Seller AND Tenant). This used to show only $contact->type,
     the single denormalised primary-type mirror, so a second type a save
     had actually kept was invisible here even though it was correctly
     preserved in the database. Loops the full parentTypes set instead —
     same root fix as the type-picker save hardening in ContactController.
--}}
<div class="flex items-center gap-2 flex-wrap {{ $justify ?? '' }}">
    @forelse($contact->parentTypes as $pt)
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
        {{ $pt->name }}
    </span>
    @empty
    @if($contact->type)
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
        {{ $contact->type->name }}
    </span>
    @endif
    @endforelse
    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
          title="{{ $commMeta['title'] ?? '' }}"
          style="background:{{ $commTint }}; color:#fff;">
        {{ $commMeta['label'] }}
    </span>
    {{-- Quick Links W1 — FICA status + Send/Resend (spec quick-links.md §4) --}}
    @include('corex.contacts._fica-quick-link')
    {{-- Quick Links W2 (spec §5.1) — buyer chips. Pure reads of data the page already holds; each click goes to where it is fixed. --}}
    @if($contact->is_buyer)
        @php
            $__pa = $contact->preapprovalQuickState();
            $__pm = $contact->primaryMatch();
            $__hasWish = $contact->hasCountableWishlist();
        @endphp
        <x-quick-link :tone="$__pa['tone']" :label="$__pa['label']" :title="$__pa['detail']"
                      :href="route('corex.contacts.show', ['contact' => $contact, 'tab' => 'info']) . '#preapproval'"
                      data-preapproval-chip="{{ $__pa['key'] }}" />
        @if($__hasWish && $__pm)
            <x-quick-link tone="info" label="Wishlist set" title="Open this buyer's matches"
                          :href="route('corex.contacts.matches.results', ['contact' => $contact, 'match' => $__pm])" data-wishlist-chip="set" />
        @else
            <x-quick-link tone="warning" label="No wishlist" title="This buyer has no search criteria yet, so they match nothing — add one on the Properties tab."
                          :href="route('corex.contacts.show', ['contact' => $contact, 'tab' => 'properties'])" data-wishlist-chip="none" />
        @endif
    @endif
</div>
