{{-- FICA entry INSIDE the contact's Quick Links dropdown (Johan 2026-10-12: "move the send FICA form into the quick links").
     Offers whichever action fits the FICA's state: Send / Send new FICA form (opens the pop-up), or Resend. Nothing when
     there is nothing to do (under review, approved) or the user may not send. The pop-up itself is rendered OUTSIDE the
     dropdown (see _header-actions) because the closed menu is display:none and would hide a fixed pop-up inside it.
     Params: $contact, $__item/$__itemSt/$__hover (menu row styling from _header-actions), $__ficaModal (pop-up name). --}}
@php
    $__fq = $contact->ficaQuickState();
    $__fqCanSend = auth()->user()?->hasPermission('compliance.fica.send');
@endphp
@feature('compliance')
@if($__fqCanSend)
    @if(in_array($__fq['action'], ['send', 'renew'], true))
    <button type="button" class="{{ $__item }}" style="{{ $__itemSt }} border-top:1px solid var(--border);" {!! $__hover !!} data-fica-send
            @click="open = false; $dispatch('open-modal', '{{ $__ficaModal }}')"
            title="Email {{ $contact->full_name }} a secure link to fill in their FICA form">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
        {{ $__fq['action'] === 'renew' ? 'Send new FICA form' : 'Send FICA form' }}
    </button>
    @elseif($__fq['action'] === 'resend' && $__fq['submission_id'])
    <form method="POST" action="{{ route('compliance.fica.resend', $__fq['submission_id']) }}" style="border-top:1px solid var(--border);">
        @csrf
        <input type="hidden" name="return_to" value="contact">
        <button type="submit" class="{{ $__item }}" style="{{ $__itemSt }}" {!! $__hover !!} data-fica-resend
                title="Email the client a fresh FICA link">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>
            Resend FICA form
        </button>
    </form>
    @endif
@endif
@endfeature
