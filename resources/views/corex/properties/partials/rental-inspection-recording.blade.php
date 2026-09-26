{{--
    Rental inspection recording — the ONE editable side of the chain's
    current tail. Rendered inside the `rentalImages()` Alpine component.
    Spec: .ai/specs/rental-inspections.md §4/§14, §18 (Inspections-tab
    rebuild, 2026-09-22 — autosave, one-tap condition, All Good bulk-fill,
    progress/collapse, read-only property type), and the chain (2026-09-23
    — "the tab must render whichever inspection is the current link, not
    just one of two hardcoded types").

    Required include var:
      $section — the inspection's type ('in'/'out'/'ad_hoc'), a real PHP
                 string. Used for the one PHP-compile-time branch below
                 (there is exactly one — the move-in-date field used to
                 read $section directly; it is now the reactive check
                 further down instead) and as a display label.

    Optional include var:
      $sectionJs — override the JS expression every Alpine call below is
                 built from (`currentInspection({{ $sectionJs }})` etc).
                 Defaults to a quoted literal of $section (the original,
                 pre-chain shape: 'in' or 'out', fixed at render time).
                 The chain's own caller (show.blade.php's single unified
                 include) passes the LIVE JS call 'tailSection()' instead
                 (no quotes — a real expression, not a string), so every
                 Alpine lookup here re-resolves reactively against
                 whichever inspection is actually the chain's tail right
                 now — including immediately after "Next inspection",
                 with no page reload — rather than staying frozen at
                 whatever type happened to be current when this partial
                 was first rendered.

    currentInspection() never returns a completed/cancelled one (§0.5's
    currentFor() excludes them), so the statuses possible here are only
    draft, in_progress, awaiting_signature — the lifecycle controls below
    don't need a "completed" branch.

    Optional include vars, 2026-09-23 (Johan, property 5792 — "surely we
    can ensure that the sides show the same and looks the same, and have
    spaces next to each other, and have photos next to each other, and
    kitchen ceilings next to each other?"):
      $predecessorJs — a raw JS expression naming the chain's predecessor
                 inspection object (defaults to 'chainPredecessor', the
                 chain caller's only real value). Drives the LEFT cell of
                 the shared room/item comparison grid below. Null-safe at
                 every read (conditionForInspection()/
                 itemPhotosForInspection()), so "no predecessor yet" and
                 "predecessor has nothing recorded for this item" render
                 identically — an empty cell, row by row — with no
                 separate top-level special case needed.
      $tailReadOnly  — PHP bool, compile-time, default false. true renders
                 the RIGHT cell read-only too (chainTail.status ===
                 'completed' — nothing left to record) and skips the
                 metadata/discrepancy/progress/photo-tray/notes/signing
                 blocks entirely (there is nothing to action on a
                 completed inspection). The chain caller in show.blade.php
                 includes this file TWICE, once per value, each behind its
                 own <div x-show> (never <template x-if> — see that file's
                 own FIX docblock on exactly why) so the flag is always a
                 real PHP literal, never threaded through as a runtime
                 expression.

    Both cells of the room/item grid are rendered by the SAME shared
    partial, rental-inspection-item-cell.blade.php — "Read-only means
    disabled controls or a static rendering of the same component — it
    does not mean a different component with a different look" (Johan).
    The standalone Compare section is being removed (cc2); every photo
    tile on both cells opens cc2's shared openCompareViewer() modal —
    see that partial's own docblock.
--}}
{{-- FIX, 2026-09-22 (Johan, live measurement, AFTER 8fccccb5a "landed" and
     was STILL broken) — the real cause was never the sizing math, it was
     `x-bind:style` (`:style="..."`) OVERWRITING the whole `style` attribute
     on ANY element that also has a static `style="..."`, not merging with
     it. Alpine sets the element's `style.cssText` wholesale from the bound
     expression on every reactive render; when that expression evaluates to
     '' (the common, nothing-selected/nothing-happening case), it wipes
     every static declaration too — this is why `style=""` showed up EMPTY
     on the deployed page rather than missing or wrong. The img tag right
     next to the broken tile had no `:style` binding at all, which is
     exactly why IT rendered correctly and the tile did not — same commit,
     one element affected, one not, by nothing but the presence of a
     `:style` attribute.

     Swept this file for every element carrying BOTH a static `style="..."`
     and a bound `:style="..."` (5 found, all fixed the same way — the 5th,
     the untagged-tray tile, was a PRIOR "FACT A" fix that never actually
     took effect on the deployed page for this exact reason, which is what
     was producing the 1054x791 tray photos / 1606px tray container): the
     photo-tile and toggle-style ones below move their static declarations
     into a real CSS class so `:style` only ever has the ONE thing it's
     actually meant to control left to overwrite. Real CSS shipped as part
     of this page's own HTML (matching the compare-view's own `<style>`
     block precedent in show.blade.php, same reasoning) — the qa-deploy
     build trigger is confirmed working now, but this avoids the class of
     risk entirely rather than trading on that. --}}
<style>
    .rir-item-photo-tile { display:inline-block; vertical-align:top; width:165px; height:100%; overflow:hidden; background:var(--surface-3); margin-right:0.375rem; }
    .rir-room-photo-tile { aspect-ratio:1/1; background:var(--surface-3); }
    .rir-marquee-rect { position:absolute; border:1px dashed var(--brand-icon,#0ea5e9); background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); pointer-events:none; }
    .rir-tray-tile { position:relative; width:3rem; height:3rem; }
    /* AT-433 Part A, 2026-09-26 — the comparison-row photo strip. A NEW
       tile, not a resize of .rir-item-photo-tile above: that class is
       still used for the tray/upload preview list elsewhere on this
       screen, so it keeps its own 165x124 size. .rir-strip-row is the
       horizontal scroller (always scrollable, never wraps — a narrow
       viewport scrolls instead of silently clipping tiles).
       FIX, 2026-09-26 (AT-433 follow-up) — the first version of this strip
       positioned .rir-add-tile as an absolutely-positioned SIBLING of this
       row, placed via a `left` computed from tile count/width and clamped
       with calc(100% - 124px) so it wouldn't run off the right edge. In the
       narrow half-width comparison cell that clamp routinely engaged,
       landing the add-tile on top of the "+N" tile (and the last visible
       tiles) — because both were position:absolute with no z-index, the
       later-painted add-tile silently ate the +N tile's clicks. Any future
       change to tile width, cap, or count would recreate the same collision
       — a computed-left formula next to a full-bleed absolute row is a bug
       generator, not a one-off mistake. Fixed at the class level: the row
       is now a real flex container and .rir-add-tile is simply its LAST
       CHILD, in normal flow, after the "+N" tile — there is no left to
       compute and nothing for it to land on top of. */
    .rir-strip-row { position:absolute; top:0; left:0; right:0; bottom:0; height:100%; overflow-x:auto; overflow-y:hidden; display:flex; flex-wrap:nowrap; align-items:flex-start; gap:6px; }
    .rir-strip-tile { flex:none; width:86px; height:64px; overflow:hidden; background:var(--surface-3); box-sizing:border-box; }
    .rir-strip-badge { position:absolute; top:2px; left:2px; min-width:16px; height:16px; padding:0 3px; border-radius:8px; background:rgba(0,0,0,0.65); color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center; z-index:1; pointer-events:none; }
    .rir-strip-nomatch { display:inline-flex; align-items:center; justify-content:center; background:var(--surface-2); border:1px dashed var(--border); }
    .rir-strip-nomatch-label { font-size:8px; font-weight:700; letter-spacing:0.02em; color:var(--text-muted); text-align:center; line-height:1.2; padding:0 4px; }
    .rir-strip-more { display:inline-flex; align-items:center; justify-content:center; background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary); font-size:0.75rem; font-weight:700; padding:0; cursor:pointer; }
    /* §24.6, AT-433 Part B — the predecessor tile's drop-target styling,
       applied entirely within the tile's own stacking context (never a new
       absolutely-positioned sibling of .rir-strip-row — the exact bug class
       that row was just rebuilt to remove). -eligible is toggled ONLY while
       a pairing drag is in progress (pairDragActive) and clears the instant
       it ends, so there is no persistent affordance and no hover state on a
       tile when nothing is being dragged, per Johan's own ruling. -over is
       the finer highlight for whichever eligible tile the drag is currently
       over — same dashed-cyan visual language as this file's own
       dragOverRoom (room heading drop target) above, for one consistent
       drop-target language across this screen. */
    .rir-strip-pair-eligible { outline:1px dashed color-mix(in srgb, var(--brand-icon,#0ea5e9) 55%, transparent); outline-offset:-1px; }
    .rir-strip-pair-over { outline:2px dashed var(--brand-icon,#0ea5e9); background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); }
    /* Item 2, 2026-09-26 — a staged (picked, not yet uploaded) photo, shown
       so choosing a file never looks like nothing happened. Not part of
       stripPairCount()'s predecessor/tail pairing (it has no server photo
       id yet) — always visible, never collapsed behind "+N". */
    .rir-strip-pending-label { position:absolute; bottom:2px; left:2px; right:2px; font-size:8px; font-weight:700; letter-spacing:0.02em; color:#fff; text-align:center; line-height:1.3; background:rgba(0,0,0,0.55); border-radius:3px; pointer-events:none; }
    /* Add-tile — now just the strip's last flex child (see the row comment
       above). flex:none keeps its 124px width fixed; align-self:stretch
       reproduces the old top:0/bottom:0 full-row-height click target while
       every tile above it stays top-aligned via the row's align-items. */
    .rir-add-tile { flex:none; align-self:stretch; width:124px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; border-radius:6px; cursor:pointer; }
</style>
{{-- $sectionJs override — see this file's own top docblock. --}}
@php($sectionJs = $sectionJs ?? "'{$section}'")
@php($predecessorJs = $predecessorJs ?? 'chainPredecessor')
@php($tailReadOnly = $tailReadOnly ?? false)

<template x-if="!currentInspection({{ $sectionJs }})">
    <div class="space-y-2">
        <div x-show="startError[{{ $sectionJs }}]" x-cloak class="text-xs" style="color:#ef4444;" x-text="startError[{{ $sectionJs }}]"></div>
        <button type="button" :disabled="startBusy[{{ $sectionJs }}]" @click="startInspection({{ $sectionJs }})"
                class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button,#0ea5e9);"
                x-text="startBusy[{{ $sectionJs }}] ? 'Starting…' : 'Start {{ ucfirst($section) }}-Inspection'"></button>
    </div>
</template>

<template x-if="currentInspection({{ $sectionJs }})">
    <div class="space-y-3">
        {{-- 2026-09-23 — a completed tail has nothing left to action:
             no meters to correct, no discrepancies to resolve, nothing to
             upload, nothing to sign. Skipped entirely rather than shown
             disabled — Johan's own item-cell rule ("Read-only means
             disabled controls... not a different component") is about the
             room/item grid below, which every completed inspection still
             needs (it's the comparison content itself); this metadata/
             lifecycle chrome has no read-only rendering to fall back to
             because it was never something the LEFT (predecessor) cell
             showed either. --}}
        @unless($tailReadOnly)
        {{--
            §17 — the header block, from Johan's real paper form: everything
            above the room tables. "Pull what we already know" — landlord,
            tenants and the recording agent are DISPLAY ONLY here (read from
            the same lease/property/user relations §15's signing block
            already resolves — never a second name field to type into).
            Meter readings, furnished state, keys/remotes and (out-inspection
            only) the move-in date are editable, defaulted from the
            property/lease at inspection start (RentalInspection::start()) —
            confirm-or-correct, not retype. Item 2/8 (2026-09-22): every
            field autosaves on change — no Save button — and property type
            is read-only, derived from the Property record (item 8: "the
            property already knows it").
        --}}
        <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2);">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-xs" style="color:var(--text-secondary);">
                <div><span style="color:var(--text-muted);">Landlord:</span> <span x-text="landlordContact ? (landlordContact.first_name + ' ' + landlordContact.last_name) : '—'"></span></div>
                <template x-for="(tenant, idx) in inspectionTenants({{ $sectionJs }})" :key="tenant.contact_id">
                    <div><span style="color:var(--text-muted);" x-text="'Tenant ' + (idx + 1) + ':'"></span> <span x-text="tenantName(tenant)"></span></div>
                </template>
                <div><span style="color:var(--text-muted);">Inspection done by:</span> <span x-text="currentInspection({{ $sectionJs }}).created_by?.name || '—'"></span></div>
                <div><span style="color:var(--text-muted);">Property type:</span> <span x-text="propertyType || '—'"></span></div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1" style="border-top:1px solid var(--border);">
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Electricity meter</label>
                    <input type="text" x-model="currentInspection({{ $sectionJs }}).electricity_meter_reading"
                           @input="autosaveDetails({{ $sectionJs }})" placeholder="Reading, or e.g. BODY CORP" class="prop-input w-full">
                </div>
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Water meter</label>
                    <input type="text" x-model="currentInspection({{ $sectionJs }}).water_meter_reading"
                           @input="autosaveDetails({{ $sectionJs }})" placeholder="Reading, or e.g. BODY CORP" class="prop-input w-full">
                </div>
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Furnished</label>
                    <select x-model="currentInspection({{ $sectionJs }}).furnished_status" @change="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                        <option value="">— Select —</option>
                        @foreach($settingItems['furnishedStatuses'] ?? [] as $fs)
                            <option value="{{ $fs->name }}">{{ $fs->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-1">
                    <div style="width:4.5rem;">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">Keys</label>
                        <input type="number" min="0" x-model.number="currentInspection({{ $sectionJs }}).keys_count"
                               @input="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">&nbsp;</label>
                        <input type="text" x-model="currentInspection({{ $sectionJs }}).keys_description"
                               @input="autosaveDetails({{ $sectionJs }})" placeholder="e.g. set keys" class="prop-input w-full">
                    </div>
                </div>
                <div class="flex gap-1">
                    <div style="width:4.5rem;">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">Remotes</label>
                        <input type="number" min="0" x-model.number="currentInspection({{ $sectionJs }}).remotes_count"
                               @input="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                    </div>
                    <div class="flex-1">
                        <label class="text-xs font-semibold" style="color:var(--text-secondary);">&nbsp;</label>
                        <input type="text" x-model="currentInspection({{ $sectionJs }}).remotes_description"
                               @input="autosaveDetails({{ $sectionJs }})" placeholder="e.g. gate remotes" class="prop-input w-full">
                    </div>
                </div>
                {{-- 2026-09-23 — was a PHP-compile-time @if($section === 'out'),
                     baked into the compiled HTML at whatever type happened
                     to be current when this partial was first rendered.
                     With $sectionJs now able to be a LIVE expression
                     (tailSection() — see the top docblock), that PHP-time
                     check would stay frozen at the type from the initial
                     page load even after "Next inspection" changes which
                     type is actually current, without a full page reload.
                     Alpine x-if reacts the same way every other lifecycle
                     control on this screen already does. --}}
                <template x-if="{{ $sectionJs }} === 'out'">
                <div>
                    <label class="text-xs font-semibold" style="color:var(--text-secondary);">Move-in date</label>
                    <input type="date" x-model="currentInspection({{ $sectionJs }}).move_in_date_recorded"
                           @change="autosaveDetails({{ $sectionJs }})" class="prop-input w-full">
                </div>
                </template>
            </div>
        </div>

        {{-- One banner per conflicting group — §0.4, must be resolved before completion. --}}
        <template x-for="discrepancy in (currentInspection({{ $sectionJs }}).discrepancies || []).filter(d => !d.resolved_at)" :key="discrepancy.id">
            <div class="rounded-md px-4 py-3 text-sm space-y-2" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                {{-- 2026-09-22 fix — reads discrepancy.item directly (the
                     discrepancy's own item() relation) rather than
                     discrepancy.observations[0]?.item, which depended on a
                     nested eager-load path that was never actually loaded
                     and rendered the literal string "undefined" as the
                     label (Johan, property 5792). --}}
                <div class="font-semibold" style="color:var(--text-primary);"
                     x-text="(discrepancy.item?.label || 'Unknown item') + ': ' + discrepancy.observations.map(o => o.condition).join(' vs ')"></div>
                <div class="flex flex-wrap items-center gap-3">
                    <template x-for="obs in discrepancy.observations" :key="obs.id">
                        <label class="flex items-center gap-1.5 text-xs" style="color:var(--text-secondary);">
                            <input type="radio" :name="'accept_' + discrepancy.id" :value="obs.id" x-model.number="discField(discrepancy.id).accepted_observation_id">
                            <span x-text="obs.condition + (obs.notes ? ' — ' + obs.notes : '')"></span>
                        </label>
                    </template>
                    <button type="button" :disabled="discBusy[discrepancy.id] || !discField(discrepancy.id).accepted_observation_id"
                            @click="resolveDiscrepancy({{ $sectionJs }}, discrepancy)"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--ds-crimson);"
                            x-text="discBusy[discrepancy.id] ? 'Resolving…' : 'Resolve'"></button>
                </div>
            </div>
        </template>

        {{-- Real empty state (BUILD_STANDARD §1a) — 2026-09-21, Johan: a
             started inspection with zero items just showed "Ready to sign"
             with nothing above it, which read as the button doing nothing.
             Point back at where items are actually added rather than
             rendering silently. --}}
        <p x-show="!activeItems().length" class="text-xs" style="color:var(--text-muted);">
            This property has no inspection items yet. Add rooms and meters under
            Inspection Items above, then come back here to record them.
        </p>

        {{-- Item 5/7, 2026-09-22 — overall progress + the whole-inspection
             "All Good" bulk-fill, sitting above the room list so it's the
             first thing seen once there's something to record. --}}
        {{-- 2026-09-22, Johan (property 5792, regression report): a fully-
             recorded room auto-collapsed with no way to reopen it, and
             "All Good"/"Mark room N/A" sat there doing nothing once every
             item was already recorded — from the screen, both read as
             "nothing here is clickable." Fixed at the class level:
             - the toggle chevron+heading is one clickable row with an
               explicit hover state so it visibly IS a control;
             - "All Good"/"Mark room N/A" disappear once a room has nothing
               left to fill (they had zero effect at that point anyway —
               a control that visibly does nothing is worse than no control);
             - "Expand all" / "Collapse all" gives a reviewing agent one
               action for the whole inspection instead of clicking every
               room; x-collapse is dropped for a plain x-show (the Collapse
               plugin isn't installed in this app — confirmed in
               node_modules/alpinejs/dist/cdn.js, it silently no-ops
               rather than blocking x-show, but it bought nothing here and
               reads as if it should be doing something). --}}
        <div x-show="activeItems().length" class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                  x-text="inspectionProgress({{ $sectionJs }}).recorded + '/' + inspectionProgress({{ $sectionJs }}).total + ' recorded'"></span>
            <div class="flex items-center gap-2">
                <button type="button" @click="toggleAllRooms({{ $sectionJs }})"
                        class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                        x-text="allRoomsOpen({{ $sectionJs }}) ? 'Collapse all' : 'Expand all'"></button>
                <button type="button" x-show="inspectionProgress({{ $sectionJs }}).recorded < inspectionProgress({{ $sectionJs }}).total"
                        :disabled="markAllGoodBusy" @click="markAllGood({{ $sectionJs }})"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);"
                        x-text="markAllGoodBusy ? 'Marking…' : 'All Good — whole inspection'"></button>
            </div>
        </div>

        {{-- Item 3, 2026-09-22 — bulk upload straight to the whole
             inspection, then tag. Untagged photos land here; the tray's
             own count IS the progress indicator (no separate badge).
             Multi-select: click, shift-click a run, ctrl/cmd-click, or
             drag a marquee over the thumbnails; drop the selection onto a
             room heading above, or use "Tag to room" below — the
             touch/mobile equivalent of the same action, since native
             HTML5 drag-and-drop is unreliable on phones (item 7). --}}
        <div class="rounded-md p-3 space-y-2" style="background:var(--surface-2);" x-show="activeItems().length">
            <div class="flex items-center justify-between gap-2">
                <label class="text-xs font-semibold px-3 py-1.5 rounded-md cursor-pointer inline-flex items-center gap-1" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);">
                    <span>&#128247;</span>
                    <span>Upload photos to this inspection</span>
                    <input type="file" accept="image/*" multiple class="hidden"
                           @change="photoUploader({{ $sectionJs }}).uploadFiles($event.target.files, {}); $event.target.value = null;">
                </label>
                <span x-show="photoUploader({{ $sectionJs }}).untaggedPhotos().length" class="text-xs font-semibold" style="color:var(--text-muted);"
                      x-text="photoUploader({{ $sectionJs }}).untaggedPhotos().length + ' untagged'"></span>
            </div>

            <template x-for="(batch, idx) in photoUploader({{ $sectionJs }}).uploadBatches.filter(b => b.status !== 'done')" :key="idx">
                <div class="flex items-center justify-between gap-2 text-xs px-2 py-1 rounded-md"
                     :style="batch.status === 'failed' ? 'background:color-mix(in srgb, var(--ds-crimson) 10%, transparent);' : 'background:var(--surface);'">
                    <span :style="batch.status === 'failed' ? 'color:var(--ds-crimson);' : 'color:var(--text-secondary);'"
                          x-text="batch.status === 'failed' ? (batch.files.length + ' photo(s) failed — ' + batch.error) : ('Uploading ' + batch.files.length + ' photo(s)… ' + (batch.percent || 0) + '%')"></span>
                    <button type="button" x-show="batch.status === 'failed'" @click="photoUploader({{ $sectionJs }}).retryBatch(batch)"
                            class="text-xs font-semibold underline" style="color:var(--text-secondary);">Retry</button>
                </div>
            </template>

            <template x-if="photoUploader({{ $sectionJs }}).untaggedPhotos().length">
                <div class="space-y-2">
                    <div class="flex items-center gap-2 flex-wrap p-2 rounded-md relative" style="background:var(--surface); min-height:3.5rem;"
                         @mousedown="photoUploader({{ $sectionJs }}).marqueeStart($event, $el)"
                         @mousemove.window="photoUploader({{ $sectionJs }}).marqueeMove($event)"
                         @mouseup.window="photoUploader({{ $sectionJs }}).marqueeEnd()">
                        {{-- FACT A, 2026-09-22 (Johan, live measurement) — every
                             Archive × rendered at the SAME coordinate, far
                             right of the tray, several stacked at 0x0. The
                             × is `position:absolute`, correctly anchored to
                             ITS OWN tile (`position:relative` was already on
                             the tile) — but the tile DIV itself carried no
                             explicit size at all; only the IMG inside it did
                             (`width:3rem;height:3rem`). Relying on an inline
                             img to establish its block parent's box is
                             exactly the "size derived from an elastic
                             container" pattern this whole bug class comes
                             from — fixed by giving the tile the SAME
                             explicit 3rem square directly, so every tile is
                             an unambiguous, independent 48x48 box regardless
                             of image content, and the × has a real anchor on
                             every one of them, not just the first. --}}
                        <template x-for="photo in photoUploader({{ $sectionJs }}).untaggedPhotos()" :key="photo.id">
                            <div :data-photo-id="photo.id" draggable="true"
                                 @dragstart="photoUploader({{ $sectionJs }}).dragStartSelection($event, photo.id)"
                                 @click="photoUploader({{ $sectionJs }}).selectClick(photo.id, photoUploader({{ $sectionJs }}).untaggedPhotos().map(p => p.id), $event)"
                                 class="rounded-md cursor-pointer rir-tray-tile"
                                 :style="photoUploader({{ $sectionJs }}).isSelected(photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''">
                                <img :src="photo.storage_path" class="rounded-md object-cover" style="display:block; width:100%; height:100%;" alt="">
                                {{-- Standards — a removed photo is archived
                                     (RentalInspectionPhoto::archive(), a real
                                     deleted_at, §20.13.1) never hard-deleted.
                                     Screened out of the tray BEFORE filing,
                                     the most common real case (a duplicate or
                                     blurry shot). --}}
                                <button type="button" @click.stop="if (confirm('Archive this photo?')) photoUploader({{ $sectionJs }}).archivePhoto(photo.id)"
                                        class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                        style="background:var(--ds-crimson); color:#fff; line-height:1;" title="Archive">&times;</button>
                            </div>
                        </template>
                        <template x-if="photoUploader({{ $sectionJs }}).marquee">
                            <div class="rir-marquee-rect"
                                 :style="'left:' + photoUploader({{ $sectionJs }}).marquee.x + 'px; top:' + photoUploader({{ $sectionJs }}).marquee.y + 'px; width:' + photoUploader({{ $sectionJs }}).marquee.w + 'px; height:' + photoUploader({{ $sectionJs }}).marquee.h + 'px;'"></div>
                        </template>
                    </div>

                    {{-- Untagged → room (many destinations, already worked) and
                         untagged → item (many destinations, was missing — an
                         agent had to route through the room first) share one
                         destination control: same interaction as every other
                         many-destination move on this screen. --}}
                    <div class="flex items-center gap-2 flex-wrap" x-show="photoUploader({{ $sectionJs }}).selected.size">
                        <span class="text-xs" style="color:var(--text-secondary);" x-text="photoUploader({{ $sectionJs }}).selected.size + ' selected'"></span>
                        <select class="prop-input text-xs" style="max-width:12rem;" x-model="trayTagRoomChoice">
                            <option value="">Tag to…</option>
                            <template x-for="group in roomGroups().filter(g => g.room)" :key="'tray-room-' + group.room.id">
                                <option :value="'room:' + group.room.id" x-text="group.room.label"></option>
                            </template>
                            <template x-for="i in allItemChoices({{ $sectionJs }})" :key="'tray-item-' + i.id">
                                <option :value="'item:' + i.id" x-text="i.roomLabel + ' — ' + i.label"></option>
                            </template>
                        </select>
                        <button type="button" :disabled="!trayTagRoomChoice" @click="applyTrayDestination({{ $sectionJs }}, trayTagRoomChoice)"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Tag selected</button>
                        <button type="button" @click="photoUploader({{ $sectionJs }}).clearSelection()" class="text-xs font-semibold underline" style="color:var(--text-secondary);">Clear</button>
                    </div>
                </div>
            </template>
        </div>
        @endunless

        {{-- Item 7, 2026-09-23 (Johan) — "Right now nothing on the screen
             tells you which side is which." One header per column, naming
             type/date/status, sitting directly above the shared room/item
             grid it labels — never inside either cell, so it can never
             drift out of alignment with what it names. --}}
        <div class="rir-compare-headers" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
            <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-secondary);">
                <template x-if="{{ $predecessorJs }}">
                    <span x-text="(({{ $predecessorJs }}.type === 'out' ? 'Out' : ({{ $predecessorJs }}.type === 'in' ? 'In' : 'Routine')) + '-inspection · ' + ({{ $predecessorJs }}.completed_at || {{ $predecessorJs }}.scheduled_for || {{ $predecessorJs }}.created_at || '').slice(0, 10) + ' · ' + {{ $predecessorJs }}.status.replace('_',' '))"></span>
                </template>
                <template x-if="!{{ $predecessorJs }}">
                    <span style="color:var(--text-muted); font-weight:normal; text-transform:none;">First inspection in this chain — nothing yet to compare against.</span>
                </template>
            </div>
            <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-secondary);">
                <span x-text="(({{ $sectionJs }} === 'out' ? 'Out' : ({{ $sectionJs }} === 'in' ? 'In' : 'Routine')) + '-inspection · ' + (currentInspection({{ $sectionJs }}).completed_at || currentInspection({{ $sectionJs }}).scheduled_for || currentInspection({{ $sectionJs }}).created_at || '').slice(0, 10) + ' · ' + currentInspection({{ $sectionJs }}).status.replace('_',' '))"></span>
            </div>
        </div>

        {{-- 2026-09-21, Johan on property 5792 — same room-heading grouping
             as the Inspection Items panel above, applied here so a walkthrough
             actually walks room by room instead of a flat list scattered by
             creation order. Keyed on group.room.id (roomGroups()), never on
             the room's free-text label. Item 7, 2026-09-22 — heading shows
             recorded/total + photo count and collapses once the room is
             fully recorded — always a default, never a lock: click the
             heading row to expand/collapse any time, whatever its state.

             FACT B, 2026-09-22 (Johan, live: "KITCHEN — 2/7 · 7 PHOTOS" over
             a gallery whose own "Show all 4" says 4) — roomProgress().photos
             (below) is deliberately the COMBINED count (this room's own
             general shots PLUS every one of its items' rolled-up photos,
             confirmed in roomProgress() itself), while the gallery a few
             lines down (R1) renders ONLY this room's own general shots — two
             genuinely different, both-correct counts with nothing on screen
             explaining the difference, which reads as the screen simply
             being wrong. Chose to LABEL rather than reconcile: forcing them
             to agree would mean either hiding the room's real total photo
             count (losing "how much evidence exists here" at a glance) or
             rendering item photos a second time in the room gallery
             (duplicating them on screen) — both worse than one word. The
             heading now reads "... · N photos total"; "Show all N" directly
             underneath the room's own gallery already means "N room
             photos" by its position, unchanged. --}}
        <template x-for="group in roomGroups()" :key="group.room ? 'room-' + group.room.id : 'general'">
            <div class="space-y-1 pt-2">
                <div class="flex items-center justify-between gap-2 rounded-md px-1 -mx-1"
                     :style="(dragOverRoom === (group.room?.id ?? null) ? 'background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 15%, transparent); outline:2px dashed var(--brand-icon,#0ea5e9);' : '') + 'cursor:pointer; transition:background .1s;'"
                     @mouseenter="$el.style.background = 'var(--surface-2)'" @mouseleave="$el.style.background = 'transparent'; dragOverRoom = null"
                     @click="toggleRoomOpen({{ $sectionJs }}, group)"
@unless($tailReadOnly)
                     @dragover.prevent="group.room && (dragOverRoom = group.room.id)"
                     @dragleave="dragOverRoom = null"
                     @drop.prevent="group.room && photoUploader({{ $sectionJs }}).dropOnRoom($event, group.room.id); dragOverRoom = null"
@endunless
                     >
                    <button type="button" class="flex items-center gap-1.5 text-left py-1" tabindex="0">
                        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"
                             :style="'color:var(--text-muted); transition:transform .15s; transform:rotate(' + (isRoomOpen({{ $sectionJs }}, group) ? 90 : 0) + 'deg);'">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                        <h4 class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);"
                            x-text="(group.room ? group.room.label : 'General') + ' — ' + roomProgress({{ $sectionJs }}, group).recorded + '/' + roomProgress({{ $sectionJs }}, group).total
                                    + (roomProgress({{ $sectionJs }}, group).photos ? ' · ' + roomProgress({{ $sectionJs }}, group).photos + ' photo' + (roomProgress({{ $sectionJs }}, group).photos === 1 ? '' : 's') + ' total' : '')"></h4>
                    </button>
                    @unless($tailReadOnly)
                    <div class="flex items-center gap-1" @click.stop>
                        {{-- Item 2, 2026-09-22 — the room's own general
                             photo(s), independent of any item; always
                             available (not gated on recording progress).
                             The heading row itself is also a drop target
                             for the tray's multi-select-and-drop (item 3). --}}
                        <template x-if="group.room">
                            <label class="text-xs font-semibold px-1.5 py-1 rounded-md cursor-pointer inline-flex items-center" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);" title="Add room photo(s)">
                                <span>&#128247;</span>
                                <input type="file" accept="image/*" multiple class="hidden"
                                       @change="onRoomPhotosSelected({{ $sectionJs }}, group.room, $event.target.files); $event.target.value = null;">
                            </label>
                        </template>
                        {{-- AT-433 Part A, 2026-09-26 — one master switch for
                             every item's own photo-strip collapse state in
                             this room, both cells at once (the state is
                             keyed by item id only, not by side — see
                             toggleAllItemStrips()/toggleItemStrip() in
                             show.blade.php). Only rendered on the editable
                             (tail) side; the predecessor cell has no room
                             header of its own to hang a control on, but it
                             shares and reacts to the same state. --}}
                        <template x-if="group.items.length">
                            <button type="button" @click="toggleAllItemStrips(group)"
                                    class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                    x-text="allItemStripsOpenInRoom(group) ? 'Collapse photos' : 'Expand photos'"></button>
                        </template>
                        {{-- Item 5/7 fix, 2026-09-22 — these have zero effect
                             once the room has nothing left to fill; showing
                             a "working" button that does nothing on click
                             is worse than not showing it. --}}
                        <template x-if="group.room && roomProgress({{ $sectionJs }}, group).recorded < roomProgress({{ $sectionJs }}, group).total">
                            <div class="flex items-center gap-1">
                                <button type="button" :disabled="markGoodBusy[group.room?.id]"
                                        @click="markRoomGood({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="markGoodBusy[group.room?.id] ? 'Marking…' : 'All Good'"></button>
                                {{-- §17, Johan on Retha's real paper form: "she
                                     strikes ENTIRE ROOMS out with one big N/A."
                                     Only offered when N/A is actually one of
                                     the agency's configured condition states. --}}
                                <button type="button" x-show="hasNaConditionState()" :disabled="markNaBusy[group.room?.id]"
                                        @click="markRoomNa({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="markNaBusy[group.room?.id] ? 'Marking…' : 'Mark room N/A'"></button>
                            </div>
                        </template>
                    </div>
                    @endunless
                </div>

                {{-- R1, 2026-09-22, Johan: "the small images is a waste of
                     time. it either has to show it big enough like on
                     gallery" — same square-tile size/grid as the property
                     Gallery (rental-section-body.blade.php: grid-cols-3
                     sm:grid-cols-5, aspect-ratio 1/1), one row by default
                     (a 15-room property must not push items ten screens
                     down), expanding to every row on click. Only rendered
                     when at least one general room photo exists (screen
                     space: nothing rendered otherwise).

                     FIX, 2026-09-22 (photo-tagging pass) — the room→item
                     move used to live as a <select> squeezed onto this
                     tile; two real problems with that, not one. (1) this
                     row's own `max-height:6.5rem; overflow-hidden` clip —
                     at gallery tile sizing a grid column is easily
                     150-300px wide, so an aspect-ratio:1/1 tile is that
                     tall too, and 104px clipped every tile short, cutting
                     the bottom-anchored control out of the clickable area
                     on any real screen width. (2) even reachable, its
                     value was the ITEM's id, not the OBSERVATION id
                     tagPhoto() actually needs — a photo files against an
                     observation, and an item with nothing recorded yet has
                     none, so this could 404 or (worse) hit an unrelated
                     observation that happened to share the number. Design
                     constraint, 2026-09-22 (Johan): "on item and on room if
                     you click the photo it opens up... wont work from room
                     to item as there are many items to 1 room" — room→item
                     is a many-destination move, so it doesn't belong on
                     the tile at all now; it lives in the opened photo view
                     (see the lightbox), which has room for a real chooser
                     built from itemChoicesFor() — item ids now (2026-09-22
                     fix), which creates the observation on demand rather
                     than requiring one to already exist.
                     Height-based clipping is also replaced with COUNT-based
                     slicing (first 3, "Show all" for the rest) so a
                     rendered tile is always whole regardless of width. --}}
                <template x-if="group.room && roomPhotosFor({{ $sectionJs }}, group.room).length">
                    <div class="pl-5 space-y-1">
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                            <template x-for="photo in (roomPhotosExpanded[group.room.id] ? roomPhotosFor({{ $sectionJs }}, group.room) : roomPhotosFor({{ $sectionJs }}, group.room).slice(0, 3))" :key="photo.id">
                                <div class="relative rounded-md overflow-hidden rir-room-photo-tile"
@unless($tailReadOnly)
                                     :style="photoUploader({{ $sectionJs }}).isSelected(photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''"
@endunless
                                     >
                                    {{-- Clicking the photo opens cc2's shared comparison
                                         modal — same handler as every other photo tile on
                                         this screen (2026-09-23, standalone Compare
                                         section removed, comparison lives in the modal).
                                         openCompareViewer(photo, insp) — this room-photo
                                         gallery is driven entirely by $sectionJs (the tail
                                         side only; there is no predecessor-side room
                                         gallery in this file), so chainTail is the correct
                                         inspection object, same as the tail item cell. --}}
                                    <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer"
                                         @click="openCompareViewer(photo, chainTail)" alt="">
@unless($tailReadOnly)
                                    {{-- Multi-select toggle — one tap/click, no modifier
                                         key, so it works identically at phone width. Feeds
                                         the same `selected` Set the tray's own multi-select
                                         already uses; the "N selected" bar below lets a
                                         whole run of room photos move at once. --}}
                                    <button type="button" @click.stop="photoUploader({{ $sectionJs }}).toggleSelected(photo.id)"
                                            class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                            :style="photoUploader({{ $sectionJs }}).isSelected(photo.id) ? 'background:var(--brand-icon,#0ea5e9); color:#fff;' : 'background:rgba(0,0,0,0.5); color:#fff;'"
                                            title="Select">&check;</button>
                                    {{-- Room → untagged — a SINGLE destination (the
                                         tray), so a plain button is correct here (Johan).
                                         Same ↑ = "up a level" convention as the item
                                         tile's own back-to-room button. --}}
                                    <button type="button" @click.stop="photoUploader({{ $sectionJs }}).untagPhoto(photo.id)"
                                            class="absolute top-0.5 right-0.5 w-4 h-4 rounded-full flex items-center justify-center text-xs font-bold"
                                            style="background:rgba(0,0,0,0.65); color:#fff; line-height:1;" title="Back to tray">&uarr;</button>
@endunless
                                </div>
                            </template>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button" x-show="roomPhotosFor({{ $sectionJs }}, group.room).length > 3"
                                    @click="roomPhotosExpanded[group.room.id] = !roomPhotosExpanded[group.room.id]"
                                    class="text-xs font-semibold underline" style="color:var(--text-secondary);"
                                    x-text="roomPhotosExpanded[group.room.id] ? 'Show less' : ('Show all ' + roomPhotosFor({{ $sectionJs }}, group.room).length)"></button>
                            @unless($tailReadOnly)
                            {{-- Bulk case: room → item still needs a chooser (many
                                 destinations), so it keeps its own dropdown here — the
                                 single-photo path moved to the opened view, but there's
                                 no "opened view" for several photos at once. Room →
                                 untagged is single-destination even in bulk, so it's
                                 just a second plain button, no chooser. --}}
                            <template x-if="selectedRoomPhotoIds({{ $sectionJs }}, group.room).length">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs" style="color:var(--text-secondary);" x-text="selectedRoomPhotoIds({{ $sectionJs }}, group.room).length + ' selected'"></span>
                                    <select class="prop-input text-xs" style="max-width:10rem;" x-model.number="roomPhotoTagItemChoice[group.room.id]">
                                        <option value="">Tag to…</option>
                                        <template x-for="i in itemChoicesFor({{ $sectionJs }}, group.room)" :key="i.id">
                                            <option :value="i.id" x-text="i.label"></option>
                                        </template>
                                    </select>
                                    <button type="button" :disabled="!roomPhotoTagItemChoice[group.room.id]"
                                            @click="tagSelectedRoomPhotosToItem({{ $sectionJs }}, group.room, roomPhotoTagItemChoice[group.room.id])"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Tag selected</button>
                                    <button type="button" @click="untagSelectedRoomPhotos({{ $sectionJs }}, group.room)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Untag selected</button>
                                    <button type="button" @click="photoUploader({{ $sectionJs }}).clearSelection()" class="text-xs font-semibold underline" style="color:var(--text-secondary);">Clear</button>
                                </div>
                            </template>
                            @endunless
                        </div>
                    </div>
                </template>

                <div x-show="isRoomOpen({{ $sectionJs }}, group)" class="space-y-1">
                    {{-- Johan, 2026-09-23, property 5792 — "Stop rendering
                         two lists. Render ONE list of rows, where each row
                         has a left cell and a right cell." ONE x-for drives
                         BOTH cells; item N is always item N on both sides
                         by construction, never by luck. Each row is its
                         own 2-column grid (rir-compare-row) — a real CSS
                         Grid container whose two direct children
                         (rir-compare-cell) are DOM siblings, so "Kitchen
                         Ceiling" on the left is structurally, not
                         visually-by-coincidence, beside "Kitchen Ceiling"
                         on the right. If one side has nothing recorded,
                         rental-inspection-item-cell.blade.php's own
                         null-safe reads (conditionForInspection() /
                         itemPhotosForInspection()) render that cell's own
                         empty state in the same footprint — the row never
                         collapses or shifts. --}}
                    <template x-for="item in group.items" :key="item.id">
                        <div class="rir-compare-row py-2" style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; border-bottom:1px solid var(--border);">
                            <div class="rir-compare-cell pl-3 space-y-1.5">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                                @include('corex.properties.partials.rental-inspection-item-cell', ['inspectionJs' => $predecessorJs, 'readOnly' => true])
                            </div>
                            <div class="rir-compare-cell pl-3 space-y-1.5">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                                @include('corex.properties.partials.rental-inspection-item-cell', ['inspectionJs' => $sectionJs, 'readOnly' => $tailReadOnly])
                                @unless($tailReadOnly)
                                {{-- Bulk case: item → room and item → untagged are both
                                     single-destination even in bulk, so this is two plain
                                     buttons, no chooser — tail cell only, the predecessor
                                     cell has no tagging at all. --}}
                                <template x-if="selectedItemPhotoIds({{ $sectionJs }}, item).length">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs" style="color:var(--text-secondary);" x-text="selectedItemPhotoIds({{ $sectionJs }}, item).length + ' selected'"></span>
                                        <button type="button" x-show="group.room" @click="backToRoomSelectedItemPhotos({{ $sectionJs }}, item, group.room)"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back to room</button>
                                        <button type="button" @click="untagSelectedItemPhotos({{ $sectionJs }}, item)"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Untag selected</button>
                                        <button type="button" @click="photoUploader({{ $sectionJs }}).clearSelection()" class="text-xs font-semibold underline" style="color:var(--text-secondary);">Clear</button>
                                    </div>
                                </template>
                                @endunless
                            </div>
                        </div>
                    </template>

                    @unless($tailReadOnly)
                    {{-- §17, Johan 2026-09-21, from Retha's real paper form:
                         one free-text notes box per room, holding evidence
                         that belongs to the whole room, not any single item —
                         "3x nails in wall", "damp under windows in corner".
                         In addition to per-item notes above, not instead.
                         Item 2, 2026-09-22 — autosaves; no Save button. --}}
                    <template x-if="group.room">
                        <div class="pl-3 pt-1" x-init="roomNoteField({{ $sectionJs }}, group.room.id)">
                            <textarea x-model="roomNoteDraft[{{ $sectionJs }} + '_' + group.room.id]"
                                      @input="autosaveRoomNote({{ $sectionJs }}, group.room)"
                                      placeholder="Room notes (e.g. 3x nails in wall, damp under windows in corner)"
                                      rows="2" class="prop-input w-full text-xs"></textarea>
                        </div>
                    </template>
                    @endunless
                </div>
            </div>
        </template>

        @unless($tailReadOnly)
        {{-- §17, Johan 2026-09-21, from Retha's real paper form: a single
             free-text summary for the whole inspection, at the foot — hers
             reads "OVERALL - APARTMENT CLEAN - FAIR - PARTIALLY FURNISHED".
             Item 2, 2026-09-22 — autosaves; no Save button. --}}
        <div class="pt-2 space-y-1" style="border-top:1px solid var(--border);">
            <label class="block text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Overall notes</label>
            <textarea x-model="currentInspection({{ $sectionJs }}).overall_notes" rows="2"
                      @input="autosaveOverallNotes({{ $sectionJs }})"
                      placeholder="e.g. Apartment clean, fair condition, partially furnished"
                      class="prop-input w-full text-xs"></textarea>
        </div>

        <div x-show="lifecycleError" x-cloak class="text-xs" style="color:#ef4444;" x-text="lifecycleError"></div>

        {{-- §15 (2026-09-20) — one shared signing block for both sections:
             per-tenant rows, the landlord row (if Property::
             sellerOwnerContact() resolves one — §15.4), then the agent's
             own signature, which only becomes available once every other
             required party already has a disposition — signed OR refused
             (§15.2a). Refusal (Stage 4) is a first-class, equally-weighted
             outcome, never styled as an error or a problem (Johan: "none of
             these should feel like [an error state] in the UI") — tenant
             signs, landlord refuses is a normal, complete outcome. The
             agent alone has no refusal option. markCompleted() does not
             yet require any of this on either type (Stage 5) — Complete
             still works unsigned/undispositioned in the meantime. --}}
        <template x-if="currentInspection({{ $sectionJs }}).status !== 'awaiting_signature'">
            <div class="flex justify-end pt-1">
                <button type="button" :disabled="hasUnresolvedDiscrepancy({{ $sectionJs }})" @click="startAwaitingSignature({{ $sectionJs }})"
                        class="px-4 py-2 rounded-md text-sm font-semibold"
                        :style="hasUnresolvedDiscrepancy({{ $sectionJs }}) ? 'background:var(--surface-2); color:var(--text-muted);' : 'background:var(--brand-button,#0ea5e9); color:#fff;'">
                    Ready to sign
                </button>
            </div>
        </template>

        <template x-if="currentInspection({{ $sectionJs }}).status === 'awaiting_signature'">
            <div class="space-y-2 pt-1" style="border-top:1px solid var(--border);">
                <template x-for="tenant in inspectionTenants({{ $sectionJs }})" :key="tenant.contact_id">
                    <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm" x-text="tenantName(tenant)" style="color:var(--text-primary);"></span>
                            <template x-if="tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <span class="flex items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                                          x-text="dispositionLabel(tenantDisposition({{ $sectionJs }}, tenant.contact_id))"></span>
                                    <button type="button" x-show="canReplaceWetInk({{ $sectionJs }}, tenantDisposition({{ $sectionJs }}, tenant.contact_id))"
                                            @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-medium underline" style="color:var(--text-secondary);">Replace</button>
                                </span>
                            </template>
                            <template x-if="!tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="openSigningFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                    <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                    <button type="button" @click="openRefusalFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Refuses</button>
                                </div>
                            </template>
                        </div>
                        <template x-if="activeSigningKey === ({{ $sectionJs }} + '_tenant_' + tenant.contact_id)">
                            <div class="space-y-2 pt-2">
                                <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id, $el))"
                                        class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                    <button type="button" @click="saveTenantSignatureFor({{ $sectionJs }}, tenant)" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                                </div>
                            </div>
                        </template>
                        @include('corex.properties.partials.rental-inspection-refusal-form', ['key' => "({$sectionJs} + '_tenant_' + tenant.contact_id)", 'saveMethod' => "saveTenantRefusalFor({$sectionJs}, tenant)"])
                        @include('corex.properties.partials.rental-inspection-wetink-form', ['key' => "({$sectionJs} + '_tenant_' + tenant.contact_id)", 'saveMethod' => "saveTenantWetInkFor({$sectionJs}, tenant)"])
                    </div>
                </template>

                {{-- §15.4 — landlord, property-level. Plain "nothing to sign"
                     line when unresolvable, never hidden and never blocking. --}}
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <template x-if="!landlordContact">
                        <span class="text-xs" style="color:var(--text-muted);">Landlord: not linked to this property — nothing to sign.</span>
                    </template>
                    <template x-if="landlordContact">
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="landlordContact.first_name + ' ' + landlordContact.last_name + ' (Landlord)'"></span>
                                <template x-if="landlordDisposition({{ $sectionJs }})">
                                    <span class="flex items-center gap-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);"
                                              x-text="dispositionLabel(landlordDisposition({{ $sectionJs }}))"></span>
                                        <button type="button" x-show="canReplaceWetInk({{ $sectionJs }}, landlordDisposition({{ $sectionJs }}))"
                                                @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-medium underline" style="color:var(--text-secondary);">Replace</button>
                                    </span>
                                </template>
                                <template x-if="!landlordDisposition({{ $sectionJs }})">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="openSigningFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                        <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                        <button type="button" @click="openRefusalFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Refuses</button>
                                    </div>
                                </template>
                            </div>
                            <template x-if="activeSigningKey === ({{ $sectionJs }} + '_landlord')">
                                <div class="space-y-2 pt-2">
                                    <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_landlord', $el))"
                                            class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_landlord')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                        <button type="button" @click="saveLandlordSignatureFor({{ $sectionJs }})" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                                    </div>
                                </div>
                            </template>
                            @include('corex.properties.partials.rental-inspection-refusal-form', ['key' => "({$sectionJs} + '_landlord')", 'saveMethod' => "saveLandlordRefusalFor({$sectionJs})"])
                            @include('corex.properties.partials.rental-inspection-wetink-form', ['key' => "({$sectionJs} + '_landlord')", 'saveMethod' => "saveLandlordWetInkFor({$sectionJs})"])
                        </div>
                    </template>
                </div>

                <div class="py-1.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">Agent</span>
                        <template x-if="agentDisposition({{ $sectionJs }})">
                            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Signed</span>
                        </template>
                        <template x-if="!agentDisposition({{ $sectionJs }}) && allRequiredPartiesDispositioned({{ $sectionJs }})">
                            <button type="button" @click="openSigningFor({{ $sectionJs }} + '_agent')"
                                    class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Sign</button>
                        </template>
                    </div>
                    <template x-if="activeSigningKey === ({{ $sectionJs }} + '_agent')">
                        <div class="space-y-2 pt-2">
                            <canvas x-init="$nextTick(() => initSignaturePadFor({{ $sectionJs }} + '_agent', $el))"
                                    class="w-full block rounded-md" style="height:110px; touch-action:none; cursor:crosshair; background:#fff; border:1px solid var(--border);"></canvas>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="clearSignatureFor({{ $sectionJs }} + '_agent')" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Clear</button>
                                <button type="button" @click="saveAgentSignatureFor({{ $sectionJs }})" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Save signature</button>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex justify-end pt-1">
                    <button type="button" :disabled="hasUnresolvedDiscrepancy({{ $sectionJs }})" @click="completeInspection({{ $sectionJs }})"
                            class="px-4 py-2 rounded-md text-sm font-semibold"
                            :style="hasUnresolvedDiscrepancy({{ $sectionJs }}) ? 'background:var(--surface-2); color:var(--text-muted);' : 'background:var(--brand-button,#0ea5e9); color:#fff;'">
                        Complete
                    </button>
                </div>
            </div>
        </template>
        @endunless
    </div>
</template>
