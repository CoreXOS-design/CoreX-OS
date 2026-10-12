{{-- FICA Quick Link — where this contact's FICA stands, and the one action that moves it forward.
     Spec: .ai/specs/quick-links.md §4 (Wave 1). Rules live in App\Support\Compliance\FicaQuickState; this only draws them.
     Shown only when the Compliance feature is on. The Send/Resend buttons only render for people holding
     compliance.fica.send (the key the store/resend routes enforce) — the chip itself is visible to anyone who can see the contact.
     Params: $contact (required) · $ficaQuick (optional pre-computed state) · $modalKey (optional suffix so two copies on one page
     — header + FICA tab — never share a pop-up name) --}}
@php
    $__q = $ficaQuick ?? $contact->ficaQuickState();
    $__modal = 'fica-send-' . $contact->id . ($modalKey ?? '');
    $__canSend = auth()->user()?->hasPermission('compliance.fica.send');
@endphp
@feature('compliance')
<x-quick-link :tone="$__q['tone']" :label="$__q['label']" :title="$__q['detail']" data-fica-quick-link="{{ $__q['key'] }}">
    @if($__canSend)
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

@if($__canSend && in_array($__q['action'], ['send', 'renew'], true))
<x-modal :name="$__modal" max-width="md">
    <form method="POST" action="{{ route('compliance.fica.store') }}" class="p-6 space-y-4">
        @csrf
        <input type="hidden" name="contact_id" value="{{ $contact->id }}">
        <input type="hidden" name="return_to" value="contact">
        <h3 class="text-base font-bold" style="color:var(--text-primary);">Send FICA form to {{ $contact->full_name }}</h3>
        <p class="text-sm" style="color:var(--text-secondary);">
            They get a secure email link to fill in and sign their FICA form. You stay on this page and can follow its progress here.
        </p>
        @if($contact->email)
            <div class="text-sm" style="color:var(--text-primary);">Sending to <strong>{{ $contact->email }}</strong></div>
        @else
            <div>
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">This contact has no email address — add one to send the form</label>
                <input type="email" name="email" required placeholder="name@example.com"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <p class="text-xs mt-1" style="color:var(--text-muted);">It is saved on the contact as their email.</p>
            </div>
        @endif
        <div class="flex justify-end gap-2">
            <button type="button" class="corex-btn-outline text-xs" @click="$dispatch('close-modal', '{{ $__modal }}')">Cancel</button>
            <button type="submit" class="corex-btn-primary text-xs" data-fica-send-confirm>Send FICA form</button>
        </div>
    </form>
</x-modal>
@endif
@endfeature
