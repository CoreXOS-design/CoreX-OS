{{-- ════════════════════════════════════════════════════════════════════════
     Contact header — ACTION CLUSTER (right-hand side of the header toolbar).

     AT-336 restyle. Extracted verbatim from show.blade.php — every route,
     permission gate, form, Alpine binding and confirm() below is the original,
     unchanged. "Back to Contacts" is NOT here: the header pins it to the left
     of the toolbar via _header-back.

     Params:
       $contact    (Contact, required)
       $wrapClass  (string, optional) — classes on the cluster wrapper.
     ════════════════════════════════════════════════════════════════════════ --}}
@php $__wrap = $wrapClass ?? 'flex flex-wrap items-center gap-2 flex-shrink-0'; @endphp
<div class="{{ $__wrap }}">
    @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
    {{-- Schedule Event from Contact --}}
    <a href="{{ route('command-center.calendar', ['view' => 'day', 'prefill_contact_id' => $contact->id, 'prefill_class' => $contact->is_buyer ? 'viewing' : 'meeting']) }}"
       target="_blank" rel="noopener"
       class="corex-btn-primary text-xs flex-shrink-0 no-underline"
       title="Opens the calendar in a new tab so you stay on this contact">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
        Schedule Event
    </a>

    {{-- Birthday reminder (opt-in, only when a DOB is on file) --}}
    @if($contact->birthday)
    <form method="POST" action="{{ route('corex.contacts.birthday-reminder.toggle', $contact) }}" class="flex-shrink-0">
        @csrf
        @if($contact->birthday_reminder)
        <button type="submit" class="corex-btn-outline text-xs no-underline" title="Stop reminding me about this birthday">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M11.983 1.907a.75.75 0 0 0-1.966 0l-.16.661a8.25 8.25 0 0 0-6.357 8.027v3.243a3 3 0 0 1-.879 2.121l-.886.886A.75.75 0 0 0 2.5 18.75h19a.75.75 0 0 0 .53-1.28l-.886-.886a3 3 0 0 1-.879-2.122v-3.242a8.25 8.25 0 0 0-6.357-8.027l-.16-.661ZM12 22.5a3 3 0 0 1-2.83-2h5.66A3 3 0 0 1 12 22.5Z"/></svg>
            Birthday reminder on
        </button>
        @else
        <button type="submit" class="corex-btn-outline text-xs no-underline" title="Remind me about this birthday">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
            Remind me of birthday
        </button>
        @endif
    </form>
    @endif

    {{-- View as Buyer (if buyer) --}}
    @if($contact->is_buyer)
    <a href="{{ route('command-center.buyers.show', $contact) }}"
       class="corex-btn-outline text-xs flex-shrink-0 no-underline">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
        Buyer Hub
    </a>
    @endif

    {{-- Create Listing from Contact (only if no linked properties).
         Agent chooses the Classic form (single-page) or the guided
         Upload Wizard — both pre-fill the contact's address and link
         the contact as the seller/landlord on save. --}}
    @if(auth()->user()->hasPermission('access_properties') && $contact->properties()->count() === 0)
    <div class="relative flex-shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false">
        <button type="button" @click="open = !open"
                class="corex-btn-outline text-xs no-underline"
                title="Create a new property linked to this contact">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Create Listing
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 ml-0.5" :class="open ? 'rotate-180' : ''" style="transition:transform .2s;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div x-show="open" x-transition @click.outside="open = false"
             class="absolute right-0 mt-1 w-60 rounded-md overflow-hidden z-30 shadow-lg"
             style="background:var(--surface); border:1px solid var(--border);" x-cloak>
            <a href="{{ route('corex.properties.wizard') }}?contact_id={{ $contact->id }}"
               target="_blank" rel="noopener"
               class="flex items-start gap-2.5 px-3 py-2.5 no-underline transition-colors"
               style="color:var(--text-primary);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--brand-icon);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <span>
                    <span class="block text-sm font-semibold">Upload Wizard</span>
                    <span class="block text-xs" style="color:var(--text-muted);">Guided, 4 quick steps</span>
                </span>
            </a>
            <a href="{{ route('corex.properties.create') }}?contact_id={{ $contact->id }}"
               target="_blank" rel="noopener"
               class="flex items-start gap-2.5 px-3 py-2.5 no-underline transition-colors"
               style="color:var(--text-primary); border-top:1px solid var(--border);" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m11.25 5.5H18a2.25 2.25 0 0 1-2.25-2.25v-2.25"/></svg>
                <span>
                    <span class="block text-sm font-semibold">Classic Form</span>
                    <span class="block text-xs" style="color:var(--text-muted);">Everything on one page</span>
                </span>
            </a>
        </div>
    </div>
    @endif

    {{-- Delete button --}}
    @if(auth()->user()->hasPermission('contacts.delete'))
    <form method="POST" action="{{ route('corex.contacts.destroy', $contact) }}"
          onsubmit="return confirm('Permanently delete {{ addslashes($contact->full_name) }}?');"
          class="flex-shrink-0">
        @csrf @method('DELETE')
        <button type="submit" class="corex-btn-outline text-xs"
                style="color: var(--ds-crimson); border-color: color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
            Delete Contact
        </button>
    </form>
    @endif
</div>
