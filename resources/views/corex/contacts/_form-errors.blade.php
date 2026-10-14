{{-- Shared validation-error banner for the Contacts create/edit forms.
     Lists every error (not just $errors->first()), scrolls to the first field that
     failed (the ID Number field carries its own inline @error message) and
     focuses it, falling back to the banner. Used by both corex/contacts/index.blade.php (quick-create) and
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
        // Take the person to the FIELD that failed, not just the banner: the banner sits at the top while a field can be a
        // screen or more below, and some fields (the ID number) are drawn by Alpine a moment after the page loads - so look
        // for the first errored field for up to ~1.5s. No field found = the banner itself is scrolled into view.
        document.addEventListener('DOMContentLoaded', () => {
            const keys = @json($errors->keys());
            const findField = () => {
                for (const key of keys) {
                    const el = document.getElementById(key) || document.querySelector('[name="' + key + '"]');
                    if (el && el.offsetParent !== null) return el;
                }
                return null;
            };
            let tries = 0;
            const go = () => {
                const field = findField();
                if (field) {
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    field.focus({ preventScroll: true });
                } else if (++tries < 15) {
                    setTimeout(go, 100);
                } else {
                    document.getElementById('contact-form-errors')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            };
            go();
        });
    </script>
@endif
