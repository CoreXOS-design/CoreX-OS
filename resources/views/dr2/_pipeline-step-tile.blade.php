{{--
    AT-334 (concurrent-lanes rework) — one UNIFORM DR2 pipeline step TILE.

    Every tile is the identical fixed size with a FIXED 3×2 action-button grid pinned to the
    bottom, so buttons line up straight across the whole board. Buttons (same 6, same order,
    every tile): Complete · Edit due · Sequence · N/A · Remove · Comments. A completed tile
    keeps the EXACT same footprint — Complete→Reopen, N/A disabled — greyed with a green ✓ +
    the actual completion date + a LIGHT strikethrough on the name. The expandable action
    panels render as FIXED-position modals (escape any overflow-x clipping inside a lane
    band). NEW-MODEL DEALS ONLY — old-model deals keep the flat _pipeline-step-row render.

    Params: row (required, mapped [model,rag,colour,blocked,na]); variant ('lane' default |
    'wide' = full-width sequence/anchor | 'gate' = full-width Granted bar). Inherits $deal,
    $steps, $locked, $statusStyles, $awaitingStepIds from the parent view.
--}}
@php($s = $row['model'])
@php($variant = $variant ?? 'lane')
@php($terminal = in_array($s->status, ['completed', 'skipped'], true))
@php($isDone = $s->status === 'completed')
@php($isNa = $row['na'])
@php($badge = $isNa ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
@php($awaiting = in_array((int) $s->id, $awaitingStepIds ?? [], true))
@php($actualDone = $s->actual_date ?? $s->completed_at)
@php($wide = in_array($variant, ['wide', 'gate'], true))
<div class="dr2-tile {{ $wide ? 'dr2-tile--wide' : '' }} {{ $variant === 'gate' ? 'dr2-tile--gate' : '' }} {{ $isDone ? 'dr2-tile--done' : '' }} {{ $isNa ? 'dr2-tile--na' : '' }} {{ $awaiting ? 'dr2-tile--warn' : '' }}"
     data-step-id="{{ $s->id }}" data-step-name="{{ $s->name }}" data-drop-follows="{{ $s->id }}"
     data-offset="{{ (int) $s->days_offset }}"
     data-follows-url="{{ route('deals-dr2.pipeline.step.follows', [$deal, $s]) }}"
     x-data="{ na:false, due:false, seq:false, done:false, cm:false }">

    {{-- HEAD: RAG dot · name (+ tags) --}}
    <div class="dr2-tile__head">
        @unless($locked)<span class="dr2-tile__grip" title="Drag to relink" aria-hidden="true">⠿</span>@endunless
        <span class="dr2-tile__rag" title="{{ ucfirst($row['rag']) }}" style="background:{{ $row['colour'] }};"></span>
        <span class="dr2-tile__name {{ $isDone ? 'dr2-tile__name--done' : '' }} {{ $isNa ? 'dr2-tile__name--na' : '' }}">
            @if($isDone)<span class="dr2-tile__check" title="Completed">✓</span>@endif
            {{ $s->name }}
        </span>
    </div>

    {{-- tags row (offset · milestone · lock · custom) --}}
    <div class="dr2-tile__tags">
        @if((int) $s->days_offset > 0)<span class="dr2-tag dr2-tag--off" title="Offset after the step it follows">+{{ (int) $s->days_offset }}d</span>@endif
        @if($s->is_milestone)<span class="dr2-tag dr2-tag--ms" title="Milestone">★</span>@endif
        @if($s->is_locked)<span title="Locked">🔒</span>@endif
        @if($s->is_custom)<span class="dr2-tag dr2-tag--custom" title="Custom step">+ custom</span>@endif
    </div>

    {{-- META: due (or actual-done) · status badge --}}
    <div class="dr2-tile__meta">
        @if($isDone && $actualDone)
            <span class="dr2-tile__date" title="Actual completion date">✓ {{ \Illuminate\Support\Carbon::parse($actualDone)->format('d M Y') }}</span>
        @else
            <span class="dr2-tile__date">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</span>
        @endif
        <span class="dr2-tile__badge" style="color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>
    </div>

    @if($variant === 'gate')<div class="dr2-tile__gatenote">Deal becomes unconditional once every condition is met.</div>@endif
    @if($awaiting)<div class="dr2-tile__warnnote">⚠ Not sent — no supplier set.</div>@elseif($row['blocked'])<div class="dr2-tile__sub">{{ $row['blocked'] }}</div>@elseif($isNa && $s->na_reason)<div class="dr2-tile__sub">Excused: {{ $s->na_reason }}</div>@endif

    {{-- FIXED 3×2 ACTION GRID (pinned to the bottom of every tile) --}}
    <div class="dr2-tile__btns">
        {{-- 1 · Complete / Reopen --}}
        @if(!$terminal && !$locked)
            @permission('view_deals')
            <button type="button" class="dr2-bt dr2-bt--go" @click="done=true" title="Mark done (set the actual date)">Complete</button>
            @else<span class="dr2-bt dr2-bt--dis">Complete</span>@endpermission
        @elseif($isDone && !$locked)
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf
                <button type="submit" class="dr2-bt">Reopen</button>
            </form>
            @else<span class="dr2-bt dr2-bt--dis">Complete</span>@endpermission
        @else<span class="dr2-bt dr2-bt--dis">Complete</span>@endif

        {{-- 2 · Edit due --}}
        @if(!$locked)
            @permission('view_deals')
            <button type="button" class="dr2-bt" @click="due=true">Edit due</button>
            @else<span class="dr2-bt dr2-bt--dis">Edit due</span>@endpermission
        @else<span class="dr2-bt dr2-bt--dis">Edit due</span>@endif

        {{-- 3 · Sequence --}}
        @if(!$locked)
            @permission('view_deals')
            <button type="button" class="dr2-bt" @click="seq=true" title="Change which step this follows + offset">Sequence</button>
            @else<span class="dr2-bt dr2-bt--dis">Sequence</span>@endpermission
        @else<span class="dr2-bt dr2-bt--dis">Sequence</span>@endif

        {{-- 4 · N/A / Reinstate --}}
        @if(!$terminal && !$locked)
            @permission('view_deals')
            <button type="button" class="dr2-bt" @click="na=true">N/A</button>
            @else<span class="dr2-bt dr2-bt--dis">N/A</span>@endpermission
        @elseif($isNa && !$locked)
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}">@csrf
                <button type="submit" class="dr2-bt">Reinstate</button>
            </form>
            @else<span class="dr2-bt dr2-bt--dis">N/A</span>@endpermission
        @else<span class="dr2-bt dr2-bt--dis">N/A</span>@endif

        {{-- 5 · Remove --}}
        @if(!$locked)
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf
                <button type="submit" class="dr2-bt dr2-bt--danger">Remove</button>
            </form>
            @else<span class="dr2-bt dr2-bt--dis">Remove</span>@endpermission
        @else<span class="dr2-bt dr2-bt--dis">Remove</span>@endif

        {{-- 6 · Comments (survives the lock) --}}
        @permission('view_deals')
        <button type="button" class="dr2-bt" @click="cm=true">Comments ({{ $s->comments->count() }})</button>
        @else<span class="dr2-bt dr2-bt--dis">Comments ({{ $s->comments->count() }})</span>@endpermission
    </div>

    {{-- ── FIXED-position action modals (never clipped by a lane band's overflow) ── --}}
    @unless($locked)
    {{-- Complete (actual date) --}}
    @if(!$terminal)
    @permission('view_deals')
    <template x-teleport="body"><div class="dr2-modal" x-show="done" x-cloak @keydown.escape.window="done=false">
        <div class="dr2-modal__bg" @click="done=false"></div>
        <div class="dr2-modal__card">
            <h4 class="dr2-modal__h">Complete “{{ $s->name }}”</h4>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                <label class="dr2-modal__lb">Actually done on
                    <input type="date" name="actual_date" value="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}" class="corex-input">
                </label>
                <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="done=false">Cancel</button><button type="submit" class="corex-btn-primary">Mark done</button></div>
            </form>
        </div>
    </div></template>
    @endpermission
    @endif

    {{-- Edit due --}}
    @permission('view_deals')
    <template x-teleport="body"><div class="dr2-modal" x-show="due" x-cloak @keydown.escape.window="due=false">
        <div class="dr2-modal__bg" @click="due=false"></div>
        <div class="dr2-modal__card">
            <h4 class="dr2-modal__h">Due date — “{{ $s->name }}”</h4>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.due', [$deal, $s]) }}">@csrf
                <input type="date" name="due_date" value="{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('Y-m-d') : '' }}" class="corex-input">
                <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="due=false">Cancel</button><button type="submit" class="corex-btn-primary">Save due date</button></div>
            </form>
        </div>
    </div></template>
    @endpermission

    {{-- Sequence (follows + offset) --}}
    @permission('view_deals')
    <template x-teleport="body"><div class="dr2-modal" x-show="seq" x-cloak @keydown.escape.window="seq=false">
        <div class="dr2-modal__bg" @click="seq=false"></div>
        <div class="dr2-modal__card">
            <h4 class="dr2-modal__h">Sequence — “{{ $s->name }}”</h4>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.follows', [$deal, $s]) }}">@csrf
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
    @endpermission

    {{-- N/A reason --}}
    @unless($terminal)
    @permission('view_deals')
    <template x-teleport="body"><div class="dr2-modal" x-show="na" x-cloak @keydown.escape.window="na=false">
        <div class="dr2-modal__bg" @click="na=false"></div>
        <div class="dr2-modal__card">
            <h4 class="dr2-modal__h">Mark N/A — “{{ $s->name }}”</h4>
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}">@csrf
                <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="width:100%;">
                <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="na=false">Cancel</button><button type="submit" class="corex-btn-primary">Mark N/A</button></div>
            </form>
        </div>
    </div></template>
    @endpermission
    @endunless
    @endunless

    {{-- Comments (survives the lock) --}}
    @permission('view_deals')
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
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" class="dr2-modal__cmform">@csrf
                <input type="text" name="body" placeholder="Add a note for this step…" required class="corex-input" style="flex:1 1 220px;">
                <button type="submit" class="corex-btn-secondary">Post</button>
            </form>
            @unless($locked)
            <form method="POST" action="{{ route('deals-dr2.documents.store', $deal) }}" enctype="multipart/form-data" class="dr2-modal__cmform">@csrf
                <input type="hidden" name="pipeline_step_id" value="{{ $s->id }}">
                <input type="file" name="file" required class="corex-input" style="flex:1 1 200px;">
                <button type="submit" class="corex-btn-outline">Attach document to this step</button>
            </form>
            @endunless
            <div class="dr2-modal__row"><button type="button" class="corex-btn-secondary" @click="cm=false">Close</button></div>
        </div>
    </div></template>
    @endpermission
</div>
