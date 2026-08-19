{{--
    AT-334 — one DR2 pipeline step row (dense one-liner + expandable per-step controls).
    Shared by the flat (old-model) and phased (new-model) layouts so every control is
    identical in both. Inherits $deal, $steps, $locked, $isNewModel, $statusStyles from the
    parent view. Params: row (required, the mapped [model,rag,colour,blocked,na]); indentPx
    (optional nesting indent); variant ('row' default | 'gate' = full-width Granted bar).
--}}
@php($s = $row['model'])
@php($badge = $row['na'] ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
@php($terminal = in_array($s->status, ['completed', 'skipped'], true))
@php($indentPx = $indentPx ?? 0)
@php($variant = $variant ?? 'row')
@php($awaiting = in_array((int) $s->id, $awaitingStepIds ?? [], true))
<div x-data="{ na:false, cm:false, due:false, seq:false, done:false }"@if($terminal) x-show="!hideDone" x-cloak @endif style="border-bottom:1px solid var(--corex-border,#e5e7eb);padding:.4rem .25rem;{{ $indentPx ? 'margin-left:'.$indentPx.'px;' : '' }}{{ $variant === 'gate' ? 'background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:.5rem .6rem;margin:.45rem 0;' : '' }}{{ $awaiting ? 'border-left:3px solid #dc2626;background:#fef2f2;' : '' }}{{ $row['na'] ? 'opacity:.6;' : '' }}">

    {{-- ONE-LINER: dot · name(+tags) · due · badge · compact inline actions --}}
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <span title="{{ ucfirst($row['rag']) }}" style="flex:0 0 auto;display:inline-block;width:.65rem;height:.65rem;border-radius:50%;background:{{ $row['colour'] }};"></span>

        <span style="flex:1 1 200px;min-width:0;{{ $row['na'] ? 'text-decoration:line-through;' : '' }}white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <strong style="font-size:{{ $variant === 'gate' ? '.95rem' : '.9rem' }};">{{ $s->name }}</strong>
            @if(($isNewModel ?? false) && (int) $s->days_offset > 0)<span title="Offset after the step it follows" style="font-size:.72rem;color:#9ca3af;margin-left:.3rem;">(+{{ (int) $s->days_offset }}d)</span>@endif
            @if($s->is_milestone && ($isNewModel ?? false))<span title="Milestone" style="color:#b45309;margin-left:.3rem;">★</span>@endif
            @if($s->is_milestone && !($isNewModel ?? false))<span title="Milestone" style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>@endif
            @if(($isNewModel ?? false) && $s->is_locked)<span title="Locked" style="font-size:.72rem;margin-left:.25rem;">🔒</span>@endif
            @if($s->is_custom)<span title="Custom step" style="font-size:.7rem;color:#2563eb;margin-left:.35rem;">+ custom</span>@endif
        </span>

        <span style="flex:0 0 auto;font-size:.78rem;color:#6b7280;white-space:nowrap;">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</span>

        <span style="flex:0 0 auto;display:inline-block;padding:.12rem .5rem;border-radius:1rem;font-size:.7rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>

        {{-- Compact inline actions (all functionality preserved) --}}
        <div style="flex:1 1 auto;display:inline-flex;gap:.3rem;flex-wrap:wrap;align-items:center;justify-content:flex-end;">
        @unless($locked)
        {{-- AT-334 P1 — composable deals: mark ANY not-started/active step done (editable
             actual date; re-cascades) + reopen a completed one. Old-model keeps its strict
             active-only "Mark complete". --}}
        @if(($isNewModel ?? false) && !$terminal)
            @permission('view_deals')
            <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;color:#047857;border-color:#6ee7b7;" @click="done = !done" title="Mark this step done (set the actual date)">Complete</button>
            @endpermission
        @endif
        @if(($isNewModel ?? false) && $s->status === 'completed')
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reopen', [$deal, $s]) }}" onsubmit="return confirm('Reopen this step? It returns to Not started and downstream dates re-cascade.');">@csrf
                <button type="submit" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;">Reopen</button>
            </form>
            @endpermission
        @endif
        @if($s->status === 'active' && !($isNewModel ?? false))
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                <button type="submit" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;color:#047857;border-color:#6ee7b7;">{{ $s->negative_status_trigger ? 'Mark passed' : 'Mark complete' }}</button>
            </form>
            {{-- AT-229 6b — a DECISION step (has a negative branch) also offers the negative
                 outcome. It completes the step and drives the deal by its negative trigger,
                 but NEVER activates the positive-path successors. --}}
            @if($s->negative_status_trigger)
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}"
                  onsubmit="return confirm('Record the NEGATIVE outcome? This completes the step and applies its declined status, and does NOT start the next steps.');">@csrf
                <input type="hidden" name="outcome" value="negative">
                <button type="submit" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;color:#b91c1c;border-color:#fca5a5;">{{ $s->negative_outcome_label ?: 'Mark declined' }}</button>
            </form>
            @endif
            @endpermission
        @endif
        @unless($terminal)
            @permission('view_deals')
            <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;" @click="na = !na">N/A</button>
            @endpermission
        @endunless
        @if($row['na'])
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}">@csrf
                <button type="submit" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;">Reinstate</button>
            </form>
            @endpermission
        @endif
        @permission('view_deals')
        <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;" @click="due = !due">Edit due</button>
        @endpermission
        @if($isNewModel ?? false)
        @permission('view_deals')
        <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;" @click="seq = !seq" title="Change which step this follows + offset">Sequence</button>
        @endpermission
        @endif
        @permission('view_deals')
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf
            <button type="submit" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;color:#b91c1c;">Remove</button>
        </form>
        @endpermission
        @endunless

        {{-- Comments survive the lock (history-keeping, not a transition). --}}
        @permission('view_deals')
        <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;" @click="cm = !cm">Comments ({{ $s->comments->count() }})</button>
        @endpermission

        </div>{{-- /compact inline actions --}}
    </div>{{-- /one-liner --}}

    @if($variant === 'gate')<div style="font-size:.72rem;color:#92400e;margin:.15rem 0 0 1.15rem;">Deal becomes unconditional once every condition is met.</div>@endif

    {{-- AT-334 P3 — a work order for this step is held awaiting a supplier. --}}
    @if($awaiting)<div style="font-size:.74rem;font-weight:600;color:#b91c1c;margin:.2rem 0 0 1.15rem;">⚠ This work order has not been sent out as no supplier has been set.</div>@endif

    {{-- Secondary context (blocked reason / excused note) — only when present --}}
    @if($row['blocked'])<div style="font-size:.73rem;color:#6b7280;margin:.2rem 0 0 1.15rem;">{{ $row['blocked'] }}</div>@endif
    @if($row['na'] && $s->na_reason)<div style="font-size:.73rem;color:#6b7280;margin:.2rem 0 0 1.15rem;">Excused: {{ $s->na_reason }}</div>@endif

    {{-- N/A reason form --}}
    @unless($terminal || $locked)
    <div x-show="na" x-cloak style="margin:.4rem 0 0 1.15rem;">
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
            <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Mark N/A</button>
        </form>
    </div>
    @endunless

    {{-- R2 — inline due-date edit (RAG recalcs off the edited date) --}}
    @unless($locked)
    @permission('view_deals')
    <div x-show="due" x-cloak style="margin:.4rem 0 0 1.15rem;">
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.due', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">@csrf
            <input type="date" name="due_date" value="{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('Y-m-d') : '' }}" class="corex-input" style="font-size:.8rem;">
            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Save due date</button>
        </form>
    </div>
    @endpermission
    @endunless

    {{-- AT-334 Phase 5 — follows (predecessor) + offset (days); re-cascades Dues then reorders. --}}
    @if($isNewModel ?? false)
    @unless($locked)
    @permission('view_deals')
    <div x-show="seq" x-cloak style="margin:.4rem 0 0 1.15rem;">
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.follows', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">@csrf
            <label style="font-size:.78rem;color:#374151;">Follows
                <select name="follows" class="corex-input" style="font-size:.8rem;">
                    <option value="">— nothing (starts on the deal date) —</option>
                    @foreach($steps as $r2)
                        @php($o = $r2['model'])
                        @if($o->id !== $s->id)
                        <option value="{{ $o->id }}" {{ (int) $s->trigger_step_instance_id === (int) $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
                        @endif
                    @endforeach
                </select>
            </label>
            <label style="font-size:.78rem;color:#374151;">+ offset
                <input type="number" name="offset" min="0" max="3650" value="{{ (int) $s->days_offset }}" class="corex-input" style="width:5rem;font-size:.8rem;"> days
            </label>
            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Save sequence</button>
        </form>
    </div>
    @endpermission
    @endunless
    @endif

    {{-- AT-334 P1 — mark done with an editable ACTUAL date (defaults today; back-datable).
         On submit, completeStep sets actual_date + re-cascades downstream Dues. New-model only. --}}
    @if(($isNewModel ?? false) && !$terminal)
    @unless($locked)
    @permission('view_deals')
    <div x-show="done" x-cloak style="margin:.4rem 0 0 1.15rem;">
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">@csrf
            <label style="font-size:.78rem;color:#374151;">Actually done on
                <input type="date" name="actual_date" value="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}" class="corex-input" style="font-size:.8rem;">
            </label>
            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;color:#047857;">Mark done</button>
        </form>
    </div>
    @endpermission
    @endunless
    @endif

    {{-- Comment thread --}}
    <div x-show="cm" x-cloak style="margin:.5rem 0 0 1.15rem;">
        @forelse($s->comments as $c)
            <div style="font-size:.8rem;margin-bottom:.35rem;">
                <span style="color:#374151;">{{ $c->body }}</span>
                <span style="color:#9ca3af;font-size:.72rem;"> — {{ $c->user->name ?? 'Someone' }}, {{ $c->created_at?->format('d M H:i') }}</span>
            </div>
        @empty
            <div style="font-size:.78rem;color:#9ca3af;margin-bottom:.35rem;">No comments yet.</div>
        @endforelse
        @permission('view_deals')
        <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
            <input type="text" name="body" placeholder="Add a note for this step…" required class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Post</button>
        </form>
        {{-- AT-225/226 — attach a document to THIS step (gas CoC → gas step); files to deal+property+contacts too. --}}
        @unless($locked)
        <form method="POST" action="{{ route('deals-dr2.documents.store', $deal) }}" enctype="multipart/form-data" style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem;">@csrf
            <input type="hidden" name="pipeline_step_id" value="{{ $s->id }}">
            <input type="file" name="file" required class="corex-input" style="flex:1 1 240px;font-size:.78rem;">
            <button type="submit" class="corex-btn-outline" style="padding:.2rem .7rem;font-size:.78rem;">Attach document to this step</button>
        </form>
        @endunless
        @endpermission
    </div>
</div>
