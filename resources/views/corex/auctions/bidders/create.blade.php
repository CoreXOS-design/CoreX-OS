@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col p-6 gap-4 max-w-2xl">
    <div>
        <a href="{{ route('corex.auctions.bidders.index', $auction) }}" class="text-sm text-gray-500 underline">&larr; Bidder Register</a>
        <h1 class="text-xl font-semibold">Register a Bidder — {{ $auction->title }}</h1>
        <p class="text-sm text-gray-500">At-the-door / staff registration. The public online registration link is not yet built (Phase 2 follow-up).</p>
    </div>

    @if($errors->any())
        <div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm">
            <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.auctions.bidders.store', $auction) }}" class="flex flex-col gap-4 text-sm" x-data="{ newContact: false }">
        @csrf

        <div>
            <label><input type="checkbox" x-model="newContact" name="new_contact" value="1"> This is a new contact, not already in CoreX</label>
        </div>

        <div x-show="!newContact">
            <label class="block text-xs text-gray-500">Existing Contact ID</label>
            <input type="number" name="contact_id" class="corex-input w-full">
            <p class="text-xs text-gray-400 mt-1">Find the contact's ID from <a href="{{ route('corex.contacts.index') }}" target="_blank" class="underline">Contacts</a>.</p>
        </div>

        <div x-show="newContact" x-cloak class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-500">First Name</label>
                <input type="text" name="new_contact_first_name" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Last Name</label>
                <input type="text" name="new_contact_last_name" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Email</label>
                <input type="email" name="new_contact_email" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Phone</label>
                <input type="text" name="new_contact_phone" class="corex-input w-full">
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-500">Bidding for</label>
            <select name="bidding_for" class="corex-input w-full">
                <option value="self">Themselves</option>
                <option value="entity">A company / trust</option>
                <option value="agent_for_third_party">As agent for a third party</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-gray-500">Entity Contact ID (if bidding for a company/trust)</label>
            <input type="number" name="entity_contact_id" class="corex-input w-full">
        </div>

        <div>
            <button type="submit" class="corex-btn-primary">Register</button>
        </div>
    </form>
</div>
@endsection
