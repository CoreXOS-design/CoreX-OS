@extends('layouts.corex')

{{-- Dedicated Pending Authorisations screen (cc1). A full-status practitioner sees a
     LIST of certificates awaiting authorisation → REVIEW (read-only, the finished PDF)
     → Authorise & sign / Reject. NOT the create/edit builder: no editable fields, no
     "unsaved changes" gate — the authoriser signs a submitted cert, never edits it. --}}

@section('corex-content')
<style>
  #eval-auth [x-cloak]{display:none!important;}
  #eval-auth .ea-card{background:var(--surface,#fff);border:1px solid var(--border,#e3e8f0);border-radius:12px;padding:20px;}
  #eval-auth .ea-h{font-size:18px;font-weight:700;color:var(--text-primary,#14315a);margin:0 0 14px;}
  #eval-auth table{width:100%;border-collapse:collapse;}
  #eval-auth thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary,#6b7280);padding:8px 10px;border-bottom:1px solid var(--border,#e3e8f0);}
  #eval-auth tbody td{padding:10px;border-bottom:1px solid var(--border,#eef2f7);font-size:13px;color:var(--text-primary,#1f2430);vertical-align:middle;}
  #eval-auth .ea-badge{display:inline-block;background:#b45309;color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:2px 10px;}
  #eval-auth .ea-modal{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;}
  #eval-auth .ea-modal .box{background:#fff;border-radius:14px;padding:22px;max-width:440px;width:90%;}
  #eval-auth input,#eval-auth textarea{width:100%;padding:9px 11px;border:1px solid var(--border,#cbd5e1);border-radius:8px;font-size:14px;}
  #eval-auth label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary,#6b7280);margin-bottom:4px;}
</style>

<div id="eval-auth" class="w-full" x-data="evalAuth()" x-init="init()">
  <div class="ea-card">
    <h1 class="ea-h">Pending Authorisations</h1>

    <template x-if="!savedSigConfigured">
      <p style="font-size:13px;color:#b45309;">You need a saved signature + signing PIN to authorise — set it up in
        <a href="/my-portal#signature" style="text-decoration:underline;">My Portal</a>, then reload this page.</p>
    </template>

    {{-- LIST — scalable to many pending at once --}}
    <template x-if="!selected">
      <div>
        <template x-if="!loading && !items.length">
          <p style="color:var(--text-secondary,#6b7280);font-size:14px;">Nothing is awaiting your authorisation right now.</p>
        </template>
        <template x-if="items.length">
          <div style="overflow-x:auto;">
            <table>
              <thead><tr><th>Property</th><th>Value</th><th>Submitting agent</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <template x-for="q in items" :key="q.id">
                  <tr>
                    <td x-text="q.address"></td>
                    <td x-text="q.estimated_market_value ? ('R ' + Number(q.estimated_market_value).toLocaleString('en-ZA')) : '—'"></td>
                    <td x-text="q.candidate_name || '—'"></td>
                    <td x-text="q.submitted_at || '—'"></td>
                    <td><span class="ea-badge">Pending authorisation</span></td>
                    <td style="text-align:right;"><button class="corex-btn-primary" @click="review(q)">Review</button></td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </template>

    {{-- REVIEW — read-only; the finished certificate as it will be signed --}}
    <template x-if="selected">
      <div>
        <button class="corex-btn-outline" @click="selected=null" style="margin-bottom:12px;">&larr; Back to list</button>
        <div style="margin-bottom:8px;">
          <span style="font-weight:700;color:var(--text-primary,#14315a);" x-text="selected.address"></span>
          <span style="color:var(--text-secondary,#6b7280);"
                x-text="(selected.estimated_market_value ? (' — R ' + Number(selected.estimated_market_value).toLocaleString('en-ZA')) : '') + ' · submitted by ' + (selected.candidate_name || 'candidate') + (selected.submitted_at ? (' on ' + selected.submitted_at) : '')"></span>
        </div>
        <p style="font-size:12.5px;color:var(--text-secondary,#6b7280);margin:0 0 10px;">Read-only review — authorise to sign it, or reject it back to the candidate with a note. You cannot edit a submitted evaluation.</p>
        <iframe :src="pdfUrl(selected)" title="Evaluation certificate preview"
                style="width:100%;height:70vh;border:1px solid var(--border,#e3e8f0);border-radius:8px;background:#f8fafc;"></iframe>
        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
          <button class="corex-btn-primary" style="background:#0b7d3b;" @click="openAuth()" :disabled="!savedSigConfigured">Authorise &amp; sign</button>
          <button class="corex-btn-outline" style="border-color:#b91c1c;color:#b91c1c;" @click="rejectOpen=true">Reject</button>
          <span x-show="flash" x-cloak x-text="flash" style="align-self:center;color:#0b7d3b;font-size:13px;"></span>
        </div>
      </div>
    </template>

    <span x-show="!selected && flash" x-cloak x-text="flash" style="color:#0b7d3b;font-size:13px;"></span>
  </div>

  {{-- Authorise PIN modal — fires directly, NO save step --}}
  <div class="ea-modal" x-show="authOpen" x-cloak @keydown.escape.window="authOpen=false">
    <div class="box" @click.stop>
      <h3 class="ea-h">Authorise &amp; sign</h3>
      <p style="font-size:13px;">Enter your signing PIN to authorise and sign this candidate's evaluation. This produces the final, filed certificate.</p>
      <label>Signing PIN</label>
      <input type="password" x-model="pin" @keydown.enter="submitAuth()" autocomplete="off" inputmode="numeric">
      <div x-show="pinError" x-cloak x-text="pinError" style="color:#b91c1c;font-size:12px;margin-top:6px;"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button class="corex-btn-outline" @click="authOpen=false">Cancel</button>
        <button class="corex-btn-primary" style="background:#0b7d3b;" @click="submitAuth()" :disabled="pinLoading || !pin">
          <span x-show="!pinLoading">Authorise &amp; sign</span><span x-show="pinLoading" x-cloak>Signing…</span>
        </button>
      </div>
    </div>
  </div>

  {{-- Reject modal --}}
  <div class="ea-modal" x-show="rejectOpen" x-cloak @keydown.escape.window="rejectOpen=false">
    <div class="box" @click.stop>
      <h3 class="ea-h">Return to the candidate</h3>
      <p style="font-size:13px;">Tell the candidate what to fix. They will see this note and can amend and resubmit.</p>
      <label>Note to candidate</label>
      <textarea x-model="rejectInput" rows="3" placeholder="e.g. Estimated value looks high — please re-check the comparables."></textarea>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;">
        <button class="corex-btn-outline" @click="rejectOpen=false">Cancel</button>
        <button class="corex-btn-primary" style="background:#b91c1c;" @click="submitReject()" :disabled="rejectLoading || !rejectInput.trim()">
          <span x-show="!rejectLoading">Return to candidate</span><span x-show="rejectLoading" x-cloak>Returning…</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function evalAuth() {
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const jhead = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  const U = {
    queue:        @json(route('tools.cma.evaluation.queue')),
    authoriseTpl: @json(route('tools.cma.evaluation.authorise', ['certificate' => '__ID__'])),
    rejectTpl:    @json(route('tools.cma.evaluation.reject',    ['certificate' => '__ID__'])),
    downloadTpl:  @json(route('tools.cma.evaluation.download',  ['certificate' => '__ID__'])),
  };
  const withId = (tpl, id) => tpl.replace('__ID__', id);
  return {
    savedSigConfigured: @json($savedSigConfigured),
    items: [], loading: true, selected: null, flash: '',
    authOpen: false, pin: '', pinError: '', pinLoading: false,
    rejectOpen: false, rejectInput: '', rejectLoading: false,

    init() { this.load(); },
    async load() {
      this.loading = true;
      try {
        const r = await fetch(U.queue, { headers: jhead });
        if (r.ok) { const j = await r.json(); this.items = (j.items || []).filter(x => x.status === 'pending_authorisation'); }
      } catch (e) {} finally { this.loading = false; }
    },
    review(q) { this.selected = q; this.flash = ''; },
    pdfUrl(q) { return withId(U.downloadTpl, q.id) + '?inline=1'; },

    openAuth() { this.pin = ''; this.pinError = ''; this.authOpen = true; },
    async submitAuth() {
      if (!this.pin) return;
      this.pinLoading = true; this.pinError = '';
      try {
        const r = await fetch(withId(U.authoriseTpl, this.selected.id), { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ pin: this.pin }) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { this.pinError = j.message || 'Could not authorise.'; return; }
        this.authOpen = false;
        this.items = this.items.filter(x => x.id !== this.selected.id);
        this.selected = null; this.flash = 'Authorised & signed — filed to the property.';
        setTimeout(() => { this.flash = ''; }, 4000);
      } finally { this.pinLoading = false; }
    },
    async submitReject() {
      if (!this.rejectInput.trim()) return;
      this.rejectLoading = true;
      try {
        const r = await fetch(withId(U.rejectTpl, this.selected.id), { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ note: this.rejectInput }) });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { alert(j.message || 'Could not return the certificate.'); return; }
        this.rejectOpen = false; this.rejectInput = '';
        this.items = this.items.filter(x => x.id !== this.selected.id);
        this.selected = null; this.flash = 'Returned to the candidate.';
        setTimeout(() => { this.flash = ''; }, 4000);
      } finally { this.rejectLoading = false; }
    },
  };
}
</script>
@endsection
