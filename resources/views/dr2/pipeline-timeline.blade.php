@extends('layouts.corex')

{{-- Pipeline Dashboard — TIMELINE view, built to the approved mockup (/tmp/dr2_timeline_agreed.html):
     step TILES positioned by date + auto-stacked, gold milestone GATES, phase BANDS, a date axis, a
     TODAY line, on-timeline comment PINS, and a fixed Comments footer. Collapsible deal-context tabs on
     top. Real data (dr1_deal_id steps + normalized comments); drag reschedules via the reschedule
     endpoint, comments post to the step comment route. Scoped under #dr2tl so nothing leaks. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
<style>
  #dr2tl{display:flex;flex-direction:column;height:calc(100vh - 4.2rem);min-height:520px;color:#1e293b;
    font-size:14px;--blue:#2563eb;--navy:#0f172a;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;
    --red:#ef4444;--green:#22c55e;--done:#16a34a;--gold:#eab308;--indigo:#4f46e5}
  #dr2tl .thead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  #dr2tl .h1{font-size:16px;font-weight:800;color:var(--navy);margin:0}
  #dr2tl .hsub{color:var(--muted);font-size:12.5px}
  #dr2tl .toggle{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;font-size:13px}
  #dr2tl .toggle a{padding:6px 14px;color:#374151;text-decoration:none}
  #dr2tl .toggle .on{background:var(--navy);color:#fff;font-weight:700}
  #dr2tl .ctabs{border:1px solid var(--line);border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden}
  #dr2tl .ctabs-h{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;user-select:none;font-size:12.5px;color:var(--muted);font-weight:600}
  #dr2tl .ctabs-h .chev{transition:transform .18s}
  #dr2tl .ctabs-h.open .chev{transform:rotate(90deg)}
  #dr2tl .ctabs-body{border-top:1px solid var(--line);padding:10px 12px}

  #dr2tl .mid{flex:1;min-height:0;display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:14px 14px 0 0;box-shadow:0 1px 2px rgba(15,23,42,.04);overflow:hidden}
  #dr2tl .midbar{display:flex;align-items:center;gap:14px;padding:9px 14px;border-bottom:1px solid var(--line);flex-wrap:wrap}
  #dr2tl .midbar .t{font-weight:800;color:var(--navy);font-size:13.5px}
  #dr2tl .hint{font-size:11.5px;color:var(--muted)} #dr2tl .hint b{color:var(--blue)}
  #dr2tl .legend{display:flex;gap:13px;font-size:11px;color:var(--muted);align-items:center;margin-left:auto;flex-wrap:wrap}
  #dr2tl .lg{display:flex;align-items:center;gap:5px}
  #dr2tl .sw{width:16px;height:9px;border-radius:3px;display:inline-block}
  #dr2tl .sw.done{background:var(--done)}#dr2tl .sw.active{background:var(--blue)}#dr2tl .sw.upcoming{background:#cbd5e1}
  #dr2tl .dia{width:10px;height:10px;background:var(--gold);transform:rotate(45deg);display:inline-block}

  #dr2tl .scroll{flex:1;min-height:0;overflow:auto;position:relative}
  #dr2tl #canvas{position:relative}
  #dr2tl .band{position:absolute;top:34px;bottom:0;z-index:0;border-left:1px dashed #dbe4ff;background:rgba(99,102,241,.03)}
  #dr2tl .band.even{background:rgba(37,99,235,.035)}
  #dr2tl .bname{position:absolute;top:30px;left:12px;font-size:11px;font-weight:800;color:var(--indigo);text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
  #dr2tl .axis{position:sticky;top:0;height:34px;background:#fbfcfe;border-bottom:1px solid var(--line);z-index:5}
  #dr2tl .tick{position:absolute;top:0;height:34px;border-left:1px solid #eef2f7;font-size:10.5px;color:var(--muted);font-weight:600;padding:9px 0 0 5px;white-space:nowrap}
  #dr2tl .mile{position:absolute;z-index:2;width:2px;background:var(--gold);top:32px;bottom:0}
  #dr2tl .mile .cap{position:absolute;top:3px;left:-8px;width:16px;height:16px;background:var(--gold);transform:rotate(45deg);border:2px solid #fff;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.2)}
  #dr2tl .mile .txt{position:absolute;top:3px;left:12px;font-size:10px;font-weight:800;color:#a16207;white-space:nowrap}
  #dr2tl .mile.done{background:var(--done)}#dr2tl .mile.done .cap{background:var(--done)}#dr2tl .mile.done .txt{color:var(--done)}
  #dr2tl .mile.up{background:#cbd5e1}#dr2tl .mile.up .cap{background:#cbd5e1}#dr2tl .mile.up .txt{color:var(--muted)}
  #dr2tl #today{position:absolute;width:2px;background:var(--red);z-index:4;top:34px;bottom:0}
  #dr2tl #today .cap{position:absolute;top:-1px;left:4px;font-size:9px;font-weight:800;color:var(--red);background:#fff;padding:0 3px;border-radius:3px}

  #dr2tl .ttile{position:absolute;z-index:3;background:#fff;border:1px solid var(--line);border-radius:10px;height:66px;padding:6px 9px;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(15,23,42,.10);overflow:hidden;cursor:grab;user-select:none}
  #dr2tl .ttile:active{cursor:grabbing}
  #dr2tl .ttile.sel{border-color:#93c5fd;box-shadow:0 6px 16px rgba(37,99,235,.22)}
  #dr2tl .ttile.dragging{box-shadow:0 10px 22px rgba(37,99,235,.30);z-index:9;opacity:.96}
  #dr2tl .ttile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:10px 0 0 10px}
  #dr2tl .ttile.active::before{background:var(--blue)}#dr2tl .ttile.done::before{background:var(--done)}#dr2tl .ttile.upcoming::before{background:#cbd5e1}
  #dr2tl .th{display:flex;align-items:center;gap:6px}
  #dr2tl .dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
  #dr2tl .dot.active{background:var(--blue)}#dr2tl .dot.done{background:var(--done)}#dr2tl .dot.upcoming{background:#cbd5e1}
  #dr2tl .nm{font-weight:700;font-size:12px;color:var(--ink,#1e293b);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #dr2tl .ttile.done .nm{color:#8a97a8;text-decoration:line-through;text-decoration-color:#cbd5e1}
  #dr2tl .star{color:var(--gold);flex:0 0 auto}
  #dr2tl .sub{font-size:10.5px;color:var(--faint);margin:2px 0 0 14px}#dr2tl .sub .d{color:var(--muted);font-weight:600}
  #dr2tl .tacts{display:flex;gap:4px;margin-top:auto;flex-wrap:nowrap;overflow:hidden}
  #dr2tl .tacts button{font-size:9.5px;line-height:1;padding:4px 6px;border:1px solid var(--line);border-radius:5px;background:#fff;color:var(--muted);cursor:pointer;font-family:inherit;white-space:nowrap}
  #dr2tl .tacts button:hover{background:#f1f5f9}
  #dr2tl .tacts .done-b{color:#065f46;border-color:#a7f3d0;background:#ecfdf5;font-weight:600}
  #dr2tl .tacts .seq{color:var(--blue);border-color:#bfdbfe;font-weight:600}
  #dr2tl .tacts .rm{color:#b91c1c;border-color:#fecaca}
  #dr2tl .pin{position:absolute;top:-6px;right:-6px;width:16px;height:16px;border-radius:50%;background:#fff;border:1.5px solid var(--indigo);color:var(--indigo);font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;z-index:4}

  #dr2tl .ctrack-line{position:absolute;left:0;height:1px;background:var(--line);z-index:1}
  #dr2tl .ctrack-lbl{position:absolute;left:12px;font-size:10px;font-weight:800;color:var(--faint);text-transform:uppercase;letter-spacing:.03em;z-index:2}
  #dr2tl .cpin{position:absolute;width:22px;height:22px;border-radius:50%;background:#fff;border:1.6px solid var(--indigo);color:var(--indigo);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;z-index:3;cursor:pointer;transform:translateX(-11px);box-shadow:0 1px 3px rgba(0,0,0,.14)}
  #dr2tl .cpin.step{border-color:var(--blue);color:var(--blue)}
  #dr2tl .cpin .stem{position:absolute;bottom:100%;left:50%;width:1.5px;height:12px;background:currentColor;opacity:.35}

  #dr2tl .foot{flex:0 0 190px;background:#fff;margin:0;border:1px solid var(--line);border-top:0;border-radius:0 0 14px 14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
  #dr2tl .cbar{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:1px solid var(--line);flex-wrap:wrap}
  #dr2tl .cbar .t{font-weight:800;color:var(--navy);font-size:13px}
  #dr2tl .cscope{font-size:11px;color:var(--muted)}#dr2tl .cscope b{color:var(--blue)}
  #dr2tl .chip{font-size:11px;padding:3px 10px;border-radius:20px;border:1px solid var(--line);background:#fff;color:var(--muted);cursor:pointer;font-family:inherit}
  #dr2tl .chip.on{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:600}
  #dr2tl .feed{flex:1;overflow-y:auto;padding:7px 15px}
  #dr2tl .cm{display:flex;gap:9px;padding:6px 0;border-bottom:1px solid #f4f6fa}
  #dr2tl .av{width:24px;height:24px;flex:0 0 24px;border-radius:50%;background:#dbe4ff;color:var(--indigo);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  #dr2tl .cmeta{font-size:11px;color:var(--muted)}#dr2tl .cmeta b{color:#1e293b}
  #dr2tl .ctag{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:5px;margin-left:5px;background:#eff6ff;color:var(--blue)}
  #dr2tl .ctag.deal{background:#eef2ff;color:var(--indigo)}
  #dr2tl .ctxt{font-size:12px;color:#1e293b;margin-top:1px;white-space:pre-wrap}
  #dr2tl .cadd{display:flex;gap:8px;padding:8px 15px;border-top:1px solid var(--line);align-items:center}
  #dr2tl .cadd select,#dr2tl .cadd input{font-family:inherit;font-size:12px;border:1px solid var(--line);border-radius:7px;padding:6px 9px;color:#1e293b}
  #dr2tl .cadd input{flex:1}
  #dr2tl .cadd button{font-size:12px;font-weight:600;padding:6px 15px;border:0;border-radius:7px;background:var(--blue);color:#fff;cursor:pointer}
  #dr2tl-toast{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:var(--navy);color:#fff;padding:9px 15px;border-radius:9px;font-size:12px;opacity:0;transition:opacity .2s;pointer-events:none;z-index:60}
  #dr2tl-toast.on{opacity:1}
</style>

<div id="dr2tl" data-deal="{{ $deal->id }}"
     data-reschedule="{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}"
     data-comment="{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}"
     data-csrf="{{ csrf_token() }}">

  <div class="thead">
    <div>
      <div class="h1">{{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
        @if($deal->property) — {{ $deal->property->buildDisplayAddress() }} @endif</div>
      <div class="hsub">Pipeline timeline</div>
    </div>
    <div class="toggle">
      <span class="on">Timeline</span>
      <a href="{{ route('deals-dr2.pipeline.list', $deal) }}">List</a>
    </div>
  </div>

  {{-- Collapsible deal-context tabs (default collapsed to give the timeline room) --}}
  <div class="ctabs" x-data="{ open:false }">
    <div class="ctabs-h" :class="open?'open':''" @click="open=!open">
      <span class="chev">▸</span> Deal panels — Structure · Work Orders · Documents · Parties · Proforma
      <span style="margin-left:auto;font-weight:400;color:#94a3b8;" x-text="open?'hide':'show'"></span>
    </div>
    <div class="ctabs-body" x-show="open" x-cloak>
      @include('dr2._pipeline-context-tabs')
    </div>
  </div>

  @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('info') }}</div>@endif
  @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:6px 12px;font-size:12.5px;margin-bottom:6px;">{{ session('error') }}</div>@endif

  @if($board['empty'])
    <div class="mid"><div style="padding:2rem;text-align:center;color:#64748b;font-size:13px;">
      No pipeline steps with a schedule yet — build the pipeline from the <strong>Deal panels → Structure</strong> tab above.
    </div></div>
  @else
  <div class="mid">
    <div class="midbar">
      <span class="t">Timeline</span>
      <span class="hint">tiles stretch to their duration · overlapping tiles stack underneath · <b>grab a tile and slide it to reschedule</b> · 💬 marks comments on the date made</span>
      <div class="legend">
        <span class="lg"><span class="sw done"></span>Done</span>
        <span class="lg"><span class="sw active"></span>Active</span>
        <span class="lg"><span class="sw upcoming"></span>Upcoming</span>
        <span class="lg"><span class="dia"></span>Milestone</span>
      </div>
    </div>
    <div class="scroll"><div id="canvas">
      <div class="axis" id="axis"></div>
      <div id="bands"></div>
      <div id="miles"></div>
      <div id="today"><span class="cap">TODAY</span></div>
      <div id="tiles"></div>
    </div></div>
  </div>
  <div class="foot">
    <div class="cbar">
      <span class="t">Comments</span>
      <span class="cscope" id="dr2tl-scope">Showing: <b>all</b></span>
      <span class="chip on" data-f="all" onclick="dr2tlFilter('all',this)">All</span>
      <span class="chip" data-f="deal" onclick="dr2tlFilter('deal',this)">Deal-level</span>
      <span class="chip" data-f="step" id="dr2tl-stepchip" onclick="dr2tlFilter('step',this)" style="display:none">This step</span>
    </div>
    <div class="feed" id="dr2tl-feed"></div>
    <div class="cadd">
      <select id="dr2tl-target">
        @foreach($board['tiles'] as $t)<option value="{{ $t['id'] }}">On: {{ $t['name'] }}</option>@endforeach
      </select>
      <input id="dr2tl-text" placeholder="Add a comment…">
      <button onclick="dr2tlAddComment()">Add</button>
    </div>
  </div>
  @endif
  <div id="dr2tl-toast"></div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  const root=document.getElementById('dr2tl'); if(!root) return;
  const BOARD=@json($board ?? ['empty'=>true]);
  if(BOARD.empty) return;
  const DAYW=BOARD.day_width||21, PADX=14, ROWH=78, TRACKH=52;
  const MLVL=BOARD.mile_levels||1, ROWTOP=96+MLVL*13; // push tiles below the (possibly stacked) milestone labels
  const BASE=new Date(BOARD.base_date+'T00:00:00Z');
  const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const x=d=>PADX+d*DAYW;
  const dstr=d=>{const t=new Date(BASE);t.setUTCDate(t.getUTCDate()+d);return t.getUTCDate()+' '+MON[t.getUTCMonth()];};
  const isoOf=d=>{const t=new Date(BASE);t.setUTCDate(t.getUTCDate()+d);return t.toISOString().slice(0,10);};
  const RESCH=root.dataset.reschedule, CBASE=root.dataset.comment, CSRF=root.dataset.csrf;

  let tiles=BOARD.tiles.map(t=>({...t})), comments=BOARD.comments.slice();
  const tileById={}; tiles.forEach(t=>tileById[t.id]=t);
  let selected=null, filter='all';

  function assignRows(){
    const order=[...tiles].sort((a,b)=>a.start-b.start||tiles.indexOf(a)-tiles.indexOf(b));
    const laneEnd=[];
    order.forEach(t=>{let r=0;while(r<laneEnd.length&&laneEnd[r]>t.start)r++;t.row=r;laneEnd[r]=t.start+t.dur;});
  }
  function render(){
    assignRows();
    const W=x(BOARD.days)+40, maxRow=Math.max(0,...tiles.map(t=>t.row));
    const bottom=ROWTOP+(maxRow+1)*ROWH+TRACKH+16;
    const cv=document.getElementById('canvas'); cv.style.width=W+'px'; cv.style.height=bottom+'px';
    const ax=document.getElementById('axis'); ax.style.width=W+'px'; ax.innerHTML='';
    for(let d=0;d<=BOARD.days;d+=7){const t=document.createElement('div');t.className='tick';t.style.left=x(d)+'px';t.textContent=dstr(d);ax.appendChild(t);}
    const bd=document.getElementById('bands'); bd.innerHTML='';
    const bnameTop=(MLVL*13+6); // band labels sit BELOW the stacked milestone labels
    BOARD.phases.forEach((p,i)=>{const b=document.createElement('div');b.className='band'+(i%2?' even':'');b.style.left=x(p.from)+'px';b.style.width=(p.to-p.from)*DAYW+'px';b.innerHTML='<span class="bname" style="top:'+bnameTop+'px">'+p.name+'</span>';bd.appendChild(b);});
    const ms=document.getElementById('miles'); ms.innerHTML='';
    BOARD.miles.forEach(m=>{const el=document.createElement('div');el.className='mile '+m.state;el.style.left=x(m.day)+'px';
      el.innerHTML='<span class="cap"></span><span class="txt" style="top:'+(3+(m.lvl||0)*13)+'px">★ '+m.name+' · '+dstr(m.day)+'</span>';ms.appendChild(el);});
    const td=document.getElementById('today'); if(BOARD.today_day>=0&&BOARD.today_day<=BOARD.days){td.style.display='';td.style.left=x(BOARD.today_day)+'px';}else{td.style.display='none';}
    const tl=document.getElementById('tiles'); tl.innerHTML='';
    tiles.forEach(t=>{
      const cc=comments.filter(c=>c.target===t.id).length;
      const el=document.createElement('div');
      el.className='ttile '+t.status+(selected===t.id?' sel':'');
      el.style.left=x(t.start)+'px'; el.style.width=Math.max(96,(t.dur*DAYW-6))+'px'; el.style.top=(ROWTOP+t.row*ROWH)+'px';
      el.dataset.id=t.id;
      el.innerHTML=
        '<div class="th"><span class="dot '+t.status+'"></span><span class="nm">'+t.name+'</span>'+(t.star?'<span class="star">★</span>':'')+'</div>'+
        '<div class="sub"><span class="d">'+t.dur+'d</span> · '+dstr(t.start)+' → '+dstr(t.start+t.dur)+'</div>'+
        '<div class="tacts">'+(t.status==='done'?'<button data-a="reopen">↺ Reopen</button>':'<button class="done-b" data-a="complete">✓ Complete</button>')+
          '<button data-a="dates">Edit dates</button><button class="seq" data-a="seq">Sequence</button><button data-a="comments">Comments</button><button class="rm" data-a="remove">Remove</button></div>'+
        (cc?'<span class="pin">'+cc+'</span>':'');
      tl.appendChild(el);
      attachDrag(el,t);
      el.querySelectorAll('button').forEach(b=>b.addEventListener('pointerdown',e=>e.stopPropagation()));
      el.querySelectorAll('button').forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();tileAction(b.dataset.a,t);}));
      el.addEventListener('click',()=>{if(!el._moved)select(t.id);});
    });
    // comments track
    const trackY=ROWTOP+(maxRow+1)*ROWH+4;
    const line=document.createElement('div');line.className='ctrack-line';line.style.top=trackY+'px';line.style.width=W+'px';tl.appendChild(line);
    const lbl=document.createElement('div');lbl.className='ctrack-lbl';lbl.style.top=(trackY+4)+'px';lbl.textContent='💬 Comments';tl.appendChild(lbl);
    comments.forEach(c=>{
      const pin=document.createElement('div');pin.className='cpin '+(c.scope==='step'?'step':'deal');
      pin.style.left=x(c.day)+'px';pin.style.top=(trackY+20)+'px';
      pin.innerHTML='<span class="stem"></span>'+(c.who[0]||'?');
      pin.title=c.who+' · '+c.when+(c.scope==='step'&&tileById[c.target]?' (on '+tileById[c.target].name+')':' (deal)')+':\n'+c.text;
      pin.onclick=()=>{ if(c.scope==='step'&&tileById[c.target])select(c.target); else {filter='deal';syncChips();renderFeed();} };
      tl.appendChild(pin);
    });
  }
  function attachDrag(el,t){
    el.addEventListener('pointerdown',e=>{
      if(e.target.tagName==='BUTTON')return;
      el._moved=false;el.classList.add('dragging');el.setPointerCapture(e.pointerId);
      const sx=e.clientX,origStart=t.start;
      const move=ev=>{const dd=Math.round((ev.clientX-sx)/DAYW);if(dd!==0)el._moved=true;const ns=Math.max(0,origStart+dd);el.style.left=x(ns)+'px';el._ns=ns;
        el.querySelector('.sub').innerHTML='<span class="d">'+t.dur+'d</span> · '+dstr(ns)+' → '+dstr(ns+t.dur);};
      const up=()=>{el.classList.remove('dragging');try{el.releasePointerCapture(e.pointerId);}catch(x){}
        el.removeEventListener('pointermove',move);el.removeEventListener('pointerup',up);
        if(el._moved&&el._ns!=null&&el._ns!==origStart){ reschedule(t,el._ns,origStart); }
        setTimeout(()=>{el._moved=false;},60);};
      el.addEventListener('pointermove',move);el.addEventListener('pointerup',up);
    });
  }
  function reschedule(t,newStart,origStart){
    const delta=newStart-origStart;
    fetch(RESCH+'/'+t.id+'/reschedule',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},credentials:'same-origin',
      body:JSON.stringify({new_start:isoOf(newStart),commit:true})})
      .then(r=>r.json()).then(j=>{ if(!j.ok){toast(j.error||'Could not reschedule');render();return;}
        toast('<span class="em">'+t.name+'</span> moved '+Math.abs(delta)+' day'+(Math.abs(delta)>1?'s':'')+' '+(delta<0?'earlier':'later'));
        setTimeout(()=>window.location.reload(),500); })
      .catch(()=>{toast('Could not reschedule');render();});
  }
  function tileAction(a,t){
    if(a==='comments'){select(t.id);document.getElementById('dr2tl-target').value=t.id;document.getElementById('dr2tl-text').focus();return;}
    if(a==='seq'){toast('Drag a tile to reschedule, or use the List view to reorder steps');return;}
    if(a==='dates'){const s=prompt('Start date (YYYY-MM-DD):',isoOf(t.start));if(!s)return;const e=prompt('End date (YYYY-MM-DD):',isoOf(t.start+t.dur));if(!e)return;return submitForm(t.id,'dates',{planned_start_date:s,due_date:e});}
    if(a==='complete'){if(confirm('Mark "'+t.name+'" complete?'))submitForm(t.id,'complete',{});return;}
    if(a==='reopen'){submitForm(t.id,'reopen',{});return;}
    if(a==='remove'){if(confirm('Remove "'+t.name+'"? (reversible)'))submitForm(t.id,'remove',{});return;}
  }
  function submitForm(stepId,action,fields){
    const f=document.createElement('form');f.method='POST';f.action=CBASE+'/'+stepId+'/'+action;f.style.display='none';
    const add=(n,v)=>{const i=document.createElement('input');i.name=n;i.value=v;f.appendChild(i);};
    add('_token',CSRF);add('from','timeline');Object.entries(fields).forEach(([n,v])=>add(n,v));
    document.body.appendChild(f);f.submit();
  }
  function select(id){selected=id;filter='step';
    const chip=document.getElementById('dr2tl-stepchip');chip.style.display='';syncChips();
    document.getElementById('dr2tl-target').value=id;render();renderFeed();}
  function syncChips(){document.querySelectorAll('#dr2tl .chip').forEach(c=>c.classList.toggle('on',c.dataset.f===filter));}
  window.dr2tlFilter=function(f,el){filter=f;document.querySelectorAll('#dr2tl .chip').forEach(c=>c.classList.remove('on'));el.classList.add('on');renderFeed();};
  function renderFeed(){
    const feed=document.getElementById('dr2tl-feed'),scope=document.getElementById('dr2tl-scope');
    let list=comments.slice();
    if(filter==='deal')list=list.filter(c=>c.scope==='deal');
    else if(filter==='step'&&selected)list=list.filter(c=>c.target===selected);
    scope.innerHTML='Showing: <b>'+(filter==='step'&&selected?(tileById[selected]?tileById[selected].name:'this step'):filter)+'</b>';
    feed.innerHTML=list.length?'':'<div style="color:#94a3b8;font-size:12px;padding:8px 0">No comments yet.</div>';
    list.slice().reverse().forEach(c=>{
      const on=c.scope==='deal'?'Deal':(tileById[c.target]?tileById[c.target].name:'step');
      const cm=document.createElement('div');cm.className='cm';
      cm.innerHTML='<div class="av">'+(c.who[0]||'?')+'</div><div><div class="cmeta"><b>'+c.who+'</b> · '+c.when+
        '<span class="ctag '+c.scope+'">'+(c.scope==='deal'?c.type.toUpperCase():'step: '+on)+'</span></div><div class="ctxt">'+c.text+'</div></div>';
      feed.appendChild(cm);
    });
  }
  window.dr2tlAddComment=function(){
    const inp=document.getElementById('dr2tl-text'),stepId=document.getElementById('dr2tl-target').value;
    if(!inp.value.trim())return;
    submitForm(stepId,'comment',{body:inp.value.trim()});
  };
  function toast(m){const t=document.getElementById('dr2tl-toast');t.innerHTML=m;t.classList.add('on');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('on'),2400);}
  render();renderFeed();
})();
</script>
@endpush
