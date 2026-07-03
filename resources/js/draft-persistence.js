// CoreX Offline Draft Persistence — AT-165
// -----------------------------------------------------------------------------
// A single reusable, framework-agnostic, degradation-safe client-side draft
// layer. As an agent types, allowlisted form state is continuously persisted to
// browser storage (localStorage for small payloads, IndexedDB for large ones);
// on return the form offers to restore the unsaved work. Survives sleep /
// disconnect / crash / accidental close. A successful server save clears it.
//
// DOCTRINE (spec .ai/specs/offline-draft-persistence.md):
//   - Never blocks typing. Every storage op is wrapped; failures degrade quietly.
//   - Honest: drafts say "Draft saved on this device", never "saved".
//   - Allowlist, not denylist: only declared field names are ever persisted, so
//     a field left off the list is structurally impossible to leak (POPIA).
//   - Per user + per form + per record keying; multi-tab last-write-wins.
//
// WIRING (declarative — no per-field x-model rewrite needed):
//   <form id="property-form"
//         data-draft='{"form":"property_capture","recordId":123,"version":"2026-07-03T10:00:00Z",
//                      "autosaveMs":1500,"ttlDays":7,"storage":"auto"}'
//         data-draft-fields="title,price,description,suburb,...">
//   The module auto-scans [data-draft] forms on load and attaches. Sensitive
//   fields are simply omitted from data-draft-fields.
// -----------------------------------------------------------------------------

(function () {
    'use strict';
    if (typeof window === 'undefined') return;

    const KEY_PREFIX = 'corex.draft.v1';
    const IDB_NAME = 'corex-drafts';
    const IDB_STORE = 'drafts';
    const DEFAULTS = { autosaveMs: 1500, ttlDays: 7, storage: 'auto' };
    // localStorage byte threshold above which we prefer IndexedDB.
    const IDB_THRESHOLD_BYTES = 50 * 1024;

    // ── Identity ──────────────────────────────────────────────────────────────
    function currentUserId() {
        // Prefer the authenticated user id already fetched by corex-api.js.
        const fromApi = window.CoreX && window.CoreX.loggedUser && window.CoreX.loggedUser.id;
        if (fromApi) return String(fromApi);
        const meta = document.querySelector('meta[name="corex-user-id"]');
        if (meta && meta.content) return String(meta.content);
        return 'anon';
    }

    function keyFor(userId, form, recordId) {
        const rec = (recordId === null || recordId === undefined || recordId === '') ? 'new' : String(recordId);
        return `${KEY_PREFIX}.${userId}.${form}.${rec}`;
    }

    // ── Storage backends (unified async API) ──────────────────────────────────
    // Both expose get(key)->obj|null, set(key,obj), remove(key), keys()->[string].
    const local = {
        available() {
            try { const k = '__corex_probe__'; localStorage.setItem(k, '1'); localStorage.removeItem(k); return true; }
            catch (e) { return false; }
        },
        async get(key) {
            try { const raw = localStorage.getItem(key); return raw ? JSON.parse(raw) : null; }
            catch (e) { return null; }
        },
        async set(key, obj) {
            // Throws QuotaExceededError on purpose so the caller can run eviction.
            localStorage.setItem(key, JSON.stringify(obj));
        },
        async remove(key) { try { localStorage.removeItem(key); } catch (e) {} },
        async keys() {
            const out = [];
            try { for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k && k.startsWith(KEY_PREFIX)) out.push(k); } }
            catch (e) {}
            return out;
        },
    };

    const idb = {
        _db: null,
        available() { return typeof indexedDB !== 'undefined'; },
        _open() {
            if (this._db) return Promise.resolve(this._db);
            return new Promise((resolve, reject) => {
                let req;
                try { req = indexedDB.open(IDB_NAME, 1); }
                catch (e) { return reject(e); }
                req.onupgradeneeded = () => { const db = req.result; if (!db.objectStoreNames.contains(IDB_STORE)) db.createObjectStore(IDB_STORE); };
                req.onsuccess = () => { this._db = req.result; resolve(this._db); };
                req.onerror = () => reject(req.error);
            });
        },
        async _tx(mode, fn) {
            const db = await this._open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(IDB_STORE, mode);
                const store = tx.objectStore(IDB_STORE);
                let result;
                const r = fn(store);
                if (r) r.onsuccess = () => { result = r.result; };
                tx.oncomplete = () => resolve(result);
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error);
            });
        },
        async get(key) { try { return (await this._tx('readonly', (s) => s.get(key))) || null; } catch (e) { return null; } },
        async set(key, obj) { return this._tx('readwrite', (s) => s.put(obj, key)); },
        async remove(key) { try { return this._tx('readwrite', (s) => s.delete(key)); } catch (e) {} },
        async keys() { try { return (await this._tx('readonly', (s) => s.getAllKeys())) || []; } catch (e) { return []; } },
    };

    function pickBackend(mode, approxBytes) {
        if (mode === 'local') return local;
        if (mode === 'idb') return idb.available() ? idb : local;
        // auto: IDB for large payloads (or when localStorage is unavailable).
        if (!local.available()) return idb.available() ? idb : local;
        if (idb.available() && approxBytes > IDB_THRESHOLD_BYTES) return idb;
        return local;
    }

    function byteLen(obj) { try { return JSON.stringify(obj).length; } catch (e) { return 0; } }

    // ── Cross-backend maintenance: TTL sweep + user-wide wipe ─────────────────
    async function allDraftKeys() {
        const [a, b] = await Promise.all([local.keys(), idb.available() ? idb.keys() : Promise.resolve([])]);
        return { local: a, idb: b };
    }

    async function sweepExpired() {
        const now = Date.now();
        const { local: lk, idb: ik } = await allDraftKeys();
        for (const k of lk) { const v = await local.get(k); if (v && v.expiresAt && v.expiresAt < now) await local.remove(k); }
        for (const k of ik) { const v = await idb.get(k); if (v && v.expiresAt && v.expiresAt < now) await idb.remove(k); }
    }

    // Clear-on-save signal from the server (full-page-POST forms). MUST be global:
    // the redirect target (e.g. the property show page) has no draft form, so this
    // cannot live inside a DraftManager — it runs on every page load.
    async function consumeClearSignals() {
        const meta = document.querySelector('meta[name="corex-clear-drafts"]');
        if (!meta || !meta.content) return;
        let sigs = []; try { sigs = JSON.parse(meta.content); } catch (e) { sigs = []; }
        const uid = currentUserId();
        for (const sig of sigs) {
            const [form, rec] = String(sig).split(':');
            const k = keyFor(uid, form, (rec === 'null' || rec === undefined) ? null : rec);
            for (const backend of [local, idb.available() ? idb : null].filter(Boolean)) await backend.remove(k);
        }
    }

    async function clearForCurrentUser() {
        const uid = currentUserId();
        const prefix = `${KEY_PREFIX}.${uid}.`;
        const { local: lk, idb: ik } = await allDraftKeys();
        let n = 0;
        for (const k of lk) if (k.startsWith(prefix)) { await local.remove(k); n++; }
        for (const k of ik) if (k.startsWith(prefix)) { await idb.remove(k); n++; }
        return n;
    }

    // ── Small DOM helpers for the banner + indicator ──────────────────────────
    function relativeTime(ts) {
        const s = Math.max(1, Math.round((Date.now() - ts) / 1000));
        if (s < 60) return `${s}s ago`;
        const m = Math.round(s / 60); if (m < 60) return `${m} min ago`;
        const h = Math.round(m / 60); if (h < 24) return `${h}h ago`;
        return `${Math.round(h / 24)}d ago`;
    }
    function clockOf(ts) { const d = new Date(ts); return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; }
    function el(tag, attrs, html) { const e = document.createElement(tag); if (attrs) for (const k in attrs) e.setAttribute(k, attrs[k]); if (html != null) e.innerHTML = html; return e; }

    // ── DraftManager: one per attached form ───────────────────────────────────
    class DraftManager {
        constructor(formEl, config) {
            this.form = formEl;
            this.cfg = Object.assign({}, DEFAULTS, config);
            this.userId = currentUserId();
            this.key = keyFor(this.userId, this.cfg.form, this.cfg.recordId);
            this.tabId = `${Date.now()}.${Math.floor(performance.now())}`;
            this.fields = (this.cfg.fields || []).filter(Boolean);
            this.timer = null;
            this.degraded = false;
            this.peerOpen = false;
            this._buildIndicator();
            this._wireChannel();
            this._start();
        }

        // Read the current allowlisted values out of the DOM (by name).
        readForm() {
            const data = {};
            for (const name of this.fields) {
                const nodes = this.form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`);
                if (!nodes.length) continue;
                if (nodes.length === 1 && nodes[0].type !== 'checkbox' && nodes[0].type !== 'radio') { data[name] = nodes[0].value; continue; }
                const first = nodes[0];
                if (first.type === 'checkbox' && nodes.length === 1) { data[name] = first.checked; continue; }
                if (first.type === 'radio') { const c = Array.from(nodes).find(n => n.checked); data[name] = c ? c.value : null; continue; }
                data[name] = Array.from(nodes).map(n => n.value); // multi-value (e.g. name[])
            }
            return data;
        }

        // Write restored values back into the DOM and fire input/change so any
        // Alpine/listener state syncs.
        writeForm(data) {
            for (const name in data) {
                if (!this.fields.includes(name)) continue; // never restore a non-allowlisted field
                const nodes = this.form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`);
                if (!nodes.length) continue;
                const val = data[name];
                if (nodes.length === 1 && nodes[0].type === 'checkbox') { nodes[0].checked = !!val; this._fire(nodes[0]); continue; }
                if (nodes[0].type === 'radio') { nodes.forEach(n => { n.checked = (n.value === val); if (n.checked) this._fire(n); }); continue; }
                if (Array.isArray(val) && nodes.length > 1) { nodes.forEach((n, i) => { n.value = val[i] != null ? val[i] : ''; this._fire(n); }); continue; }
                nodes[0].value = Array.isArray(val) ? (val[0] || '') : (val != null ? val : '');
                this._fire(nodes[0]);
            }
        }
        _fire(node) { node.dispatchEvent(new Event('input', { bubbles: true })); node.dispatchEvent(new Event('change', { bubbles: true })); }

        backendFor(payload) { return pickBackend(this.cfg.storage, byteLen(payload)); }

        async persist() {
            const now = Date.now();
            const payload = {
                v: 1, tabId: this.tabId, form: this.cfg.form, recordId: this.cfg.recordId ?? null,
                baseVersion: this.cfg.version ?? null, savedAt: now,
                expiresAt: now + this.cfg.ttlDays * 86400000,
                data: this.readForm(),
            };
            const backend = this.backendFor(payload);
            const ok = await this._writeWithQuotaGuard(backend, payload);
            if (ok) {
                this.degraded = false;
                this._setIndicator(`Draft saved ${clockOf(now)}`);
                try { this.channel && this.channel.postMessage({ key: this.key, tabId: this.tabId, savedAt: now }); } catch (e) {}
            }
        }

        // Wrapped write with graceful quota recovery: purge expired → evict oldest
        // for this user → degrade silently. Never throws into the form.
        async _writeWithQuotaGuard(backend, payload) {
            try { await backend.set(this.key, payload); return true; }
            catch (e1) {
                if (!this._isQuota(e1)) { return false; }
                try { await sweepExpired(); await backend.set(this.key, payload); return true; } catch (e2) {}
                try { await this._evictOldestForUser(backend); await backend.set(this.key, payload); return true; } catch (e3) {}
                this.degraded = true;
                this._setIndicator("Couldn't save draft — storage full", true);
                return false;
            }
        }
        _isQuota(e) { return e && (e.name === 'QuotaExceededError' || e.code === 22 || /quota/i.test(e.message || '')); }
        async _evictOldestForUser(backend) {
            const prefix = `${KEY_PREFIX}.${this.userId}.`;
            const keys = (await backend.keys()).filter(k => k.startsWith(prefix) && k !== this.key);
            let oldest = null, oldestTs = Infinity;
            for (const k of keys) { const v = await backend.get(k); if (v && v.savedAt < oldestTs) { oldestTs = v.savedAt; oldest = k; } }
            if (oldest) await backend.remove(oldest);
        }

        async load() {
            for (const backend of [local, idb.available() ? idb : null].filter(Boolean)) {
                const v = await backend.get(this.key);
                if (v && v.data) return v;
            }
            return null;
        }

        async clear() {
            for (const backend of [local, idb.available() ? idb : null].filter(Boolean)) { await backend.remove(this.key); }
            this._clearTimer();
            this._setIndicator('');
        }

        // Which allowlisted fields differ between the draft and the current DOM?
        diffAgainstForm(draftData) {
            const cur = this.readForm(); const diffs = [];
            for (const name of this.fields) {
                const a = JSON.stringify(draftData[name] ?? null), b = JSON.stringify(cur[name] ?? null);
                if (a !== b) diffs.push(name);
            }
            return diffs;
        }

        // ── Lifecycle ─────────────────────────────────────────────────────────
        async _start() {
            await sweepExpired();
            const draft = await this.load();
            if (draft && draft.data && this.diffAgainstForm(draft.data).length) {
                this._showRestoreBanner(draft);
            }
            this._wireInputs();
            this._wireFlush();
        }

        _wireInputs() {
            const handler = () => this._scheduleAutosave();
            this.form.addEventListener('input', handler, true);
            this.form.addEventListener('change', handler, true);
        }
        _scheduleAutosave() { this._clearTimer(); this.timer = setTimeout(() => this.persist(), this.cfg.autosaveMs); }
        _clearTimer() { if (this.timer) { clearTimeout(this.timer); this.timer = null; } }

        _wireFlush() {
            // Flush the last keystrokes on sleep/close so nothing is lost.
            const flush = () => { this._clearTimer(); this.persist(); };
            document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flush(); });
            window.addEventListener('pagehide', flush);
            window.addEventListener('beforeunload', flush);
        }

        _wireChannel() {
            try {
                this.channel = ('BroadcastChannel' in window) ? new BroadcastChannel('corex-drafts') : null;
                if (this.channel) this.channel.onmessage = (ev) => {
                    if (ev.data && ev.data.key === this.key && ev.data.tabId !== this.tabId) {
                        this.peerOpen = true; this._noteMultiTab();
                    }
                };
            } catch (e) { this.channel = null; }
        }
        _noteMultiTab() {
            if (this._peerNoteShown) return; this._peerNoteShown = true;
            const note = el('span', { 'data-draft-peer': '', style: 'margin-left:.5rem;opacity:.7;' }, '· also open in another tab — newest edit wins');
            if (this.indicator) this.indicator.appendChild(note);
        }

        // ── UI ─────────────────────────────────────────────────────────────────
        _buildIndicator() {
            // A subtle inline "Draft saved HH:MM" line appended after the form.
            this.indicator = el('div', { 'data-draft-indicator': '', 'aria-live': 'polite',
                style: 'font-size:.75rem;opacity:.7;margin-top:.25rem;min-height:1rem;' });
            this.form.appendChild(this.indicator);
        }
        _setIndicator(text, warn) {
            if (!this.indicator) return;
            this.indicator.textContent = text || '';
            this.indicator.style.color = warn ? '#b45309' : '';
            if (this.peerOpen && text) this._noteMultiTab();
        }

        _showRestoreBanner(draft) {
            const stale = draft.baseVersion != null && this.cfg.version != null && String(draft.baseVersion) !== String(this.cfg.version);
            const diffs = this.diffAgainstForm(draft.data);
            const when = `${relativeTime(draft.savedAt)} (${clockOf(draft.savedAt)})`;
            const banner = el('div', { 'data-draft-banner': '', role: 'alert',
                style: 'display:flex;gap:.75rem;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;'
                     + 'padding:.6rem .9rem;margin-bottom:.75rem;border-radius:10px;font-size:.85rem;'
                     + (stale ? 'background:#fffbeb;border:1px solid #fcd34d;color:#92400e;'
                              : 'background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;') });
            const msg = stale
                ? `This record was changed since your draft. Review before restoring — differs in: <strong>${diffs.join(', ') || '—'}</strong>.`
                : `Unsaved changes from <strong>${when}</strong> on this device.`;
            const left = el('div', null, msg);
            const right = el('div', { style: 'display:flex;gap:.5rem;flex-shrink:0;' });
            const restoreBtn = el('button', { type: 'button',
                style: 'padding:.25rem .7rem;border-radius:6px;border:1px solid currentColor;background:transparent;color:inherit;cursor:pointer;font-weight:600;' },
                stale ? 'Restore anyway' : 'Restore');
            const discardBtn = el('button', { type: 'button',
                style: 'padding:.25rem .7rem;border-radius:6px;border:1px solid transparent;background:transparent;color:inherit;cursor:pointer;opacity:.8;' },
                'Discard');
            restoreBtn.addEventListener('click', () => { this.writeForm(draft.data); banner.remove(); this._setIndicator(`Draft restored from ${clockOf(draft.savedAt)}`); });
            discardBtn.addEventListener('click', async () => { await this.clear(); banner.remove(); this._offerUndo(draft); });
            right.appendChild(restoreBtn); right.appendChild(discardBtn);
            banner.appendChild(left); banner.appendChild(right);
            this.form.parentNode.insertBefore(banner, this.form);
        }

        _offerUndo(draft) {
            const undo = el('div', { 'data-draft-undo': '',
                style: 'padding:.5rem .9rem;margin-bottom:.75rem;border-radius:8px;background:#f1f5f9;color:#334155;font-size:.8rem;display:flex;gap:.75rem;align-items:center;justify-content:space-between;' },
                'Draft discarded.');
            const btn = el('button', { type: 'button', style: 'text-decoration:underline;background:none;border:none;color:inherit;cursor:pointer;' }, 'Undo');
            btn.addEventListener('click', async () => { const backend = this.backendFor(draft); await this._writeWithQuotaGuard(backend, draft); undo.remove(); this._showRestoreBanner(draft); });
            undo.appendChild(btn);
            this.form.parentNode.insertBefore(undo, this.form);
            setTimeout(() => undo.remove(), 8000);
        }
    }

    // ── Public API + auto-scan ────────────────────────────────────────────────
    function parseConfig(formEl) {
        let cfg = {};
        try { cfg = JSON.parse(formEl.getAttribute('data-draft') || '{}'); } catch (e) { cfg = {}; }
        const fieldsAttr = formEl.getAttribute('data-draft-fields') || '';
        cfg.fields = cfg.fields || fieldsAttr.split(',').map(s => s.trim()).filter(Boolean);
        return cfg;
    }

    const registry = new WeakMap();
    function attach(formEl, config) {
        if (!formEl || registry.has(formEl)) return registry.get(formEl);
        const cfg = Object.assign({}, config || parseConfig(formEl));
        if (!cfg.form || !cfg.fields || !cfg.fields.length) return null; // nothing to persist
        const mgr = new DraftManager(formEl, cfg);
        registry.set(formEl, mgr);
        return mgr;
    }

    function scan() {
        // Global first: honour any clear-on-save signal even on pages with no form.
        consumeClearSignals();
        document.querySelectorAll('form[data-draft]').forEach((f) => attach(f));
    }

    window.CoreX = window.CoreX || {};
    window.CoreX.draft = {
        attach,
        scan,
        consumeClearSignals,
        clearForCurrentUser,
        sweepExpired,
        managerFor(formEl) { return registry.get(formEl) || null; },
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
})();
