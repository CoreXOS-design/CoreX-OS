@extends('layouts.corex')

{{-- Pipeline Dashboard — LIST view. The SAME tile visual as the timeline (left-edge colour · dot · name
     · ★ · "Xd · date → date" sub-line · action row), as full-width vertical cards, with grab-to-reorder
     (display position ONLY). Collapsible deal-context tabs on top + the same Comments footer. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
<style>
  #dr2ls{display:flex;flex-direction:column;height:calc(100vh - 4.2rem);min-height:520px;color:#1e293b;font-size:14px;
    --blue:#2563eb;--navy:#0f172a;--muted:#64748b;--faint:#94a3b8;--line:#e2e8f0;--red:#ef4444;--done:#16a34a;--gold:#eab308;--indigo:#4f46e5}
  #dr2ls .thead{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px}
  #dr2ls .h1{font-size:16px;font-weight:800;color:var(--navy);margin:0}#dr2ls .hsub{color:var(--muted);font-size:12.5px}
  #dr2ls .toggle{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden;font-size:13px}
  #dr2ls .toggle a{padding:6px 14px;color:#374151;text-decoration:none}#dr2ls .toggle .on{background:var(--navy);color:#fff;font-weight:700}
  #dr2ls .ctabs{border:1px solid var(--line);border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden}
  #dr2ls .ctabs-h{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;user-select:none;font-size:12.5px;color:var(--muted);font-weight:600}
  #dr2ls .ctabs-h .chev{transition:transform .18s}#dr2ls .ctabs-h.open .chev{transform:rotate(90deg)}
  #dr2ls .ctabs-body{border-top:1px solid var(--line);padding:10px 12px}
  #dr2ls .mid{flex:1;min-height:0;overflow-y:auto;background:#fff;border:1px solid var(--line);border-radius:14px 14px 0 0;padding:12px 14px}
  #dr2ls .lhint{font-size:11.5px;color:var(--muted);margin-bottom:8px}#dr2ls .lhint b{font-weight:800}
  #dr2ls .ltile{position:relative;display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 12px 10px 16px;margin-bottom:8px;box-shadow:0 1px 2px rgba(15,23,42,.05)}
  #dr2ls .ltile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:10px 0 0 10px}
  #dr2ls .ltile.active::before{background:var(--blue)}#dr2ls .ltile.done::before{background:var(--done)}#dr2ls .ltile.upcoming::before{background:#cbd5e1}
  #dr2ls .ltile.dragging{opacity:.45}#dr2ls .ltile.drag-over{outline:2px dashed #93c5fd;outline-offset:2px}
  #dr2ls .grip{cursor:grab;color:#cbd5e1;font-size:15px;user-select:none;flex:0 0 auto}
  #dr2ls .dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
  #dr2ls .dot.active{background:var(--blue)}#dr2ls .dot.done{background:var(--done)}#dr2ls .dot.upcoming{background:#cbd5e1}
  #dr2ls .lbody{flex:1;min-width:0}
  #dr2ls .nm{font-weight:700;font-size:13px;color:#1e293b}#dr2ls .ltile.done .nm{color:#8a97a8;text-decoration:line-through;text-decoration-color:#cbd5e1}
  #dr2ls .star{color:var(--gold)}
  #dr2ls .sub{font-size:11px;color:var(--faint);margin-top:2px}#dr2ls .sub .d{color:var(--muted);font-weight:600}
  #dr2ls .badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;background:#f1f5f9;color:var(--muted);white-space:nowrap}
  #dr2ls .badge.active{background:#dbeafe;color:#1d4ed8}#dr2ls .badge.done{background:#d1fae5;color:#047857}
  #dr2ls .lacts{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;flex:0 0 auto;max-width:52%}
  #dr2ls .lacts button,#dr2ls .lacts .btn{font-size:11px;line-height:1;padding:6px 9px;border:1px solid var(--line);border-radius:6px;background:#fff;color:var(--muted);cursor:pointer;font-family:inherit;white-space:nowrap}
  #dr2ls .lacts button:hover{background:#f1f5f9}
  #dr2ls .lacts .done-b{color:#065f46;border-color:#a7f3d0;background:#ecfdf5;font-weight:600}
  #dr2ls .lacts .seq{color:var(--blue);border-color:#bfdbfe;font-weight:600}#dr2ls .lacts .rm{color:#b91c1c;border-color:#fecaca}
  #dr2ls .inline-form{width:100%;margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  #dr2ls .inline-form input{font-family:inherit;font-size:12px;border:1px solid var(--line);border-radius:6px;padding:5px 8px}
  #dr2ls .inline-form .go{border:0;color:#fff;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer}
  /* footer (same as timeline) */
  #dr2ls .foot{flex:0 0 180px;background:#fff;border:1px solid var(--line);border-top:0;border-radius:0 0 14px 14px;display:flex;flex-direction:column;overflow:hidden}
  #dr2ls .cbar{display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:1px solid var(--line);flex-wrap:wrap}
  #dr2ls .cbar .t{font-weight:800;color:var(--navy);font-size:13px}
  #dr2ls .feed{flex:1;overflow-y:auto;padding:7px 15px}
  #dr2ls .cm{display:flex;gap:9px;padding:6px 0;border-bottom:1px solid #f4f6fa}
  #dr2ls .av{width:24px;height:24px;flex:0 0 24px;border-radius:50%;background:#dbe4ff;color:var(--indigo);font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center}
  #dr2ls .cmeta{font-size:11px;color:var(--muted)}#dr2ls .cmeta b{color:#1e293b}
  #dr2ls .ctag{font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:5px;margin-left:5px;background:#eff6ff;color:var(--blue)}
  #dr2ls .ctxt{font-size:12px;color:#1e293b;margin-top:1px;white-space:pre-wrap}
  #dr2ls .cadd{display:flex;gap:8px;padding:8px 15px;border-top:1px solid var(--line);align-items:center}
  #dr2ls .cadd select,#dr2ls .cadd input{font-family:inherit;font-size:12px;border:1px solid var(--line);border-radius:7px;padding:6px 9px}
  #dr2ls .cadd input{flex:1}#dr2ls .cadd button{font-size:12px;font-weight:600;padding:6px 15px;border:0;border-radius:7px;background:var(--blue);color:#fff;cursor:pointer}
</style>

@php($from = 'list')
@php($statusClass = fn($s) => $s->status==='completed'?'done':($s->status==='active'?'active':'upcoming'))
<div id="dr2ls" data-reorder="{{ route('deals-dr2.pipeline.reorder', $deal) }}" data-csrf="{{ csrf_token() }}">

  <div class="thead">
    <div>
      <div class="h1">{{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
        @if($deal->property) — {{ $deal->property->buildDisplayAddress() }} @endif</div>
      <div class="hsub">Pipeline list</div>
    </div>
    <div class="toggle"><a href="{{ route('deals-dr2.pipeline.timeline', $deal) }}">Timeline</a><span class="on">List</span></div>
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

  <div class="mid" id="dr2ls-list">
    @unless($locked)<div class="lhint">Grab a card's <b>⠿</b> to reorder (display only — never changes dependencies or dates).</div>@endunless
    @if($steps->isEmpty())
      <div style="padding:1.5rem;text-align:center;color:#64748b;font-size:13px;">No pipeline steps yet — build from the <strong>Deal panels → Structure</strong> tab above.</div>
    @endif
    @foreach($steps as $row)
      @php($s = $row['model'])
      @php($sc = $statusClass($s))
      <div class="ltile {{ $sc }}" data-id="{{ $s->id }}" x-data="{ open:'' }">
        <div style="display:flex;align-items:center;gap:12px;width:100%;">
          @unless($locked)<span class="grip" draggable="true" title="Drag to reorder">⠿</span>@endunless
          <span class="dot {{ $sc }}"></span>
          <div class="lbody">
            <div class="nm">{{ $s->name }} @if($s->is_milestone)<span class="star" title="Milestone">★</span>@endif</div>
            <div class="sub">
              @if(!is_null($s->duration_days))<span class="d">{{ $s->duration_days }}d</span> · @endif
              {{ $s->planned_start_date?->format('j M') }} → {{ $s->due_date?->format('j M Y') }}
              @if($row['blocked']) · <span style="color:#b45309;">{{ $row['blocked'] }}</span>@endif
              @if($s->comments->count()) · {{ $s->comments->count() }} comment{{ $s->comments->count()===1?'':'s' }}@endif
            </div>
          </div>
          <span class="badge {{ $sc }}">{{ ucfirst(str_replace('_',' ',$s->status)) }}</span>
          @unless($locked)
          <div class="lacts">
            @php($terminal = in_array($s->status, ['completed','skipped']))
            @unless($terminal)
              <button type="button" class="done-b" @click="open = open==='done'?'':'done'">✓ Complete</button>
              <button type="button" @click="open = open==='dates'?'':'dates'">Edit dates</button>
              <button type="button" class="seq" @click="open = open==='na'?'':'na'">N/A</button>
              <button type="button" @click="open = open==='cm'?'':'cm'">Comments</button>
              <button type="button" class="rm" @click="open = open==='rm'?'':'rm'">Remove</button>
            @else
              @if($s->status==='skipped')<form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="list"><button class="btn">Reinstate</button></form>@endif
              <button type="button" @click="open = open==='cm'?'':'cm'">Comments</button>
            @endunless
          </div>
          @endunless
        </div>
        @unless($locked)
        <form x-show="open==='done'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}" class="inline-form">@csrf<input type="hidden" name="from" value="list">
          <label style="font-size:12px;color:#374151;">Done on <input type="date" name="actual_date" value="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}"></label><button class="go" style="background:#16a34a;">Mark done</button></form>
        <form x-show="open==='dates'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.dates', [$deal, $s]) }}" class="inline-form">@csrf<input type="hidden" name="from" value="list">
          <label style="font-size:12px;color:#374151;">Start <input type="date" name="planned_start_date" required value="{{ $s->planned_start_date?->format('Y-m-d') }}"></label>
          <label style="font-size:12px;color:#374151;">End <input type="date" name="due_date" required value="{{ $s->due_date?->format('Y-m-d') }}"></label><button class="go" style="background:#2563eb;">Save dates</button></form>
        <form x-show="open==='na'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" class="inline-form">@csrf<input type="hidden" name="from" value="list">
          <input type="text" name="reason" placeholder="Why not applicable?" style="flex:1;"><button class="go" style="background:#f59e0b;">Mark N/A</button></form>
        <form x-show="open==='rm'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? (reversible)')" class="inline-form">@csrf<input type="hidden" name="from" value="list">
          <button class="go" style="background:#ef4444;">Remove step</button><span style="font-size:11px;color:#94a3b8;">reversible</span></form>
        <div x-show="open==='cm'" x-cloak class="inline-form" style="flex-direction:column;align-items:stretch;">
          @foreach($s->comments->sortBy('created_at') as $c)
            <div style="font-size:12px;color:#374151;border-left:2px solid #e5e7eb;padding:2px 8px;"><b>{{ $c->user?->name ?? 'System' }}</b> <span style="color:#9ca3af;font-size:10px;">· {{ $c->created_at->format('j M H:i') }}</span><br>{{ $c->body }}</div>
          @endforeach
          <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" style="display:flex;gap:6px;">@csrf<input type="hidden" name="from" value="list">
            <input type="text" name="body" required placeholder="Add a comment…" style="flex:1;"><button class="go" style="background:#0ea5e9;">Post</button></form>
        </div>
        @endunless
      </div>
    @endforeach
  </div>

  {{-- Comments footer (same normalized feed as the timeline) --}}
  <div class="foot">
    <div class="cbar"><span class="t">Comments</span><span style="font-size:11px;color:#64748b;">{{ count($board['comments'] ?? []) }} on this deal</span></div>
    <div class="feed">
      @forelse(array_reverse($board['comments'] ?? []) as $c)
        <div class="cm"><div class="av">{{ substr($c['who'],0,1) }}</div><div>
          <div class="cmeta"><b>{{ $c['who'] }}</b> · {{ $c['when'] }}<span class="ctag">{{ $c['scope']==='deal' ? strtoupper($c['type']) : 'step' }}</span></div>
          <div class="ctxt">{{ $c['text'] }}</div></div></div>
      @empty
        <div style="color:#94a3b8;font-size:12px;padding:8px 0;">No comments yet. Use a step's <b>Comments</b> action to add one.</div>
      @endforelse
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function(){
  const list=document.getElementById('dr2ls-list'); if(!list) return;
  const url='{{ route('deals-dr2.pipeline.reorder', $deal) }}', token='{{ csrf_token() }}'; let dragEl=null;
  const rows=()=>Array.prototype.slice.call(list.querySelectorAll('.ltile'));
  function persist(){const order=rows().map(r=>parseInt(r.dataset.id));
    fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},credentials:'same-origin',body:JSON.stringify({order})})
      .then(r=>r.json()).then(j=>{if(!j.ok)alert(j.error||'Could not save order.');window.location.reload();}).catch(()=>window.location.reload());}
  list.addEventListener('dragstart',e=>{const g=e.target.closest('.grip');if(!g){e.preventDefault();return;}dragEl=g.closest('.ltile');dragEl.classList.add('dragging');e.dataTransfer.effectAllowed='move';});
  list.addEventListener('dragover',e=>{e.preventDefault();const over=e.target.closest('.ltile');if(!over||over===dragEl)return;rows().forEach(r=>r.classList.remove('drag-over'));over.classList.add('drag-over');const rc=over.getBoundingClientRect();const after=(e.clientY-rc.top)>rc.height/2;list.insertBefore(dragEl,after?over.nextSibling:over);});
  list.addEventListener('drop',e=>e.preventDefault());
  list.addEventListener('dragend',()=>{if(!dragEl)return;dragEl.classList.remove('dragging');rows().forEach(r=>r.classList.remove('drag-over'));dragEl=null;persist();});
})();
</script>
@endpush
