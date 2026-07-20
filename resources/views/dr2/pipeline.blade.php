{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-216 (DR2 · WS-PIPELINE) — pipeline tracking board for one DR2 deal.
    PURE TRACKING OVERLAY: attaching a pipeline / completing a step never changes the
    DR1 deal's state — only the pipeline's own steps + the deal's pipeline pointer.

    AT-305 — two-column redesign (layout/density only; all functionality preserved).
    LEFT: pipeline steps as dense one-liner rows (status dot · step name · milestone ·
    due date · status badge, with Edit due / Remove / Comments as compact inline
    actions that expand in place). RIGHT: a sticky panel — Documents, Send documents
    to a party, Proforma Invoices — modelled on the buyer viewing-pack screen. Two
    columns on desktop with an independently-scrolling right rail; stacks on mobile.
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
@endphp
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
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 items-start" style="margin-top:1rem;">

        {{-- ── LEFT (60%): the pipeline step board ─────────────────────────────── --}}
        <div class="lg:col-span-3 space-y-4 min-w-0">
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
        {{-- No pipeline yet → attach one. --}}
        <div class="corex-card" style="padding:1.5rem;">
            <h2 style="margin:0 0 .75rem;font-size:1.05rem;">Attach a pipeline</h2>
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
        {{-- Step board — dense one-liner rows (AT-305). Per-step operations + comments
             expand in place. AT-244: when the deal is not proceeding the board is MUTED
             and every state-changing action is withdrawn (no dead buttons); comments
             stay live (annotating why a deal fell through is history, not a transition). --}}
        <div class="corex-card" style="padding:.25rem .5rem;{{ $locked ? 'opacity:.72;filter:grayscale(.35);' : '' }}">
            @foreach($steps as $row)
                @php($s = $row['model'])
                @php($badge = $row['na'] ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
                @php($terminal = in_array($s->status, ['completed', 'skipped'], true))
                <div x-data="{ na:false, cm:false, due:false }" style="border-bottom:1px solid var(--corex-border,#e5e7eb);padding:.4rem .25rem;{{ $row['na'] ? 'opacity:.6;' : '' }}">

                    {{-- ONE-LINER: dot · name · milestone/custom · due · badge · compact actions --}}
                    <div style="display:flex;align-items:center;gap:.55rem;flex-wrap:wrap;">
                        <span title="{{ ucfirst($row['rag']) }}" style="flex:0 0 auto;display:inline-block;width:.65rem;height:.65rem;border-radius:50%;background:{{ $row['colour'] }};"></span>

                        <span style="flex:1 1 220px;min-width:0;{{ $row['na'] ? 'text-decoration:line-through;' : '' }}white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <strong style="font-size:.9rem;">{{ $s->name }}</strong>
                            @if($s->is_milestone)<span title="Milestone" style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>@endif
                            @if($s->is_custom)<span title="Custom step" style="font-size:.7rem;color:#2563eb;margin-left:.35rem;">+ custom</span>@endif
                        </span>

                        <span style="flex:0 0 auto;font-size:.78rem;color:#6b7280;white-space:nowrap;">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</span>

                        <span style="flex:0 0 auto;display:inline-block;padding:.12rem .5rem;border-radius:1rem;font-size:.7rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>

                        {{-- Compact inline actions --}}
                        <span style="flex:0 0 auto;display:inline-flex;gap:.3rem;flex-wrap:wrap;align-items:center;">
                            @unless($locked)
                            @if($s->status === 'active')
                                @permission('view_deals')
                                <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                                    <button type="submit" class="corex-btn-secondary" style="padding:.12rem .5rem;font-size:.72rem;">Complete</button>
                                </form>
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
                        </span>
                    </div>

                    {{-- Secondary context (blocked reason / excused note) — only when present --}}
                    @if($row['blocked'])<div style="font-size:.73rem;color:#6b7280;margin:.2rem 0 0 1.2rem;">{{ $row['blocked'] }}</div>@endif
                    @if($row['na'] && $s->na_reason)<div style="font-size:.73rem;color:#6b7280;margin:.2rem 0 0 1.2rem;">Excused: {{ $s->na_reason }}</div>@endif

                    {{-- N/A reason form --}}
                    @unless($terminal || $locked)
                    <div x-show="na" x-cloak style="margin:.4rem 0 0 1.2rem;">
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
                            <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Mark N/A</button>
                        </form>
                    </div>
                    @endunless

                    {{-- R2 — inline due-date edit (RAG recalcs off the edited date) --}}
                    @unless($locked)
                    @permission('view_deals')
                    <div x-show="due" x-cloak style="margin:.4rem 0 0 1.2rem;">
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.due', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">@csrf
                            <input type="date" name="due_date" value="{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('Y-m-d') : '' }}" class="corex-input" style="font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Save due date</button>
                        </form>
                    </div>
                    @endpermission
                    @endunless

                    {{-- Comment thread --}}
                    <div x-show="cm" x-cloak style="margin:.5rem 0 0 1.2rem;">
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
            @endforeach

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

        {{-- ── RIGHT (40%): sticky rail — Documents · Send to a party · Proforma ──── --}}
        <div class="lg:col-span-2 lg:sticky lg:top-4 self-start space-y-4 min-w-0">
            {{-- DR2 deal documents (AT-225/226) — upload + Send documents to a party --}}
            @include('dr2._deal-documents', ['deal' => $deal])
            {{-- Proforma Invoices (Accounting pillar) — generate from Granted onward --}}
            @include('proforma._deal-section', ['deal' => $deal])
        </div>
    </div>{{-- /grid --}}
</div>
@endsection
