{{-- Contacts step aux-partial: inline-manageable lead sources.
     Contact types are the six FIXED signing roles (Owner, Other, Seller, Buyer,
     Lessor, Lessee) — not configurable, so they are deliberately not shown here.
     $contactSources. --}}
<div class="px-6 py-5 space-y-6">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your lead sources</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Where your contacts come from. Add the channels you actually use — they appear on the contact form and in reporting.</p>
    </div>

    @include('agency-setup.steps._collection', [
        'collectionKey' => 'contact_source', 'collectionLabel' => 'Lead sources',
        'collectionPlaceholder' => 'e.g. Walk-in, Referral, Property24', 'items' => $contactSources,
    ])
</div>
