@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §0b — the capture surface, rebuilt
    2026-09-22 per Johan: "it lives on a property... agent needs to see the
    spaces again, like with inspections... upload photos and tag it to the
    room, then type in what inventory is in that room, and if they want to
    even tag the line item added to a photo in that room." Reached only from
    the property (see properties/show.blade.php's single Inventory link) —
    the property is already known, so it is never asked for here.

    Deliberately its own page with its OWN Alpine component
    (rentalInventoryCapture below) — not extending or touching the giant
    shared component in properties/show.blade.php, to avoid any collision
    with concurrent work there this session. Rooms are the SAME PropertyRoom
    rows and ordering (sort_order, id) that surface already uses.

    Autosave throughout — every add/edit/tag fires its own request the
    moment the agent acts; there is no Save button anywhere on this page.
    Autosave is not announced with helper text (Johan: never say it, just
    behave that way) — every line on this screen is either data or a
    control.

    §13, 2026-09-27 — Johan's approved capture-screen mockup replaced the
    all-rooms-open accordion list (design pass 2, 2026-09-22) with a single
    active room at a time, selected from a horizontal, never-wrapping strip
    of room chips across the top, each carrying a status dot (captured /
    part done / not opened).

    §13 layout pass, 2026-09-27 — Johan: "we have a whole wide screen yet
    we choose to not align qty with desc and use a lot more space than
    needed. With proper engineering everything from 1 inventory item can
    sit on 1 line." The max-w-3xl cap is dropped so the whole card width is
    used, and each line's qty/description/condition-chips/photo-strip/
    actions collapse onto ONE row (.inv-line, ≥1024px) instead of stacking
    across 4-5 lines. Behaviour (autosave, arrow-key grid nav, condition
    click, photo upload/tag, remove) is unchanged — this pass is markup +
    CSS only; see .ai/specs/rental-inventory.md §13.9 for the full note.

    §13.10, 2026-09-27 — Johan: "on inventory we need to show all the same
    menu items like on inspections, not just a blank page like it's now."
    This page now renders inside the SAME property shell
    (properties/show.blade.php's identity strip + tab bar) via two shared
    partials (partials/_property-shell-header.blade.php,
    _property-shell-tabs.blade.php — 'link' mode: every tab except
    Inventory is a real link to the property page, since this page has
    none of the other tabs' panels to switch to locally). The page's own
    "Back to property" button is removed — redundant now that Overview (or
    any other tab) is one click away. See .ai/specs/rental-inventory.md
    §13.10 for the full note, including why the header/tab MARKUP moved
    into partials while a small amount of variable-derivation PHP is
    deliberately re-computed here rather than shared (that top-of-file
    derivation in show.blade.php itself stayed in place there because two
    of its variables are read again later in that same giant file).

    §13.11, 2026-09-27 — Johan, having seen the single-active-room chip
    strip: "show the spaces like on inspections. It's not separate tabs,
    it's just quick navigation to get to that section." Every room now
    renders stacked down the page (like the inspection recording screen's
    room list) instead of one room's panel at a time; the chip strip is
    kept, sticky at the top of the scrolling inventory area, and clicking
    a chip smooth-scrolls to that room's panel rather than switching which
    panel is visible. See §13.11 in the spec for the scroll-mechanics note
    (scroll-margin-top + IntersectionObserver scrollspy).
--}}

@section('corex-content')
{{-- §4a — the SAME shared uploader rental-inspections built
     (rental-inspections.md §20.13.4), not a second bespoke one. --}}
@if($inventory)
<script src="{{ asset_v('js/corex-photo-batch-uploader.js') }}"></script>
@endif
<style>
    /* §13.10 — property shell adoption. Only the tab PANEL scrolls, same
       pattern properties/show.blade.php's own restyle already uses
       (AT-393) — the property header stays visible above it, never
       scrolling away, no matter how many rooms this inventory has. */
    .corex-props-v2 .prop-tab-panel { overflow-y: auto; overflow-x: clip; }
    /* §13.11 — the tab bar + room-pill strip stick TOGETHER as one group
       (one sticky container, not two independently-offset ones) so
       nothing needs a hardcoded "height of the thing above me" pixel
       value. Room panels get their scroll-margin-top set to this group's
       REAL rendered height via a CSS custom property, updated in JS
       (updateStickyOffset()) — the 160px fallback only matters for the
       brief instant before that JS runs. */
    #inv-sticky-shell { position: sticky; top: 0; z-index: 15; background: var(--surface); }
    .inv-room-panel { scroll-margin-top: var(--inv-sticky-offset, 160px); }

    /* §13.9 layout pass — one inventory line = one row on desktop.
       Mobile/tablet (<1024px) wraps to 2 lines: qty+desc+actions, then
       chips+photos — done with `order` + a forced flex-basis:100% break,
       NOT a second markup tree, so there is exactly one DOM per line for
       the arrow-key grid nav (onCellKeydown) to address via data-row/
       data-cell. Desktop (>=1024px) switches the SAME element to a 5-column
       grid with explicit grid-column per cell (not auto-placement), so a
       row with fewer cells (the draft add-row has no chips/photos) still
       lines its qty/description/actions up under the cells that do have
       them — auto-placement would silently compact those into the wrong
       columns. */
    .inv-header-row { display: none; }
    .inv-line {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.375rem 0.5rem;
        padding: 0.375rem 0;
        border-bottom: 1px solid var(--border);
    }
    .inv-cell-qty { order: 1; width: 4.5rem; flex-shrink: 0; }
    /* flex-basis:0% (the "0%" in the shorthand), not the more common
       "auto" — auto reads the item's own `width` property as its basis,
       and .prop-input sets width:100% globally, which would make THIS
       item alone claim the full flex line and wrap qty away from it. A
       literal 0% basis ignores `width` entirely; flex-grow:1 alone still
       expands it to fill whatever space remains after qty/actions.
       min-width is deliberately NOT set here at mobile — flex-wrap's own
       line-fitting pass sums every mobile-line-1 item's min-width (or
       min-content floor, absent one) BEFORE any flex-grow runs, and an
       8rem floor alone (72px qty + 128px desc-floor + ~113px actions +
       gaps, on a ~258px-wide phone content column) already exceeds the
       available line width, forcing "actions" to wrap to its own third
       line even though it fits fine once desc grows from a smaller
       floor. min-width:0 (the flex-item default) lets qty+desc+actions
       share line 1 as intended, at the min-content this specific input
       actually needs, not an arbitrary reserved floor. */
    .inv-cell-desc { order: 2; flex: 1 1 0%; }
    .inv-cell-actions { order: 3; display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; margin-left: auto; }
    /* A zero-size flex item with flex-basis:100% forces a line break
       BEFORE itself — everything after it starts a fresh line, while it
       consumes none of that line's own visible width (unlike putting
       flex-basis:100% directly on .inv-cell-chips, which would force
       chips onto its own line ALONE, leaving nothing for photos to
       share it with). Hidden outright at desktop, where there's no
       wrapping to force. */
    .inv-linebreak { order: 4; flex-basis: 100%; width: 0; height: 0; margin: 0; padding: 0; }
    .inv-cell-chips { order: 5; display: flex; align-items: center; gap: 0.25rem; flex-wrap: wrap; }
    .inv-cell-photos { order: 6; display: flex; align-items: center; gap: 0.375rem; overflow-x: auto; flex-wrap: nowrap; flex: 1 1 auto; min-width: 0; }
    @media (min-width: 1024px) {
        .inv-linebreak { display: none; }
        /* §13.12 fix, 2026-09-27 — the Condition/Photos column headers
           didn't line up with the chips/photos below them. Root cause:
           `.inv-header-row` and `.inv-line` are each their OWN separate
           CSS Grid container — an `auto` track sizes to that INSTANCE's
           own content, not shared across sibling grid containers. The
           header row's "Condition"/(blank) cells are short text, so its
           auto columns rendered narrower than a data row's (4 chip
           buttons / Tag photo+Remove), and every row's own auto columns
           could ALSO differ slightly from each other depending on which
           condition state was selected. Fixed widths (12.5rem for
           chips, matched to 4 condition buttons at their widest;
           7.5rem for actions, matched to "Tag photo" + "Remove") make
           every grid instance on the page compute the IDENTICAL column
           layout regardless of instance or content — the actual fix;
           these values must stay in sync between the two rules below. */
        .inv-header-row {
            display: grid;
            grid-template-columns: 4.5rem minmax(0,1fr) 12.5rem minmax(0,13rem) 7.5rem;
            gap: 0.5rem;
            padding: 0 0 0.375rem 0;
            border-bottom: 1px solid var(--border);
        }
        .inv-line {
            display: grid;
            grid-template-columns: 4.5rem minmax(0,1fr) 12.5rem minmax(0,13rem) 7.5rem;
            gap: 0.5rem;
            align-items: center;
            min-height: 44px;
            padding: 0.25rem 0;
        }
        /* `order` (needed above for the mobile flex reflow) also feeds
           grid's own auto-placement cursor, even though grid-column is
           explicit here — the cursor still advances in ORDER-modified
           sequence, and a column value LOWER than the previous one in
           that sequence pushes the item to a new row (this is why chips
           landed on row 2 the first time this was written: order was
           left at its mobile value of 4, after actions' mobile order of
           3, so column 3 < the cursor's already-advanced column 5).
           Resetting order to match grid-column here keeps the sequence
           monotonic, so every cell lands in row 1. */
        .inv-cell-qty { grid-column: 1; order: 1; width: auto; }
        .inv-cell-desc { grid-column: 2; order: 2; }
        .inv-cell-chips { grid-column: 3; order: 3; flex-basis: auto; flex-wrap: nowrap; }
        .inv-cell-photos { grid-column: 4; order: 4; }
        .inv-cell-actions { grid-column: 5; order: 5; margin-left: 0; }
    }
</style>
@php
    // §13.10 — the SAME small derivation properties/show.blade.php's own
    // top-of-file PHP block computes for the identity-strip partial. Recomputed here
    // (not shared) because show.blade.php's own copy stays in place there
    // — $thumb and $isMarketable are each read again further down that
    // giant file, so moving their derivation into a shared location this
    // page pulls from would need a bigger, riskier change to that file for
    // no behavioural gain. This block is the ONLY thing duplicated —
    // _property-shell-header.blade.php's actual MARKUP is not.
    $isNew = false; // this route only ever resolves an existing property
    $backToRentals  = (bool) session('corex.lens.properties', false);
    $backToImported = (bool) session('corex.lens.properties_imported', false)
        && \Illuminate\Support\Facades\Route::has('corex.properties.imported-stock');
    $backRoute = $backToImported ? 'corex.properties.imported-stock' : ($backToRentals ? 'corex.rentals.properties.index' : 'corex.properties.index');
    $backLabel = $backToImported ? 'Back to Imported Stock' : ($backToRentals ? 'Back to Rentals' : 'Back to Properties');
    $thumb = $property->thumbFor($property->gallery_images_json[0] ?? ($property->dawn_images_json[0] ?? null));
    $listingTypeLabel = match(strtolower((string) ($property->listing_type ?? 'sale'))) {
        'rental' => 'For Rent',
        default  => 'For Sale',
    };
    $statusLabel = ucwords(str_replace('_', ' ', (string) ($property->status ?: 'Draft')));
    $brandPillStyle = 'background:var(--brand-default); color:#fff; border:none;';
    $sbAddr = $property->buildDisplayAddress();
    $hasRealAddr = $sbAddr !== '' && $sbAddr !== ($property->title ?? '') && $sbAddr !== 'Unknown Property';
    $cmpLive    = $readinessReport->snapshotAt !== null;
    $cmpReady   = $readinessReport->ready && !$cmpLive;
    $cmpLabel   = $cmpLive ? 'LIVE' : ($cmpReady ? 'READY' : 'BLOCKED');
    $cmpPillBg  = $cmpLive ? '#10b981' : ($cmpReady ? 'rgba(0,212,170,.18)' : 'rgba(245,158,11,.18)');
    $cmpPillFg  = $cmpLive ? '#ffffff' : ($cmpReady ? '#047857' : '#b45309');
@endphp
<div class="w-full h-full flex flex-col space-y-4 corex-props-v2">
    @include('corex.properties.partials._property-shell-header', [
        'property' => $property, 'isNew' => $isNew, 'backRoute' => $backRoute, 'backLabel' => $backLabel,
        'thumb' => $thumb, 'hasRealAddr' => $hasRealAddr, 'sbAddr' => $sbAddr, 'brandPillStyle' => $brandPillStyle,
        'listingTypeLabel' => $listingTypeLabel, 'statusLabel' => $statusLabel,
        'cmpPillBg' => $cmpPillBg, 'cmpPillFg' => $cmpPillFg, 'cmpLabel' => $cmpLabel,
        'complianceMode' => 'link', 'showSaveButton' => false,
    ])

    {{-- Sec 13.11 - ONE Alpine scope for the whole tab panel: pill strip and stacked room panels share rooms/lines/activeRoomId on their common ancestor. --}}
    <div class="flex-1 overflow-y-auto prop-tab-panel"
         @if($inventory) x-data="rentalInventoryCapture({{ $inventory->id }}, {{ $property->id }}, '{{ $spaceStoreUrl ?? '' }}', '{{ $seedSpacesFromListingUrl ?? '' }}')" @endif>
        <div id="inv-sticky-shell">
            @include('corex.properties.partials._property-shell-tabs', [
                'property' => $property, 'isNew' => $isNew, 'allDriveDocs' => $allDriveDocs, 'coreMatches' => $coreMatches,
                'mode' => 'link', 'activeTabKey' => 'inventory',
            ])

            @if($inventory)
            {{-- §13.11 — the room-pill strip, now pure quick-navigation
                 (scroll-to, not switch-to) — Johan: "it's not separate
                 tabs, it's just quick navigation to get to that section."
                 Sticks together with the tab bar above it as one group
                 (see #inv-sticky-shell); overflow-x-auto + flex-nowrap is
                 the whole "never wraps" mechanism, same as every other
                 horizontal strip on this page. --}}
            <div x-show="rooms.length" class="flex items-center gap-2 overflow-x-auto px-4 sm:px-6 py-2" style="flex-wrap:nowrap; background:var(--surface); border-bottom:1px solid var(--border);">
                <template x-for="room in rooms" :key="room.id">
                    {{-- Same :style clobber trap as the dot below — the
                         "never wraps" white-space rule has to live INSIDE the
                         one bound :style expression, not as a co-located
                         static style="..." on this same button. --}}
                    <button type="button" @click="scrollToRoom(room.id)"
                            class="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full shrink-0"
                            :style="(room.id === activeRoomId ? 'background:var(--brand-button,#0ea5e9); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary);') + ' white-space:nowrap;'">
                        {{-- Alpine's :style clobber trap (rental-inspections.md
                             §22.3b): a bound :style REPLACES the whole style
                             attribute on every reactive render, so a co-located
                             static style="..." on the same tag silently
                             disappears the moment the binding evaluates. The
                             fixed 8x8 size lives INSIDE the one bound
                             expression instead, never as a separate static
                             attribute on this tag. --}}
                        <span class="rounded-full shrink-0" :style="'width:8px; height:8px; background:' + roomStatusColor(room.id) + ';'"></span>
                        <span x-text="room.label"></span>
                    </button>
                </template>
            </div>
            @endif
        </div>

        <div class="p-4 sm:p-6 space-y-4">

            <div class="min-w-0">
                <h1 class="text-lg font-semibold truncate">Inventory — {{ $property->buildDisplayAddress() }}</h1>
                @if($inventory)
                    <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                        <span class="ds-badge {{ $inventory->status === 'completed' ? 'ds-badge-success' : ($inventory->status === 'cancelled' ? 'ds-badge-danger' : 'ds-badge-muted') }}">
                            {{ ucfirst(str_replace('_', ' ', $inventory->status)) }}
                        </span>
                    </p>
                @endif
            </div>

            @if(!$inventory)
                {{-- §0a/§15 — RentalInventory::resolveOrStartFor() now always resolves
                     or starts an inventory for a real Property (a sale property, or any
                     rental property between tenancies, gets a property-level inventory
                     with no lease attached) — this branch should be unreachable. Kept as
                     an honest fallback rather than a silent blank page if it ever isn't. --}}
                <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                    Something went wrong loading this property's inventory. Refresh the page — if this keeps happening, contact support.
                </div>
            @else
                @if(in_array($inventory->status, ['completed', 'cancelled']))
                    <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">
                        This inventory is {{ $inventory->status }} and read-only here.
                        <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="font-semibold" style="color: var(--brand-button,#0ea5e9);">Open the full record</a>
                        @if($inventory->status === 'completed')
                            for signatures and the move-out comparison.
                        @endif
                    </div>
                @else
                    <div class="flex items-center justify-end">
                        <a href="{{ route('corex.rental-inventories.show', $inventory) }}" class="text-xs font-semibold" style="color: var(--brand-button,#0ea5e9);">Signatures &amp; complete →</a>
                    </div>
                @endif

                {{-- Add space — Johan: "we specced inventory being blank then you can
                     create the spaces same as with inspections." Same write path
                     the Inspection Items section's own "Space (new room)" control
                     uses (RentalInspectionRecordingController::storeItem(), kind=
                     space) — a room created here is the SAME PropertyRoom row
                     inspections sees, not a second space model. Always available,
                     not just when blank — an agent adds more spaces as the walk-
                     through finds them, same as on the inspection side. --}}
                <div class="rounded-md p-3" style="background: var(--surface-2);">
                    <template x-if="!rooms.length">
                        <div class="pb-2 space-y-2">
                            <p class="text-xs" style="color: var(--text-secondary);">
                                This property has no spaces set up yet — add the first one below.
                            </p>
                            {{-- Johan, 2026-09-28: "why ask the agent to retype something we
                                 know." One click, seeded from the listing's own advertising
                                 Spaces (bedrooms, bathrooms, flatlets, etc.) — the SAME source
                                 Inspections' own "Build from advertising details" button reads.
                                 Never silent: an explicit click, a confirm dialog, and an
                                 honest error (e.g. no advertising Spaces yet) if it can't. --}}
                            <button type="button" :disabled="seedBusy" @click="seedSpacesFromListing()"
                                    class="text-xs font-semibold rounded-md text-white px-3 py-1.5" style="background:var(--brand-button,#0ea5e9);"
                                    x-text="seedBusy ? 'Creating…' : 'Create spaces from listing'"></button>
                        </div>
                    </template>
                    <form @submit.prevent="addSpace()" class="flex items-end gap-2 flex-wrap">
                        <div>
                            <label class="text-xs font-semibold block" style="color: var(--text-secondary);">Room type</label>
                            <select x-model="newSpace.space_type" class="prop-input" style="max-width:11rem;">
                                <option value="">Room type…</option>
                                @foreach($spaceTypes as $spaceType)
                                    <option value="{{ $spaceType }}">{{ $spaceType }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-1" style="min-width:10rem;">
                            <label class="text-xs font-semibold block" style="color: var(--text-secondary);">Name</label>
                            <input type="text" x-model="newSpace.label" placeholder="e.g. Bedroom 1" maxlength="191"
                                   class="prop-input w-full" @keydown.enter.prevent="addSpace()">
                        </div>
                        <button type="submit" :disabled="spaceBusy || !newSpace.label.trim() || !newSpace.space_type"
                                class="text-xs font-semibold rounded-md text-white px-3 py-1.5" style="background:var(--brand-button,#0ea5e9);"
                                x-text="spaceBusy ? 'Adding…' : 'Add space'"></button>
                    </form>
                    <p x-show="spaceError" x-cloak class="text-xs pt-1" style="color:#ef4444;" x-text="spaceError"></p>
                </div>

                {{-- §13 — "Copy from last inventory." Only rendered when the server
                     confirmed a prior inventory genuinely exists for this property
                     (RentalInventory::priorInventory()) — the endpoint is the real
                     gate either way, this just avoids offering a control that can
                     only ever 404. --}}
                @if($hasPriorInventory)
                <div class="flex items-center justify-between gap-2 rounded-md p-3" style="background: var(--surface-2);">
                    <p class="text-xs" style="color: var(--text-secondary);">This property has an earlier inventory on record.</p>
                    <button type="button" :disabled="copyBusy" @click="copyFromLastInventory()"
                            class="text-xs font-semibold rounded-md px-3 py-1.5 shrink-0" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);"
                            x-text="copyBusy ? 'Copying…' : 'Copy from last inventory'"></button>
                </div>
                <p x-show="copyError" x-cloak class="text-xs" style="color:#ef4444;" x-text="copyError"></p>
                @endif

                {{-- §13.11 — every room stacked down the page, in the SAME
                     order the pill strip lists them. Each panel is
                     addressable by id (room-panel-N) for the pill strip's
                     scrollToRoom() and the scrollspy observer below. --}}
                <div class="space-y-4">
                    <template x-for="room in rooms" :key="room.id">
                        <div class="inv-room-panel rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);"
                             :id="'room-panel-' + room.id" :data-room-id="room.id">
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="text-sm font-semibold" x-text="room.label"></h2>
                                <span class="text-xs shrink-0" style="color: var(--text-muted);" x-text="linesFor(room.id).length + ' item' + (linesFor(room.id).length === 1 ? '' : 's')"></span>
                            </div>

                            {{-- §13.9 — one column header row per room, labelling the
                                 grid columns below so they're not repeated per line;
                                 desktop only (.inv-header-row is display:none below
                                 1024px, since mobile wraps to 2 lines and a header
                                 wouldn't line up with either). The blank 5th cell keeps
                                 it aligned with the actions column, which has no label. --}}
                            <div class="inv-header-row" aria-hidden="true">
                                <span class="text-[11px] font-semibold uppercase" style="color: var(--text-muted); letter-spacing:0.02em;">Qty</span>
                                <span class="text-[11px] font-semibold uppercase" style="color: var(--text-muted); letter-spacing:0.02em;">Description</span>
                                <span class="text-[11px] font-semibold uppercase" style="color: var(--text-muted); letter-spacing:0.02em;">Condition</span>
                                <span class="text-[11px] font-semibold uppercase" style="color: var(--text-muted); letter-spacing:0.02em;">Photos</span>
                                <span></span>
                            </div>

                            {{-- Line items. §0b+2026-09-26 — every committed line is a
                                 live grid cell (arrow-key navigation, onCellKeydown()
                                 below). §13.9 — qty/description/condition-chips/photo-
                                 strip/actions now sit on ONE row on desktop (.inv-line,
                                 >=1024px), wrapping to 2 lines only below that — see
                                 the <style> block above. The separate "(N photos)" text
                                 next to the description is dropped; the strip already
                                 shows the photos, with a small "N×" badge only when the
                                 strip has more photos than comfortably fit (>3). --}}
                            <div>
                                <template x-for="line in linesFor(room.id)" :key="line.id">
                                    <div class="inv-line text-sm">
                                        {{-- No inline width:100% here (unlike the old
                                             markup) — that would set an explicit `width`
                                             on the element, which flexbox's flex-basis:
                                             auto then reads as this item's flex-basis,
                                             forcing it to claim the WHOLE mobile flex
                                             line width and wrap. The .inv-cell-qty/-desc
                                             classes own sizing in both layouts now. --}}
                                        <input type="text" inputmode="numeric" pattern="[0-9]*" x-model="line.quantity"
                                               class="prop-input inv-cell-qty"
                                               :data-row="'line-' + line.id" data-cell="qty"
                                               @keydown="onCellKeydown($event, room, 'line', line, 'qty')"
                                               @blur="commitRow(room, 'line', line)">

                                        <input type="text" x-model="line.description"
                                               class="prop-input inv-cell-desc"
                                               :data-row="'line-' + line.id" data-cell="description"
                                               @keydown="onCellKeydown($event, room, 'line', line, 'description')"
                                               @blur="commitRow(room, 'line', line)">

                                        {{-- Forces the mobile line-break before chips —
                                             see .inv-linebreak in the <style> block for
                                             why this can't just be flex-basis:100% on
                                             .inv-cell-chips itself. --}}
                                        <div class="inv-linebreak" aria-hidden="true"></div>

                                        {{-- §13 — condition chips, the agency-configurable
                                             move-in vocabulary. A plain click sets it —
                                             no separate save step, matching every other
                                             control on this page. --}}
                                        <div class="inv-cell-chips">
                                            <template x-for="state in conditionStates" :key="state.key">
                                                <button type="button" tabindex="-1" @click="setLineCondition(line, state.key)"
                                                        class="text-[11px] font-semibold px-2 py-0.5 rounded-full shrink-0"
                                                        :style="line.condition_key === state.key ? 'background:var(--brand-button,#0ea5e9); color:#fff;' : 'background:var(--surface-2); color:var(--text-secondary);'"
                                                        x-text="state.label"></button>
                                            </template>
                                        </div>

                                        {{-- §13, Johan's approved mockup — "Photos are a
                                             horizontal strip on the line, and a photo
                                             uploads THE MOMENT it is selected. No
                                             staging, ever." A bare file input behind a
                                             "+" tile, same reasoning as every other
                                             immediate-upload control on this page: the
                                             native file picker is free, no custom camera
                                             UI needed. Uploads AND tags to this line in
                                             ONE request (storePhotos() with
                                             rental_inventory_line_id) — never a
                                             stage-then-tag two-step. §13.9 — thumbnails
                                             shrunk 44px -> 36px to fit the row; the
                                             strip itself scrolls (overflow-x-auto) when
                                             there are more photos than fit, and a "N×"
                                             badge appears alongside it past 3 photos as
                                             an at-a-glance overflow signal. --}}
                                        <div class="inv-cell-photos">
                                            <span x-show="photosForLine(line).length > 3" x-cloak class="text-[10px] font-semibold shrink-0" style="color: var(--text-muted);" x-text="photosForLine(line).length + '×'"></span>
                                            <template x-for="photo in photosForLine(line)" :key="photo.id">
                                                <img :src="photo.storage_path" class="rounded-md object-cover shrink-0" style="width:36px; height:36px; background:var(--surface-3);" alt="Item photo">
                                            </template>
                                            <label class="flex items-center justify-center rounded-md cursor-pointer shrink-0" style="width:36px; height:36px; background:var(--surface-2); border:1px dashed var(--border); font-size:14px; color:var(--text-muted);">
                                                <span>+</span>
                                                <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" tabindex="-1"
                                                       @change="uploadPhotoToLine(line, $event.target.files); $event.target.value=''">
                                            </label>
                                        </div>

                                        <div class="inv-cell-actions">
                                            {{-- tabindex="-1" on both: mouse-clickable, never a Tab/keyboard stop (§2 of the grid spec). --}}
                                            <button type="button" tabindex="-1" x-show="photoUploader().roomPhotos(room.id).length" @click="openTagger(line)" class="text-xs font-semibold shrink-0" style="color: var(--brand-button,#0ea5e9);">Tag photo</button>
                                            <button type="button" tabindex="-1" @click="retireLine(line)" class="text-xs font-semibold shrink-0" style="color: var(--ds-crimson,#c41e3a);">Remove</button>
                                        </div>
                                    </div>
                                </template>
                                <p x-show="!linesFor(room.id).length" class="text-xs py-1" style="color: var(--text-muted);">No items yet.</p>
                            </div>

                            {{-- §12, made prominent 2026-09-28 (Johan, property 5294) —
                                 "every space needs a VISIBLE 'Nothing in this room'
                                 button right on the space, next to Add item." Was
                                 buried as plain text inside the "No items yet." row
                                 above; moved to its own row directly beside the
                                 always-open Add row below, styled as a real button.
                                 Same rule as before: only offered while the room
                                 genuinely has no items — once a real item exists the
                                 room already satisfies the completion gate through
                                 that line, and marking it empty too would be
                                 contradictory. NOW reversible (Johan's explicit ask)
                                 via the new DELETE .../mark-empty endpoint — counts as
                                 checked either way, exactly like the POST already did. --}}
                            <div x-show="!linesFor(room.id).length" class="flex items-center gap-2 pb-2">
                                <template x-if="!isRoomMarkedEmpty(room.id)">
                                    <button type="button" tabindex="-1" :disabled="markRoomBusy[room.id]"
                                            @click="markRoomEmpty(room)"
                                            class="text-xs font-semibold rounded-md px-3 py-1.5"
                                            style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);"
                                            x-text="markRoomBusy[room.id] ? 'Marking…' : 'Nothing in this room'"></button>
                                </template>
                                <template x-if="isRoomMarkedEmpty(room.id)">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-semibold rounded-md px-3 py-1.5" style="background:color-mix(in srgb, var(--ds-green,#16a34a) 12%, transparent); color: var(--ds-green,#16a34a);">&#10003; Nothing in this room</span>
                                        <button type="button" tabindex="-1" :disabled="markRoomBusy[room.id]"
                                                @click="unmarkRoomEmpty(room)"
                                                class="text-xs font-semibold" style="color: var(--text-secondary); text-decoration:underline;"
                                                x-text="markRoomBusy[room.id] ? 'Undoing…' : 'Undo'"></button>
                                    </div>
                                </template>
                            </div>

                            {{-- Add line — spreadsheet-grid capture (Johan,
                                 2026-09-25/26), §13: ALWAYS open, already ready to type
                                 into — no "add item" click ever stands between the
                                 agent and a blank line. See onCellKeydown() for the
                                 full contract (Tab/Enter/→-at-end commits and moves to
                                 the next row's qty; ←-at-start moves to the previous
                                 row's description; ↑/↓ always move a row in the same
                                 column). This row is always the blank bottom row —
                                 committing resets it in place SYNCHRONOUSLY, before the
                                 save request resolves, so a fast typist filling the
                                 next row never races their own in-flight save. §13.9 —
                                 reuses .inv-line so its Qty/Description columns line up
                                 exactly under the committed lines above (and under the
                                 header row); it has no chips/photos cells, but the
                                 explicit grid-column placement on qty/desc/actions (not
                                 auto-placement) keeps them aligned to the same columns
                                 regardless. --}}
                            <form @submit.prevent="commitDraftRow(room)" class="inv-line" style="padding-top:0.5rem;">
                                <input type="text" inputmode="numeric" pattern="[0-9]*" x-model="newLine[room.id].quantity" placeholder="Qty"
                                       class="prop-input inv-cell-qty"
                                       data-row="draft" data-cell="qty"
                                       @keydown="onCellKeydown($event, room, 'draft', null, 'qty')"
                                       @blur="commitRow(room, 'draft', null)">
                                <input type="text" x-model="newLine[room.id].description" placeholder="e.g. White wooden headboard"
                                       class="prop-input inv-cell-desc"
                                       data-row="draft" data-cell="description"
                                       @keydown="onCellKeydown($event, room, 'draft', null, 'description')"
                                       @blur="commitRow(room, 'draft', null)">
                                <div class="inv-cell-actions">
                                    <button type="submit" tabindex="-1" :disabled="lineBusy[room.id]"
                                            class="text-xs font-semibold rounded-md text-white" style="background:var(--brand-button,#0ea5e9); padding:0.375rem 0.75rem;">Add</button>
                                </div>
                            </form>
                            {{-- §7 — quiet, in-place feedback, never a toast: a
                                 one-line fade that disappears on its own. --}}
                            <p x-show="lineSavedFlash[room.id]" x-cloak x-transition.opacity.duration.400ms
                               class="text-xs" style="color:#16a34a;">&#10003; Saved</p>
                            <p x-show="lineSaveError[room.id]" x-cloak
                               class="text-xs" style="color:var(--ds-crimson,#c41e3a);">Couldn't save that item — check your connection and try again.</p>

                            {{-- Room-level general photos — unchanged from before this
                                 pass: photos of the room overall, not tied to any one
                                 item (a different concept from the per-line strips
                                 above, which §13 adds alongside this, not instead of
                                 it — the many-to-many line-photo tag still needs a pool
                                 of untagged/general photos to tag FROM via the modal
                                 below). §4a: adopts the SAME batched uploader and the
                                 SAME gallery-sized, count-clipped layout rental-
                                 inspections settled on. --}}
                            <div class="pt-2 space-y-1" style="border-top:1px solid var(--border);">
                                <template x-if="photoUploader().roomPhotos(room.id).length">
                                    <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                                        <template x-for="photo in (roomPhotosExpanded[room.id] ? photoUploader().roomPhotos(room.id) : photoUploader().roomPhotos(room.id).slice(0, 3))" :key="photo.id">
                                            <div class="relative rounded-md overflow-hidden" style="aspect-ratio:1/1; background:var(--surface-3);">
                                                <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer" @click="openTaggerForPhoto(room, photo)" alt="Room photo">
                                                <span x-show="photo.lines && photo.lines.length" class="absolute top-0.5 left-0.5 text-[10px] font-bold text-white rounded-full flex items-center justify-center" style="width:16px; height:16px; background:var(--brand-button,#0ea5e9);" x-text="photo.lines.length"></span>
                                                <button type="button" tabindex="-1" @click.stop="if (confirm('Archive this photo?')) photoUploader().archivePhoto(photo.id)"
                                                        class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                                        style="background:var(--ds-crimson,#c41e3a); color:#fff; line-height:1;" title="Archive">&times;</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <div class="flex items-center gap-2 flex-wrap pt-1">
                                    <button type="button" tabindex="-1" x-show="photoUploader().roomPhotos(room.id).length > 3"
                                            @click="roomPhotosExpanded[room.id] = !roomPhotosExpanded[room.id]"
                                            class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                                            x-text="roomPhotosExpanded[room.id] ? 'Show less' : ('Show all ' + photoUploader().roomPhotos(room.id).length)"></button>
                                    <label class="text-xs font-semibold px-3 py-1.5 rounded-md cursor-pointer inline-flex items-center gap-1" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                                        <span>&#128247;</span>
                                        <span>Add photo(s)</span>
                                        <input type="file" accept="image/*,.heic,.heif" multiple class="hidden" @change="photoUploader().uploadFiles($event.target.files, { property_room_id: room.id }); $event.target.value = ''">
                                    </label>
                                </div>
                                {{-- Same batch progress/retry rows as the inspections
                                     recording surface — real upload-progress percent,
                                     a batch that fails is independently retryable. --}}
                                <template x-for="(batch, idx) in photoUploader().uploadBatches.filter(b => b.status !== 'done' && b.extraFields && Number(b.extraFields.property_room_id) === Number(room.id))" :key="idx">
                                    <div class="flex items-center justify-between gap-2 text-xs px-2 py-1 rounded-md"
                                         :style="batch.status === 'failed' ? 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);' : 'background:var(--surface-2);'">
                                        <span :style="batch.status === 'failed' ? 'color:var(--ds-crimson);' : 'color:var(--text-secondary);'"
                                              x-text="batch.status === 'failed' ? (batch.files.length + ' photo(s) failed — ' + batch.error) : ('Uploading ' + batch.files.length + ' photo(s)… ' + (batch.percent || 0) + '%')"></span>
                                        <button type="button" tabindex="-1" x-show="batch.status === 'failed'" @click="photoUploader().retryBatch(batch)"
                                                class="text-xs font-semibold underline" style="color:var(--text-secondary);">Retry</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Tag-to-photo picker — opened per line or per photo; toggling a
                     checkbox tags/untags immediately, same autosave rule as everywhere
                     else on this page. --}}
                <div x-show="tagger.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" style="background: rgba(0,0,0,0.5);" @click.self="tagger.open = false">
                    <div class="w-full sm:max-w-sm rounded-t-lg sm:rounded-lg p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border); max-height: 80vh; overflow-y: auto;">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold">Tag to a photo</h3>
                            <button type="button" @click="tagger.open = false" class="text-xs" style="color: var(--text-muted);">Close</button>
                        </div>
                        <template x-if="tagger.mode === 'line'">
                            <div class="flex flex-wrap gap-2">
                                <template x-for="photo in tagger.photos" :key="photo.id">
                                    <button type="button" @click="toggleTag(tagger.line, photo)" class="relative shrink-0" style="width:64px; height:64px;">
                                        <img :src="photo.storage_path" class="w-full h-full object-cover rounded-md" :style="isTagged(tagger.line, photo) ? 'border:2px solid var(--brand-button,#0ea5e9);' : 'border:1px solid var(--border);'" alt="Room photo">
                                    </button>
                                </template>
                            </div>
                        </template>
                        <template x-if="tagger.mode === 'photo'">
                            <div class="space-y-1">
                                <template x-for="line in tagger.lines" :key="line.id">
                                    <label class="flex items-center gap-2 py-1 text-sm">
                                        <input type="checkbox" :checked="isTagged(line, tagger.photo)" @change="toggleTag(line, tagger.photo)">
                                        <span x-text="line.quantity + 'x ' + line.description"></span>
                                    </label>
                                </template>
                                <p x-show="!tagger.lines.length" class="text-xs" style="color: var(--text-muted);">No items in this room yet.</p>
                            </div>
                        </template>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@if($inventory)
@push('scripts')
<script>
function rentalInventoryCapture(inventoryId, propertyId, spaceStoreUrl, seedSpacesFromListingUrl) {
    return {
        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        baseUrl: `/corex/rental-inventories/${inventoryId}`,
        seedSpacesFromListingUrl,
        spaceStoreUrl,
        rooms: @json($roomsForJs),
        lines: @json($linesForJs),
        // §13 — the capture-time condition chip vocabulary, agency-
        // configurable, never hardcoded.
        conditionStates: @json($conditionStates),
        // §12 — the completion gate's "nothing in this room" state. A Set of
        // property_room_ids an agent has explicitly confirmed empty, the
        // same distinction rental-inspections' markRoomNa() draws per room.
        markedEmptyRoomIds: new Set(@json($roomMarksForJs).map(m => Number(m.property_room_id))),
        markRoomBusy: {},

        // §13.11 — every room now renders stacked (x-for over `rooms`),
        // never one room's panel at a time; activeRoomId here means ONLY
        // "which pill is highlighted," driven by whichever room the agent
        // last clicked (scrollToRoom()) or, once scrolling starts, by the
        // scrollspy observer below — never panel visibility. Johan: "it's
        // not separate tabs, it's just quick navigation to get to that
        // section."
        activeRoomId: null,
        // §13.14 fix, 2026-09-27 — conductor's real-browser report:
        // "Bedroom 2" was highlighted on load while "Bedroom 1" (the
        // genuinely topmost room, scrollTop 0) sat at the top of the
        // screen. Root cause, found by checking exactly what the
        // conductor asked to check — "stored/remembered room" vs "the
        // scrollspy's own first callback": it was the FORMER. This used
        // to call pickInitialActiveRoom() (below), which picks by
        // COMPLETION STATUS ("needs attention first" — first not-opened
        // room, else first part-done) — a "remembered preference" that
        // has nothing to do with scroll position and can name ANY room
        // in the list, not necessarily the first one. The doc comment
        // right here claimed "the scrollspy observer corrects this
        // within a frame of real scroll position" — that assumption
        // doesn't reliably hold in practice (IntersectionObserver's own
        // initial callback timing/ordering isn't something this screen
        // controls closely enough to depend on for correctness). Fixed
        // by not depending on it at all: on load, with nothing scrolled
        // yet, the FIRST room in DOM order IS what's genuinely visible
        // at the top of the panel — so that's what gets set directly,
        // synchronously, with no dependency on any async correction.
        initFirstActiveRoom() {
            this.activeRoomId = this.rooms.length ? this.rooms[0].id : null;
        },
        // Kept for copyFromLastInventory()'s own use below — after a bulk
        // copy (a discrete action, not a page load), landing attention on
        // whatever most needs it is still a reasonable, separate choice
        // from "what's visible right now." Same "needs attention first"
        // priority as before: first not-opened room, else first
        // part-done, else just the first room.
        pickInitialActiveRoom() {
            if (!this.rooms.length) { this.activeRoomId = null; return; }
            const notOpened = this.rooms.find(r => this.roomStatus(r.id) === 'not_opened');
            if (notOpened) { this.activeRoomId = notOpened.id; return; }
            const partDone = this.rooms.find(r => this.roomStatus(r.id) === 'part_done');
            if (partDone) { this.activeRoomId = partDone.id; return; }
            this.activeRoomId = this.rooms[0].id;
        },
        // §13.12 fix, 2026-09-27 — cc1's report from a real click on the
        // deployed page: scrollToRoom() fired but NOTHING moved (window.
        // scrollY stayed 0 AND the target's own on-screen position was
        // unchanged — not just "scrolled the wrong container", genuinely
        // inert. §13.13 fix, 2026-09-27 — cc1's SECOND real-browser
        // report, this time against the real property shell (5792, not
        // a fixture): the round-1 fix's ancestor-walk (starting from
        // #inv-sticky-shell's parent) never landed on .prop-tab-panel on
        // the real page, even though cc1's own direct measurement
        // confirmed .prop-tab-panel IS the one genuinely-scrollable
        // element there (scrollHeight 3354 > clientHeight 614). Rather
        // than keep debugging WHY that walk failed on a page this
        // environment can't reproduce read-write, this version stops
        // pretending the scroll container is a mystery to be
        // rediscovered: .prop-tab-panel IS the intended scroll container
        // by design (§13.10 built it that way on purpose — the app
        // shell's own "only the tab panel scrolls" pattern, reused
        // verbatim from properties/show.blade.php). Goes straight to
        // that known selector first; the walk survives only as a
        // defensive fallback if that selector somehow isn't genuinely
        // scrollable.
        scrollToRoom(id) {
            this.activeRoomId = id;
            const el = document.getElementById('room-panel-' + id);
            if (!el) return;
            const offset = this._stickyOffsetPx();
            const container = this._scrollContainer();
            if (container === window) {
                const top = Math.max(0, el.getBoundingClientRect().top + window.scrollY - offset);
                window.scrollTo({ top, behavior: 'smooth' });
            } else {
                const top = Math.max(0, container.scrollTop + el.getBoundingClientRect().top - container.getBoundingClientRect().top - offset);
                container.scrollTo({ top, behavior: 'smooth' });
            }
        },
        // §13.13 fix — .prop-tab-panel is the KNOWN, named scroll
        // container this screen builds (§13.10), checked first by an
        // actual scrollability test (scrollHeight > clientHeight), not
        // rediscovered by walking up from an arbitrary starting point.
        // The walk (from the sticky shell's own parent, checking BOTH
        // `overflow` and `overflow-y` computed values — round 1 only
        // checked `overflow-y`, which the real page's failure suggests
        // may not have been the actual property in effect there) and the
        // #appScroll/window fallbacks stay only as defensive layers for
        // whatever this page's own selector can't explain.
        _scrollContainer() {
            const panel = document.querySelector('.prop-tab-panel');
            if (panel && panel.scrollHeight > panel.clientHeight + 1) return panel;
            let el = document.getElementById('inv-sticky-shell')?.parentElement ?? null;
            while (el && el !== document.documentElement && el !== document.body) {
                if (el.scrollHeight > el.clientHeight + 1) {
                    const style = getComputedStyle(el);
                    if (style.overflowY === 'auto' || style.overflowY === 'scroll' || style.overflow === 'auto' || style.overflow === 'scroll') {
                        return el;
                    }
                }
                el = el.parentElement;
            }
            const appScroll = document.getElementById('appScroll');
            if (appScroll && appScroll.scrollHeight > appScroll.clientHeight + 1) return appScroll;
            return window;
        },
        // The sticky tab-bar+pill-strip group's REAL rendered height,
        // read fresh every time rather than trusting the CSS custom
        // property to have been set yet (updateStickyOffset() publishes
        // the same number for scroll-margin-top's use, on a $nextTick
        // delay — this reads the DOM directly so scrollToRoom/scrollspy
        // never race that).
        _stickyOffsetPx() {
            const shell = document.getElementById('inv-sticky-shell');
            return shell ? shell.offsetHeight : 160;
        },
        // §13 — the chip's status dot. [design call, flagged rather than
        // silently assumed]: Inventory has no per-item checklist the way
        // Inspections does, so "part done" here means "has items, but not
        // every one of them has a condition picked yet" — a real, actionable
        // signal (something is genuinely left to do), not an arbitrary
        // three-way split. "captured" is the SAME condition markCompleted()
        // (§12) already accepts for this room (a mark, or at least one
        // line) PLUS every line having a condition — so a green dot always
        // means "this room would not block completion AND every item in it
        // has been graded," never just the bare minimum.
        roomStatus(roomId) {
            if (this.isRoomMarkedEmpty(roomId)) return 'captured';
            const lines = this.linesFor(roomId);
            if (!lines.length) return 'not_opened';
            return lines.every(l => l.condition_key) ? 'captured' : 'part_done';
        },
        roomStatusColor(roomId) {
            const status = this.roomStatus(roomId);
            if (status === 'captured') return '#16a34a';
            if (status === 'part_done') return '#d97706';
            return '#9ca3af';
        },

        openRooms: {},
        newLine: {},
        lineBusy: {},
        // §"spreadsheet-speed capture" (Johan, 2026-09-25/26) — per-room
        // quiet-save feedback (§7) and per-line dirty-check snapshots for
        // the arrow-key grid (keyed by real line id, across all rooms —
        // not per-room, since a line id is already globally unique).
        lineSavedFlash: {},
        lineSaveError: {},
        lineSnapshots: {},
        // §11.2/§12 investigation, 2026-09-27 — an existing line's commit can
        // now fire twice in quick succession: once explicitly (a keydown-
        // driven Tab/Enter/arrow commit) and once implicitly (the native
        // `blur` that same focus change also triggers, now wired to commit
        // too — see the new @blur bindings below, added to close the exact
        // gap that investigation found: a mouse click away from an edited
        // cell, with no Tab/Enter in between, previously discarded the edit
        // with zero warning). Both calls see the same not-yet-saved values,
        // because the first call's PUT is still in flight when blur fires,
        // so without this guard both would send an identical, redundant
        // request. Keyed by line id -> the in-flight request's own value
        // signature, not a bare busy boolean, so a GENUINE second edit
        // (different values) is never suppressed.
        _pendingLineCommits: {},
        newSpace: { space_type: '', label: '' },
        spaceBusy: false,
        spaceError: '',
        seedBusy: false,
        // §13 — "Copy from last inventory."
        copyBusy: false,
        copyError: '',
        // §4a — same photo layout discipline as inspections (rental-
        // inspections.md §20.14.3): clipping is COUNT-based (first 3, "Show
        // all N"), never height-based — a height clip is what cut real
        // tiles in half on the deployed box the first time this shipped.
        roomPhotosExpanded: {},
        tagger: { open: false, mode: null, line: null, photo: null, photos: [], lines: [] },

        init() {
            this.rooms.forEach(r => this._initRoomState(r));
            // §4c/qty-nullable (Johan, 2026-10-02) — a blank quantity is
            // `null` server-side but ALWAYS '' client-side, never null: this
            // keeps every x-model-bound qty input dealing with one blank
            // representation instead of reasoning about null vs ''.
            this.lines.forEach(l => {
                l.quantity = this.normalizeQtyDisplay(l.quantity);
                this.lineSnapshots[l.id] = { quantity: l.quantity, description: l.description };
            });
            this.initFirstActiveRoom();
            // Johan, 2026-09-28 — "each clickable to jump to that space."
            // The show/sign page links its own unvisited-room list here as
            // `#room-panel-{id}`, the SAME id scrollToRoom()/the scrollspy
            // already address panels by. Overrides initFirstActiveRoom()'s
            // own default pick when a specific room was actually requested.
            // $nextTick — the sticky-offset measurement below (and the
            // panels themselves) need one render pass to exist first.
            const requestedRoomMatch = window.location.hash.match(/^#room-panel-(\d+)$/);
            if (requestedRoomMatch) {
                const requestedRoomId = Number(requestedRoomMatch[1]);
                this.$nextTick(() => this.scrollToRoom(requestedRoomId));
            }
            // §13.11/§13.12 — the sticky tab-bar+pill-strip group's REAL
            // rendered height, published as a CSS custom property so
            // every room panel's scroll-margin-top (set in the <style>
            // block) stays correct even if that group's height changes (a
            // longer property address wrapping to 2 lines, a narrower
            // phone, etc.) — measured, never guessed. $nextTick because
            // the pill strip's own x-show/x-for need one render pass to
            // have real dimensions. §13.12 fix: _setupScrollSpy() moved
            // into this SAME $nextTick, after the offset measurement —
            // it previously ran synchronously here, one tick BEFORE
            // _updateStickyOffset(), so its rootMargin used a hardcoded
            // guess instead of the real value. On the real deployed page
            // (taller identity strip / longer address than this branch's
            // own private-schema test fixture had) that guess undershot
            // the sticky group's true height, which is very likely why
            // cc1 saw "Bedroom 2" highlighted on load instead of the
            // genuinely-topmost "Bedroom 1".
            this.$nextTick(() => {
                this._updateStickyOffset();
                this._setupScrollSpy();
            });
            window.addEventListener('resize', () => this._updateStickyOffset());
            // §11.2 investigation, 2026-09-27 — closing an existing item's
            // edit down to the ONE commit path this grid already had (a
            // Tab/Enter/arrow-boundary keydown) meant leaving the page
            // entirely (closing the tab, hitting back, switching apps on a
            // phone) while still focused in a cell never fired that path,
            // and silently discarded the edit. `visibilitychange`/
            // `beforeunload` are the two hooks a keydown-only contract can
            // never cover, because neither one is a keydown — the tab is
            // simply gone. `keepalive: true` on the flush request (see
            // flushDirtyLines()) is what lets that request actually survive
            // the page going away, which a plain fetch() does not guarantee.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.flushDirtyLines();
            });
            window.addEventListener('beforeunload', () => this.flushDirtyLines());
        },
        _updateStickyOffset() {
            const el = document.getElementById('inv-sticky-shell');
            if (el) document.documentElement.style.setProperty('--inv-sticky-offset', el.offsetHeight + 'px');
        },
        // §13.11/§13.12 — "scrolling updates the active pill." rootMargin
        // biases toward the room panel sitting just below the sticky
        // group. §13.12 fix: this used to be a hardcoded -150px guess —
        // cc1 reported "Bedroom 2" highlighted on load with "Bedroom 1"
        // genuinely at the top, and a rootMargin that under-reserves the
        // sticky group's real height (this branch's own private-schema
        // test fixture had a much shorter identity strip than a real
        // property) is exactly the failure shape: the topmost room's
        // panel gets excluded from the shrunk observation zone while the
        // next one down qualifies. Now reads the SAME real, measured
        // height _stickyOffsetPx() and scrollToRoom() both use, so the
        // scroll target and the scrollspy trigger zone can't drift apart
        // from each other the way two independent guesses could. The
        // -60% bottom margin is untouched: a room barely peeking in at
        // the very bottom of the screen still shouldn't steal the
        // highlight from the one actually being read.
        //
        // §13.13 fix — `root` is now explicitly `.prop-tab-panel`
        // (conductor: "Scrollspy must observe with root = that same
        // panel"), not the default `null` (browser viewport). Two real
        // consequences, not just cosmetic: (1) IntersectionObserver only
        // ever fires for a target whose scroll ancestor chain actually
        // includes `root` — with `root: null` a target nested inside a
        // scrollable panel the observer doesn't know about can still be
        // observed, but its reported intersection can desync from what a
        // person actually sees scroll past, exactly the "Bedroom 2
        // highlighted while Bedroom 1 is visibly at the top" shape cc1
        // reported; (2) rootMargin's `-60%` is a percentage OF THE ROOT's
        // own size — against the real viewport (900px+) that's a very
        // different pixel value than against this panel's actual
        // clientHeight (614px, per cc1's own measurement), which changes
        // how trigger-happy the "next room" hand-off is. Reuses
        // _scrollContainer() (the same element scrollToRoom() scrolls) so
        // the two can't name a different container from each other again.
        _setupScrollSpy() {
            if (!this.rooms.length || typeof IntersectionObserver === 'undefined') return;
            const container = this._scrollContainer();
            const observer = new IntersectionObserver((entries) => {
                const visible = entries.filter(e => e.isIntersecting);
                if (!visible.length) return;
                visible.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                const id = Number(visible[0].target.dataset.roomId);
                if (id) this.activeRoomId = id;
            }, { root: container === window ? null : container, rootMargin: `-${this._stickyOffsetPx()}px 0px -60% 0px`, threshold: 0 });
            this.rooms.forEach(r => {
                const el = document.getElementById('room-panel-' + r.id);
                if (el) observer.observe(el);
            });
        },
        // §11.2 — every existing line whose current value differs from its
        // last-saved snapshot, PLUS any trailing draft row with an
        // un-submitted description, each sent as a best-effort keepalive
        // request so it survives the page unloading mid-request (a plain
        // fetch() gives no such guarantee once the tab is actually closing —
        // `keepalive: true` is what lets the browser finish sending it).
        flushDirtyLines() {
            this.lines.forEach(line => {
                const description = (line.description || '').trim();
                if (!description) return;
                const quantity = this.normalizeQtyDisplay(line.quantity);
                const snap = this.lineSnapshots[line.id] || { quantity, description: line.description };
                if (snap.quantity === quantity && snap.description === description) return;
                fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    keepalive: true,
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_room_id: line.property_room_id, quantity: this.qtyForPayload(quantity), description }),
                }).then(() => { this.lineSnapshots[line.id] = { quantity, description }; }).catch(() => {});
            });
            // The trailing draft row has the identical gap (§11.2): typed but
            // never committed, and about to disappear along with the tab.
            // Same request shape commitDraftRow() itself fires, just also
            // reachable from the unload path.
            Object.keys(this.newLine).forEach(roomId => {
                const form = this.newLine[roomId];
                const description = (form.description || '').trim();
                if (!description) return;
                const payload = { property_room_id: Number(roomId), quantity: this.qtyForPayload(form.quantity), description };
                this.newLine[roomId] = { quantity: '', description: '' };
                fetch(`${this.baseUrl}/lines`, {
                    method: 'POST',
                    keepalive: true,
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                }).then(async (res) => {
                    if (!res.ok) return;
                    const line = await res.json();
                    const quantity = this.normalizeQtyDisplay(line.quantity);
                    this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                    this.lineSnapshots[line.id] = { quantity, description: line.description };
                }).catch(() => {});
            });
        },
        // Extracted so a room added live via addSpace() (never present at
        // page-load init()) gets the exact same per-room state a
        // server-seeded room gets — one place, not two copies of this setup
        // that could drift.
        _initRoomState(room) {
            // Johan, 2026-10-02: "I type the qty, tabbing goes to desc" —
            // qty defaulting to 1 meant deleting it first to type 4. Starts
            // blank now; stays blank if left blank (never silently saved as
            // 0 or 1 — see commitDraftRow()/qtyForPayload()).
            this.newLine[room.id] = { quantity: '', description: '' };
            this.lineBusy[room.id] = false;
            // "Nothing in this room"/"Undo" fix, 2026-09-28 — same undefined-
            // bound-boolean-attribute bug as the rental-applications strike
            // button (see rental-click-through.mjs's own header docblock):
            // markRoomBusy[room.id] was never initialised, so it read
            // `undefined` until the button's own first click set it. Alpine's
            // :disabled binding forwards that straight to the DOM's
            // `toggleAttribute('disabled', undefined)` — and a WebIDL
            // optional-boolean argument passed as literal `undefined` is
            // spec'd as THE ARGUMENT BEING OMITTED, so it just flips
            // whatever the attribute's CURRENT state is instead of forcing
            // it false. Freshly-rendered HTML has no `disabled` attribute,
            // so that very first flip ADDS it — permanently, since the
            // button can never be clicked to run the code that would set a
            // real boolean. Initialising it to `false` here (same pattern
            // already used for lineBusy above) makes every :disabled
            // evaluation a genuine boolean from the first paint onward.
            this.markRoomBusy[room.id] = false;
        },
        // Blank is always '' client-side (never null/undefined) — see init().
        normalizeQtyDisplay(v) {
            return (v === null || v === undefined) ? '' : v;
        },
        // Blank is always null server-side (never silently 0) — Johan,
        // 2026-10-02: "lets get that to null and it will work perfect."
        qtyForPayload(v) {
            const t = (v === null || v === undefined) ? '' : String(v).trim();
            return t === '' ? null : t;
        },
        linesFor(roomId) { return this.lines.filter(l => Number(l.property_room_id) === Number(roomId)); },

        // Johan: "we specced inventory being blank then you can create the
        // spaces same as with inspections." Calls the SAME
        // rental-inspection-items.store endpoint (kind=space) the
        // Inspection Items section's own "Space (new room)" control uses —
        // a real PropertyRoom row, not a second space model. The response
        // is a list of freshly-created RentalInspectionItem checklist rows
        // (this room's default inspection facets) each carrying its own
        // `.room` relation — inventory only cares about the room itself,
        // never the checklist, so it takes items[0].room and discards the
        // rest; every item shares the exact same room, so which one is
        // arbitrary.
        async addSpace() {
            const label = this.newSpace.label.trim();
            if (!label || !this.newSpace.space_type) return;
            this.spaceBusy = true;
            this.spaceError = '';
            try {
                const res = await fetch(this.spaceStoreUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ kind: 'space', label, space_type: this.newSpace.space_type }),
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    this.spaceError = body.message || 'Could not add that space — try again.';
                    return;
                }
                const result = await res.json();
                const room = result.items?.[0]?.room;
                if (!room) return;
                this.rooms.push({ id: room.id, label: room.label });
                this._initRoomState({ id: room.id });
                this.newSpace = { space_type: '', label: '' };
                // §13.11 — a freshly-added space is exactly the "not
                // opened" room the agent just asked for; scroll to it (its
                // panel exists in the DOM one tick after this push) rather
                // than just marking a pill active with nothing visibly
                // happening.
                this.$nextTick(() => {
                    this._setupScrollSpy();
                    this.scrollToRoom(room.id);
                });
            } finally {
                this.spaceBusy = false;
            }
        },

        // Johan, 2026-09-28: "why ask the agent to retype something we
        // know." Same endpoint Inspections' own "Build from advertising
        // details" button calls (RentalInspectionFormSeeder::
        // seedFromAdvertising()) — reads the property's advertising Spaces
        // (spaces_json), creates one real PropertyRoom per unit. One-time
        // per property (the seeder's own 409 guard) and never silent — an
        // explicit click behind a confirm, with the failure (e.g. no
        // advertising Spaces yet) surfaced via the same spaceError slot
        // addSpace() already uses.
        async seedSpacesFromListing() {
            if (!confirm('Create spaces from this property\'s advertising Spaces (bedrooms, bathrooms, etc.)? You can edit or add more afterwards.')) return;
            this.seedBusy = true;
            this.spaceError = '';
            try {
                const res = await fetch(this.seedSpacesFromListingUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({}),
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    this.spaceError = body.message || 'Could not create spaces from the listing — try again.';
                    return;
                }
                const result = await res.json();
                (result.rooms || []).forEach(room => {
                    if (!this.rooms.some(r => r.id === room.id)) {
                        this.rooms.push({ id: room.id, label: room.label });
                        this._initRoomState({ id: room.id });
                    }
                });
                this.$nextTick(() => this._setupScrollSpy());
            } finally {
                this.seedBusy = false;
            }
        },

        // §13, Johan's approved mockup — "a furnished flat is re-let with
        // the same contents, and re-typing forty lines is the work we are
        // supposed to be doing for them." Additive only: appends the
        // copied lines to whatever already exists, never overwrites.
        async copyFromLastInventory() {
            if (!confirm('Copy every item from the last inventory into this one?')) return;
            this.copyBusy = true;
            this.copyError = '';
            try {
                const res = await fetch(`${this.baseUrl}/copy-from-last`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) {
                    const body = await res.json().catch(() => ({}));
                    this.copyError = body.message || 'Could not copy from the last inventory.';
                    return;
                }
                const body = await res.json();
                (body.lines || []).forEach(line => {
                    const quantity = this.normalizeQtyDisplay(line.quantity);
                    this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                    this.lineSnapshots[line.id] = { quantity, description: line.description };
                });
                this.pickInitialActiveRoom();
            } finally {
                this.copyBusy = false;
            }
        },

        // §4a — the SAME reusable component rental-inspections built
        // (public/js/corex-photo-batch-uploader.js, rental-inspections.md
        // §20.13.4), not a second bespoke upload/progress implementation.
        // One instance for the whole inventory (there is only one document
        // here, unlike inspections' In/Out sections) — memoized so every
        // call site shares the same reactive `photos`/`uploadBatches`
        // state. `roomPhotos(roomId)` is the uploader's own generic
        // filter — it also excludes anything carrying a
        // rental_inspection_observation_id, a key inventory photos never
        // have, so it filters correctly here with no changes needed.
        _photoUploader: null,
        photoUploader() {
            if (!this._photoUploader) {
                this._photoUploader = window.corexPhotoBatchUploader({
                    csrf: this.csrf,
                    uploadUrl: `${this.baseUrl}/photos`,
                    archiveUrl: (photoId) => `${this.baseUrl}/photos/${photoId}`,
                    photos: @json($photosForJs),
                });
            }
            return this._photoUploader;
        },
        // §13 — the per-line photo strip's own photo OBJECTS (not just the
        // ids `line.photos` holds) — looked up against the uploader's own
        // reactive `photos` array so a strip always reflects the current
        // state (including after an archive elsewhere removes one; a stale
        // id left behind in `line.photos` is silently dropped here by
        // `.filter(Boolean)` rather than needing a second cleanup pass).
        photosForLine(line) {
            return (line.photos || []).map(id => this.photoUploader().photos.find(p => p.id === id)).filter(Boolean);
        },
        // §13 — upload AND tag to this line in ONE request
        // (rental_inventory_line_id on storePhotos()), never a stage-then-
        // tag two-step. onBatchDone (the uploader's own 3rd, optional arg)
        // pushes the new photo's id onto this line's own `photos` array the
        // moment the batch's POST succeeds — the same bookkeeping
        // toggleTag() already does for the modal-based tag path, just
        // triggered from upload instead of a checkbox.
        uploadPhotoToLine(line, files) {
            this.photoUploader().uploadFiles(files, { property_room_id: line.property_room_id, rental_inventory_line_id: line.id }, (body) => {
                (body.photos || []).forEach(p => { if (!line.photos.includes(p.id)) line.photos.push(p.id); });
            });
        },

        // ── Spreadsheet-grid capture (Johan, 2026-09-25, expanded
        // 2026-09-26) ─────────────────────────────────────────────────
        // Contract — this is what gets tested key-by-key on the deployed
        // page, so it lives here as one readable block, not spread out:
        //   Tab (no shift), from description      → commit this row if
        //     non-empty, land on next row's qty (selected)
        //   Enter, from either cell                → same as above
        //   → (ArrowRight), caret at END, no selection, from description
        //                                           → same as above
        //   → (ArrowRight), caret at END, no selection, from qty
        //                                           → same row's description
        //     (selected), no commit — still the same row
        //   ← (ArrowLeft), caret at START, no selection, from qty
        //                                           → commit this row if
        //     changed, land on PREVIOUS row's description (selected)
        //   ← (ArrowLeft), caret at START, no selection, from description
        //                                           → same row's qty
        //     (selected), no commit — still the same row
        //   ↑ (ArrowUp) / ↓ (ArrowDown), any caret position
        //                                           → commit this row if
        //     changed, land on the SAME column of the prev/next row
        //     (selected); no-op at the top/bottom edge of the grid
        //   Any arrow key when the caret is NOT at that boundary, or when
        //     text is selected                      → normal cursor /
        //     selection-collapse behaviour, untouched
        //   Shift+Tab                                → left un-intercepted;
        //     native tab order already lands on the right cell because
        //     every other focusable control in a room's block carries
        //     tabindex="-1" (Add button, condition chips, per-line photo
        //     input, photo archive/show-all/retry, Tag photo/Remove)
        // Committing a row never blocks the focus move — save requests are
        // fire-and-forget (commitRow() is called, not awaited) so the
        // keyboard never waits on the network. A row is never saved empty
        // (draft) or blanked to empty (existing line — reverts instead).
        gridRowKeys(room) {
            return [...this.linesFor(room.id).map(l => 'line-' + l.id), 'draft'];
        },
        gridCell(container, rowKey, cellKind) {
            return container ? container.querySelector(`[data-row="${rowKey}"][data-cell="${cellKind}"]`) : null;
        },
        focusAndSelect(el) {
            if (!el) return;
            el.focus();
            el.select();
        },
        onCellKeydown(event, room, rowKind, rowRef, cellKind) {
            const key = event.key;
            if (key !== 'Enter' && key !== 'Tab' && key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'ArrowUp' && key !== 'ArrowDown') return;

            const el = event.target;
            const container = el.closest('[data-room-id]');
            const keys = this.gridRowKeys(room);
            const currentKey = rowKind === 'draft' ? 'draft' : ('line-' + rowRef.id);
            const idx = keys.indexOf(currentKey);
            const hasSelection = el.selectionStart !== el.selectionEnd;
            const atStart = el.selectionStart === 0 && el.selectionEnd === 0;
            const atEnd = el.selectionStart === el.value.length && el.selectionEnd === el.value.length;

            const gotoNextRowQty = () => {
                this.commitRow(room, rowKind, rowRef);
                this.focusAndSelect(this.gridCell(container, keys[idx + 1] ?? 'draft', 'qty'));
            };

            if (key === 'Enter') { event.preventDefault(); gotoNextRowQty(); return; }

            if (key === 'Tab') {
                if (event.shiftKey || cellKind === 'qty') return;
                event.preventDefault();
                gotoNextRowQty();
                return;
            }

            if (key === 'ArrowRight') {
                if (hasSelection || !atEnd) return;
                event.preventDefault();
                if (cellKind === 'qty') this.focusAndSelect(this.gridCell(container, currentKey, 'description'));
                else gotoNextRowQty();
                return;
            }

            if (key === 'ArrowLeft') {
                if (hasSelection || !atStart) return;
                event.preventDefault();
                if (cellKind === 'description') {
                    this.focusAndSelect(this.gridCell(container, currentKey, 'qty'));
                } else if (idx > 0) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx - 1], 'description'));
                }
                return;
            }

            if (key === 'ArrowDown') {
                event.preventDefault();
                if (idx < keys.length - 1) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx + 1], cellKind));
                }
                return;
            }

            if (key === 'ArrowUp') {
                event.preventDefault();
                if (idx > 0) {
                    this.commitRow(room, rowKind, rowRef);
                    this.focusAndSelect(this.gridCell(container, keys[idx - 1], cellKind));
                }
                return;
            }
        },
        commitRow(room, rowKind, rowRef) {
            if (rowKind === 'draft') this.commitDraftRow(room);
            else this.commitExistingLineIfDirty(room, rowRef);
        },
        flashSaved(roomId) {
            this.lineSavedFlash[roomId] = true;
            setTimeout(() => { if (this.lineSavedFlash[roomId]) this.lineSavedFlash[roomId] = false; }, 1200);
        },
        // The trailing blank row. Resets IN PLACE, synchronously, before the
        // save request resolves — a fast typist starting the next row must
        // never race their own in-flight POST for this one.
        commitDraftRow(room) {
            const form = this.newLine[room.id];
            const description = (form.description || '').trim();
            if (!description) return; // never save an empty row
            // Blank qty ships as null, never silently defaulted to 0/1
            // (Johan, 2026-10-02).
            const payload = { property_room_id: room.id, quantity: this.qtyForPayload(form.quantity), description };
            this.newLine[room.id] = { quantity: '', description: '' };
            this.lineBusy[room.id] = true;
            fetch(`${this.baseUrl}/lines`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then(async (res) => {
                if (!res.ok) { this.lineSaveError[room.id] = true; return; }
                const line = await res.json();
                const quantity = this.normalizeQtyDisplay(line.quantity);
                this.lines.push({ id: line.id, property_room_id: line.property_room_id, quantity, description: line.description, condition_key: line.condition_key ?? null, photos: [] });
                this.lineSnapshots[line.id] = { quantity, description: line.description };
                this.lineSaveError[room.id] = false;
                this.flashSaved(room.id);
            }).catch(() => { this.lineSaveError[room.id] = true; })
              .finally(() => { this.lineBusy[room.id] = false; });
        },
        // An existing (already-persisted) line, edited via the grid.
        // Dirty-checked against the last-saved snapshot so pure navigation
        // (arrowing through rows to review them) never fires a request.
        async commitExistingLineIfDirty(room, line) {
            const description = (line.description || '').trim();
            const quantity = this.normalizeQtyDisplay(line.quantity);
            const snap = this.lineSnapshots[line.id] || { quantity, description: line.description };
            if (!description) {
                // An edit can't blank an existing item's description out —
                // that's not "removing an empty row", it's erasing a real
                // one. Restore rather than silently losing it; "Remove" is
                // its own explicit control (retireLine) for that.
                line.description = snap.description;
                return;
            }
            if (snap.quantity === quantity && snap.description === description) return;
            // §11.2/§12 — a keydown commit and the @blur it triggers a moment
            // later (see the comment on _pendingLineCommits above) both reach
            // this point with the identical, not-yet-saved values. Skip the
            // second one rather than fire a redundant duplicate PUT.
            const signature = quantity + '|' + description;
            if (this._pendingLineCommits[line.id] === signature) return;
            this._pendingLineCommits[line.id] = signature;
            try {
                const res = await fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ property_room_id: line.property_room_id, quantity: this.qtyForPayload(quantity), description }),
                });
                if (!res.ok) { this.lineSaveError[room.id] = true; return; }
                const updated = await res.json();
                line.quantity = this.normalizeQtyDisplay(updated.quantity);
                line.description = updated.description;
                this.lineSnapshots[line.id] = { quantity: line.quantity, description: updated.description };
                this.lineSaveError[room.id] = false;
                this.flashSaved(room.id);
            } catch (e) {
                this.lineSaveError[room.id] = true;
            } finally {
                delete this._pendingLineCommits[line.id];
            }
        },
        // §13 — the condition chip's own immediate commit. Optimistic
        // (sets the chip active right away) with a rollback on failure,
        // same discipline as every other click-to-set control here.
        async setLineCondition(line, key) {
            if (line.condition_key === key) return;
            const previous = line.condition_key;
            line.condition_key = key;
            try {
                const res = await fetch(`${this.baseUrl}/lines/${line.id}`, {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        property_room_id: line.property_room_id,
                        quantity: this.qtyForPayload(this.normalizeQtyDisplay(line.quantity)),
                        description: line.description,
                        condition_key: key,
                    }),
                });
                if (!res.ok) { line.condition_key = previous; return; }
                const updated = await res.json();
                line.condition_key = updated.condition_key;
                this.lineSnapshots[line.id] = { quantity: this.normalizeQtyDisplay(updated.quantity), description: updated.description };
            } catch (e) {
                line.condition_key = previous;
            }
        },
        // §12 — "nothing in this room," the explicit counterpart to a room
        // with real lines in it. Idempotent: marking an already-marked room
        // again just refreshes who/when server-side; client state doesn't
        // change. Reversible (Johan, 2026-09-28) via unmarkRoomEmpty() below —
        // if the agent later adds a real item, the room satisfies the
        // completion gate through that line instead, and the mark simply
        // stops mattering (no need to unmark it first).
        isRoomMarkedEmpty(roomId) { return this.markedEmptyRoomIds.has(Number(roomId)); },
        async markRoomEmpty(room) {
            this.markRoomBusy[room.id] = true;
            try {
                const res = await fetch(`${this.baseUrl}/rooms/${room.id}/mark-empty`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                this.markedEmptyRoomIds.add(Number(room.id));
            } finally {
                this.markRoomBusy[room.id] = false;
            }
        },
        // Johan, 2026-09-28 — the "Undo." Same URI as markRoomEmpty(), DELETE
        // instead of POST (RESTful counterpart, not a second endpoint).
        async unmarkRoomEmpty(room) {
            this.markRoomBusy[room.id] = true;
            try {
                const res = await fetch(`${this.baseUrl}/rooms/${room.id}/mark-empty`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                });
                if (!res.ok) return;
                this.markedEmptyRoomIds.delete(Number(room.id));
            } finally {
                this.markRoomBusy[room.id] = false;
            }
        },
        async retireLine(line) {
            if (!confirm('Remove this item? It stays in the record, marked removed.')) return;
            const res = await fetch(`${this.baseUrl}/lines/${line.id}/retire`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            this.lines = this.lines.filter(l => l.id !== line.id);
        },

        openTagger(line) {
            this.tagger = { open: true, mode: 'line', line, photo: null, photos: this.photoUploader().roomPhotos(line.property_room_id), lines: [] };
        },
        openTaggerForPhoto(room, photo) {
            this.tagger = { open: true, mode: 'photo', line: null, photo, photos: [], lines: this.linesFor(room.id) };
        },
        isTagged(line, photo) {
            if (!line || !photo) return false;
            return (line.photos || []).includes(photo.id);
        },
        async toggleTag(line, photo) {
            const tagged = this.isTagged(line, photo);
            const method = tagged ? 'DELETE' : 'POST';
            const res = await fetch(`${this.baseUrl}/lines/${line.id}/photos/${photo.id}`, {
                method,
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const lineRef = this.lines.find(l => l.id === line.id);
            const photoRef = this.photoUploader().photos.find(p => p.id === photo.id);
            if (tagged) {
                if (lineRef) lineRef.photos = lineRef.photos.filter(id => id !== photo.id);
                if (photoRef) photoRef.lines = photoRef.lines.filter(id => id !== line.id);
            } else {
                if (lineRef && !lineRef.photos.includes(photo.id)) lineRef.photos.push(photo.id);
                if (photoRef && !photoRef.lines.includes(line.id)) photoRef.lines.push(line.id);
            }
        },
    };
}
</script>
@endpush
@endif
