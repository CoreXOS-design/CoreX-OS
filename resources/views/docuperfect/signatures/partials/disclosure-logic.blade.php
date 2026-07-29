{{--
    Shared disclosure logic (§19 Part A — APPROVED).
    @include'd INSIDE the Alpine object literal of BOTH signing views:
      - external/sign.blade.php  (externalSign())   — seller / external signer
      - sign.blade.php           (signDocument())   — agent
    SINGLE SOURCE. Do not copy-paste these methods into either view.

    Contract:
      * Each consuming component MUST define _currentSignerRole() and have
        state: webDisclosureAnswers:{}, totalDisclosureRows:0, storedDisclosure:{}.
      * The bare-table converter _processDisclosureTable()/_processCertificateRow()
        remains external-only (legacy path; #119 uses the checklist structure).
        _processAllDisclosures() calls it only when present (typeof guard).

    Legal rule (Johan-approved, PPA s70): the mandatory-disclosure grid is
    EDITABLE only for owner_party (the seller discloses). The agent and every
    other signer see it READ-ONLY but must SEE the seller's actual answers.
--}}
        // Editable ONLY when the current signer is the owner/seller party.
        _disclosureEditable(disclosureParty) {
            // AT-303 Stage 1 — once an earlier owner-party recipient has SIGNED
            // the shared MDF grid it is LOCKED. A downstream recipient sees it
            // READ-ONLY and proposes changes via a mark amendment (Stage 2),
            // never a silent overwrite that would void the earlier party's
            // already-applied signature. The server enforces this too
            // (completeWeb disclosure_lock) — this is the UI half.
            if (this.disclosureMarksLocked) return false;
            const ownerTerms = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
            const dp = (disclosureParty || 'owner_party').toLowerCase();
            const role = (typeof this._currentSignerRole === 'function'
                ? (this._currentSignerRole() || '') : '').toLowerCase();
            return ownerTerms.includes(role) && ownerTerms.includes(dp);
        },

        // True ONLY when the CURRENT signer is the disclosing owner/seller
        // party. The mandatory-disclosure grid is gate-counted toward a
        // signer's required/incomplete total ONLY for that signer; every
        // other signer (agent, buyer) sees it READ-ONLY and is NOT gated
        // on it (PPA s70 — the seller is the sole discloser).
        _signerIsDisclosingParty() {
            const ownerTerms = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
            const role = (typeof this._currentSignerRole === 'function'
                ? (this._currentSignerRole() || '') : '').toLowerCase();
            return ownerTerms.includes(role);
        },

        // §20 — INTRINSIC, STATELESS disclosure key (docKey from the
        // wrapper's server-stamped data-disclosure-doc + this row's ordinal
        // among its own wrapper's canonical rows). Same row => same key from
        // any caller; no counter, no DOM-order/pack-position dependence.
        // ALL four delegate to the ONE shared rule (window.CoreXDisclosure
        // in a4-page-styles.blade.php — loaded by both signing views).
        // The gate, the store, and the agent-review restore therefore use a
        // single, stateless, intrinsic key derivation. No second copy.
        _disclosureDocKey(el) {
            return window.CoreXDisclosure.docKeyOf(el);
        },
        _disclosureKeyFor(rowEl) {
            return window.CoreXDisclosure.keyForRow(rowEl);
        },
        _disclosureDateKeyFor(rowEl) {
            return window.CoreXDisclosure.dateKeyForRow(rowEl);
        },
        _isDisclosureAnswerKey(k) {
            return window.CoreXDisclosure.isAnswerKey(k);
        },

        // Seed in-memory answers from the persisted store so a later signer
        // (e.g. the agent reviewing after the seller) starts from the
        // seller's actual selections, never a blank grid.
        _seedDisclosureFromStore() {
            const stored = this.storedDisclosure || {};
            if (stored && typeof stored === 'object') {
                Object.keys(stored).forEach(k => {
                    if (this.webDisclosureAnswers[k] === undefined) {
                        this.webDisclosureAnswers[k] = stored[k];
                    }
                });
            }
        },

        // Re-apply stored YES/NO/N/A onto the rendered grid (checklist
        // placeholders + bare-table radios). Runs after conversion so a
        // re-rendered document shows prior answers — required for the
        // read-only agent review.
        _restoreDisclosureAnswers() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;
            const ans = this.webDisclosureAnswers || {};
            container.querySelectorAll('.corex-disclosure-row').forEach(row => {
                const key = row.getAttribute('data-disclosure-key');
                if (!key) return;
                const val = (ans[key] || '').toString().toLowerCase();
                if (!val) return;
                row.querySelectorAll('.corex-radio-placeholder').forEach(ph => {
                    const sel = (ph.dataset.value || '').toLowerCase() === val;
                    ph.setAttribute('data-selected', sel ? 'true' : 'false');
                    ph.textContent = sel ? '●' : '○';
                });
            });
            Object.keys(ans).forEach(k => {
                if (!this._isDisclosureAnswerKey(k)) return;
                const el = container.querySelector(
                    'input[type="radio"][name="' + k + '"][value="' + ans[k] + '"]');
                if (el) el.checked = true;
            });
        },

        // .corex-disclosure-checklist converter. Registers
        // totalDisclosureRows + disclosure_row_-prefixed answers so the
        // completion gate counts it identically to the bare-table path in
        // BOTH views (pre-existing bug fixed here). Editable only for
        // owner_party; read-only (visible, non-interactive) otherwise.
        processWebDisclosureChecklists(root) {
            const container = root || this.$refs.webDocContent || null;
            if (!container) return;
            const self = this;
            // Keys are INTRINSIC per document via _disclosureKeyFor (docKey
            // + per-doc index). No cross-document cursor — a document's keys
            // are identical at any pack position. Same derivation as the
            // bare-table converter, so checklist + bare-table never collide.
            const checklists = container.querySelectorAll('.corex-disclosure-checklist');
            let gatedIdx = 0;

            checklists.forEach(checklist => {
                const disclosureParty = checklist.getAttribute('data-disclosure-party') || 'owner_party';
                const editable = self._disclosureEditable(disclosureParty);

                checklist.querySelectorAll('.corex-disclosure-row').forEach(row => {
                    const rowKey = self._disclosureKeyFor(row);
                    row.setAttribute('data-disclosure-key', rowKey);
                    row.setAttribute('data-editable', editable ? 'true' : 'false');

                    const radios = row.querySelectorAll('.corex-radio-placeholder');
                    radios.forEach(radio => {
                        const rv = (radio.dataset.value || '').toLowerCase();
                        const isSel = (self.webDisclosureAnswers[rowKey] || '')
                            .toString().toLowerCase() === rv;
                        radio.setAttribute('data-selected', isSel ? 'true' : 'false');
                        radio.textContent = isSel ? '●' : '○';
                        radio.style.fontSize = '16pt';

                        if (editable) {
                            radio.style.cursor = 'pointer';
                            radio.addEventListener('click', () => {
                                radios.forEach(r => {
                                    r.setAttribute('data-selected', 'false');
                                    r.textContent = '○';
                                });
                                radio.setAttribute('data-selected', 'true');
                                radio.textContent = '●';
                                self.webDisclosureAnswers[rowKey] = radio.dataset.value || '';
                                if (typeof self.updateIncompleteCount === 'function') self.updateIncompleteCount();
                                if (typeof self._updateIncompleteCount === 'function') self._updateIncompleteCount();
                            });
                        } else {
                            radio.style.cursor = 'default';
                        }
                    });
                    // Count toward the gate ONLY for the disclosing party.
                    // Non-disclosing signers (agent, buyer) see the grid
                    // read-only and must NOT be gated on it (PPA s70).
                    if (editable) gatedIdx++;
                });
            });

            this.totalDisclosureRows = (this.totalDisclosureRows || 0) + gatedIdx;
        },

        // Orchestrator: reset → seed from store → bare-table converter (only
        // if the view defines it) → checklist converter → restore. Idempotent
        // across §19 re-pagination. Replaces the prior pair of direct calls.
        _processAllDisclosures() {
            this.totalDisclosureRows = 0;
            // Keys are stateless (window.CoreXDisclosure) — no per-pass
            // counters to reset; identical to what the store/restore use.
            this._seedDisclosureFromStore();
            const container = this.$refs.webDocContent || null;
            if (!container) { this._restoreDisclosureAnswers(); return; }
            // Wrapper-scoped: a pack merges one .corex-document-wrapper per
            // document. Process disclosures PER wrapper so §19 per-wrapper
            // pagination cannot bleed one document's rows into another and
            // every row gets a stable, document-ordered, globally-unique
            // disclosure_row_<n> key. Single docs (0/1 wrapper) => whole
            // container, cursor 0 => byte-identical to the prior behaviour.
            const wrappers = container.querySelectorAll('.corex-document-wrapper');
            const scopes = wrappers.length >= 2 ? Array.from(wrappers) : [container];
            scopes.forEach(scope => {
                if (typeof this._processDisclosureTable === 'function') {
                    this._processDisclosureTable(scope);
                }
                this.processWebDisclosureChecklists(scope);
            });
            this._restoreDisclosureAnswers();
        },
