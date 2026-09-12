{{-- AT-392, 2026-09-08 — the highlighter's legend + loading/error states +
     page-by-page render, shared between the agent review screen and the
     authoriser screen (both now mark up documents). Extracted out of
     review.blade.php rather than copy-pasted a second time when the
     authoriser screen needed it too — this exact markup carries several
     hard-won, easy-to-reintroduce bugs (SVG-inside-<template> clone
     failure, pointerdown-bubbling focus loss) that an independent second
     copy would risk drifting from or reintroducing. Included from inside
     each screen's own per-document "x-show activeDocId === this document"
     wrapper — everything here is driven purely by the shared
     rentalDocumentHighlighter() Alpine state, no Blade-side parameters
     needed.

     Highlighter collection expansion, 2026-09-09 — Johan: "an agency can
     have 10 highlighters set up, each with their own label." Replaces the
     fixed three-category/six-colour scheme entirely: an agency-owned,
     arbitrary-length collection (RentalApplicationHighlighter), never
     hardcoded. No underline, no border, no outline of any kind on a mark
     itself — Johan, from real marked-up bank statements: "no lines as it
     strikes out," since this module has a genuine strike-out feature a
     line would be confused with. Colours come from fillFor() in the
     shared JS factory, reading `highlighters` (passed in from the
     controller) — never CSS custom properties, never hardcoded here. --}}

{{-- Stage 2, 2026-09-11 — the capture panel's row-click-to-jump "flash."
     Normal motion: a brief pulsing stroke-width (rah-mark-flash, applied by
     strokesSvgFor() in the shared script — see its own comment on why a CSS
     class, not an imperative DOM mutation, is the only safe way to do this
     against an x-html-regenerated SVG). Reduced motion: Johan's spec
     verbatim, "respect prefers-reduced-motion — outline instead of
     animation" — no @keyframes at all, just an immediate, static, high-
     contrast outline stroke for the same duration, then it clears (a single
     state change is not the "motion" prefers-reduced-motion opts out of). --}}
<style>
    .rah-mark-flash { animation: rahMarkFlashPulse 0.4s ease-in-out 3; }
    @keyframes rahMarkFlashPulse {
        0%, 100% { filter: brightness(1); }
        50% { filter: brightness(0.6); }
    }
    @media (prefers-reduced-motion: reduce) {
        .rah-mark-flash { animation: none; stroke: #000 !important; stroke-opacity: 0.85 !important; }
    }
</style>

<template x-if="loading">
    <p class="text-sm py-4" style="color: var(--text-secondary);">Loading document…</p>
</template>
<template x-if="loadError">
    <p class="text-sm py-4" style="color: var(--ds-crimson, #dc2626);" x-text="loadError"></p>
</template>

{{-- 2026-09-10 (cc5, AT-392) — "a desk with highlighters lying on it.
     Every tool is always visible, always in the same place, nothing
     morphs into anything else." The tool controls used to live in the
     sticky header as a single reflowing row (stroke-size buttons
     appearing/disappearing depending on which tool was active — Johan:
     "some shows, some goes away"), plus a colour DROPDOWN that only ever
     changed the colour, never the tool — the exact ambiguity Johan hit
     ("picked income but it stayed on note"). Fixed left panel now: every
     highlighter is its own always-visible button that IS the tool
     (picking a colour picks up highlight mode with it, one action, no
     separate colour-then-tool step left to drift apart); Note is its own
     button with its own fixed identity, never sharing the highlighter
     palette. `position: sticky` here is safe — unlike the tenant-wishlist
     drawer's earlier z-index bug, this panel is a normal flow sibling
     inside .rental-review-main's own scroll box, never position:fixed,
     so it has no viewport-escape/stacking-context risk to begin with. --}}
<div class="flex gap-4 items-start" x-show="!loading && !loadError">
    {{-- ROUND 4, 2026-09-11 — Johan, real browser, 1522px viewport: "theres
         no ways anyone can read that... left marker panel first... cut
         that panel at least into half its size... top to bottom we are
         only using half the screen." Measured: 190px wide, content ending
         ~295px down a ~630px-tall column — half the height genuinely
         empty. "Be clever about it" — his word: the old shape (a full-
         width labelled row PER highlighter, PER stroke size) is what
         forced the width, not the number of tools. Reworked so a narrow
         column is the natural fit rather than a squashed version of the
         wide one:
         - Highlighters are a colour choice — now a wrapping grid of small
           swatches (colour IS the identity), not a label+swatch+check row
           each. Scales to however many an agency configures (2026-09-09:
           "an agency can have 10 highlighters"), never hardcoded to 3.
         - Stroke is a size choice — now three small buttons each showing
           an actual line AT that stroke's own thickness (s.px, already
           the real drawn weight), not three text buttons "Thin"/"Medium"/
           "Thick" that never fit 3-across this narrow.
         - Undo/Redo are an icon pair, not two text buttons.
         - The full label never disappears — every control keeps its real
           name as a `:title` tooltip, the same degrade-legibly fallback
           already used elsewhere on this screen.
         - The active-state tick and the currently-selected stroke/colour
           both survive, just restyled to fit (a border ring + check badge
           instead of a bold label row).
         190px → 90px (more than half, by design — round number with
         margin, not a value picked to exactly clear the "at least half"
         bar). All of it goes to `.flex-1 min-w-0` below (the PDF column),
         which was already flex — no change needed there for the freed
         space to reach it. --}}
    {{-- BUG FIX, 2026-09-11 — Johan, live on QA1: "slim the pen rail from
         ~110px to ~44px... it is only that wide because of the stacked
         text headings — MARK-UP, CAPTURE, HIGHLIGHT, NOTE, STROKE — each
         on its own line above its controls." All five headings removed;
         every control's own `:title`/`title` tooltip (several already had
         one) now carries what the heading used to say. Every group
         (capture pens, any other highlighter, note, stroke, undo/redo)
         stacks in a single 44px-wide column instead of a wrapping grid —
         width for width, this is the same set of controls, just without
         the labels that were forcing the extra ~46px. The selected state
         stays exactly as it was (the tick, the border, the stroke-weight
         bar) — Johan: "that is what the tick already does." Thin borders
         between groups replace the headings as the only remaining visual
         separator. --}}
    {{-- 2026-09-12 — read-only while with the authoriser (reviewLocked, set
         once in x-init from $rentalApplication->isPendingAuthorisation()).
         The server refuses the save regardless (see
         guardScreenNotLockedForAuthoriser()); this just stops the agent
         from reaching a drawing gesture in the first place, same title
         either way so it reads as "why is this greyed out" not a mystery. --}}
    {{-- 2026-09-13 — Johan, twice, and independently cc4's own agent walk:
         a rail of unlabelled colour circles means an agent has no idea
         which pen is which until she hovers — hover-only is not a label,
         and picking the wrong one means income filed as an expense. His
         own words: a LETTER on the swatch (do NOT hardcode I/E/U — since
         these are agency-configurable and QA1 already has "Electricity"
         and "Deposit Proof" alongside the defaults), the label word
         itself very small underneath, shrunk to fit rather than growing
         the box, and the tooltip stays either way. The rail's own width
         (44px, set in the 2026-09-11 "slim the pen rail" round) does NOT
         change — "the document's width is the whole point of this
         screen's rebuild" — so the label has LESS room than the reverted
         2026-09-12 attempt gave it (that one widened the rail to 60px;
         this one fits inside the existing 44px). The selected-state tick
         moves to a small corner badge (exactly Note's own already-
         existing pattern, below) since the letter now permanently
         occupies the swatch's centre — the border highlight (unchanged)
         already carries the "this one is active" signal on its own; the
         corner tick is confirmation, not the only signal. penLabelFontSizePx
         is computed once, in this component's own init() (a real
         lifecycle method, never a raw x-init statement — see
         reviewLocked's own docblock two rounds ago for exactly why that
         distinction matters) off the single LONGEST label actually in
         this picker set (Note's own "Note" included, so it never ends up
         a visibly different size from the highlighters beside it), never
         per-label, so the row reads as one deliberate scale. Floors at
         7px, then ellipses — the full name is always in the tooltip
         regardless of what's visible.

         2026-09-13, round 2 — Johan, live on QA1: "Expense and
         Electricity both render 'E'." The letter is NOT the plain first
         character of the label (that was round 1's bug) — it comes from
         computePenLetterAssignments()'s own penLetterById map, which
         resolves collisions across the whole picker set (first letter
         where free, else the next letter actually IN that label, else a
         number — see that method's own comment for the full rule and why
         it sorts by id, never display order). --}}
    <div class="flex-shrink-0 space-y-1.5" style="width: 44px; position: sticky; top: 0;"
         :style="{ opacity: reviewLocked ? '0.4' : '1', pointerEvents: reviewLocked ? 'none' : 'auto' }"
         :title="reviewLocked ? 'Read-only — this application is with the authoriser' : ''">
        {{-- Capture pens (Income/Expense) — "the highlighter mark IS the
             ledger line." Dragging with one open opens the capture chip
             (below) instead of just laying down ink. --}}
        <template x-for="h in capturePickerHighlighters()" :key="h.id">
            <div class="mx-auto" style="width: 40px;">
                <button type="button" class="relative flex items-center justify-center rounded-md transition-all duration-100 mx-auto"
                        :title="h.label + ' — draw to capture a line'"
                        @click="pickHighlighter(h.id)"
                        :style="{
                            width: '26px', height: '26px',
                            background: h.color,
                            border: (activeTool === 'highlight' && activeHighlighterId === h.id) ? '2px solid var(--text-primary)' : '1px solid var(--border)',
                        }">
                    <span style="color:#fff; font-weight:800; font-size:12px; text-shadow: 0 0 2px rgba(0,0,0,0.65);" x-text="penLetterById[h.id] || '?'"></span>
                    <span x-show="activeTool === 'highlight' && activeHighlighterId === h.id" style="position:absolute; top:-4px; right:-4px; color:#fff; background:var(--text-primary); border-radius:9999px; width:12px; height:12px; font-size:8px; font-weight:800; line-height:12px; text-align:center;">&check;</span>
                </button>
                <p class="text-center leading-tight mt-0.5" style="color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" :style="{ fontSize: penLabelFontSizePx + 'px' }" :title="h.label" x-text="h.label"></p>
            </div>
        </template>
        <template x-if="capturePickerHighlighters().length === 0">
            <p class="text-[8px] leading-snug text-center" style="color: var(--text-muted);" title="No capture highlighters configured — see Settings">—</p>
        </template>

        {{-- Any OTHER highlighter an agency has configured (e.g. the
             default "Unpaid" pen) — a plain highlight, never a ledger
             entry. Omitted entirely when an agency has none.

             Tooltip consistency, 2026-09-13 — Johan: "Income and Expense
             say 'draw to capture a line'; Unpaid and Electricity just say
             the name... make them consistent." Went with EVERY pen
             explaining what drawing does, not the terser option — a
             tooltip that only repeats the on-screen label is worth less
             than one that also says what the gesture does, and Note's own
             tooltip already worked this way. --}}
        <template x-for="h in plainPickerHighlighters()" :key="h.id">
            <div class="mx-auto" style="width: 40px;">
                <button type="button" class="relative flex items-center justify-center rounded-md transition-all duration-100 mx-auto"
                        :title="h.label + ' — draw to highlight'"
                        @click="pickHighlighter(h.id)"
                        :style="{
                            width: '26px', height: '26px',
                            background: h.color,
                            border: (activeTool === 'highlight' && activeHighlighterId === h.id) ? '2px solid var(--text-primary)' : '1px solid var(--border)',
                        }">
                    <span style="color:#fff; font-weight:800; font-size:12px; text-shadow: 0 0 2px rgba(0,0,0,0.65);" x-text="penLetterById[h.id] || '?'"></span>
                    <span x-show="activeTool === 'highlight' && activeHighlighterId === h.id" style="position:absolute; top:-4px; right:-4px; color:#fff; background:var(--text-primary); border-radius:9999px; width:12px; height:12px; font-size:8px; font-weight:800; line-height:12px; text-align:center;">&check;</span>
                </button>
                <p class="text-center leading-tight mt-0.5" style="color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" :style="{ fontSize: penLabelFontSizePx + 'px' }" :title="h.label" x-text="h.label"></p>
            </div>
        </template>

        {{-- NOTE — its own fixed identity (NOTE_COLOR), never sharing the
             highlighter palette (see pickNoteTool()) — no backing
             RentalApplicationHighlighter record, so "Note" is a literal
             string here, not h.label, but it still gets the same tiny
             label treatment and the same shared font size as every real
             highlighter beside it (see computePenLabelFontSize()'s own
             comment on why Note is included in that computation). Its own
             tooltip already explained the gesture before this round
             ("click anywhere on the document to pin a note") — the
             consistency fix above brings the highlighters UP to this
             one's own standard, not the other way around. --}}
        <div class="mx-auto" style="width: 40px;">
            <button type="button" class="relative flex items-center justify-center rounded-md transition-all duration-100 mx-auto"
                    title="Note — click anywhere on the document to pin a note"
                    @click="pickNoteTool()"
                    :style="{
                        width: '26px', height: '26px',
                        background: NOTE_COLOR,
                        border: activeTool === 'note' ? '2px solid var(--text-primary)' : '1px solid var(--border)',
                    }">
                <span style="color:#fff; font-weight:800; font-size:11px; line-height:1;">N</span>
                <span x-show="activeTool === 'note'" style="position:absolute; top:-4px; right:-4px; color:#fff; background:var(--text-primary); border-radius:9999px; width:12px; height:12px; font-size:8px; font-weight:800; line-height:12px; text-align:center;">&check;</span>
            </button>
            <p class="text-center leading-tight mt-0.5" style="color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" :style="{ fontSize: penLabelFontSizePx + 'px' }" title="Note">Note</p>
        </div>

        {{-- Stroke width — always rendered, dimmed+inert (not hidden) when
             Note is active. Each button draws the real stroke weight
             (s.px) — a glance tells you which is active, same as the
             colour swatches. Stacked (not a row of 3) — 44px only fits one
             per line now. --}}
        <div class="space-y-1 pt-1" style="border-top: 1px solid var(--border);" :style="{ opacity: activeTool === 'highlight' ? '1' : '0.4', pointerEvents: activeTool === 'highlight' ? 'auto' : 'none' }">
            <template x-for="s in strokeSizes" :key="s.key">
                <button type="button" class="flex items-center justify-center rounded-md mx-auto" :title="'Stroke: ' + s.label" @click="setStrokeSize(s.key)"
                        :style="{ width:'26px', height:'20px', border:'1px solid var(--border)', background: strokeSizeKey === s.key ? 'var(--ds-blue-soft, #eff6ff)' : 'transparent' }">
                    <span :style="{ display:'block', width:'14px', height: (s.px/3) + 'px', minHeight:'2px', borderRadius:'2px', background: strokeSizeKey === s.key ? 'var(--ds-blue, #2563eb)' : 'var(--text-secondary)' }"></span>
                </button>
            </template>
        </div>

        {{-- Undo/Redo — stacked, not side-by-side (44px doesn't fit two
             comfortably). Same disabled/enabled logic as before, unchanged. --}}
        <div class="space-y-1 pt-1" style="border-top: 1px solid var(--border);">
            <button type="button" class="flex items-center justify-center rounded-md mx-auto" title="Undo (Ctrl+Z)"
                    @click="undo()" :disabled="!canUndo()"
                    :style="{ width:'26px', height:'22px', border:'1px solid var(--border)', color: canUndo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canUndo() ? '1' : '0.5', cursor: canUndo() ? 'pointer' : 'default' }">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
            </button>
            <button type="button" class="flex items-center justify-center rounded-md mx-auto" title="Redo (Ctrl+Shift+Z)"
                    @click="redo()" :disabled="!canRedo()"
                    :style="{ width:'26px', height:'22px', border:'1px solid var(--border)', color: canRedo() ? 'var(--text-secondary)' : 'var(--text-muted)', opacity: canRedo() ? '1' : '0.5', cursor: canRedo() ? 'pointer' : 'default' }">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg>
            </button>
        </div>
    </div>

    {{-- Capture chip, 2026-09-11 — Johan's spec verbatim: "~330px wide,
         anchored beside the mark, never over it; above if no room below.
         Date, Description, Amount. Amount focused and selected on open.
         Enter saves. Esc cancels AND drops the mark. Clicking an existing
         mark opens the same chip in edit mode, with Update and Delete."
         `position:fixed` via captureChipStyle() — anchored off the raw
         pointer event, not the mark's own document-relative coordinates,
         so it's correct regardless of scroll position. @keydown.enter on
         the wrapping form (not just the amount field) so Enter from
         Date/Description also saves, matching "Enter saves" without
         qualifying which field. --}}
    {{-- BUG FIX, 2026-09-11 — Johan, live on QA1: "the capture popup is
         transparent... the bank statement rows show straight through it."
         Root cause: `corex-card` is not a real global class in this
         codebase — it exists only scoped under two unrelated screens
         (dr2/pipeline.blade.php, dr2/distribute-compose.blade.php) — so it
         contributed literally nothing here; the chip had no background at
         all. Every other floating panel on this same screen (the wishlist
         drawer, the send-back/decline modals) uses this exact
         rounded-md + explicit `background: var(--surface)` +
         `border: 1px solid var(--border)` pattern, never a `.corex-card`
         class — matched here instead of inventing a new convention. --}}
    <form x-show="captureChip" x-cloak :style="captureChipStyle()" @submit.prevent="confirmCaptureChip()"
          @keydown.escape.prevent="cancelCaptureChip()"
          class="rounded-md p-3" style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 8px 24px rgba(0,0,0,0.18);">
        <template x-if="captureChip">
            <div class="space-y-2">
                <p class="text-xs font-semibold" :style="{ color: captureChip.entryType === 'income' ? 'var(--ds-purple, #7c3aed)' : 'var(--ds-amber, #f59e0b)' }">
                    <span x-text="captureChip.entryType === 'income' ? 'Income' : 'Expense'"></span>
                    <span x-text="captureChip.mode === 'edit' ? ' — edit' : ' — new'"></span>
                </p>
                <div>
                    <label class="text-[11px] font-medium block mb-0.5" style="color: var(--text-secondary);">Date</label>
                    {{-- .lazy — see review.blade.php's statement-period date fields for why. --}}
                    <input type="date" class="corex-input text-xs w-full" x-model.lazy="captureChip.date">
                </div>
                <div>
                    <label class="text-[11px] font-medium block mb-0.5" style="color: var(--text-secondary);">Description</label>
                    <input type="text" class="corex-input text-xs w-full" x-model="captureChip.description" maxlength="255">
                </div>
                <div>
                    <label class="text-[11px] font-medium block mb-0.5" style="color: var(--text-secondary);">Amount</label>
                    <input type="number" step="0.01" data-capture-chip-amount class="corex-input text-xs w-full" x-model="captureChip.amount">
                </div>
                <p class="text-[11px]" style="color: var(--ds-crimson, #dc2626);" x-show="captureChip.error" x-text="captureChip.error"></p>
                <div class="flex items-center justify-between gap-2 pt-1">
                    <button type="button" x-show="captureChip.mode === 'edit'" class="text-[11px]" style="color: var(--ds-crimson, #dc2626);" :disabled="captureChip.saving" @click="deleteCaptureChip()">Delete</button>
                    <span x-show="captureChip.mode !== 'edit'"></span>
                    <span class="flex items-center gap-2">
                        <button type="button" class="text-[11px]" style="color: var(--text-muted);" @click="cancelCaptureChip()">Cancel</button>
                        <button type="submit" class="corex-btn-primary text-xs" style="padding: 0.2rem 0.6rem;" :disabled="captureChip.saving" x-text="captureChip.saving ? 'Saving…' : (captureChip.mode === 'edit' ? 'Update' : 'Save')"></button>
                    </span>
                </div>
            </div>
        </template>
    </form>

    <div class="flex-1 min-w-0">
        {{-- Legend removed, 2026-09-11 — Johan, live on QA1: "the legend is
             eating the document... two lines of the agent's screen spent
             explaining colours that are already shown, selected and
             labelled in the pen rail immediately to its left." Standing
             rule: every line of space is either data the agent needs or a
             control they act on — the rail already carries the colours
             and labels, so this was pure duplication, not a second source
             of information. legendHighlighters() (the helper that drove
             this) is left in the shared script unused rather than removed
             — it has no other caller, but touching document-highlighter-
             script.blade.php's own logic wasn't asked for here. --}}

        {{-- Instructional copy removed, 2026-09-11 — Johan's standing rule:
             "every line of space is either data the agent needs or a
             control they act on." Three lines of how-to prose above the
             document was neither — cut, not shortened. --}}

        {{-- Progressive load, 2026-09-08 — Johan: "the agent must be able to
             SEE that more pages are still coming, and roughly how many. A
             page 1 that looks like the whole document is worse than a slow
             load." Sharpness kept at full quality per his decision — this is
             a one-time cost per document, made LESS painful by showing page 1
             immediately, not made invisible.
             2026-09-10 — Johan hit this again: "suggesting a loading modal that
             the agent dont think the first page is it." The banner text was
             already correct, it just wasn't visually loud enough for a
             multi-second wait — a flat, static line of small text reads as
             "done" at a glance. Added a genuinely animated spinner so the
             in-progress state is unmistakable, not just stated. --}}
        <div class="flex items-center gap-2 text-xs py-2 px-3 rounded-md mb-2" x-show="pagesLoading" x-cloak
             style="background: var(--ds-blue-soft, #eff6ff); color: var(--ds-blue, #2563eb);">
            <svg class="w-3.5 h-3.5 flex-shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <span>Page 1 of <span x-text="totalPages"></span> shown — loading the remaining <span x-text="totalPages - pages.length"></span> pages. You can start marking up page 1 now.</span>
        </div>

        <div class="flex items-center gap-2 text-xs py-2 px-3 rounded-md mb-2" x-show="applyError" x-cloak
             style="background: #fef2f2; color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626);">
            <span x-text="applyError"></span>
            {{-- Version-conflict recovery — Johan: no live locking, but a genuine
                 collision must be visible and recoverable, not silent. Reloading is
                 the whole recovery mechanism: it discards this tab's unsaved
                 changes and re-fetches the current, now-authoritative state. --}}
            <button type="button" x-show="applyErrorReason === 'version_conflict'" class="text-xs font-semibold underline" @click="reloadHighlighter()">Reload document</button>
        </div>

        <div class="space-y-4 pt-2">
    <template x-for="page in pages" :key="page.index">
        <div>
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-semibold" style="color: var(--text-muted);">Page <span x-text="page.index + 1"></span></span>
                <button type="button" class="text-xs" style="color: var(--ds-crimson, #dc2626);" @click="clearPage(page.index)">Clear my marks on this page</button>
            </div>
            <div class="relative inline-block select-none" style="max-width:100%;">
                <img :src="page.data_uri" class="rah-page-img block" :data-page="page.index"
                     style="max-width:100%; height:auto; border:1px solid var(--border);"
                     draggable="false" @dragstart.prevent>
                <div class="absolute inset-0" style="cursor:crosshair; touch-action:none;"
                     :data-page="page.index"
                     @pointerdown.prevent="startDraw($event, page.index)"
                     @pointermove.prevent="moveDraw($event, page.index)"
                     @pointerup.prevent="endDraw($event, page.index)"
                     @pointercancel.prevent="endDraw($event, page.index)"
                     @dragstart.prevent>
                    {{-- Highlight strokes — freehand redesign, 2026-09-09.
                         Freehand ink following the actual drawn path (round
                         caps/joins, a real marker-pen gesture, never a
                         rectangle), translucent, blended with
                         mix-blend-mode:multiply so text underneath stays
                         readable and overlapping strokes darken naturally —
                         Johan, from real marked-up bank statements: "no
                         lines as it strikes out" (the underline this used to
                         carry drew a hard line through crossed text, which
                         reads as struck-out — colliding with the real
                         strike-out feature on the assessment panel). The
                         blend mode is set on EACH POLYLINE individually
                         (strokesSvgFor(), not here on the SVG container) —
                         setting it on the container instead would flatten
                         all strokes into one composited layer first (plain
                         alpha blending each other), then multiply that
                         WHOLE layer against the page once; per-polyline
                         blending is what makes two overlapping strokes
                         genuinely compound and darken against EACH OTHER,
                         not just against the page beneath both.
                         2026-09-08 — Johan: "highlighter dont work - just shows a
                         little black x but no colour applied." Root cause found by
                         actually loading the screen (real browser console, not a
                         markup check): a browser parses <template x-for>/<template
                         x-if> INSIDE an <svg> as foreign SVG content, so it never
                         gets the special "content is a cloneable fragment"
                         treatment real HTML <template> gets elsewhere on this same
                         page — Alpine's clone step threw a real, reproducible
                         "Failed to execute 'importNode'" error on every draw,
                         silently leaving every polyline's points/stroke/width
                         blank. The little black x (the remove-mark button below)
                         is a plain HTML button OUTSIDE the svg, so it rendered
                         fine and was the only visible sign anything existed. Fixed
                         by building the polyline markup as a STRING and binding it
                         with x-html directly on the <svg> — no <template> inside
                         SVG at all, still fully reactive since x-html re-evaluates
                         on every dependency change same as x-text/x-show. --}}
                    <svg class="absolute inset-0" style="pointer-events:none; width:100%; height:100%;"
                         x-html="strokesSvgFor(page.index)"
                         @mouseover="if ($event.target.dataset.markId) hoveredMarkId = $event.target.dataset.markId"
                         @mouseout="if ($event.target.dataset.markId && $event.target.dataset.markId === hoveredMarkId) hoveredMarkId = null"
                         @click="onStrokeClick($event, page.index)"></svg>
                    {{-- Remove-stroke handle, freehand redesign 2026-09-09 —
                         Johan: "every stroke currently carries a black
                         circled x... eight of them scattered down the
                         page... competes with the marks themselves." Now
                         appears ONLY for the one stroke currently under the
                         cursor (hoveredMarkId, set by the SVG's own
                         @mouseover/@mouseout above via real hit-testing
                         against the drawn ink, not a bounding box) — and
                         only if it's a mark the current user actually owns
                         (or an unattributed legacy mark); someone else's
                         mark never shows a × at all, hovered or not. --}}
                    <template x-for="(mark, mi) in strokesFor(page.index)" :key="'r'+mi">
                        <button type="button" title="Remove this mark" x-show="canEditMark(mark) && mark.id && hoveredMarkId === mark.id"
                                @mouseover="hoveredMarkId = mark.id" @mouseout="if (hoveredMarkId === mark.id) hoveredMarkId = null"
                                @pointerdown.stop.prevent="removeMark(page.index, mi, 'highlight')"
                                :style="{ position:'absolute', left:(toDisplayX(mark.points[0].x, page.index)-9)+'px', top:(toDisplayY(mark.points[0].y, page.index)-9)+'px', width:'18px', height:'18px', borderRadius:'9999px', background:'#475569', color:'#fff', fontSize:'12px', lineHeight:'16px', textAlign:'center', border:'1px solid #fff', padding:'0', pointerEvents:'auto', cursor:'pointer' }">&times;</button>
                    </template>
                    {{-- Notes — a pinned marker + its text, visible inline. Dot
                         fill is now a fixed NOTE_COLOR (2026-09-10, see the
                         template above), never fillFor()'s highlighter-colour
                         lookup, with a plain fixed white ring for contrast —
                         this is a discrete pin, not a stroke crossing text, so
                         a border here isn't the "line as it strikes out" Johan
                         ruled out.
                         2026-09-08 — Johan: "note does not work - clicked, shows
                         small modal but cannot type anything in it." Root cause,
                         found the same way as the highlighter bug above (a real
                         browser, not a markup check): the draw-surface div this
                         sits inside has @pointerdown.prevent="startDraw(...)" with
                         no .stop, so a pointerdown that starts on the textarea
                         (clicking into it to type) bubbles up and gets
                         preventDefault()'d there — which cancels the browser's own
                         default focus behaviour for that pointerdown. The textarea
                         was never actually receiving focus, so every keystroke
                         went nowhere; @click.stop on the textarea couldn't help
                         because click fires AFTER pointerdown, once the damage was
                         already done. Fixed by stopping the pointerdown itself
                         from ever reaching the draw surface, on every interactive
                         element here (not just the textarea — the marker dot and
                         its popover buttons had the same latent exposure). --}}
                    {{-- 2026-09-10 (cc5+cc4, Johan: "a note must look like a note
                         on the document, not like a highlight") — fixed colour
                         (NOTE_COLOR) and a plain-text "N" glyph, never fillFor()'s
                         highlighter-colour lookup, so a note is identifiable at a
                         glance regardless of category and can never be mistaken
                         for a highlight stroke. Dot stays the click target
                         (unchanged hit area/position) — only its fill and the
                         glyph inside it changed. Text, not <svg>, deliberately —
                         this file's own docblock flags SVG-inside-<template>
                         clone failure as a known, hard-won landmine. --}}
                    <template x-for="(note, ni) in notesFor(page.index)" :key="'n'+ni">
                        <div @pointerdown.stop :style="{ position:'absolute', left:toDisplayX(note.x, page.index)+'px', top:toDisplayY(note.y, page.index)+'px', transform:'translate(-50%,-50%)', pointerEvents:'auto' }">
                            <div class="rounded-full flex items-center justify-center" :style="{ width:'16px', height:'16px', background: NOTE_COLOR, border:'2px solid #fff', boxShadow:'0 0 0 1px rgba(0,0,0,0.3)', cursor:'pointer' }"
                                 @click="toggleNotePopover(page.index, ni)">
                                <span style="color:#fff; font-size:8px; font-weight:800; line-height:1; user-select:none;">N</span>
                            </div>
                            <div x-show="openNote && openNote.page === page.index && openNote.index === ni" x-cloak
                                 class="rounded-md p-2" style="position:absolute; top:20px; left:0; width:240px; background: var(--surface); border:1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 10;">
                                <p class="text-sm mb-1" style="white-space:pre-wrap; color: var(--text-primary);" x-text="note.text"></p>
                                <p class="text-[11px] mb-2" style="color: var(--text-muted);" x-text="(note.authorName || 'Unknown') + ' · ' + labelFor(note)"></p>
                                <div class="flex justify-end gap-2">
                                    <button type="button" x-show="canEditMark(note)" class="text-xs" style="color: var(--ds-crimson, #dc2626);" @click="removeMark(page.index, ni, 'note')">Remove</button>
                                    <button type="button" class="text-xs" style="color: var(--text-muted);" @click="openNote = null">Close</button>
                                </div>
                            </div>
                        </div>
                    </template>
                    {{-- Pending note being typed (note tool, awaiting text). --}}
                    <div x-show="pendingNote && pendingNote.page === page.index" x-cloak @pointerdown.stop
                         :style="{ position:'absolute', left:(pendingNote ? pendingNote.x : 0)+'px', top:(pendingNote ? pendingNote.y : 0)+'px', transform:'translate(-50%,-50%)', pointerEvents:'auto' }">
                        <div class="rounded-md p-2" style="width:220px; background: var(--surface); border:1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
                            <textarea x-model="pendingNoteText" rows="3" class="corex-input text-xs w-full" placeholder="Note text…" @click.stop :data-pending-note-page="page.index"></textarea>
                            <div class="flex justify-end gap-2 mt-1">
                                <button type="button" class="text-xs" style="color: var(--text-muted);" @click.stop="pendingNote = null; pendingNoteText = ''">Cancel</button>
                                <button type="button" class="text-xs font-semibold" style="color: var(--ds-blue, #2563eb);" @click.stop="commitNote()">Add note</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
        </div>
    </div>
</div>
