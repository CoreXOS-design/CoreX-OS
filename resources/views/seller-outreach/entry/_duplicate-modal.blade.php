{{--
    Pitch capture — blocking duplicate panel.

    Parity with the Contacts screen (components/duplicate-detection-modal.blade.php)
    and the DR2 party-picker: when EntryPointController::duplicateGate() finds an
    existing contact by phone/email/ID at ADD TIME, it flashes `pitch_duplicate`
    and redirects back here instead of silently linking and only telling the agent
    on the composer. The agent decides on the spot:
      • Use existing & continue  → re-POST with contact_id (links, then composer)
      • Create new anyway         → re-POST with bypass_duplicate_check=1

    Requires the caller to pass $actionUrl (the matching store route).

    Expected session('pitch_duplicate') shape:
      { mode, can_override, duplicates: [{id, name, phone, email, owner}] }
--}}
@if(session('pitch_duplicate'))
@php $pd = session('pitch_duplicate'); @endphp
<div class="rounded-lg p-5"
     style="background: color-mix(in srgb, var(--ds-amber) 8%, var(--surface)); border: 2px solid color-mix(in srgb, var(--ds-amber) 40%, transparent);">

    {{-- Header --}}
    <div class="flex items-start gap-3 mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
        </svg>
        <div>
            <div class="text-sm font-semibold" style="color:var(--ds-amber);">
                @if($pd['mode'] === 'soft_warn')
                    Possible duplicate — is this the same person?
                @elseif($pd['mode'] === 'hard_block_override')
                    Duplicate blocked
                @else
                    Contact already exists
                @endif
            </div>
            <p class="text-xs mt-0.5" style="color:var(--text-secondary);">
                @if($pd['mode'] === 'soft_warn')
                    A contact matching this phone or email already exists. Pitch to the existing contact, or create a new one if this is a different person.
                @elseif($pd['mode'] === 'hard_block_override')
                    A contact matching this phone or email already exists. Only an administrator may create a duplicate.
                @else
                    A contact matching this phone or email is already managed by another agent. Continue with the existing contact.
                @endif
            </p>
        </div>
    </div>

    {{-- Matches --}}
    <div class="space-y-2 mb-4">
        @foreach($pd['duplicates'] ?? [] as $dup)
            <div class="rounded-md p-3 flex items-center gap-3" style="background:var(--surface); border:1px solid var(--border);">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                     style="background:var(--brand-icon,#0ea5e9);">
                    {{ strtoupper(substr($dup['name'] ?? '', 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $dup['name'] }}</div>
                    <div class="text-xs" style="color:var(--text-muted);">
                        @if(!empty($dup['phone'])){{ $dup['phone'] }}@endif
                        @if(!empty($dup['phone']) && !empty($dup['email'])) · @endif
                        @if(!empty($dup['email'])){{ $dup['email'] }}@endif
                        @if(empty($dup['phone']) && empty($dup['email']))Managed by {{ $dup['owner'] ?? 'another agent' }}@endif
                    </div>
                </div>
                {{-- Link this existing contact into the pitch and go to the composer.
                     Agency-scoped link by id (the controller bypasses ContactScope
                     per Non-Negotiable #10), so a seller captured by another agent
                     can still be pitched. --}}
                <form method="POST" action="{{ $actionUrl }}" class="shrink-0">
                    @csrf
                    <input type="hidden" name="contact_id" value="{{ $dup['id'] }}">
                    {{-- #3 Address-first: carry the captured address through the link
                         path so an address-less listing still lands it on promotion. --}}
                    @if(old('address') !== null && old('address') !== '')
                        <input type="hidden" name="address" value="{{ old('address') }}">
                    @endif
                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md"
                            style="background:var(--brand-button,#0ea5e9); color:#fff; border:0; cursor:pointer;">
                        Use &amp; continue →
                    </button>
                </form>
            </div>
        @endforeach
    </div>

    {{-- Create anyway — soft_warn (anyone) or hard_block_override (admins only).
         Never for hard_block_request. Carries the captured fields back through
         old() + the bypass flag so the store route creates instead of re-blocking. --}}
    @if($pd['mode'] === 'soft_warn' || ($pd['mode'] === 'hard_block_override' && ($pd['can_override'] ?? false)))
        <form method="POST" action="{{ $actionUrl }}" class="inline">
            @csrf
            @foreach(['first_name','last_name','phone','email','id_number','address'] as $f)
                @if(old($f) !== null && old($f) !== '')
                    <input type="hidden" name="{{ $f }}" value="{{ old($f) }}">
                @endif
            @endforeach
            <input type="hidden" name="bypass_duplicate_check" value="1">
            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md"
                    style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border); cursor:pointer;">
                @if($pd['mode'] === 'hard_block_override')Create anyway (Admin)@else Create as new contact @endif
            </button>
        </form>
    @endif
</div>
@endif
