{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Root-cause fix (2026-08-19, per Johan/conductor diagnosis) — the inline
     @php(...) form cannot parse an expression this shape (nested parens
     wrapping an array literal with => pairs and empty [] elements). Blade's
     directive-argument tokenizer mis-terminates it, and everything in the
     file from that point on is emitted as literal, uncompiled Blade source.
     This is why the page rendered raw @if/{{ }}/@php text, why clearing the
     view cache never helped (the SOURCE never compiled correctly to begin
     with), and why it kept coming back — the defensive default kept
     reintroducing this exact shape. Rule: never use inline @php(...) with an
     array literal or nested parentheses — always a full @php ... @endphp
     block. --}}
@php
    $deedLink = $deedLink ?? [];
    $deedLink = $deedLink + ['owners' => [], 'candidates' => [], 'tracked_property_id' => null];
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Compose pitch about this property</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Capture the seller's contact info first. We'll dedupe against existing contacts before creating a new one.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ url()->previous() }}" class="corex-btn-outline text-xs no-underline">
                    ← Back
                </a>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            @foreach($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    {{-- Blocking duplicate panel — shown when duplicateGate() found an existing
         contact at add time (parity with the Contacts screen / DR2 party-picker).
         The action URL matches the source: tracked-property or prospecting listing. --}}
    @include('seller-outreach.entry._duplicate-modal', [
        'actionUrl' => !empty($trackedProperty)
            ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
            : (!empty($property)
                ? route('seller-outreach.entry.store-from-property', $property->id)
                : route('seller-outreach.entry.store-from-prospecting', $listing->id)),
    ])

    {{-- Source summary — listing OR tracked property. Map Workspace Phase B
         extends the view to render either context; the form below posts to
         the matching store route. --}}
    @if(!empty($trackedProperty))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Tracked Property
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $trackedProperty->displayAddress() }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($trackedProperty->last_known_asking_price))R {{ number_format((float) $trackedProperty->last_known_asking_price, 0, '.', ',') }} · @endif
                {{ $trackedProperty->property_type ?? 'property' }}
                @if(!empty($trackedProperty->bedrooms)) · {{ $trackedProperty->bedrooms }} beds @endif
                @if(!empty($trackedProperty->bathrooms)) · {{ $trackedProperty->bathrooms }} baths @endif
                @if(!empty($trackedProperty->erf_number)) · Erf {{ $trackedProperty->erf_number }} @endif
            </div>
        </div>
    @elseif(!empty($property))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Property — in agency stock
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $property->address ?: $property->title ?: 'Property #' . $property->id }}{{ !empty($property->suburb) ? ', ' . $property->suburb : '' }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($property->price))R {{ number_format((float) $property->price, 0, '.', ',') }} · @endif
                {{ $property->property_type ?? 'property' }}
                @if(!empty($property->beds)) · {{ $property->beds }} beds @endif
                @if(!empty($property->baths)) · {{ $property->baths }} baths @endif
            </div>
            <div class="text-xs mt-2" style="color: var(--text-muted);">
                No seller contact is linked yet — capture the seller below to pitch.
            </div>
        </div>
    @elseif(!empty($listing))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                Listing from {{ strtoupper((string) ($listing->portal_source ?? 'portal')) }}
            </div>
            <div class="font-semibold text-sm" style="color: var(--text-primary);">
                {{ $listing->address ?? '(no address)' }}{{ !empty($listing->suburb) ? ', ' . $listing->suburb : '' }}
            </div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                @if(!empty($listing->price))R {{ number_format((float) $listing->price, 0, '.', ',') }} · @endif
                {{ $listing->property_type ?? 'property' }}
                @if(!empty($listing->bedrooms)) · {{ $listing->bedrooms }} beds @endif
                @if(!empty($listing->bathrooms)) · {{ $listing->bathrooms }} baths @endif
            </div>
        </div>
    @endif

    {{-- Contact form — SEARCH & link an existing contact, OR capture a new one.
         Both modes post to the store route matching the source; the controller
         branches on contact_id. --}}
    @php
        // Defensive view-boundary default (2026-08-19) — the controller now builds and
        // passes $composeConfig explicitly (EntryPointController::fromProspecting()). This
        // is a FALLBACK only, so a different entry point rendering this same template
        // (fromProperty / fromTrackedProperty) — or any future regression that forgets to
        // pass it — degrades to a working page built from whatever view variables ARE
        // present, never an undefined-variable error screen on Johan's critical path.
        $composeConfig ??= [
            'contactKind' => old('contact_kind', 'natural_person'),
            'idKind' => old('id_type', 'sa_id'),
            'searchUrl' => route('corex.properties.contacts.search-global'),
            'deeds' => $deeds ?? [],
            'linkDeedUrl' => $linkDeedUrl ?? null,
            'deedOwners' => $deedLink['owners'] ?? [],
            'deedCandidates' => $deedLink['candidates'] ?? [],
            'deedPollUrl' => $deedPollUrl ?? null,
            'sellers' => $sellerState['sellers'] ?? [],
            'tva' => $sellerState['tva'] ?? (object) [],
            'propertyId' => $sellerState['property_id'] ?? null,
            'linkSellerUrl' => $linkSellerUrl ?? null,
            'unlinkSellerUrl' => $unlinkSellerUrl ?? null,
            'linkSellersBatchUrl' => $linkSellersBatchUrl ?? null,
            'tvaIngestUrl' => $tvaIngestUrl ?? null,
            'primarySellerUrl' => $primarySellerUrl ?? null,
            'deadEndSellerUrl' => $deadEndSellerUrl ?? null,
            'unlinkDeedUrl' => $unlinkDeedUrl ?? null,
            'removeNumberUrl' => $removeNumberUrl ?? null,
            'primaryNumberUrl' => $primaryNumberUrl ?? null,
            'linkedDeed' => $sellerState['linked_deed'] ?? null,
            'removed' => $sellerState['removed'] ?? [],
            'contactTyped' => (trim((string) old('phone', '')) !== '' || trim((string) old('email', '')) !== ''),
        ];
    @endphp
    {{-- P0 fix (2026-08-19) — the entire Alpine state object used to live inline in this
         x-data attribute. Quoting Johan verbatim in a JS comment inside it put a literal "
         character in a double-quoted HTML attribute, which the BROWSER's HTML parser (not
         Blade) terminated the attribute on — everything after rendered as visible body text.
         A byte-length check on the compiled Blade output can never catch this: the string is
         intact in the PHP/HTML output: the truncation happens only when a browser parses it.
         Fix, per ruling: the object literal is now defined ONCE in a real <script> block
         (bottom of this file) as composeSeller(config); this attribute passes only a small,
         comment-free config object via Js::from(), which is safe in both JS-string and
         HTML-attribute context. No JavaScript object literal may live inside a Blade
         attribute again — this is the third screen this has taken down. --}}
    <form method="POST"
          x-data="composeSeller({{ Js::from($composeConfig) }})"
          x-init="startDeedPoll()"
          action="{{ !empty($trackedProperty)
              ? route('seller-outreach.entry.store-from-tracked-property', $trackedProperty->id)
              : (!empty($property)
                  ? route('seller-outreach.entry.store-from-property', $property->id)
                  : route('seller-outreach.entry.store-from-prospecting', $listing->id)) }}">
        @csrf

        {{-- R1 — explicit LINKED-DEED state + unlink. When a deed is selected the sellers panel +
             property address follow it; ✕ Unlink reverts to auto-match (confirm-on-click). --}}
        <div x-show="linkedDeed" x-cloak class="rounded-md p-3 mb-4 flex items-center justify-between gap-3 flex-wrap"
             style="background: color-mix(in srgb, #10b981 10%, var(--surface)); border:1px solid color-mix(in srgb, #10b981 45%, var(--border));">
            <div class="min-w-0">
                <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded" style="background:#10b981; color:#fff;">Deed linked ✓</span>
                <span class="text-xs ml-1" style="color: var(--text-secondary);" x-text="linkedDeed && linkedDeed.address"></span>
                <span class="text-[11px] block mt-0.5" style="color: var(--text-muted);">Sellers + property address follow this deed.</span>
            </div>
            <button type="button" @click="unlinkDeed()" :disabled="sellerBusy"
                    class="shrink-0 text-xs font-semibold" style="color: var(--ds-crimson); background:none; border:0; cursor:pointer;">✕ Unlink deed</button>
        </div>

        {{-- ── Owners & Contact Numbers (merged 2026-08-19, Johan) ──
             Was two panels (a read-only "Owners & contact numbers" summary, and this "From the
             deed you scraped" list) plus a third ("Sellers on this property", unchanged, below).
             Johan: "not sure why we would want a third panel... essentially incorporating blue
             into grey, and keeping green." These are the owners CMA/the deed gave us for this
             property — always current data, so no past-owner handling belongs here (that's a
             different, dormant feature — see .ai/specs/deeds-capture.md §7). One header (title +
             Refresh + one status line), one row per owner carrying everything BOTH former panels
             showed: link state, ID + Copy ID, the ticks, and their current numbers/emails. The
             Refresh button re-pulls owners AND their numbers in one call — see refreshDeedMatch().
             Alpine-REACTIVE (driven by `deed` + `sellers`) so a scrape landing while the screen is
             open surfaces on its own; "View full deed" opens Deeds Capture. --}}
        <div x-show="deed.owners.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 40%, var(--border));">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-1">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                          style="background: var(--brand-icon, #0ea5e9); color:#fff;">Owners &amp; contact numbers</span>
                    <span class="text-xs" style="color: var(--text-muted);">Owner(s) already captured from the deeds record</span>
                </div>
                <div class="flex items-center gap-3">
                    @if(auth()->user()->hasPermission('deeds_capture.access'))
                        <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                           class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">View full deed →</a>
                    @endif
                    <button type="button" @click="refreshDeedMatch()" :disabled="deedRefreshing"
                            class="px-3 py-1.5 text-xs font-semibold rounded-md"
                            style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border); cursor:pointer;">
                        <span x-show="!deedRefreshing">&#8635; Refresh</span>
                        <span x-show="deedRefreshing">Refreshing&hellip;</span>
                    </button>
                </div>
            </div>
            {{-- ONE status line for the whole panel — replaces the two separate messages
                 (deedRefreshMessage and the old grey panel's own message) that used to sit in
                 two different panels and could read as disagreeing with each other. --}}
            <div class="text-xs mb-2" style="color: var(--text-primary); font-weight: 600;" x-text="deedRefreshMessage || ownersSummary()"></div>
            <div class="space-y-2">
                <template x-for="owner in deed.owners" :key="ownerKey(owner)">
                    <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                {{-- Feature 2 (2026-08-19) — SELECTION tick, leading position: include
                                     this owner in the ONE "Link ticked sellers" action below (replaces
                                     the old per-owner "+ Link as seller" button). Ticked by default:
                                     this owner comes from the deed already linked to THIS listing, the
                                     same trust level deeds-capture's own promote gives its current
                                     owners with no ticking at all.
                                     Johan (2026-08-19, "ticks arent confusing, its confusing when they
                                     are bunched together"): this tick and the "no TVA numbers" tick
                                     further down the row are BOTH checkboxes, kept unambiguous purely
                                     by distance and position — this one leads the row, the other sits
                                     beside the name, separated by a wider gap than either has from its
                                     own label. Visible 2px border + real accent colour, not a hairline. --}}
                                <template x-if="isLinkableOwner(owner)">
                                    <label class="flex items-center shrink-0" style="cursor:pointer; padding: 6px; margin: -6px 0 0 -6px;">
                                        <input type="checkbox" :checked="isOwnerTicked(owner, true)" @change="toggleOwnerTick(owner, true)"
                                               style="width:18px; height:18px; border:2px solid var(--text-secondary); border-radius:4px; accent-color: var(--brand-icon, #0ea5e9); cursor:pointer;">
                                    </label>
                                </template>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold flex items-center flex-wrap gap-x-4 gap-y-1" style="color: var(--text-primary);">
                                        <span class="inline-flex items-center min-w-0">
                                            <template x-if="owner.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold mr-1 px-1.5 py-0.5 rounded align-middle" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                            <span class="truncate" x-text="owner.display_name || (((owner.first_name || '') + ' ' + (owner.last_name || '')).trim()) || owner.name || '(unnamed owner)'"></span>
                                        </span>
                                        {{-- STATE tick, beside the name it describes (Johan, verbatim:
                                             "just put the contact no tva tick next to their name"). Does
                                             NOT gate the selection tick above — an owner can be both
                                             selected and flagged uncontactable; the seller still links
                                             either way (server-enforced too). Amber accent, distinct from
                                             the selection tick's brand-blue, reinforcing the two mean
                                             different things even before reading the label. --}}
                                        <template x-if="canMarkNoTva(owner)">
                                            <label class="inline-flex items-center gap-2 text-[11px] font-medium normal-case" style="cursor:pointer; color: var(--text-secondary); padding: 4px 8px 4px 4px; border-radius: 6px;">
                                                <input type="checkbox" :checked="isOwnerNoTvaTicked(owner)" @change="toggleOwnerNoTva(owner)" :disabled="sellerBusy"
                                                       style="width:16px; height:16px; border:2px solid var(--ds-amber, #f59e0b); border-radius:4px; accent-color: var(--ds-amber, #f59e0b); cursor:pointer;">
                                                <span>No TVA numbers found</span>
                                            </label>
                                        </template>
                                        <template x-if="owner.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="owner.dead_end && owner.dead_end.label"></span></span></template>
                                    </div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                        <template x-if="owner.is_entity"><span><span x-show="owner.entity_reg_no">Reg: <span class="font-mono" x-text="owner.entity_reg_no"></span> @include('corex._partials.copy-id-btn', ['value' => 'owner.entity_reg_no', 'label' => 'Copy reg'])</span><span x-show="!owner.entity_reg_no" class="italic">Company / entity owner</span></span></template>
                                        <template x-if="!owner.is_entity && owner.id_number"><span>ID: <span class="font-mono" x-text="owner.id_number"></span> @include('corex._partials.copy-id-btn', ['value' => 'owner.id_number', 'label' => 'Copy ID'])</span></template>
                                        <template x-if="!owner.is_entity && !owner.id_number"><span class="italic">No ID on the deed record</span></template>
                                    </div>
                                </div>
                            </div>
                            <div class="shrink-0 flex flex-col gap-1">
                                <template x-if="isSellerLinked(owner)">
                                    <span class="px-3 py-1.5 text-xs font-semibold rounded-md text-center"
                                          style="background: color-mix(in srgb, #10b981 18%, transparent); color: var(--text-primary);">✓ Seller linked</span>
                                </template>
                            </div>
                        </div>
                        {{-- Current numbers/emails, folded in from the former grey panel (2026-08-19
                             merge). Sourced via findLinkedSeller(owner) — empty until this owner is
                             actually linked, since numbers live on the linked Contact record, not on
                             the raw deed-owner row. Display only (tel:/wa.me/mailto: links) — removing
                             a number stays a "Sellers on this property" action below (unchanged, per
                             Johan: keep green as it is), so there is still only ONE place to remove a
                             number, not two. --}}
                        <template x-if="findLinkedSeller(owner) && (findLinkedSeller(owner).phones.length || findLinkedSeller(owner).emails.length)">
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <template x-for="p in (findLinkedSeller(owner) || {}).phones || []" :key="'p-' + p.value">
                                    <span class="inline-flex items-center gap-1">
                                        <a :href="'tel:' + p.value" class="text-xs" style="color: var(--brand-icon, #2563eb); text-decoration: none;" x-text="p.value"></a>
                                        <a :href="waUrl(p.value)" target="_blank" rel="noopener"
                                           class="text-[10px] font-semibold px-1.5 py-0.5 rounded" style="background: #10b981; color: #fff; text-decoration: none;">WA</a>
                                    </span>
                                </template>
                                <template x-for="e in (findLinkedSeller(owner) || {}).emails || []" :key="'e-' + e.value">
                                    <a :href="'mailto:' + e.value" class="text-xs" style="color: var(--brand-icon, #2563eb); text-decoration: none;" x-text="e.value"></a>
                                </template>
                            </div>
                        </template>
                        {{-- Numbers already scraped for this exact SA ID — tick which to carry along
                             when this owner is linked. Unticked by default, matching the deeds-capture
                             item_ids[] checkboxes exactly (same product, same shape). --}}
                        <template x-if="isLinkableOwner(owner) && tvaFor(owner.id_number)">
                            <div class="mt-2 ml-6 pt-2" style="border-top: 1px dashed var(--border);">
                                <div class="text-[11px] mb-1" style="color: var(--text-muted);">Numbers already found for this owner — tick to capture with the link:</div>
                                <template x-for="item in tvaFor(owner.id_number).items" :key="item.id">
                                    <label class="flex items-center gap-2 py-0.5 text-xs" style="cursor:pointer; color: var(--text-primary);">
                                        <input type="checkbox" :checked="isOwnerTvaTicked(owner.id_number, item.id)" @change="toggleOwnerTvaTick(owner.id_number, item.id)">
                                        <span x-text="item.type + ': ' + item.value"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Possible deed match (agent-verified) — reactive; shows when there's no confirmed owner
             but candidate deeds exist (P24 marketing address vs deeds-office scheme address). --}}
        <div x-show="!deed.owners.length && deed.candidates.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, var(--surface)); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-2">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded"
                          style="background: var(--ds-amber, #f59e0b); color:#111;">Possible deed match</span>
                    <span class="text-xs" style="color: var(--text-muted);">Same street &amp; suburb — verify this is the same property before using</span>
                </div>
                @if(auth()->user()->hasPermission('deeds_capture.access'))
                    <a href="{{ route('corex.deeds-capture.index') }}" target="_blank" rel="noopener"
                       class="text-xs font-semibold no-underline" style="color: var(--ds-amber, #f59e0b);">Open Deeds Capture →</a>
                @endif
            </div>
            <div class="space-y-2">
                <template x-for="cand in deed.candidates" :key="cand.tracked_property_id">
                    <div class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <template x-if="cand.address"><div class="text-xs font-semibold mb-1" style="color: var(--text-secondary);">Deed: <span x-text="cand.address"></span></div></template>
                        <template x-for="owner in cand.owners" :key="ownerKey(owner)">
                            <div class="py-1">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-start gap-3 min-w-0">
                                        {{-- SELECTION tick — unticked by default, this is an UNVERIFIED
                                             candidate match ("verify this is the same property before
                                             using" above), so ticking one is a deliberate act, never an
                                             assumed default. Same visible-border/hit-area/spacing
                                             treatment as the confirmed-deed block above. --}}
                                        <template x-if="isLinkableOwner(owner)">
                                            <label class="flex items-center shrink-0" style="cursor:pointer; padding: 6px; margin: -6px 0 0 -6px;">
                                                <input type="checkbox" :checked="isOwnerTicked(owner, false)" @change="toggleOwnerTick(owner, false)"
                                                       style="width:18px; height:18px; border:2px solid var(--text-secondary); border-radius:4px; accent-color: var(--brand-icon, #0ea5e9); cursor:pointer;">
                                            </label>
                                        </template>
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold flex items-center flex-wrap gap-x-4 gap-y-1" style="color: var(--text-primary);">
                                                <span class="inline-flex items-center min-w-0">
                                                    <template x-if="owner.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold mr-1 px-1.5 py-0.5 rounded align-middle" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                                    <span class="truncate" x-text="owner.display_name || (((owner.first_name || '') + ' ' + (owner.last_name || '')).trim()) || owner.name || '(unnamed owner)'"></span>
                                                </span>
                                                {{-- STATE tick, beside the name — same as the confirmed-deed
                                                     block above: does not gate the selection tick, seller
                                                     still links either way. --}}
                                                <template x-if="canMarkNoTva(owner)">
                                                    <label class="inline-flex items-center gap-2 text-[11px] font-medium normal-case" style="cursor:pointer; color: var(--text-secondary); padding: 4px 8px 4px 4px; border-radius: 6px;">
                                                        <input type="checkbox" :checked="isOwnerNoTvaTicked(owner)" @change="toggleOwnerNoTva(owner)" :disabled="sellerBusy"
                                                               style="width:16px; height:16px; border:2px solid var(--ds-amber, #f59e0b); border-radius:4px; accent-color: var(--ds-amber, #f59e0b); cursor:pointer;">
                                                        <span>No TVA numbers found</span>
                                                    </label>
                                                </template>
                                                <template x-if="owner.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="owner.dead_end && owner.dead_end.label"></span></span></template>
                                            </div>
                                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                                <template x-if="owner.is_entity"><span><span x-show="owner.entity_reg_no">Reg: <span class="font-mono" x-text="owner.entity_reg_no"></span> @include('corex._partials.copy-id-btn', ['value' => 'owner.entity_reg_no', 'label' => 'Copy reg'])</span><span x-show="!owner.entity_reg_no" class="italic">Company / entity owner</span></span></template>
                                                <template x-if="!owner.is_entity && owner.id_number"><span>ID: <span class="font-mono" x-text="owner.id_number"></span> @include('corex._partials.copy-id-btn', ['value' => 'owner.id_number', 'label' => 'Copy ID'])</span></template>
                                                <template x-if="!owner.is_entity && !owner.id_number"><span class="italic">No ID on the deed record</span></template>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="shrink-0 flex flex-col gap-1">
                                        <template x-if="isSellerLinked(owner)">
                                            <span class="px-3 py-1.5 text-xs font-semibold rounded-md text-center"
                                                  style="background: color-mix(in srgb, #10b981 18%, transparent); color: var(--text-primary);">✓ Seller linked</span>
                                        </template>
                                    </div>
                                </div>
                                <template x-if="isLinkableOwner(owner) && tvaFor(owner.id_number)">
                                    <div class="mt-2 ml-6 pt-2" style="border-top: 1px dashed var(--border);">
                                        <div class="text-[11px] mb-1" style="color: var(--text-muted);">Numbers already found for this owner — tick to capture with the link:</div>
                                        <template x-for="item in tvaFor(owner.id_number).items" :key="item.id">
                                            <label class="flex items-center gap-2 py-0.5 text-xs" style="cursor:pointer; color: var(--text-primary);">
                                                <input type="checkbox" :checked="isOwnerTvaTicked(owner.id_number, item.id)" @change="toggleOwnerTvaTick(owner.id_number, item.id)">
                                                <span x-text="item.type + ': ' + item.value"></span>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Feature 2 (2026-08-19) — ONE primary button: links the property, every ticked seller
             above, and their ticked contact numbers, all in a single request/transaction. Mirrors
             the deeds-capture one-click promote interaction (commit 3bc53b5b8, Johan-approved
             2026-08-19: "works bloody well") — same shape, so it feels like the same product. --}}
        <div x-show="hasLinkableOwners()" x-cloak class="rounded-md p-3 mb-4 flex items-center justify-between gap-3 flex-wrap"
             style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="text-xs" x-show="sellerBatchMessage" x-cloak x-text="sellerBatchMessage" style="color: var(--text-primary); font-weight: 600;"></div>
            <div class="text-xs" x-show="!sellerBatchMessage" style="color: var(--text-muted);">Tick the owners above, then link them all in one click.</div>
            <button type="button" @click="linkTickedSellers()" :disabled="sellerBusy || !anyOwnerTicked()"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md border-0 shrink-0"
                    style="background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;">
                <span x-show="!sellerBatchBusy">Link ticked sellers</span>
                <span x-show="sellerBatchBusy">Linking&hellip;</span>
            </button>
        </div>

        {{-- ── Sellers on this property (Part A multi-seller + Part B TVA picker) ──
             Property → many seller-links → many standalone Contacts (each ID-keyed, never merged).
             Reactive: updated by link/unlink + the poll. Each seller shows its own numbers and, when
             TVA scraped numbers matched its SA ID, a per-seller picker to add chosen numbers. --}}
        <div x-show="sellers.length" x-cloak class="rounded-md p-4 mb-4"
             style="background: color-mix(in srgb, #10b981 6%, var(--surface)); border:1px solid color-mix(in srgb, #10b981 40%, var(--border));">
            <div class="flex items-center gap-2 mb-2 flex-wrap">
                <span class="text-[10px] uppercase tracking-wider font-bold px-2 py-0.5 rounded" style="background:#10b981; color:#fff;">Sellers on this property</span>
                <span class="text-xs" style="color: var(--text-muted);">Each seller is its own contact — link as many as the property has</span>
            </div>
            <div class="space-y-3">
                <template x-for="s in sellers" :key="s.contact_id">
                    <div class="rounded-md p-3" style="background: var(--surface); border:1px solid var(--border);">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold flex items-center gap-2 flex-wrap" style="color: var(--text-primary);">
                                    <button type="button" @click="setPrimary(s.contact_id)" :disabled="sellerBusy"
                                            :title="s.is_primary ? 'This is the primary seller' : 'Make this the primary seller'"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold leading-none"
                                            :style="s.is_primary ? 'color:#b45309; background: color-mix(in srgb, #f59e0b 18%, transparent); border:0; cursor:pointer;' : 'color: var(--text-muted); background: var(--surface-2); border:0; cursor:pointer;'">
                                        <span x-text="s.is_primary ? '★' : '☆'"></span>
                                        <span x-text="s.is_primary ? 'Primary' : 'Make primary'"></span>
                                    </button>
                                    <template x-if="s.is_entity"><span class="text-[10px] uppercase tracking-wider font-bold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, #6366f1 20%, transparent); color: var(--text-primary);">Company</span></template>
                                    <span x-text="s.display_name || (((s.first_name || '') + ' ' + (s.last_name || '')).trim()) || 'Seller'"></span>
                                    <template x-if="s.dead_end"><span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); color: var(--text-primary);">⚠ Dead end · <span x-text="s.dead_end && s.dead_end.label"></span></span></template>
                                </div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    <template x-if="!s.is_entity && s.id_number"><span>ID: <span class="font-mono" x-text="s.id_number"></span> @include('corex._partials.copy-id-btn', ['value' => 's.id_number', 'label' => 'Copy ID'])</span></template>
                                    {{-- Entity seller: reg number + the representative directors (cc6's link) who are the contactable people. --}}
                                    <template x-if="s.is_entity">
                                        <span>
                                            <span x-show="s.entity_reg_no">Reg: <span class="font-mono" x-text="s.entity_reg_no"></span> @include('corex._partials.copy-id-btn', ['value' => 's.entity_reg_no', 'label' => 'Copy reg'])<span x-show="s.representatives && s.representatives.length"> · </span></span>
                                            <span x-show="s.representatives && s.representatives.length">Represented by <span x-text="(s.representatives || []).map(r => r.name).join(', ')"></span></span>
                                            <span x-show="!s.representatives || !s.representatives.length" class="italic">Directors link separately from the deed rows above</span>
                                        </span>
                                    </template>
                                </div>
                                {{-- Numbers/dead-end apply to natural-person sellers only — a company is reached through its directors. --}}
                                <template x-if="!s.is_entity">
                                <div class="flex flex-wrap gap-1 mt-1 items-center">
                                    <template x-for="p in s.phones" :key="'p' + p.value">
                                        <span class="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded font-mono"
                                              :style="p.is_primary ? 'background: color-mix(in srgb,#10b981 18%,transparent); color: var(--text-primary);' : 'background: var(--surface-2); color: var(--text-secondary);'">
                                            <template x-if="s.phones.length > 1"><button type="button" @click="setPrimaryNumber(s.contact_id,'phone',p.value)" :title="p.is_primary?'Primary phone':'Make primary phone'" style="background:none;border:0;cursor:pointer;" x-text="p.is_primary?'★':'☆'"></button></template>
                                            <span x-text="p.value"></span>
                                            <a :href="'tel:'+p.value" title="Call" class="no-underline" style="color: var(--brand-icon,#0ea5e9);">📞</a>
                                            <a :href="waUrl(p.value)" target="_blank" rel="noopener" title="WhatsApp" class="no-underline">🟢</a>
                                            <button type="button" @click="removeNumber(s.contact_id,'phone',p.value)" :disabled="sellerBusy" title="Remove number" style="background:none;border:0;cursor:pointer; color: var(--ds-crimson); font-weight:bold;">×</button>
                                        </span>
                                    </template>
                                    <template x-for="e in s.emails" :key="'e' + e.value">
                                        <span class="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded"
                                              :style="e.is_primary ? 'background: color-mix(in srgb,#10b981 18%,transparent); color: var(--text-primary);' : 'background: var(--surface-2); color: var(--text-secondary);'">
                                            <template x-if="s.emails.length > 1"><button type="button" @click="setPrimaryNumber(s.contact_id,'email',e.value)" :title="e.is_primary?'Primary email':'Make primary email'" style="background:none;border:0;cursor:pointer;" x-text="e.is_primary?'★':'☆'"></button></template>
                                            <span x-text="e.value"></span>
                                            <a :href="'mailto:'+e.value" title="Email" class="no-underline" style="color: var(--brand-icon,#0ea5e9);">✉</a>
                                            <button type="button" @click="removeNumber(s.contact_id,'email',e.value)" :disabled="sellerBusy" title="Remove email" style="background:none;border:0;cursor:pointer; color: var(--ds-crimson); font-weight:bold;">×</button>
                                        </span>
                                    </template>
                                    {{-- Johan (2026-08-19, correcting the first build of this): "the
                                         tick cant be on the green contact screen... it should be on
                                         the blue contact screen... because if theres no contact
                                         details it would appear on the green screen." The SETTING
                                         decision moved to the "No contact details on TVA" tick beside
                                         each owner's name on the blue "From the deed you scraped"
                                         panel (writes the same ContactDeadEndFlag via the same
                                         markSellerDeadEnd() service call this panel already used) —
                                         removed the "mark ‘No contact details’" button that used to
                                         sit here so there is only ONE control that can SET the fact,
                                         never two. This panel is left purely descriptive: "No number
                                         yet" states what's true; the ⚠ Dead end badge above (already
                                         existing, unchanged) shows it once flagged from blue. "clear
                                         dead-end" below stays — undoing a flag is a correction, not a
                                         second way of making the same original decision. --}}
                                    <span x-show="!s.contactable && !s.dead_end" class="text-[11px] italic" style="color: var(--text-muted);">No number yet.</span>
                                    <button type="button" x-show="s.dead_end" @click="clearSellerDeadEnd(s.contact_id)" :disabled="sellerBusy"
                                            class="text-[11px] font-semibold" style="color: var(--brand-icon, #0ea5e9); background:none; border:0; cursor:pointer;">clear dead-end</button>
                                </div>
                                </template>
                            </div>
                            <button type="button" @click="unlinkSeller(s.contact_id)" :disabled="sellerBusy"
                                    class="shrink-0 text-xs font-semibold" style="color: var(--ds-crimson); background:none; border:0; cursor:pointer;">Remove</button>
                        </div>

                        {{-- TVA numbers matched to THIS seller by ID — agent picks which to write onto this contact. --}}
                        <template x-if="tvaFor(s.id_number)">
                            <div class="mt-2 pt-2" style="border-top:1px dashed var(--border);">
                                <div class="text-[11px] font-semibold mb-1" style="color: var(--text-secondary);">TVA numbers scraped for this seller — tick which to add:</div>
                                <template x-for="item in tvaFor(s.id_number).items" :key="item.id">
                                    <label class="flex items-center gap-2 text-xs py-0.5" style="cursor:pointer;">
                                        <input type="checkbox" :checked="isTvaPicked(s.contact_id, item.id)" @change="toggleTvaPick(s.contact_id, item.id)">
                                        <span x-text="item.type === 'email' ? '✉' : '📞'"></span>
                                        <span class="font-mono" x-text="item.value"></span>
                                        <span class="text-[10px]" style="color: var(--text-muted);" x-show="item.link_date">· linked <span x-text="item.link_date"></span></span>
                                    </label>
                                </template>
                                <button type="button" @click="saveTvaNumbers(s.contact_id)"
                                        :disabled="sellerBusy || !((tvaPicks[s.contact_id] || []).length)"
                                        class="mt-1 px-3 py-1 text-xs font-semibold rounded-md border-0"
                                        style="background:#10b981; color:#fff; cursor:pointer;">Add picked numbers to this seller</button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Manual "Link a deed" fallback — ALWAYS available so the agent can pick the right deed
             when auto-match doesn't fire (P24 marketing address vs deeds-office scheme address). --}}
        <div x-show="deeds.length" x-cloak class="flex items-center justify-between gap-3 flex-wrap mb-4 rounded-md p-3"
                 style="background: var(--surface); border: 1px dashed var(--border);">
                <div class="text-xs" style="color: var(--text-muted);">
                    <span x-show="!deed.owners.length && !deed.candidates.length">No deed auto-matched to this property — </span>
                    <span x-show="deed.owners.length || deed.candidates.length">Not the right owner? </span>
                    pick the scraped deed yourself.
                </div>
                <button type="button" @click="showDeedModal = true"
                        class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md border-0"
                        style="background: var(--brand-default, #0b2a4a); color:#fff; cursor:pointer;">
                    🔍 Link a deed
                </button>
        </div>

        {{-- #3 Address-first: when the source listing carries no street address, capture
             one BEFORE the seller. Reuses the SAME "Property Address" modal + component as
             the Contact screen's "Start a Property from an Address" (Johan 2026-08-11), so
             the agent works exactly as they do from contacts. The structured fields submit
             with THIS form; storeFromProspecting composes the address and lands it on the
             listing's OWN promoted property (external_id-tied). --}}
        @if(!empty($needsAddress) && $needsAddress)
            <div class="rounded-md p-4 mb-4" style="background: var(--surface); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
                <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                    Property address <span style="color: var(--ds-crimson);">*</span>
                </h2>
                <p class="text-xs mb-3" style="color: var(--text-muted);">
                    This listing was captured without a street address. Set it here to create the property, then continue.
                </p>
                @include('corex._partials.property-address-capture', [
                    'fieldPrefix'  => 'pitch_addr',
                    'heldCheckUrl' => route('corex.contacts.check-held-address'),
                    'initial'      => [
                        'unitNumber'       => old('unit_number', ''),
                        'floorNumber'      => old('floor_number', ''),
                        'unitSectionBlock' => old('unit_section_block', ''),
                        'complexName'      => old('complex_name', ''),
                        'streetNumber'     => old('street_number', ''),
                        'streetName'       => old('street_name', ''),
                        'suburb'           => old('suburb', ''),
                        'city'             => old('city', ''),
                        'province'         => old('province', ''),
                    ],
                    'initialP24'   => [
                        'provinceId'   => old('pitch_addr_province_id', 0),
                        'cityId'       => old('pitch_addr_city_id', 0),
                        'suburbId'     => old('pitch_addr_suburb_id', 0),
                        'provinceName' => old('province', ''),
                        'cityName'     => old('city', ''),
                        'suburbName'   => old('suburb', ''),
                    ],
                ])
            </div>
        @endif

        {{-- When sellers are already linked, collapse the manual capture form — it is NOT a required
             gate anymore. "Create & continue" runs off the linked sellers. --}}
        <div x-show="sellers.length && !showManualForm" class="mb-4">
            <button type="button" @click="showManualForm = true"
                    class="px-3 py-1.5 text-xs font-semibold rounded-md"
                    style="background: transparent; color: var(--text-secondary); border:1px dashed var(--border); cursor:pointer;">
                + Add another seller manually
            </button>
        </div>

        <div x-show="!sellers.length || showManualForm" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <h2 class="text-base font-semibold" style="color: var(--text-primary);">Seller contact</h2>
                {{-- Mode toggle: pick a known owner, or capture a new one. --}}
                <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);">
                    {{-- Base `style` matches the initial mode ('create') so the toggle renders
                         correctly before Alpine hydrates; `:style` takes over reactively. --}}
                    <button type="button" @click="mode = 'search'"
                            class="px-3 py-1.5 text-xs font-semibold border-0"
                            style="background: var(--surface-2); color: var(--text-secondary); cursor:pointer;"
                            :style="mode === 'search'
                                ? 'background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;'
                                : 'background: var(--surface-2); color: var(--text-secondary); cursor:pointer;'">
                        Search existing
                    </button>
                    <button type="button" @click="mode = 'create'; selected = null"
                            class="px-3 py-1.5 text-xs font-semibold border-0"
                            style="background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;"
                            :style="mode === 'create'
                                ? 'background: var(--brand-icon, #0ea5e9); color:#fff; cursor:pointer;'
                                : 'background: var(--surface-2); color: var(--text-secondary); cursor:pointer;'">
                        Create new
                    </button>
                </div>
            </div>

            {{-- ── Search existing contact ── --}}
            <div x-show="mode === 'search'" x-cloak class="space-y-2">
                {{-- Chosen contact — its id is what the controller links. --}}
                <template x-if="selected">
                    <div class="flex items-center justify-between gap-3 rounded-md p-3"
                         style="background: var(--surface-2); border:1px solid var(--border);">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);" x-text="label(selected)"></div>
                            <div class="text-xs truncate" style="color: var(--text-muted);">
                                <span x-text="selected.phone || ''"></span><span x-show="selected.phone && selected.email"> · </span><span x-text="selected.email || ''"></span>
                            </div>
                        </div>
                        <button type="button" @click="selected = null" class="text-xs font-semibold shrink-0" style="color: var(--brand-icon, #0ea5e9); background:none; border:0; cursor:pointer;">Change</button>
                        <input type="hidden" name="contact_id" :value="selected.id">
                    </div>
                </template>

                {{-- Search box + live results (hidden once a contact is chosen). --}}
                <div x-show="!selected">
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Search your contacts</label>
                    <input type="text" x-model="q" @input.debounce.300ms="search()"
                           placeholder="Name, phone or email…" autocomplete="off"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <div class="mt-1 text-xs" style="color: var(--text-muted);" x-show="loading">Searching…</div>
                    <div class="mt-1 text-xs" style="color: var(--text-muted);" x-show="!loading && q.trim().length >= 2 && results.length === 0">
                        No matches — switch to “Create new”.
                    </div>
                    <div class="mt-2 rounded-md overflow-hidden" style="border:1px solid var(--border);" x-show="results.length > 0">
                        <template x-for="c in results" :key="c.id">
                            <button type="button" @click="choose(c)"
                                    class="w-full text-left px-3 py-2 text-sm block"
                                    style="background: var(--surface); color: var(--text-primary); border:0; border-bottom:1px solid var(--border); cursor:pointer;">
                                <span class="font-semibold" x-text="label(c)"></span>
                                <span class="text-xs" style="color: var(--text-muted);">— <span x-text="c.phone || c.email || ''"></span></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ── Create new contact ── --}}
            <div x-show="mode === 'create'" class="space-y-3">

            {{-- Contact Is: Natural person OR Entity (company / CC / trust). An entity seller must be
                 capturable here — not forced to a natural person. Selecting Entity swaps the name fields
                 for the registered name + reg number; a hidden input carries contact_kind to the store. --}}
            <input type="hidden" name="contact_kind" :value="contactKind">
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Contact is <span style="color: var(--ds-crimson);">*</span></label>
                <div class="flex items-center gap-4 text-sm" style="color: var(--text-secondary);">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="contact_kind_toggle" value="natural_person" x-model="contactKind"> Natural person</label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer"><input type="radio" name="contact_kind_toggle" value="entity" x-model="contactKind"> Entity <span style="color: var(--text-muted);">(company / CC / trust)</span></label>
                </div>
            </div>

            {{-- Natural-person name fields --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="contactKind === 'natural_person'">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        First name <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="first_name" x-ref="firstName" value="{{ old('first_name') }}" :required="mode === 'create' && sellers.length === 0 && contactKind === 'natural_person'" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Last name</label>
                    <input type="text" name="last_name" x-ref="lastName" value="{{ old('last_name') }}" maxlength="100"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- Entity fields (company / CC / trust) — registered name + optional registration number. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="contactKind === 'entity'" x-cloak>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                        Registered name <span style="color: var(--ds-crimson);">*</span>
                    </label>
                    <input type="text" name="entity_name" value="{{ old('entity_name') }}" :required="mode === 'create' && sellers.length === 0 && contactKind === 'entity'" maxlength="255"
                           placeholder="e.g. Blue Horizon Trust"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Registration number <span style="color: var(--text-muted); font-weight:400;">(optional)</span></label>
                    <input type="text" name="entity_reg_no" value="{{ old('entity_reg_no') }}" maxlength="100"
                           placeholder="e.g. 2019/123456/07"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <p class="text-[11px] mt-1" style="color: var(--text-muted);">Add the directors/representatives on the entity record afterward.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Phone</label>
                    <input type="tel" name="phone" x-ref="phone" value="{{ old('phone') }}" maxlength="30" placeholder="082 123 4567"
                           @input="contactTyped = hasTypedContact()"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Email</label>
                    <input type="email" name="email" x-ref="email" value="{{ old('email') }}" maxlength="255"
                           @input="contactTyped = hasTypedContact()"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- #17 — SA ID vs foreign passport at create time (natural person only; an entity is keyed on
                 its registration number above). SA path validates the 13-digit ID; a foreign national
                 enters a passport + a directly-entered Date of Birth (the passport doesn't encode it).
                 Same discriminator + rules as the main contact form. Backward-compatible. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" x-show="contactKind === 'natural_person'">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">ID Type</label>
                    <select name="id_type" x-model="idKind"
                            class="w-full px-3 py-2 text-sm rounded-md"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="sa_id">South African ID</option>
                        <option value="passport">Foreign / Passport</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);"><span x-text="idKind === 'passport' ? 'Passport Number' : 'ID Number'"></span> <span style="color: var(--text-muted); font-weight:400;">(optional)</span></label>
                    <input type="text" name="id_number" x-ref="idNumber" value="{{ old('id_number') }}"
                           :inputmode="idKind === 'passport' ? 'text' : 'numeric'"
                           :maxlength="idKind === 'passport' ? 50 : 13"
                           :placeholder="idKind === 'passport' ? 'e.g. AB1234567' : 'e.g. 7610025020081'"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Date of Birth <span style="color: var(--text-muted); font-weight:400;" x-show="idKind !== 'passport'">(optional)</span><span class="text-red-500" x-show="idKind === 'passport'" x-cloak>*</span></label>
                    <input type="date" name="birthday" value="{{ old('birthday') }}"
                           :required="contactKind === 'natural_person' && idKind === 'passport'"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>

            {{-- Property-level "No contact details available" tick REMOVED (2026-08-19, Johan,
                 verbatim, real property with 4 owners/3 found in TVA): "I need the tick... to be per
                 seller." A single tick on this manual-entry form couldn't express "3 of 4 owners have
                 numbers, one doesn't" — replaced by the per-owner "No TVA numbers found" tick beside
                 each name in the deed/candidate owner lists above, which links the seller regardless
                 and writes the SAME ContactDeadEndFlag onto their contact record. Do not re-add a
                 tick here — that would be the two-ways-of-saying-the-same-thing Johan asked removed. --}}

            <div class="text-xs" style="color: var(--text-muted);">
                <span x-show="contactKind === 'natural_person'">Provide at least a phone or email — we'll check if this person already exists in your contacts.</span>
                <span x-show="contactKind === 'entity'" x-cloak>We'll match the entity on its registration number (or name) so you land on the captured company, not a duplicate.</span>
            </div>
            </div>{{-- /create-new --}}
        </div>

        <div class="flex items-center gap-2 flex-wrap mt-4">
            <button type="submit"
                    :disabled="mode === 'search' && !selected"
                    class="corex-btn-primary px-6 py-2.5 text-sm"
                    :style="(mode === 'search' && !selected)
                        ? 'background: var(--surface-2); color: var(--text-muted); box-shadow:none; cursor:not-allowed;'
                        : 'background: var(--brand-button, #0ea5e9); color:#ffffff; cursor:pointer;'">
                <span x-text="(sellers.length && mode !== 'search') ? 'Create &amp; continue →' : (mode === 'search' ? 'Link &amp; continue →' : 'Create &amp; continue →')"></span>
            </button>
            <a href="{{ url()->previous() }}" class="corex-btn-outline text-sm no-underline">Cancel</a>
        </div>

        {{-- Manual deed-picker modal — clean, searchable, scrollable list of the agency's deeds.
             Inside the form so it shares the Alpine scope (prefills the fields via useDeedOwner). --}}
        <div x-show="showDeedModal" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-center p-4"
             style="background: rgba(0,0,0,0.5);"
             @keydown.escape.window="showDeedModal = false">
            <div class="w-full max-w-2xl rounded-lg mt-10 flex flex-col"
                 style="background: var(--surface); border:1px solid var(--border); max-height: 80vh;"
                 @click.outside="showDeedModal = false">
                <div class="flex items-center justify-between gap-3 px-4 py-3" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">Link a deed to this property</h3>
                    <button type="button" @click="showDeedModal = false" class="text-sm"
                            style="color: var(--text-muted); background:none;border:0;cursor:pointer;">✕</button>
                </div>
                <div class="px-4 py-3" style="border-bottom:1px solid var(--border);">
                    <input type="text" x-model="deedSearch" placeholder="Search address, owner, erf, suburb…" autocomplete="off"
                           class="w-full px-3 py-2 text-sm rounded-md"
                           style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                </div>
                <div class="overflow-y-auto px-2 py-2" style="min-height: 120px;">
                    <template x-for="deed in filteredDeeds()" :key="deed.tracked_property_id">
                        <button type="button" @click="pickDeed(deed)"
                                class="w-full text-left px-3 py-2 rounded-md mb-1 block"
                                style="background: var(--surface-2); border:1px solid var(--border); cursor:pointer;">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);" x-text="deed.address || '(no address)'"></div>
                            <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                <template x-if="deed.erf"><span>Erf <span x-text="deed.erf"></span> · </span></template>
                                <span x-text="deed.suburb || ''"></span>
                                <template x-if="deed.sold_price"><span> · Sold R<span x-text="Number(deed.sold_price).toLocaleString()"></span></span></template>
                                <template x-if="deed.sold_date"><span> · <span x-text="deed.sold_date"></span></span></template>
                            </div>
                            <div class="text-xs mt-0.5 font-medium" style="color: var(--brand-icon, #0ea5e9);" x-text="deed.owner_names"></div>
                            {{-- CX-101 (2026-08-19, Johan, blocked) — a deed already linked to a
                                 property USED to be hidden from this list entirely, leaving the
                                 one deed that belonged here the one deed he couldn't pick, with no
                                 explanation. Shown now, state stated plainly, still pickable. --}}
                            <template x-if="deed.already_on_books_as">
                                <div class="text-xs mt-1 px-2 py-1 rounded" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 15%, transparent); color: var(--text-primary);">
                                    Already linked to a property on your books — <span x-text="deed.already_on_books_as"></span>. Pick it anyway to use this deed here too.
                                </div>
                            </template>
                            <template x-if="!deed.has_owner">
                                <div class="text-xs mt-1 px-2 py-1 rounded" style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted);">
                                    No owner captured on this deed yet — you can still link it; add the seller separately below.
                                </div>
                            </template>
                        </button>
                    </template>
                    <div x-show="filteredDeeds().length === 0" class="px-3 py-6 text-center text-sm" style="color: var(--text-muted);">
                        No deeds match “<span x-text="deedSearch"></span>”.
                    </div>
                </div>
                <div class="px-4 py-2 text-xs" style="border-top:1px solid var(--border); color: var(--text-muted);">
                    Picking a deed prefills the seller from its registered owner and remembers the link.
                </div>
            </div>
        </div>
    </form>
</div>
<script>
function composeSeller(config) {
    return {
        mode: 'create',
        contactKind: config.contactKind,
        idKind: config.idKind,
        q: '',
        results: [],
        loading: false,
        selected: null,
        searchUrl: config.searchUrl,
        label(c) { return ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '(no name)'; },
        async search() {
            const term = this.q.trim();
            if (term.length < 2) { this.results = []; this.loading = false; return; }
            this.loading = true;
            try {
                const res = await fetch(this.searchUrl + '?q=' + encodeURIComponent(term), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                this.results = res.ok ? await res.json() : [];
            } catch (e) { this.results = []; }
            this.loading = false;
        },
        choose(c) { this.selected = c; this.results = []; this.q = ''; },
        useDeedOwner(o) {
            this.mode = 'create';
            this.selected = null;
            this.$nextTick(() => {
                if (this.$refs.firstName) this.$refs.firstName.value = o.first_name || '';
                if (this.$refs.lastName)  this.$refs.lastName.value  = o.last_name || '';
                if (this.$refs.idNumber)  this.$refs.idNumber.value  = o.id_number || '';
                if (this.$refs.firstName) this.$refs.firstName.focus();
            });
        },
        showDeedModal: false,
        deedSearch: '',
        deeds: config.deeds,
        linkDeedUrl: config.linkDeedUrl,
        deed: { owners: config.deedOwners, candidates: config.deedCandidates },
        deedPollUrl: config.deedPollUrl,
        _deedTimer: null,
        deedRefreshing: false,
        deedRefreshMessage: null,
        // Automatic timer: numbers only (exact-ID TVA match, no wrong-match risk).
        // Deed matching (fuzzy address) updates ONLY from refreshDeedMatch(), on click.
        startDeedPoll() {
            if (!this.deedPollUrl) return;
            this._deedTimer = setInterval(() => this.pollNumbers(), 5000);
            this._onDeedVisible = () => { if (!document.hidden) this.pollNumbers(); };
            document.addEventListener('visibilitychange', this._onDeedVisible);
            window.addEventListener('focus', this._onDeedVisible);
            this.pollNumbers();
        },
        async pollNumbers() {
            if (document.hidden) return;
            try {
                const res = await fetch(this.deedPollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) return;
                const d = await res.json();
                if (d.sellers) this.sellers = d.sellers;
                if (d.tva) this.tva = d.tva;
                if (d.property_id) this.propertyId = d.property_id;
                if ('linked_deed' in d) this.linkedDeed = d.linked_deed;
                if ('removed' in d) this.removed = d.removed || [];
            } catch (e) { /* transient — keep polling */ }
        },
        async refreshDeedMatch() {
            // Merge (2026-08-19): ONE Refresh button now covers what used to be two separate
            // refreshes (the deed/owner list, and the grey panel's own contact-numbers poll) —
            // this is the same deedPollUrl response both used; applySellerState() (below) picks
            // up the sellers/tva/property/linked-deed/removed side, this method keeps its own
            // owners/candidates/deeds side. One fetch, one place both halves come from.
            if (!this.deedPollUrl || this.deedRefreshing) return;
            this.deedRefreshing = true;
            this.deedRefreshMessage = null;
            try {
                const res = await fetch(this.deedPollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) { this.deedRefreshMessage = 'Refresh failed — try again.'; return; }
                const d = await res.json();
                this.deed.owners = d.owners || [];
                this.deed.candidates = d.candidates || [];
                if (Array.isArray(d.deeds)) this.deeds = d.deeds;
                this.applySellerState(d);
            } catch (e) {
                this.deedRefreshMessage = 'Refresh failed — try again.';
            } finally {
                this.deedRefreshing = false;
            }
        },
        sellers: config.sellers,
        tva: config.tva,
        propertyId: config.propertyId,
        linkSellerUrl: config.linkSellerUrl,
        unlinkSellerUrl: config.unlinkSellerUrl,
        linkSellersBatchUrl: config.linkSellersBatchUrl,
        tvaIngestUrl: config.tvaIngestUrl,
        primarySellerUrl: config.primarySellerUrl,
        deadEndSellerUrl: config.deadEndSellerUrl,
        unlinkDeedUrl: config.unlinkDeedUrl,
        removeNumberUrl: config.removeNumberUrl,
        primaryNumberUrl: config.primaryNumberUrl,
        linkedDeed: config.linkedDeed,
        removed: config.removed,
        tvaPicks: {},
        sellerBusy: false,
        showManualForm: false,
        async setPrimary(contactId) {
            if (!this.primarySellerUrl || this.sellerBusy) return;
            this.sellers = this.sellers.map(s => ({ ...s, is_primary: s.contact_id === contactId }));
            this.sellerBusy = true;
            try {
                const res = await fetch(this.primarySellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        async markSellerDeadEnd(contactId, reason) {
            if (!this.deadEndSellerUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.deadEndSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, reason: reason || 'not_in_tva' }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        async clearSellerDeadEnd(contactId) {
            if (!this.deadEndSellerUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.deadEndSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, clear: true }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        _postHeaders() {
            return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' };
        },
        applySellerState(d) {
            window.dispatchEvent(new CustomEvent('owners-updated', { detail: { owners: d.sellers || [] } }));
            this.sellers = d.sellers || [];
            this.tva = d.tva || {};
            if (d.property_id) this.propertyId = d.property_id;
            if ('linked_deed' in d) this.linkedDeed = d.linked_deed;
            if ('removed' in d) this.removed = d.removed || [];
        },
        isSellerLinked(owner) {
            if (!owner) return false;
            return this.sellers.some(s =>
                (owner.contact_id && s.contact_id === owner.contact_id)
                || (owner.id_number && s.id_number === owner.id_number));
        },
        isRemoved(idNumber) { return !!idNumber && (this.removed || []).includes(idNumber); },
        // Feature 2 (2026-08-19) — tick-and-one-click linking, mirroring the deeds-capture
        // one-click promote pattern (commit 3bc53b5b8): tick several deed/candidate owners (and,
        // per owner, which of their already-scraped TVA numbers to carry along), then ONE button
        // links the property, every ticked seller and their numbers in a single request. Replaces
        // clicking "+ Link as seller" once per owner. Same key shape the existing x-for uses, so a
        // tick survives the reactive deed/candidate list being replaced wholesale by a poll.
        ownerKey(owner) { return (owner.contact_id || '') + '-' + (owner.id_number || owner.name || owner.display_name || ''); },
        isLinkableOwner(owner) { return !this.isSellerLinked(owner) && !!(owner.contact_id || owner.is_entity || owner.id_number); },
        // The "No TVA numbers found" tick is usable on EVERY owner with an identifiable target,
        // linked or not — the SELECTION tick above stays gated to not-yet-linked (isLinkableOwner),
        // but this one must not be, or an owner already linked (e.g. via "Link a deed", which links
        // ALL of a deed's current owners in one server-side action with no per-owner tick) would
        // have no way to be flagged at all. Johan hit exactly this gap, blocked on Continue.
        canMarkNoTva(owner) { return !!(owner.contact_id || owner.is_entity || owner.id_number); },
        findLinkedSeller(owner) {
            if (!owner) return null;
            return this.sellers.find(s =>
                (owner.contact_id && s.contact_id === owner.contact_id)
                || (owner.id_number && s.id_number === owner.id_number)) || null;
        },
        sellerTicks: {},
        // Default tick state (2026-08-19, per conductor ask — a deliberate call, not put to
        // Johan): CONFIRMED deed owners (this listing's own linked deed) start TICKED — they are
        // this property's actual registered owners, the same trust level deeds-capture's own
        // promote already treats as auto-include with no ticking at all. UNVERIFIED candidate-deed
        // owners start UNTICKED — the section's own copy already says "verify this is the same
        // property before using," so ticking one must be a deliberate act, not an assumed default.
        isOwnerTicked(owner, defaultTicked) {
            const k = this.ownerKey(owner);
            return k in this.sellerTicks ? this.sellerTicks[k] : defaultTicked;
        },
        toggleOwnerTick(owner, defaultTicked) {
            const k = this.ownerKey(owner);
            this.sellerTicks[k] = !this.isOwnerTicked(owner, defaultTicked);
        },
        // TVA number checkboxes default UNTICKED for every owner — exact mirror of deeds-capture's
        // own item_ids[] checkboxes (no `checked` attribute there either), so ticking a phone/email
        // number is always its own deliberate choice, independent of the owner-link decision.
        tvaTicksForOwner: {},
        isOwnerTvaTicked(idNumber, itemId) { return (this.tvaTicksForOwner[idNumber] || []).includes(itemId); },
        toggleOwnerTvaTick(idNumber, itemId) {
            if (!this.tvaTicksForOwner[idNumber]) this.tvaTicksForOwner[idNumber] = [];
            const arr = this.tvaTicksForOwner[idNumber];
            const i = arr.indexOf(itemId);
            if (i >= 0) arr.splice(i, 1); else arr.push(itemId);
        },
        // Johan, verbatim (2026-08-19, real property, 4 owners, 3 found in TVA): "I need the tick...
        // to be per seller... but we still link the seller to the property but update same on
        // contact record." This is a STATE tick on the person, never a gate on the selection tick
        // above — an owner can be selected AND flagged uncontactable at the same time; both submit
        // together and the seller links regardless (enforced server-side too, see the controller).
        //
        // GATE OVERRIDE (2026-08-19, Johan, blocked mid-test): "Create & Continue" refuses to
        // proceed while any linked seller is neither contactable nor flagged — this tick is HOW an
        // agent satisfies that for a person TVA genuinely had nothing on. Two cases:
        //  - Owner not yet linked: pure client-side intent, bundled into the next "Link ticked
        //    sellers" submit (tickedOwnersPayload() below) — the controller writes the real
        //    ContactDeadEndFlag in the SAME transaction as the link.
        //  - Owner ALREADY linked (e.g. came in via "Link a deed", which links every current owner
        //    server-side with no per-owner tick at all): there is no pending batch submit to bundle
        //    into, so ticking here fires the SAME single-seller endpoint the green panel used to
        //    (markSellerDeadEnd/clearSellerDeadEnd) immediately — same ContactDeadEndFlag row either
        //    way, so "Create & Continue"'s sellersNeedingContact() (which re-reads that flag fresh
        //    from the DB) sees the same fact regardless of which path set it.
        ownerNoTvaTicks: {},
        isOwnerNoTvaTicked(owner) {
            const linked = this.findLinkedSeller(owner);
            if (linked) return !!linked.dead_end;
            return !!this.ownerNoTvaTicks[this.ownerKey(owner)];
        },
        async toggleOwnerNoTva(owner) {
            const linked = this.findLinkedSeller(owner);
            if (linked) {
                if (linked.dead_end) { await this.clearSellerDeadEnd(linked.contact_id); }
                else { await this.markSellerDeadEnd(linked.contact_id, 'not_in_tva'); }
                return;
            }
            const k = this.ownerKey(owner);
            this.ownerNoTvaTicks[k] = !this.ownerNoTvaTicks[k];
        },
        tickedOwnersPayload() {
            const sellers = [];
            const collect = (list, defaultTicked) => {
                (list || []).forEach(o => {
                    if (!this.isLinkableOwner(o) || !this.isOwnerTicked(o, defaultTicked)) return;
                    const entry = o.contact_id ? { contact_id: o.contact_id }
                        : o.is_entity ? { entity: true, entity_name: o.display_name || o.name, entity_reg_no: o.entity_reg_no || o.id_number || '' }
                        : { first_name: o.first_name, last_name: o.last_name, id_number: o.id_number };
                    if (o.id_number && (this.tvaTicksForOwner[o.id_number] || []).length) {
                        entry.tva_item_ids = this.tvaTicksForOwner[o.id_number];
                    }
                    if (this.isOwnerNoTvaTicked(o)) {
                        entry.no_tva_numbers = true;
                    }
                    sellers.push(entry);
                });
            };
            collect(this.deed.owners, true);
            (this.deed.candidates || []).forEach(cand => collect(cand.owners, false));
            return sellers;
        },
        anyOwnerTicked() { return this.tickedOwnersPayload().length > 0; },
        // Merge (2026-08-19) — the ONE status line for the merged "Owners & contact numbers"
        // panel, replacing what used to be two separate messages (this panel's own
        // deedRefreshMessage, and the standalone grey panel's message) that could read as
        // disagreeing about the same four people. Counts against deed.owners — the CMA/deed's
        // current owners, always current data, no past-owner handling needed here.
        ownersSummary() {
            const owners = this.deed.owners || [];
            const total = owners.length;
            if (total === 0) return 'No owners on this deed.';
            let withNumbers = 0;
            let marked = 0;
            owners.forEach(o => {
                const linked = this.findLinkedSeller(o);
                if (linked) {
                    if ((linked.phones || []).length || (linked.emails || []).length) withNumbers++;
                    if (linked.dead_end) marked++;
                } else if (this.isOwnerNoTvaTicked(o)) {
                    marked++;
                }
            });
            const parts = [total + ' owner' + (total === 1 ? '' : 's')];
            if (withNumbers > 0) parts.push(withNumbers + ' with number' + (withNumbers === 1 ? '' : 's'));
            if (marked > 0) parts.push(marked + ' marked no contact details');
            return parts.join(' · ');
        },
        hasLinkableOwners() {
            return (this.deed.owners || []).some(o => this.isLinkableOwner(o))
                || (this.deed.candidates || []).some(c => (c.owners || []).some(o => this.isLinkableOwner(o)));
        },
        sellerBatchBusy: false,
        sellerBatchMessage: null,
        async linkTickedSellers() {
            if (!this.linkSellersBatchUrl || this.sellerBusy) return;
            const sellers = this.tickedOwnersPayload();
            if (!sellers.length) return;
            this.sellerBusy = true;
            this.sellerBatchBusy = true;
            this.sellerBatchMessage = null;
            try {
                const res = await fetch(this.linkSellersBatchUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ sellers }) });
                if (res.ok) {
                    const d = await res.json();
                    this.sellerBatchMessage = d.batch_summary || null;
                    this.applySellerState(d);
                    this.sellerTicks = {};
                    this.tvaTicksForOwner = {};
                    this.ownerNoTvaTicks = {};
                } else {
                    const err = await res.json().catch(() => ({}));
                    this.sellerBatchMessage = err.message || (err.errors && Object.values(err.errors).flat()[0]) || 'Could not link the ticked owners — nothing was linked.';
                }
            } catch (e) {
                this.sellerBatchMessage = 'Could not link the ticked owners — a network error occurred. Please try again.';
            } finally {
                this.sellerBusy = false;
                this.sellerBatchBusy = false;
            }
        },
        async unlinkDeed() {
            if (!this.unlinkDeedUrl || this.sellerBusy) return;
            if (!window.confirm('Unlink this deed? Its auto-linked sellers are removed and the address reverts. Manual sellers stay.')) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.unlinkDeedUrl, { method: 'POST', headers: this._postHeaders(), body: '{}' });
                if (res.ok) { this.applySellerState(await res.json()); this.refreshDeedMatch(); }
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        async removeNumber(contactId, type, value) {
            if (!this.removeNumberUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.removeNumberUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, type, value }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        async setPrimaryNumber(contactId, type, value) {
            if (!this.primaryNumberUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.primaryNumberUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ contact_id: contactId, type, value }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        waUrl(v) { return 'https://wa.me/' + (v || '').replace(/[^0-9]/g, '').replace(/^0/, '27'); },
        async linkSeller(owner) {
            if (!this.linkSellerUrl || this.sellerBusy) return;
            let body;
            if (owner.contact_id) {
                body = { contact_id: owner.contact_id };
            } else if (owner.is_entity) {
                body = { entity: true, entity_name: owner.display_name || owner.name, entity_reg_no: owner.entity_reg_no || owner.id_number || '' };
            } else if (owner.id_number) {
                body = { first_name: owner.first_name, last_name: owner.last_name, id_number: owner.id_number };
            } else {
                window.alert('This owner has no SA ID or registration number on the deed \u2014 cannot link as a distinct seller.');
                return;
            }
            this.sellerBusy = true;
            try {
                const res = await fetch(this.linkSellerUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify(body) });
                if (res.ok) { this.applySellerState(await res.json()); }
                else {
                    const err = await res.json().catch(() => ({}));
                    const msg = err.message || (err.errors && Object.values(err.errors).flat()[0]) || 'Could not link this owner as a seller.';
                    window.alert(msg);
                }
            } catch (e) {
                window.alert('Could not link this owner \u2014 a network error occurred. Please try again.');
            } finally { this.sellerBusy = false; }
        },
        async unlinkSeller(contactId) {
            if (!this.unlinkSellerUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.unlinkSellerUrl, { method: 'POST', headers: this._postHeaders(),
                    body: JSON.stringify({ contact_id: contactId }) });
                if (res.ok) this.applySellerState(await res.json());
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        tvaFor(idNumber) { return (idNumber && this.tva[idNumber]) ? this.tva[idNumber] : null; },
        toggleTvaPick(contactId, itemId) {
            if (!this.tvaPicks[contactId]) this.tvaPicks[contactId] = [];
            const arr = this.tvaPicks[contactId];
            const i = arr.indexOf(itemId);
            if (i >= 0) arr.splice(i, 1); else arr.push(itemId);
        },
        isTvaPicked(contactId, itemId) { return (this.tvaPicks[contactId] || []).includes(itemId); },
        async saveTvaNumbers(contactId) {
            const ids = this.tvaPicks[contactId] || [];
            if (!ids.length || !this.tvaIngestUrl || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.tvaIngestUrl, { method: 'POST', headers: this._postHeaders(),
                    body: JSON.stringify({ contact_id: contactId, item_ids: ids }) });
                if (res.ok) { this.applySellerState(await res.json()); this.tvaPicks[contactId] = []; }
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        filteredDeeds() {
            const q = this.deedSearch.trim().toLowerCase();
            if (!q) return this.deeds;
            return this.deeds.filter(d => (d.search || '').includes(q));
        },
        async pickDeed(deed) {
            this.showDeedModal = false;
            if (!this.linkDeedUrl || !deed.tracked_property_id || this.sellerBusy) return;
            this.sellerBusy = true;
            try {
                const res = await fetch(this.linkDeedUrl, { method: 'POST', headers: this._postHeaders(), body: JSON.stringify({ tracked_property_id: deed.tracked_property_id }) });
                if (res.ok) { this.applySellerState(await res.json()); this.refreshDeedMatch(); }
            } catch (e) { /* ignore */ } finally { this.sellerBusy = false; }
        },
        contactTyped: config.contactTyped,
        hasTypedContact() {
            return !!((this.$refs.phone && this.$refs.phone.value.trim())
                || (this.$refs.email && this.$refs.email.value.trim()));
        },
    };
}
</script>
@endsection
