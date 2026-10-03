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

<form method="POST" action="{{ $isEdit ? route('corex.auctions.update', $auction) : route('corex.auctions.store') }}" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 w-full rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);">
    @csrf
    @if($isEdit) @method('PUT') @endif

    @if(!$isEdit && ($prefillProperty ?? null))
        {{-- §9 steps 1-2 combined — see AuctionController::store()'s attachPropertyAsLot() call. --}}
        <input type="hidden" name="property_id" value="{{ $prefillProperty->id }}">
        <div class="sm:col-span-2 xl:col-span-3 rounded bg-blue-50 text-blue-800 px-4 py-2 text-sm">
            This auction will start with <strong>{{ $prefillProperty->buildDisplayAddress() }}</strong> as Lot 1.
        </div>
    @endif

    <div>
        <label class="prop-label">Reference *</label>
        <input type="text" name="reference" value="{{ old('reference', $auction->reference) }}" required maxlength="40" class="prop-input w-full" placeholder="AUC-2026-014">
    </div>
    <div>
        <label class="prop-label">Title *</label>
        <input type="text" name="title" value="{{ old('title', $auction->title) }}" required maxlength="255" class="prop-input w-full">
    </div>

    <div>
        <label class="prop-label">Branch</label>
        <select name="branch_id" class="prop-input w-full">
            <option value="">—</option>
            @foreach($branches as $branch)
                <option value="{{ $branch->id }}" @selected(old('branch_id', $auction->branch_id) == $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="prop-label">Auction Type</label>
        <select name="auction_type_id" class="prop-input w-full">
            <option value="">—</option>
            @foreach($auctionTypes as $type)
                <option value="{{ $type->id }}" @selected(old('auction_type_id', $auction->auction_type_id) == $type->id)>{{ $type->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label class="prop-label">Bidding Mode *</label>
        <select name="bidding_mode" required class="prop-input w-full">
            @foreach(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'Hybrid'] as $val => $label)
                @continue(! in_array($val, $biddingModesEnabled, true))
                <option value="{{ $val }}" @selected(old('bidding_mode', $auction->bidding_mode) === $val)>{{ $label }}</option>
            @endforeach
        </select>
        @if(count($biddingModesEnabled) < 3)
            <p class="text-xs text-muted mt-1">More bidding modes can be enabled in Settings → Auctions.</p>
        @endif
    </div>

    <div>
        <label class="prop-label">Auctioneer *</label>
        <div class="flex gap-3 text-sm mb-1">
            @if($auctioneerMode !== 'external')
            <label><input type="radio" name="auctioneer_kind" value="internal" @checked(old('auctioneer_kind', $auction->auctioneer_kind ?: 'internal') === 'internal')> Our auctioneer</label>
            @endif
            @if($auctioneerMode !== 'internal')
            <label><input type="radio" name="auctioneer_kind" value="external" @checked(old('auctioneer_kind', $auction->auctioneer_kind) === 'external')> Outside auction house</label>
            @endif
        </div>
        <select name="auctioneer_user_id" class="prop-input w-full mb-1">
            <option value="">Select internal auctioneer…</option>
            @foreach($internalAuctioneers as $u)
                <option value="{{ $u->id }}" @selected(old('auctioneer_user_id', $auction->auctioneer_user_id) == $u->id)>{{ $u->name }}</option>
            @endforeach
        </select>
        <input type="text" name="auctioneer_company" value="{{ old('auctioneer_company', $auction->auctioneer_company) }}" placeholder="Auction house name" class="prop-input w-full mb-1">
        <input type="text" name="auctioneer_licence_no" value="{{ old('auctioneer_licence_no', $auction->auctioneer_licence_no) }}" placeholder="Licence no." class="prop-input w-full">
    </div>

    <div>
        <label class="prop-label">Auction Date/Time *</label>
        @include('corex.auctions._datetime', ['name' => 'starts_at', 'label' => 'Auction Date/Time', 'value' => $auction->starts_at, 'required' => true])
    </div>

    <div>
        <label class="prop-label">Registration Opens</label>
        @include('corex.auctions._datetime', ['name' => 'registration_opens_at', 'label' => 'Registration Opens', 'value' => $auction->registration_opens_at])
    </div>
    <div>
        <label class="prop-label">Registration Closes</label>
        @include('corex.auctions._datetime', ['name' => 'registration_closes_at', 'label' => 'Registration Closes', 'value' => $auction->registration_closes_at])
    </div>

    <div>
        <label class="prop-label">Venue Name</label>
        <input type="text" name="venue_name" value="{{ old('venue_name', $auction->venue_name) }}" class="prop-input w-full">
    </div>
    <div>
        <label class="prop-label">Venue Address</label>
        <input type="text" name="venue_address" value="{{ old('venue_address', $auction->venue_address) }}" class="prop-input w-full">
    </div>

    <div class="sm:col-span-2 xl:col-span-3 border-t border-gray-100 pt-3">
        <p class="text-xs font-semibold text-gray-600 mb-2">Public advert — how buyers reach the auctioneer</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="sm:col-span-2">
                <label class="prop-label">Register-to-bid link (the auctioneer's own page, if they take registrations)</label>
                <input type="url" name="external_registration_url" value="{{ old('external_registration_url', $auction->external_registration_url) }}" placeholder="https://" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Auctioneer phone</label>
                <input type="text" name="auctioneer_phone" value="{{ old('auctioneer_phone', $auction->auctioneer_phone) }}" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Auctioneer email</label>
                <input type="email" name="auctioneer_email" value="{{ old('auctioneer_email', $auction->auctioneer_email) }}" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Rules of Auction (PDF) @if($auction->rules_file_name)<span class="text-muted">— current: {{ $auction->rules_file_name }}</span>@endif</label>
                <input type="file" name="rules_file" accept="application/pdf" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Conditions of Sale (PDF) @if($auction->conditions_file_name)<span class="text-muted">— current: {{ $auction->conditions_file_name }}</span>@endif</label>
                <input type="file" name="conditions_file" accept="application/pdf" class="prop-input w-full">
            </div>
        </div>
    </div>

    <div class="sm:col-span-2 xl:col-span-3">
        <label class="prop-label">Notes</label>
        <textarea name="notes" rows="3" class="prop-input w-full">{{ old('notes', $auction->notes) }}</textarea>
    </div>

    <div class="sm:col-span-2 xl:col-span-3 flex gap-2">
        <button type="submit" class="corex-btn-primary">{{ $isEdit ? 'Save' : 'Create Auction' }}</button>
        <a href="{{ $isEdit ? route('corex.auctions.show', $auction) : route('corex.auctions.index') }}" class="corex-btn-outline">Cancel</a>
    </div>
</form>
