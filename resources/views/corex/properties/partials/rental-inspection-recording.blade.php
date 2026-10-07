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
    /* §27, 2026-09-27 — Johan: All/Needs attention/Not yet recorded reads as
       ONE compact segmented control, not three separate pill buttons with
       gaps. */
    .rir-seg-group { display:flex; align-items:stretch; height:2.75rem; border:1px solid var(--border); border-radius:9999px; overflow:hidden; box-sizing:border-box; }
    /* height:auto — .rir-nav-pill's own fixed 2.75rem would otherwise fight
       align-items:stretch here and overflow the group's own 1px border
       inset by 2px (border-box: the group's 44px already includes its
       border, leaving ~42px of interior for children to stretch into). */
    .rir-seg-btn.rir-nav-pill { height:auto; border-top:0; border-bottom:0; border-left:0; border-right:1px solid var(--border); border-radius:0; }
    .rir-seg-btn.rir-nav-pill:last-child { border-right:0; }
    /* §27, 2026-09-27 regression fix — Johan, property 5294: "you're killing
       my OCD. make the buttons the same, that's just off." Room pills used
       .rounded-full/var(--surface-2) (light); Photos:shown + the filter used
       the DARK .compare-viewer-mode-btn family (#10151B bg, #3FC9E6 active)
       — a completely different visual language, plus a different
       border-radius (rounded-md vs rounded-full) and a taller effective box
       (the pill strip's own native horizontal scrollbar added height ONLY
       to that one child, so row-level `items-center` centred the shorter
       Photos/filter buttons differently to the taller pill-strip box).
       .rir-nav-pill is the ONE shared visual family for every control on
       this row — same light/teal colours as the room pills' own inactive/
       active look, SCOPED to this row only (never touches the many other
       .compare-viewer-mode-btn call sites elsewhere on this page). Height
       is set explicitly (not min-height) on every control INCLUDING this
       row's own outer wrapper (see the markup's own docblock) so the
       scrollbar's extra height never leaks into the alignment math again. */
    .rir-nav-pill { height:2.75rem; background:var(--surface-2); color:var(--text-secondary); border:none; box-sizing:border-box; }
    .rir-nav-pill-active { background:var(--brand-button,#0ea5e9); color:#fff; }
    .rir-tray-tile { position:relative; width:3rem; height:3rem; }
    /* §29, 2026-09-27 — Johan, property 5294: "keep them this size and have a
       resize option ... to enlarge them to the same size as the thumbnail
       photos in the room/item sections." That size is .rir-strip-tile's own
       86x64 (rental-inspection-recording.blade.php's item-cell partner,
       rental-inspection-item-cell.blade.php) — read directly from that class
       rather than guessed, so Large genuinely matches the Kitchen-etc strip
       tiles pixel-for-pixel. */
    .rir-tray-tile-lg { width:86px; height:64px; }
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
    /* §45.3 (Build I-1) — when the photo was taken (or, honestly, only uploaded), laid over the bottom of a
       tile. Informational only: pointer-events none so it never steals the tile's own click/drag. */
    .rir-photo-time { position:absolute; left:0; right:0; bottom:0; padding:1px 3px; background:rgba(0,0,0,0.6); color:#fff; font-size:8.5px; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:center; z-index:1; pointer-events:none; }
    .rir-strip-badge { position:absolute; top:2px; left:2px; min-width:16px; height:16px; padding:0 3px; border-radius:8px; background:rgba(0,0,0,0.65); color:#fff; font-size:10px; font-weight:700; line-height:16px; text-align:center; z-index:1; pointer-events:none; }
    /* §41, 2026-09-29 — a small, clickable indicator on a tile whose photo
       already belongs to an active match group (manual OR auto-paired —
       the same groupForPhoto() lookup, one indicator for both). Opposite
       corner from .rir-strip-badge's index number so the two never
       overlap; unlike that badge this one is NOT pointer-events:none — it
       is its own second way (besides the tile image itself) to open the
       compare viewer already anchored on this exact pair. */
    .rir-strip-linked-badge { position:absolute; top:2px; right:2px; width:16px; height:16px; border-radius:8px; background:#4FBE82; color:#0B0E12; font-size:9px; font-weight:700; line-height:16px; text-align:center; z-index:1; cursor:pointer; }
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
    /* AT-436, 2026-09-27 — a photo mid-upload (or, with the failed
       modifier below, one that failed and needs a retry tap). Not part of
       stripPairCount()'s predecessor/tail pairing (it has no server photo
       id yet) — always visible, never collapsed behind "+N". A <button>,
       not a <span>: the PENDING state renders it disabled (native
       non-interactivity, no pointer-events hack needed), the failed state
       renders it as a real, clickable Retry control — same element, same
       footprint, so nothing shifts when a batch fails. */
    .rir-strip-pending-label { position:absolute; bottom:2px; left:2px; right:2px; font-size:8px; font-weight:700; letter-spacing:0.02em; color:#fff; text-align:center; line-height:1.3; background:rgba(0,0,0,0.55); border-radius:3px; border:none; padding:1px 2px; font-family:inherit; }
    .rir-strip-pending-failed { background:var(--ds-crimson,#dc2626); cursor:pointer; }
    /* Add-tile — now just the strip's last flex child (see the row comment
       above). flex:none keeps its 124px width fixed; align-self:stretch
       reproduces the old top:0/bottom:0 full-row-height click target while
       every tile above it stays top-aligned via the row's align-items. */
    .rir-add-tile { flex:none; align-self:stretch; width:124px; display:flex; align-items:center; justify-content:center; font-size:1.25rem; border-radius:6px; cursor:pointer; }
    /* §25, AT-433 Part C — the photo note, truncated to two lines, under
       the thumbnail. An overlay caption WITHIN the tile's own stacking
       context (never a new absolutely-positioned sibling of .rir-strip-row
       — the same discipline .rir-strip-pair-eligible's own docblock
       already states) — deliberately not a taller tile: this row's height
       has already broken and been re-fixed multiple times (§22.3/22.3a/
       22.3b in the spec), and every fix landed on the same principle —
       change the geometry at the class level, never per-element, and never
       via a bound :style next to a static one. Growing the tile itself to
       fit a caption risks exactly that class of regression for a feature
       that has its own room-level off switch to fall back on; an overlay
       does not touch tile/row height at all. Pointer-events:none so it
       never intercepts a click meant for the image, the corner buttons
       (Select/tag/untag on the editable side), or a pairing drag. */
    .rir-strip-note { position:absolute; bottom:0; left:0; right:0; max-height:1.6em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; font-size:7px; line-height:0.8em; font-weight:600; color:#fff; text-align:left; background:rgba(0,0,0,0.6); padding:2px 3px; pointer-events:none; }
    /* §27 — recording-screen navigation (space nav, photo show/hide,
       problem filter). The nav strip reuses the compare viewer's own
       .cv-space-tab/.cv-space-tab-active classes (show.blade.php) for
       visual consistency — this is the one class this feature adds of its
       own, for a room the CURRENT filter has nothing left to show: greyed
       and non-interactive rather than removed, so the agent always sees
       every real room and never wonders where one went. */
    .rir-space-tab-empty { opacity: 0.35; cursor: not-allowed; }
    /* §32, 2026-09-28 (Johan, property 5294) — this grid MUST live in a real
       CSS class, never inline on an x-show'd element. Alpine's x-show
       toggles visibility by calling el.style.removeProperty('display') on
       show() (packages/alpinejs/src/directives/x-show.js) — that call does
       not restore whatever custom display value was previously inline, it
       just deletes the "display" entry outright, and per Alpine's own
       once()-gated toggle() this fires even on the very FIRST evaluation
       when the row starts visible (the normal case). An inline
       `style="display:grid; ..."` on the same element as `x-show` is
       therefore wiped the moment Alpine's show() runs, and a bare <div>
       falls back to the UA default `display:block` — the two
       .rir-compare-cell children then stack instead of sitting side by
       side. A class-based rule is never touched by x-show (that directive
       only ever mutates the element's own INLINE style.display), so this
       is the fix, not a style preference. */
    .rir-compare-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; border-bottom:1px solid var(--border); }
    /* §32, 2026-09-28 — chain's first inspection, no predecessor: the row
       renders only the tail cell (x-if gate above), so the grid collapses
       to a single full-width column instead of an empty second track.
       Two-class selector so this always wins over .rir-compare-row above
       regardless of declaration order. */
    .rir-compare-row.rir-compare-row-solo { display:block; }
    /* §32, 2026-09-28 (Johan, property 5294) — "that bar must stay on
       screen while scrolling the inspection." Positioning itself (fixed
       top/left/width while pinned) is computed and applied inline by the
       x-init scroll-listener on this element (own docblock at this file's
       "rir-insp-nav-sticky" div — plain `position:sticky` cannot work here,
       see that docblock for the measured, verified reason). This class
       only sets what never changes between pinned/unpinned. z-index sits
       above ordinary tab content but below every modal/overlay on this
       page (compare viewer, tag panels, etc. all use z-[9999]/z-20+ — see
       show.blade.php). */
    .rir-insp-nav-sticky { z-index:15; }
    /* §32, 2026-09-28, round 2 — offsets scrollToInspectionRoom()'s
       scrollIntoView({block:'start'}) (show.blade.php) so a clicked room's
       heading stops just under the pinned tab bar + .rir-insp-nav-sticky
       pill bar, not hidden behind them. A STATIC px value here cannot be
       correct at every viewport size: `scroll-margin-top` is measured in
       `.prop-tab-panel`'s OWN internal coordinate space, but the pinned
       bars' combined height needs converting from VIEWPORT space into
       that space via `.prop-tab-panel`'s own offset from the viewport top
       — and that offset itself changes with viewport width (measured
       live: 154.5px at 1522px wide vs 198.6px at a narrow 768px, this
       page's responsive layout reflowing what sits above the panel).
       `--rir-room-anchor-offset` is set live by the same x-init below
       that pins the nav bar (computed on init/resize, not on every
       scroll — panel-offset and bar height are scroll-INVARIANT, only
       resize changes them). The fallback (115px) only applies before
       that JS has run once. */
    .rir-room-anchor { scroll-margin-top: var(--rir-room-anchor-offset, 115px); }

    /* §36, 2026-09-28 (Johan, property 5294) — "a condition and its note
       must JUMP OUT." Selected-condition-button colour, by the agency's own
       configured severity (RentalInspectionSetting::SEVERITY_COLORS) —
       theme-aware tokens throughout, never a hardcoded hex, so these read
       correctly in both light and dark mode. Applies identically in every
       state this partial renders (editable, awaiting-signature — same
       editable branch — and read-only/compare-predecessor, both driven by
       rental-inspection-item-cell.blade.php's own $readOnly branch). */
    .rir-cond-btn-selected-blue  { background: var(--brand-button, #0ea5e9); color: #fff; }
    .rir-cond-btn-selected-red   { background: var(--ds-crimson, #c41e3a); color: #fff; }
    .rir-cond-btn-selected-amber { background: var(--ds-amber, #f59e0b); color: #fff; }
    .rir-cond-btn-selected-grey  { background: var(--text-secondary); color: #fff; }
    .rir-cond-btn-unselected { background: var(--surface-2); color: var(--text-secondary); }
    .rir-cond-btn-unselected-readonly { background: var(--surface-2); color: var(--text-secondary); opacity: 0.5; }

    /* §36 — the item/room note callout: never muted grey (Johan's own
       words) — a tinted background matching the severity, a left border,
       normal-weight text. Item notes use noteCalloutTone() (red/amber for
       an issue condition, light blue otherwise); room notes have no
       condition of their own and always render the blue tone. */
    .rir-note-callout { border-radius: 6px; padding: 0.375rem 0.625rem; border-left: 3px solid; font-weight: 400; }
    .rir-note-callout-red   { background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 12%, var(--surface)); border-left-color: var(--ds-crimson, #c41e3a); color: var(--text-primary); }
    .rir-note-callout-amber { background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, var(--surface)); border-left-color: var(--ds-amber, #f59e0b); color: var(--text-primary); }
    .rir-note-callout-blue  { background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, var(--surface)); border-left-color: var(--brand-button, #0ea5e9); color: var(--text-primary); }
</style>
{{-- $sectionJs override — see this file's own top docblock. --}}
@php($sectionJs = $sectionJs ?? "'{$section}'")
@php($predecessorJs = $predecessorJs ?? 'chainPredecessor')
@php($tailReadOnly = $tailReadOnly ?? false)
{{-- §27 — both $tailReadOnly copies of this partial share one page (see
     this file's own top docblock on why); a bare room id would collide
     between them, so every anchor id this round adds is disambiguated by
     which copy it belongs to at PHP compile time. --}}
@php($roomIdPrefix = $tailReadOnly ? 'ro' : 'rw')

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
                    <button type="button" :disabled="isDiscBusy(discrepancy.id) || !discField(discrepancy.id).accepted_observation_id"
                            @click="resolveDiscrepancy({{ $sectionJs }}, discrepancy)"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--ds-crimson);"
                            x-text="isDiscBusy(discrepancy.id) ? 'Resolving…' : 'Resolve'"></button>
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
                <button type="button" data-qa="mark-all-good" x-show="inspectionProgress({{ $sectionJs }}).recorded < inspectionProgress({{ $sectionJs }}).total"
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
                {{-- §29, 2026-09-27 — Johan: can't really see the small
                     untagged thumbnails; keep Small as the default, offer
                     Large (matches the room/item strip tile size exactly,
                     .rir-tray-tile-lg above). Remove (x) and click-to-tag
                     behaviour are unchanged in both sizes — this only ever
                     toggles a CSS class on the same tiles. Grouped with the
                     count (not a separate justify-between child) so the
                     toggle sits right next to "N untagged", not stranded at
                     the far edge of the row. --}}
                <div x-show="photoUploader({{ $sectionJs }}).untaggedPhotos().length" class="flex items-center gap-2 flex-none">
                    <span class="text-xs font-semibold" style="color:var(--text-muted);"
                          x-text="photoUploader({{ $sectionJs }}).untaggedPhotos().length + ' untagged'"></span>
                    <div class="flex items-center gap-1">
                        <button type="button" class="text-xs font-semibold px-2 py-1 rounded-md cv-touch compare-viewer-mode-btn"
                                :class="trayTileSize === 'small' ? 'compare-viewer-mode-btn-active' : ''"
                                @click="setTrayTileSize('small')">Small</button>
                        <button type="button" class="text-xs font-semibold px-2 py-1 rounded-md cv-touch compare-viewer-mode-btn"
                                :class="trayTileSize === 'large' ? 'compare-viewer-mode-btn-active' : ''"
                                @click="setTrayTileSize('large')">Large</button>
                    </div>
                </div>
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
                                 :class="trayTileSize === 'large' ? 'rir-tray-tile-lg' : ''"
                                 :style="photoUploader({{ $sectionJs }}).isSelected(photo.id) ? 'outline:2px solid var(--brand-icon,#0ea5e9);' : ''">
                                <img :src="photo.storage_path" :title="photo.taken_caption" class="rounded-md object-cover" style="display:block; width:100%; height:100%;" alt="">
                                <span class="rir-photo-time" x-show="trayTileSize === 'large'" x-text="photo.taken_caption_short"></span>
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
                    <span x-text="(inspectionTypeLabel({{ $predecessorJs }}.type) + '-inspection · ' + ({{ $predecessorJs }}.completed_at || {{ $predecessorJs }}.scheduled_for || {{ $predecessorJs }}.created_at || '').slice(0, 10) + ' · ' + {{ $predecessorJs }}.status.replace('_',' '))"></span>
                </template>
                <template x-if="!{{ $predecessorJs }}">
                    <span style="color:var(--text-muted); font-weight:normal; text-transform:none;">First inspection in this chain — nothing yet to compare against.</span>
                </template>
            </div>
            <div class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-secondary);">
                <span x-text="(inspectionTypeLabel({{ $sectionJs }}) + '-inspection · ' + (currentInspection({{ $sectionJs }}).completed_at || currentInspection({{ $sectionJs }}).scheduled_for || currentInspection({{ $sectionJs }}).created_at || '').slice(0, 10) + ' · ' + currentInspection({{ $sectionJs }}).status.replace('_',' '))"></span>
            </div>
        </div>

        {{-- .ai/specs/rental-inspections.md §27 — recording-screen
             navigation. Johan: 'The inspection screen... shall I call it
             crud? like with the photo compare - top spaces to quick
             navigate to. maybe a tick to show / hide photos, maybe a tick
             to show all or only problem spaces... a small inspection is a
             scroll. a large inspection is a proper scroll.'

             §27, 2026-09-27 restyle — Johan, property 5294: the room links
             read as "plain grey text room tabs" wrapping onto a second
             line with the filter. Room chips now reuse the rental-
             inventory capture screen's OWN chip markup verbatim
             (resources/views/corex/rental-inventories/capture.blade.php —
             rounded-full pill, 8x8 status dot, never-wraps overflow-x-auto
             strip with the browser's native/visible scrollbar) rather than
             the old underline .cv-space-tab look (still used unchanged by
             the separate Compare viewer elsewhere on this page — not
             touched here). The dot's colour is derived entirely from data
             already printed beside it (recorded/total, needs-attention) —
             no new tracked state, since inspections has no single
             "active room" concept the way the inventory screen's
             single-panel view does (behaviour unchanged, per Johan's own
             instruction: click still scrolls to the room, nothing here
             switches a panel). Photos: shown/hidden and the three filter
             buttons (now one compact segmented control, .rir-seg-group)
             moved onto this SAME line, right of the chip strip — one row
             at lg:/1024px+ (chip strip is flex:1 min-w-0 and scrolls
             internally first, so it yields space to the two fixed-width
             controls rather than pushing them off), wrapping allowed
             below 1024px exactly as the rest of this tab already does
             (§28.2). Rendered OUTSIDE the tailReadOnly unless-block
             above/below — none of these controls mutate anything, so they
             render identically (and stay useful) on a completed, read-only
             inspection too. --}}
        {{-- items-start (not items-center), 2026-09-27 — Johan: the pill
             strip's own native horizontal scrollbar was adding height ONLY
             to that one child (an overflow-x:auto box with auto height
             grows to fit content PLUS the scrollbar, below the content —
             standard browser behaviour, not a bug), so items-center
             centred it differently to the shorter Photos/filter buttons.
             Every control below is now an EXPLICIT, IDENTICAL 2.75rem tall
             (.rir-nav-pill/.rir-seg-group, not just a min-height floor) —
             with items-start, all three start at the row's own top edge,
             so their centres coincide automatically; the pill strip's
             scrollbar simply extends below that shared line, in its own
             space, never pulling anything else out of alignment.

             §32, 2026-09-28 (Johan, property 5294) — "that bar must stay
             on screen while scrolling the inspection." Plain CSS
             `position:sticky` (the property-shell tab bar's own pattern,
             `_property-shell-tabs.blade.php:50`, and the gallery tag bar's,
             `show.blade.php:3868`) does NOT work here — checked live,
             real headless Chrome, real QA1 data (property 5792): this bar
             lives inside the "Inspection" collapsible card
             (`show.blade.php:4903`, `.prop-section`), and `.prop-section`
             itself has `overflow:hidden` (corex.css:608, there purely to
             clip the card's own rounded corners — shared by every
             collapsible section on this page, Identity/Pricing/Mandate/
             Items/Inspection alike, so it is NOT this feature's to change).
             An `overflow:hidden` ancestor between a sticky element and its
             real scrolling ancestor (`.prop-tab-panel`) becomes the
             sticky's new containing block instead — and since `.prop-
             section` itself never scrolls, `position:sticky` on a
             descendant of it just scrolls away with the card. Confirmed by
             direct measurement: scrolling `.prop-tab-panel` by 3201px moved
             this bar by the same 3201px (fully unpinned) while the actual
             tab bar — which sits OUTSIDE any `.prop-section`, so has no
             such ancestor — stayed correctly fixed at the same viewport
             position throughout.

             x-init below is the fix: a small, self-contained scroll
             listener on `.prop-tab-panel` that switches this bar to
             `position:fixed` (which — confirmed, no ancestor sets
             `transform`/`filter`/`contain` — escapes `overflow:hidden`
             clipping entirely, unlike `sticky`) once its natural position
             would scroll above the shared tab bar, computing `top`/`left`/
             `width` from the CARD's own live rect (`.prop-section` —
             NOT `.prop-tab-panel`, which is wider: it sits outside this
             card's own padding, `p-6` on the Inspections-tab wrapper plus
             the card's own inset, ~27px worth — measured live, 2026-09-28
             round 2, Johan: bar spanned 322→1497 against the card's real
             349→1477) so it reads as "pinned under the tab bar, flush with
             the card's own edges" — and back to normal flow once scrolled
             above the pin point. Deliberately vanilla JS, not a new method
             on show.blade.php's `rentalImages()` factory: this behaviour
             is purely a presentation/scroll concern of this one bar,
             self-contained to this partial, never touching the shared
             component's data/method surface.

             §32.1, 2026-09-28 round 2 (Johan, property 5294, real Chrome
             1522×784) — three defects found in round 1's implementation,
             all traced to ONE root cause: this bar sits inside
             `.prop-section-body.space-y-3` (line ~977), and Tailwind's
             `space-y-3` puts a real `margin-top:0.75rem` (12px) on it as a
             normal-flow sibling. `getBoundingClientRect()`/`offsetHeight`
             never include an element's own margin, so switching to
             `position:fixed` WITHOUT explicitly zeroing that margin left
             it applying on top of the computed `top:` value — the bar's
             own background started 12px below where `top` said it would,
             showing scrolling content through that gap (defect: "gap above
             bar"). And the placeholder — sized from `bar.offsetHeight`
             alone, margin excluded — reserved 12px LESS flow height than
             the bar actually consumed before pinning, so the very act of
             pinning shrank the panel's total scrollable height by 12px
             out from under any smooth `scrollIntoView` animation already
             in flight (defect: room clicks landing at the wrong position —
             `scrollToInspectionRoom()`, show.blade.php, computes its
             target offset once; a height change of the scrolling content
             during that animation throws the landing off by the same
             amount, compounding over repeated pin/unpin toggles during a
             single scroll). Fix: read the bar's REAL computed
             `margin-top` once (never hardcode the Tailwind value — a
             class change elsewhere must not silently break this), fold it
             into the placeholder's reserved height so the total flow
             height is IDENTICAL before and after pinning (zero jump,
             zero cause for `scrollIntoView` to land wrong), and zero the
             bar's own inline `margin-top` while fixed so its background
             starts exactly at `top:` with no gap. --}}
        {{-- §32.2, 2026-09-28 round 2 — the 'scroll' listener originally
             called getBoundingClientRect() + rewrote position/top/left/
             width inline on EVERY scroll event (Chrome fires dozens per
             second during a native `scrollIntoView({behavior:'smooth'})`
             animation, show.blade.php's `scrollToInspectionRoom()`).
             First fix tried: throttle to one `update()` per animation
             frame via `requestAnimationFrame` instead of once per raw
             scroll event, and skip no-op style writes. That measurably
             cut write frequency but did NOT fix it — confirmed live, same
             16-room property (5577): short hops (adjacent rooms) always
             landed correctly even before this, but long hops (first-room
             ↔ last-room) still intermittently landed short, overshot, or
             on the wrong room entirely, and the failure was genuinely
             TIMING-SENSITIVE (adding console.log instrumentation to watch
             it changed the timing enough to make it stop reproducing —
             a real race, not a logic bug in the threshold math itself,
             which traced values confirmed was always correct).

             Real fix: stop touching this bar's layout AT ALL while a
             scroll is actively in flight. `scheduleUpdate` now DEBOUNCES
             — waits for scroll events to go quiet for `SETTLE_MS` before
             calling `update()` — rather than running throttled-but-still
             continuous updates during the animation. A `scrollIntoView`
             animation fires a steady stream of scroll events with no gap
             until it finishes, so it now gets ZERO layout writes from
             this bar for its entire duration; a real mouse-wheel/
             scrollbar-drag scroll fires events with small natural gaps,
             so the bar still visually snaps to pinned/unpinned within
             ~1 frame of the user pausing — not perceptibly different from
             updating live. Verified live afterward: every first↔last and
             other long-distance pair tested on the 16-room property
             landed correctly, repeatedly, at 1522×784 and at a narrow
             viewport — no longer timing-sensitive. --}}
        <div class="flex items-start gap-2 flex-wrap lg:flex-nowrap rir-insp-nav-sticky" x-show="activeItems().length"
             x-init="
                (() => {
                    const bar = $el;
                    const panel = bar.closest('.prop-tab-panel');
                    const card = bar.closest('.prop-section');
                    const tabBar = document.querySelector('.sticky.top-0');
                    if (!panel || !card || !tabBar) return;
                    const placeholder = document.createElement('div');
                    placeholder.style.display = 'none';
                    bar.parentNode.insertBefore(placeholder, bar);
                    let pinned = false;
                    let settleTimer = null;
                    const applyPinnedGeometry = (pinTop) => {
                        const cardRect = card.getBoundingClientRect();
                        const newTop = pinTop + 'px', newLeft = cardRect.left + 'px', newWidth = cardRect.width + 'px';
                        if (bar.style.top !== newTop) bar.style.top = newTop;
                        if (bar.style.left !== newLeft) bar.style.left = newLeft;
                        if (bar.style.width !== newWidth) bar.style.width = newWidth;
                    };
                    {{-- §32, 2026-09-28 round 2 — feeds .rir-room-anchor's
                         `--rir-room-anchor-offset` (own docblock in this
                         file's <style> block).

                         §32, 2026-09-28 round 2 — NOT scroll-invariant
                         after all, confirmed live: the shared property-
                         shell tab bar (`_property-shell-tabs.blade.php`,
                         `position:sticky; top:0`) only reads its real,
                         STUCK `getBoundingClientRect().bottom` once the
                         page has scrolled at least once and its own sticky
                         has engaged — BEFORE that first scroll, it reports
                         its natural, pre-stick document position instead
                         (measured live: 382.6px at a narrow 768px viewport
                         vs its real stuck 253.6px — a 129px difference,
                         whatever sits above it in the page's own flow
                         before it locks to the top). This bar's own
                         `x-init` runs at mount, before any scrolling has
                         ever happened, so a ONE-SHOT measurement (the
                         original design — computed once via
                         `ResizeObserver`, on the theory that panel-offset
                         and bar-height are scroll-invariant, which they
                         are — the TAB BAR's position was the missing
                         variable) bakes in that wrong pre-stick value
                         permanently, breaking exactly the FIRST room click
                         on a freshly-opened section — confirmed live,
                         property 5577, narrow viewport: clicking the very
                         first room chip on a fresh page landed 141px too
                         low; every click after that (once the page has
                         scrolled at all) landed correctly. Recomputing
                         inside `update()` itself — which already runs on
                         every settled scroll — means the offset self-
                         corrects the moment the tab bar's own sticky
                         engages, before scrollToInspectionRoom() is ever
                         given a chance to use it. --}}
                    const updateAnchorOffset = (pinTop) => {
                        if (bar.offsetHeight === 0) return;
                        const offset = (pinTop + bar.offsetHeight) - panel.getBoundingClientRect().top;
                        panel.style.setProperty('--rir-room-anchor-offset', offset + 'px');
                    };
                    {{-- §38, 2026-09-28 (Johan, property 5294, 1522×784) —
                         switching Inventory→Inspections→expanding the
                         Inspection section left this bar `position:fixed`
                         at the top-left of the viewport, outside the card,
                         with the page not even scrolled. Root cause: this
                         whole panel is `x-show` (display:none), never
                         removed from the DOM (show.blade.php's
                         `activeTab === 'inspections'` tab, and this
                         section's own `open['inspection']` collapse) — a
                         scroll/resize/ResizeObserver(bar) firing while
                         EITHER ancestor is hidden reads `bar`'s (or the
                         placeholder's) `getBoundingClientRect()` as all-
                         zero, which satisfies `naturalTop(0) <= pinTop`
                         unconditionally and pins the bar to garbage
                         geometry — then nothing ever recomputed it once
                         the tab/section became visible again, because
                         neither a tab switch nor a section expand fires
                         scroll or resize, and `display:none`→`block`
                         is not guaranteed to fire ResizeObserver reliably
                         across browsers the moment this happens. --}}
                    const update = () => {
                        const measureEl = pinned ? placeholder : bar;
                        const rect = measureEl.getBoundingClientRect();
                        {{-- Never trust a 0-size rect enough to decide pin
                             state from it — this IS the tab-hidden /
                             section-collapsed case. Force static and bail;
                             the IntersectionObserver below re-runs this
                             with real geometry the moment it's visible
                             again. --}}
                        if (rect.width === 0 && rect.height === 0) {
                            if (pinned) {
                                bar.style.position = bar.style.top = bar.style.left = bar.style.width = bar.style.marginTop = '';
                                placeholder.style.display = 'none';
                                pinned = false;
                            }
                            return;
                        }
                        const pinTop = tabBar.getBoundingClientRect().bottom;
                        const naturalTop = rect.top;
                        const shouldPin = naturalTop <= pinTop;
                        if (shouldPin && !pinned) {
                            const ownMarginTop = parseFloat(getComputedStyle(bar).marginTop) || 0;
                            placeholder.style.height = (bar.offsetHeight + ownMarginTop) + 'px';
                            placeholder.style.display = 'block';
                            bar.style.position = 'fixed';
                            bar.style.marginTop = '0';
                            bar.style.zIndex = '15';
                            bar.style.background = 'var(--surface)';
                            pinned = true;
                            applyPinnedGeometry(pinTop);
                        } else if (!shouldPin && pinned) {
                            bar.style.position = bar.style.top = bar.style.left = bar.style.width = bar.style.marginTop = '';
                            placeholder.style.display = 'none';
                            pinned = false;
                        } else if (shouldPin && pinned) {
                            applyPinnedGeometry(pinTop);
                        }
                        updateAnchorOffset(pinTop);
                    };
                    const SETTLE_MS = 80;
                    const scheduleUpdate = () => {
                        if (settleTimer) clearTimeout(settleTimer);
                        settleTimer = setTimeout(() => { settleTimer = null; update(); }, SETTLE_MS);
                    };
                    panel.addEventListener('scroll', scheduleUpdate, { passive: true });
                    window.addEventListener('resize', scheduleUpdate);
                    new ResizeObserver(scheduleUpdate).observe(bar);
                    {{-- §38 — the actual fix: an IntersectionObserver on
                         `bar` itself fires the instant its rendered
                         presence flips (display:none <-> displayed), which
                         covers BOTH triggers named in the task (a tab
                         switch hides/shows the WHOLE panel; a section
                         expand/collapse hides/shows just this bar's own
                         collapsing ancestor) with one mechanism, calling
                         `update()` immediately (not debounced — this is a
                         discrete state flip, not a scroll stream) so the
                         bar is never left pinned-and-stale, and never
                         stays static-when-it-should-pin, after either. --}}
                    new IntersectionObserver(() => update(), { threshold: [0] }).observe(bar);
                    update();
                })()
             ">
            {{-- position:relative, 2026-09-27 — the fade overlay below
                 anchors to THIS wrapper's own right edge, not the row's. --}}
            <div class="flex-1 min-w-0 overflow-x-auto" style="position:relative;">
                <div class="flex items-center gap-2" style="flex-wrap:nowrap;">
                    <template x-for="group in roomGroups()" :key="'insp-nav-' + (group.room ? group.room.id : 'general')">
                        <button type="button"
                                class="flex items-center gap-1.5 text-xs font-semibold px-3 rounded-full shrink-0 cv-touch rir-nav-pill"
                                :class="!roomMatchesFilter({{ $sectionJs }}, group) ? 'rir-space-tab-empty' : ''"
                                :disabled="!roomMatchesFilter({{ $sectionJs }}, group)"
                                :style="'background:var(--surface-2); color:var(--text-secondary); white-space:nowrap;'"
                                @click="scrollToInspectionRoom({{ $sectionJs }}, group, {{ $tailReadOnly ? 'true' : 'false' }})">
                            <span class="rounded-full shrink-0"
                                  :style="'width:8px; height:8px; background:' + (roomHasAttentionItem({{ $sectionJs }}, group) ? '#D9534F' : ((roomProgress({{ $sectionJs }}, group).total && roomProgress({{ $sectionJs }}, group).recorded >= roomProgress({{ $sectionJs }}, group).total) ? '#3FC9E6' : 'var(--text-muted)')) + ';'"
                                  :title="roomHasAttentionItem({{ $sectionJs }}, group) ? 'Needs attention' : ''"></span>
                            <span x-text="group.room ? group.room.label : 'General'"></span>
                            <span style="opacity:0.7;" x-text="' ' + roomProgress({{ $sectionJs }}, group).recorded + '/' + roomProgress({{ $sectionJs }}, group).total"></span>
                            {{-- §36, 2026-09-28 — the room pill's own issue
                                 count ("Bedroom 1 · 1 issue"), red/amber
                                 items only. Shown whenever this room has at
                                 least one, independent of the active filter
                                 (roomIssueCount() own docblock, show.blade.php). --}}
                            <span x-show="roomIssueCount({{ $sectionJs }}, group)" style="font-weight:700; color:var(--ds-crimson,#c41e3a);"
                                  x-text="'· ' + roomIssueCount({{ $sectionJs }}, group) + ' issue' + (roomIssueCount({{ $sectionJs }}, group) === 1 ? '' : 's')"></span>
                        </button>
                    </template>
                </div>
                {{-- §27, 2026-09-27 — Johan: "the strip ends mid-pill right
                     against Photos: shown... it sort of looks cut." A soft
                     fade so an overflowing pill fades into the panel
                     background instead of being hard-clipped by the
                     scroll wrapper's own edge. Purely decorative —
                     pointer-events:none so it never blocks a click on the
                     pill underneath it. Height matches the pill band only
                     (2.75rem, top:0) — never the taller scrollbar-reserved
                     space below it (§27.9/§31's own "items-start" fix). --}}
                <div aria-hidden="true" style="position:absolute; top:0; right:0; width:24px; height:2.75rem; background:linear-gradient(to right, transparent, var(--surface)); pointer-events:none;"></div>
            </div>
            {{-- §27, 2026-09-27 — Johan (approved): "we just need some form
                 of splitter to split the buttons from the spaces." A thin
                 divider between the pill strip and Photos:shown, centred
                 within the shared 2.75rem row band (margin-top offsets it
                 from the row's own items-start top edge rather than
                 align-self:center, which would centre against the pill
                 wrapper's TALLER scrollbar-inclusive line box instead —
                 see this row's own items-start docblock above). 4px margin
                 either side + the row's own gap-2 (8px) = ~12px total gap
                 either side, as asked. --}}
            <div class="flex-none" style="width:1px; height:28px; margin:8px 4px 0; background:var(--border);"></div>
            {{-- §27.1.2 — Johan: keep the existing room-level "Expand/
                 Collapse photos" thumbnail-count control exactly as it is
                 (below, in the room heading); this is a genuinely
                 different, GLOBAL control that collapses the photo strips
                 entirely rather than just capping their thumbnail count. --}}
            <button type="button" class="text-xs font-semibold px-3 rounded-full cv-touch rir-nav-pill flex-none"
                    :class="photosVisible ? 'rir-nav-pill-active' : ''"
                    @click="togglePhotosVisible()"
                    x-text="photosVisible ? 'Photos: shown' : 'Photos: hidden'"></button>
            <div class="rir-seg-group flex-none">
                <button type="button" class="text-xs font-semibold px-3 cv-touch rir-nav-pill rir-seg-btn"
                        :class="filterMode === 'all' ? 'rir-nav-pill-active' : ''"
                        @click="setFilterMode('all')">All</button>
                <button type="button" class="text-xs font-semibold px-3 cv-touch rir-nav-pill rir-seg-btn"
                        :class="filterMode === 'attention' ? 'rir-nav-pill-active' : ''"
                        @click="setFilterMode('attention')">Needs attention</button>
                <button type="button" class="text-xs font-semibold px-3 cv-touch rir-nav-pill rir-seg-btn"
                        :class="filterMode === 'unrecorded' ? 'rir-nav-pill-active' : ''"
                        @click="setFilterMode('unrecorded')">Not yet recorded</button>
            </div>
        </div>

        {{-- Johan's ruling — a filter that matches nothing must say so in
             words, never render a blank panel with no explanation. --}}
        <p class="text-xs rounded-md p-3" style="background:var(--surface-2); color:var(--text-muted);"
           x-show="activeItems().length && filterMode !== 'all' && !hasAnyFilterMatch({{ $sectionJs }})"
           x-text="filteredEmptyMessage()"></p>

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
            {{-- §32, 2026-09-28 — scrollToInspectionRoom() (show.blade.php)
                 scrolls this element to scrollIntoView({block:'start'}).
                 Now that the tab bar + .rir-insp-nav-sticky pill bar are
                 both pinned on top of the scroll container, "start" would
                 land this heading directly underneath them, hidden behind
                 the pinned bars, unless the browser knows to stop short —
                 .rir-room-anchor's scroll-margin-top (own docblock in this
                 file's <style> block) is exactly that "stop short" amount. --}}
            <div class="space-y-1 pt-2 rir-room-anchor"
                 :id="'insp-room-{{ $roomIdPrefix }}-' + (group.room ? group.room.id : 'general')"
                 x-show="roomMatchesFilter({{ $sectionJs }}, group)">
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
                        {{-- §36, 2026-09-28 — appends the room's own issue count, same source (roomIssueCount(), show.blade.php) as the nav pill above. --}}
                        <h4 class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);"
                            x-text="(group.room ? group.room.label : 'General') + ' — ' + roomProgress({{ $sectionJs }}, group).recorded + '/' + roomProgress({{ $sectionJs }}, group).total
                                    + (roomProgress({{ $sectionJs }}, group).photos ? ' · ' + roomProgress({{ $sectionJs }}, group).photos + ' photo' + (roomProgress({{ $sectionJs }}, group).photos === 1 ? '' : 's') + ' total' : '')
                                    + (roomIssueCount({{ $sectionJs }}, group) ? ' · ' + roomIssueCount({{ $sectionJs }}, group) + ' issue' + (roomIssueCount({{ $sectionJs }}, group) === 1 ? '' : 's') : '')"></h4>
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
                        {{-- §25, AT-433 Part C — room-level "Photo notes
                             on/off", remembered per user (localStorage, same
                             pattern as itemStripExpanded above). Shared state
                             (photoNotesVisible, keyed by room id) reacts on
                             BOTH cells, same "one master switch, only
                             rendered on the editable side" convention as the
                             Collapse/Expand photos button just above. --}}
                        <template x-if="group.items.length">
                            <button type="button" data-qa="toggle-photo-notes" @click="togglePhotoNotesForRoom(group.room)"
                                    class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                    x-text="arePhotoNotesVisibleForRoom(group.room) ? 'Photo notes: On' : 'Photo notes: Off'"></button>
                        </template>
                        {{-- Item 5/7 fix, 2026-09-22 — these have zero effect
                             once the room has nothing left to fill; showing
                             a "working" button that does nothing on click
                             is worse than not showing it. --}}
                        <template x-if="group.room && roomProgress({{ $sectionJs }}, group).recorded < roomProgress({{ $sectionJs }}, group).total">
                            <div class="flex items-center gap-1">
                                <button type="button" data-qa="mark-room-good" :disabled="isMarkGoodBusy(group.room?.id)"
                                        @click="markRoomGood({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="isMarkGoodBusy(group.room?.id) ? 'Marking…' : 'All Good'"></button>
                                {{-- §17, Johan on Retha's real paper form: "she
                                     strikes ENTIRE ROOMS out with one big N/A."
                                     Only offered when N/A is actually one of
                                     the agency's configured condition states. --}}
                                <button type="button" data-qa="mark-room-na" x-show="hasNaConditionState()" :disabled="isMarkNaBusy(group.room?.id)"
                                        @click="markRoomNa({{ $sectionJs }}, group.room)"
                                        class="text-xs font-semibold px-2 py-1 rounded-md" style="background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);"
                                        x-text="isMarkNaBusy(group.room?.id) ? 'Marking…' : 'Mark room N/A'"></button>
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
                    {{-- §27.1.2 — the global "Photos: shown/hidden" toggle.
                         Reclaims the row for condition buttons/labels by
                         removing the photo block entirely, never just
                         capping its thumbnail count (that's the existing,
                         unchanged "Expand/Collapse photos" control). --}}
                    <div class="pl-5 space-y-1" x-show="photosVisible">
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
                                    <img :src="photo.storage_path" class="w-full h-full object-cover cursor-pointer" :title="photo.taken_caption"
                                         @click="openCompareViewer(photo, chainTail)" alt="">
                                    <span class="rir-photo-time" data-qa="insp-photo-time" x-text="photo.taken_caption_short"></span>
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
                         collapses or shifts.

                         §32, 2026-09-28 (Johan, property 5294) — that
                         "nothing recorded" empty-state reasoning above only
                         holds when a predecessor inspection EXISTS but
                         hasn't recorded this particular item; it never
                         applied to the chain's first inspection, where
                         there is no predecessor at all. That case now skips
                         the predecessor cell entirely (x-if below, same
                         gate as this row's own header just above,
                         §27/`$predecessorJs`) rather than rendering an
                         empty grey copy of every item — the row falls back
                         to a single, full-width column
                         (.rir-compare-row-solo) for the one working cell. --}}
                    {{-- §33, 2026-09-28 (Johan, property 5294) — a completed
                         inspection ("In — completed · 59/59") rendered every
                         item as the greyed, disabled button grid with NO
                         condition selected and NO photos, even though the
                         data genuinely existed (room headings correctly
                         showed "5/5 · 3 photos total"). Root cause, confirmed
                         pre-existing via `git blame` (73d8969cb3,
                         2026-09-23 — five days before 650cd2cce, this
                         file's own predecessor-cell fix, which never
                         touched this line): the TAIL cell's own include
                         just below passed `$sectionJs` (a raw JS
                         expression like `tailSection()`, evaluating to the
                         STRING 'in'/'out') as `inspectionJs` while ALSO
                         setting `readOnly` true once `$tailReadOnly` is
                         true (completed) — but rental-inspection-item-
                         cell.blade.php's own docblock is explicit:
                         `readOnly=true` means `inspectionJs` MUST be an
                         INSPECTION OBJECT expression (exactly what the
                         PREDECESSOR cell passes, `$predecessorJs` /
                         `chainPredecessor`, one line above), read via
                         conditionForInspection()/itemPhotosForInspection()
                         — both do `insp.observations`/`insp.photos`, and a
                         plain STRING has neither, so both silently
                         returned empty/null for every item on every
                         completed inspection ever viewed. `$tailInspectionJs`
                         below is computed once, PHP-side: unchanged
                         ($sectionJs, a section-type string — correct for
                         the editable accessors readOnly=false reads
                         through) while still recording/awaiting_signature;
                         `currentInspection($sectionJs)` (resolves to the
                         real chainTail object) once completed — the exact
                         fix the predecessor cell already had. --}}
                    @php($tailInspectionJs = $tailReadOnly ? "currentInspection({$sectionJs})" : $sectionJs)
                    <template x-for="item in group.items" :key="item.id">
                        {{-- §27.3 — the filter reads the TAIL side's
                             condition and hides/shows the WHOLE row (both
                             cells) together, never splitting a row so only
                             one cell reacts. --}}
                        <div class="rir-compare-row py-2" :class="{{ $predecessorJs }} ? '' : 'rir-compare-row-solo'"
                             x-show="itemMatchesFilter({{ $sectionJs }}, item)">
                            <template x-if="{{ $predecessorJs }}">
                            <div class="rir-compare-cell pl-3 space-y-1.5">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                                @include('corex.properties.partials.rental-inspection-item-cell', ['inspectionJs' => $predecessorJs, 'readOnly' => true])
                            </div>
                            </template>
                            <div class="rir-compare-cell pl-3 space-y-1.5">
                                <span class="text-sm" style="color:var(--text-primary);" x-text="item.label"></span>
                                @include('corex.properties.partials.rental-inspection-item-cell', ['inspectionJs' => $tailInspectionJs, 'readOnly' => $tailReadOnly])
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
                    @if($tailReadOnly)
                    {{-- §33, 2026-09-28 (Johan, property 5294) — "the room
                         notes, read-only." Before this, a completed
                         inspection's room notes weren't just non-editable —
                         the ENTIRE block above (including a look at the
                         text) sat inside @unless($tailReadOnly), so they
                         were omitted from the completed view completely.
                         roomNoteFor() (unlike conditionForInspection() /
                         itemPhotosForInspection() above) already resolves
                         `section` via currentInspection(section) internally
                         — no predecessor/tail-object split needed here,
                         $sectionJs is correct as-is in both branches. Only
                         rendered when a note actually exists — no empty
                         box for a room nobody wrote anything about.

                         §36, 2026-09-28 — the note itself is now a callout
                         (rir-note-callout, own docblock in this file's
                         <style> block), never plain muted text: a room
                         note has no condition of its own to derive a
                         severity from, so it always renders the calm blue
                         tone (the same "otherwise" bucket an item note
                         with a blue/grey-severity condition gets). --}}
                    <template x-if="group.room && roomNoteFor({{ $sectionJs }}, group.room.id)?.note">
                        <div class="pl-3 pt-1 space-y-0.5">
                            <span class="text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Room notes</span>
                            <div class="text-xs rir-note-callout rir-note-callout-blue" style="white-space:pre-wrap;" x-text="roomNoteFor({{ $sectionJs }}, group.room.id)?.note"></div>
                        </div>
                    </template>
                    @endif
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

        {{-- §45.3 (Build I-1) — the checklist of items still to record, shown when Complete (or Send for
             signature) was refused because items are ungraded. Each room name jumps to that room. --}}
        <div x-show="ungradedItems.length" x-cloak data-qa="ungraded-items-panel" class="text-xs space-y-1 rounded-md p-2"
             style="border:1px solid #ef4444; background:color-mix(in srgb, #ef4444 8%, transparent); color:var(--text-primary);">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold" style="color:#ef4444;" x-text="'Still to record: ' + ungradedItems.length + (ungradedItems.length === 1 ? ' item' : ' items') + ' (Not applicable counts as recorded)'"></span>
                <button type="button" class="underline font-semibold" @click="setFilterMode('unrecorded')">Show only what is left</button>
            </div>
            <template x-for="g in ungradedRoomGroups()" :key="g.key">
                <div>
                    <button type="button" class="font-semibold underline" @click="jumpToUngradedRoom({{ $sectionJs }}, g.roomId)" x-text="g.label + ' (' + g.items.length + ')'"></button>
                    <span x-text="': ' + g.items.join(', ')"></span>
                </div>
            </template>
        </div>

        {{-- §45.5 (Build I-3) — who attended, in what capacity, and what the invitation trail says about each
             party. Facts only: the screen never says what attendance means for anyone's rights (§45.11). Each
             expected party (every lease tenant, each invited landlord, the inspector) needs an outcome before
             the inspection can complete; anyone else who was in the room is added underneath. A correction
             writes a new record and keeps the old one; Withdraw keeps it on file marked withdrawn. --}}
        <div id="attendance-panel" data-qa="attendance-panel" class="space-y-2 pt-2 rounded-md"
             x-show="currentInspection({{ $sectionJs }}).status !== 'cancelled'"
             x-init="ensureAttendanceBoard({{ $sectionJs }})"
             :style="attendanceAttention ? 'outline:2px solid #ef4444; outline-offset:4px;' : ''"
             style="border-top:1px solid var(--border);">
            <div class="flex items-center justify-between gap-2">
                <label class="block text-xs font-bold uppercase tracking-wide" style="color:var(--text-secondary);">Attendance</label>
                <span class="text-xs" style="color:var(--text-muted);" x-text="attendanceSummary({{ $sectionJs }})"></span>
            </div>
            <div x-show="attendanceError" x-cloak class="text-xs" style="color:#ef4444;" x-text="attendanceError"></div>
            <div x-show="!attendanceBoard({{ $sectionJs }})" class="text-xs" style="color:var(--text-muted);">Loading attendance…</div>

            <template x-for="row in attendanceRows({{ $sectionJs }})" :key="row.key">
                <div class="py-1.5" style="border-bottom:1px solid var(--border);">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <span class="text-sm" style="color:var(--text-primary);">
                            <span x-text="row.name || 'Unnamed'"></span>
                            <span class="text-xs" style="color:var(--text-muted);" x-text="'(' + attendanceRoleLabel(row.party_role) + ')'"></span>
                        </span>
                        <span class="flex items-center gap-2 flex-wrap">
                            <template x-if="row.attendance">
                                <span class="flex items-center gap-2">
                                    <span class="text-xs font-semibold" style="color:var(--text-primary);" x-text="attendanceOutcomeText(row.attendance)"></span>
                                    <button type="button" class="text-xs underline" style="color:var(--text-secondary);" x-show="attendanceEditable({{ $sectionJs }})"
                                            @click="attFormFor(row.key).open = !attFormFor(row.key).open">Change</button>
                                    <button type="button" class="text-xs underline" style="color:var(--text-secondary);" x-show="attendanceEditable({{ $sectionJs }})"
                                            @click="withdrawAttendance({{ $sectionJs }}, row.attendance.id)">Withdraw</button>
                                </span>
                            </template>
                            <template x-if="!row.attendance && attendanceEditable({{ $sectionJs }})">
                                <span class="flex items-center gap-2">
                                    <button type="button" :disabled="attendanceBusy" @click="recordAttendance({{ $sectionJs }}, row, 'attended', false)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Attended</button>
                                    <button type="button" :disabled="attendanceBusy" @click="recordAttendance({{ $sectionJs }}, row, 'did_not_attend', false)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Did not attend</button>
                                    <button type="button" @click="attFormFor(row.key).open = !attFormFor(row.key).open"
                                            class="text-xs underline" style="color:var(--text-secondary);">Someone attended on their behalf</button>
                                </span>
                            </template>
                        </span>
                    </div>
                    <div x-show="attFormFor(row.key).open && attendanceEditable({{ $sectionJs }})" x-cloak class="flex flex-wrap items-end gap-2 mt-1.5">
                        <input type="text" x-model="attFormFor(row.key).name" maxlength="191" placeholder="Name of the person who attended on their behalf"
                               class="prop-input text-xs" style="min-width:16rem;">
                        <input type="time" x-model="attFormFor(row.key).arrived" class="prop-input text-xs" title="Arrival time (optional)">
                        <button type="button" :disabled="attendanceBusy || !attFormFor(row.key).name.trim()" @click="recordAttendance({{ $sectionJs }}, row, 'attended', true)"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--brand-button,#0ea5e9); color:#fff;">Save</button>
                        <button type="button" :disabled="attendanceBusy" @click="recordAttendance({{ $sectionJs }}, row, 'attended', false)"
                                class="text-xs underline" style="color:var(--text-secondary);">They attended themselves</button>
                    </div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);" data-qa="attendance-invitation"
                         x-text="row.invitation.lines.map(l => l.text).join(' · ')"></div>
                    <div class="mt-1" x-show="attendanceEditable({{ $sectionJs }})">
                        <button type="button" class="text-xs underline" style="color:var(--text-secondary);"
                                @click="invFormFor(row.key).open = !invFormFor(row.key).open">Record an invitation given</button>
                    </div>
                    <div x-show="invFormFor(row.key).open && attendanceEditable({{ $sectionJs }})" x-cloak class="flex flex-wrap items-end gap-2 mt-1.5">
                        <input type="text" x-model="invFormFor(row.key).method" maxlength="60" list="attendance-invitation-methods" placeholder="How (for example a phone call)"
                               class="prop-input text-xs" style="min-width:14rem;">
                        <input type="datetime-local" x-model="invFormFor(row.key).at" class="prop-input text-xs" title="When it was given (leave empty for now)">
                        <button type="button" :disabled="attendanceBusy || !invFormFor(row.key).method.trim()" @click="recordInvitationGiven({{ $sectionJs }}, row)"
                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--brand-button,#0ea5e9); color:#fff;">Save</button>
                    </div>
                </div>
            </template>
            <datalist id="attendance-invitation-methods">
                <template x-for="m in invitationMethods" :key="m"><option :value="m"></option></template>
            </datalist>

            <template x-for="other in attendanceOthers({{ $sectionJs }})" :key="other.id">
                <div class="flex items-center justify-between gap-2 py-1" style="border-bottom:1px solid var(--border);">
                    <span class="text-sm" style="color:var(--text-primary);">
                        <span x-text="other.attendee_name || 'Unnamed'"></span>
                        <span class="text-xs" style="color:var(--text-muted);" x-text="'(' + (attendedAsLabels[other.attended_as] || other.attended_as) + ')'"></span>
                    </span>
                    <button type="button" class="text-xs underline" style="color:var(--text-secondary);" x-show="attendanceEditable({{ $sectionJs }})" @click="withdrawAttendance({{ $sectionJs }}, other.id)">Withdraw</button>
                </div>
            </template>
            <div x-show="attendanceEditable({{ $sectionJs }})">
                <button type="button" class="text-xs underline" style="color:var(--text-secondary);" @click="otherForm.open = !otherForm.open">Add someone else who attended</button>
            </div>
            <div x-show="otherForm.open && attendanceEditable({{ $sectionJs }})" x-cloak class="flex flex-wrap items-end gap-2">
                <input type="text" x-model="otherForm.name" maxlength="191" placeholder="Name" class="prop-input text-xs" style="min-width:12rem;">
                <select x-model="otherForm.as" class="prop-input text-xs">
                    <option value="co_occupant" x-text="attendedAsLabels.co_occupant"></option>
                    <option value="other" x-text="attendedAsLabels.other"></option>
                </select>
                <input type="time" x-model="otherForm.arrived" class="prop-input text-xs" title="Arrival time (optional)">
                <button type="button" :disabled="attendanceBusy || !otherForm.name.trim()" @click="addOtherAttendee({{ $sectionJs }})"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--brand-button,#0ea5e9); color:#fff;">Add</button>
            </div>
        </div>

        {{-- Conductor brief 2026-09-29 — "print for signature": the same
             report, plus blank signature blocks for every outstanding
             party, to print and send/hand for a wet-ink signature. Not
             gated on status !== 'awaiting_signature' the way the signing
             block below is — an agent may want a printout before ANY
             party has signed anything. --}}
        <template x-if="!['completed', 'cancelled'].includes(currentInspection({{ $sectionJs }}).status)">
            <div class="flex justify-end pt-1">
                <a :href="inspectionUrls.inspectionsBase + '/' + currentInspection({{ $sectionJs }}).id + '/print-for-signature'"
                   target="_blank" rel="noopener"
                   class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); text-decoration:none;">
                    Print for signature
                </a>
            </div>
        </template>

        {{-- §46 — "Sign by link": each party's personal signing link, its status, and the ways to get it to them (email,
             WhatsApp, copy, full-screen QR, sign on this device). Shown for any open inspection (a party can read the report
             before it is ready to sign); the panel hides itself when the agency has signing by link switched off or the
             inspection is of a kind that is not signed. The host screen refreshes its signatures when someone signs. --}}
        <template x-if="!['completed', 'cancelled'].includes(currentInspection({{ $sectionJs }}).status)">
            <div @signing-links-changed="refreshInspectionData()" class="space-y-2">
                {{-- §47 — a signed report is locked; "Edit report" clears every signature. --}}
                @include('corex.rental-inspections.partials._report-lock', ['inspectionIdJs' => "currentInspection({$sectionJs}).id", 'reloadOnChange' => false])
                @include('corex.rental-inspections.partials._signing-links', ['inspectionIdJs' => "currentInspection({$sectionJs}).id", 'reloadOnChange' => false])
            </div>
        </template>
        {{-- §47 — a completed report has been SENT: never editable, by anyone. The only way forward is "Start new inspection". --}}
        <template x-if="currentInspection({{ $sectionJs }}).status === 'completed'">
            <div @signing-links-changed="refreshInspectionData()">
                @include('corex.rental-inspections.partials._report-lock', ['inspectionIdJs' => "currentInspection({$sectionJs}).id", 'reloadOnChange' => false])
            </div>
        </template>

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
                                          x-text="dispositionLabel(tenantDisposition({{ $sectionJs }}, tenant.contact_id), {{ $sectionJs }})"></span>
                                    <button type="button" x-show="canActOnWetInk({{ $sectionJs }}, tenantDisposition({{ $sectionJs }}, tenant.contact_id))"
                                            @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-medium underline" style="color:var(--text-secondary);"
                                            x-text="wetInkActionLabel(tenantDisposition({{ $sectionJs }}, tenant.contact_id))"></button>
                                </span>
                            </template>
                            <template x-if="!tenantDisposition({{ $sectionJs }}, tenant.contact_id)">
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="openSigningFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                    <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_tenant_' + tenant.contact_id)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                    <button type="button" @click="sendTenantForWetInk({{ $sectionJs }}, tenant)"
                                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Send for wet-ink</button>
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
                                              x-text="dispositionLabel(landlordDisposition({{ $sectionJs }}), {{ $sectionJs }})"></span>
                                        <button type="button" x-show="canActOnWetInk({{ $sectionJs }}, landlordDisposition({{ $sectionJs }}))"
                                                @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-medium underline" style="color:var(--text-secondary);"
                                                x-text="wetInkActionLabel(landlordDisposition({{ $sectionJs }}))"></button>
                                    </span>
                                </template>
                                <template x-if="!landlordDisposition({{ $sectionJs }})">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="openSigningFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Sign</button>
                                        <button type="button" @click="openWetInkFor({{ $sectionJs }} + '_landlord')"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Wet ink</button>
                                        <button type="button" @click="sendLandlordForWetInk({{ $sectionJs }})"
                                                class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Send for wet-ink</button>
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

                {{-- §34, 2026-09-28 (Johan's ruling) — the agent's own
                     signature must use the SAME PIN signature CoreX already
                     uses elsewhere for agents, not a second, separately-
                     built hand-drawn pad — tenant/landlord above are
                     unaffected (a tenant/landlord has no CoreX account, no
                     saved signature, no PIN; the hand-drawn canvas stays
                     exactly right for them). Reused verbatim:
                     `signature/_placer.blade.php` + its `signaturePlacer()`
                     Alpine component — the SAME reusable "place my
                     signature" widget already shared by e-sign and the CMA
                     certificate generator (per that file's own docblock),
                     backed by the SAME `AgentSignatureService` /
                     `AgentSignatureController` (`/signature/status`,
                     `/signature/unlock`, `/signature/asset/{type}`) an
                     agent already set up once in My Portal — nothing new
                     built, only consumed. `context` is scoped per
                     inspection (`'rental-inspection:' + id`) so the PIN
                     unlock (and the decrypted image it reveals) never
                     leaks across documents, same isolation the component's
                     own docblock describes for e-sign/CMA. --}}
                <div class="py-1.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">Agent</span>
                        <template x-if="agentDisposition({{ $sectionJs }})">
                            <span class="text-xs font-semibold uppercase tracking-wide" style="color:var(--text-muted);">Signed</span>
                        </template>
                    </div>
                    <template x-if="!agentDisposition({{ $sectionJs }}) && allRequiredPartiesDispositioned({{ $sectionJs }})">
                        <div class="pt-2" x-data="signaturePlacer({ context: 'rental-inspection:' + currentInspection({{ $sectionJs }}).id })" x-init="init()">
                            <template x-if="!signatureImg">
                                <button type="button" @click="ensureUnlocked()"
                                        class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Sign with PIN</button>
                            </template>
                            <template x-if="signatureImg">
                                <div class="space-y-2">
                                    <img :src="signatureImg" alt="Your saved signature" class="rounded-md" style="height:70px; background:#fff; border:1px solid var(--border); padding:4px;">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="signatureImg = null; unlocked = false;" class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Not you? Unlock again</button>
                                        <button type="button" @click="saveAgentSignatureFor({{ $sectionJs }}, signatureImg)" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);">Confirm &amp; save signature</button>
                                    </div>
                                </div>
                            </template>
                            @include('signature._placer')
                        </div>
                    </template>
                </div>

                <div class="flex justify-end items-center gap-3 pt-1">
                    <span x-show="readyToComplete({{ $sectionJs }})" x-cloak data-qa="ready-to-complete"
                          class="text-xs font-semibold px-2 py-1 rounded-md" style="background:color-mix(in srgb, #059669 14%, transparent); color:#059669;">Ready to complete</span>
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
