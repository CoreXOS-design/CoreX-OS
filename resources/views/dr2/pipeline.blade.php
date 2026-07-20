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
    .dr2-pipe-grid { height: calc(100vh - 9.5rem); }
    .dr2-pipe-col  { max-height: 100%; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding-right: .35rem; }
}
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
            @foreach($steps as $row)
                @php($s = $row['model'])
                @php($badge = $row['na'] ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
                @php($terminal = in_array($s->status, ['completed', 'skipped'], true))
                <div x-data="{ na:false, cm:false, due:false }"@if($terminal) x-show="!hideDone" x-cloak @endif style="border-bottom:1px solid var(--corex-border,#e5e7eb);padding:.4rem .25rem;{{ $row['na'] ? 'opacity:.6;' : '' }}">

                    {{-- ONE-LINER: dot · name(+tags) · due · badge · compact inline actions --}}
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <span title="{{ ucfirst($row['rag']) }}" style="flex:0 0 auto;display:inline-block;width:.65rem;height:.65rem;border-radius:50%;background:{{ $row['colour'] }};"></span>

                        <span style="flex:1 1 200px;min-width:0;{{ $row['na'] ? 'text-decoration:line-through;' : '' }}white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <strong style="font-size:.9rem;">{{ $s->name }}</strong>
                            @if($s->is_milestone)<span title="Milestone" style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>@endif
                            @if($s->is_custom)<span title="Custom step" style="font-size:.7rem;color:#2563eb;margin-left:.35rem;">+ custom</span>@endif
                        </span>

                        <span style="flex:0 0 auto;font-size:.78rem;color:#6b7280;white-space:nowrap;">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</span>

                        <span style="flex:0 0 auto;display:inline-block;padding:.12rem .5rem;border-radius:1rem;font-size:.7rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>

                        {{-- Compact inline actions (all functionality preserved) --}}
                        <div style="flex:1 1 auto;display:inline-flex;gap:.3rem;flex-wrap:wrap;align-items:center;justify-content:flex-end;">
                        @unless($locked)
                        @if($s->status === 'active')
                            @permission('view_deals')
                            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                                <button type="submit" class="corex-btn-secondary" style="padding:.12rem .5rem;font-size:.72rem;">{{ $s->negative_status_trigger ? 'Mark passed' : 'Mark complete' }}</button>
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

                        {{-- AT-229 COC sub-process — per-deal work-order panel: select the COCs this
                             deal needs, set each one's responsible party + recipient, and send
                             (listing + selling agents CC'd, de-duped). Shows when the step offers COCs. --}}
                        @php($canWo = ! $locked && auth()->user()?->hasPermission('deals_v2.distribute_documents'))
                        @php($offersCoc = $canWo && optional($s->pipelineStep)->workOrders && $s->pipelineStep->workOrders->isNotEmpty() && in_array($s->status, ['active','completed']))
                        @if($offersCoc)
                        @php($offeredTypes = $s->pipelineStep->workOrders->pluck('service_type')->filter()->unique()->values()->all())
                        <span x-data="{
                                open:false, loading:false, busy:false, err:'', msg:'',
                                types: @js($offeredTypes), responsible:{}, suppliers:[], rows:[],
                                sendBase: '{{ route('deals-dr2.pipeline.step.coc.send', [$deal, $s, '__WO__']) }}',
                                async load(){
                                    this.open=true; this.loading=true; this.err=''; this.msg='';
                                    try { const r = await fetch('{{ route('deals-dr2.pipeline.step.coc.panel', [$deal, $s]) }}', {headers:{'Accept':'application/json'},credentials:'same-origin'}); const j = await r.json();
                                        this.responsible=j.responsible_labels||{}; this.suppliers=j.suppliers||[]; if(j.offered_types&&j.offered_types.length) this.types=j.offered_types;
                                        this.rows=(j.work_orders||[]).map(w=>({id:w.id,service_type:w.service_type,responsible_party:w.responsible_party,service_provider_id:w.service_provider_id||'',status:w.status,recipient_email:w.recipient_email,cc_emails:w.cc_emails}));
                                    } catch(e){ this.err='Could not load work orders.'; }
                                    this.loading=false;
                                },
                                async save(){
                                    this.busy=true; this.err='';
                                    try { const r = await fetch('{{ route('deals-dr2.pipeline.step.coc.sync', [$deal, $s]) }}', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},credentials:'same-origin',body:JSON.stringify({work_orders:this.rows})});
                                        const j = await r.json(); if(r.ok&&j.ok){ this.msg='Saved.'; await this.load(); } else { this.err=(j.errors?Object.values(j.errors).flat().join(' '):'Save failed.'); }
                                    } catch(e){ this.err='Save failed.'; }
                                    this.busy=false;
                                },
                                async send(w){
                                    if(!w.id){ this.err='Save first, then send.'; return; }
                                    this.busy=true; this.err='';
                                    try { const r = await fetch(this.sendBase.replace('__WO__', w.id), {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},credentials:'same-origin',body:JSON.stringify({})});
                                        const j = await r.json(); if(r.ok&&j.ok){ this.msg=j.message||'Sent.'; await this.load(); } else { this.err=j.message||'Send failed.'; }
                                    } catch(e){ this.err='Send failed.'; }
                                    this.busy=false;
                                }
                             }" style="display:inline;">
                            <button type="button" class="corex-btn-outline" style="padding:.12rem .5rem;font-size:.72rem;color:#0f766e;border-color:#0f766e;" @click="load()">Work orders (COCs)</button>
                            <div x-show="open" x-cloak @click.self="open=false" style="position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:1rem;">
                                <div class="corex-card" style="width:100%;max-width:760px;max-height:90vh;display:flex;flex-direction:column;padding:0;" @click.stop>
                                    <div style="padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                                        <strong style="font-size:.9rem;">Work orders (COCs) — {{ $s->name }}</strong>
                                        <button type="button" @click="open=false" style="font-size:1.2rem;line-height:1;color:#6b7280;">&times;</button>
                                    </div>
                                    <div style="padding:1rem;overflow-y:auto;flex:1;">
                                        <div x-show="loading" style="color:#6b7280;font-size:.85rem;">Loading…</div>
                                        <div x-show="!loading">
                                            <p style="font-size:.72rem;color:#6b7280;margin-bottom:.5rem;">Pick the COCs this deal needs + who is responsible for each; Save, then Send. Listing &amp; selling agents are CC'd (de-duped, one email per address).</p>
                                            <template x-for="(w,i) in rows" :key="i">
                                              <div style="border-top:1px solid #eee;padding:.5rem 0;">
                                                <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.4rem;align-items:end;">
                                                  <div>
                                                    <label style="font-size:.66rem;color:#6b7280;">COC / service</label>
                                                    <select x-model="w.service_type" :disabled="w.status==='sent'" class="corex-input" style="width:100%;font-size:.8rem;">
                                                      <template x-for="t in types" :key="t"><option :value="t" x-text="t"></option></template>
                                                      <option value="Other">Other</option>
                                                    </select>
                                                  </div>
                                                  <div>
                                                    <label style="font-size:.66rem;color:#6b7280;">Responsible / recipient</label>
                                                    <select x-model="w.responsible_party" :disabled="w.status==='sent'" class="corex-input" style="width:100%;font-size:.8rem;">
                                                      <template x-for="(lbl,val) in responsible" :key="val"><option :value="val" x-text="lbl"></option></template>
                                                    </select>
                                                    <select x-show="w.responsible_party==='supplier' || w.responsible_party==='transfer_attorney'" x-model="w.service_provider_id" :disabled="w.status==='sent'" class="corex-input" style="width:100%;font-size:.8rem;margin-top:.25rem;">
                                                      <option value="">— pick supplier —</option>
                                                      <template x-for="s in suppliers" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                                                    </select>
                                                  </div>
                                                  <div style="display:flex;gap:.35rem;align-items:center;">
                                                    <span x-show="w.status==='sent'" style="font-size:.7rem;color:#047857;">✓ sent</span>
                                                    <button type="button" x-show="w.status!=='sent' && w.id" @click="send(w)" :disabled="busy" class="corex-btn-secondary" style="font-size:.72rem;padding:.2rem .5rem;">Send</button>
                                                    <button type="button" x-show="w.status!=='sent'" @click="rows.splice(i,1)" style="font-size:.85rem;color:#b91c1c;">✕</button>
                                                  </div>
                                                </div>
                                                <div x-show="w.recipient_email" style="font-size:.66rem;color:#6b7280;margin-top:.2rem;" x-text="'→ ' + (w.recipient_email||'') + (w.cc_emails ? '  (cc ' + w.cc_emails + ')' : '')"></div>
                                              </div>
                                            </template>
                                            <button type="button" @click="rows.push({service_type:(types[0]||'COC'), responsible_party:'supplier', service_provider_id:'', status:'pending', id:null})" style="font-size:.75rem;color:#0f766e;margin-top:.6rem;">+ Add COC</button>
                                            <div x-show="err" x-text="err" style="color:#b91c1c;font-size:.78rem;margin-top:.5rem;"></div>
                                            <div x-show="msg" x-text="msg" style="color:#047857;font-size:.78rem;margin-top:.5rem;"></div>
                                        </div>
                                    </div>
                                    <div style="padding:.75rem 1rem;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:.5rem;">
                                        <button type="button" @click="open=false" class="corex-btn-outline" style="font-size:.8rem;">Close</button>
                                        <button type="button" @click="save()" :disabled="busy" class="corex-btn-primary" style="font-size:.8rem;" x-text="busy ? 'Working…' : 'Save selection'"></button>
                                    </div>
                                </div>
                            </div>
                        </span>
                        @endif
                        </div>{{-- /compact inline actions --}}
                    </div>{{-- /one-liner --}}

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

        {{-- ── RIGHT (≈40%): own scroll region — Documents · Send to a party · Proforma --}}
        <div class="lg:col-span-2 space-y-4 min-w-0 dr2-pipe-col">
            {{-- DR2 deal documents (AT-225/226) — upload + Send documents to a party --}}
            @include('dr2._deal-documents', ['deal' => $deal])
            {{-- Proforma Invoices (Accounting pillar) — generate from Granted onward --}}
            @include('proforma._deal-section', ['deal' => $deal])
        </div>
    </div>{{-- /grid --}}
</div>
@endsection
