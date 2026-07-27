@extends('layouts.corex')

{{-- Pipeline Dashboard — LIST view, rebuilt to the SAME phased sections as the Timeline: full-width
     vertical kanban cards (the shared uniform tile) grouped under Deal Signed → Stage 1 · Suspensive
     Conditions (condition groups) → GRANTED gate → Stage 2 · Transfer & Registration, with
     grab-to-reorder (DISPLAY position ONLY — never rewires dependencies or dates). Collapsible
     deal-context tabs on top (bounded + internally scrollable) + the same Comments footer. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
<style>
  #dr2ls{display:flex;flex-direction:column;height:calc(100vh - 4.2rem);min-height:520px;color:#1e293b;font-size:14px}
  #dr2ls .thead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  #dr2ls .h1{font-size:16px;font-weight:800;color:#0f172a;margin:0}#dr2ls .hsub{color:#64748b;font-size:12.5px}
  #dr2ls .toggle{display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:13px}
  #dr2ls .toggle a{padding:6px 14px;color:#374151;text-decoration:none}#dr2ls .toggle .on{background:#0f172a;color:#fff;font-weight:700}
  #dr2ls .back{color:#2563eb;font-size:12.5px;text-decoration:none;font-weight:600}
  #dr2ls .ctabs{border:1px solid #e2e8f0;border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden;flex:0 0 auto}
  #dr2ls .ctabs-h{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;user-select:none;font-size:12.5px;color:#64748b;font-weight:600}
  #dr2ls .ctabs-h .chev{transition:transform .18s}#dr2ls .ctabs-h.open .chev{transform:rotate(90deg)}
  #dr2ls .ctabs-body{border-top:1px solid #e2e8f0;padding:10px 12px;max-height:min(48vh,460px);overflow-y:auto;overscroll-behavior:contain}
  #dr2ls .mid{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;background:#f4f6fa;border:1px solid #e2e8f0;border-radius:14px 14px 0 0;padding:14px 16px 22px}
  #dr2ls .lhint{font-size:11.5px;color:#64748b;margin-bottom:8px}#dr2ls .lhint b{font-weight:800}
  #dr2ls .foot{flex:0 0 190px;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 14px 14px;display:flex;flex-direction:column;overflow:hidden}
  #dr2ls .cbar{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}
  #dr2ls .cbar .t{font-weight:800;color:#0f172a;font-size:13px}
  #dr2ls .feed{flex:1;overflow-y:auto;padding:7px 15px}
  #dr2ls .cm{display:flex;gap:9px;padding:6px 0;border-bottom:1px solid #f4f6fa}
  #dr2ls .av{width:24px;height:24px;flex:0 0 24px;border-radius:50%;background:#dbe4ff;color:#4f46e5;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  #dr2ls .cmeta{font-size:11px;color:#64748b}#dr2ls .cmeta b{color:#1e293b}
  #dr2ls .ctag{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:5px;margin-left:5px;background:#eff6ff;color:#2563eb}
  #dr2ls .ctxt{font-size:12px;color:#1e293b;margin-top:1px;white-space:pre-wrap}
  #dr2ls .cadd{display:flex;gap:8px;padding:8px 15px;border-top:1px solid #e2e8f0;align-items:center;flex-wrap:wrap}
  #dr2ls .cadd select,#dr2ls .cadd input{font-family:inherit;font-size:12px;border:1px solid #e2e8f0;border-radius:7px;padding:6px 9px}
  #dr2ls .cadd input{flex:1;min-width:120px}#dr2ls .cadd button{font-size:12px;font-weight:600;padding:6px 15px;border:0;border-radius:7px;background:#2563eb;color:#fff;cursor:pointer}
</style>

@php($from = 'list')
@php($rowById = $steps->keyBy(fn ($r) => (int) $r['model']->id))
@php($seq = collect($board['stage2']['segments'] ?? [])->flatMap(fn ($seg) => ($seg['type'] ?? null) === 'sequence' ? [(int) $seg['step']->id] : collect($seg['lanes'] ?? [])->flatMap(fn ($lane) => collect($lane)->map(fn ($m) => (int) $m->id)))->all())

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

  <div class="ctabs" x-data="{ open:false }">
    <div class="ctabs-h" :class="open?'open':''" @click="open=!open">
      <span class="chev">▸</span> Deal panels — Structure · Work Orders · Documents · Parties · Proforma
      <span style="margin-left:auto;font-weight:400;color:#94a3b8;" x-text="open?'hide':'show'"></span>
    </div>
    <div class="ctabs-body" x-show="open" x-cloak>@include('dr2._pipeline-context-tabs')</div>
  </div>

  @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('info') }}</div>@endif
  @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('error') }}</div>@endif

  <div class="mid dr2-listwrap" id="dr2ls-list">
    @if($board['empty'])
      <div style="padding:1.5rem;text-align:center;color:#64748b;font-size:13px;">No pipeline steps yet — build from the <strong>Deal panels → Structure</strong> tab above.</div>
    @else
      @unless($locked)<div class="lhint">Grab a card's <b>⠿</b> to reorder (display only — never changes dependencies or dates).</div>@endunless

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
          {{-- STAGE 1 --}}
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
                  @else Deal becomes unconditional once every condition above is met@if($board['gate']['projected']) · projected {{ $board['gate']['projected'] }}@endif @endif
                </div>
              </div>
            </div>
          </div>
          <div class="dr2-ph-arrow">▼</div>

          {{-- STAGE 2 --}}
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

  {{-- Comments footer --}}
  <div class="foot">
    <div class="cbar"><span class="t">Comments</span><span style="font-size:11px;color:#64748b;">{{ count($board['comments'] ?? []) }} on this deal</span></div>
    <div class="feed">
      @forelse(array_reverse($board['comments'] ?? []) as $c)
        <div class="cm"><div class="av">{{ substr($c['who'],0,1) }}</div><div>
          <div class="cmeta"><b>{{ $c['who'] }}</b> · {{ $c['when'] }}<span class="ctag">{{ ($c['scope']==='deal' || $c['step']===($board['anchor_id']??null)) ? 'DEAL' : 'STEP' }}</span></div>
          <div class="ctxt">{{ $c['text'] }}</div></div></div>
      @empty
        <div style="color:#94a3b8;font-size:12px;padding:8px 0;">No comments yet. Use a card's <b>Comments</b> action to add one.</div>
      @endforelse
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
@endsection

@push('scripts')
<script>
(function(){
  const list=document.getElementById('dr2ls-list'); if(!list) return;
  const url=list.dataset.reorder, token=list.dataset.csrf, cbase=list.dataset.comment; let dragEl=null;
  const rows=()=>Array.prototype.slice.call(list.querySelectorAll('.dr2-lrow'));

  // Add-comment (footer) — posts through the step comment route (redirect).
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
