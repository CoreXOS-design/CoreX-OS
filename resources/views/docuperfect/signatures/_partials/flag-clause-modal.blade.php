{{-- E-Sign V3 Phase 1B.6 (FIX 2) — Flag Clause modal.

     Replaces the Phase 1B.5 override-modal abstraction. The recipient
     flags a printed clause with a suggested change; on submit the system
     creates a DocumentAmendment with amendment_type='flag_raised' (Phase
     2 ES-4 promotion path) and writes through to
     web_template_data.clause_flags for display continuity.

     Listens for the 'open-flag-clause-modal' window CustomEvent
     dispatched by either the inline _toggleClauseFlag() function in
     sign.blade.php or any other surface that wants to capture a clause
     change proposal.

     Required Blade locals:
       $token — recipient signing token

     Spec: .ai/specs/esign-v3-complete-spec.md §7.5.5 (Phase 1B.6 revision). --}}

<div x-data="flagClauseModalAlpine()" x-init="init()"
     x-show="open" x-cloak
     style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5);">
    <div style="background: #fff; border-radius: 8px; max-width: 640px; width: 92%; max-height: 88vh; overflow-y: auto; padding: 1.5rem;">

        <h3 style="margin: 0 0 0.5rem; color: #92400e; font-size: 1.05rem; font-weight: 700;">
            Flag clause <span x-text="clauseRef"></span>
        </h3>
        <p style="margin: 0 0 1rem; color: #6b7280; font-size: 0.85rem;">
            Tell the agent what you&rsquo;d like to change about this clause. The
            agent will review your request before any change is applied. The
            original clause stays as printed until the agent acts on your flag.
        </p>

        <div style="padding: 0.8rem; background: color-mix(in srgb, #d97706 8%, transparent); border-left: 3px solid #d97706; border-radius: 4px; margin-bottom: 1rem;">
            <div style="font-size: 0.7rem; color: #92400e; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">
                Original clause
            </div>
            <p style="margin: 0.4rem 0 0; color: #4b5563;" x-text="clauseOriginalText"></p>
        </div>

        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #111827; margin-bottom: 0.3rem;">
            Your suggested change <span style="color: #dc2626;">*</span>
        </label>
        <textarea x-model="suggestedChange" rows="4"
                  placeholder="What would you like this clause to say instead?"
                  style="width: 100%; padding: 0.7rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.9rem; resize: vertical;"></textarea>

        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #111827; margin: 0.85rem 0 0.3rem;">
            Why are you suggesting this? <span style="color: #6b7280; font-weight: 400;">(optional)</span>
        </label>
        <textarea x-model="reason" rows="2"
                  placeholder="Explain your reasoning to the agent…"
                  style="width: 100%; padding: 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.85rem; resize: vertical;"></textarea>

        <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
            <button type="button" @click="close()"
                    style="padding: 0.6rem 1.2rem; background: #f3f4f6; color: #111827; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                Cancel
            </button>
            <button type="button" @click="submit()"
                    :disabled="submitting || !suggestedChange.trim()"
                    :style="(submitting || !suggestedChange.trim()) ? 'opacity: 0.4; cursor: not-allowed;' : ''"
                    style="padding: 0.6rem 1.4rem; background: #d97706; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                <span x-text="submitting ? 'Submitting…' : 'Flag for agent review'"></span>
            </button>
        </div>

        <div x-show="error" x-cloak
             style="margin-top: 0.8rem; padding: 0.6rem; background: #fee2e2; color: #991b1b; border-radius: 4px; font-size: 0.85rem;"
             x-text="error"></div>
    </div>
</div>

<script>
function flagClauseModalAlpine() {
    return {
        open: false,
        clauseRef: '',
        clauseOriginalText: '',
        suggestedChange: '',
        reason: '',
        submitting: false,
        error: '',
        storeUrl: @json(route('signatures.external.flagClause', ['token' => $token])),
        init() {
            window.addEventListener('open-flag-clause-modal', (e) => {
                const d = e.detail || {};
                this.clauseRef = d.clauseRef || '';
                this.clauseOriginalText = (d.clauseText || '').substring(0, 600);
                this.suggestedChange = '';
                this.reason = '';
                this.error = '';
                this.open = true;
            });
        },
        close() { this.open = false; },
        async submit() {
            this.error = '';
            const sugg = (this.suggestedChange || '').trim();
            if (!sugg) { this.error = 'Tell the agent what change you want.'; return; }
            this.submitting = true;
            try {
                const csrf = document.querySelector('meta[name=csrf-token]').content;
                const r = await fetch(this.storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({
                        clause_ref:           this.clauseRef,
                        clause_original_text: this.clauseOriginalText,
                        suggested_change:     sugg,
                        reason:               (this.reason || '').trim() || null,
                    }),
                });
                if (r.ok) {
                    // AT-291 ITEM 4 — do NOT reload. A full page reload
                    // wipes every captured-but-unsubmitted signature,
                    // initial and filled field held in the signing view's
                    // Alpine state (forbidden per STANDARDS §E-Sign). Instead
                    // hand the committed flag to the signing component in
                    // place; its reactive freeze banner takes over and every
                    // piece of captured work is preserved.
                    const j = await r.json().catch(() => ({}));
                    window.dispatchEvent(new CustomEvent('clause-flagged-committed', {
                        detail: {
                            clauseRef:   this.clauseRef,
                            concern:     sugg,
                            reason:      (this.reason || '').trim() || null,
                            amendmentId: j.amendment_id ?? null,
                        },
                    }));
                    this.close();
                } else {
                    const j = await r.json().catch(() => ({}));
                    this.error = j.error || j.message || ('Submit failed (' + r.status + ')');
                }
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            }
            this.submitting = false;
        },
    };
}
</script>
