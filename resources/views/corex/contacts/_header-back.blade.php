{{-- Contact header — the "Back to Contacts" link.

     Its own partial so the header can place it at the LEFT of the identity
     row without two copies of the markup drifting apart. Icon-only since
     AT-393 so the row stays one line; the label lives in the tooltip and a
     screen-reader span, so it is still "Back to Contacts" to everyone. --}}
<a href="{{ route('corex.contacts.index') }}"
   class="corex-btn-outline text-xs no-underline inline-flex items-center flex-shrink-0"
   style="padding-left:0.5rem; padding-right:0.5rem;"
   title="Back to Contacts" aria-label="Back to Contacts">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
    <span class="sr-only">Back to Contacts</span>
</a>
