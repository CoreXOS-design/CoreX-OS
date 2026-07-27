@extends('layouts.corex')

{{-- Pipeline Dashboard Phase 2 — the TIMELINE view. Steps as duration bars on a horizontal time axis,
     auto-stacked into rows, with milestone gates, derived phase bands, a today line, and the activity
     lane (comments now; email/WhatsApp later). Horizontal drag reschedules a step (whole tile slides,
     duration preserved) and cascades downstream dependents with a confirmation. Spec §3, §4.1. --}}

@section('content')
@include('dr2._pipeline-surface-styles')
@php($tl = $timeline)
<div class="max-w-full mx-auto px-3 py-4">

    {{-- Header + view toggle (Timeline | List — the board view is retired) --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.6rem;">
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:#111827;">Pipeline timeline</div>
            <div style="font-size:.78rem;color:#6b7280;">
                {{ $deal->deal_no ? 'Deal ' . $deal->deal_no : ('Deal #' . $deal->id) }}
                @if($deal->property) · {{ $deal->property->buildDisplayAddress() }} @endif
            </div>
        </div>
        <div style="display:inline-flex;border:1px solid #e5e7eb;border-radius:.5rem;overflow:hidden;font-size:.78rem;">
            <span style="padding:.35rem .75rem;background:#111827;color:#fff;font-weight:600;">Timeline</span>
            <a href="{{ route('deals-dr2.pipeline.list', $deal) }}" style="padding:.35rem .75rem;color:#374151;text-decoration:none;">List</a>
        </div>
    </div>

    {{-- Deal-context tabs, on top of the timeline --}}
    @include('dr2._pipeline-context-tabs')

    <div x-data="pipelineTimeline(@js($tl))">

    @if($locked)
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.5rem .75rem;font-size:.8rem;margin-bottom:.6rem;">
            <strong>Pipeline locked.</strong> {{ $lockReason }} @if($unlockHint) <span style="color:#7f1d1d;">{{ $unlockHint }}</span> @endif
        </div>
    @endif

    @if(session('info'))<div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('info') }}</div>@endif
    @if(session('error'))<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:.5rem;padding:.45rem .75rem;font-size:.8rem;margin-bottom:.6rem;">{{ session('error') }}</div>@endif

    @if($tl['empty'])
        <div style="border:1px dashed #d1d5db;border-radius:.6rem;padding:2rem;text-align:center;color:#6b7280;font-size:.85rem;">
            No pipeline steps with a schedule yet — build the pipeline from the <strong>Deal Structure</strong> tab above.
        </div>
    @else
    <div class="tl-scroll" style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:.6rem;background:#fff;">
        <div class="tl-canvas" :style="`position:relative;width:${canvasWidth}px;min-width:100%;`">

            {{-- Phase bands (derived between milestone gates). Background only; the label is shown
                 ONLY when the band is wide enough and is clipped to the band so labels never mash. --}}
            <template x-for="(b,i) in data.bands" :key="'band'+i">
                <div :style="`position:absolute;top:0;bottom:0;left:${b.start_index*dw}px;width:${(b.end_index-b.start_index)*dw}px;background:${i%2? 'rgba(2,132,199,.035)':'rgba(2,132,199,.075)'};border-left:1px dashed #cbd5e1;overflow:hidden;`">
                    <div x-show="(b.end_index-b.start_index)*dw > 52" style="position:sticky;top:0;font-size:.62rem;color:#64748b;padding:2px 5px;white-space:nowrap;font-weight:600;text-overflow:ellipsis;overflow:hidden;" x-text="b.label"></div>
                </div>
            </template>

            {{-- Today line --}}
            <template x-if="data.today_index >= 0 && data.today_index <= data.total_days">
                <div :style="`position:absolute;top:0;bottom:0;left:${data.today_index*dw}px;width:2px;background:#ef4444;z-index:6;`">
                    <div style="position:absolute;top:2px;left:3px;font-size:.6rem;color:#ef4444;font-weight:700;white-space:nowrap;">today</div>
                </div>
            </template>

            {{-- Gates lane (milestone diamonds) — each gets a readable angled label; adjacent labels are
                 staggered on two levels so close milestones don't overlap ("Deal Signed"/"Proof of
                 Funds"/"Bond Approved" are legible even when the diamonds are days apart). --}}
            <div style="position:relative;height:74px;border-bottom:1px solid #f1f5f9;">
                <template x-for="(g,gi) in data.gates" :key="'gate'+g.id">
                    <div :style="`position:absolute;left:${g.index*dw}px;top:8px;z-index:4;`">
                        <div :style="`width:13px;height:13px;transform:translateX(-7px) rotate(45deg);background:${g.is_milestone?'#0f172a':'#94a3b8'};border:1px solid #fff;box-shadow:0 0 0 1px ${g.is_milestone?'#0f172a':'#94a3b8'};`" :title="g.name"></div>
                        <div :style="`position:absolute;left:2px;top:${gi%2? '34px':'18px'};font-size:.6rem;line-height:1;color:#334155;font-weight:600;white-space:nowrap;transform:rotate(28deg);transform-origin:left top;`" x-text="g.name"></div>
                    </div>
                </template>
            </div>

            {{-- Bars (steps stretched to duration, auto-stacked into rows) --}}
            <div :style="`position:relative;height:${data.row_count*rowH + 8}px;`">
                <template x-for="bar in data.bars" :key="'bar'+bar.id">
                    <div
                        @pointerdown="onDown(bar,$event)"
                        :style="barStyle(bar)"
                        :title="bar.name + '  (' + bar.duration_days + 'd)'">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="bar.name"></span>
                        <span x-show="bar.is_milestone" style="font-size:.6rem;opacity:.85;">◆</span>
                    </div>
                </template>
            </div>

            {{-- Activity lane (normalized events by date) --}}
            <div style="position:relative;height:40px;border-top:1px solid #f1f5f9;background:#fafafa;">
                <div style="position:absolute;top:2px;left:4px;font-size:.6rem;color:#9ca3af;font-weight:600;">ACTIVITY</div>
                <template x-for="ev in data.events" :key="'ev'+ev.key">
                    <div :style="`position:absolute;left:${ev.index*dw}px;top:16px;transform:translateX(-5px);z-index:3;cursor:pointer;`"
                         @click="openEvent = (openEvent===ev.key?null:ev.key)">
                        <div :style="`width:10px;height:10px;border-radius:50%;background:${ev.type==='comment'?'#0ea5e9':'#a855f7'};border:1px solid #fff;box-shadow:0 0 0 1px #cbd5e1;`"></div>
                        <div x-show="openEvent===ev.key" x-cloak
                             style="position:absolute;top:14px;left:0;z-index:20;background:#fff;border:1px solid #e5e7eb;border-radius:.4rem;box-shadow:0 6px 20px rgba(0,0,0,.12);padding:.5rem;width:230px;">
                            <div style="font-size:.62rem;color:#6b7280;"><span x-text="ev.type"></span> · <span x-text="ev.occurred_at"></span> <span x-show="ev.off_axis" style="color:#f59e0b;">(off-axis)</span></div>
                            <div style="font-size:.72rem;color:#111827;font-weight:600;" x-text="ev.author || 'System'"></div>
                            <div style="font-size:.72rem;color:#374151;margin-top:2px;white-space:pre-wrap;" x-text="ev.body"></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Step popover (tile click) — details + action set (reuses the board's routes) --}}
    <div x-show="openStep" x-cloak @keydown.escape.window="openStep=null"
         style="position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.35);" @click.self="openStep=null">
        <template x-if="openStep">
            <div style="background:#fff;border-radius:.6rem;box-shadow:0 20px 50px rgba(0,0,0,.25);width:340px;max-width:92vw;padding:1rem;">
                <div style="display:flex;justify-content:space-between;align-items:start;gap:.5rem;">
                    <div style="font-size:.95rem;font-weight:700;color:#111827;" x-text="openStep.name"></div>
                    <button @click="openStep=null" style="border:none;background:none;font-size:1.1rem;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
                </div>
                <div style="font-size:.74rem;color:#6b7280;margin:.25rem 0 .5rem;">
                    <span x-text="fmt(openStep.start_index)"></span> → <span x-text="fmt(openStep.end_index)"></span>
                    · <span x-text="openStep.duration_days"></span>d
                    · <span style="text-transform:capitalize;" x-text="openStep.status"></span>
                    <span x-show="openStep.blocked" style="color:#b45309;" x-text="' · '+openStep.blocked"></span>
                </div>

                <template x-if="!{{ $locked ? 'false' : 'true' }}">
                    <div style="font-size:.75rem;color:#991b1b;">Pipeline locked — actions unavailable.</div>
                </template>

                @unless($locked)
                <div style="display:flex;flex-direction:column;gap:.4rem;">
                    {{-- Complete --}}
                    <template x-if="openStep.draggable">
                        <form :action="actionUrl('complete', openStep.id)" method="POST" onsubmit="return confirm('Mark this step complete?')">
                            @csrf<input type="hidden" name="from" value="timeline">
                            <button type="submit" style="width:100%;background:#10b981;color:#fff;border:none;border-radius:.4rem;padding:.4rem;font-size:.78rem;font-weight:600;cursor:pointer;">✓ Mark complete</button>
                        </form>
                    </template>
                    {{-- Comment --}}
                    <form :action="actionUrl('comment', openStep.id)" method="POST" style="display:flex;gap:.3rem;">
                        @csrf<input type="hidden" name="from" value="timeline">
                        <input name="body" required placeholder="Add a comment…" style="flex:1;border:1px solid #d1d5db;border-radius:.4rem;padding:.35rem .5rem;font-size:.76rem;">
                        <button type="submit" style="background:#0ea5e9;color:#fff;border:none;border-radius:.4rem;padding:.35rem .6rem;font-size:.76rem;font-weight:600;cursor:pointer;">Post</button>
                    </form>
                    {{-- Remove --}}
                    <template x-if="openStep.draggable">
                        <form :action="actionUrl('remove', openStep.id)" method="POST" onsubmit="return confirm('Remove this step from the pipeline? (reversible)')">
                            @csrf<input type="hidden" name="from" value="timeline">
                            <button type="submit" style="width:100%;background:#fff;color:#b91c1c;border:1px solid #fecaca;border-radius:.4rem;padding:.35rem;font-size:.76rem;font-weight:600;cursor:pointer;">Remove step</button>
                        </form>
                    </template>
                    <a :href="'{{ route('deals-dr2.pipeline.list', $deal) }}'" style="text-align:center;font-size:.72rem;color:#6b7280;text-decoration:none;">Open in List ↗ (edit dates, N/A, sequence)</a>
                    <div style="font-size:.68rem;color:#9ca3af;text-align:center;">Tip: drag the bar to reschedule its start.</div>
                </div>
                @endunless
            </div>
        </template>
    </div>

    {{-- Reschedule confirm dialog --}}
    <div x-show="preview" x-cloak style="position:fixed;inset:0;z-index:70;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,.4);">
        <template x-if="preview">
            <div style="background:#fff;border-radius:.6rem;box-shadow:0 20px 50px rgba(0,0,0,.3);width:440px;max-width:94vw;padding:1.1rem;">
                <div style="font-size:.95rem;font-weight:700;color:#111827;">Reschedule step</div>
                <div style="font-size:.78rem;color:#374151;margin:.3rem 0 .6rem;">
                    Moving <strong x-text="preview.moved.find(m=>m.is_dragged)?.name"></strong>
                    by <strong x-text="signed(preview.delta_days)+' day'+(Math.abs(preview.delta_days)===1?'':'s')"></strong>.
                    <span x-show="preview.moved.length>1">This cascades <strong x-text="preview.moved.length-1"></strong> downstream step(s).</span>
                    <span x-show="preview.moved.length===1">No downstream steps are affected.</span>
                </div>

                <div style="max-height:230px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:.4rem;">
                    <template x-for="m in preview.moved" :key="'mv'+m.id">
                        <div :style="`display:flex;justify-content:space-between;gap:.5rem;padding:.35rem .5rem;font-size:.74rem;border-bottom:1px solid #f8fafc;${m.is_dragged?'background:#f0f9ff;font-weight:600;':''}`">
                            <span x-text="m.name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                            <span style="color:#6b7280;white-space:nowrap;"><span x-text="m.old_start"></span> → <span style="color:#0369a1;" x-text="m.new_start"></span></span>
                        </div>
                    </template>
                </div>
                <template x-if="preview.held.length">
                    <div style="font-size:.7rem;color:#92400e;margin-top:.4rem;">
                        Held (unchanged): <span x-text="preview.held.map(h=>h.name+' ('+h.reason+')').join(', ')"></span>
                    </div>
                </template>

                <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.8rem;">
                    <button @click="preview=null" style="background:#fff;border:1px solid #d1d5db;color:#374151;border-radius:.4rem;padding:.4rem .8rem;font-size:.78rem;cursor:pointer;">Cancel</button>
                    <button @click="commit()" :disabled="committing" style="background:#0284c7;border:none;color:#fff;border-radius:.4rem;padding:.4rem .9rem;font-size:.78rem;font-weight:600;cursor:pointer;" x-text="committing?'Saving…':'Confirm reschedule'"></button>
                </div>
            </div>
        </template>
    </div>
    @endif
    </div>{{-- /x-data pipelineTimeline --}}
</div>

@push('scripts')
<script>
function pipelineTimeline(data) {
    return {
        data,
        dw: data.day_width || 26,
        rowH: 26,
        openStep: null,
        openEvent: null,
        preview: null,
        committing: false,
        // drag state
        dragging: null, startX: 0, offsetDays: 0, moved: false,

        get canvasWidth() { return Math.max(600, (this.data.total_days||1) * this.dw + 40); },

        barStyle(bar) {
            const left = bar.start_index * this.dw + (this.dragging===bar.id ? this.offsetDays*this.dw : 0);
            const w = Math.max(this.dw*0.9, bar.duration_days * this.dw);
            const top = bar.row * this.rowH + 4;
            const terminal = bar.status==='completed' || bar.status==='skipped';
            return `position:absolute;left:${left}px;top:${top}px;width:${w}px;height:${this.rowH-6}px;`
                + `background:${bar.colour};color:#fff;border-radius:.35rem;padding:0 .4rem;`
                + `display:flex;align-items:center;gap:.25rem;font-size:.68rem;font-weight:600;`
                + `box-shadow:0 1px 2px rgba(0,0,0,.15);z-index:${this.dragging===bar.id?9:2};`
                + `cursor:${bar.draggable?'grab':'default'};${terminal?'opacity:.55;':''}`
                + `${this.dragging===bar.id?'outline:2px solid #0284c7;':''}`;
        },

        onDown(bar, e) {
            this.openEvent = null;
            if (!bar.draggable || {{ $locked ? 'true' : 'false' }}) { this.openStep = bar; return; }
            this.dragging = bar.id; this.startX = e.clientX; this.offsetDays = 0; this.moved = false;
            const mv = (ev) => {
                const d = Math.round((ev.clientX - this.startX) / this.dw);
                if (d !== this.offsetDays) { this.offsetDays = d; if (d!==0) this.moved = true; }
            };
            const up = () => {
                document.removeEventListener('pointermove', mv);
                document.removeEventListener('pointerup', up);
                const d = this.offsetDays; this.dragging = null; this.offsetDays = 0;
                if (!this.moved || d === 0) { this.openStep = bar; return; }
                this.requestPreview(bar, d);
            };
            document.addEventListener('pointermove', mv);
            document.addEventListener('pointerup', up);
        },

        addDays(iso, n) {
            const dt = new Date(iso + 'T00:00:00Z');
            dt.setUTCDate(dt.getUTCDate() + n);
            return dt.toISOString().slice(0,10);
        },
        fmt(index) { return this.addDays(this.data.range_start, index); },
        signed(n) { return (n>=0?'+':'') + n; },
        actionUrl(action, stepId) {
            return `{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}/${stepId}/${action}`;
        },

        async requestPreview(bar, deltaDays) {
            const newStart = this.addDays(this.data.range_start, bar.start_index + deltaDays);
            try {
                const r = await this.post(bar.id, { new_start: newStart, commit: false });
                if (!r.ok) { alert(r.error || 'Could not reschedule.'); return; }
                r._new_start = newStart; r._step = bar.id;
                if (!r.moved.length) return; // nothing changed
                this.preview = r;
            } catch (e) { alert('Could not reschedule.'); }
        },
        async commit() {
            if (!this.preview) return;
            this.committing = true;
            try {
                const r = await this.post(this.preview._step, { new_start: this.preview._new_start, commit: true });
                if (!r.ok) { alert(r.error || 'Could not save.'); this.committing=false; return; }
                window.location.reload();
            } catch (e) { alert('Could not save.'); this.committing=false; }
        },
        async post(stepId, body) {
            const res = await fetch(`{{ url('deals-dr2/'.$deal->id.'/pipeline/steps') }}/${stepId}/reschedule`, {
                method:'POST',
                headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
                credentials:'same-origin',
                body: JSON.stringify(body),
            });
            return res.json();
        },
    };
}
</script>
@endpush
@endsection
