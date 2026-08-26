@extends('layouts.corex')

{{-- Candidate "My Evaluations" screen (cc1) — mirrors the Pending Authorisations page.
     A LIST of the candidate's own submitted evaluations → OPEN → a READ-ONLY view of the
     finished certificate (Download / Print / Share, and Edit & resubmit when returned).
     Viewing a submitted cert never lands in the editable create/edit builder. --}}

@section('corex-content')
<style>
  #eval-mine [x-cloak]{display:none!important;}
  #eval-mine .em-card{background:var(--surface,#fff);border:1px solid var(--border,#e3e8f0);border-radius:12px;padding:20px;}
  #eval-mine .em-h{font-size:18px;font-weight:700;color:var(--text-primary,#14315a);margin:0 0 14px;}
  #eval-mine table{width:100%;border-collapse:collapse;}
  #eval-mine thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary,#6b7280);padding:8px 10px;border-bottom:1px solid var(--border,#e3e8f0);}
  #eval-mine tbody td{padding:10px;border-bottom:1px solid var(--border,#eef2f7);font-size:13px;color:var(--text-primary,#1f2430);vertical-align:middle;}
  #eval-mine .em-badge{display:inline-block;color:#fff;border-radius:999px;font-size:11px;font-weight:700;padding:2px 10px;}
  #eval-mine .em-note{background:#fef2f2;color:#991b1b;border-radius:8px;padding:10px 12px;font-size:13px;margin:10px 0;}
</style>

<div id="eval-mine" class="w-full" x-data="evalMine()" x-init="init()">
  <div class="em-card">
    <h1 class="em-h">My Evaluations</h1>

    {{-- LIST — the candidate's submitted evaluations, scalable to many --}}
    <template x-if="!selected">
      <div>
        <div style="margin-bottom:12px;">
          <a href="{{ route('tools.cma') }}" class="corex-btn-primary">+ New evaluation</a>
        </div>
        <template x-if="!loading && !items.length">
          <p style="color:var(--text-secondary,#6b7280);font-size:14px;">You have not submitted any evaluations yet.
            <a href="{{ route('tools.cma') }}" style="text-decoration:underline;">Create one</a>.</p>
        </template>
        <template x-if="items.length">
          <div style="overflow-x:auto;">
            <table>
              <thead><tr><th>Property</th><th>Value</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <template x-for="q in items" :key="q.id">
                  <tr>
                    <td x-text="q.address"></td>
                    <td x-text="q.estimated_market_value ? ('R ' + Number(q.estimated_market_value).toLocaleString('en-ZA')) : '—'"></td>
                    <td x-text="q.submitted_at || '—'"></td>
                    <td><span class="em-badge" x-text="statusLabel(q.status)" :style="'background:' + statusColour(q.status)"></span></td>
                    <td style="text-align:right;">
                      <button class="corex-btn-primary" @click="open(q)" x-text="q.status === 'rejected' ? 'Fix &amp; resubmit' : 'Open'"></button>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </template>

    {{-- READ-ONLY view — the finished certificate as it will be shared --}}
    <template x-if="selected">
      <div>
        <button class="corex-btn-outline" @click="selected=null" style="margin-bottom:12px;">&larr; Back to my evaluations</button>
        <div style="margin-bottom:8px;">
          <span style="font-weight:700;color:var(--text-primary,#14315a);" x-text="selected.address"></span>
          <span style="color:var(--text-secondary,#6b7280);"
                x-text="selected.estimated_market_value ? (' — R ' + Number(selected.estimated_market_value).toLocaleString('en-ZA')) : ''"></span>
          <span class="em-badge" x-text="statusLabel(selected.status)" :style="'margin-left:8px;background:' + statusColour(selected.status)"></span>
        </div>

        {{-- Returned to the candidate: show the note + let them edit & resubmit --}}
        <template x-if="selected.status === 'rejected'">
          <div>
            <div class="em-note"><b>Returned for changes:</b> <span x-text="selected.reject_note || ''"></span></div>
            <a :href="'{{ route('tools.cma') }}?edit=' + selected.id" class="corex-btn-primary">Edit &amp; resubmit</a>
          </div>
        </template>

        <template x-if="selected.status !== 'rejected'">
          <div>
            <p style="font-size:12.5px;color:var(--text-secondary,#6b7280);margin:0 0 10px;"
               x-text="selected.status === 'authorised' ? 'This certificate has been authorised — download, print or share it.' : 'Awaiting authorisation by a full-status practitioner. You can download or print a copy.'"></p>
            <iframe :src="pdfUrl(selected)" title="Evaluation certificate"
                    style="width:100%;height:70vh;border:1px solid var(--border,#e3e8f0);border-radius:8px;background:#f8fafc;"></iframe>
            <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
              <button class="corex-btn-outline" @click="download(selected)">Download</button>
              <button class="corex-btn-outline" @click="print(selected)">Print</button>
              <button class="corex-btn-outline" x-show="selected.status === 'authorised'" @click="share(selected)">Share</button>
              <span x-show="flash" x-cloak x-text="flash" style="align-self:center;color:#0b7d3b;font-size:13px;"></span>
            </div>
          </div>
        </template>
      </div>
    </template>

    <span x-show="!selected && flash" x-cloak x-text="flash" style="color:#0b7d3b;font-size:13px;"></span>
  </div>

  {{-- Share — WhatsApp did-you-send confirm (same shared modal Core Matches / the builder use). --}}
  @include('partials.whatsapp-send-confirm-modal')
</div>

<script>
function evalMine() {
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const jhead = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
  const U = {
    queue:        @json(route('tools.cma.evaluation.queue')),
    downloadTpl:  @json(route('tools.cma.evaluation.download',  ['certificate' => '__ID__'])),
    shareMetaTpl: @json(route('tools.cma.evaluation.share-meta', ['certificate' => '__ID__'])),
  };
  const withId = (tpl, id) => tpl.replace('__ID__', id);
  return {
    items: [], loading: true, selected: null, flash: '',
    sentConfirm: { open: false, communicationId: null }, markSentBase: '',

    init() { this.load(); },
    async load() {
      this.loading = true;
      try {
        const r = await fetch(U.queue, { headers: jhead });
        if (r.ok) { const j = await r.json(); this.items = (j.role === 'candidate') ? (j.items || []) : []; }
      } catch (e) {} finally { this.loading = false; }
    },
    statusLabel(s) { return ({ pending_authorisation: 'Pending authorisation', authorised: 'Authorised', rejected: 'Returned', draft: 'Draft' })[s] || s; },
    statusColour(s) { return ({ pending_authorisation: '#b45309', authorised: '#0b7d3b', rejected: '#b91c1c', draft: '#64748b' })[s] || '#64748b'; },

    open(q) { this.selected = q; this.flash = ''; },
    pdfUrl(q) { return withId(U.downloadTpl, q.id) + '?inline=1'; },
    download(q) { window.open(withId(U.downloadTpl, q.id), '_blank'); },
    print(q) { window.open(withId(U.downloadTpl, q.id) + '?inline=1', '_blank'); },

    // Share to the linked client via WhatsApp — did-you-send model (same as the builder).
    async share(q) {
      const r = await fetch(withId(U.shareMetaTpl, q.id), { headers: jhead });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) { alert(j.message || 'Could not prepare the share.'); return; }
      if (!j.wa_phone) { alert('This contact has no WhatsApp number.'); return; }
      window.open('https://wa.me/' + j.wa_phone + '?text=' + encodeURIComponent(j.message), '_blank', 'noopener');
      try {
        const ir = await fetch(j.increment_url, { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ channel: 'whatsapp', body: j.message }) });
        const idata = await ir.json().catch(() => ({}));
        if (idata && idata.communication_id) { this.markSentBase = j.mark_sent_base; this.sentConfirm = { open: true, communicationId: idata.communication_id }; }
      } catch (e) {}
    },
    async confirmSent(didSend) {
      const commId = this.sentConfirm.communicationId;
      this.sentConfirm.open = false;
      if (!commId || !didSend) return;
      try {
        await fetch(this.markSentBase + '/' + commId + '/mark-sent', { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: '{}' });
      } catch (e) {}
    },
  };
}
</script>
@endsection
