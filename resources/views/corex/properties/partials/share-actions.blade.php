{{--
    Listing share actions — copy / WhatsApp / email a public link to this listing.
    Reuses Property::public_url (the canonical public listing URL); no new public
    renderer. Gated by the properties.share permission and a publicly-shareable
    status so drafts/withdrawn listings are never shared.
    Spec: .ai/specs/listing-share-link.md

    Expects: $property
--}}
@php
    $shareUser = auth()->user();
    $shareableStatuses = ['active', 'newlisting', 'new_listing', 'new listing', 'reduced'];
    $canShare = $shareUser
        && $shareUser->hasPermission('properties.share')
        && in_array(strtolower((string) ($property->status ?? '')), $shareableStatuses, true);
@endphp

@if($canShare)
<div x-data="{
        open: false,
        copied: false,
        url: @js($property->public_url),
        text: @js('Check out this listing: ' . ($property->title ?: 'this property')),
        subject: @js($property->title ?: 'Property listing'),
        wa() { return 'https://wa.me/?text=' + encodeURIComponent(this.text + ' ' + this.url); },
        mail() { return 'mailto:?subject=' + encodeURIComponent(this.subject) + '&body=' + encodeURIComponent(this.text + ' ' + this.url); },
        copy() {
            navigator.clipboard.writeText(this.url).then(() => {
                this.copied = true;
                setTimeout(() => this.copied = false, 1500);
            });
        }
     }"
     class="relative">
    <button type="button" @click="open = !open"
            class="prop-action-btn prop-action-btn-neutral"
            title="Share a public link to this listing">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
        Share
    </button>

    <div x-show="open" x-cloak @click.outside="open = false" @keydown.escape.window="open = false"
         x-transition.opacity
         class="absolute right-0 mt-1 z-50 w-44 rounded-md py-1"
         style="background:var(--surface);border:1px solid var(--border);box-shadow:0 8px 30px rgba(0,0,0,0.18);">

        <button type="button" @click="copy()"
                class="w-full flex items-center gap-2 px-3 py-2 text-xs text-left transition-colors"
                style="color:var(--text-secondary);background:transparent;"
                onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
            <span x-text="copied ? 'Copied!' : 'Copy link'"></span>
        </button>

        <a :href="wa()" target="_blank" rel="noopener"
           class="w-full flex items-center gap-2 px-3 py-2 text-xs no-underline transition-colors"
           style="color:var(--text-secondary);"
           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
            WhatsApp
        </a>

        <a :href="mail()"
           class="w-full flex items-center gap-2 px-3 py-2 text-xs no-underline transition-colors"
           style="color:var(--text-secondary);"
           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
            Email
        </a>
    </div>
</div>
@endif
