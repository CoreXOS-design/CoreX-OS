@extends('layouts.corex')

{{-- Pipeline Dashboard — LIST view = the PHASED TWO-PANEL layout (spec "LIST + PROGRESSION build
     2026-07-28", mockup .ai/mockups/dr2_list_phased.html).
     LEFT  = the phased pipeline (Deal Signed anchor → Stage 1 · Suspensive Conditions condition groups →
             GRANTED gate → Stage 2 · Transfer & Registration), each step the shared uniform tile
             (dr2._pipeline-step-tile) with the full action set incl. Sequence = LINK-TO-STEP (follows),
             and grab-to-reorder (DISPLAY position ONLY). Scrolls independently.
     RIGHT = a WIDER panel: the deal panels (Structure / Work Orders / Documents / Email Parties /
             Proforma) MOVED off the top of the page into here, and BELOW them the per-step Comments
             section (each comment shown against its step name — not an anonymous feed).
     Reads buildPhased(); QA1 only; a pure overlay — nothing here changes DR1 deal state. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
<style>
  #dr2ls{display:flex;flex-direction:column;height:calc(100vh - 4.2rem);min-height:520px;color:#1e293b;font-size:14px}
  #dr2ls .thead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  #dr2ls .h1{font-size:16px;font-weight:800;color:#0f172a;margin:0}#dr2ls .hsub{color:#64748b;font-size:12.5px}
  #dr2ls .toggle{display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:13px}
  #dr2ls .toggle a{padding:6px 14px;color:#374151;text-decoration:none}#dr2ls .toggle .on{background:#0f172a;color:#fff;font-weight:700}
  #dr2ls .toggle span.on{padding:6px 14px}
  #dr2ls .back{color:#2563eb;font-size:12.5px;text-decoration:none;font-weight:600}

  /* ── the two-panel body ── */
  #dr2ls .two{flex:1;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) 460px;gap:14px;align-items:stretch}

  /* LEFT — phased pipeline, scrolls on its own */
  #dr2ls .left{min-height:0;overflow-y:auto;overscroll-behavior:contain;background:#f4f6fa;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px 22px}
  #dr2ls .lhint{font-size:11.5px;color:#64748b;margin-bottom:10px}#dr2ls .lhint b{font-weight:800}

  /* Compact per-step action buttons on the LEFT list — small inline coloured pills like the mockup
     (.ai/mockups/dr2_list_phased.html), overriding the shared tile's bulky full-width 3×2 grid so each
     step row is far more condensed. Scoped to #dr2ls .left ONLY — the Timeline/board tile treatment is
     untouched. Keeps every action + its colour (Complete green, Remove red). !important is required to
     beat the global .hfc-card button[type=submit] rule (also !important) so the SUBMIT actions
     (Reopen/Remove/Reinstate) stay compact pills too, not big filled buttons; the id-scoped selector
     is more specific so it wins. */
  #dr2ls .left .dr2-tile__btns{display:flex;flex-wrap:wrap;gap:5px;margin-top:.4rem;padding-top:.4rem;border-top:1px solid #eef2f7}
  #dr2ls .left .dr2-tile__btns form{display:inline;width:auto;margin:0}
  #dr2ls .left .dr2-bt{display:inline-block!important;width:auto!important;height:auto!important;min-height:0!important;padding:2px 9px!important;font-size:11px!important;line-height:1.55!important;border-radius:6px!important;border:1px solid #e5e7eb!important;background:#fff!important;color:#374151!important;box-shadow:none!important;white-space:nowrap;overflow:visible;text-overflow:clip}
  #dr2ls .left .dr2-bt:hover{background:#f1f5f9!important}
  #dr2ls .left .dr2-bt--go{color:#047857!important;border-color:#6ee7b7!important;background:#ecfdf5!important}
  #dr2ls .left .dr2-bt--danger{color:#b91c1c!important;border-color:#fecaca!important;background:#fff!important}
  #dr2ls .left .dr2-bt--dis{color:#c7cdd6!important;border-color:#eef2f7!important;background:#fafbfc!important;cursor:not-allowed}

  /* Compact each LEFT-list step ROW to ~2 lines. The shared tile stacks head / tags / meta / sub as
     separate BLOCKS (≈6 lines of wasted vertical space); flatten them onto ONE flex row here so
     line 1 = grip · dot · name · offset(+Nd) · ★ · date … status badge (far right), and line 2 = the
     action pills. Scoped to #dr2ls .left ONLY — the shared partial markup and the Timeline tiles are
     untouched. Grip, status dot, and every action/behaviour are kept; button STYLING is not changed. */
  #dr2ls .left .dr2-tile{display:flex;flex-direction:row;flex-wrap:wrap;align-items:center;column-gap:8px;row-gap:2px;height:auto;min-height:0}
  #dr2ls .left .dr2-tile__head{flex:0 1 auto;min-width:0;align-items:center}
  #dr2ls .left .dr2-tile__rag{margin-top:0}
  #dr2ls .left .dr2-tile__name{line-height:1.2}
  #dr2ls .left .dr2-tile__tags{flex:0 0 auto;margin:0;min-height:0;align-items:center}
  #dr2ls .left .dr2-tile__meta{flex:1 1 120px;min-width:100px;margin:0}
  /* "Waiting on X" / warn / gate notes: keep them, but as one small muted line tucked under the name —
     never a whole tall line. */
  #dr2ls .left .dr2-tile__sub,#dr2ls .left .dr2-tile__warnnote,#dr2ls .left .dr2-tile__gatenote{flex:1 0 100%;order:9;margin:0 0 0 20px;line-height:1.2}
  #dr2ls .left .dr2-tile__btns{flex:1 0 100%;order:10;margin-top:3px}

  /* RIGHT — wider rail: deal panels on TOP, comments BELOW */
  /* RIGHT — a SINGLE tabbed pane (deal panels + a Comments tab). One tab at a time, full-height,
     nothing stacked. */
  #dr2ls .right{min-height:0;display:flex;flex-direction:column}
  #dr2ls .rpane{flex:1;min-height:0;display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
  #dr2ls .rtabs{flex:0 0 auto;display:flex;flex-wrap:wrap;gap:3px;padding:8px 10px 0;border-bottom:1px solid #e2e8f0}
  #dr2ls .rtabs .dr2-tab{font-size:11px;padding:6px 9px}
  #dr2ls .rbody{flex:1;min-height:0;display:flex;flex-direction:column}
  #dr2ls .rp{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding:14px}
  #dr2ls .rp--comments{padding:0;overflow:hidden;display:flex;flex-direction:column}
  #dr2ls .cbar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}
  #dr2ls .cbar .t{font-weight:800;color:#0f172a;font-size:13px}#dr2ls .cbar .n{font-size:11px;color:#64748b}
  #dr2ls .feed{flex:1;overflow-y:auto;padding:8px 14px}
  #dr2ls .cm{display:flex;gap:9px;padding:7px 0;border-bottom:1px solid #f4f6fa}
  #dr2ls .av{width:26px;height:26px;flex:0 0 26px;border-radius:50%;background:#dbe4ff;color:#4f46e5;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  #dr2ls .cm__b{min-width:0}
  #dr2ls .cmeta{font-size:11px;color:#64748b}#dr2ls .cmeta b{color:#1e293b}
  #dr2ls .ctag{font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:5px;margin-left:5px;background:#eff6ff;color:#2563eb;white-space:nowrap}
  #dr2ls .ctag.deal{background:#eef2ff;color:#4f46e5}
  #dr2ls .ctxt{font-size:12.5px;color:#1e293b;margin-top:1px;white-space:pre-wrap;word-break:break-word}
  #dr2ls .cadd{display:flex;gap:8px;padding:9px 14px;border-top:1px solid #e2e8f0;align-items:center;flex-wrap:wrap}
  #dr2ls .cadd select,#dr2ls .cadd input{font-family:inherit;font-size:12px;border:1px solid #e2e8f0;border-radius:7px;padding:6px 9px;color:#1e293b}
  #dr2ls .cadd select{max-width:150px}#dr2ls .cadd input{flex:1;min-width:120px}
  #dr2ls .cadd button{font-size:12px;font-weight:600;padding:6px 15px;border:0;border-radius:7px;background:#2563eb;color:#fff;cursor:pointer}
  /* Comments tab — sort control + header-line (step badge · date · who) */
  #dr2ls .csort{margin-left:auto;font-size:11px;color:#64748b;display:inline-flex;align-items:center;gap:5px}
  #dr2ls .csort select{font-family:inherit;font-size:11px;border:1px solid #e2e8f0;border-radius:6px;padding:3px 6px;color:#1e293b}
  #dr2ls .rp--comments .ctag{margin-left:0}
  #dr2ls .rp--comments .cmeta{display:flex;flex-wrap:wrap;align-items:center;gap:4px}

  @media(max-width:1100px){#dr2ls{height:auto}#dr2ls .two{grid-template-columns:1fr}#dr2ls .left{max-height:none}#dr2ls .rpane{min-height:480px}}
</style>

@php($from = 'list')
@php($rowById = $steps->keyBy(fn ($r) => (int) $r['model']->id))
@php($seq = collect($board['stage2']['segments'] ?? [])->flatMap(fn ($seg) => ($seg['type'] ?? null) === 'sequence' ? [(int) $seg['step']->id] : collect($seg['lanes'] ?? [])->flatMap(fn ($lane) => collect($lane)->map(fn ($m) => (int) $m->id)))->all())
@php($stepLabel = function ($id) use ($rowById, $board) {
      if ($id === null) return 'Deal';
      if ((int) $id === (int) ($board['anchor_id'] ?? 0)) return 'Deal';
      return $rowById->has((int) $id) ? $rowById[(int) $id]['model']->name : 'Deal';
    })

<div id="dr2ls" data-reorder="{{ route('deals-dr2.pipeline.reorder', $deal) }}" data-comment="{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}" data-csrf="{{ csrf_token() }}">

  <div class="thead">
    <div>
      <div class="h1">Deal Pipeline</div>
      <div class="hsub">{{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
        @if($deal->property) — {{ $deal->property->buildDisplayAddress() }} @endif</div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <div class="toggle"><a href="{{ route('deals-dr2.pipeline.timeline', $deal) }}">Timeline</a><span class="on">List</span></div>
      <a class="back" href="{{ route('deals-dr2.index') }}">← DR2 Register</a>
    </div>
  </div>

  @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('info') }}</div>@endif
  @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('error') }}</div>@endif

  <div class="two">
    {{-- ══════════ LEFT · PHASED PIPELINE ══════════ --}}
    <div class="left dr2-listwrap" id="dr2ls-list">
      @if($board['empty'])
        <div style="padding:1.5rem;text-align:center;color:#64748b;font-size:13px;">No pipeline steps yet — build from the <strong>Deal Structure</strong> panel on the right.</div>
      @else
        @unless($locked)<div class="lhint">Grab a card's <b>⠿</b> to reorder (display only — never changes dependencies or dates). Use <b>Sequence</b> to change which step a step follows.</div>@endunless

        <div class="dr2-ph">
          {{-- ANCHOR --}}
          @if($board['anchor_id'] && $rowById->has($board['anchor_id']))
            <div class="dr2-ph-anchor"><div class="dr2-lrow" data-id="{{ $board['anchor_id'] }}">@include('dr2._pipeline-step-tile', ['row' => $rowById[$board['anchor_id']], 'variant' => 'wide'])</div></div>
          @endif

          @if($board['flat'])
            <div class="dr2-ph-stage">
              <div class="dr2-ph-stage__h"><span class="dr2-ph-stage__t">Pipeline</span></div>
              <div style="margin-top:.6rem">
                @foreach($board['all_ids'] as $sid)
                  @if($rowById->has($sid))<div class="dr2-lrow" data-id="{{ $sid }}">@include('dr2._pipeline-step-tile', ['row' => $rowById[$sid], 'variant' => 'wide'])</div>@endif
                @endforeach
              </div>
            </div>
          @else
            {{-- STAGE 1 · Suspensive Conditions --}}
            @if(!empty($board['stage1']['groups']))
            <div class="dr2-ph-arrow">▼</div>
            <div class="dr2-ph-stage">
              <div class="dr2-ph-stage__h"><span class="dr2-ph-stage__n">1</span><span class="dr2-ph-stage__t">Suspensive Conditions</span></div>
              <div class="dr2-ph-stage__s">All of these must be met for the deal to be granted. They run in parallel.</div>
              @foreach($board['stage1']['groups'] as $grp)
                <div class="dr2-ph-grp">
                  <div class="dr2-ph-grp__h">
                    <span class="dr2-ph-grp__ic">{{ $grp['icon'] }}</span>
                    <span>{{ $grp['label'] }}@if($grp['sub'])<span class="dr2-ph-grp__sub"> · {{ $grp['sub'] }}</span>@endif</span>
                    <span class="dr2-ph-pill {{ $grp['active'] ? 'dr2-ph-pill--active' : 'dr2-ph-pill--done' }}">{{ $grp['active'] ? 'ACTIVE' : 'DONE' }}</span>
                  </div>
                  @foreach($grp['step_ids'] as $sid)
                    @if($rowById->has($sid))<div class="dr2-lrow" data-id="{{ $sid }}">@include('dr2._pipeline-step-tile', ['row' => $rowById[$sid], 'variant' => 'wide'])</div>@endif
                  @endforeach
                </div>
              @endforeach
            </div>
            @endif

            {{-- GRANTED gate --}}
            <div class="dr2-ph-arrow">▼</div>
            <div class="dr2-ph-gate {{ $board['gate']['granted'] ? '' : 'dr2-ph-gate--pending' }}">
              <div class="dr2-ph-gate__inner">
                <span class="dr2-ph-gate__star">★</span>
                <div>
                  <div class="dr2-ph-gate__t">{{ $board['gate']['granted'] ? 'GRANTED' : 'GRANTED — pending' }}</div>
                  <div class="dr2-ph-gate__s">
                    @if($board['gate']['granted'])Deal is unconditional — every suspensive condition above is met.
                    @else Deal becomes unconditional once every condition above is met{{ $board['gate']['projected'] ? ' · projected '.$board['gate']['projected'] : '' }}@endif
                  </div>
                </div>
              </div>
            </div>
            <div class="dr2-ph-arrow">▼</div>

            {{-- STAGE 2 · Transfer & Registration --}}
            @if(!empty($seq))
            <div class="dr2-ph-stage {{ $board['stage2']['active'] ? '' : 'is-locked' }}">
              <div class="dr2-ph-stage__h"><span class="dr2-ph-stage__n">2</span><span class="dr2-ph-stage__t">Transfer &amp; Registration</span></div>
              <div class="dr2-ph-stage__s">Runs once the deal is granted — a single sequence, in date order.</div>
              @unless($board['stage2']['active'])<div class="dr2-ph-lock">🔒 Activates once the deal is granted. Shown here as what comes next.</div>@endunless
              @foreach($seq as $sid)
                @if($rowById->has($sid))<div class="dr2-lrow" data-id="{{ $sid }}">@include('dr2._pipeline-step-tile', ['row' => $rowById[$sid], 'variant' => 'wide'])</div>@endif
              @endforeach
            </div>
            @endif
          @endif
        </div>
      @endif
    </div>

    {{-- ══════════ RIGHT · ONE tabbed pane — the deal panels + a Comments tab (nothing stacked) ══════════
         DEFAULT TAB: the deal's structure is "captured" once its pipeline is built — the exact signal the
         Deal Structure panel itself uses (`_deal-structure` shows the built conditions when $hasPipeline,
         else the capture form). So default to Structure until captured, then to Comments. localStorage
         then remembers the user's own switches per deal so we never force them back. --}}
    @php($woAtt = ($woNeedsAttention ?? false))
    @php($tabKey = 'dr2_list_tab_'.$deal->id)
    @php($defaultTab = $hasPipeline ? 'comments' : 'structure')
    <div class="right"
         x-data="{ tab: (localStorage.getItem('{{ $tabKey }}') || '{{ $defaultTab }}'), set(t){ this.tab = t; localStorage.setItem('{{ $tabKey }}', t); } }">
      <div class="rpane">
        <div class="rtabs" role="tablist" aria-label="Deal panels">
          <button type="button" class="dr2-tab" :class="tab==='structure'?'corex-tab-active':''" @click="set('structure')" role="tab" :aria-selected="tab==='structure'">Deal Structure</button>
          <button type="button" class="dr2-tab" :class="tab==='wo'?'corex-tab-active':''" @click="set('wo')" role="tab" :aria-selected="tab==='wo'" style="{{ $woAtt ? 'color:#b91c1c;font-weight:700;' : '' }}" title="{{ $woAtt ? 'A work order is waiting for a supplier' : '' }}">Supplier Work Orders{!! $woAtt ? ' <span aria-hidden=&quot;true&quot; style=&quot;color:#dc2626&quot;>&#9679;</span>' : '' !!}</button>
          <button type="button" class="dr2-tab" :class="tab==='docs'?'corex-tab-active':''" @click="set('docs')" role="tab" :aria-selected="tab==='docs'">Documents</button>
          <button type="button" class="dr2-tab" :class="tab==='email'?'corex-tab-active':''" @click="set('email')" role="tab" :aria-selected="tab==='email'">Email Parties</button>
          <button type="button" class="dr2-tab" :class="tab==='pi'?'corex-tab-active':''" @click="set('pi')" role="tab" :aria-selected="tab==='pi'">Proforma Invoice</button>
          <button type="button" class="dr2-tab" :class="tab==='comments'?'corex-tab-active':''" @click="set('comments')" role="tab" :aria-selected="tab==='comments'">Comments{{ count($board['comments'] ?? []) ? ' ('.count($board['comments']).')' : '' }}</button>
        </div>

        <div class="rbody">
          <div class="rp" x-show="tab==='structure'" x-cloak role="tabpanel">@include('dr2._deal-structure', ['deal' => $deal, 'conditionCatalog' => $conditionCatalog, 'dealConditions' => $dealConditions, 'hasPipeline' => $hasPipeline, 'locked' => $locked])</div>
          <div class="rp" x-show="tab==='wo'" x-cloak role="tabpanel">@include('dr2._supplier-work-orders', ['deal' => $deal, 'steps' => $steps, 'locked' => $locked])</div>
          <div class="rp" x-show="tab==='docs'" x-cloak role="tabpanel">@include('dr2._deal-documents', ['deal' => $deal])</div>
          <div class="rp" x-show="tab==='email'" x-cloak role="tabpanel">@include('dr2._email-parties', ['deal' => $deal])</div>
          <div class="rp" x-show="tab==='pi'" x-cloak role="tabpanel">@include('proforma._deal-section', ['deal' => $deal])</div>

          {{-- Comments tab — per-step comments. Each comment is a HEADER line (step badge · date · who)
               with the text on the line below, and a Sort control above the feed. The source feed is
               chronological ascending (PipelineEventService::eventsForDeal), so `idx` is a reliable date
               key: newest-first = idx desc. Per-step tagging + the add-comment box are kept. --}}
          @php($commentsJs = collect($board['comments'] ?? [])->values()->map(function ($c, $i) use ($stepLabel, $board) {
                $isDeal = ($c['scope'] ?? '') === 'deal' || ($c['step'] ?? null) === ($board['anchor_id'] ?? null);
                return [
                    'idx'    => $i,
                    'step'   => $isDeal ? 'Deal' : $stepLabel($c['step'] ?? null),
                    'isDeal' => $isDeal,
                    'who'    => (string) ($c['who'] ?? ''),
                    'when'   => (string) ($c['when'] ?? ''),
                    'text'   => (string) ($c['text'] ?? ''),
                    'type'   => (string) ($c['type'] ?? ''),
                ];
              })->all())
          @php($hasTypes = collect($commentsJs)->pluck('type')->filter()->unique()->count() > 1)
          <div class="rp rp--comments" x-show="tab==='comments'" x-cloak role="tabpanel"
               x-data="{ sort:'date', comments: {{ \Illuminate\Support\Js::from($commentsJs) }},
                         sorted(){ const a=[...this.comments], s=this.sort, d=(x,y)=>y.idx-x.idx;
                           if(s==='step') a.sort((x,y)=> (x.step||'').localeCompare(y.step||'') || d(x,y));
                           else if(s==='user') a.sort((x,y)=> (x.who||'').localeCompare(y.who||'') || d(x,y));
                           else if(s==='type') a.sort((x,y)=> (x.type||'').localeCompare(y.type||'') || d(x,y));
                           else a.sort(d);
                           return a; } }">
            <div class="cbar">
              <span class="t">Comments</span>
              <span class="n">{{ count($board['comments'] ?? []) }} on this deal · shown against their step</span>
              <label class="csort">Sort
                <select x-model="sort">
                  <option value="date">Date (newest)</option>
                  <option value="step">Step</option>
                  <option value="user">User</option>
                  @if($hasTypes)<option value="type">Type</option>@endif
                </select>
              </label>
            </div>
            <div class="feed">
              <template x-if="comments.length===0">
                <div style="color:#94a3b8;font-size:12px;padding:10px 0;">No comments yet. Use a step's <b>Comments</b> action (left), or add one below.</div>
              </template>
              <template x-for="c in sorted()" :key="c.idx">
                <div class="cm">
                  <div class="av" x-text="(c.who||'?').charAt(0).toUpperCase()"></div>
                  <div class="cm__b">
                    <div class="cmeta"><span class="ctag" :class="c.isDeal ? 'deal' : ''" x-text="c.step"></span> · <span x-text="c.when"></span> · <b x-text="c.who"></b></div>
                    <div class="ctxt" x-text="c.text"></div>
                  </div>
                </div>
              </template>
            </div>
            @unless($locked || $board['empty'])
            <div class="cadd">
              <select id="dr2ls-target">
                <option value="{{ $board['anchor_id'] }}">General (deal)</option>
                @foreach($steps as $r)<option value="{{ $r['model']->id }}">On: {{ $r['model']->name }}</option>@endforeach
              </select>
              <input id="dr2ls-text" placeholder="Add a comment…">
              <button onclick="dr2lsAddComment()">Add</button>
            </div>
            @endunless
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  const list=document.getElementById('dr2ls-list'); if(!list) return;
  const root=document.getElementById('dr2ls');
  const url=root.dataset.reorder, token=root.dataset.csrf, cbase=root.dataset.comment; let dragEl=null;
  const rows=()=>Array.prototype.slice.call(list.querySelectorAll('.dr2-lrow'));

  // Add-comment — posts through the step comment route (redirect). General (deal) targets the anchor.
  window.dr2lsAddComment=function(){
    const inp=document.getElementById('dr2ls-text'), sel=document.getElementById('dr2ls-target');
    if(!inp||!inp.value.trim())return; const stepId=sel.value; if(!stepId)return;
    const f=document.createElement('form');f.method='POST';f.action=cbase+'/'+stepId+'/comment';f.style.display='none';
    const add=(n,v)=>{const i=document.createElement('input');i.name=n;i.value=v;f.appendChild(i);};
    add('_token',token);add('from','list');add('body',inp.value.trim());
    document.body.appendChild(f);f.submit();
  };
  const t=document.getElementById('dr2ls-text'); if(t) t.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();window.dr2lsAddComment();}});

  // Grab-to-reorder (position ONLY) — the grip lives inside each tile.
  function persist(){const order=rows().map(r=>parseInt(r.dataset.id)).filter(n=>!isNaN(n));
    fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},credentials:'same-origin',body:JSON.stringify({order})})
      .then(r=>r.json()).then(j=>{if(!j.ok)alert(j.error||'Could not save order.');window.location.reload();}).catch(()=>window.location.reload());}
  list.querySelectorAll('.dr2-lrow').forEach(row=>{
    const grip=row.querySelector('.dr2-tile__grip'); if(!grip) return;
    grip.style.display='inline'; grip.setAttribute('draggable','true');
    grip.addEventListener('dragstart',e=>{dragEl=row;row.classList.add('dr2-dragging');e.dataTransfer.effectAllowed='move';});
    grip.addEventListener('dragend',()=>{if(!dragEl)return;dragEl.classList.remove('dr2-dragging');rows().forEach(r=>r.classList.remove('dr2-drag-over'));dragEl=null;persist();});
  });
  list.addEventListener('dragover',e=>{if(!dragEl)return;e.preventDefault();const over=e.target.closest('.dr2-lrow');if(!over||over===dragEl)return;
    rows().forEach(r=>r.classList.remove('dr2-drag-over'));over.classList.add('dr2-drag-over');
    const rc=over.getBoundingClientRect();const after=(e.clientY-rc.top)>rc.height/2;over.parentNode.insertBefore(dragEl,after?over.nextSibling:over);});
  list.addEventListener('drop',e=>e.preventDefault());
})();
</script>
@endpush
