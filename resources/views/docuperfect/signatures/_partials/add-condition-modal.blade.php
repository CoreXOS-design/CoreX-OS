{{-- E-Sign V3 Phase 1B.6 — Add Condition modal (rewritten).

     Recipient-side modal for adding a brand-new condition. No clause-
     library access (recipient writes are always free text). Optionally
     links to an existing numbered clause via `relates_to_clause_ref`.

     Listens for the 'open-add-condition-modal' window CustomEvent
     dispatched by the "+ Add condition" buttons rendered inside
     InsertableBlockRenderer.

     Required Blade locals:
       $token            — recipient signing token (mounted route /sign/{token})
       $numberedClauses  — array of [{ref, preview}] extracted from the body

     Spec: .ai/specs/esign-v3-complete-spec.md §7.5.4 (Phase 1B.6 revision) --}}

<div x-data="addConditionModalAlpine()" x-init="init()"
     x-show="open" x-cloak
     style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5);">
    <div style="background: #fff; border-radius: 8px; max-width: 640px; width: 92%; max-height: 88vh; overflow-y: auto; padding: 1.5rem;">

        <h3 style="margin: 0 0 0.5rem; color: #111827; font-size: 1.05rem; font-weight: 700;"
            x-text="'Add a condition' + (blockLabel ? ' to ' + blockLabel : '')"></h3>
        <p style="margin: 0 0 1rem; color: #6b7280; font-size: 0.85rem;">
            Describe any additional condition you want included. The agent will
            review your request before it&rsquo;s added to the document.
        </p>

        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #111827; margin-bottom: 0.3rem;">
            Your proposed condition
        </label>
        <textarea x-model="customText" rows="6"
                  placeholder="Type the condition wording…"
                  style="width: 100%; padding: 0.7rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; resize: vertical;"></textarea>

        <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
            <button type="button" @click="close()"
                    style="padding: 0.6rem 1.2rem; background: #f3f4f6; color: #111827; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                Cancel
            </button>
            <button type="button" @click="submit()"
                    :disabled="submitting || !customText.trim()"
                    :style="(submitting || !customText.trim()) ? 'opacity: 0.4; cursor: not-allowed;' : ''"
                    style="padding: 0.6rem 1.4rem; background: #0ea5e9; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                <span x-text="submitting ? 'Saving…' : 'Save condition'"></span>
            </button>
        </div>

        <div x-show="error" x-cloak
             style="margin-top: 0.8rem; padding: 0.6rem; background: #fee2e2; color: #991b1b; border-radius: 4px; font-size: 0.85rem;"
             x-text="error"></div>
    </div>
</div>

<script>
// Phase 1B.7 (FIX A) — attach click handlers to `+ Add condition` buttons
// rendered by InsertableBlockRenderer. The buttons are plain HTML emitted
// by the server-side renderer; they carry data-block-id / data-block-purpose
// / data-block-label attributes which we surface here as the modal's event
// detail. The previous wiring lived inside Phase 1B.5's override-modal
// partial (deleted in Phase 1B.6) — re-homed here so the dispatch survives.
(function () {
    // Recipient-signing fix — DELEGATED handlers on document.
    // The "+ Add condition" and per-condition ".btn-add-initial" buttons are
    // NOT static HTML: the document body arrives via Alpine x-html and is then
    // relocated by paginateDocument() in a deferred $nextTick+setTimeout, i.e.
    // AFTER DOMContentLoaded / alpine:initialized. The prior one-shot
    // querySelectorAll attachment bound to zero buttons (they didn't exist yet)
    // and never re-ran, so on the recipient signing surface "+ Add condition"
    // and the per-condition initial slots were dead. Event delegation on
    // document survives every x-html inject + pagination rebuild + in-place
    // condition-row append — no re-attachment needed.
    document.addEventListener('click', function (e) {
        const addBtn = e.target.closest('.btn-add-condition');
        if (addBtn) {
            e.preventDefault();
            window.dispatchEvent(new CustomEvent('open-add-condition-modal', {
                detail: {
                    blockId: addBtn.dataset.blockId,
                    purpose: addBtn.dataset.blockPurpose,
                    label:   addBtn.dataset.blockLabel,
                },
            }));
            return;
        }
        const initBtn = e.target.closest('.btn-add-initial');
        if (initBtn && !initBtn.disabled && !initBtn.classList.contains('initial-filled')) {
            e.preventDefault();
            postInitial(initBtn);
            return;
        }
    });

    // Phase 1B.7 (FIX C) — wire per-condition initial buttons to POST the
    // initialCondition endpoint. On 201 the slot transitions to filled by
    // mutating classes in place (avoids a full reload on every initial).
    async function postInitial(btn) {
        const token       = btn.dataset.signingToken;
        const conditionId = btn.dataset.conditionId;
        const partyKey    = btn.dataset.partyKey;
        if (!token || !conditionId) return;
        const csrf = document.querySelector('meta[name=csrf-token]')?.content;
        const url  = `/sign/${token}/conditions/${conditionId}/initial`;
        try {
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({}),
            });
            if (r.ok || r.status === 409) {
                // Either success OR an already-initialed conflict — repaint
                // either way. The /409 case happens when a double-click
                // races; the slot WAS persisted on first click, the second
                // simply reflects that state.
                const slot = btn;
                slot.classList.remove('initial-active');
                slot.classList.add('initial-filled');
                slot.disabled = true;
                slot.setAttribute(
                    'style',
                    'display:inline-flex; flex-direction:column; align-items:center; padding:0.35rem 0.6rem; '
                    + 'background:#ecfdf5; border:1px solid #047857; border-radius:4px; font-size:0.75rem;'
                );
                const letters = slot.querySelector('strong')?.textContent || '';
                slot.innerHTML = '<strong style="color:#047857; letter-spacing:0.05em;">' + letters + '</strong>'
                    + '<small style="color:#065f46; font-size:0.65rem; margin-top:1px;">just now</small>';
                // Recipient-signing fix — the condition-initial slots are now
                // first-class required items; refresh the submit gate so this
                // freshly-filled slot drops out of the "N remaining" count.
                document.dispatchEvent(new CustomEvent('corex-refresh-signing-count'));
            } else {
                const j = await r.json().catch(() => ({}));
                alert(j.error || ('Could not initial (' + r.status + ')'));
            }
        } catch (e) {
            alert('Network error: ' + e.message);
        }
    }
    // Handlers are DELEGATED on document (top of this IIFE) so they survive
    // x-html inject + pagination + in-place row append. No per-element
    // attachment / lifecycle re-binding is needed. The legacy
    // 'phase-1b7-reattach-initial-handlers' event is now a no-op but is still
    // dispatched by _appendConditionRow for backward-compat; instead we refresh
    // the submit-gate count when a new condition row is appended.
    document.addEventListener('phase-1b7-reattach-initial-handlers', function () {
        document.dispatchEvent(new CustomEvent('corex-refresh-signing-count'));
    });
})();

function addConditionModalAlpine() {
    return {
        open: false,
        blockId: '',
        blockPurpose: '',
        blockLabel: '',
        customText: '',
        submitting: false,
        error: '',
        storeUrl: @json(route('signatures.external.addCondition', ['token' => $token])),
        init() {
            window.addEventListener('open-add-condition-modal', (e) => {
                const d = e.detail || {};
                this.blockId = d.blockId || 'other_conditions';
                this.blockPurpose = d.purpose || 'other_conditions';
                this.blockLabel = d.label || '';
                this.customText = '';
                this.error = '';
                this.open = true;
            });
        },
        close() { this.open = false; },
        async submit() {
            this.error = '';
            const content = (this.customText || '').trim();
            if (!content) { this.error = 'Please enter the condition wording.'; return; }
            this.submitting = true;
            try {
                const csrf = document.querySelector('meta[name=csrf-token]').content;
                const r = await fetch(this.storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({
                        block_id:      this.blockId,
                        block_purpose: this.blockPurpose,
                        content:       content,
                        source:        'custom',
                    }),
                });
                if (r.ok) {
                    // Phase 1B.9 (FIX 3) — in-place DOM append. The
                    // previous implementation called location.reload()
                    // here, which wiped Alpine state including every
                    // captured signature and initial — the critical
                    // recipient-experience bug. We now append the new
                    // <li> row server-rendered (rendered_row) into the
                    // matching insertable-block <ol>, leaving the rest
                    // of the page untouched.
                    const data = await r.json().catch(() => ({}));
                    this._appendConditionRow(data);
                    this.close();
                } else {
                    const j = await r.json().catch(() => ({}));
                    this.error = j.error || j.message || ('Save failed (' + r.status + ')');
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            }
            this.submitting = false;
        },

        /**
         * Phase 1B.9 (FIX 3) — insert the server-rendered <li> row into the
         * target insertable-block's <ol>, OR seed the block with a fresh
         * <ol> if it was empty (still showing 'No conditions yet.').
         * Falls back to a soft toast + page-state-aware reload only if the
         * server didn't return rendered_row (unexpected, but defensive).
         */
        _appendConditionRow(data) {
            if (!data || !data.rendered_row) {
                // Server contract changed unexpectedly — degrade gracefully
                // but avoid the destructive reload. Show a confirmation
                // toast; the recipient can refresh manually if they want.
                this._showSavedToast();
                return;
            }
            const blockEl = document.querySelector(
                '.insertable-block[data-block-id="' + (this.blockId || 'other_conditions') + '"]'
            );
            if (!blockEl) {
                this._showSavedToast();
                return;
            }

            // Find or create the <ol>
            let list = blockEl.querySelector('ol.conditions-list-numbered, ul.conditions-list-unnumbered');
            if (!list) {
                // The empty-state <p> is rendered when there are no
                // conditions yet — replace it with an <ol> seeded by the
                // new row. The renderer's auto_number default for
                // other_conditions purpose is true, so emit <ol>.
                const empty = blockEl.querySelector('p.no-conditions-yet');
                const isOtherConditions = (this.blockPurpose || 'other_conditions') === 'other_conditions';
                const newList = document.createElement(isOtherConditions ? 'ol' : 'ul');
                newList.className = isOtherConditions ? 'conditions-list conditions-list-numbered' : 'conditions-list conditions-list-unnumbered';
                newList.setAttribute('style', isOtherConditions
                    ? 'list-style: decimal outside; padding-left: 1.5em; margin: 0.4rem 0;'
                    : 'list-style: none; padding-left: 0; margin: 0.4rem 0;');
                if (empty) {
                    empty.replaceWith(newList);
                } else {
                    // Insert before the Add Condition button (or at end)
                    const addBtn = blockEl.querySelector('.btn-add-condition');
                    if (addBtn) {
                        blockEl.insertBefore(newList, addBtn);
                    } else {
                        blockEl.appendChild(newList);
                    }
                }
                list = newList;
            }

            // Inject the new row HTML — wrap in a template so we get a
            // real <li> node we can append (innerHTML assignment to <ol>
            // would replace existing rows).
            const wrap = document.createElement('template');
            wrap.innerHTML = data.rendered_row.trim();
            const node = wrap.content.firstElementChild;
            if (node) {
                list.appendChild(node);
                // The new row carries .btn-add-initial buttons — re-attach
                // the handler IIFE so the recipient can initial the new
                // condition without a reload.
                document.dispatchEvent(new CustomEvent('phase-1b7-reattach-initial-handlers'));
            }

            this._showSavedToast();
        },

        _showSavedToast() {
            // Tiny inline toast — surfaces success without commandeering
            // the existing signing notification surface.
            const toast = document.createElement('div');
            toast.textContent = 'Condition added. The agent will review it.';
            toast.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);'
                + 'padding:0.7rem 1.2rem;background:#0ea5e9;color:#fff;border-radius:6px;'
                + 'font-size:0.9rem;box-shadow:0 4px 16px rgba(0,0,0,0.15);z-index:10000;';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
        },
    };
}
</script>
