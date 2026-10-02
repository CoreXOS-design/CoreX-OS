@php
    $isEdit = $auction->exists;
@endphp

@if($errors->any())
    <div class="rounded bg-red-50 text-red-800 px-4 py-2 text-sm mb-4">
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $isEdit ? route('corex.auctions.update', $auction) : route('corex.auctions.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-3xl">
    @csrf
    @if($isEdit) @method('PUT') @endif

    @if(!$isEdit && ($prefillProperty ?? null))
        {{-- §9 steps 1-2 combined — see AuctionController::store()'s attachPropertyAsLot() call. --}}
        <input type="hidden" name="property_id" value="{{ $prefillProperty->id }}">
        <div class="sm:col-span-2 rounded bg-blue-50 text-blue-800 px-4 py-2 text-sm">
            This auction will start with <strong>{{ $prefillProperty->buildDisplayAddress() }}</strong> as Lot 1.
        </div>
    @endif

    <div>
        <label class="block text-xs text-gray-500">Reference *</label>
        <input type="text" name="reference" value="{{ old('reference', $auction->reference) }}" required maxlength="40" class="corex-input w-full" placeholder="AUC-2026-014">
    </div>
    <div>
        <label class="block text-xs text-gray-500">Title *</label>
        <input type="text" name="title" value="{{ old('title', $auction->title) }}" required maxlength="255" class="corex-input w-full">
    </div>

    <div>
        <label class="block text-xs text-gray-500">Branch</label>
        <select name="branch_id" class="corex-input w-full">
            <option value="">—</option>
            @foreach($branches as $branch)
                <option value="{{ $branch->id }}" @selected(old('branch_id', $auction->branch_id) == $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500">Auction Type</label>
        <select name="auction_type_id" class="corex-input w-full">
            <option value="">—</option>
            @foreach($auctionTypes as $type)
                <option value="{{ $type->id }}" @selected(old('auction_type_id', $auction->auction_type_id) == $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500">Bidding Mode *</label>
        <select name="bidding_mode" required class="corex-input w-full">
            @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                @continue(! in_array($val, $biddingModesEnabled, true))
                <option value="{{ $val }}" @selected(old('bidding_mode', $auction->bidding_mode) === $val)>{{ $label }}</option>
            @endforeach
        </select>
        @if(count($biddingModesEnabled) < 3)
            <p class="text-xs text-gray-400 mt-1">More bidding modes can be enabled in Settings → Auctions.</p>
        @endif
    </div>

    <div>
        <label class="block text-xs text-gray-500">Auctioneer *</label>
        <div class="flex gap-3 text-sm mb-1">
            @if($auctioneerMode !== 'external')
            <label><input type="radio" name="auctioneer_kind" value="internal" @checked(old('auctioneer_kind', $auction->auctioneer_kind ?: 'internal') === 'internal')> Our auctioneer</label>
            @endif
            @if($auctioneerMode !== 'internal')
            <label><input type="radio" name="auctioneer_kind" value="external" @checked(old('auctioneer_kind', $auction->auctioneer_kind) === 'external')> Outside auction house</label>
            @endif
        </div>
        <select name="auctioneer_user_id" class="corex-input w-full mb-1">
            <option value="">Select internal auctioneer…</option>
            @foreach($internalAuctioneers as $u)
                <option value="{{ $u->id }}" @selected(old('auctioneer_user_id', $auction->auctioneer_user_id) == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
        <input type="text" name="auctioneer_company" value="{{ old('auctioneer_company', $auction->auctioneer_company) }}" placeholder="Auction house name" class="corex-input w-full mb-1">
        <input type="text" name="auctioneer_licence_no" value="{{ old('auctioneer_licence_no', $auction->auctioneer_licence_no) }}" placeholder="Licence no." class="corex-input w-full">
    </div>

    <div>
        <label class="block text-xs text-gray-500">Auction Date/Time *</label>
        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $auction->starts_at?->format('Y-m-d\TH:i')) }}" required class="corex-input w-full">
    </div>

    <div>
        <label class="block text-xs text-gray-500">Registration Opens</label>
        <input type="datetime-local" name="registration_opens_at" value="{{ old('registration_opens_at', $auction->registration_opens_at?->format('Y-m-d\TH:i')) }}" class="corex-input w-full">
    </div>
    <div>
        <label class="block text-xs text-gray-500">Registration Closes</label>
        <input type="datetime-local" name="registration_closes_at" value="{{ old('registration_closes_at', $auction->registration_closes_at?->format('Y-m-d\TH:i')) }}" class="corex-input w-full">
    </div>

    <div>
        <label class="block text-xs text-gray-500">Venue Name</label>
        <input type="text" name="venue_name" value="{{ old('venue_name', $auction->venue_name) }}" class="corex-input w-full">
    </div>
    <div>
        <label class="block text-xs text-gray-500">Venue Address</label>
        <input type="text" name="venue_address" value="{{ old('venue_address', $auction->venue_address) }}" class="corex-input w-full">
    </div>

    <div class="sm:col-span-2 border-t border-gray-100 pt-3">
        <p class="text-xs font-semibold text-gray-600 mb-2">Public advert — how buyers reach the auctioneer</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2">
                <label class="block text-xs text-gray-500">Register-to-bid link (the auctioneer's own page, if they take registrations)</label>
                <input type="url" name="external_registration_url" value="{{ old('external_registration_url', $auction->external_registration_url) }}" placeholder="https://" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Auctioneer phone</label>
                <input type="text" name="auctioneer_phone" value="{{ old('auctioneer_phone', $auction->auctioneer_phone) }}" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Auctioneer email</label>
                <input type="email" name="auctioneer_email" value="{{ old('auctioneer_email', $auction->auctioneer_email) }}" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Rules of Auction (PDF) @if($auction->rules_file_name)<span class="text-gray-400">— current: {{ $auction->rules_file_name }}</span>@endif</label>
                <input type="file" name="rules_file" accept="application/pdf" class="corex-input w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500">Conditions of Sale (PDF) @if($auction->conditions_file_name)<span class="text-gray-400">— current: {{ $auction->conditions_file_name }}</span>@endif</label>
                <input type="file" name="conditions_file" accept="application/pdf" class="corex-input w-full">
            </div>
        </div>
    </div>

    <div class="sm:col-span-2">
        <label class="block text-xs text-gray-500">Notes</label>
        <textarea name="notes" rows="3" class="corex-input w-full">{{ old('notes', $auction->notes) }}</textarea>
    </div>

    <div class="sm:col-span-2 flex gap-2">
        <button type="submit" class="corex-btn-primary">{{ $isEdit ? 'Save' : 'Create Auction' }}</button>
        <a href="{{ $isEdit ? route('corex.auctions.show', $auction) : route('corex.auctions.index') }}" class="corex-btn-outline">Cancel</a>
    </div>
</form>
