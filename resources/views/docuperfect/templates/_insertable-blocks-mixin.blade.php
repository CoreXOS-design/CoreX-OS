{{-- Shared Alpine mixin (2026-08-20) for the "Insertable Blocks & Clauses"
     panel — @include'd once per page, spread into that page's own x-data via
     `{...corexInsertableBlocksMixin(), ...otherState}`. Both cds-builder.blade.php
     and edit-web.blade.php's Content tab use this SAME function; there is no
     second copy to drift out of sync with.

     Structural safety (Johan: "wrap the marker in its own block-level
     element so str_replace cannot break nesting") is enforced SERVER-SIDE at
     save time (App\Services\Docuperfect\MarkerBlockLevelNormalizer), not
     here — that guarantees every marker ends up correctly wrapped no matter
     how it landed in the DOM (this insert button, a hand-edit, a paste,
     anything), rather than only when this exact button was used. This mixin
     stays a straightforward raw-text insert at the cursor; the server
     normalizes it on save regardless. --}}
<script>
    function corexInsertableBlocksMixin() {
        return {
            insertBlockExpanded: false,
            clauseSearch: '',
            clauseList: [],
            clausesLoading: false,
            clausesUrl: @json(route('docuperfect.clauses.json')),

            // Insert a `~~~~MARKER~~~~` placeholder at the current cursor position
            // in the contenteditable doc. For CUSTOM, prompts for a label.
            insertBlockMarker(purpose) {
                let token = purpose;
                if (purpose === 'CUSTOM') {
                    const label = (prompt('Block label (e.g. "Outstanding Repairs"):') || '').trim();
                    if (!label) return;
                    token = 'CUSTOM:' + label;
                }
                const marker = '~~~~' + token + '~~~~';
                this._insertTextAtCursor(marker);
            },

            async loadClauses() {
                this.clausesLoading = true;
                try {
                    const url = this.clausesUrl + (this.clauseSearch ? '?q=' + encodeURIComponent(this.clauseSearch) : '');
                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (r.ok) {
                        const data = await r.json();
                        this.clauseList = Array.isArray(data) ? data : (data.data || data.clauses || []);
                    } else {
                        this.clauseList = [];
                    }
                } catch (e) {
                    console.warn('Clause library fetch failed:', e);
                    this.clauseList = [];
                }
                this.clausesLoading = false;
            },

            insertClauseAtCursor(clause) {
                // Insert the clause text into the document at the current cursor.
                // The clause is plain text — agent can edit before saving template.
                this._insertTextAtCursor(clause.text || clause.content || '');
            },

            _insertTextAtCursor(text) {
                const container = document.getElementById('docContainer');
                if (!container) return;
                container.focus();
                const sel = window.getSelection();
                let range;
                if (sel && sel.rangeCount > 0 && container.contains(sel.anchorNode)) {
                    range = sel.getRangeAt(0);
                } else {
                    range = document.createRange();
                    range.selectNodeContents(container);
                    range.collapse(false);
                }
                range.deleteContents();
                const node = document.createTextNode(text);
                range.insertNode(node);
                range.setStartAfter(node);
                range.setEndAfter(node);
                sel.removeAllRanges();
                sel.addRange(range);
            },
        };
    }
</script>
