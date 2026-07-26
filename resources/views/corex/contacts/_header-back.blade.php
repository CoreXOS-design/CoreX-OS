{{-- Contact header — the "Back to Contacts" link.

     Its own partial so the header can pin it to the LEFT of the split toolbar
     while the rest of the action cluster right-aligns, without two copies of
     the markup drifting apart. --}}
<a href="{{ route('corex.contacts.index') }}"
   class="corex-btn-outline text-xs no-underline inline-flex items-center gap-1.5 whitespace-nowrap">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
    Back to Contacts
</a>
