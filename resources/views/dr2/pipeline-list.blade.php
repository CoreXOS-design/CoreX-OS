@extends('layouts.corex')

{{-- Pipeline Dashboard — the LIST view. Deal-context tabs ON TOP, then the SAME step CARDS as the
     board (dr2._pipeline-step-tile) stacked vertically as a list. Grab a card's ⠿ grip to reorder
     (DISPLAY position ONLY — never rewires dependencies or dates); every card carries the full action
     set (Complete / Edit due / Sequence / N-A / Remove / Comment) exactly like the board. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
@php($from = 'list')
<div class="max-w-5xl mx-auto px-3 py-4">

    {{-- Header + view toggle (Timeline | List — the board view is retired) --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.6rem;">
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:#111827;">Pipeline list</div>
            <div style="font-size:.78rem;color:#6b7280;">
                {{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
                @if($deal->property) · {{ $deal->property->buildDisplayAddress() }} @endif
            </div>
        </div>
        <div style="display:inline-flex;border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;font-size:.8rem;">
            <a href="{{ route('deals-dr2.pipeline.timeline', $deal) }}" style="padding:.4rem .85rem;color:#374151;text-decoration:none;">Timeline</a>
            <span style="padding:.4rem .85rem;background:#111827;color:#fff;font-weight:600;">List</span>
        </div>
    </div>

    {{-- Deal-context tabs, on top of the list --}}
    @include('dr2._pipeline-context-tabs')

    @if($locked)
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.5rem .75rem;font-size:.8rem;margin-bottom:.6rem;">
            <strong>Pipeline locked.</strong> {{ $lockReason }} @if($unlockHint) <span style="color:#7f1d1d;">{{ $unlockHint }}</span> @endif
        </div>
    @endif
    @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('info') }}</div>@endif
    @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('error') }}</div>@endif

    @if($steps->isEmpty())
        <div style="border:1px dashed #d1d5db;border-radius:.6rem;padding:1.5rem;text-align:center;color:#6b7280;font-size:.85rem;">
            No pipeline steps yet — build the pipeline from the <strong>Deal Structure</strong> tab above.
        </div>
    @else
        @unless($locked)
        <div style="font-size:.72rem;color:#9ca3af;margin:.2rem 0 .5rem;">Grab a card's <span style="font-weight:700;">⠿</span> to reorder (display only — never changes dependencies or dates).</div>
        @endunless

        <div id="dr2-list" class="dr2-listwrap" data-reorder-url="{{ route('deals-dr2.pipeline.reorder', $deal) }}"
             style="display:flex;flex-direction:column;gap:.5rem;{{ $locked ? 'opacity:.72;filter:grayscale(.3);' : '' }}">
            @foreach($steps as $row)
                <div class="dr2-lrow" data-id="{{ $row['model']->id }}">
                    @include('dr2._pipeline-step-tile', ['row' => $row, 'variant' => 'wide', 'from' => 'list'])
                </div>
            @endforeach
        </div>

        {{-- Removed steps (restore) --}}
        @if($removedSteps->isNotEmpty() && !$locked)
            <div style="margin-top:.7rem;font-size:.74rem;color:#6b7280;">
                <div style="font-weight:600;margin-bottom:.2rem;">Removed steps</div>
                @foreach($removedSteps as $rs)
                    <form method="POST" action="{{ route('deals-dr2.pipeline.step.restore', $deal) }}" style="display:flex;gap:.4rem;align-items:center;margin-bottom:.15rem;">@csrf
                        <input type="hidden" name="step_id" value="{{ $rs->id }}"><input type="hidden" name="from" value="list">
                        <span style="flex:1;">{{ $rs->name }}</span>
                        <button class="dr2-bt">Restore</button>
                    </form>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Activity (same normalized events as the timeline) --}}
    <div style="margin-top:1.1rem;">
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
                            @if($e->direction) · {{ $e->direction }} @endif
                        </div>
                        <div style="color:#111827;font-weight:600;">{{ $e->authorName ?? 'System' }}</div>
                        <div style="color:#374151;white-space:pre-wrap;">{{ $e->body }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var list = document.getElementById('dr2-list');
    if (!list) return;
    var url = list.dataset.reorderUrl, token = '{{ csrf_token() }}', dragEl = null;
    function rows() { return Array.prototype.slice.call(list.querySelectorAll('.dr2-lrow')); }
    function persist() {
        var order = rows().map(function (r) { return parseInt(r.dataset.id); });
        fetch(url, { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token}, credentials:'same-origin', body: JSON.stringify({ order: order }) })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.ok) alert(j.error || 'Could not save order.'); window.location.reload(); })
            .catch(function () { window.location.reload(); });
    }
    // Reorder is driven by each card's ⠿ grip. In the list the board's follows-JS is not loaded, so the
    // grip means "reorder" here (position only).
    list.querySelectorAll('.dr2-tile__grip').forEach(function (g) { g.setAttribute('draggable', 'true'); g.title = 'Drag to reorder'; });
    list.addEventListener('dragstart', function (e) {
        var grip = e.target.closest('.dr2-tile__grip'); if (!grip) { e.preventDefault(); return; }
        dragEl = grip.closest('.dr2-lrow'); dragEl.classList.add('dr2-dragging');
        e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragEl.dataset.id); } catch (x) {}
    });
    list.addEventListener('dragover', function (e) {
        e.preventDefault(); var over = e.target.closest('.dr2-lrow'); if (!over || over === dragEl) return;
        rows().forEach(function (r) { r.classList.remove('dr2-drag-over'); }); over.classList.add('dr2-drag-over');
        var rect = over.getBoundingClientRect(); var after = (e.clientY - rect.top) > rect.height / 2;
        list.insertBefore(dragEl, after ? over.nextSibling : over);
    });
    list.addEventListener('drop', function (e) { e.preventDefault(); });
    list.addEventListener('dragend', function () {
        if (!dragEl) return; dragEl.classList.remove('dr2-dragging');
        rows().forEach(function (r) { r.classList.remove('dr2-drag-over'); }); dragEl = null; persist();
    });
})();
</script>
@endpush
