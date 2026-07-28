{{-- Shared Timeline step action row + teleported action modals. Used by BOTH the on-axis duration
     tile AND the Unscheduled tray card, so a step's full action set (Complete/Reopen · Edit dates ·
     Sequence · N/A/Reinstate · Comments · Remove) is identical wherever the step is shown — undated
     steps in the tray keep every action they had before the redesign (spec CORRECTION 2026-07-28).
     Expects in scope: $s (DealStepInstance), $cc (int comment count), $deal, $locked, $steps.
     The enclosing element MUST carry x-data="{done:false,due:false,seq:false,na:false,cm:false}". --}}
@php($terminal = in_array($s->status, ['completed', 'skipped'], true))
@php($isDone = $s->status === 'completed')
@php($isNa = $s->status === 'skipped' && ! empty($s->na_reason))
<div class="tacts">
  {{-- 1 · Complete / Reopen --}}
  @if(!$terminal && !$locked)
    <button type="button" class="b go" @click="done=true" title="Mark done (set the actual date)">✓ Complete</button>
  @elseif($isDone && !$locked)
    <form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf<input type="hidden" name="from" value="timeline"><button type="submit" class="b">↺ Reopen</button></form>
  @endif
  {{-- 2 · Edit dates --}}
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
