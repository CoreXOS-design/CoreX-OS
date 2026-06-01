{{--
    MIC Phase D5 — shared lazy-loaded slide-over for suburb deep-dives and
    demand-pocket narratives. Included once per page; opens on dispatched
    events:

      window.dispatchEvent(new CustomEvent('mic-open-suburb', { detail: { suburb: 'Margate' } }));
      window.dispatchEvent(new CustomEvent('mic-open-pocket', { detail: { suburb: 'Margate', bedrooms: 3 } }));

    Body is HTML for suburb (server-rendered partial) or built client-side
    from the pocketNarrative JSON.
--}}
<div x-data="micSlideover()"
     @mic-open-suburb.window="openSuburb($event.detail.suburb)"
     @mic-open-pocket.window="openPocket($event.detail.suburb, $event.detail.bedrooms)"
     @keydown.escape.window="close()"
     x-show="open" x-cloak
     style="position: fixed; inset: 0; z-index: 60; display: flex; justify-content: flex-end;
            background: rgba(15, 23, 42, 0.45);">
    <aside @click.outside="close()"
           style="width: min(480px, 100%); height: 100%; background: var(--surface);
                  border-left: 1px solid var(--border); overflow-y: auto;
                  box-shadow: -8px 0 24px rgba(0,0,0,0.18);">
        <div style="display: flex; justify-content: flex-end; padding: 8px;">
            <button type="button" @click="close()"
                    style="background: none; border: none; cursor: pointer;
                           font-size: 1.25rem; color: var(--text-muted); padding: 4px 8px;">×</button>
        </div>
        <div x-show="loading" style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.8125rem;">
            Loading…
        </div>
        <div x-show="!loading" x-html="body"></div>
    </aside>
</div>

<script>
    window.micSlideover = function () {
        return {
            open: false,
            loading: false,
            body: '',
            close() {
                this.open = false;
                this.body = '';
            },
            openSuburb(suburb) {
                if (!suburb) return;
                this.open = true;
                this.loading = true;
                this.body = '';
                const url = '/corex/market-intelligence/suburb/' + encodeURIComponent(suburb);
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                    .then(r => r.ok ? r.text() : Promise.reject('HTTP ' + r.status))
                    .then(html => { this.body = html; })
                    .catch(err => { this.body = '<div style="padding:20px;color:var(--ds-crimson, #dc2626);">Could not load: ' + err + '</div>'; })
                    .finally(() => { this.loading = false; });
            },
            openPocket(suburb, bedrooms) {
                if (!suburb || !bedrooms) return;
                this.open = true;
                this.loading = true;
                this.body = '';
                const params = new URLSearchParams({ suburb, bedrooms });
                const url = '/corex/market-intelligence/analyse/pocket-narrative?' + params.toString();
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
                    .then(data => {
                        const facts = data.facts || {};
                        const cacheBadge = data.from_cache ? 'cached' : 'fresh';
                        const fallbackBadge = data.from_fallback
                            ? '<span style="font-size:0.625rem;padding:1px 6px;border-radius:6px;background:color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent);color:var(--ds-amber, #f59e0b);font-weight:600;margin-left:6px;">Fallback</span>'
                            : '';
                        this.body = `
                            <div style="padding:16px;max-width:480px;">
                                <h3 style="font-size:1.0625rem;font-weight:600;margin:0 0 8px 0;">${this._esc(facts.suburb || suburb)} · ${facts.bedrooms || bedrooms}-bed</h3>
                                ${fallbackBadge}
                                <div style="padding:10px 12px;margin:12px 0;border-radius:6px;background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface));border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, var(--border));">
                                    <div style="font-size:0.625rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--brand-icon, #0ea5e9);margin-bottom:4px;">Ellie's read</div>
                                    <div style="font-size:0.8125rem;line-height:1.5;white-space:pre-line;">${this._esc(data.narrative || '')}</div>
                                    <div style="margin-top:6px;font-size:0.625rem;color:var(--text-muted);">${cacheBadge} · just now</div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                                    <div style="padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;">
                                        <div style="font-size:0.625rem;text-transform:uppercase;font-weight:600;color:var(--text-muted);">Strong buyers</div>
                                        <div style="font-size:1.0625rem;font-weight:600;color:var(--ds-green, #10b981);">${(facts.buyer_count ?? 0).toLocaleString()}</div>
                                    </div>
                                    <div style="padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;">
                                        <div style="font-size:0.625rem;text-transform:uppercase;font-weight:600;color:var(--text-muted);">Active listings</div>
                                        <div style="font-size:1.0625rem;font-weight:600;">${(facts.listing_count ?? 0).toLocaleString()}</div>
                                    </div>
                                </div>
                                ${facts.avg_price ? `<div style="margin-top:8px;padding:8px 10px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;font-size:0.75rem;">Average asking: <strong>R ${facts.avg_price.toLocaleString()}</strong></div>` : ''}
                                <div style="margin-top:14px;display:flex;gap:6px;flex-wrap:wrap;">
                                    <a href="/corex/market-intelligence/?suburb=${encodeURIComponent(facts.suburb || suburb)}&bedrooms_exact=${facts.bedrooms || bedrooms}&action_preset=pitch_now_high" style="padding:5px 10px;font-size:0.6875rem;font-weight:600;background:var(--brand-button);color:#fff;border-radius:4px;text-decoration:none;">Pitch this pocket →</a>
                                </div>
                            </div>`;
                    })
                    .catch(err => { this.body = '<div style="padding:20px;color:var(--ds-crimson, #dc2626);">Could not load: ' + err + '</div>'; })
                    .finally(() => { this.loading = false; });
            },
            _esc(s) {
                return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            },
        };
    };
</script>
