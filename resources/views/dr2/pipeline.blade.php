{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-216 (DR2 · WS-PIPELINE) — pipeline tracking board for one DR2 deal.
    PURE TRACKING OVERLAY: attaching a pipeline / completing a step never changes the
    DR1 deal's state — only the pipeline's own steps + the deal's pipeline pointer.
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

    @if($steps->isEmpty() && $locked)
        {{-- Declined and never worked: no pipeline to show, and none may be started. --}}
        <div class="corex-card" style="margin-top:1rem;padding:1.5rem;max-width:520px;">
            <h2 style="margin:0 0 .5rem;font-size:1.05rem;">No pipeline</h2>
            <p style="margin:0;color:var(--text-muted,#6b7280);font-size:.9rem;">
                This deal was declined without a pipeline being started, and a pipeline cannot be
                started on a deal that is not proceeding. Reinstate it on the register first.
            </p>
        </div>
    @elseif($steps->isEmpty())
        {{-- No pipeline yet → attach one. --}}
        <div class="corex-card" style="margin-top:1rem;padding:1.5rem;max-width:520px;">
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
        {{-- Step board — one card per step, with per-step operations + comments.
             AT-244: when the deal is not proceeding the board is MUTED and every
             state-changing action is withdrawn (no dead buttons — a blocked action is
             hidden, per STANDARDS). Comments stay live: annotating why a deal fell
             through is history-keeping, not a stage transition. --}}
        <div class="corex-card" style="margin-top:1rem;padding:.5rem;{{ $locked ? 'opacity:.72;filter:grayscale(.35);' : '' }}">
            @foreach($steps as $row)
                @php($s = $row['model'])
                @php($badge = $row['na'] ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
                @php($terminal = in_array($s->status, ['completed', 'skipped'], true))
                <div x-data="{ na:false, cm:false, due:false }" style="border-bottom:1px solid var(--corex-border,#e5e7eb);padding:.65rem .5rem;{{ $row['na'] ? 'opacity:.6;' : '' }}">
                    <div style="display:flex;align-items:flex-start;gap:.6rem;">
                        <span title="{{ ucfirst($row['rag']) }}" style="flex:0 0 auto;margin-top:.35rem;display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:{{ $row['colour'] }};"></span>
                        <div style="flex:1 1 auto;min-width:0;">
                            <div style="{{ $row['na'] ? 'text-decoration:line-through;' : '' }}">
                                <strong>{{ $s->name }}</strong>
                                @if($s->is_milestone)<span style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>@endif
                                @if($s->is_custom)<span style="font-size:.7rem;color:#2563eb;margin-left:.35rem;">+ custom</span>@endif
                            </div>
                            @if($row['blocked'])<div style="font-size:.75rem;color:#6b7280;">{{ $row['blocked'] }}</div>@endif
                            @if($row['na'] && $s->na_reason)<div style="font-size:.75rem;color:#6b7280;">Excused: {{ $s->na_reason }}</div>@endif
                        </div>
                        <div style="flex:0 0 auto;text-align:right;font-size:.8rem;">
                            <span style="display:inline-block;padding:.15rem .5rem;border-radius:1rem;font-size:.72rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>
                            <div style="color:#6b7280;margin-top:.15rem;">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</div>
                        </div>
                    </div>

                    {{-- Action bar --}}
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem;padding-left:1.3rem;">
                        @unless($locked)
                        @if($s->status === 'active')
                            @permission('view_deals')
                            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                                <button type="submit" class="corex-btn-secondary" style="padding:.2rem .6rem;font-size:.78rem;">Mark complete</button>
                            </form>
                            @endpermission
                        @endif
                        @unless($terminal)
                            @permission('view_deals')
                            <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="na = !na">N/A</button>
                            @endpermission
                        @endunless
                        @if($row['na'])
                            @permission('view_deals')
                            <form method="POST" action="{{ route('deals-dr2.pipeline.step.reinstate', [$deal, $s]) }}">@csrf
                                <button type="submit" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;">Reinstate</button>
                            </form>
                            @endpermission
                        @endif
                        @permission('view_deals')
                        <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="due = !due">Edit due</button>
                        @endpermission
                        @permission('view_deals')
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf
                            <button type="submit" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;color:#b91c1c;">Remove</button>
                        </form>
                        @endpermission
                        @endunless

                        {{-- Comments survive the lock (history-keeping, not a transition). --}}
                        @permission('view_deals')
                        <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="cm = !cm">Comments ({{ $s->comments->count() }})</button>
                        @endpermission

                        {{-- AT-229 — OPTIONAL "Send work order" (Non-neg #2 entry point). Shows only
                             when this step's config sends a work order and it is at its trigger point. --}}
                        @php($canWo = ! $locked && auth()->user()?->hasPermission('deals_v2.distribute_documents'))
                        @php($stepWorkOrders = $canWo ? (optional($s->pipelineStep)->workOrders ?? collect())->filter(fn ($w) => (($w->trigger_point ?: 'activated') === 'activated' && $s->status === 'active') || (($w->trigger_point ?: 'activated') === 'completed' && $s->status === 'completed')) : collect())
                        @foreach($stepWorkOrders as $wo)
                        <span x-data="{
                                serviceType: '{{ addslashes((string) $wo->service_type) }}',
                                open:false, loading:false, sending:false, err:'', ok:'',
                                fields:{}, suppliers:[], supplierId:'', contactId:'',
                                newSupplier:{ name:'', company:'', email:'', phone:'' },
                                fieldKeys:['date','service_label','property_address','seller_name','seller_email','seller_tel','purchaser_name','purchaser_tel','attorneys','rep_name','rep_email','rep_tel','keys_name','keys_tel','payer','notes'],
                                labels:{date:'Date',service_label:'Service',property_address:'Property',seller_name:'Seller',seller_email:'Seller email',seller_tel:'Seller tel',purchaser_name:'Purchaser',purchaser_tel:'Purchaser tel',attorneys:'Attorneys',rep_name:'Representative',rep_email:'Rep email',rep_tel:'Rep tel',keys_name:'Keys held by',keys_tel:'Keys tel',payer:'Invoice payer',notes:'Notes'},
                                wide:['property_address','attorneys','notes'],
                                get chosen(){ return this.suppliers.find(s => String(s.id) === String(this.supplierId)); },
                                get contacts(){ return this.chosen?.service_contacts || []; },
                                async load(){
                                    this.open=true; this.loading=true; this.err=''; this.ok='';
                                    try { const r = await fetch('{{ route('deals-dr2.pipeline.step.work-order.form', [$deal, $s]) }}' + '?service_type=' + encodeURIComponent(this.serviceType), { headers:{'Accept':'application/json'}, credentials:'same-origin' }); const j = await r.json(); this.fields = j.fields || {}; this.suppliers = j.suppliers || []; }
                                    catch(e){ this.err='Could not load the work order form.'; }
                                    this.loading=false;
                                },
                                async send(){
                                    this.sending=true; this.err='';
                                    const body = { ...this.fields, service_type: this.serviceType };
                                    if (this.supplierId === '__new__'){ Object.assign(body, { supplier_name:this.newSupplier.name, supplier_company:this.newSupplier.company, supplier_email:this.newSupplier.email, supplier_phone:this.newSupplier.phone }); }
                                    else { body.service_provider_id = this.supplierId; body.service_provider_contact_id = this.contactId || null; }
                                    try {
                                        const r = await fetch('{{ route('deals-dr2.pipeline.step.work-order.send', [$deal, $s]) }}', { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}, credentials:'same-origin', body: JSON.stringify(body) });
                                        const j = await r.json();
                                        if (r.ok && j.ok){ this.ok = j.message || 'Work order sent.'; setTimeout(()=>{ this.open=false; window.location.reload(); }, 1400); }
                                        else { this.err = j.message || 'Send failed.'; }
                                    } catch(e){ this.err='Send failed.'; }
                                    this.sending=false;
                                }
                             }" style="display:inline;">
                            <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;color:#0f766e;border-color:#0f766e;" @click="load()">Send work order{{ $wo->service_type ? ' — '.$wo->service_type : '' }}</button>

                            <div x-show="open" x-cloak @click.self="open=false" style="position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:1rem;">
                                <div class="corex-card" style="width:100%;max-width:640px;max-height:90vh;display:flex;flex-direction:column;padding:0;" @click.stop>
                                    <div style="padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
                                        <strong style="font-size:.9rem;">Send work order — {{ $s->name }}</strong>
                                        <button type="button" @click="open=false" style="font-size:1.2rem;line-height:1;color:#6b7280;">&times;</button>
                                    </div>
                                    <div style="padding:1rem;overflow-y:auto;flex:1;">
                                        <div x-show="loading" style="color:#6b7280;font-size:.85rem;">Loading…</div>
                                        <div x-show="!loading">
                                            <label style="display:block;font-size:.72rem;color:#6b7280;margin-bottom:.2rem;">Supplier (chosen at send — never pre-selected)</label>
                                            <select x-model="supplierId" class="corex-input" style="width:100%;font-size:.82rem;margin-bottom:.5rem;">
                                                <option value="">— pick a supplier —</option>
                                                <template x-for="s in suppliers" :key="s.id"><option :value="s.id" x-text="s.name + (s.specialty ? ' ('+s.specialty+')' : '')"></option></template>
                                                <option value="__new__">+ Capture a new supplier</option>
                                            </select>
                                            <div x-show="supplierId === '__new__'" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:.5rem;">
                                                <input x-model="newSupplier.name" placeholder="Supplier name" class="corex-input" style="font-size:.82rem;">
                                                <input x-model="newSupplier.company" placeholder="Company (optional)" class="corex-input" style="font-size:.82rem;">
                                                <input x-model="newSupplier.email" type="email" placeholder="Email" class="corex-input" style="font-size:.82rem;">
                                                <input x-model="newSupplier.phone" placeholder="Phone (optional)" class="corex-input" style="font-size:.82rem;">
                                            </div>
                                            <div x-show="supplierId && supplierId !== '__new__' && contacts.length" style="margin-bottom:.5rem;">
                                                <label style="display:block;font-size:.72rem;color:#6b7280;margin-bottom:.2rem;">Send to contact (primary by default)</label>
                                                <select x-model="contactId" class="corex-input" style="width:100%;font-size:.82rem;">
                                                    <option value="">Firm email</option>
                                                    <template x-for="c in contacts" :key="c.id"><option :value="c.id" x-text="((c.contact_person||c.attorney_name||'Contact')) + (c.email ? ' — '+c.email : '')"></option></template>
                                                </select>
                                            </div>
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;border-top:1px solid #e5e7eb;padding-top:.5rem;">
                                                <template x-for="key in fieldKeys" :key="key">
                                                    <div :style="wide.includes(key) ? 'grid-column:1 / -1;' : ''">
                                                        <label style="display:block;font-size:.72rem;color:#6b7280;margin:.25rem 0 .15rem;" x-text="labels[key]"></label>
                                                        <input x-model="fields[key]" class="corex-input" style="width:100%;font-size:.82rem;">
                                                    </div>
                                                </template>
                                            </div>
                                            <div x-show="err" x-text="err" style="color:#b91c1c;font-size:.78rem;margin-top:.5rem;"></div>
                                            <div x-show="ok" x-text="ok" style="color:#047857;font-size:.78rem;margin-top:.5rem;"></div>
                                        </div>
                                    </div>
                                    <div style="padding:.75rem 1rem;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:.5rem;">
                                        <button type="button" @click="open=false" class="corex-btn-outline" style="font-size:.8rem;">Cancel</button>
                                        <button type="button" @click="send()" :disabled="sending || !supplierId" class="corex-btn-secondary" style="font-size:.8rem;" x-text="sending ? 'Sending…' : 'Send work order'"></button>
                                    </div>
                                </div>
                            </div>
                        </span>
                        @endforeach
                    </div>

                    {{-- N/A reason form --}}
                    @unless($terminal || $locked)
                    <div x-show="na" x-cloak style="margin:.4rem 0 0 1.3rem;">
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
                            <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Mark N/A</button>
                        </form>
                    </div>
                    @endunless

                    {{-- R2 — inline due-date edit (RAG recalcs off the edited date) --}}
                    @unless($locked)
                    @permission('view_deals')
                    <div x-show="due" x-cloak style="margin:.4rem 0 0 1.3rem;">
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.due', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">@csrf
                            <input type="date" name="due_date" value="{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('Y-m-d') : '' }}" class="corex-input" style="font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Save due date</button>
                        </form>
                    </div>
                    @endpermission
                    @endunless

                    {{-- Comment thread --}}
                    <div x-show="cm" x-cloak style="margin:.5rem 0 0 1.3rem;">
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
            <div x-data="{ rm:false }" style="padding:.65rem .5rem;border-top:2px solid var(--corex-border,#e5e7eb);">
                <button type="button" class="corex-btn-outline" style="padding:.25rem .7rem;font-size:.8rem;color:#b45309;" @click="rm = !rm">Removed steps ({{ $removedSteps->count() }})</button>
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
            <div x-data="{ add:false }" style="padding:.65rem .5rem;">
                <button type="button" class="corex-btn-outline" style="padding:.25rem .7rem;font-size:.8rem;" @click="add = !add">+ Add custom step</button>
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

    {{-- DR2 deal documents (AT-225/226) — upload files itself to deal + property + contacts --}}
    <div style="margin-top:1rem;">
        @include('dr2._deal-documents', ['deal' => $deal])
    </div>

    {{-- Proforma Invoices (Accounting pillar) — generate from Granted onward --}}
    <div style="margin-top:1rem;">
        @include('proforma._deal-section', ['deal' => $deal])
    </div>
</div>
@endsection
