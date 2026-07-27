@extends('layouts.corex')

{{-- Pipeline Dashboard — TIMELINE view, rebuilt to Johan's APPROVED phased/sectioned mockup
     (/tmp/dr2_phased_agreed.html): a VERTICAL, top-to-bottom pipeline —
       Deal Signed ★  →  Stage 1 · Suspensive Conditions (one group per condition, run in parallel)
       →  GRANTED gate (blue bar)  →  Stage 2 · Transfer & Registration (sequence + concurrent bands,
       locked until granted)  →  + Add custom step.
     Deal-context tabs stay on TOP, collapsible, default collapsed, each panel bounded + internally
     scrollable. A Comments footer (All / Deal-level / This step) posts through the step comment route.
     Every step is the shared uniform tile (dr2._pipeline-step-tile) with its full 6-action set. Real
     data (dr1_deal_id steps); QA1 only; a pure overlay — nothing here changes DR1 deal state. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
<style>
  #dr2tl{display:flex;flex-direction:column;height:calc(100vh - 4.2rem);min-height:520px;color:#1e293b;font-size:14px}
  #dr2tl .thead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  #dr2tl .h1{font-size:16px;font-weight:800;color:#0f172a;margin:0}
  #dr2tl .hsub{color:#64748b;font-size:12.5px}
  #dr2tl .toggle{display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:13px}
  #dr2tl .toggle a{padding:6px 14px;color:#374151;text-decoration:none}
  #dr2tl .toggle .on{background:#0f172a;color:#fff;font-weight:700}
  #dr2tl .back{color:#2563eb;font-size:12.5px;text-decoration:none;font-weight:600}
  /* collapsible deal-context tabs — bounded + internally scrollable (Johan D) */
  #dr2tl .ctabs{border:1px solid #e2e8f0;border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden;flex:0 0 auto}
  #dr2tl .ctabs-h{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;user-select:none;font-size:12.5px;color:#64748b;font-weight:600}
  #dr2tl .ctabs-h .chev{transition:transform .18s}
  #dr2tl .ctabs-h.open .chev{transform:rotate(90deg)}
  #dr2tl .ctabs-body{border-top:1px solid #e2e8f0;padding:10px 12px;max-height:min(48vh,460px);overflow-y:auto;overscroll-behavior:contain}
  /* the pipeline scroll region */
  #dr2tl .mid{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;background:#f4f6fa;border:1px solid #e2e8f0;border-radius:14px 14px 0 0;padding:14px 16px 22px}
  #dr2tl .legend{display:flex;flex-wrap:wrap;gap:13px;align-items:center;margin:0 2px 12px;font-size:11px;color:#64748b}
  #dr2tl .legend .dot{display:inline-block;width:9px;height:9px;border-radius:50%;vertical-align:middle;margin-right:3px}
  #dr2tl .legend .r{background:#ef4444}#dr2tl .legend .a{background:#f59e0b}#dr2tl .legend .g{background:#22c55e}#dr2tl .legend .d{background:#16a34a}
  #dr2tl .legend .s{color:#eab308}
  /* footer comments */
  #dr2tl .foot{flex:0 0 200px;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 14px 14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
  #dr2tl .cbar{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}
  #dr2tl .cbar .t{font-weight:800;color:#0f172a;font-size:13px}
  #dr2tl .cscope{font-size:11px;color:#64748b}#dr2tl .cscope b{color:#2563eb}
  #dr2tl .chip{font-size:11px;padding:3px 10px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;font-family:inherit}
  #dr2tl .chip.on{background:#2563eb;color:#fff;border-color:#2563eb;font-weight:600}
  #dr2tl .feed{flex:1;overflow-y:auto;padding:7px 15px}
  #dr2tl .cm{display:flex;gap:9px;padding:6px 0;border-bottom:1px solid #f4f6fa}
  #dr2tl .av{width:24px;height:24px;flex:0 0 24px;border-radius:50%;background:#dbe4ff;color:#4f46e5;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  #dr2tl .cmeta{font-size:11px;color:#64748b}#dr2tl .cmeta b{color:#1e293b}
  #dr2tl .ctag{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:5px;margin-left:5px;background:#eff6ff;color:#2563eb}
  #dr2tl .ctag.deal{background:#eef2ff;color:#4f46e5}
  #dr2tl .ctxt{font-size:12px;color:#1e293b;margin-top:1px;white-space:pre-wrap}
  #dr2tl .cadd{display:flex;gap:8px;padding:8px 15px;border-top:1px solid #e2e8f0;align-items:center;flex-wrap:wrap}
  #dr2tl .cadd select,#dr2tl .cadd input{font-family:inherit;font-size:12px;border:1px solid #e2e8f0;border-radius:7px;padding:6px 9px;color:#1e293b}
  #dr2tl .cadd input{flex:1;min-width:120px}
  #dr2tl .cadd button{font-size:12px;font-weight:600;padding:6px 15px;border:0;border-radius:7px;background:#2563eb;color:#fff;cursor:pointer}
  /* the timeline is action-button driven — no drag here; the grip is for the List view */
  #dr2tl .dr2-tile__grip{display:none}
  #dr2tl-toast{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:9px 15px;border-radius:9px;font-size:12px;opacity:0;transition:opacity .2s;pointer-events:none;z-index:160}
  #dr2tl-toast.on{opacity:1}
</style>

@php($from = 'timeline')
@php($rowById = $steps->keyBy(fn ($r) => (int) $r['model']->id))
<div id="dr2tl" data-comment="{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}" data-csrf="{{ csrf_token() }}">

  <div class="thead">
    <div>
      <div class="h1">Deal Pipeline</div>
      <div class="hsub">
        {{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
        @if($deal->property) — {{ $deal->property->buildDisplayAddress() }} @endif
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <div class="toggle"><span class="on">Timeline</span><a href="{{ route('deals-dr2.pipeline.list', $deal) }}">List</a></div>
      <a class="back" href="{{ route('deals-dr2.index') }}">← DR2 Register</a>
    </div>
  </div>

  {{-- Collapsible deal-context tabs — on TOP, default collapsed, panel bounded + internally scrollable --}}
  <div class="ctabs" x-data="{ open:false }">
    <div class="ctabs-h" :class="open?'open':''" @click="open=!open">
      <span class="chev">▸</span> Deal panels — Structure · Work Orders · Documents · Parties · Proforma
      <span style="margin-left:auto;font-weight:400;color:#94a3b8;" x-text="open?'hide':'show'"></span>
    </div>
    <div class="ctabs-body" x-show="open" x-cloak>@include('dr2._pipeline-context-tabs')</div>
  </div>

  @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('info') }}</div>@endif
  @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('error') }}</div>@endif

  @if($board['empty'])
    <div class="mid"><div style="padding:2rem;text-align:center;color:#64748b;font-size:13px;">
      No pipeline steps yet — build the pipeline from the <strong>Deal panels → Structure</strong> tab above.
    </div></div>
  @else
  <div class="mid">
    <div class="legend">
      <span><span class="dot d"></span>completed</span>
      <span><span class="dot r"></span>overdue / due now</span>
      <span><span class="dot a"></span>due soon</span>
      <span><span class="dot g"></span>on track</span>
      <span><span class="s">★</span> milestone</span>
      <span>🔒 locked</span>
    </div>

    <div class="dr2-ph">
      {{-- ANCHOR --}}
      @if($board['anchor_id'] && $rowById->has($board['anchor_id']))
        <div class="dr2-ph-anchor">
          @include('dr2._pipeline-step-tile', ['row' => $rowById[$board['anchor_id']], 'variant' => 'wide'])
        </div>
      @endif

      @if($board['flat'])
        {{-- Old-model / non-composable deal: a single flat sequence (no gate). --}}
        <div class="dr2-ph-stage">
          <div class="dr2-ph-stage__h"><span class="dr2-ph-stage__t">Pipeline</span></div>
          <div style="margin-top:.6rem">
            @foreach($board['all_ids'] as $sid)
              @if($rowById->has($sid))@include('dr2._pipeline-step-tile', ['row' => $rowById[$sid], 'variant' => 'wide'])@endif
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
              @if($rowById->has($sid))@include('dr2._pipeline-step-tile', ['row' => $rowById[$sid], 'variant' => 'wide'])@endif
            @endforeach
            @if($grp['key'] === 'cash' && $grp['sub'])
              <div class="dr2-ph-note">Proof of funds is what grants the deal. The actual payments are received later, at the deeds office — see Stage 2.</div>
            @endif
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
              @if($board['gate']['granted'])
                Deal is unconditional — every suspensive condition above is met.
              @else
                Deal becomes unconditional once every condition above is met{{ $board['gate']['projected'] ? ' · projected '.$board['gate']['projected'] : '' }}
              @endif
            </div>
          </div>
        </div>
      </div>
      <div class="dr2-ph-arrow">▼</div>

      {{-- STAGE 2 · Transfer & Registration --}}
      @if($board['stage2']['has'])
      <div class="dr2-ph-stage {{ $board['stage2']['active'] ? '' : 'is-locked' }}">
        <div class="dr2-ph-stage__h"><span class="dr2-ph-stage__n">2</span><span class="dr2-ph-stage__t">Transfer &amp; Registration</span></div>
        <div class="dr2-ph-stage__s">Runs once the deal is granted — a single sequence, in date order.</div>
        @unless($board['stage2']['active'])
          <div class="dr2-ph-lock">🔒 Activates once the deal is granted. Shown here as what comes next.</div>
        @endunless
        @include('dr2._pipeline-segments', ['segments' => $board['stage2']['segments'], 'rowById' => $rowById])
      </div>
      @endif
      @endif

      @unless($locked)
      <button type="button" class="dr2-ph-addbtn" @click="$dispatch('dr2-open-structure'); document.querySelector('#dr2tl .ctabs-h:not(.open)')?.click();">+ Add custom step (Deal Structure)</button>
      @endunless
    </div>
  </div>

  {{-- Comments footer — All / Deal-level / This step; posts through the step comment route (redirect) --}}
  <div class="foot">
    <div class="cbar">
      <span class="t">Comments</span>
      <span class="cscope" id="dr2tl-scope">Showing: <b>all</b></span>
      <span class="chip on" data-f="all" onclick="dr2tlFilter('all',this)">All</span>
      <span class="chip" data-f="deal" onclick="dr2tlFilter('deal',this)">Deal-level</span>
      <span class="chip" data-f="step" onclick="dr2tlFilter('step',this)">This step</span>
    </div>
    <div class="feed" id="dr2tl-feed"></div>
    @unless($locked)
    <div class="cadd">
      <select id="dr2tl-target">
        <option value="{{ $board['anchor_id'] }}">General (deal)</option>
        @foreach($steps as $r)<option value="{{ $r['model']->id }}">On: {{ $r['model']->name }}</option>@endforeach
      </select>
      <input id="dr2tl-text" placeholder="Add a comment…" @keydown.enter="dr2tlAddComment()">
      <button onclick="dr2tlAddComment()">Add</button>
    </div>
    @endunless
  </div>
  @endif
  <div id="dr2tl-toast"></div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  const root=document.getElementById('dr2tl'); if(!root) return;
  const COMMENTS=@json($board['comments'] ?? []);
  const ANCHOR={{ $board['anchor_id'] ?? 'null' }};
  const CBASE=root.dataset.comment, CSRF=root.dataset.csrf;
  let filter='all';

  function renderFeed(){
    const feed=document.getElementById('dr2tl-feed'), scope=document.getElementById('dr2tl-scope');
    if(!feed) return;
    const sel=document.getElementById('dr2tl-target');
    const stepTarget=sel?parseInt(sel.value):null;
    let list=COMMENTS.slice();
    if(filter==='deal') list=list.filter(c=>c.scope==='deal'||c.step===ANCHOR);
    else if(filter==='step'&&stepTarget) list=list.filter(c=>c.step===stepTarget);
    scope.innerHTML='Showing: <b>'+(filter==='step'?'this step':filter)+'</b>';
    feed.innerHTML=list.length?'':'<div style="color:#94a3b8;font-size:12px;padding:8px 0">No comments yet.</div>';
    list.slice().reverse().forEach(c=>{
      const deal=(c.scope==='deal'||c.step===ANCHOR);
      const cm=document.createElement('div');cm.className='cm';
      cm.innerHTML='<div class="av">'+((c.who||'?')[0])+'</div><div><div class="cmeta"><b>'+esc(c.who)+'</b> · '+esc(c.when)+
        '<span class="ctag '+(deal?'deal':'')+'">'+(deal?'DEAL':'STEP')+'</span></div><div class="ctxt">'+esc(c.text)+'</div></div>';
      feed.appendChild(cm);
    });
  }
  function esc(s){const d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
  window.dr2tlFilter=function(f,el){filter=f;root.querySelectorAll('.chip').forEach(c=>c.classList.remove('on'));el.classList.add('on');renderFeed();};
  window.dr2tlAddComment=function(){
    const inp=document.getElementById('dr2tl-text'), sel=document.getElementById('dr2tl-target');
    if(!inp||!inp.value.trim())return;
    const stepId=sel.value; if(!stepId){toast('No step to attach the comment to.');return;}
    const f=document.createElement('form');f.method='POST';f.action=CBASE+'/'+stepId+'/comment';f.style.display='none';
    const add=(n,v)=>{const i=document.createElement('input');i.name=n;i.value=v;f.appendChild(i);};
    add('_token',CSRF);add('from','timeline');add('body',inp.value.trim());
    document.body.appendChild(f);f.submit();
  };
  function toast(m){const t=document.getElementById('dr2tl-toast');if(!t)return;t.textContent=m;t.classList.add('on');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('on'),2400);}
  const sel=document.getElementById('dr2tl-target'); if(sel) sel.addEventListener('change',()=>{ if(filter==='step') renderFeed(); });
  renderFeed();
})();
</script>
@endpush
