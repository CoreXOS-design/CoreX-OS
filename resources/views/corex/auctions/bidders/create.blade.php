@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <div>
        <a href="{{ route('corex.auctions.bidders.index', $auction) }}" class="text-sm text-muted underline">&larr; Bidder Register</a>
        <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Register a Bidder — {{ $auction->title }}</h1>
        <p class="text-xs" style="color:var(--text-muted);">At-the-door / staff registration. The public online registration link is not yet built (Phase 2 follow-up).</p>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-crimson,#dc2626) 12%,transparent);color:var(--ds-crimson,#dc2626);">
            <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.auctions.bidders.store', $auction) }}" class="flex flex-col gap-4 text-sm max-w-2xl rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);" x-data="{ newContact: false }">
        @csrf

        <div>
            <label><input type="checkbox" x-model="newContact" name="new_contact" value="1"> This is a new contact, not already in CoreX</label>
        </div>

        <div x-show="!newContact">
            <label class="prop-label">Existing Contact ID</label>
            <input type="number" name="contact_id" class="prop-input w-full">
            <p class="text-xs text-muted mt-1">Find the contact's ID from <a href="{{ route('corex.contacts.index') }}" target="_blank" class="underline">Contacts</a>.</p>
        </div>

        <div x-show="newContact" x-cloak class="grid grid-cols-2 gap-3">
            <div>
                <label class="prop-label">First Name</label>
                <input type="text" name="new_contact_first_name" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Last Name</label>
                <input type="text" name="new_contact_last_name" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Email</label>
                <input type="email" name="new_contact_email" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Phone</label>
                <input type="text" name="new_contact_phone" class="prop-input w-full">
            </div>
        </div>

        <div>
            <label class="prop-label">Bidding for</label>
            <select name="bidding_for" class="prop-input w-full">
                <option value="self">Themselves</option>
                <option value="entity">A company / trust</option>
                <option value="agent_for_third_party">As agent for a third party</option>
            </select>
        </div>

        <div>
            <label class="prop-label">Entity Contact ID (if bidding for a company/trust)</label>
            <input type="number" name="entity_contact_id" class="prop-input w-full">
        </div>

        <div>
            <button type="submit" class="corex-btn-primary">Register</button>
        </div>
    </form>
</div>
@endsection
