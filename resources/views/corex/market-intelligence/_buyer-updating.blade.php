{{-- "Updating <buyer>'s matches..." (mic-work-tab.md 13.15 addendum): shown on the buyer banner, in place of the count, while the page rebuilds the selected buyer's
     matches under the current rules (one buyer is 12-30 s). It asks for the rebuild, then reloads the page, which then shows the list. Reads the shared $buyerUpdating. --}}
@if(!empty($buyerUpdating))
<span data-qa="buyer-updating" x-data="{ failed: false, tries: 0,
        run() {
            var self = this; this.failed = false;
            var m = document.querySelector('meta[name=csrf-token]');
            fetch(@js($buyerUpdating['url']), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': m ? m.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ buyer_id: {{ (int) $buyerUpdating['buyer_id'] }} }) })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (j) { if (j.state === 'busy' && self.tries++ < 40) { setTimeout(function () { self.run(); }, 3000); } else if (j.state === 'done') { window.location.reload(); } else { self.failed = true; } })
                .catch(function () { self.failed = true; });
        } }" x-init="run()" style="font-size: .6875rem; color: var(--text-secondary);">
    <span x-show="!failed">Updating {{ $buyerUpdating['first_name'] ?: 'this buyer' }}'s matches&hellip; (about half a minute, once)</span>
    <span x-show="failed" x-cloak>Could not update the matches. <button type="button" @click="tries = 0; run()" style="background:none; border:none; padding:0; color: var(--brand-icon); text-decoration: underline; cursor:pointer; font-size: inherit;">Try again</button></span>
</span>
@endif
