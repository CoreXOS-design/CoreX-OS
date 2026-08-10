{{--
    SHARED wet-ink amend / selection-edit tool (selectionEditor).

    Extracted from external/sign.blade.php so BOTH the recipient signing page AND the agent review
    page use the SAME tool (Johan 2026-08-10 — shared, not forked). Renders: a floating "✎ Amend
    this" button at the current text selection, a sticky "Amendments" panel (highlighted-text + Amend
    button + a navigable list of the document's changes/conditions with View + Initial actions), and
    the amend modal (reword inline / move to Other Conditions / strike out). The panel LIST is built
    from the rendered change-marks in the document (.change-del[data-change-id], .condition-row
    [data-condition-id], .cir-slot[data-party-key]) on discrete events only — NO MutationObserver.

    PARAMETERS
      $editSelectionUrl (required)  POST endpoint that authors the strike/amend for this surface.
                                    recipient: route('signatures.external.editSelection', $token)
                                    agent:     the agent-side edit-selection route.
      $viewerKey        (required)  the acting party's canonical party key (drives "my" cir-slot /
                                    condition-initial detection + the corex-open-change-initial payload).
      $pendingReview    (bool=false) show the "Amendment pending agent review" status pill for
                                    not-yet-initialed changes (recipient passes $wetInkPendingReview).
      $wrapperClass     (string='') CSS class on the x-data root (its placement in the host layout).
                                    recipient passes 'recipient-amend-col' (its 3-col column).

    HOST-PAGE CONTRACT (what the including page must also provide)
      • The document body must render the change-marks (server-side): .change-inline/.change-del/
        .change-ins/.change-xref[data-change-id], .cir-slot[data-change-id][data-party-key],
        .condition-row[data-condition-id] with .btn-add-initial[data-party-key].
      • Emit `corex-doc-ready` (a CustomEvent on document) after the doc body paints so the panel
        list builds; the tool also self-rebuilds on `corex-amendment-created` and
        `corex-change-initialed`, plus two timed passes.
      • The "✎ Initial" action dispatches `corex-open-change-initial` {changeId, partyKey} (body) or
        clicks the condition's own `.btn-add-initial` (which dispatches `corex-open-condition-initial`);
        the host must handle those to open its capture modal (recipient: the shared _capture-modal +
        _change-initial-affordance partials). On apply, dispatch `corex-change-initialed` to refresh.
      • On a successful strike the tool dispatches `corex-amendment-created`
        {changeId, mode, replacement, ocRef, selected, range}; the host paints the mark in place
        (recipient: _paintNewAmendment) — the tool itself does not mutate the document body.
--}}
@php
    $editSelectionUrl = $editSelectionUrl ?? null;
    $viewerKey        = $viewerKey ?? '';
    $pendingReview    = $pendingReview ?? false;
    $wrapperClass     = $wrapperClass ?? '';
    // BOUNDED edit model (Johan 2026-08-10): once the agent has re-edited and the doc re-circulates for
    // signatures (STATUS_AMENDMENT_INITIALING), there is NO third edit — a recipient can only accept-and-
    // initial or decline. The host passes allowEdit=false in that round to HIDE the amend affordances
    // (float ✎ button + "Amend highlighted text" control + the amend modal) while the navigable list and
    // its View/Initial actions stay. Mirrors cc2's server guard in editSelection (422 when closed).
    $allowEdit        = $allowEdit ?? true;
@endphp
<div x-data="selectionEditor({ url: @js($editSelectionUrl), viewerKey: @js($viewerKey), pendingReview: @js((bool) $pendingReview), allowEdit: @js((bool) $allowEdit) })" class="{{ $wrapperClass }}">

                    {{-- The float ✎ button + the amend modal are true overlays → stay teleported to <body> so
                         position:fixed resolves to the VIEWPORT. The Amendments PANEL itself is NOT teleported:
                         it is a real flow element in its own column beside the document (Johan 2026-08-07). --}}
                    {{-- Floating edit button — positioned by JS right at the current selection. Omitted when
                         edits are closed (re-initial round) so a recipient cannot start a third edit. --}}
                    @if($allowEdit)
                    <template x-teleport="body">
                        <button type="button" x-ref="floatBtn" @click="openFromSelection()"
                                class="items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg text-white shadow-lg"
                                style="display:none; position:fixed; z-index:9500; background:#b45309;">✎ Amend this</button>
                    </template>
                    @endif

                    {{-- "Amendments" panel — a real column beside the document (position:sticky within its own
                         column so it stays in view while scrolling; NOT a fixed overlay over the document).
                         Houses the amend controls AND a navigable list of the changes on this document (built
                         from the rendered change-marks — no MutationObserver; rebuilt on discrete events).
                         Matches cc2's agent review-page amendment pattern (card / list-item / badge / pill). --}}
                        <div class="sel-sticky-bar" role="region" aria-label="Amendments">
                            <div class="sel-amend-head">
                                <span class="sel-amend-head-icon" aria-hidden="true">✎</span>
                                <span>Amendments</span>
                                <span class="sel-amend-count" x-text="amendments.length"></span>
                            </div>

                            @if($allowEdit)
                            {{-- amend controls — highlighted text + Amend button --}}
                            <div class="sel-amend-controls">
                                <div class="sel-amend-info">
                                    <span class="sel-amend-label">Highlighted text</span>
                                    <span class="sel-amend-text" :class="selected ? 'has-sel' : 'is-hint'"
                                          x-text="selected ? ('“' + selected.slice(0,80) + (selected.length>80?'…':'') + '”') : 'Nothing yet — drag to highlight any word, phrase or clause below.'"></span>
                                </div>
                                <button type="button" @click="openFromSelection()" :disabled="!selected"
                                        class="sel-amend-btn">✎ Amend highlighted text</button>
                            </div>
                            @else
                            {{-- Edits are CLOSED for this round (bounded model). No more amending — accept each
                                 change by initialing it below, or decline to request a new document. --}}
                            <div class="sel-amend-closed">
                                Changes are closed for this round. Please initial each change below to accept it, or decline to request a new document.
                            </div>
                            @endif

                            {{-- navigable list of changes on this document --}}
                            <div class="amend-list">
                                <div class="amend-empty" x-show="amendments.length === 0">
                                    @if($allowEdit)
                                    No amendments yet — highlight any word, phrase or clause below to propose a change.
                                    @else
                                    No changes to initial.
                                    @endif
                                </div>
                                <template x-for="a in amendments" :key="a.key">
                                    <div class="amend-item">
                                        <div class="amend-item-top">
                                            <span class="amend-badge" x-text="a.badge"></span>
                                            <span class="amend-pill" :class="a.pillClass" x-text="a.status"></span>
                                        </div>
                                        <div class="amend-item-loc" x-text="a.location" x-show="a.location"></div>
                                        <div class="amend-item-sum">
                                            <span class="amend-old" x-text="a.oldText"></span>
                                            <span class="amend-new" x-show="a.newText" x-text="a.newText"></span>
                                        </div>
                                        <div class="amend-item-actions">
                                            <button type="button" class="amend-btn-view" @click="scrollToChange(a.id, a.kind)">View</button>
                                            <button type="button" class="amend-btn-initial" x-show="a.canInitial" @click="initialItem(a)">✎ Initial</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                    {{-- Modal — a proper CENTERED overlay with backdrop, teleported to body, above the top nav.
                         Omitted when edits are closed (re-initial round) — no way to author a third edit. --}}
                    @if($allowEdit)
                    <template x-teleport="body">
                    <div x-show="open" x-cloak class="sel-modal-overlay" @keydown.escape.window="open=false" @click="open=false">
                        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>
                            <div class="px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
                                <h3 class="text-white font-semibold text-lg">Amend the highlighted text</h3>
                            </div>
                            <div class="p-6 space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Highlighted text — will be struck through</label>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm" style="text-decoration:line-through; color:#6b7280;" x-text="selected || 'Highlight text in the document first.'"></div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-600 mb-1">How should this change appear?</label>
                                    <div class="flex gap-2">
                                        <button type="button" @click="mode='inline'"
                                                class="flex-1 rounded-lg border px-3 py-2 text-left text-xs"
                                                :class="mode==='inline' ? 'border-[#0b2a4a] bg-[#eef4fb] font-semibold text-[#0b2a4a]' : 'border-slate-200 text-slate-600'">
                                            Reword inline
                                            <span class="block font-normal text-[11px] text-slate-500">Small change — new wording sits right where the old text was.</span>
                                        </button>
                                        <button type="button" @click="mode='reference'"
                                                class="flex-1 rounded-lg border px-3 py-2 text-left text-xs"
                                                :class="mode==='reference' ? 'border-[#0b2a4a] bg-[#eef4fb] font-semibold text-[#0b2a4a]' : 'border-slate-200 text-slate-600'">
                                            Move to Other Conditions
                                            <span class="block font-normal text-[11px] text-slate-500">Big change — strike here, full replacement added as a numbered Other Condition.</span>
                                        </button>
                                        <button type="button" @click="mode='strike'"
                                                class="flex-1 rounded-lg border px-3 py-2 text-left text-xs"
                                                :class="mode==='strike' ? 'border-[#b91c1c] bg-[#fef2f2] font-semibold text-[#b91c1c]' : 'border-slate-200 text-slate-600'">
                                            Strike out (remove)
                                            <span class="block font-normal text-[11px] text-slate-500">No replacement — strike the text out, e.g. an unwanted alternative clause.</span>
                                        </button>
                                    </div>
                                </div>
                                <div x-show="mode!=='strike'">
                                    <label class="block text-xs font-medium text-slate-600 mb-1">Replacement text</label>
                                    <textarea x-model="replacement" rows="4" class="w-full rounded-lg border-slate-300 text-sm px-3 py-2" placeholder="The new wording…"></textarea>
                                </div>
                                <p class="text-xs text-slate-500" x-show="mode==='inline'">The highlighted text stays visible, struck through, with your replacement inserted right there. A full-width initial row for every party is dropped in under that clause.</p>
                                <p class="text-xs text-slate-500" x-show="mode==='reference'" x-cloak>The highlighted text stays visible, struck through, with a "See Other Conditions — clause N" cross-reference. The full replacement is added as a numbered Other Condition. A full-width initial row for every party is dropped in under that clause.</p>
                                <p class="text-xs text-slate-500" x-show="mode==='strike'" x-cloak>The highlighted text is struck through and removed, with no replacement. A full-width initial row for every party is dropped in under that clause — everyone initials the removal.</p>
                                <p x-show="err" x-text="err" class="text-xs text-red-600"></p>
                                <div class="flex items-center justify-end gap-3 pt-2">
                                    <button type="button" @click="open=false" class="px-4 py-2.5 text-sm text-slate-600 font-medium">Cancel</button>
                                    <button type="button" @click="submit()" :disabled="busy" class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white" style="background:#0b2a4a;">
                                        <span x-show="!busy">Apply strike-out</span><span x-show="busy" x-cloak>Applying…</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    </template>
                    @endif
</div>

@once
<script>
                    function selectionEditor(cfg) {
                        return {
                            open: false, selected: '', prefix: '', suffix: '', replacement: '', mode: 'inline', busy: false, err: '', _cap: null,
                            amendments: [], viewerKey: (cfg.viewerKey || ''), pendingReview: !!cfg.pendingReview,
                            // BOUNDED edit model — false in the re-initial round: NO new/third edit. The amend
                            // markup (float ✎ / controls / modal) is omitted server-side; these JS guards are
                            // belt-and-braces so a stray selection or a programmatic open can never author one.
                            allowEdit: (cfg.allowEdit !== false),
                            init() {
                                const handler = () => setTimeout(() => this.onSelect(), 10);
                                document.addEventListener('mouseup', handler);
                                document.addEventListener('keyup', handler);
                                document.addEventListener('selectionchange', handler);
                                // Build the amendments list from the rendered change-marks. Rebuild ONLY on
                                // discrete events — never a MutationObserver loop: after the doc paints
                                // (corex-doc-ready), after an amendment is created (corex-amendment-created),
                                // and after a change is initialed (corex-change-initialed). A couple of timed
                                // passes cover the late doc render (post "Sign Electronically").
                                const rb = () => setTimeout(() => this.rebuildList(), 60);
                                document.addEventListener('corex-doc-ready', rb);
                                document.addEventListener('corex-amendment-created', rb);
                                document.addEventListener('corex-change-initialed', rb);
                                setTimeout(rb, 1200);
                                setTimeout(rb, 2600);
                            },
                            _cssEsc(s) { return (window.CSS && CSS.escape) ? CSS.escape(String(s)) : String(s).replace(/["\\]/g, '\\$&'); },
                            // Nearest heading/clause label above a change — a short "location" for the list item.
                            _changeLocation(el) {
                                try {
                                    const block = (el.closest && el.closest('.corex-clause, .corex-h1, .corex-h2, .corex-h3, p, li, td, blockquote')) || el;
                                    let cur = block, h = null;
                                    while (cur && !h) {
                                        let prev = cur.previousElementSibling;
                                        while (prev) {
                                            if (prev.matches && prev.matches('.corex-h1, .corex-h2, .corex-h3, h1, h2, h3, h4')) { h = prev; break; }
                                            const inner = prev.querySelector && prev.querySelector('.corex-h1, .corex-h2, .corex-h3');
                                            if (inner) { h = inner; break; }
                                            prev = prev.previousElementSibling;
                                        }
                                        cur = cur.parentElement;
                                    }
                                    if (h) { const t = (h.textContent || '').replace(/\s+/g, ' ').trim(); return t.slice(0, 48); }
                                    const t = (block.textContent || '').replace(/\s+/g, ' ').trim();
                                    return t ? (t.slice(0, 46) + (t.length > 46 ? '…' : '')) : '';
                                } catch (e) { return ''; }
                            },
                            // Enumerate the change-marks in the document (each unique data-change-id) into list items
                            // with a type badge, one-line location + summary, and this recipient's status pill.
                            rebuildList() {
                                try {
                                    const vk = this._cssEsc(this.viewerKey || '');
                                    const seen = new Set(); const out = [];
                                    // (a) BODY clause amendments — each .change-del[data-change-id].
                                    document.querySelectorAll('.change-del[data-change-id]').forEach((del) => {
                                        const id = del.getAttribute('data-change-id');
                                        if (!id || seen.has('b:' + id)) return; seen.add('b:' + id);
                                        const e = this._cssEsc(id);
                                        const wrap = del.closest('.change-inline') || del.parentElement;
                                        const ins  = document.querySelector('.change-ins[data-change-id="' + e + '"]');
                                        const xref = document.querySelector('.change-xref[data-change-id="' + e + '"]');
                                        const isXref = !!xref || del.hasAttribute('data-oc-ref');
                                        const oldText = (del.textContent || '').replace(/\s+/g, ' ').trim();
                                        let newText = '';
                                        if (isXref) newText = '→ Other Conditions';
                                        else if (ins && (ins.textContent || '').trim()) newText = (ins.textContent || '').replace(/\s+/g, ' ').trim();
                                        else newText = 'Struck out (removed)';
                                        // status — from THIS recipient's own initial slot for the change.
                                        const mySlot = this.viewerKey
                                            ? document.querySelector('.cir-slot[data-change-id="' + e + '"][data-party-key="' + vk + '"]')
                                            : null;
                                        const myInk = mySlot ? mySlot.querySelector('.cir-ink') : null;
                                        const myFilled = !!mySlot && (mySlot.classList.contains('cir-filled') || (myInk && !myInk.hasAttribute('data-empty') && !!myInk.querySelector('img')));
                                        let status = 'Pending', pillClass = 'amend-pill--pending';
                                        if (myFilled) { status = 'Initialed'; pillClass = 'amend-pill--done'; }
                                        else if (this.pendingReview) { status = 'Amendment pending agent review'; pillClass = 'amend-pill--review'; }
                                        out.push({
                                            key: 'b:' + id, id, kind: 'body', badge: 'Clause amendment',
                                            location: this._changeLocation(wrap),
                                            oldText: oldText.slice(0, 90), newText: newText.slice(0, 90),
                                            status, pillClass,
                                            canInitial: !!mySlot && !myFilled,   // this recipient owns an un-filled slot
                                        });
                                    });
                                    // (b) OTHER CONDITIONS — each .condition-row[data-condition-id] (mirrors the agent
                                    // review panel, which lists conditions as items too). Status from THIS recipient's
                                    // own .btn-add-initial / initial-slot for the condition.
                                    document.querySelectorAll('.condition-row[data-condition-id]').forEach((row) => {
                                        const id = row.getAttribute('data-condition-id');
                                        if (!id || seen.has('c:' + id)) return; seen.add('c:' + id);
                                        const e = this._cssEsc(id);
                                        const content = (row.querySelector('.condition-content')?.textContent || '').replace(/\s+/g, ' ').trim();
                                        // my slot: an active (un-filled) button = can initial; a filled slot = done.
                                        const myBtn = this.viewerKey
                                            ? row.querySelector('.btn-add-initial[data-condition-id="' + e + '"][data-party-key="' + vk + '"]')
                                            : row.querySelector('.btn-add-initial[data-condition-id="' + e + '"]');
                                        const myFilledSlot = row.querySelector('[data-condition-id="' + e + '"][data-party-key="' + vk + '"].initial-filled, [data-condition-id="' + e + '"][data-party-key="' + vk + '"][data-signed="true"]');
                                        const canInitial = !!myBtn && !myBtn.classList.contains('initial-filled');
                                        let status = 'Pending', pillClass = 'amend-pill--pending';
                                        if (myFilledSlot || (myBtn && myBtn.classList.contains('initial-filled'))) { status = 'Initialed'; pillClass = 'amend-pill--done'; }
                                        else if (this.pendingReview) { status = 'Amendment pending agent review'; pillClass = 'amend-pill--review'; }
                                        out.push({
                                            key: 'c:' + id, id, kind: 'condition', badge: 'Other Condition',
                                            location: 'Other Conditions',
                                            oldText: content.slice(0, 110), newText: '',
                                            status, pillClass, canInitial,
                                        });
                                    });
                                    this.amendments = out;
                                } catch (e) { /* list is a convenience; never break the signing surface */ }
                            },
                            // Scroll the document to a change/condition and flash it (the "View" action).
                            scrollToChange(id, kind) {
                                try {
                                    const e = this._cssEsc(id);
                                    const sel = (kind === 'condition')
                                        ? '.condition-row[data-condition-id="' + e + '"]'
                                        : '.change-inline[data-change-id="' + e + '"], .change-del[data-change-id="' + e + '"]';
                                    const el = document.querySelector(sel);
                                    if (!el) return;
                                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    el.classList.add('amend-flash');
                                    setTimeout(() => el.classList.remove('amend-flash'), 1700);
                                } catch (e) {}
                            },
                            // Initial THIS change/condition straight from the panel — opens the SAME draw/type capture
                            // modal the in-document slots use (never a reload). Body → corex-open-change-initial;
                            // Other Condition → click its own .btn-add-initial (its delegated handler dispatches
                            // corex-open-condition-initial). On apply, applySignature persists + paints, then
                            // dispatches corex-change-initialed which rebuilds this list.
                            initialItem(item) {
                                try {
                                    const e = this._cssEsc(item.id);
                                    if (item.kind === 'condition') {
                                        const vk = this._cssEsc(this.viewerKey || '');
                                        const btn = document.querySelector('.condition-row[data-condition-id="' + e + '"] .btn-add-initial[data-condition-id="' + e + '"][data-party-key="' + vk + '"]')
                                                 || document.querySelector('.btn-add-initial[data-condition-id="' + e + '"]');
                                        if (btn) { this.scrollToChange(item.id, 'condition'); btn.click(); }
                                        return;
                                    }
                                    // body change — open the change-initial modal for this recipient's own slot.
                                    this.scrollToChange(item.id, 'body');
                                    document.dispatchEvent(new CustomEvent('corex-open-change-initial', {
                                        detail: { changeId: item.id, partyKey: this.viewerKey },
                                    }));
                                } catch (e) {}
                            },
                            inOwnUi(node) {
                                const el = node && (node.nodeType === 1 ? node : node.parentElement);
                                // Only reject selections inside our OWN wet-ink UI or an already-struck mark —
                                // NOT the whole page (the signing screen is itself an Alpine [x-data] root).
                                return !!(el && el.closest('[data-strikethrough-applied="1"], .change-margin, .wetink-initial-btn, .sel-sticky-bar, input, textarea'));
                            },
                            capture() {
                                const sel = window.getSelection();
                                if (!sel || sel.isCollapsed || !sel.rangeCount) return null;
                                const text = sel.toString().replace(/\s+/g, ' ').trim();
                                if (!text) return null;
                                if (this.inOwnUi(sel.anchorNode) || this.inOwnUi(sel.focusNode)) return null;
                                const range = sel.getRangeAt(0);
                                let prefix = '', suffix = '';
                                try {
                                    prefix = (range.startContainer.textContent || '').slice(Math.max(0, range.startOffset - 40), range.startOffset);
                                    suffix = (range.endContainer.textContent || '').slice(range.endOffset, range.endOffset + 40);
                                } catch (e) {}
                                // Keep the live Range so a created amendment can be PAINTED in place (no reload,
                                // which would wipe in-progress field initials/signatures — same bug class as the
                                // amendment-initial fix, Johan 2026-08-06).
                                return { text, prefix, suffix, rect: range.getBoundingClientRect(), range: range.cloneRange() };
                            },
                            onSelect() {
                                // Bounded edit model — no amend affordance when edits are closed (re-initial round).
                                if (!this.allowEdit) return;
                                // While the amend modal is OPEN, the selection has already been captured into
                                // _cap. Opening the modal (clicking the ✎ button / sticky bar) fires the button's
                                // own document 'mouseup', and clicking collapses the text selection — so this
                                // delayed onSelect would otherwise capture nothing and NULL _cap, leaving submit()
                                // with range:null so the created amendment never paints live (Johan 2026-08-07,
                                // ISSUE B). Preserve the captured selection until the modal closes.
                                if (this.open) return;
                                const cap = this.capture();
                                const btn = this.$refs.floatBtn;
                                if (!cap) { this._cap = null; if (btn) btn.style.display = 'none'; return; }
                                this._cap = cap;
                                this.selected = cap.text; this.prefix = cap.prefix; this.suffix = cap.suffix;
                                if (btn && cap.rect) {
                                    btn.style.left = Math.max(8, cap.rect.left) + 'px';
                                    btn.style.top = (cap.rect.bottom + 6) + 'px';
                                    btn.style.display = 'inline-flex';
                                }
                            },
                            openFromSelection() {
                                // Bounded edit model — closed round can never open the amend modal.
                                if (!this.allowEdit) return;
                                const cap = this._cap || this.capture();
                                // PERSIST the capture (incl. its live Range) onto _cap. Without this, a capture
                                // taken fresh here (when _cap was null/stale — e.g. opening from the sticky-bar
                                // button rather than the float ✎) is discarded, so submit() sends range:null and
                                // the created amendment NEVER paints in place → it only appears after a manual
                                // reload even though the server applied it (Johan 2026-08-07, ISSUE B).
                                if (cap) { this._cap = cap; this.selected = cap.text; this.prefix = cap.prefix; this.suffix = cap.suffix; }
                                this.replacement = ''; this.mode = 'inline'; this.err = ''; this.open = true;
                                const btn = this.$refs.floatBtn; if (btn) btn.style.display = 'none';
                            },
                            async submit() {
                                this.err = '';
                                if (!this.selected.trim()) { this.err = 'Highlight the text you want to change first.'; return; }
                                if (this.mode !== 'strike' && !this.replacement.trim()) { this.err = 'Enter the replacement text.'; return; }
                                this.busy = true;
                                try {
                                    const resp = await fetch(cfg.url, {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                                                   'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}' },
                                        body: JSON.stringify({ selected: this.selected, prefix: this.prefix, suffix: this.suffix, replacement: this.replacement.trim(), mode: this.mode }),
                                    });
                                    const data = await resp.json().catch(() => ({}));
                                    if (resp.ok && data.ok) {
                                        // Paint the new amendment IN PLACE — never re-fetch the page here.
                                        // A reload re-fetches the server doc, which has no record of the field
                                        // initials/signatures applied client-side via "apply to all" (held only
                                        // in the DOM until final submit) → reloading WIPED them. The amendment is
                                        // already persisted server-side (strikeSelection → writeAmend), so we hand
                                        // the change to the main signing component to render the struck mark + the
                                        // per-party initial row at the captured selection, preserving every applied
                                        // initial/signature (Johan 2026-08-06).
                                        document.dispatchEvent(new CustomEvent('corex-amendment-created', { detail: {
                                            changeId: data.change_id, mode: this.mode,
                                            replacement: this.replacement.trim(), ocRef: data.oc_ref || null,
                                            selected: this.selected, range: this._cap ? this._cap.range : null,
                                        } }));
                                        this.open = false; this.busy = false; this.selected = ''; this.replacement = ''; this._cap = null;
                                        const fb = this.$refs.floatBtn; if (fb) fb.style.display = 'none';
                                    }
                                    else { this.err = data.error || 'Could not apply the change.'; this.busy = false; }
                                } catch (e) { this.err = 'Network error — please retry.'; this.busy = false; }
                            },
                        };
                    }
</script>
<style>
                    .sel-modal-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center;
                        justify-content: center; padding: 1rem; background: rgba(0,0,0,.6); }
                    /* The "Amendments" panel — a light card matching the left "How to sign" rail (white, #e2e8f0
                       border, slate type, amber action) AND cc2's agent review-page amendment pattern (list-item
                       card + type badge + rounded status pill). Vertical card; position:sticky within its column
                       on wide screens, a normal stacked card below the document on narrow screens. */
                    .sel-sticky-bar {
                        position: static; z-index: 20;
                        width: 100%; margin-top: 12px; max-height: none;
                        display: flex; flex-direction: column; gap: .6rem;
                        background: #ffffff; color: #0f172a;
                        border: 1px solid #e2e8f0; border-radius: 14px;
                        padding: 14px 16px;
                        box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
                        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    }
                    @media (min-width: 1440px) {
                        .sel-sticky-bar {
                            position: sticky; top: 16px; margin-top: 0;
                            max-height: calc(100vh - 32px);
                        }
                    }
                    .sel-sticky-bar .sel-amend-head {
                        display: flex; align-items: center; gap: .45rem; flex-shrink: 0;
                        font-size: 13px; font-weight: 600; color: #0f172a;
                        padding-bottom: 10px; border-bottom: 1px solid #e2e8f0;
                    }
                    .sel-sticky-bar .sel-amend-head-icon {
                        display: inline-flex; align-items: center; justify-content: center;
                        width: 22px; height: 22px; border-radius: 50%;
                        background: #fff7ed; color: #b45309; font-size: 12px; flex-shrink: 0;
                    }
                    .sel-sticky-bar .sel-amend-count {
                        margin-left: auto; min-width: 20px; height: 20px; padding: 0 6px;
                        display: inline-flex; align-items: center; justify-content: center;
                        border-radius: 10px; background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700;
                    }
                    /* amend controls */
                    .sel-sticky-bar .sel-amend-controls { display: flex; flex-direction: column; gap: .4rem; flex-shrink: 0; }
                    /* closed-round notice (bounded edit model — replaces the amend controls) */
                    .sel-sticky-bar .sel-amend-closed { font-size: 12px; line-height: 1.45; color: #92400e;
                        background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px; flex-shrink: 0; }
                    .sel-sticky-bar .sel-amend-info { display: flex; flex-direction: column; align-items: flex-start; gap: .2rem; min-width: 0; }
                    .sel-sticky-bar .sel-amend-label { font-size: .62rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; font-weight: 600; }
                    .sel-sticky-bar .sel-amend-text { font-size: .78rem; font-weight: 500; line-height: 1.4; max-width: 100%;
                        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
                    .sel-sticky-bar .sel-amend-text.has-sel { color: #0f172a; }
                    .sel-sticky-bar .sel-amend-text.is-hint { color: #94a3b8; font-weight: 400; }
                    .sel-sticky-bar .sel-amend-btn {
                        width: 100%; padding: .5rem 1rem; font-size: .74rem; font-weight: 600; border-radius: 8px;
                        color: #fff; background: #b45309;
                        display: inline-flex; align-items: center; justify-content: center; gap: .3rem;
                        transition: background .12s ease;
                    }
                    .sel-sticky-bar .sel-amend-btn:hover:not(:disabled) { background: #92400e; }
                    .sel-sticky-bar .sel-amend-btn:disabled { background: #cbd5e1; color: #f1f5f9; cursor: not-allowed; }
                    /* navigable amendments list */
                    .sel-sticky-bar .amend-list {
                        flex: 1; min-height: 0; overflow-y: auto;
                        display: flex; flex-direction: column; gap: 8px;
                        margin-top: 2px; padding-top: 10px; border-top: 1px solid #e2e8f0;
                    }
                    .sel-sticky-bar .amend-empty { font-size: 12px; color: #94a3b8; line-height: 1.45; }
                    .sel-sticky-bar .amend-item {
                        display: flex; flex-direction: column; gap: 4px; width: 100%; text-align: left;
                        background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px;
                        cursor: pointer; transition: border-color .12s, box-shadow .12s;
                    }
                    .sel-sticky-bar .amend-item:hover { border-color: #b45309; box-shadow: 0 0 0 2px #fef3c7; }
                    .sel-sticky-bar .amend-item-top { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
                    .sel-sticky-bar .amend-badge {
                        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
                        color: #475569; background: #f1f5f9; border-radius: 4px; padding: 1px 6px;
                    }
                    .sel-sticky-bar .amend-pill {
                        margin-left: auto; font-size: 10px; font-weight: 600; border-radius: 999px; padding: 1px 8px; white-space: nowrap;
                    }
                    .sel-sticky-bar .amend-pill--pending { background: #fef3c7; color: #92400e; }
                    .sel-sticky-bar .amend-pill--review  { background: #dbeafe; color: #1e40af; }
                    .sel-sticky-bar .amend-pill--done    { background: #dcfce7; color: #166534; }
                    .sel-sticky-bar .amend-item-loc { font-size: 11px; color: #64748b; }
                    .sel-sticky-bar .amend-item-sum { font-size: 12px; line-height: 1.35; }
                    .sel-sticky-bar .amend-old { color: #b91c1c; text-decoration: line-through; }
                    .sel-sticky-bar .amend-new { color: #166534; margin-left: 4px; }
                    /* per-item actions — View (jump) + Initial (capture), matching the agent review panel */
                    .sel-sticky-bar .amend-item-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
                    .sel-sticky-bar .amend-btn-view {
                        font-size: 12px; color: #475569; border: 1px solid #cbd5e1; background: #fff;
                        border-radius: 7px; padding: 4px 10px; cursor: pointer;
                    }
                    .sel-sticky-bar .amend-btn-view:hover { background: #f8fafc; }
                    .sel-sticky-bar .amend-btn-initial {
                        font-size: 12px; font-weight: 600; color: #fff; background: #0369a1; border: 1px solid #0369a1;
                        border-radius: 7px; padding: 4px 10px; cursor: pointer;
                    }
                    .sel-sticky-bar .amend-btn-initial:hover { background: #075985; }
                    /* flash the change when a list item is clicked */
                    .amend-flash { animation: amendFlash 1.7s ease; border-radius: 3px; }
                    @keyframes amendFlash {
                        0%, 100% { background: transparent; box-shadow: none; }
                        15%, 55% { background: #fde68a; box-shadow: 0 0 0 3px #fbbf24; }
                    }
</style>
@endonce
