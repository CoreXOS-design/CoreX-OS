{{-- Shared sign-up / edit fields for a single agent. $signup is null on create. --}}
@php
    $field = 'w-full rounded-md px-3 py-2 text-sm';
    $fstyle = 'background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
    $lbl = 'block text-xs font-medium mb-1';
    $err = fn ($k) => $errors->first($k);
@endphp
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="agency_name">Agency name <span style="color:var(--ds-crimson);">*</span></label>
        <input id="agency_name" type="text" name="agency_name" value="{{ old('agency_name', $signup?->agency?->name) }}" required class="{{ $field }}" style="{{ $fstyle }}" placeholder="The name printed on their documents">
        @if($err('agency_name'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('agency_name') }}</p>@endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="trading_name">Trading / display name <span style="color:var(--text-muted);">(optional)</span></label>
        <input id="trading_name" type="text" name="trading_name" value="{{ old('trading_name', $signup?->agency?->trading_name) }}" class="{{ $field }}" style="{{ $fstyle }}" placeholder="Defaults to the agency name">
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="name">Agent's full name <span style="color:var(--ds-crimson);">*</span></label>
        <input id="name" type="text" name="name" value="{{ old('name', $signup?->user?->name) }}" required class="{{ $field }}" style="{{ $fstyle }}">
        @if($err('name'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('name') }}</p>@endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="email">Email (their login) <span style="color:var(--ds-crimson);">*</span></label>
        @if($signup)
            <input id="email" type="email" value="{{ $signup->user?->email }}" disabled class="{{ $field }}" style="{{ $fstyle }} opacity:.7;">
            <p class="text-xs mt-1" style="color:var(--text-muted);">The login email cannot be changed here.</p>
        @else
            <input id="email" type="email" name="email" value="{{ old('email') }}" required class="{{ $field }}" style="{{ $fstyle }}">
            @if($err('email'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('email') }}</p>@endif
        @endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="cell">Mobile <span style="color:var(--ds-crimson);">*</span></label>
        <input id="cell" type="text" name="cell" value="{{ old('cell', $signup?->user?->cell) }}" required class="{{ $field }}" style="{{ $fstyle }}">
        @if($err('cell'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('cell') }}</p>@endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="ffc_number">FFC number <span style="color:var(--ds-crimson);">*</span></label>
        <input id="ffc_number" type="text" name="ffc_number" value="{{ old('ffc_number', $signup?->ffc_number) }}" required class="{{ $field }}" style="{{ $fstyle }}">
        @if($err('ffc_number'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('ffc_number') }}</p>@endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="ffc_expires_on">FFC expires <span style="color:var(--ds-crimson);">*</span></label>
        <input id="ffc_expires_on" type="date" name="ffc_expires_on" value="{{ old('ffc_expires_on', $signup?->ffc_expires_on?->toDateString()) }}" required class="{{ $field }}" style="{{ $fstyle }}">
        @if($err('ffc_expires_on'))<p class="text-xs mt-1" style="color:var(--ds-crimson);">{{ $err('ffc_expires_on') }}</p>@endif
    </div>
    <div>
        <label class="{{ $lbl }}" style="color:var(--text-secondary);" for="agency_type">What they do</label>
        @if($signup)
            <input id="agency_type" type="text" value="{{ ucfirst($signup->agency?->agency_type ?: 'both') }}" disabled class="{{ $field }}" style="{{ $fstyle }} opacity:.7;">
        @else
            <select id="agency_type" name="agency_type" class="{{ $field }}" style="{{ $fstyle }}">
                <option value="both" @selected(old('agency_type', 'both') === 'both')>Sales and rentals</option>
                <option value="sales" @selected(old('agency_type') === 'sales')>Sales only</option>
                <option value="rentals" @selected(old('agency_type') === 'rentals')>Rentals only</option>
            </select>
        @endif
    </div>
</div>
