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

{{-- Legend — Johan asked for this explicitly ("a map key"). Driven by
     legendHighlighters() — every currently-choosable highlighter, plus
     any archived one that still has a mark on THIS open document (an old
     mark's colour is never left unexplained just because someone tidied
     the settings screen). Each row carries its own role tag now that role
     is a per-highlighter property, not a fixed second axis.

     2026-09-08, night sweep at 1522px — --ds-slate-soft was never actually
     defined in corex.css, so its hardcoded #f1f5f9 fallback always won
     regardless of theme, against theme-aware (light-in-dark-mode) text —
     unreadable in dark mode. --surface-2 is the real, theme-aware token. --}}
<div class="flex flex-wrap items-center gap-3 py-2 px-3 rounded-md text-xs mb-2" x-show="!loading && !loadError"
     style="background: var(--surface-2, #f9fafb); border: 1px solid var(--border);">
    <span class="font-semibold" style="color: var(--text-secondary);">Legend</span>
    <template x-for="h in legendHighlighters()" :key="h.id">
        <span class="flex items-center gap-1">
            <span :style="{ display:'inline-block', width:'20px', height:'14px', borderRadius:'3px', background: h.color, opacity: h.archived ? '0.5' : '1' }"></span>
            <span style="color: var(--text-muted);" x-text="h.label"></span>
            <span style="color: var(--text-muted); font-size: 10px;" x-text="'(' + (h.role_scope === 'both' ? 'agent + authoriser' : h.role_scope) + (h.archived ? ', archived' : '') + ')'"></span>
        </span>
    </template>
    <span class="text-[11px]" style="color: var(--text-muted);" x-show="legendHighlighters().length === 0">No highlighters configured yet.</span>
</div>

<template x-if="loading">
    <p class="text-sm py-4" style="color: var(--text-secondary);">Loading document…</p>
</template>
<template x-if="loadError">
    <p class="text-sm py-4" style="color: var(--ds-crimson, #dc2626);" x-text="loadError"></p>
</template>
<p class="text-xs py-2" style="color: var(--text-muted);" x-show="!loading && !loadError">
    <span x-show="activeTool === 'highlight'">Click and drag across the document, like a marker pen, to highlight.</span>
    <span x-show="activeTool === 'note'">Click anywhere on the document to pin a note.</span>
    Marks are saved for this document — anyone who opens it next sees the same marks. You can edit or remove your own marks; anyone else's are read-only to you.
</p>

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

<div class="space-y-4 pt-2" x-show="!loading && !loadError">
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
                         @mouseout="if ($event.target.dataset.markId && $event.target.dataset.markId === hoveredMarkId) hoveredMarkId = null"></svg>
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
                                :style="{ position:'absolute', left:(mark.points[0].x-9)+'px', top:(mark.points[0].y-9)+'px', width:'18px', height:'18px', borderRadius:'9999px', background:'#475569', color:'#fff', fontSize:'12px', lineHeight:'16px', textAlign:'center', border:'1px solid #fff', padding:'0', pointerEvents:'auto', cursor:'pointer' }">&times;</button>
                    </template>
                    {{-- Notes — a pinned marker + its text, visible inline. Dot
                         fill resolves to its highlighter's colour (fillFor,
                         same as a highlight stroke) with a plain fixed
                         white ring for contrast — this is a discrete pin,
                         not a stroke crossing text, so a border here isn't
                         the "line as it strikes out" Johan ruled out.
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
                    <template x-for="(note, ni) in notesFor(page.index)" :key="'n'+ni">
                        <div @pointerdown.stop :style="{ position:'absolute', left:note.x+'px', top:note.y+'px', transform:'translate(-50%,-50%)', pointerEvents:'auto' }">
                            <div class="rounded-full" :style="{ width:'16px', height:'16px', background: fillFor(note), border:'2px solid #fff', boxShadow:'0 0 0 1px rgba(0,0,0,0.3)', cursor:'pointer' }"
                                 @click="toggleNotePopover(page.index, ni)"></div>
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
