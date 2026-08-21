{{--
    CX-108 — "Linked Emails" tab of the DR2 pipeline right panel.
    Manual email-to-deal link/unlink, reachable from DR2 (Johan: "email arrives
    in holding area - agent can go and link to a deal"). Backed by
    Dr2CommunicationLinkController (routes: deals-dr2.communications.*).
    include('dr2._communications', ['deal' => $deal]).
--}}
<div style="padding:1rem;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.5rem;" data-tour="dr2-communications"
     x-data="{
        open: true,
        links: [],
        q: '', results: [], searching: false, linking: false, err: '',
        expandedId: null, expandedHtml: '', expanding: false,
        async toggleExpand(commId){
            if(this.expandedId === commId){ this.expandedId = null; return; }
            this.expandedId = commId; this.expandedHtml = ''; this.expanding = true;
            try {
                const r = await fetch('{{ url('deals-dr2/communications') }}/' + commId + '/body', {headers:{Accept:'text/html'}});
                this.expandedHtml = r.ok ? await r.text() : '<p style=\'color:#b91c1c;font-size:.8rem;\'>Could not load this email.</p>';
            } catch(e) { this.expandedHtml = '<p style=\'color:#b91c1c;font-size:.8rem;\'>Could not load this email.</p>'; }
            this.expanding = false;
        },
        async loadLinks(){
            try {
                const r = await fetch('{{ route('deals-dr2.communications.index', $deal) }}', {headers:{Accept:'application/json'}});
                const j = await r.json();
                this.links = (j && j.data) || [];
            } catch(e) { this.links = []; }
        },
        async search(){
            if(this.q.trim().length < 2){ this.results = []; return; }
            this.searching = true;
            try {
                const r = await fetch('{{ route('deals-dr2.communications.search', $deal) }}?q=' + encodeURIComponent(this.q.trim()), {headers:{Accept:'application/json'}});
                const j = await r.json();
                this.results = (j && j.data) || [];
            } catch(e) { this.results = []; }
            this.searching = false;
        },
        async link(commId){
            this.linking = true; this.err = '';
            try {
                const r = await fetch('{{ route('deals-dr2.communications.link', $deal) }}', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({communication_id: commId})
                });
                const j = await r.json();
                if(r.ok && j.ok){ this.q = ''; this.results = []; await this.loadLinks(); }
                else { this.err = (j.message || 'Could not link that email.'); }
            } catch(e) { this.err = 'Could not link that email.'; }
            this.linking = false;
        },
        async unlink(linkId){
            if(!confirm('Unlink this email from the deal? The reference history captured from it is kept.')) return;
            this.err = '';
            try {
                const r = await fetch('{{ url('deals-dr2') }}/{{ $deal->id }}/communications/' + linkId + '/unlink', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin'
                });
                const j = await r.json();
                if(r.ok && j.ok){ await this.loadLinks(); }
                else { this.err = (j.message || 'Could not unlink that email.'); }
            } catch(e) { this.err = 'Could not unlink that email.'; }
        }
     }"
     x-init="loadLinks()">

    <button type="button" @click="open = !open" class="dr2-sect-head">
        <span class="dr2-chev" :class="open ? '' : 'dr2-chev-closed'">▾</span>
        <span class="dr2-sect-title">Linked emails</span>
    </button>

    <div x-show="open" x-cloak style="margin-top:.6rem;">
        <p style="margin:0 0 .6rem;font-size:.78rem;color:var(--text-muted,#6b7280);">
            Link an email from the holding area to this deal, or unlink one that was filed to the wrong deal.
        </p>

        {{-- Search + link --}}
        <div style="position:relative;margin-bottom:.7rem;">
            <input type="text" x-model="q" @input.debounce.220ms="search()" autocomplete="off"
                   placeholder="Search unlinked emails by subject or sender…" class="corex-input" style="width:100%;font-size:.8rem;">
            <div x-show="results.length" x-cloak style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;">
                <template x-for="row in results" :key="row.id">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.5rem .7rem;border-bottom:1px solid #f3f4f6;">
                        <div style="min-width:0;">
                            <div style="font-weight:600;color:#0b2a4a;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="row.subject || '(no subject)'"></div>
                            <div style="font-size:.72rem;color:#6b7280;" x-text="[row.from_identifier, row.occurred_at].filter(Boolean).join(' · ')"></div>
                        </div>
                        <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.2rem .6rem;white-space:nowrap;" :disabled="linking" @click="link(row.id)">Link</button>
                    </div>
                </template>
            </div>
        </div>
        <p x-show="err" x-cloak x-text="err" style="color:#b91c1c;font-size:.75rem;margin:0 0 .6rem;"></p>

        {{-- Currently linked --}}
        <div x-show="links.length === 0" x-cloak style="font-size:.78rem;color:var(--text-muted,#9ca3af);">
            No emails linked to this deal yet.
        </div>
        <div style="display:flex;flex-direction:column;gap:.35rem;">
            <template x-for="row in links" :key="row.id">
                <div>
                    {{-- CX-112 — read the email before trusting a filing decision to it. Reuses
                         the SAME on-demand viewer as the Unfiled Emails screen, so an agent
                         learns one email viewer, not two. --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;font-size:.78rem;padding:.4rem .55rem;border:1px solid var(--border,rgba(0,0,0,.06));border-radius:6px;cursor:pointer;"
                         @click="toggleExpand(row.communication_id)">
                        <div style="min-width:0;">
                            <span style="display:inline-block;width:.8rem;color:#9ca3af;" x-text="expandedId === row.communication_id ? '▾' : '▸'"></span>
                            <span style="font-weight:600;" x-text="(row.communication && row.communication.subject) || '(no subject)'"></span>
                            <span style="color:#9ca3af;" x-text="' — ' + ((row.communication && row.communication.from_identifier) || '')"></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;white-space:nowrap;">
                            <span style="color:#9ca3af;" x-text="row.link_method"></span>
                            <button type="button" class="corex-btn-outline" style="font-size:.72rem;padding:.2rem .6rem;" @click.stop="unlink(row.id)">Unlink</button>
                        </div>
                    </div>
                    <div x-show="expandedId === row.communication_id" x-cloak style="padding:.6rem;margin-top:.3rem;background:var(--surface-muted,#f9fafb);border-radius:6px;">
                        <div x-show="expanding" x-cloak style="font-size:.78rem;color:var(--text-muted,#9ca3af);">Loading…</div>
                        <div x-show="!expanding" x-html="expandedHtml"></div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
