{{-- FICA action as a header BUTTON (the Buyer Hub's button row). Shows whichever action fits where the FICA stands and
     HIDES itself when there is nothing to do: approved / on file (done) or already under review render nothing at all.
       none / rejected  -> "Send FICA form"        (opens the pop-up)
       expiring         -> "Send new FICA form"    (opens the pop-up)
       awaiting / expired link / corrections -> "Resend FICA form"
     Only for people holding compliance.fica.send, only when the Compliance feature is on. Spec: quick-links.md §4.
     Params: $contact (required) · $modalKey (optional pop-up name suffix) --}}
@php
    $__fb = $contact->ficaQuickState();
    $__fbModal = 'fica-send-' . $contact->id . ($modalKey ?? '-btn');
    $__fbCan = auth()->user()?->hasPermission('compliance.fica.send');
@endphp
@feature('compliance')
@if($__fbCan && $__fb['action'])
    @if(in_array($__fb['action'], ['send', 'renew'], true))
        <button type="button" class="corex-btn-outline text-xs flex-shrink-0" data-fica-send
                @click="$dispatch('open-modal', '{{ $__fbModal }}')"
                title="Email {{ $contact->full_name }} a secure link to fill in their FICA form">
            {{ $__fb['action'] === 'renew' ? 'Send new FICA form' : 'Send FICA form' }}
        </button>
        @php $__modal = $__fbModal; @endphp
        @include('corex.contacts._fica-send-modal')
    @elseif($__fb['action'] === 'resend' && $__fb['submission_id'])
        <form method="POST" action="{{ route('compliance.fica.resend', $__fb['submission_id']) }}" class="inline flex-shrink-0">
            @csrf
            <input type="hidden" name="return_to" value="contact">
            <button type="submit" class="corex-btn-outline text-xs" data-fica-resend title="Email the client a fresh FICA link">Resend FICA form</button>
        </form>
    @endif
@endif
@endfeature
