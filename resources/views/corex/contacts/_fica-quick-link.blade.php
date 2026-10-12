{{-- FICA Quick Link — where this contact's FICA stands (the pill), and — when $showActions — the one action that moves it forward.
     Spec: .ai/specs/quick-links.md §4. Rules live in App\Support\Compliance\FicaQuickState; this only draws them.
     Shown only when the Compliance feature is on. Send/Resend only render for people holding compliance.fica.send.
     Params: $contact (required) · $ficaQuick (optional pre-computed state)
             $showActions (default true) — false on the contact header: there the ACTION lives in the Quick Links menu
                           (_fica-quick-menu-item) and only the pill stays in the header.
             $modalKey (optional suffix so two copies on one page never share a pop-up name) --}}
@php
    $__q = $ficaQuick ?? $contact->ficaQuickState();
    $__modal = 'fica-send-' . $contact->id . ($modalKey ?? '');
    $__canSend = auth()->user()?->hasPermission('compliance.fica.send');
    $__actions = ($showActions ?? true) && $__canSend;
@endphp
@feature('compliance')
<x-quick-link :tone="$__q['tone']" :label="$__q['label']" :title="$__q['detail']" data-fica-quick-link="{{ $__q['key'] }}">
    @if($__actions)
        @if(in_array($__q['action'], ['send', 'renew'], true))
            <button type="button" class="corex-btn-outline text-xs" data-fica-send
                    @click="$dispatch('open-modal', '{{ $__modal }}')">
                {{ $__q['action'] === 'renew' ? 'Send new FICA form' : 'Send FICA form' }}
            </button>
        @elseif($__q['action'] === 'resend' && $__q['submission_id'])
            <form method="POST" action="{{ route('compliance.fica.resend', $__q['submission_id']) }}" class="inline">
                @csrf
                <input type="hidden" name="return_to" value="contact">
                <button type="submit" class="corex-btn-outline text-xs" data-fica-resend>Resend</button>
            </form>
        @endif
    @endif
</x-quick-link>

@if($__actions && in_array($__q['action'], ['send', 'renew'], true))
    @include('corex.contacts._fica-send-modal')
@endif
@endfeature
