{{-- "Not for <buyer>" (mic-work-tab.md 13.17): a small text button on the top line of the card's right panel, next to the Score, ONLY when a buyer is selected.
     Click -> the card collapses to a one-line stub "Hidden for <first name> · Undo" for 10 seconds (no browser confirm), then leaves the list and the banner count drops by one.
     In the "hidden" list (?hidden=1) the same place shows "Restore". Reads the row's own data ($adv: buyer_id, buyer_first_name, buyer_hidden, buyer_exclusion_id, key); nothing is worked out here.
     Included once from _advert-intel.blade.php (cc6's file). --}}
@if(!empty($adv['buyer_id']))
@php
    $bhFirst = trim((string) ($adv['buyer_first_name'] ?? '')) !== '' ? trim((string) $adv['buyer_first_name']) : 'buyer';
    $bhConfig = [
        'buyer' => (int) $adv['buyer_id'],
        'file' => (int) ($adv['key'] ?? 0),
        'first' => $bhFirst,
        'hidden' => !empty($adv['buyer_hidden']),
        'exclusion' => $adv['buyer_exclusion_id'] ?? null,
        'storeUrl' => route('market-intelligence.buyer-hide.store'),
        'restoreUrl' => route('market-intelligence.buyer-hide.restore', 0),
    ];
@endphp
<span x-data="micBuyerHide(@js($bhConfig))" data-qa="buyer-hide" style="flex-shrink:0;">
    <button type="button" x-show="!hidden" @click="hide()" :disabled="busy" data-qa="buyer-hide-btn" title="Take this property out of {{ $bhFirst }}'s list. It stays out until you restore it."
            style="background:none; border:none; padding:0 2px; margin:0; font-size:.75rem; font-weight:600; line-height:1.5rem; color: var(--text-secondary); cursor:pointer; text-decoration: underline; white-space:nowrap;">Not for {{ $bhFirst }}</button>
    <button type="button" x-show="hidden && !stubbed" x-cloak @click="restore()" :disabled="busy" data-qa="buyer-restore-btn"
            style="background:none; border:none; padding:0 2px; margin:0; font-size:.75rem; font-weight:600; line-height:1.5rem; color: var(--brand-icon); cursor:pointer; text-decoration: underline; white-space:nowrap;">Restore</button>
</span>
@once
<script>
    window.micBuyerHide = function (c) {
        return {
            hidden: c.hidden, stubbed: false, busy: false, exclusion: c.exclusion, timer: null, stub: null,
            token() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; },
            post(url, body) {
                return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.token(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) })
                    .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
            },
            bump(delta, hiddenCount) {
                var el = document.querySelector('[data-qa="buyer-banner-count"]');
                if (el) { el.innerHTML = el.innerHTML.replace(/^\s*[0-9,]+/, function (n) { return Math.max(0, parseInt(n.replace(/[^0-9]/g, ''), 10) + delta).toLocaleString('en-US'); }); }
                var link = document.querySelector('[data-qa="buyer-hidden-link"]');
                if (link && hiddenCount !== undefined) {
                    var n = link.querySelector('[data-n]'); if (n) { n.textContent = hiddenCount; }
                    link.style.display = hiddenCount > 0 || link.dataset.viewing === '1' ? '' : 'none';
                }
            },
            article() { return this.$el.closest('article.mi-advert'); },
            wrap() { return this.$el.closest('[data-qa="row-wrap"]'); },
            hide() {
                var self = this; this.busy = true;
                this.post(c.storeUrl, { buyer_id: c.buyer, file: c.file }).then(function (j) {
                    self.exclusion = j.id; self.hidden = true; self.stubbed = true; self.busy = false;
                    var art = self.article(), wrap = self.wrap();
                    if (art) { art.style.display = 'none'; }
                    var stub = document.createElement('div');
                    stub.setAttribute('data-qa', 'buyer-hide-stub');
                    stub.style.cssText = 'display:flex; align-items:center; gap:8px; padding:6px 12px; font-size:.75rem; color: var(--text-secondary); background: var(--bg); opacity:.85;';
                    stub.innerHTML = '<span></span><button type="button" style="background:none; border:none; padding:0; font-size:.75rem; font-weight:700; color: var(--brand-icon); cursor:pointer; text-decoration: underline;">Undo</button>';
                    stub.firstChild.textContent = 'Hidden for ' + c.first + ' · ';
                    stub.lastChild.addEventListener('click', function () { self.undo(); });
                    if (wrap) { wrap.insertBefore(stub, wrap.firstChild); }
                    self.stub = stub;
                    self.bump(-1, j.hidden_count);
                    self.timer = setTimeout(function () { if (wrap) { wrap.remove(); } }, 10000);
                }).catch(function () { self.busy = false; alert('Could not hide this property. Try again.'); });
            },
            undo() {
                var self = this; clearTimeout(this.timer);
                this.post(c.restoreUrl.replace(/\/0\//, '/' + this.exclusion + '/'), {}).then(function (j) {
                    var art = self.article(); if (art) { art.style.display = ''; }
                    if (self.stub) { self.stub.remove(); self.stub = null; }
                    self.hidden = false; self.stubbed = false; self.bump(1, j.hidden_count);
                }).catch(function () { alert('Could not undo. Use Restore in the hidden list.'); });
            },
            restore() {
                var self = this; this.busy = true;
                this.post(c.restoreUrl.replace(/\/0\//, '/' + this.exclusion + '/'), {}).then(function (j) {
                    var wrap = self.wrap(); if (wrap) { wrap.remove(); }
                    self.bump(-1, j.hidden_count);
                }).catch(function () { self.busy = false; alert('Could not restore. Try again.'); });
            }
        };
    };
</script>
@endonce
@endif
