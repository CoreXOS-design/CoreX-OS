@extends('layouts.corex')

{{-- Pipeline Dashboard Phase 3 — the LIST view. Same steps + activity as the timeline, vertical.
     Grab-to-reorder (⠿ grip) writes DISPLAY position ONLY — never dependencies or dates (decision 4).
     Sequence-click sets an explicit position; Edit-dates sets start+end inline; the full action set
     (Complete / Edit dates / Sequence / N-A / Remove / Comment) reuses the board routes with
     ?from=list. Spec §3, decisions 2 & 4. --}}

@section('content')
@php($stepName = collect($rows)->mapWithKeys(fn ($r) => [(int) $r['model']->id => $r['model']->name]))
<style>
    .dr2-la{background:none;border:none;padding:.1rem .35rem;font-size:.72rem;font-weight:600;color:#374151;cursor:pointer;border-radius:.3rem;}
    .dr2-la:hover{background:#f1f5f9;}
    .dr2-btn-go{border:none;color:#fff;border-radius:.35rem;padding:.25rem .6rem;font-size:.74rem;font-weight:600;cursor:pointer;}
    .dr2-lrow.dr2-drag-over{background:#eff6ff;}
    .dr2-lrow.dr2-dragging{opacity:.4;}
</style>
<div class="max-w-4xl mx-auto px-3 py-4">

    {{-- Header + view toggle --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.6rem;">
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:#111827;">Pipeline list</div>
            <div style="font-size:.78rem;color:#6b7280;">
                {{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
                @if($deal->property) · {{ $deal->property->buildDisplayAddress() }} @endif
            </div>
        </div>
        <div style="display:inline-flex;border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;font-size:.78rem;">
            <a href="{{ route('deals-dr2.pipeline.timeline', $deal) }}" style="padding:.35rem .75rem;color:#374151;text-decoration:none;">Timeline</a>
            <span style="padding:.35rem .75rem;background:#111827;color:#fff;font-weight:600;">List</span>
            <a href="{{ route('deals-dr2.pipeline', $deal) }}" style="padding:.35rem .75rem;color:#374151;text-decoration:none;">Board</a>
        </div>
    </div>

    @if($locked)
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.5rem .75rem;font-size:.8rem;margin-bottom:.6rem;">
            <strong>Pipeline locked.</strong> {{ $lockReason }} @if($unlockHint) <span style="color:#7f1d1d;">{{ $unlockHint }}</span> @endif
        </div>
    @endif
    @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('info') }}</div>@endif
    @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('error') }}</div>@endif

    @if($rows->isEmpty())
        <div style="border:1px dashed #d1d5db;border-radius:.6rem;padding:2rem;text-align:center;color:#6b7280;font-size:.85rem;">
            No pipeline steps yet. <a href="{{ route('deals-dr2.pipeline', $deal) }}" style="color:#0ea5e9;font-weight:600;">Open the board</a> to attach a pipeline.
        </div>
    @else
    @unless($locked)
    <div style="font-size:.72rem;color:#9ca3af;margin-bottom:.35rem;">Drag <span style="font-weight:700;">⠿</span> to reorder (display only — never changes dependencies or dates). Click the # to jump a step to a position.</div>
    @endunless

    <div id="dr2-list" data-reorder-url="{{ route('deals-dr2.pipeline.reorder', $deal) }}"
         style="border:1px solid #e5e7eb;border-radius:.6rem;overflow:hidden;background:#fff;{{ $locked ? 'opacity:.72;filter:grayscale(.3);' : '' }}">
        @foreach($rows as $i => $row)
            @php($s = $row['model'])
            <div class="dr2-lrow" data-id="{{ $s->id }}" x-data="{ open:'' }"
                 style="border-bottom:1px solid #f1f5f9;padding:.5rem .6rem;{{ in_array($s->status, ['completed','skipped']) ? 'background:#fafafa;' : '' }}">
                <div style="display:flex;align-items:center;gap:.55rem;">
                    {{-- grip --}}
                    @unless($locked)
                    <span class="dr2-grip" draggable="true" title="Drag to reorder"
                          style="cursor:grab;color:#cbd5e1;font-size:1rem;user-select:none;">⠿</span>
                    @else<span style="color:#e5e7eb;">⠿</span>@endunless
                    {{-- sequence number (click to set explicit position) --}}
                    <button type="button" @click="open = (open==='seq'?'':'seq')" title="Set position"
                            style="min-width:1.6rem;height:1.6rem;border:1px solid #e5e7eb;border-radius:.35rem;background:#f8fafc;color:#475569;font-size:.72rem;font-weight:700;cursor:pointer;">{{ $i + 1 }}</button>
                    {{-- rag dot --}}
                    <span style="width:9px;height:9px;border-radius:50%;flex:none;background:{{ $row['colour'] }};"></span>
                    {{-- name + badges --}}
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.84rem;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $s->name }}
                            @if($s->is_milestone)<span title="Milestone" style="color:#0f172a;">◆</span>@endif
                            @if($row['na'])<span style="font-size:.64rem;color:#92400e;background:#fef3c7;border-radius:.25rem;padding:0 .3rem;">N/A</span>@endif
                            @if($row['blocked'])<span style="font-size:.64rem;color:#b45309;">· {{ $row['blocked'] }}</span>@endif
                        </div>
                        <div style="font-size:.7rem;color:#6b7280;">
                            {{ $s->planned_start_date?->format('d M') }} → {{ $s->due_date?->format('d M Y') }}
                            @if(!is_null($row['duration'])) · {{ $row['duration'] }}d @endif
                            · <span style="text-transform:capitalize;">{{ str_replace('_',' ',$s->status) }}</span>
                            @if($s->comments->count()) · {{ $s->comments->count() }} comment{{ $s->comments->count()===1?'':'s' }} @endif
                        </div>
                    </div>
                    {{-- action menu --}}
                    @unless($locked)
                    <div style="display:flex;gap:.25rem;flex-wrap:wrap;justify-content:flex-end;">
                        @php($terminal = in_array($s->status, ['completed','skipped']))
                        @unless($terminal)
                            <button type="button" @click="open = open==='done'?'':'done'" class="dr2-la" style="color:#047857;">Complete</button>
                            <button type="button" @click="open = open==='dates'?'':'dates'" class="dr2-la">Edit dates</button>
                            <button type="button" @click="open = open==='na'?'':'na'" class="dr2-la">N/A</button>
                            <button type="button" @click="open = open==='rm'?'':'rm'" class="dr2-la" style="color:#b91c1c;">Remove</button>
                        @endunless
                        @if($s->status === 'skipped')
                            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}" style="display:inline;">@csrf<input type="hidden" name="from" value="list"><button class="dr2-la">Reinstate</button></form>
                        @endif
                        <button type="button" @click="open = open==='cm'?'':'cm'" class="dr2-la">Comment</button>
                    </div>
                    @endunless
                </div>

                {{-- Inline: set position (sequence-click) --}}
                <div x-show="open==='seq'" x-cloak style="margin-top:.4rem;display:flex;gap:.35rem;align-items:center;">
                    <span style="font-size:.72rem;color:#6b7280;">Move to position</span>
                    <input type="number" min="1" max="{{ count($rows) }}" value="{{ $i + 1 }}"
                           @keydown.enter.prevent="window.dr2MoveTo({{ $s->id }}, parseInt($event.target.value)); open=''"
                           style="width:4rem;border:1px solid #d1d5db;border-radius:.35rem;padding:.2rem .4rem;font-size:.76rem;">
                    <span style="font-size:.68rem;color:#9ca3af;">press Enter</span>
                </div>

                @unless($locked)
                {{-- Inline: Complete --}}
                <form x-show="open==='done'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}" style="margin-top:.4rem;display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;">@csrf<input type="hidden" name="from" value="list">
                    <label style="font-size:.72rem;color:#374151;">Done on <input type="date" name="actual_date" value="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}" style="border:1px solid #d1d5db;border-radius:.35rem;padding:.2rem .4rem;font-size:.76rem;"></label>
                    <button class="dr2-btn-go" style="background:#10b981;">Mark done</button>
                </form>
                {{-- Inline: Edit dates (start + end) --}}
                <form x-show="open==='dates'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.dates', [$deal, $s]) }}" style="margin-top:.4rem;display:flex;gap:.35rem;align-items:center;flex-wrap:wrap;">@csrf<input type="hidden" name="from" value="list">
                    <label style="font-size:.72rem;color:#374151;">Start <input type="date" name="planned_start_date" required value="{{ $s->planned_start_date?->format('Y-m-d') }}" style="border:1px solid #d1d5db;border-radius:.35rem;padding:.2rem .4rem;font-size:.76rem;"></label>
                    <label style="font-size:.72rem;color:#374151;">End <input type="date" name="due_date" required value="{{ $s->due_date?->format('Y-m-d') }}" style="border:1px solid #d1d5db;border-radius:.35rem;padding:.2rem .4rem;font-size:.76rem;"></label>
                    <button class="dr2-btn-go" style="background:#0284c7;">Save dates</button>
                </form>
                {{-- Inline: N/A --}}
                <form x-show="open==='na'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" style="margin-top:.4rem;display:flex;gap:.35rem;align-items:center;">@csrf<input type="hidden" name="from" value="list">
                    <input type="text" name="reason" placeholder="Why not applicable? (e.g. no gas)" style="flex:1;border:1px solid #d1d5db;border-radius:.35rem;padding:.25rem .5rem;font-size:.76rem;">
                    <button class="dr2-btn-go" style="background:#f59e0b;">Mark N/A</button>
                </form>
                {{-- Inline: Remove --}}
                <form x-show="open==='rm'" x-cloak method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? (reversible — archived, not deleted)')" style="margin-top:.4rem;">@csrf<input type="hidden" name="from" value="list">
                    <button class="dr2-btn-go" style="background:#ef4444;">Remove step</button>
                    <span style="font-size:.68rem;color:#9ca3af;">reversible</span>
                </form>
                {{-- Inline: Comment (+ thread) --}}
                <div x-show="open==='cm'" x-cloak style="margin-top:.4rem;">
                    @foreach($s->comments->sortBy('created_at') as $c)
                        <div style="font-size:.74rem;color:#374151;border-left:2px solid #e5e7eb;padding:.1rem .5rem;margin-bottom:.2rem;">
                            <span style="color:#111827;font-weight:600;">{{ $c->user?->name ?? 'System' }}</span>
                            <span style="color:#9ca3af;font-size:.66rem;">· {{ $c->created_at->format('d M H:i') }}</span><br>{{ $c->body }}
                        </div>
                    @endforeach
                    <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" style="display:flex;gap:.35rem;margin-top:.2rem;">@csrf<input type="hidden" name="from" value="list">
                        <input type="text" name="body" required placeholder="Add a comment…" style="flex:1;border:1px solid #d1d5db;border-radius:.35rem;padding:.25rem .5rem;font-size:.76rem;">
                        <button class="dr2-btn-go" style="background:#0ea5e9;">Post</button>
                    </form>
                </div>
                @endunless
            </div>
        @endforeach
    </div>

    {{-- Removed steps (restore) --}}
    @if($removedSteps->isNotEmpty() && !$locked)
        <div style="margin-top:.6rem;font-size:.74rem;color:#6b7280;">
            <div style="font-weight:600;margin-bottom:.2rem;">Removed steps</div>
            @foreach($removedSteps as $rs)
                <form method="POST" action="{{ route('deals-dr2.pipeline.step.restore', $deal) }}" style="display:flex;gap:.4rem;align-items:center;margin-bottom:.15rem;">@csrf
                    <input type="hidden" name="step_id" value="{{ $rs->id }}"><input type="hidden" name="from" value="list">
                    <span style="flex:1;">{{ $rs->name }}</span>
                    <button class="dr2-la">Restore</button>
                </form>
            @endforeach
        </div>
    @endif

    {{-- Activity (same normalized events as the timeline) --}}
    <div style="margin-top:1rem;">
        <div style="font-size:.8rem;font-weight:700;color:#111827;margin-bottom:.35rem;">Activity</div>
        @if($activity->isEmpty())
            <div style="font-size:.76rem;color:#9ca3af;">No activity yet. Comments (and, later, emails &amp; WhatsApp) appear here.</div>
        @else
            <div style="border:1px solid #e5e7eb;border-radius:.6rem;background:#fff;">
                @foreach($activity as $e)
                    <div style="padding:.4rem .6rem;border-bottom:1px solid #f8fafc;font-size:.76rem;">
                        <div style="color:#6b7280;font-size:.66rem;">
                            <span style="text-transform:uppercase;letter-spacing:.03em;color:{{ $e->type==='comment' ? '#0ea5e9' : '#a855f7' }};font-weight:700;">{{ $e->type }}</span>
                            · {{ $e->occurredAt->format('d M Y H:i') }}
                            @if($e->isStepScoped() && $stepName->has($e->stepId)) · <span style="color:#374151;">{{ $stepName->get($e->stepId) }}</span> @endif
                            @if($e->direction) · {{ $e->direction }} @endif
                        </div>
                        <div style="color:#111827;font-weight:600;">{{ $e->authorName ?? 'System' }}</div>
                        <div style="color:#374151;white-space:pre-wrap;">{{ $e->body }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    var list = document.getElementById('dr2-list');
    if (!list) return;
    var url = list.dataset.reorderUrl;
    var token = '{{ csrf_token() }}';
    var dragEl = null;

    function rows() { return Array.prototype.slice.call(list.querySelectorAll('.dr2-lrow')); }

    function persist() {
        var order = rows().map(function (r) { return parseInt(r.dataset.id); });
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            credentials: 'same-origin',
            body: JSON.stringify({ order: order }),
        }).then(function (r) { return r.json(); })
          .then(function (j) { if (!j.ok) { alert(j.error || 'Could not save order.'); } window.location.reload(); })
          .catch(function () { alert('Could not save order.'); window.location.reload(); });
    }

    // Move a step to an explicit 1-based position (sequence-click), then persist.
    window.dr2MoveTo = function (id, pos) {
        var rs = rows();
        var el = rs.find(function (r) { return parseInt(r.dataset.id) === id; });
        if (!el || !pos || pos < 1) return;
        var others = rs.filter(function (r) { return r !== el; });
        var target = others[Math.min(pos - 1, others.length)];
        if (target) { list.insertBefore(el, target); } else { list.appendChild(el); }
        persist();
    };

    // Native HTML5 drag via the ⠿ grip.
    list.addEventListener('dragstart', function (e) {
        var grip = e.target.closest('.dr2-grip');
        if (!grip) { e.preventDefault(); return; }
        dragEl = grip.closest('.dr2-lrow');
        dragEl.classList.add('dr2-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragEl.dataset.id); } catch (x) {}
    });
    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var over = e.target.closest('.dr2-lrow');
        if (!over || over === dragEl) return;
        rows().forEach(function (r) { r.classList.remove('dr2-drag-over'); });
        over.classList.add('dr2-drag-over');
        var rect = over.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        list.insertBefore(dragEl, after ? over.nextSibling : over);
    });
    list.addEventListener('drop', function (e) { e.preventDefault(); });
    list.addEventListener('dragend', function () {
        if (!dragEl) return;
        dragEl.classList.remove('dr2-dragging');
        rows().forEach(function (r) { r.classList.remove('dr2-drag-over'); });
        dragEl = null;
        persist();
    });
})();
</script>
@endpush
