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
  /* zoom control — Month/Week/Day pixels-per-day levels, rescales the axis + every tile together */
  #dr2tl .zoomctl{display:inline-flex;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:11.5px;margin-left:auto}
  #dr2tl .zbtn{padding:5px 11px;border:0;border-left:1px solid #e2e8f0;background:#fff;color:#374151;cursor:pointer;font-family:inherit;font-weight:600}
  #dr2tl .zbtn:first-child{border-left:0}
  #dr2tl .zbtn:hover{background:#f1f5f9}
  #dr2tl .zbtn.on{background:#0f172a;color:#fff}
  #dr2tl .legend{display:flex;gap:13px;font-size:11px;color:#64748b;align-items:center;flex-wrap:wrap}
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
  #dr2tl .ttile{position:absolute;z-index:3;background:#fff;border:1px solid #e2e8f0;border-radius:10px;height:136px;
    padding:6px 9px;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(15,23,42,.10);overflow:hidden}
  #dr2tl .ttile:hover{z-index:8;box-shadow:0 6px 16px rgba(37,99,235,.20)}
  #dr2tl .ttile::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:10px 0 0 10px}
  #dr2tl .ttile.active::before{background:#2563eb}#dr2tl .ttile.done::before{background:#16a34a}#dr2tl .ttile.upcoming::before{background:#cbd5e1}
  #dr2tl .th{display:flex;align-items:flex-start;gap:6px}
  #dr2tl .th .dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;margin-top:4px}
  #dr2tl .th .dot.active{background:#2563eb}#dr2tl .th .dot.done{background:#16a34a}#dr2tl .th .dot.upcoming{background:#cbd5e1}
  /* name wraps up to 2 lines (clamped) instead of single-line-ellipsis truncating — "Deposit Paym…" */
  #dr2tl .nm{font-weight:700;font-size:12px;line-height:1.25;color:#1e293b;white-space:normal;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
  #dr2tl .ttile.done .nm{color:#8a97a8;text-decoration:line-through;text-decoration-color:#cbd5e1}
  #dr2tl .star{color:#eab308;flex:0 0 auto;font-size:11px;margin-top:2px}
  /* dates line — allowed to wrap rather than being clipped mid-date */
  #dr2tl .sub{font-size:10.5px;color:#94a3b8;margin:2px 0 0 14px;white-space:normal;overflow:visible}
  #dr2tl .sub .d{color:#64748b;font-weight:700}
  /* actions WRAP onto multiple lines instead of forcing an internal horizontal scrollbar */
  #dr2tl .tacts{display:flex;gap:4px;row-gap:3px;margin-top:auto;flex-wrap:wrap;overflow:visible}
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

  /* persistent Unscheduled tray — bounded, scrolls internally (Johan's "set area, scrolls inside") */
  #dr2tl .utray-wrap{flex:0 0 auto;margin:8px 15px 0;border:1px dashed #fcd34d;background:#fffbeb;border-radius:10px;overflow:hidden;display:flex;flex-direction:column;max-height:min(44vh,420px)}
  #dr2tl .utray-h{padding:7px 12px;font-size:11.5px;color:#92400e;border-bottom:1px dashed #fde68a;flex:0 0 auto}
  #dr2tl .utray-h b{color:#78350f}
  #dr2tl .utray{flex:1;min-height:0;overflow-y:auto;overscroll-behavior:contain;display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;align-content:flex-start}
  #dr2tl .ucard{position:relative;flex:1 1 240px;max-width:340px;min-width:220px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px 8px 13px;box-shadow:0 1px 2px rgba(15,23,42,.05)}
  #dr2tl .ucard::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:10px 0 0 10px}
  #dr2tl .ucard.active::before{background:#2563eb}#dr2tl .ucard.done::before{background:#16a34a}#dr2tl .ucard.upcoming::before{background:#cbd5e1}
  #dr2tl .ucard .uh{display:flex;align-items:center;gap:6px}
  #dr2tl .ucard .usub{font-size:10.5px;color:#94a3b8;margin:3px 0 5px 14px}
  #dr2tl .ucard.done .nm{color:#8a97a8;text-decoration:line-through;text-decoration-color:#cbd5e1}
  #dr2tl .ubadge{font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:11px;background:#f1f5f9;color:#64748b}
  #dr2tl .ubadge.active{background:#dbeafe;color:#1d4ed8}#dr2tl .ubadge.done{background:#d1fae5;color:#047857}
  #dr2tl .ucard .tacts{margin-top:4px}
  /* draggable dated tile affordance */
  #dr2tl .ttile.draggable{cursor:grab}
  #dr2tl .ttile.dragging{cursor:grabbing;z-index:9;box-shadow:0 8px 20px rgba(37,99,235,.28);opacity:.95}

  /* projected tiles — start inferred (due − offset), not yet committed: dashed border + a small tag */
  #dr2tl .ttile.projected{border-style:dashed;border-color:#c7d2fe;background:#fcfdff}
  #dr2tl .ttile.projected::before{opacity:.5}
  #dr2tl .projtag{font-size:8px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#6366f1;background:#eef2ff;border:1px solid #e0e7ff;border-radius:5px;padding:1px 4px;flex:0 0 auto}
  /* 📅 set-dates control (header, right-aligned) */
  #dr2tl .th .setdates{margin-left:auto;flex:0 0 auto;border:1px solid #e2e8f0;background:#fff;border-radius:5px;font-size:10px;line-height:1;padding:2px 5px;cursor:pointer}
  #dr2tl .th .setdates:hover{background:#eff6ff;border-color:#bfdbfe}
  /* edge-drag resize handles (dated, non-terminal tiles only) */
  #dr2tl .rhandle{position:absolute;top:0;bottom:0;width:8px;z-index:6;cursor:ew-resize;touch-action:none;background:transparent;display:flex;align-items:center;justify-content:center}
  #dr2tl .rhandle.rleft{left:0;border-radius:10px 0 0 10px}
  /* right handle: ALWAYS visible (not hover-only) — a fixed 8px grab zone with a grip mark, present on
     every resizable tile regardless of width, so it's consistently findable instead of an invisible
     hover-only strip a narrow tile could hide entirely */
  #dr2tl .rhandle.rright{right:0;border-radius:0 10px 10px 0;background:rgba(37,99,235,.08);border-left:1px solid rgba(37,99,235,.16)}
  #dr2tl .rhandle.rright::after{content:"";width:2px;height:20px;border-radius:1px;background:rgba(37,99,235,.5)}
  #dr2tl .ttile:hover .rhandle{background:rgba(37,99,235,.14)}
  #dr2tl .rhandle:hover{background:rgba(37,99,235,.32)!important}
  #dr2tl .rhandle.rright:hover::after{background:#2563eb}
  #dr2tl .ttile.resizing{z-index:9;box-shadow:0 8px 20px rgba(37,99,235,.28)}
  #dr2tl .ed-note{font-size:11px;color:#4f46e5;background:#eef2ff;border-radius:7px;padding:6px 9px;margin-top:2px;line-height:1.35}

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
{{-- ROWH must stay ≥ the .ttile CSS height (136px) + a gap, so taller wrapped-content tiles never
     overlap the row stacked beneath them. --}}
@php($ROWH = 152)
@php($bandLabelTop = max(2, $ROWTOP - 34 - 15))
<div id="dr2tl" data-comment="{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}" data-csrf="{{ csrf_token() }}"
     data-base="{{ $board['base_date'] ?? '' }}" data-dayw="{{ $DAYW }}" data-padx="{{ $PADX }}"
     data-days="{{ (int) ($board['days'] ?? 0) }}" data-deal="{{ $deal->id }}">

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

  {{-- Collapsible deal-context tabs — on TOP. DEFAULT (Johan's rule): a deal with NO structure/pipeline
       yet opens EXPANDED (prompt the user to set up the deal structure); once structure is captured
       ($hasPipeline) it opens COLLAPSED to save vertical space. The user's own expand/collapse is then
       sticky per-deal (localStorage) and never forced back on later loads. Matches the list, whose right
       pane already defaults to the Structure tab when the deal has no structure. --}}
  <div class="ctabs"
       x-data="{ open: (localStorage.getItem('dr2_panels_open_{{ $deal->id }}') || '{{ ($hasPipeline ?? false) ? '0' : '1' }}') === '1' }"
       x-init="$watch('open', v => localStorage.setItem('dr2_panels_open_{{ $deal->id }}', v ? '1' : '0'))">
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
  @php($dstrIso = fn ($d) => $base->copy()->addDays((int) $d)->format('Y-m-d'))
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
  {{-- Resolve each comment's target step id to its real name (same pattern as the List's $stepLabel) —
       the feed otherwise had nothing but the literal "STEP" tag with no indication of WHICH step. --}}
  @php($stepLabel = function ($target) use ($rowById) {
        if ($target === 'deal' || $target === null) return 'Deal';
        return $rowById->has((int) $target) ? $rowById[(int) $target]['model']->name : 'Deal';
      })
  @php($commentsResolved = collect($board['comments'] ?? [])->map(function ($c) use ($stepLabel) {
        return $c + ['step_name' => $stepLabel($c['target'] ?? null)];
      })->all())

  <div class="mid">
    <div class="midbar">
      <span class="t">Timeline</span>
      <span class="hint">tiles stretch to their duration · overlapping tiles stack underneath · <b>drag a tile to reschedule</b> · <b>drag an edge to resize</b> · 📅 sets exact dates · dashed = projected · 💬 marks comments on the date made</span>
      <div class="zoomctl" role="group" aria-label="Zoom">
        <button type="button" class="zbtn" data-zoom="month">Month</button>
        <button type="button" class="zbtn" data-zoom="week">Week</button>
        <button type="button" class="zbtn" data-zoom="day">Day</button>
      </div>
      <div class="legend">
        <span class="lg"><span class="sw done"></span>Done</span>
        <span class="lg"><span class="sw active"></span>Active</span>
        <span class="lg"><span class="sw upcoming"></span>Upcoming</span>
        <span class="lg"><span class="dia"></span>Milestone</span>
      </div>
    </div>

    {{-- Persistent Unscheduled tray — every step with no start/due date sits here with its FULL action
         set, so nothing is ever dropped (spec CORRECTION 2026-07-28). Set both dates (Edit dates on the
         List, or Due date here) to move a step onto the axis above. --}}
    @if(!empty($board['unscheduled']))
      <div class="utray-wrap">
        <div class="utray-h">
          <b>⚠ Unscheduled — {{ count($board['unscheduled']) }} step{{ count($board['unscheduled']) === 1 ? '' : 's' }} with no start date.</b>
          They keep every action; set a start &amp; end (Deal Structure or the List view) to place them on the timeline above.
        </div>
        <div class="utray">
          @foreach($board['unscheduled'] as $u)
            @php($r = $rowById->get((int) $u['id']))
            @php($s = $r['model'] ?? null)
            @if($s)
              @php($usc = $s->status === 'completed' ? 'done' : ($s->status === 'active' ? 'active' : 'upcoming'))
              @php($cc = $s->comments->count())
              <div class="ucard {{ $usc }}" data-step-id="{{ $s->id }}"
                   @unless($locked) x-data="{done:false,seq:false,na:false,cm:false,ed:false}" @endunless>
                <div class="uh">
                  <span class="dot {{ $usc }}"></span>
                  <span class="nm" title="{{ $s->name }}">{{ $s->name }}</span>
                  @if($s->is_milestone)<span class="star" title="Milestone">★</span>@endif
                </div>
                <div class="usub"><span class="ubadge {{ $usc }}">{{ ucfirst(str_replace('_', ' ', $s->status)) }}</span> · no dates set</div>
                @include('dr2._pipeline-timeline-actions', ['s' => $s, 'cc' => $cc])
              </div>
            @endif
          @endforeach
        </div>
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
          <div class="band {{ $i % 2 ? 'alt' : '' }}" data-from="{{ (int) $p['from'] }}" data-to="{{ (int) $p['to'] }}"
               style="left:{{ $PADX + $p['from'] * $DAYW }}px;width:{{ $bandW }}px;">
            @if($bandW >= 118)<span class="bname" style="top:{{ $bandLabelTop }}px;max-width:{{ $bandW - 14 }}px;overflow:hidden;text-overflow:ellipsis;">{{ $p['name'] }}</span>@endif
          </div>
        @endforeach

        {{-- milestone diamonds --}}
        @foreach($board['miles'] as $m)
          <div class="mile {{ $m['state'] }}" data-day="{{ (int) $m['day'] }}" style="left:{{ $PADX + $m['day'] * $DAYW }}px;">
            <span class="cap"></span>
            <span class="txt" style="top:{{ 2 + (int) ($m['lvl'] ?? 0) * 15 }}px;">★ {{ $m['name'] }} · {{ $dstr($m['day']) }}</span>
          </div>
        @endforeach

        {{-- red TODAY line --}}
        @if((int) $board['today_day'] >= 0 && (int) $board['today_day'] <= $days)
          <div class="today" data-day="{{ (int) $board['today_day'] }}" style="left:{{ $PADX + (int) $board['today_day'] * $DAYW }}px;"><span class="cap">TODAY</span></div>
        @endif

        {{-- step TILES (positioned by date, width ∝ duration, stacked by packed row) --}}
        @foreach($board['tiles'] as $tile)
          @php($r = $rowById->get((int) $tile['id']))
          @php($s = $r['model'] ?? null)
          @php($left = $PADX + (int) $tile['start'] * $DAYW)
          {{-- legibility floor raised from 128 to 168 so the wrapped name/dates/actions have room without
               forcing the internal horizontal scroll the old narrow floor caused on short-duration tiles --}}
          @php($floorW = max(168, (int) $tile['dur'] * $DAYW - 4))
          @php($cap = $capRight[(int) $tile['id']] ?? null)
          @php($maxW = $cap !== null ? max(46, ($PADX + (int) $cap * $DAYW) - $left - 4) : $floorW)
          @php($width = (int) min($floorW, $maxW))
          @php($top = $ROWTOP + (int) $tile['row'] * $ROWH)
          @php($terminal = $s ? in_array($s->status, ['completed', 'skipped'], true) : false)
          @php($isDone = $s && $s->status === 'completed')
          @php($isNa = $s && $s->status === 'skipped' && ! empty($s->na_reason))
          @php($cc = $commentCount[(int) $tile['id']] ?? 0)
          @php($projected = $s && !empty($tile['projected']))
          @php($canDrag = $s && !$locked && !$terminal && !$projected)
          @php($canResize = $s && !$locked && !$terminal && !$projected)
          @php($startIso = $s && $s->planned_start_date ? $s->planned_start_date->format('Y-m-d') : $dstrIso($tile['start']))
          @php($dueIso = $s && $s->due_date ? $s->due_date->format('Y-m-d') : $dstrIso((int) $tile['start'] + (int) $tile['dur']))
          <div class="ttile {{ $tile['status'] }}{{ $projected ? ' projected' : '' }}{{ $canDrag ? ' draggable' : '' }}" style="left:{{ $left }}px;width:{{ $width }}px;top:{{ $top }}px;"
               data-step-id="{{ $tile['id'] }}" data-start="{{ (int) $tile['start'] }}" data-dur="{{ (int) $tile['dur'] }}"
               data-cap="{{ $cap === null ? '' : (int) $cap }}"
               data-startdate="{{ $startIso }}" data-due="{{ $dueIso }}"
               @if($canDrag) data-draggable="1" @endif
               @if($canResize) data-resizable="1" @endif
               @if($s && !$locked) x-data="{done:false,seq:false,na:false,cm:false,ed:false}" @endif>
            <div class="th">
              <span class="dot {{ $tile['status'] }}"></span>
              <span class="nm" title="{{ $tile['name'] }}">{{ $tile['name'] }}</span>
              @if(!empty($tile['star']))<span class="star" title="Suspensive condition">★</span>@endif
              @if($projected)<span class="projtag" title="Projected start (due date − offset) — no start date is set. Use “Edit dates” to confirm the real dates.">proj</span>@endif
            </div>
            <div class="sub"><span class="d">{{ (int) $tile['dur'] }}d</span> · {{ $dstr($tile['start']) }} → {{ $dstr((int) $tile['start'] + (int) $tile['dur']) }}{{ $projected ? ' · projected' : '' }}</div>

            @if($s)
            @include('dr2._pipeline-timeline-actions', ['s' => $s, 'cc' => $cc])
            @endif

            @if($canResize)
              <span class="rhandle rleft" title="Drag to change the start date"></span>
              <span class="rhandle rright" title="Drag to change the end date"></span>
            @endif

            @if($cc)<span class="pin" title="{{ $cc }} comment(s)">{{ $cc }}</span>@endif
            {{-- The both-dates "Edit dates" control now lives in the shared _pipeline-timeline-actions
                 partial (one date control, used identically by on-axis tiles AND Unscheduled tray cards). --}}
          </div>
        @endforeach

        {{-- comments track — pins positioned by the date each note was made --}}
        <div class="ctrack-line" style="top:{{ $trackY }}px;width:{{ $W }}px;"></div>
        <div class="ctrack-lbl" style="top:{{ $trackY + 4 }}px;">💬 Comments</div>
        @foreach($commentsResolved as $c)
          <div class="cpin {{ ($c['scope'] ?? '') === 'step' ? 'step' : 'deal' }}" data-day="{{ (int) ($c['day'] ?? 0) }}" style="left:{{ $PADX + (int) ($c['day'] ?? 0) * $DAYW }}px;top:{{ $trackY + 20 }}px;"
               title="{{ $c['step_name'] }} · {{ $c['who'] }} · {{ $c['when'] }} — {{ \Illuminate\Support\Str::limit($c['text'], 140) }}"><span class="stem"></span>{{ \Illuminate\Support\Str::substr($c['who'] ?? '?', 0, 1) }}</div>
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
  const COMMENTS=@json($commentsResolved);
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
        '<span class="ctag '+(deal?'deal':'')+'">'+esc((c.step_name||(deal?'Deal':'Step')).toUpperCase())+'</span></div><div class="ctxt">'+esc(c.text)+'</div></div>';
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

  // ---- shared date/scale state for drag / resize / zoom. DAYW is `let`, not `const` — zoom reassigns
  //      it, and every closure below reads it live (JS closures capture `let` bindings by reference), so
  //      a drag/resize started after a zoom change uses the CURRENT pixels-per-day, not the page-load one.
  const BASE = root.dataset.base ? new Date(root.dataset.base+'T00:00:00') : null;
  const PADX = parseInt(root.dataset.padx)||14;
  let DAYW = parseInt(root.dataset.dayw)||21;

  if (BASE) {
    const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const addDays=n=>{const d=new Date(BASE.getTime());d.setDate(d.getDate()+n);return d;};
    const fmtShort=n=>{const d=addDays(n);return d.getDate()+' '+MON[d.getMonth()];};
    const isoDay=n=>{const d=addDays(n);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');};
    const xForDay=n=>PADX+n*DAYW;
    const postJson=(url,body)=>fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},credentials:'same-origin',body:JSON.stringify(body)}).then(r=>r.json());
    const postDates=(stepId,startIso,dueIso)=>fetch(CBASE+'/'+stepId+'/dates',{method:'POST',redirect:'manual',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},credentials:'same-origin',body:JSON.stringify({planned_start_date:startIso,due_date:dueIso})});

    // ---- drag a DATED tile horizontally to reschedule (spec CORRECTION 2026-07-28). Rides the EXISTING
    //      reschedule endpoint: preview (commit:false) → confirm → commit:true → reload; the service
    //      preserves the tile's duration and cascades downstream. ONLY already-dated tiles are draggable —
    //      undated steps live in the Unscheduled tray and are NOT drag-schedulable (that first-time-schedule
    //      extension needs Johan's sign-off; the reschedule service throws for a null-start step). ----
    root.querySelectorAll('.ttile[data-draggable="1"]').forEach(el=>{
      el.addEventListener('pointerdown', e=>{
        if(e.button!==0) return;
        if(e.target.closest('button,form,a,input,select,label,.tacts,template,.rhandle')) return;
        const stepId=el.dataset.stepId, origStart=parseInt(el.dataset.start)||0, dur=parseInt(el.dataset.dur)||1;
        const name=((el.querySelector('.nm')||{}).textContent||'step').trim();
        const sub=el.querySelector('.sub'), subHtml=sub?sub.innerHTML:'';
        let moved=false, ns=origStart;
        const sx=e.clientX;
        el.classList.add('dragging');
        try{el.setPointerCapture(e.pointerId);}catch(_){}
        const revert=()=>{el.style.left=xForDay(origStart)+'px';if(sub)sub.innerHTML=subHtml;};
        const move=ev=>{const dd=Math.round((ev.clientX-sx)/DAYW);if(dd!==0)moved=true;ns=Math.max(0,origStart+dd);el.style.left=xForDay(ns)+'px';if(sub)sub.innerHTML='<span class="d">'+dur+'d</span> · '+fmtShort(ns)+' → '+fmtShort(ns+dur);};
        const up=()=>{
          el.classList.remove('dragging');
          try{el.releasePointerCapture(e.pointerId);}catch(_){}
          el.removeEventListener('pointermove',move);el.removeEventListener('pointerup',up);el.removeEventListener('pointercancel',up);
          if(!moved||ns===origStart){revert();return;}
          const url=CBASE+'/'+stepId+'/reschedule', newStart=isoDay(ns);
          postJson(url,{new_start:newStart,commit:false}).then(res=>{
            if(!res||!res.ok){toast((res&&res.error)||'Could not reschedule.');revert();return;}
            const mv=res.moved||[], held=res.held||[], others=mv.filter(m=>!m.is_dragged).length;
            let msg='Reschedule “'+name+'” to '+newStart+'?';
            if(others>0)msg+='\n\n'+others+' downstream step'+(others===1?'':'s')+' will cascade to keep the sequence.';
            if(held.length)msg+='\n'+held.length+' step'+(held.length===1?'':'s')+' held (date set by hand / done): '+held.map(h=>h.name).join(', ');
            if(!window.confirm(msg)){revert();return;}
            postJson(url,{new_start:newStart,commit:true}).then(r2=>{
              if(!r2||!r2.ok){toast((r2&&r2.error)||'Could not save the new date.');revert();return;}
              window.location.reload();
            }).catch(()=>{toast('Network error saving.');revert();});
          }).catch(()=>{toast('Network error.');revert();});
        };
        el.addEventListener('pointermove',move);el.addEventListener('pointerup',up);el.addEventListener('pointercancel',up);
        e.preventDefault();
      });
    });

    // ---- edge-drag RESIZE (dated, non-terminal tiles only): grab a tile's left/right edge to change its
    //      start / end. Persisted through the EXISTING editDates route (POST .../dates) then a reload — no
    //      new endpoint, no shared file touched. Projected/undated tiles are NOT edge-resizable; they use
    //      the 📅 Set-dates modal to commit real dates. ----
    root.querySelectorAll('.ttile[data-resizable="1"]').forEach(el=>{
      const stepId=el.dataset.stepId;
      const origStart=parseInt(el.dataset.start)||0, origDur=parseInt(el.dataset.dur)||1;
      const sub=el.querySelector('.sub'); const subHtml=sub?sub.innerHTML:'';
      el.querySelectorAll('.rhandle').forEach(h=>{
        const isRight=h.classList.contains('rright');
        h.addEventListener('pointerdown', e=>{
          if(e.button!==0) return; e.stopPropagation(); e.preventDefault();
          // Read the CURRENT on-screen box fresh (not captured once at page-load) — a zoom change since
          // load has already rewritten el.style.left/width, and revert() must snap back to THAT, not a
          // stale pre-zoom position.
          const baseLeft=parseInt(el.style.left)||xForDay(origStart), baseW=parseInt(el.style.width)||(origDur*DAYW-4);
          let ns=origStart, ne=origStart+origDur, moved=false; const sx=e.clientX;
          el.classList.add('resizing');
          try{h.setPointerCapture(e.pointerId);}catch(_){}
          const revert=()=>{el.style.left=baseLeft+'px';el.style.width=baseW+'px';if(sub)sub.innerHTML=subHtml;};
          const move=ev=>{
            const dd=Math.round((ev.clientX-sx)/DAYW); if(dd!==0)moved=true;
            if(isRight){ ne=Math.max(origStart+1, origStart+origDur+dd); }
            else { ns=Math.max(0, Math.min(origStart+origDur-1, origStart+dd)); }
            el.style.left=xForDay(ns)+'px'; el.style.width=Math.max(46,(ne-ns)*DAYW-4)+'px';
            if(sub)sub.innerHTML='<span class="d">'+(ne-ns)+'d</span> · '+fmtShort(ns)+' → '+fmtShort(ne);
          };
          const up=()=>{
            el.classList.remove('resizing');
            try{h.releasePointerCapture(e.pointerId);}catch(_){}
            h.removeEventListener('pointermove',move);h.removeEventListener('pointerup',up);h.removeEventListener('pointercancel',up);
            if(!moved || (ns===origStart && ne===origStart+origDur)){ revert(); return; }
            postDates(stepId, isoDay(ns), isoDay(ne)).then(r=>{
              if(r && r.status===422){ r.json().then(j=>toast((j&&j.message)||'Could not save the new dates.')).catch(()=>toast('Could not save the new dates.')); revert(); return; }
              window.location.reload();
            }).catch(()=>{ toast('Network error saving dates.'); revert(); });
          };
          h.addEventListener('pointermove',move);h.addEventListener('pointerup',up);h.addEventListener('pointercancel',up);
        });
      });
    });

    // ---- ZOOM — Month/Week/Day pixels-per-day levels. Every positioned element (ticks, bands,
    //      milestones, today line, tiles, comment pins) carries its position as a plain day-index data
    //      attribute, so a zoom change is a pure client-side re-layout from those — no reload, no server
    //      round-trip. Reassigning DAYW here is what makes the drag/resize closures above pick up the new
    //      scale on their NEXT interaction (they read the shared `let DAYW`, not a frozen copy). ----
    const ZOOM_LEVELS = {month:7, week:21, day:50};
    const ZDAYS = parseInt(root.dataset.days)||0;
    const canvasEl = root.querySelector('.canvas'), axisEl = root.querySelector('.axis');
    const dealId = root.dataset.deal;
    const tickInterval = dayw => Math.max(1, Math.round(140/dayw));

    function rebuildAxis(){
      if(!axisEl) return;
      axisEl.querySelectorAll('.tick').forEach(t=>t.remove());
      const step=tickInterval(DAYW);
      for(let d=0; d<=ZDAYS; d+=step){
        const t=document.createElement('div'); t.className='tick';
        t.style.left=xForDay(d)+'px'; t.textContent=fmtShort(d);
        axisEl.appendChild(t);
      }
    }

    function applyZoom(level){
      DAYW = ZOOM_LEVELS[level] || ZOOM_LEVELS.week;
      root.dataset.dayw = String(DAYW);
      const w = PADX + ZDAYS*DAYW + 40;
      if(canvasEl) canvasEl.style.width = w+'px';
      if(axisEl) axisEl.style.width = w+'px';
      rebuildAxis();

      root.querySelectorAll('.band').forEach(b=>{
        const from=parseInt(b.dataset.from)||0, to=parseInt(b.dataset.to)||0;
        b.style.left=xForDay(from)+'px'; b.style.width=Math.max(0,(to-from)*DAYW)+'px';
      });
      root.querySelectorAll('.mile').forEach(m=>{ m.style.left=xForDay(parseInt(m.dataset.day)||0)+'px'; });
      const todayEl=root.querySelector('.today');
      if(todayEl) todayEl.style.left=xForDay(parseInt(todayEl.dataset.day)||0)+'px';

      // same floor/cap formula as the server-side render (PipelineTimelineService's blade) — kept in
      // sync so a tile is never narrower under JS re-layout than the initial server paint would give it.
      root.querySelectorAll('.ttile[data-start]').forEach(el=>{
        const start=parseInt(el.dataset.start)||0, dur=parseInt(el.dataset.dur)||1;
        const capRaw=el.dataset.cap, cap=(capRaw===''||capRaw===undefined)?null:parseInt(capRaw);
        const left=xForDay(start);
        const floorW=Math.max(168, dur*DAYW-4);
        const maxW = cap!==null ? Math.max(46,xForDay(cap)-left-4) : floorW;
        el.style.left=left+'px'; el.style.width=Math.min(floorW,maxW)+'px';
      });
      root.querySelectorAll('.cpin').forEach(p=>{ p.style.left=xForDay(parseInt(p.dataset.day)||0)+'px'; });

      root.querySelectorAll('.zbtn').forEach(b=>b.classList.toggle('on', b.dataset.zoom===level));
      try{ if(dealId) localStorage.setItem('dr2tl_zoom_'+dealId, level); }catch(_){}
    }

    root.querySelectorAll('.zbtn').forEach(b=>b.addEventListener('click', ()=>applyZoom(b.dataset.zoom)));

    let initialZoom='week';
    try{ if(dealId){ const saved=localStorage.getItem('dr2tl_zoom_'+dealId); if(saved && ZOOM_LEVELS[saved]) initialZoom=saved; } }catch(_){}
    applyZoom(initialZoom);
  }

  // Direct SET-DATES from the 📅 modal (dated OR projected tiles). Posts both dates to the existing
  // editDates route, then reloads the timeline. The 302→list redirect is not followed (redirect:manual).
  window.dr2tlSaveDates=function(ev, stepId){
    ev.preventDefault();
    const form=ev.target;
    const start=form.querySelector('input[name=planned_start_date]').value;
    const due=form.querySelector('input[name=due_date]').value;
    if(!start||!due){ toast('Set both a start and an end date.'); return false; }
    if(due<start){ toast('The end date must be on or after the start.'); return false; }
    fetch(CBASE+'/'+stepId+'/dates',{method:'POST',redirect:'manual',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF},credentials:'same-origin',body:JSON.stringify({planned_start_date:start,due_date:due})})
      .then(r=>{ if(r && r.status===422){ r.json().then(j=>toast((j&&j.message)||'Could not save the dates.')).catch(()=>toast('Could not save the dates.')); return; } window.location.reload(); })
      .catch(()=>toast('Network error saving dates.'));
    return false;
  };
})();
</script>
@endpush
