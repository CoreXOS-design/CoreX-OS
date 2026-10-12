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
    {{-- Every pill here is the one shared <x-pill> (same size, font, colour); only the small dot carries a state. --}}
    @forelse($contact->parentTypes as $pt)
    <x-pill :label="$pt->name" />
    @empty
    @if($contact->type)
    <x-pill :label="$contact->type->name" />
    @endif
    @endforelse
    <x-pill :label="$commMeta['label']" :title="$commMeta['title'] ?? ''" :dot="$commTint" />
    {{-- Quick Links W1 — FICA status pill (the Send/Resend action lives in the Quick Links menu) --}}
    @include('corex.contacts._fica-quick-link', ['showActions' => false])
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
