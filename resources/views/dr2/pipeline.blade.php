{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-216 (DR2 · WS-PIPELINE) — pipeline tracking board for one DR2 deal.
    PURE TRACKING OVERLAY: attaching a pipeline / completing a step never changes the
    DR1 deal's state — only the pipeline's own steps + the deal's pipeline pointer.

    AT-305 — two-column redesign (layout/density only; ALL functionality preserved,
    incl. AT-229 negative-outcome decision branch + the COC work-order panel).
    LEFT: pipeline steps as dense one-liner rows (status dot · step name · milestone ·
    due date · status badge, with Complete/N-A/Edit due/Remove/Comments/Work-orders as
    compact inline actions that expand in place). RIGHT: a sticky, independently-
    scrolling rail — Documents, Send documents to a party, Proforma Invoices — modelled
    on the buyer viewing-pack screen. Two columns on desktop; stacks on mobile.
--}}
@extends('layouts.corex')

@section('corex-content')
@php
    $statusStyles = [
        'not_started' => ['Not started', '#6b7280', '#f3f4f6'],
        'active'      => ['Active',      '#1d4ed8', '#dbeafe'],
        'completed'   => ['Completed',   '#047857', '#d1fae5'],
        'not_applicable' => ['N/A',       '#6b7280', '#f3f4f6'],
        'skipped'     => ['Skipped',     '#6b7280', '#f3f4f6'],
    ];
    // AT-305b — count of completed/terminal steps, for the "Hide completed" toggle.
    $completedCount = ($steps ?? collect())->filter(fn ($r) => in_array($r['model']->status, ['completed', 'skipped'], true))->count();
@endphp
{{--
    AT-305b — TRUE independent dual-pane scroll. Each column is its own bounded
    overflow-y region so scrolling the pipeline (left) does NOT move the docs/
    proforma rail (right) and vice-versa; the page itself does not scroll the
    columns off screen. Desktop only (≥1024px); on mobile the columns stack and
    scroll with the page. Scoped <style> (not arbitrary Tailwind) so it applies on
    qa1 without a CSS rebuild.
--}}
<style>
@media (min-width: 1024px) {
    /* Each column is its OWN fixed-height scroll region — bulletproof regardless of
       the grid's align-items (does not rely on stretch): scrolling one never moves
       the other, and the page does not scroll the columns off. */
    .dr2-pipe-col { height: calc(100vh - 9.5rem); min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding-right: .35rem; }
}
/* AT-331 — tabbed right panel. Scoped here (no Tailwind, no CSS rebuild on qa1). */
.dr2-tabbar { display:flex; gap:.25rem; padding:.3rem; background:var(--surface-2,#f0f2f8);
    border:1px solid var(--border,rgba(0,0,0,.08)); border-radius:12px; margin-bottom:.85rem;
    position:sticky; top:0; z-index:3; }
.dr2-tab { flex:1; border:0; background:transparent; color:var(--text-muted,#6b7280);
    font-family:inherit; font-size:.76rem; font-weight:600; line-height:1.15; padding:.5rem .3rem;
    border-radius:9px; cursor:pointer; transition:background .15s,color .15s;
    display:flex; align-items:center; justify-content:center; gap:.35rem; text-align:center; }
.dr2-tab:hover { color:var(--text-primary,#111827); }
.dr2-tab.corex-tab-active { background:var(--brand-button,#0ea5e9); color:#fff; box-shadow:0 1px 3px rgba(2,20,40,.18); }
.dr2-tab:focus-visible { outline:2px solid var(--brand-button,#0ea5e9); outline-offset:2px; }
/* Standard collapse/expand section header. */
.dr2-sect-head { display:flex; align-items:center; gap:.5rem; width:100%; border:0; background:transparent;
    color:var(--text-primary,#111827); font-family:inherit; cursor:pointer; padding:0; text-align:left; }
.dr2-sect-title { font-size:.9rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); }
.dr2-chev { transition:transform .2s; color:var(--text-muted,#9ca3af); font-size:.85rem; line-height:1; }
.dr2-chev.dr2-chev-closed { transform:rotate(-90deg); }

/* AT-334 concurrent-lane board (new-model deals). Scoped here — no Tailwind, no CSS rebuild on qa1. */
.dr2-stage-h { margin:.9rem .15rem .35rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; }
.dr2-stage-h span { display:block; font-weight:400; text-transform:none; letter-spacing:0; font-size:.72rem; color:#9ca3af; }
.dr2-tile { width:200px; min-height:172px; box-sizing:border-box; display:flex; flex-direction:column;
    border:1px solid var(--corex-border,#e5e7eb); border-radius:10px; padding:.5rem .55rem; background:var(--surface,#fff); position:relative; }
.dr2-tile--wide { width:100%; }
.dr2-tile--gate { background:#fffbeb; border:1px solid #fcd34d; }
.dr2-tile--done { opacity:.62; filter:grayscale(.3); }
.dr2-tile--na { opacity:.6; }
.dr2-tile--warn { border-left:3px solid #dc2626; background:#fef2f2; }
.dr2-tile.dr2-drop-ok { outline:2px dashed #2563eb; outline-offset:2px; }
.dr2-tile__head { display:flex; align-items:flex-start; gap:.35rem; }
.dr2-tile__grip { cursor:grab; color:#cbd5e1; font-size:.8rem; line-height:1.1; user-select:none; }
.dr2-tile__rag { flex:0 0 auto; width:.6rem; height:.6rem; border-radius:50%; margin-top:.18rem; }
.dr2-tile__name { flex:1 1 auto; font-weight:700; font-size:.82rem; line-height:1.15; color:#111827; word-break:break-word; }
.dr2-tile__name--done { font-weight:600; text-decoration:line-through; text-decoration-color:rgba(0,0,0,.28); color:#374151; }
.dr2-tile__name--na { text-decoration:line-through; }
.dr2-tile__check { color:#047857; font-weight:800; margin-right:.1rem; }
.dr2-tile__tags { display:flex; flex-wrap:wrap; gap:.25rem; margin:.25rem 0 0; min-height:.9rem; }
.dr2-tag { font-size:.66rem; padding:0 .3rem; border-radius:.5rem; line-height:1.4; }
.dr2-tag--off { color:#9ca3af; background:#f3f4f6; }
.dr2-tag--ms { color:#b45309; }
.dr2-tag--custom { color:#2563eb; }
.dr2-tile__meta { display:flex; align-items:center; justify-content:space-between; gap:.35rem; margin:.3rem 0 .1rem; }
.dr2-tile__date { font-size:.72rem; color:#6b7280; white-space:nowrap; }
.dr2-tile__badge { font-size:.66rem; padding:.1rem .45rem; border-radius:1rem; white-space:nowrap; }
.dr2-tile__gatenote { font-size:.7rem; color:#92400e; margin:.15rem 0; }
.dr2-tile__warnnote { font-size:.7rem; font-weight:600; color:#b91c1c; margin:.15rem 0; }
.dr2-tile__sub { font-size:.7rem; color:#6b7280; margin:.15rem 0; }
.dr2-tile__btns { margin-top:auto; display:grid; grid-template-columns:repeat(3,1fr); gap:.22rem; padding-top:.4rem; }
.dr2-tile__btns form { display:block; margin:0; }
.dr2-bt { display:block; width:100%; box-sizing:border-box; text-align:center; font-family:inherit; font-size:.68rem;
    line-height:1.1; padding:.28rem .1rem; border:1px solid var(--corex-border,#e5e7eb); border-radius:6px;
    background:#fff; color:#374151; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dr2-bt:hover { background:#f9fafb; }
.dr2-bt--go { color:#047857; border-color:#6ee7b7; }
.dr2-bt--danger { color:#b91c1c; }
.dr2-bt--dis { color:#c7cdd6; background:#fafbfc; cursor:not-allowed; }
.dr2-band { margin:.4rem 0; border:1px dashed #cbd5e1; border-radius:10px; padding:.55rem .5rem; position:relative; }
.dr2-band__tag { position:absolute; top:-.6rem; left:.7rem; background:var(--surface,#fff); padding:0 .35rem; font-size:.66rem; color:#94a3b8; letter-spacing:.03em; }
.dr2-band__lanes { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.15rem; align-items:flex-start; }
.dr2-lane { display:flex; flex-direction:column; align-items:stretch; gap:.15rem; flex:0 0 auto; }
.dr2-lane__link { text-align:center; color:#cbd5e1; font-size:.7rem; line-height:.7; }
.dr2-seq { position:relative; padding-left:.55rem; margin:.4rem 0; }
.dr2-seq__rail { position:absolute; left:0; top:.15rem; bottom:.15rem; width:4px; border-radius:3px; background:#2563eb; }
.dr2-modal { position:fixed; inset:0; z-index:120; display:flex; align-items:center; justify-content:center; padding:1rem; }
.dr2-modal__bg { position:absolute; inset:0; background:rgba(15,23,42,.4); }
.dr2-modal__card { position:relative; z-index:1; background:var(--surface,#fff); border-radius:12px; padding:1rem 1.1rem; width:min(420px,92vw); box-shadow:0 10px 40px rgba(2,20,40,.28); }
.dr2-modal__card--wide { width:min(560px,94vw); }
.dr2-modal__h { margin:0 0 .7rem; font-size:.95rem; font-weight:700; color:#111827; }
.dr2-modal__lb { display:block; font-size:.78rem; color:#374151; margin-bottom:.55rem; }
.dr2-modal__row { display:flex; justify-content:flex-end; gap:.5rem; margin-top:.9rem; }
.dr2-modal__cmform { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.dr2-modal__thread { max-height:38vh; overflow-y:auto; border:1px solid var(--corex-border,#e5e7eb); border-radius:8px; padding:.5rem; }
.dr2-cmt { font-size:.8rem; margin-bottom:.35rem; color:#374151; }
.dr2-cmt__by { color:#9ca3af; font-size:.72rem; }
.dr2-cmt__empty { font-size:.78rem; color:#9ca3af; }
</style>
<div class="corex-page">
    <div class="corex-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 class="corex-page-title">Deal Pipeline</h1>
            <p class="corex-page-subtitle">
                Deal {{ $deal->deal_no ?? $deal->id }}
                @if($deal->property_address) — {{ $deal->property_address }} @endif
            </p>
        </div>
        <a href="{{ route('deals-dr2.index') }}" class="corex-btn-secondary">← DR2 Register</a>
    </div>

    @if(session('info'))
        <div class="corex-alert corex-alert-info" style="margin:1rem 0;">{{ session('info') }}</div>
    @endif
    @if(session('error'))
        <div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>
    @endif

    {{--
        AT-244 — the lock banner. A locked surface must SAY why it is locked and OFFER the
        action that unlocks it (STANDARDS — "No Silent Locks"). The way out is the deal's
        own status control on the register: a declined deal stays re-grantable. We link
        there rather than inventing a second revival mechanism.
    --}}
    @if($locked)
        <div class="corex-card" role="status"
             style="margin:1rem 0;padding:1rem 1.15rem;display:flex;align-items:flex-start;gap:.75rem;
                    border-left:4px solid var(--ds-crimson,#c41e3a);background:var(--surface,#fff);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                 stroke="var(--ds-crimson,#c41e3a)" style="width:1.4rem;height:1.4rem;flex:0 0 auto;margin-top:.1rem;">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
            <div style="flex:1 1 auto;min-width:0;">
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                    <span class="ds-badge ds-badge-danger">Pipeline locked</span>
                    <strong style="font-size:.95rem;">{{ $lockReason }}</strong>
                </div>
                <p style="margin:.4rem 0 .6rem;font-size:.85rem;color:var(--text-muted,#6b7280);">{{ $unlockHint }}</p>
                <a href="{{ route('deals-dr2.index') }}" class="corex-btn-secondary"
                   style="padding:.3rem .75rem;font-size:.8rem;">Reinstate on the deal register →</a>
            </div>
        </div>
    @endif

    {{-- AT-305 — two columns (stacks on mobile). Left: pipeline. Right: sticky docs/proforma rail. --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 items-start dr2-pipe-grid" style="margin-top:1rem;">

        {{-- ── LEFT (≈60%): the pipeline step board — own scroll region ─────────── --}}
        <div class="lg:col-span-3 space-y-4 min-w-0 dr2-pipe-col">
    @if($steps->isEmpty() && $locked)
        {{-- Declined and never worked: no pipeline to show, and none may be started. --}}
        <div class="corex-card" style="padding:1.5rem;">
            <h2 style="margin:0 0 .5rem;font-size:1.05rem;">No pipeline</h2>
            <p style="margin:0;color:var(--text-muted,#6b7280);font-size:.9rem;">
                This deal was declined without a pipeline being started, and a pipeline cannot be
                started on a deal that is not proceeding. Reinstate it on the register first.
            </p>
        </div>
    @elseif($steps->isEmpty())
        {{-- AT-334 — empty-state points at the Deal Structure tab (the new-model path). --}}
        <div class="corex-card" style="padding:1.5rem;margin-bottom:1rem;text-align:center;">
            <div style="font-size:2rem;line-height:1;margin-bottom:.5rem;">🧩</div>
            <h2 style="margin:0 0 .4rem;font-size:1.05rem;">Complete the deal structure to build your pipeline</h2>
            <p style="margin:0 0 .9rem;color:var(--text-muted,#6b7280);font-size:.9rem;">
                Choose this deal's suspensive conditions (cash, bond, subject-to-sale) and the pipeline assembles itself — with the right steps, milestones and dates.
            </p>
            <button type="button" class="corex-btn-primary" onclick="document.querySelector('.dr2-tabbar')?.scrollIntoView({behavior:'smooth'}); window.dispatchEvent(new CustomEvent('dr2-open-structure'))"
                    style="font-size:.9rem;">Open Deal Structure →</button>
        </div>
        {{-- Fallback: the standard-template attach still available. --}}
        <div class="corex-card" style="padding:1.5rem;">
            <h2 style="margin:0 0 .75rem;font-size:1.05rem;">Or attach a standard template</h2>
            @if($templates->isEmpty())
                <p style="color:var(--corex-text-muted,#6b7280);">
                    No active pipeline templates for this agency yet. Create one under
                    @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
                        <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-link">Pipeline Setup</a>.
                    @else Pipeline Setup. @endif
                </p>
            @else
            @permission('view_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.attach', $deal) }}">
                @csrf
                <label for="template_id" style="display:block;margin-bottom:.35rem;font-weight:600;">
                    Template
                    @if($deal->deal_type)
                        <span style="font-weight:400;color:var(--corex-text-muted,#6b7280);">— defaulted from deal type "{{ $deal->deal_type }}"; change if needed</span>
                    @endif
                </label>
                <select name="template_id" id="template_id" class="corex-input" required style="width:100%;">
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}" {{ (isset($defaultTemplateId) && $t->id === $defaultTemplateId) ? 'selected' : '' }}>{{ $t->name }}{{ $t->deal_type ? ' · '.$t->deal_type : '' }}{{ $t->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-primary" style="margin-top:1rem;">Attach pipeline</button>
            </form>
            @endpermission
            @endif
        </div>
    @else
        {{-- Step board — dense one-liner rows (AT-305). Per-step operations, COC work
             orders and comments expand in place. AT-244: when the deal is not proceeding
             the board is MUTED and every state-changing action is withdrawn (no dead
             buttons); comments stay live (history-keeping, not a transition). --}}
        <div class="corex-card" style="padding:.25rem .5rem;{{ $locked ? 'opacity:.72;filter:grayscale(.35);' : '' }}"
             x-data="{ hideDone: false }" x-init="hideDone = (localStorage.getItem('dr2_hide_completed') === '1')">
            {{-- AT-305b — Hide-completed toggle. Persists per user via localStorage; default
                 off (show all). Only offered when there are completed/terminal steps to hide. --}}
            @if($completedCount > 0)
            <div style="display:flex;align-items:center;justify-content:flex-end;gap:.5rem;padding:.35rem .4rem;border-bottom:1px solid var(--corex-border,#e5e7eb);">
                <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;color:#374151;cursor:pointer;">
                    <input type="checkbox" x-model="hideDone" @change="localStorage.setItem('dr2_hide_completed', hideDone ? '1' : '0')">
                    Hide completed steps
                </label>
                <span x-show="hideDone" x-cloak style="font-size:.72rem;color:#6b7280;">({{ $completedCount }} hidden)</span>
            </div>
            @endif
            @php($rowById = $steps->keyBy(fn ($r) => (int) $r['model']->id))
            @if(($isNewModel ?? false) && !empty($board) && ($board['gate'] || !empty($board['stage2']) || !empty($board['stage1']) || $board['anchor']))
                {{-- AT-334 (concurrent-lanes rework) — CLEAN LANE BOARD (new-model deals):
                     Anchor → Stage 1 condition lanes → Granted GATE (full-width bar) → Stage 2
                     (full-width SEQUENCE POINTS + dashed CONCURRENT BANDS of vertical-chain
                     lanes). Every step is a UNIFORM tile with the fixed 3×2 button grid.
                     Segment structure comes from DealLaneComposer. --}}
                @if($board['anchor'] && $rowById->has($board['anchor']->id))
                    @include('dr2._pipeline-step-tile', ['row' => $rowById[$board['anchor']->id], 'variant' => 'wide'])
                @endif

                @if(!empty($board['stage1']))
                    <div class="dr2-stage-h">Stage 1 · Suspensive Conditions<span>all must be met to grant · run in parallel</span></div>
                    @include('dr2._pipeline-segments', ['segments' => $board['stage1'], 'rowById' => $rowById])
                @endif

                @if($board['gate'] && $rowById->has($board['gate']->id))
                    @include('dr2._pipeline-step-tile', ['row' => $rowById[$board['gate']->id], 'variant' => 'gate'])
                @endif

                @if(!empty($board['stage2']))
                    <div class="dr2-stage-h">Stage 2 · Transfer &amp; Registration<span>runs once granted · sequence points span the width; concurrent work sits in lanes</span></div>
                    @include('dr2._pipeline-segments', ['segments' => $board['stage2'], 'rowById' => $rowById])
                @endif
            @else
                @foreach($steps as $row)
                    @include('dr2._pipeline-step-row', ['row' => $row])
                @endforeach
            @endif

            {{-- R2 — Removed steps (soft-deleted) with per-step Restore. No permanent stranding. --}}
            @if($removedSteps->isNotEmpty())
            <div x-data="{ rm:false }" style="padding:.5rem .25rem;border-top:2px solid var(--corex-border,#e5e7eb);">
                <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;color:#b45309;" @click="rm = !rm">Removed steps ({{ $removedSteps->count() }})</button>
                <div x-show="rm" x-cloak style="margin-top:.5rem;">
                    @foreach($removedSteps as $rs)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.35rem 0;font-size:.85rem;">
                        <span style="text-decoration:line-through;color:#6b7280;">{{ $rs->name }}</span>
                        @unless($locked)
                        @permission('view_deals')
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.restore', $deal) }}">@csrf
                            <input type="hidden" name="step_id" value="{{ $rs->id }}">
                            <button type="submit" class="corex-btn-secondary" style="padding:.15rem .6rem;font-size:.75rem;">Restore</button>
                        </form>
                        @endpermission
                        @endunless
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Add a custom step --}}
            @unless($locked)
            @permission('view_deals')
            <div x-data="{ add:false }" style="padding:.5rem .25rem;">
                <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="add = !add">+ Add custom step</button>
                <div x-show="add" x-cloak style="margin-top:.5rem;">
                    <form method="POST" action="{{ route('deals-dr2.pipeline.step.add', $deal) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">@csrf
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Step name</label>
                            <input type="text" name="name" required placeholder="e.g. Plans approved" class="corex-input" style="font-size:.85rem;"></div>
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Due date</label>
                            <input type="date" name="due_date" class="corex-input" style="font-size:.85rem;"></div>
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Insert after</label>
                            <select name="after_step_id" class="corex-input" style="font-size:.85rem;">
                                <option value="">— at the end —</option>
                                @foreach($steps as $r2)
                                    <option value="{{ $r2['model']->id }}">{{ $r2['model']->name }}</option>
                                @endforeach
                            </select></div>
                        <button type="submit" class="corex-btn-primary" style="padding:.3rem .8rem;font-size:.82rem;">Add step</button>
                    </form>
                </div>
            </div>
            @endpermission
            @endunless
        </div>
    @endif
        </div>{{-- /LEFT --}}

        {{-- ── RIGHT (≈40%): own scroll region. AT-331 — the four sections are now TABS
             (one visible at a time) instead of a single tall stack, so nothing (esp. the
             "Email Parties" send action) is ever pushed off-screen. Layout-only: each pane
             just holds the SAME partial as before; no controller/route/permission change.
             Active tab persists per user via localStorage. --}}
        <div class="lg:col-span-2 min-w-0 dr2-pipe-col"
             x-data="{ tab: ({{ $hasPipeline ? 'true' : 'false' }} ? (window.localStorage.getItem('dr2_right_tab') || 'wo') : 'structure') }"
             x-init="$watch('tab', v => window.localStorage.setItem('dr2_right_tab', v))"
             @dr2-open-structure.window="tab='structure'">

            <div class="dr2-tabbar" role="tablist" aria-label="Deal right panel">
                <button type="button" class="dr2-tab" :class="tab==='structure' ? 'corex-tab-active' : ''" @click="tab='structure'" role="tab" :aria-selected="tab==='structure'">Deal Structure</button>
                {{-- AT-334 P3 — turns RED when a work order is held awaiting a supplier. --}}
                @php($woAtt = ($woNeedsAttention ?? false))
                <button type="button" class="dr2-tab" :class="tab==='wo'    ? 'corex-tab-active' : ''" @click="tab='wo'"    role="tab" :aria-selected="tab==='wo'" style="{{ $woAtt ? 'color:#b91c1c;font-weight:700;' : '' }}" title="{{ $woAtt ? 'A work order is waiting for a supplier' : '' }}">Supplier Work Orders{!! $woAtt ? ' <span aria-hidden=&quot;true&quot; style=&quot;color:#dc2626&quot;>&#9679;</span>' : '' !!}</button>
                <button type="button" class="dr2-tab" :class="tab==='docs'  ? 'corex-tab-active' : ''" @click="tab='docs'"  role="tab" :aria-selected="tab==='docs'">Documents</button>
                <button type="button" class="dr2-tab" :class="tab==='email' ? 'corex-tab-active' : ''" @click="tab='email'" role="tab" :aria-selected="tab==='email'">Email Parties</button>
                <button type="button" class="dr2-tab" :class="tab==='pi'    ? 'corex-tab-active' : ''" @click="tab='pi'"    role="tab" :aria-selected="tab==='pi'">Proforma Invoice</button>
            </div>

            {{-- AT-334 — Deal Structure: pick the suspensive conditions → assemble the pipeline. --}}
            <div x-show="tab==='structure'" x-cloak role="tabpanel">
                @include('dr2._deal-structure', ['deal' => $deal, 'conditionCatalog' => $conditionCatalog, 'dealConditions' => $dealConditions, 'hasPipeline' => $hasPipeline, 'locked' => $locked])
            </div>
            {{-- AT-229 §17 — Supplier Work Orders config (NOT a modal). Wrapped as a pane; no logic change. --}}
            <div x-show="tab==='wo'" x-cloak role="tabpanel">
                @include('dr2._supplier-work-orders', ['deal' => $deal, 'steps' => $steps, 'locked' => $locked])
            </div>
            {{-- DR2 deal documents (AT-225/226) — list + upload. --}}
            <div x-show="tab==='docs'" x-cloak role="tabpanel">
                @include('dr2._deal-documents', ['deal' => $deal])
            </div>
            {{-- AT-331 — Email Parties: the AT-228 party-first distribution block, now its own tab. --}}
            <div x-show="tab==='email'" x-cloak role="tabpanel">
                @include('dr2._email-parties', ['deal' => $deal])
            </div>
            {{-- Proforma Invoices (Accounting pillar) — generate from Granted onward. Wrapped as a pane; no logic change. --}}
            <div x-show="tab==='pi'" x-cloak role="tabpanel">
                @include('proforma._deal-section', ['deal' => $deal])
            </div>
        </div>
    </div>{{-- /grid --}}
</div>
@endsection
