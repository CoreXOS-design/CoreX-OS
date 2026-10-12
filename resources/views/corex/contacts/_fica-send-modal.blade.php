{{-- The "Send FICA form" pop-up. Opened by `open-modal` with the name in $__modal; rendered only for someone who may send.
     Params: $contact, $__modal (pop-up name). Posts the existing compliance.fica.store and comes back to the contact. --}}
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
