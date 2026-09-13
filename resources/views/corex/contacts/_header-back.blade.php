{{-- Contact header — the "Back to Contacts" link.

     Its own partial so the header can place it above the name (breadcrumb
     style, AT-393) without two copies of the markup drifting apart. A quiet
     text link rather than a button: it is wayfinding, not an action. --}}
<a href="{{ route('corex.contacts.index') }}"
   class="text-xs font-medium no-underline hover:underline inline-flex items-center gap-1.5 whitespace-nowrap"
   style="color: var(--text-muted);">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
    Back to Contacts
</a>
