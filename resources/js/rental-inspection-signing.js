// .ai/specs/rental-inspections.md §46 — the agent's "Sign by link" panel: one row per party (each tenant, the landlord,
// the agent) with the status of their personal signing link and the ways to get it to them — email, WhatsApp, copy,
// a full-screen QR code, or "sign on this device". Used on the inspection page and on the phone recording screen, so it
// is a plain global factory (like signaturePlacer()) and not tied to either page's own Alpine scope.
//
// cfg: { inspectionId, base }  — base is the /corex/rental-inspections URL prefix; reloadOnChange: true reloads the page
// when a party signs (the inspection page); otherwise a 'signing-links-changed' event is dispatched for the host screen.
window.inspectionSigningLinks = function (cfg) {
    return {
        panel: null,
        loading: true,
        error: '',
        notice: '',
        busy: '',
        qr: { open: false, name: '', dataUri: '', url: '' },
        timer: null,
        lastSeen: '',

        get root() { return `${cfg.base}/${cfg.inspectionId}/signing-links`; },
        csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },

        init() {
            this.load();
            // The agent's screen should notice a party signing on their own phone without a manual refresh.
            this.timer = setInterval(() => { if (!document.hidden && !this.qr.open) this.load(true); }, 15000);
        },

        async request(method, url, body) {
            const opts = { method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' } };
            if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
            const res = await fetch(url, opts);
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || ('That did not work (error ' + res.status + ').'));
            return data;
        },

        async load(quiet) {
            try {
                const data = await this.request('GET', this.root);
                this.apply(data);
            } catch (e) { if (!quiet) this.error = e.message; }
            finally { this.loading = false; }
        },

        // Take a new panel; when someone has signed or responded since we last looked (here or on their own phone),
        // tell the host screen so it can refresh its own signature list.
        apply(panel) {
            const seen = (panel.rows || []).map(r => r.key + ':' + (r.recorded ? r.recorded.disposition : '') + ':' + (r.link ? r.link.status : '')).join('|');
            const recordedNow = (panel.rows || []).filter(r => r.recorded).length;
            const changedRecorded = this.panel && recordedNow !== (this.panel.rows || []).filter(r => r.recorded).length;
            this.panel = panel;
            this.lastSeen = seen;
            if (changedRecorded) {
                if (cfg.reloadOnChange) { window.location.reload(); } else { this.$dispatch('signing-links-changed'); }
            }
        },

        async act(row, fn) {
            this.error = ''; this.notice = ''; this.busy = row ? row.key : 'all';
            try { await fn(); } catch (e) { this.error = e.message; }
            finally { this.busy = ''; }
        },

        // Make sure the party has a live link (and say how it is being shared); returns the server's answer.
        async ensure(row, channel) {
            const data = await this.request('POST', this.root, { party_role: row.role, party_contact_id: row.contact_id, channel: channel || null });
            this.apply(data.panel);
            return data;
        },

        linkOf(row) { return row.link; },

        email(row) {
            return this.act(row, async () => {
                const data = await this.ensure(row, null);
                const sent = await this.request('POST', `${this.root}/${data.link_id}/email`);
                this.apply(sent.panel);
                if (sent.result.status !== 'sent') throw new Error(sent.result.error || 'The email did not send.');
                this.notice = 'Emailed to ' + sent.result.to + '.';
            });
        },

        sendAll() {
            return this.act(null, async () => {
                const data = await this.request('POST', `${this.root}/send-all`);
                this.apply(data.panel);
                const sent = data.results.filter(r => r.status === 'sent').length;
                const problems = data.results.filter(r => r.status === 'failed' || r.status === 'skipped');
                this.notice = sent + ' emailed.' + (problems.length ? ' Not sent: ' + problems.map(p => p.name + ' (' + (p.error || p.status) + ')').join('; ') : '');
            });
        },

        whatsapp(row) {
            return this.act(row, async () => {
                const data = await this.ensure(row, 'whatsapp');
                window.open(data.whatsapp_url, '_blank', 'noopener');
            });
        },

        copy(row) {
            return this.act(row, async () => {
                const data = await this.ensure(row, 'copied');
                try { await navigator.clipboard.writeText(data.url); this.notice = 'Link copied.'; }
                catch (e) { window.corexNotice('Copy this link:\n' + data.url, 'Copy this link'); }
            });
        },

        showQr(row) {
            return this.act(row, async () => {
                const data = await this.ensure(row, null);
                const qr = await this.request('GET', `${this.root}/${data.link_id}/qr`);
                this.qr = { open: true, name: row.name, dataUri: qr.data_uri, url: qr.url };
                this.load(true);
            });
        },

        signHere(row) {
            return this.act(row, async () => {
                const data = await this.ensure(row, 'device');
                window.location.href = data.device_url;
            });
        },

        async revoke(row) {
            if (!row.link || !(await window.corexConfirm({ title: 'Revoke link', message: 'Revoke ' + row.name + "'s link? It stops working straight away.", confirmLabel: 'Revoke', danger: true }))) return;
            return this.act(row, async () => {
                const data = await this.request('POST', `${this.root}/${row.link.id}/revoke`);
                this.apply(data.panel);
                this.notice = 'Link revoked.';
            });
        },

        // Display helpers
        chipStyle(row) {
            const s = row.recorded ? 'recorded' : (row.link ? row.link.status : 'not_sent');
            const map = {
                signed: '#059669', recorded: '#059669', declined: '#dc2626', opened: '#0284c7', sent: '#7c3aed',
                expired: '#9ca3af', revoked: '#9ca3af', not_sent: '#6b7280',
            };
            const c = map[s] || '#6b7280';
            return `background: color-mix(in srgb, ${c} 14%, transparent); color: ${c};`;
        },
        chipText(row) {
            if (row.recorded) return row.recorded.label + (row.recorded.via ? ' ' + row.recorded.via : '');
            return row.link ? row.link.status_label : 'Not sent';
        },
        detail(row) {
            const l = row.link;
            if (!l) return '';
            const t = (iso) => iso ? new Date(iso).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
            const parts = [];
            if (l.last_sent_at) parts.push('Sent' + (l.last_sent_channel === 'email' ? ' by email' + (l.last_sent_to ? ' to ' + l.last_sent_to : '') : l.last_sent_channel ? ' (' + l.last_sent_channel + ')' : '') + ' ' + t(l.last_sent_at));
            if (l.last_send_status === 'failed') parts.push('Email failed: ' + (l.last_send_error || 'unknown error'));
            if (l.last_send_status === 'skipped') parts.push(l.last_send_error || 'No email address on file.');
            if (l.opened_at) parts.push('Opened ' + t(l.opened_at));
            if (l.outcome_at) parts.push((l.status === 'declined' ? 'Responded ' : 'Signed ') + t(l.outcome_at));
            if (l.live && !l.outcome_at && l.expires_at) parts.push('Live until ' + new Date(l.expires_at).toLocaleDateString([], { day: 'numeric', month: 'short' }));
            return parts.join(' · ');
        },
    };
};

// .ai/specs/rental-inspections.md §47 — the lock on a signed / sent report.
//   • signed, not yet sent: "Edit report" — warns that ALL signatures are cleared, asks why, then voids them and reopens.
//   • sent to the parties (completed / copies sent): never editable, by anyone — only "Start new inspection" is left.
// State comes from the same panel feed as the Sign-by-link panel (GET …/signing-links → lock + reopened).
// cfg: { inspectionId, base, canEdit, canStart, reloadOnChange }
window.inspectionReportLock = function (cfg) {
    return {
        lock: null,
        reopened: null,
        confirming: false,
        reason: '',
        busy: false,
        error: '',
        notice: '',

        csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },
        get root() { return `${cfg.base}/${cfg.inspectionId}`; },

        init() { this.load(); },

        async request(method, url, body) {
            const opts = { method, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'X-Requested-With': 'XMLHttpRequest' } };
            if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
            const res = await fetch(url, opts);
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || ('That did not work (error ' + res.status + ').'));
            return data;
        },

        async load() {
            try {
                const data = await this.request('GET', `${this.root}/signing-links`);
                this.lock = data.lock;
                this.reopened = data.reopened;
            } catch (e) { /* the banner simply stays hidden */ }
        },

        get visible() {
            return !!this.lock && (this.lock.signed_locked || this.lock.distributed || this.lock.replaces || this.lock.replaced_by || (this.reopened && this.reopened.resend_needed));
        },

        async reopen() {
            this.error = '';
            if (this.reason.trim().length < 3) { this.error = 'Please say why the report is being changed.'; return; }
            this.busy = true;
            try {
                const data = await this.request('POST', `${this.root}/reopen`, { reason: this.reason });
                this.notice = data.message;
                this.confirming = false;
                this.reason = '';
                await this.load();
                if (cfg.reloadOnChange) { window.location.reload(); } else { this.$dispatch('signing-links-changed'); }
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },

        async startNew() {
            this.error = '';
            this.busy = true;
            try {
                const data = await this.request('POST', `${this.root}/replace`, {});
                if (cfg.reloadOnChange) { window.location.href = data.url; return; }
                this.notice = 'New inspection started — it replaces this one.';
                await this.load();
                this.$dispatch('signing-links-changed');
            } catch (e) { this.error = e.message; }
            finally { this.busy = false; }
        },
    };
};
