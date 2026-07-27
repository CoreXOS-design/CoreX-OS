{{-- Contacts step aux-partial: inline-manageable lead sources + labels.
     Contact types are the six FIXED signing roles (Owner, Other, Seller, Buyer,
     Lessor, Lessee) — not configurable, so they are deliberately not shown here.
     $contactSources, $contactIdentifierLabels. --}}
<div class="px-6 py-5 space-y-6">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your lead sources</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Where your contacts come from. Add the channels you actually use — they appear on the contact form and in reporting.</p>
    </div>

    @include('agency-setup.steps._collection', [
        'collectionKey' => 'contact_source', 'collectionLabel' => 'Lead sources',
        'collectionPlaceholder' => 'e.g. Walk-in, Referral, Property24', 'items' => $contactSources,
    ])

    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Phone / email labels</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">Assignable to each phone number and email address on a contact (e.g. Personal, Business). Personal/Business/Contact are already here — add more if you need them.</p>
    </div>

    @include('agency-setup.steps._collection', [
        'collectionKey' => 'contact_identifier_label', 'collectionLabel' => 'Phone / email labels',
        'collectionPlaceholder' => 'e.g. Personal, Business', 'items' => $contactIdentifierLabels,
    ])
</div>
