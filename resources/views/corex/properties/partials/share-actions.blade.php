{{--
    Listing share actions — click Share, choose whose contact details the link
    carries (my details / listing agent's details — the same chooser as the
    Live Preview button, extracted from syndication-panel.blade.php rather than
    redesigned), then copy / WhatsApp / email the resulting attributed link.

    The attributed link points at corex.properties.preview (?agent=<id> or
    ?agent=listing) — the SAME public, shareable, CoreX-hosted preview page
    Live Preview already uses. This is a change from the previous version of
    this file, which shared Property::public_url (the external company-website
    URL) with no attribution at all — see the 2026-08-24 audit for why.

    Gated by the properties.share permission and a publicly-shareable status
    so drafts/withdrawn listings are never shared. Spec: .ai/specs/listing-share-link.md

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
@php
    // No title slug — {slug?} is unread by the controller and only bloats the
    // copy-shareable URL. Same base the Live Preview chooser uses.
    $shareSynPreviewBase = route('corex.properties.preview', $property);
@endphp
<div x-data="corexPropertyShareChooser({
        previewUrl: @js($shareSynPreviewBase),
        myId: @js(auth()->id()),
        isAssistant: @js((bool) $shareUser->is_assistant),
        shareText: @js('Check out this listing: ' . ($property->title ?: 'this property')),
        shareSubject: @js($property->title ?: 'Property listing'),
     })"
     class="relative">
    {{-- 2026-08-24 (Johan) — Buyers Pipeline wraps each wishlist's matches in an
         overflow-y:auto / max-height:600px scroll container (detail.blade.php) so the
         accordion doesn't grow unbounded. A plain position:absolute dropdown gets
         visually clipped by that ancestor's overflow regardless of z-index — the menu
         opened but only the first option was reachable ("cuts off... can only test
         with my details"). The Core Matches results page has no such wrapper, which
         is why the same partial looked fine there. Fixed with x-teleport="body" (the
         same escape-the-clipping-ancestor pattern already used elsewhere in this
         codebase — docuperfect esign wizard, dr2 pipeline tiles) plus a computed
         position:fixed anchored to the button's own bounding rect, since a teleported
         node loses its positioned ancestor and position:absolute would otherwise
         anchor to the whole page instead of the button. The extra $refs.shareBtn
         guard on @click.outside avoids the classic teleport gotcha: without it, a
         click on the button itself fires the BUTTON's own toggle-open handler, then
         bubbles to document where the now-separate (no longer a DOM descendant)
         teleported menu's own @click.outside immediately closes what the button just
         opened, in the same click. --}}
    <div x-data="{ open: false, menuTop: 0, menuLeft: 0,
            openMenu() {
                if (!this.open) {
                    const r = this.$refs.shareBtn.getBoundingClientRect();
                    this.menuTop = r.bottom + 4;
                    {{-- 2026-08-24 (Johan, follow-up) — clamp BOTH edges, not just the
                         right one. A trigger button close to the left edge of a narrow
                         panel (e.g. the Properties detail sidebar's Actions column) made
                         r.right - 224 go negative, rendering the menu off-screen to the
                         left with no lower bound to stop it. Same clamp shape already
                         used in this codebase for an anchored popover (docuperfect
                         importer/review.blade.php's selection toolbar). --}}
                    this.menuLeft = Math.max(8, Math.min(r.right - 224, window.innerWidth - 232));
                }
                this.open = !this.open;
            } }"
         @keydown.escape.window="open = false" class="relative">
        <button type="button" x-ref="shareBtn" @click="openMenu()"
                class="prop-action-btn prop-action-btn-neutral"
                title="Share a link to this listing">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
            Share
        </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak x-transition.opacity
             @click.outside="(!$refs.shareBtn || !$refs.shareBtn.contains($event.target)) && (open = false, back())"
             {{-- 2026-08-24 (Johan, regression fix) — a single :style STRING binding here
                  previously coexisted with a separate static `style=` attribute for the
                  panel's own background/border/shadow. Alpine's string-form :style REPLACES
                  the element's inline style wholesale at runtime rather than merging with
                  it (object-form :style merges; a template-literal string does not) — so
                  the static style was silently discarded the instant Alpine ran, leaving a
                  transparent panel with correct position but no paint. Folded into ONE
                  binding so there is nothing left to conflict. --}}
             :style="{ position: 'fixed', top: menuTop + 'px', left: menuLeft + 'px', background: 'var(--surface)', border: '1px solid var(--border)', boxShadow: '0 8px 30px rgba(0,0,0,0.18)' }"
             class="z-50 w-56 rounded-md py-1">

            {{-- Step 1: whose details — skipped entirely for an assistant (AT-267: an
                 assistant may only ever share with the listing agent's info, same rule
                 as Live Preview). --}}
            <template x-if="step === 'choose'">
                <div class="px-2 py-1">
                    <p class="px-1 py-1 text-[0.6875rem] font-semibold" style="color:var(--text-muted);">Show contact info for:</p>
                    <button type="button" @click="chooseMine()"
                            class="w-full flex items-center gap-2 px-2 py-2 text-xs text-left rounded transition-colors"
                            style="color:var(--text-secondary);background:transparent;"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                        My details
                    </button>
                    <button type="button" @click="chooseListing()"
                            class="w-full flex items-center gap-2 px-2 py-2 text-xs text-left rounded transition-colors"
                            style="color:var(--text-secondary);background:transparent;"
                            onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                        Listing agent's details
                    </button>
                </div>
            </template>

            {{-- Step 2: copy / WhatsApp / email the now-attributed link. --}}
            <template x-if="step === 'share'">
                <div>
                    <button type="button" x-show="!isAssistant" @click="back()"
                            class="w-full flex items-center gap-1.5 px-3 py-1.5 text-[0.6875rem] text-left transition-colors"
                            style="color:var(--text-muted);background:transparent;">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                        Back
                    </button>

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

                    <div x-show="copyError" x-cloak class="px-3 py-1 text-[11px]" style="color:var(--ds-crimson);" x-text="copyError"></div>
                </div>
            </template>
        </div>
    </template>
    </div>
</div>
@endif
