{{-- Shared validation-error banner for the Contacts create/edit forms.
     Lists every error (not just $errors->first()), auto-scrolls to itself,
     and focuses #id_number specifically when that's what failed — the ID
     Number field's own inline @error message carries the rest of the
     detail. Used by both corex/contacts/index.blade.php (quick-create) and
     corex/contacts/show.blade.php (edit). --}}
@if($errors->any())
    <div id="contact-form-errors" class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--ds-crimson) 14%, transparent); border:2px solid var(--ds-crimson); color: var(--text-primary); box-shadow: 0 2px 10px color-mix(in srgb, var(--ds-crimson) 25%, transparent);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="var(--ds-crimson)"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
        <div class="flex-1">
            <strong>Couldn't save — please fix the following:</strong>
            <ul class="list-disc list-inside mt-1 space-y-0.5">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const el = document.getElementById('contact-form-errors');
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            @if($errors->has('id_number'))
                document.getElementById('id_number')?.focus({ preventScroll: true });
            @endif
        });
    </script>
@endif
