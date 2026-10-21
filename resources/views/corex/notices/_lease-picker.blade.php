{{--
    AT-495 - the lease picker behind the Notices tabs' buttons ("Record a received notice", "Send notice", "Issue breach notice"). A notice is
    always about one lease, so the button first asks WHICH lease (search by tenant name, property address or lease number), then opens the existing
    screen for it. Include once per page; open it with a button from corex.notices._pick-lease-button. Data: GET /api/v1/rentals/notices/leases
    (only the leases this user may see). Needs nothing from the including page.
--}}
@once
<div x-data="noticeLeasePicker(@js(route('api.v1.rentals.notices.leases')))" x-on:open-lease-picker.window="open($event.detail)" x-on:keydown.escape.window="close()"
     x-show="shown" x-cloak class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-24" style="background: rgba(0,0,0,.45);" role="dialog" aria-modal="true" data-qa="lease-picker">
    <div class="w-full max-w-xl rounded-md p-5 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" x-on:click.outside="close()">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold" x-text="title" data-qa="lease-picker-title"></h2>
                <p class="text-xs" style="color: var(--text-muted);">Search by tenant name, property address or lease number, then choose the lease.</p>
            </div>
            <button type="button" class="corex-btn-outline text-xs" x-on:click="close()">Close</button>
        </div>
        <input type="text" x-ref="q" x-model="q" x-on:input="typed()" class="prop-input w-full" placeholder="Tenant, address or lease number" autocomplete="off" data-qa="lease-picker-search">
        <div class="text-sm" x-show="error" x-text="error" style="color: #991b1b;"></div>
        <div class="space-y-2" style="max-height: 22rem; overflow-y: auto;" data-qa="lease-picker-results">
            <div class="text-xs" x-show="loading" style="color: var(--text-muted);">Searching&hellip;</div>
            <div class="text-sm py-4 text-center" x-show="searched && !loading && results.length === 0 && !error" style="color: var(--text-muted);" data-qa="lease-picker-empty">No leases found. Try a different name, address or lease number.</div>
            <template x-for="lease in results" :key="lease.id">
                <div class="rounded-md px-3 py-2 flex flex-wrap items-center justify-between gap-2" style="border: 1px solid var(--border);" data-lease-result>
                    <div class="min-w-0">
                        <div class="text-sm font-medium" style="color: var(--text-primary);" x-text="lease.property"></div>
                        <div class="text-xs" style="color: var(--text-muted);"><span x-text="lease.number"></span> &middot; <span x-text="lease.tenant"></span> &middot; <span x-text="lease.status"></span></div>
                        <div class="text-xs mt-1" x-show="lease.note" x-text="lease.note" style="color: #b45309;"></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="a in lease.actions" :key="a.url">
                            <a :href="a.url" class="corex-btn-primary text-xs" x-text="a.label"></a>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
<script>
    window.noticeLeasePicker = function (endpoint) {
        return {
            shown: false, purpose: '', title: '', template: null, q: '', results: [], loading: false, searched: false, error: '', timer: null, seq: 0,
            open(d) {
                this.purpose = d.purpose; this.title = d.title || 'Choose the lease'; this.template = d.template || null;
                this.q = ''; this.results = []; this.error = ''; this.searched = false; this.shown = true;
                this.$nextTick(() => { if (this.$refs.q) { this.$refs.q.focus(); } });
                this.load();
            },
            close() { this.shown = false; },
            typed() { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(), 250); },
            async load() {
                const n = ++this.seq;
                this.loading = true; this.error = '';
                try {
                    const p = new URLSearchParams({ purpose: this.purpose, q: this.q });
                    if (this.template) { p.set('template', this.template); }
                    const r = await fetch(endpoint + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    const j = await r.json();
                    if (n === this.seq) { this.results = j.leases || []; }
                } catch (e) {
                    if (n === this.seq) { this.error = 'The leases could not be loaded. Close this and try again.'; }
                } finally {
                    if (n === this.seq) { this.loading = false; this.searched = true; }
                }
            },
        };
    };
    document.addEventListener('click', function (e) {
        const b = e.target.closest('[data-lease-picker]');
        if (!b) { return; }
        e.preventDefault();
        window.dispatchEvent(new CustomEvent('open-lease-picker', { detail: JSON.parse(b.getAttribute('data-lease-picker')) }));
    });
</script>
@endonce
