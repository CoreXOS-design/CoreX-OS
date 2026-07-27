@extends('layouts.corex')

{{-- Pipeline Dashboard — TIMELINE view: the HORIZONTAL date-Gantt (source of truth:
     .ai/mockups/dr2_timeline_horizontal.html). A date axis runs across the top; each step is a TILE
     positioned by its planned_start_date, width proportional to its duration; overlapping tiles
     auto-stack into rows so they never collide; behind them sit derived phase BANDS, gold milestone
     DIAMONDS, and a red TODAY line; a comment track pins notes on the date each was made. This is the
     time-based view — deliberately DISTINCT from the List's vertical sectioned cards.
     Reads the dormant horizontal read-model PipelineTimelineService::buildBoard() (tiles/miles/phases/
     comments + server-packed rows). Deal-context tabs stay on TOP, collapsible, default collapsed,
     bounded + internally scrollable. Each tile keeps the full 6-action set (Complete/Reopen · Edit due ·
     Sequence · N/A · Remove · Comments) posting to the existing deals-dr2.pipeline.step.* routes. A
     Comments footer posts through the step comment route. Real data (dr1_deal_id steps); QA1 only; a
     pure overlay — nothing here changes DR1 deal state. --}}

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
  #dr2tl .toggle span.on{padding:6px 14px}
  #dr2tl .back{color:#2563eb;font-size:12.5px;text-decoration:none;font-weight:600}
  /* collapsible deal-context tabs — bounded + internally scrollable (Johan D) */
  #dr2tl .ctabs{border:1px solid #e2e8f0;border-radius:10px;background:#fff;margin-bottom:8px;overflow:hidden;flex:0 0 auto}
  #dr2tl .ctabs-h{display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;user-select:none;font-size:12.5px;color:#64748b;font-weight:600}
  #dr2tl .ctabs-h .chev{transition:transform .18s}
  #dr2tl .ctabs-h.open .chev{transform:rotate(90deg)}
  #dr2tl .ctabs-body{border-top:1px solid #e2e8f0;padding:10px 12px;max-height:min(48vh,460px);overflow-y:auto;overscroll-behavior:contain}

  /* ── the Gantt surface ── */
  #dr2tl .mid{flex:1;min-height:0;display:flex;flex-direction:column;background:#fff;border:1px solid #e2e8f0;border-radius:14px 14px 0 0;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
  #dr2tl .midbar{display:flex;align-items:center;gap:14px;padding:10px 15px;border-bottom:1px solid #e2e8f0;flex-wrap:wrap}
  #dr2tl .midbar .t{font-weight:800;color:#0f172a;font-size:13.5px}
  #dr2tl .midbar .hint{font-size:11.5px;color:#64748b}
  #dr2tl .midbar .hint b{color:#2563eb}
  #dr2tl .legend{display:flex;gap:13px;font-size:11px;color:#64748b;align-items:center;margin-left:auto;flex-wrap:wrap}
  #dr2tl .lg{display:flex;align-items:center;gap:5px}
  #dr2tl .sw{width:16px;height:9px;border-radius:3px;display:inline-block}
  #dr2tl .sw.done{background:#16a34a}#dr2tl .sw.active{background:#2563eb}#dr2tl .sw.upcoming{background:#cbd5e1}
  #dr2tl .dia{width:10px;height:10px;background:#eab308;transform:rotate(45deg);display:inline-block}

  #dr2tl .scroll{flex:1;min-height:0;overflow:auto;position:relative}
  #dr2tl .canvas{position:relative}

  /* phase bands (behind) */
  #dr2tl .band{position:absolute;top:34px;bottom:0;z-index:0;border-left:1px dashed #dbe4ff;background:rgba(99,102,241,.03)}
  #dr2tl .band.alt{background:rgba(37,99,235,.045)}
  #dr2tl .band .bname{position:absolute;left:10px;font-size:11px;font-weight:800;color:#4f46e5;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;opacity:.85}

  /* date axis */
  #dr2tl .axis{position:sticky;top:0;height:34px;background:#fbfcfe;border-bottom:1px solid #e2e8f0;z-index:5}
  #dr2tl .tick{position:absolute;top:0;height:34px;border-left:1px solid #eef2f7;font-size:10.5px;color:#64748b;font-weight:600;padding:9px 0 0 5px;white-space:nowrap}

  /* milestone vertical gate */
  #dr2tl .mile{position:absolute;z-index:2;width:2px;background:#eab308;top:32px;bottom:0}
  #dr2tl .mile .cap{position:absolute;top:2px;left:-8px;width:16px;height:16px;background:#eab308;transform:rotate(45deg);border:2px solid #fff;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.2)}
  #dr2tl .mile .txt{position:absolute;left:12px;font-size:10px;font-weight:800;color:#a16207;white-space:nowrap;max-width:190px;overflow:hidden;text-overflow:ellipsis}
  #dr2tl .mile.done{background:#16a34a}#dr2tl .mile.done .cap{background:#16a34a}#dr2tl .mile.done .txt{color:#16a34a}
  #dr2tl .mile.up{background:#cbd5e1}#dr2tl .mile.up .cap{background:#cbd5e1}#dr2tl .mile.up .txt{color:#64748b}

  /* today line */
  #dr2tl .today{position:absolute;width:2px;background:#ef4444;z-index:4;top:34px;bottom:0}
  #dr2tl .today .cap{position:absolute;top:-1px;left:4px;font-size:9px;font-weight:800;color:#ef4444;background:#fff;padding:0 3px;border-radius:3px}

  /* the TILE = the duration bar */
  #dr2tl .ttile{position:absolute;z-index:3;background:#fff;border:1px solid #e2e8f0;border-radius:10px;height:78px;
    padding:6px 9px;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(15,23,42,.10);overflow:hidden}
  #dr2tl .ttile:hover{z-index:8;box-shadow:0 6px 16px rgba(37,99,235,.20)}
  #dr2tl .ttile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:10px 0 0 10px}
  #dr2tl .ttile.active::before{background:#2563eb}#dr2tl .ttile.done::before{background:#16a34a}#dr2tl .ttile.upcoming::before{background:#cbd5e1}
  #dr2tl .th{display:flex;align-items:center;gap:6px}
  #dr2tl .th .dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
  #dr2tl .th .dot.active{background:#2563eb}#dr2tl .th .dot.done{background:#16a34a}#dr2tl .th .dot.upcoming{background:#cbd5e1}
  #dr2tl .nm{font-weight:700;font-size:12px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #dr2tl .ttile.done .nm{color:#8a97a8;text-decoration:line-through;text-decoration-color:#cbd5e1}
  #dr2tl .star{color:#eab308;flex:0 0 auto;font-size:11px}
  #dr2tl .sub{font-size:10.5px;color:#94a3b8;margin:2px 0 0 14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  #dr2tl .sub .d{color:#64748b;font-weight:700}
  #dr2tl .tacts{display:flex;gap:4px;margin-top:auto;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;padding-bottom:2px;scrollbar-width:thin}
  #dr2tl .tacts::-webkit-scrollbar{height:5px}
  #dr2tl .tacts::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
  /* !important beats the .hfc-card button[type=submit] global (which is also !important); our
     id-scoped selector is more specific, so submit buttons (Reopen/Remove) match type=button ones. */
  #dr2tl .tacts .b{font-size:9.5px!important;line-height:1!important;padding:4px 6px!important;border:1px solid #e2e8f0!important;border-radius:5px!important;background:#fff!important;color:#64748b!important;cursor:pointer;font-family:inherit;font-weight:600!important;white-space:nowrap;flex:0 0 auto}
  #dr2tl .tacts .b:hover{background:#f1f5f9!important}
  #dr2tl .tacts .b.go{color:#065f46!important;border-color:#a7f3d0!important;background:#ecfdf5!important}
  #dr2tl .tacts .b.seq{color:#2563eb!important;border-color:#bfdbfe!important}
  #dr2tl .tacts .b.rm{color:#b91c1c!important;border-color:#fecaca!important;background:#fff!important}
  #dr2tl .tacts form{display:inline}
  #dr2tl .pin{position:absolute;top:-6px;right:-6px;width:16px;height:16px;border-radius:50%;background:#fff;border:1.5px solid #4f46e5;color:#4f46e5;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;z-index:4}

  /* comments-on-timeline track */
  #dr2tl .ctrack-line{position:absolute;left:0;height:1px;background:#e2e8f0;z-index:1}
  #dr2tl .ctrack-lbl{position:absolute;left:12px;font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;z-index:2}
  #dr2tl .cpin{position:absolute;width:22px;height:22px;border-radius:50%;background:#fff;border:1.6px solid #4f46e5;color:#4f46e5;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;z-index:3;cursor:default;transform:translateX(-11px);box-shadow:0 1px 3px rgba(0,0,0,.14)}
  #dr2tl .cpin.step{border-color:#2563eb;color:#2563eb}
  #dr2tl .cpin .stem{position:absolute;bottom:100%;left:50%;width:1.5px;height:12px;background:currentColor;opacity:.35}

  /* unscheduled strip */
  #dr2tl .unsched{margin:8px 15px 0;padding:7px 11px;border:1px dashed #fcd34d;background:#fffbeb;border-radius:8px;font-size:11.5px;color:#92400e}
  #dr2tl .unsched b{color:#78350f}

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
  #dr2tl-toast{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);background:#0f172a;color:#fff;padding:9px 15px;border-radius:9px;font-size:12px;opacity:0;transition:opacity .2s;pointer-events:none;z-index:160}
  #dr2tl-toast.on{opacity:1}
</style>

@php($from = 'timeline')
@php($rowById = $steps->keyBy(fn ($r) => (int) $r['model']->id))
@php($DAYW = (int) ($board['day_width'] ?? 21))
@php($PADX = 14)
@php($mileLevels = max(1, (int) ($board['mile_levels'] ?? 1)))
@php($ROWTOP = 58 + $mileLevels * 17)
@php($ROWH = 94)
@php($bandLabelTop = max(2, $ROWTOP - 34 - 15))
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
      No dated pipeline steps yet — set start &amp; due dates from the <strong>Deal panels → Structure</strong> tab above, or open the <a href="{{ route('deals-dr2.pipeline.list', $deal) }}" style="color:#2563eb;font-weight:600;">List view</a>.
    </div></div>
  @else
  @php($base = \Illuminate\Support\Carbon::parse($board['base_date']))
  @php($dstr = fn ($d) => $base->copy()->addDays((int) $d)->format('j M'))
  @php($days = (int) $board['days'])
  @php($W = $PADX + $days * $DAYW + 40)
  @php($tiles = collect($board['tiles']))
  @php($maxRow = (int) ($tiles->max('row') ?? 0))
  @php($trackY = $ROWTOP + ($maxRow + 1) * $ROWH + 4)
  @php($canvasH = $trackY + 60)
  {{-- per-row next-start, so a legibility-floored tile never overruns the next tile in its row --}}
  @php($capRight = [])
  @php($tiles->groupBy('row')->each(function ($g) use (&$capRight) {
        $s = $g->sortBy('start')->values();
        for ($i = 0; $i < $s->count(); $i++) { $capRight[(int) $s[$i]['id']] = $s[$i + 1]['start'] ?? null; }
      }))
  @php($commentCount = [])
  @php($steps->each(function ($r) use (&$commentCount) { $commentCount[(int) $r['model']->id] = $r['model']->comments->count(); }))

  <div class="mid">
    <div class="midbar">
      <span class="t">Timeline</span>
      <span class="hint">tiles stretch to their duration · overlapping tiles stack underneath · 💬 marks comments on the date made</span>
      <div class="legend">
        <span class="lg"><span class="sw done"></span>Done</span>
        <span class="lg"><span class="sw active"></span>Active</span>
        <span class="lg"><span class="sw upcoming"></span>Upcoming</span>
        <span class="lg"><span class="dia"></span>Milestone</span>
      </div>
    </div>

    @if(!empty($board['unscheduled']))
      <div class="unsched">
        <b>⚠ {{ count($board['unscheduled']) }} step(s) not on the timeline</b> (no start/due date set — add dates in Deal Structure):
        {{ collect($board['unscheduled'])->pluck('name')->implode(' · ') }}
      </div>
    @endif

    <div class="scroll">
      <div class="canvas" style="width:{{ $W }}px;height:{{ $canvasH }}px;">

        {{-- date axis --}}
        <div class="axis" style="width:{{ $W }}px;">
          @for($d = 0; $d <= $days; $d += 7)
            <div class="tick" style="left:{{ $PADX + $d * $DAYW }}px;">{{ $dstr($d) }}</div>
          @endfor
        </div>

        {{-- phase bands --}}
        @foreach($board['phases'] as $i => $p)
          @php($bandW = max(0, ($p['to'] - $p['from']) * $DAYW))
          <div class="band {{ $i % 2 ? 'alt' : '' }}" style="left:{{ $PADX + $p['from'] * $DAYW }}px;width:{{ $bandW }}px;">
            @if($bandW >= 118)<span class="bname" style="top:{{ $bandLabelTop }}px;max-width:{{ $bandW - 14 }}px;overflow:hidden;text-overflow:ellipsis;">{{ $p['name'] }}</span>@endif
          </div>
        @endforeach

        {{-- milestone diamonds --}}
        @foreach($board['miles'] as $m)
          <div class="mile {{ $m['state'] }}" style="left:{{ $PADX + $m['day'] * $DAYW }}px;">
            <span class="cap"></span>
            <span class="txt" style="top:{{ 2 + (int) ($m['lvl'] ?? 0) * 15 }}px;">★ {{ $m['name'] }} · {{ $dstr($m['day']) }}</span>
          </div>
        @endforeach

        {{-- red TODAY line --}}
        @if((int) $board['today_day'] >= 0 && (int) $board['today_day'] <= $days)
          <div class="today" style="left:{{ $PADX + (int) $board['today_day'] * $DAYW }}px;"><span class="cap">TODAY</span></div>
        @endif

        {{-- step TILES (positioned by date, width ∝ duration, stacked by packed row) --}}
        @foreach($board['tiles'] as $tile)
          @php($r = $rowById->get((int) $tile['id']))
          @php($s = $r['model'] ?? null)
          @php($left = $PADX + (int) $tile['start'] * $DAYW)
          @php($floorW = max(128, (int) $tile['dur'] * $DAYW - 4))
          @php($cap = $capRight[(int) $tile['id']] ?? null)
          @php($maxW = $cap !== null ? max(46, ($PADX + (int) $cap * $DAYW) - $left - 4) : $floorW)
          @php($width = (int) min($floorW, $maxW))
          @php($top = $ROWTOP + (int) $tile['row'] * $ROWH)
          @php($terminal = $s ? in_array($s->status, ['completed', 'skipped'], true) : false)
          @php($isDone = $s && $s->status === 'completed')
          @php($isNa = $s && $s->status === 'skipped' && ! empty($s->na_reason))
          @php($cc = $commentCount[(int) $tile['id']] ?? 0)
          <div class="ttile {{ $tile['status'] }}" style="left:{{ $left }}px;width:{{ $width }}px;top:{{ $top }}px;"
               data-step-id="{{ $tile['id'] }}"
               @if($s && !$locked) x-data="{done:false,due:false,seq:false,na:false,cm:false}" @endif>
            <div class="th">
              <span class="dot {{ $tile['status'] }}"></span>
              <span class="nm" title="{{ $tile['name'] }}">{{ $tile['name'] }}</span>
              @if(!empty($tile['star']))<span class="star" title="Suspensive condition">★</span>@endif
            </div>
            <div class="sub"><span class="d">{{ (int) $tile['dur'] }}d</span> · {{ $dstr($tile['start']) }} → {{ $dstr((int) $tile['start'] + (int) $tile['dur']) }}</div>

            @if($s)
            <div class="tacts">
              {{-- 1 · Complete / Reopen --}}
              @if(!$terminal && !$locked)
                <button type="button" class="b go" @click="done=true" title="Mark done (set the actual date)">✓ Complete</button>
              @elseif($isDone && !$locked)
                <form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b">↺ Reopen</button></form>
              @endif
              {{-- 2 · Edit due --}}
              @unless($locked)<button type="button" class="b" @click="due=true">Edit dates</button>@endunless
              {{-- 3 · Sequence --}}
              @unless($locked)<button type="button" class="b seq" @click="seq=true" title="Change which step this follows + offset">Sequence</button>@endunless
              {{-- 4 · N/A / Reinstate --}}
              @if(!$terminal && !$locked)
                <button type="button" class="b" @click="na=true">N/A</button>
              @elseif($isNa && !$locked)
                <form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b">Reinstate</button></form>
              @endif
              {{-- 5 · Comments --}}
              <button type="button" class="b" @click="cm=true">💬 {{ $cc }}</button>
              {{-- 6 · Remove --}}
              @unless($locked)<form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b rm">Remove</button></form>@endunless
            </div>

            {{-- ── action modals (teleported to body so a scrolling canvas never clips them) ── --}}
            @unless($locked)
              @if(!$terminal)
              <template x-teleport="body"><div class="dr2-modal" x-show="done" x-cloak @keydown.escape.window="done=false">
                <div class="dr2-modal__bg" @click="done=false"></div>
                <div class="dr2-modal__card">
                  <h4 class="dr2-modal__h">Complete “{{ $s->name }}”</h4>
                  <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="timeline">
                    <label class="dr2-modal__lb">Actually done on
                      <input type="date" name="actual_date" value="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}" class="corex-input">
                    </label>
                    <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="done=false">Cancel</button><button type="submit" class="corex-btn-primary">Mark done</button></div>
                  </form>
                </div>
              </div></template>
              @endif

              <template x-teleport="body"><div class="dr2-modal" x-show="due" x-cloak @keydown.escape.window="due=false">
                <div class="dr2-modal__bg" @click="due=false"></div>
                <div class="dr2-modal__card">
                  <h4 class="dr2-modal__h">Due date — “{{ $s->name }}”</h4>
                  <form method="POST" action="{{ route('deals-dr2.pipeline.step.due', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="timeline">
                    <input type="date" name="due_date" value="{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('Y-m-d') : '' }}" class="corex-input">
                    <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="due=false">Cancel</button><button type="submit" class="corex-btn-primary">Save due date</button></div>
                  </form>
                </div>
              </div></template>

              <template x-teleport="body"><div class="dr2-modal" x-show="seq" x-cloak @keydown.escape.window="seq=false">
                <div class="dr2-modal__bg" @click="seq=false"></div>
                <div class="dr2-modal__card">
                  <h4 class="dr2-modal__h">Sequence — “{{ $s->name }}”</h4>
                  <form method="POST" action="{{ route('deals-dr2.pipeline.step.follows', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="timeline">
                    <label class="dr2-modal__lb">Follows
                      <select name="follows" class="corex-input">
                        <option value="">— nothing (starts on the deal date) —</option>
                        @foreach($steps as $r2)
                          @php($o = $r2['model'])
                          @if($o->id !== $s->id)
                          <option value="{{ $o->id }}" {{ (int) $s->trigger_step_instance_id === (int) $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
                          @endif
                        @endforeach
                      </select>
                    </label>
                    <label class="dr2-modal__lb">+ offset (days)
                      <input type="number" name="offset" min="0" max="3650" value="{{ (int) $s->days_offset }}" class="corex-input" style="width:6rem;">
                    </label>
                    <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="seq=false">Cancel</button><button type="submit" class="corex-btn-primary">Save sequence</button></div>
                  </form>
                </div>
              </div></template>

              @unless($terminal)
              <template x-teleport="body"><div class="dr2-modal" x-show="na" x-cloak @keydown.escape.window="na=false">
                <div class="dr2-modal__bg" @click="na=false"></div>
                <div class="dr2-modal__card">
                  <h4 class="dr2-modal__h">Mark N/A — “{{ $s->name }}”</h4>
                  <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}">@csrf<input type="hidden" name="from" value="timeline">
                    <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="width:100%;">
                    <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="na=false">Cancel</button><button type="submit" class="corex-btn-primary">Mark N/A</button></div>
                  </form>
                </div>
              </div></template>
              @endunless
            @endunless

            {{-- Comments (survives the lock) --}}
            <template x-teleport="body"><div class="dr2-modal" x-show="cm" x-cloak @keydown.escape.window="cm=false">
              <div class="dr2-modal__bg" @click="cm=false"></div>
              <div class="dr2-modal__card dr2-modal__card--wide">
                <h4 class="dr2-modal__h">Comments — “{{ $s->name }}”</h4>
                <div class="dr2-modal__thread">
                  @forelse($s->comments as $c)
                    <div class="dr2-cmt"><span>{{ $c->body }}</span><span class="dr2-cmt__by"> — {{ $c->user->name ?? 'Someone' }}, {{ $c->created_at?->format('d M H:i') }}</span></div>
                  @empty
                    <div class="dr2-cmt__empty">No comments yet.</div>
                  @endforelse
                </div>
                <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" class="dr2-modal__cmform">@csrf<input type="hidden" name="from" value="timeline">
                  <input type="text" name="body" placeholder="Add a note for this step…" required class="corex-input" style="flex:1 1 220px;">
                  <button type="submit" class="corex-btn-secondary">Post</button>
                </form>
                <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="cm=false">Close</button></div>
              </div>
            </div></template>
            @endif

            @if($cc)<span class="pin" title="{{ $cc }} comment(s)">{{ $cc }}</span>@endif
          </div>
        @endforeach

        {{-- comments track — pins positioned by the date each note was made --}}
        <div class="ctrack-line" style="top:{{ $trackY }}px;width:{{ $W }}px;"></div>
        <div class="ctrack-lbl" style="top:{{ $trackY + 4 }}px;">💬 Comments</div>
        @foreach($board['comments'] as $c)
          <div class="cpin {{ ($c['scope'] ?? '') === 'step' ? 'step' : 'deal' }}" style="left:{{ $PADX + (int) ($c['day'] ?? 0) * $DAYW }}px;top:{{ $trackY + 20 }}px;"
               title="{{ $c['who'] }} · {{ $c['when'] }} — {{ \Illuminate\Support\Str::limit($c['text'], 140) }}"><span class="stem"></span>{{ \Illuminate\Support\Str::substr($c['who'] ?? '?', 0, 1) }}</div>
        @endforeach

      </div>
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
    if(filter==='deal') list=list.filter(c=>c.scope==='deal'||c.target===ANCHOR);
    else if(filter==='step'&&stepTarget) list=list.filter(c=>c.target===stepTarget);
    scope.innerHTML='Showing: <b>'+(filter==='step'?'this step':filter)+'</b>';
    feed.innerHTML=list.length?'':'<div style="color:#94a3b8;font-size:12px;padding:8px 0">No comments yet.</div>';
    list.slice().reverse().forEach(c=>{
      const deal=(c.scope==='deal'||c.target===ANCHOR);
      const cm=document.createElement('div');cm.className='cm';
      cm.innerHTML='<div class="av">'+esc((c.who||'?')[0])+'</div><div><div class="cmeta"><b>'+esc(c.who)+'</b> · '+esc(c.when)+
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
