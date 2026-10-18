{{-- ════════════════════════════════════════════════════════════════════════
     Contact header — ACTION CLUSTER (right-hand side of the header toolbar).

     AT-336 restyle. Extracted verbatim from show.blade.php — every route,
     permission gate, form, Alpine binding and confirm() below is the original,
     unchanged. "Back to Contacts" is NOT here: the header pins it to the left
     of the toolbar via _header-back.

     Quick Links (Johan 2026-10-12): the action buttons — Schedule Event, Buyer Hub,
     Viewing Pack, Create Listing, birthday reminder — now live in ONE "Quick Links"
     dropdown instead of a row of buttons. Each item keeps its own route and gate.
     The Rental Application link was dropped from this page at Johan's request.
     Delete Contact stays outside the menu (destructive, kept apart on purpose).
     The status chips (FICA, pre-approval, wishlist) stay in the header badges.

     Params:
       $contact    (Contact, required)
       $wrapClass  (string, optional) — classes on the cluster wrapper.
     ════════════════════════════════════════════════════════════════════════ --}}
@php
    $__wrap = $wrapClass ?? 'flex flex-wrap items-center gap-2 flex-shrink-0';
    $__canListing = auth()->user()->hasPermission('access_properties') && $contact->properties()->count() === 0;
    $__item   = 'flex items-center gap-2.5 px-3 py-2 text-sm no-underline w-full text-left transition-colors';
    $__itemSt = 'color:var(--text-primary); background:transparent;';
    $__ficaModal = 'fica-send-' . $contact->id . '-menu';
    $__hover  = "onmouseover=\"this.style.background='var(--surface-2)'\" onmouseout=\"this.style.background='transparent'\"";
@endphp
<div class="{{ $__wrap }}">
    @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])

    {{-- One dropdown for every quick action on this contact. --}}
    <div class="relative flex-shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false" data-contact-quick-links>
        <button type="button" @click="open = !open" class="corex-btn-primary text-xs no-underline" aria-haspopup="true" :aria-expanded="open"
                title="Schedule, build a viewing pack, create a listing and other actions for this contact">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/></svg>
            Quick Links
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ml-0.5" :class="open ? 'rotate-180' : ''" style="transition:transform .2s;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div x-show="open" x-transition @click.outside="open = false" x-cloak
             class="absolute right-0 mt-1 w-64 rounded-md overflow-hidden z-30 shadow-lg"
             style="background:var(--surface); border:1px solid var(--border);">

            {{-- Schedule Event from Contact --}}
            <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $contact->id, 'prefill_class' => $contact->is_buyer ? 'viewing' : 'meeting']) }}"
               target="_blank" rel="noopener" class="{{ $__item }}" style="{{ $__itemSt }}" {!! $__hover !!}
               title="Opens the calendar in a new tab so you stay on this contact">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                Schedule Event
            </a>

            {{-- FICA: Send / Send new / Resend, whichever fits where this contact's FICA stands (nothing when there is nothing to do) --}}
            @include('corex.contacts._fica-quick-menu-item')

            @if($contact->is_buyer)
            {{-- This contact's profile on the Buyers Pipeline (Buyer Hub) — shown for any contact on the pipeline (is_buyer);
                 a feature link, so it sits inside @feature('buyer-pipeline') (FeatureNavGuardCoverageTest). --}}
            @feature('buyer-pipeline')
            <a data-buyer-pipeline-profile href="{{ route('command-center.buyers.show', $contact) }}" class="{{ $__item }}" style="{{ $__itemSt }} border-top:1px solid var(--border);" {!! $__hover !!}>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                <span>Buyer Pipeline Profile
                    <span class="block text-xs" style="color:var(--text-muted);">{{ ucfirst($contact->buyer_state ?: 'new') }} buyer · open in the pipeline</span></span>
            </a>
            @endfeature

            {{-- Viewing Pack: one click, same POST the Buyer Hub uses --}}
            @feature('viewing-packs')
            @permission('viewing_packs.create')
            <form method="POST" action="{{ route('corex.viewing-packs.store') }}" style="border-top:1px solid var(--border);">
                @csrf
                <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                <button type="submit" class="{{ $__item }}" style="{{ $__itemSt }}" {!! $__hover !!} data-viewing-pack-quick
                        title="Creates a draft viewing pack for this buyer and opens it">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                    Viewing Pack
                </button>
            </form>
            @endpermission
            @endfeature
            @endif

            {{-- Create Listing from Contact (only if no linked properties): both routes pre-fill the contact's address and link
                 the contact as seller/landlord on save. --}}
            @if($__canListing)
            <a href="{{ route('corex.properties.wizard') }}?contact_id={{ $contact->id }}" target="_blank" rel="noopener"
               class="{{ $__item }}" style="{{ $__itemSt }} border-top:1px solid var(--border);" {!! $__hover !!}
               title="Create a new property linked to this contact — guided, 4 quick steps">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                <span>Create Listing <span class="block text-xs" style="color:var(--text-muted);">Upload Wizard · guided</span></span>
            </a>
            <a href="{{ route('corex.properties.create') }}?contact_id={{ $contact->id }}" target="_blank" rel="noopener"
               class="{{ $__item }}" style="{{ $__itemSt }}" {!! $__hover !!}
               title="Create a new property linked to this contact — everything on one page">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                <span>Create Listing <span class="block text-xs" style="color:var(--text-muted);">Classic form · one page</span></span>
            </a>
            @endif

            {{-- Birthday reminder (opt-in, only when a DOB is on file) --}}
            @if($contact->birthday)
            <form method="POST" action="{{ route('corex.contacts.birthday-reminder.toggle', $contact) }}" style="border-top:1px solid var(--border);">
                @csrf
                <button type="submit" class="{{ $__item }}" style="{{ $__itemSt }}" {!! $__hover !!}
                        title="{{ $contact->birthday_reminder ? 'Stop reminding me about this birthday' : 'Remind me about this birthday' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="{{ $contact->birthday_reminder ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
                    {{ $contact->birthday_reminder ? 'Birthday reminder on' : 'Remind me of birthday' }}
                </button>
            </form>
            @endif
        </div>
    </div>

    {{-- The Send-FICA pop-up lives OUTSIDE the dropdown: a closed menu is display:none and would hide a fixed pop-up inside it. --}}
    @feature('compliance')
    @if(auth()->user()->hasPermission('compliance.fica.send') && in_array($contact->ficaQuickState()['action'], ['send', 'renew'], true))
        @php $__modal = $__ficaModal; @endphp
        @include('corex.contacts._fica-send-modal')
    @endif
    @endfeature

    {{-- Delete button --}}
    @if(auth()->user()->hasPermission('contacts.delete'))
    <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
          onsubmit="return confirm('Permanently delete {{ addslashes($contact->full_name) }}?');"
          class="flex-shrink-0">
        @csrf @method('DELETE')
        <button type="submit" class="corex-btn-outline text-xs"
                style="color: var(--ds-crimson); border-color: color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
            Delete Contact
        </button>
    </form>
    @endif
</div>
