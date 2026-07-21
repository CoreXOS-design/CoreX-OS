{{-- AT-229 §17 — Supplier Work Orders, INLINE in the DR2 pipeline RIGHT column
     (alongside Documents / Send-to-party / Proforma). NOT a modal. Tick the COCs
     this deal needs up front, set responsible party + recipient per ticked one;
     un-ticked COCs cascade their pipeline step to N/A and ticked ones send when the
     trigger step completes (agents CC'd, de-duped). The WHEN/trigger step is defined
     in PIPELINE SETUP (the granting step), NOT selected here — Johan 2026-07-20. --}}
@php($canWoDeal = ! ($locked ?? false) && auth()->user()?->hasPermission('deals_v2.distribute_documents'))
@if($canWoDeal && ! $steps->isEmpty())
<div class="corex-card" style="padding:.75rem;"
     x-data="{
        loading:true, busy:false, err:'', msg:'',
        items:[], responsible:{}, suppliers:[],
        {{-- AT-319 — filter the supplier picker by the row's required type. Supplier rows match on
             the COC service-type code (it.code) against the supplier's types; attorney rows match on
             the legacy specialty. Prevent-or-absorb: zero matches → show ALL + a hint, never a dead
             dropdown; an untagged supplier is reachable via the fallback or by tagging it. --}}
        attorneySpecialties: ['transfer_attorney','conveyancer','bond_attorney'],
        matchingSuppliers(it){
            if(it.responsible_party==='transfer_attorney'){
                return this.suppliers.filter(s => this.attorneySpecialties.includes(s.specialty));
            }
            return this.suppliers.filter(s => Array.isArray(s.types) && s.types.includes(it.code));
        },
        suppliersFor(it){ const m=this.matchingSuppliers(it); return m.length ? m : this.suppliers; },
        isSupplierFallback(it){ return this.suppliers.length>0 && this.matchingSuppliers(it).length===0; },
        {{-- Item 2 — inline add-supplier (no navigating away). Creates the supplier TAGGED with THIS
             row's AgencyServiceType code so it appears in the type-filtered picker immediately, then
             selects it. `specialty` defaults to 'other' (attorney matching keys off specialty and is
             unaffected). --}}
        addingFor:null, newSup:{name:'',email:''}, addBusy:false, addErr:'',
        openAdd(it){ this.addingFor=it.code; this.newSup={name:'',email:''}; this.addErr=''; },
        cancelAdd(){ this.addingFor=null; this.addErr=''; },
        async submitAdd(it){
            if(!this.newSup.name.trim()){ this.addErr='Supplier name is required.'; return; }
            this.addBusy=true; this.addErr='';
            try {
                const r = await fetch('{{ route('deals-dr2.suppliers.inline') }}', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}, credentials:'same-origin', body: JSON.stringify({name:this.newSup.name.trim(), email:(this.newSup.email.trim()||null), specialty:'other', service_types:[it.code]})});
                const j = await r.json();
                if(r.ok && j.provider){ this.suppliers.push(j.provider); it.service_provider_id = j.provider.id; this.addingFor=null; }
                else { this.addErr=(j.errors?Object.values(j.errors).flat().join(' '):'Could not add supplier.'); }
            } catch(e){ this.addErr='Could not add supplier.'; }
            this.addBusy=false;
        },
        async load(){
            this.loading=true; this.err='';
            // Surface the post-save confirmation carried across the reload (item 1).
            try { const saved = sessionStorage.getItem('coc_saved_msg'); if(saved){ this.msg=saved; sessionStorage.removeItem('coc_saved_msg'); } } catch(e){}
            try { const r = await fetch('{{ route('deals-dr2.pipeline.coc-config.panel', $deal) }}', {headers:{'Accept':'application/json'},credentials:'same-origin'}); const j = await r.json();
                this.responsible=j.responsible_labels||{}; this.suppliers=j.suppliers||[];
                this.items=(j.items||[]).map(i=>({...i}));
            } catch(e){ this.err='Could not load supplier work orders.'; }
            this.loading=false;
        },
        async save(){
            this.busy=true; this.err=''; this.msg='';
            try { const r = await fetch('{{ route('deals-dr2.pipeline.coc-config.save', $deal) }}', {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},credentials:'same-origin',body:JSON.stringify({items:this.items.map(i=>({code:i.code, applies:!!i.applies, responsible_party:i.responsible_party, service_provider_id:i.service_provider_id||null}))})});
                const j = await r.json(); if(r.ok&&j.ok){
                    // Reflect the save across the WHOLE pipeline without a manual browser refresh.
                    // load() re-renders THIS panel, but the pipeline step board (server-rendered,
                    // left column) shows the step N/A changes and is stale — so reload the page and
                    // carry the confirmation across so the agent still sees the saved message.
                    // NB: keep this x-data attribute free of double-quotes — a literal double-quote
                    // here truncates the double-quoted HTML attribute and breaks the whole panel.
                    try { sessionStorage.setItem('coc_saved_msg','Saved. Un-ticked COCs marked N/A; ticked ones send when the trigger step completes.'); } catch(e){}
                    window.location.reload();
                    return;
                }
                else { this.err=(j.errors?Object.values(j.errors).flat().join(' '):'Save failed.'); }
            } catch(e){ this.err='Save failed.'; }
            this.busy=false;
        }
     }" x-init="load()">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.35rem;">
        <strong style="font-size:.9rem;color:#0f766e;">Supplier Work Orders</strong>
        <span x-show="loading" x-cloak style="font-size:.72rem;color:#6b7280;">Loading…</span>
    </div>
    <p style="font-size:.72rem;color:#6b7280;margin:0 0 .6rem;">Tick the COCs this deal needs and set who is responsible + who receives each work-order email. Un-ticked COCs are marked N/A on the pipeline. Work orders send when the trigger step completes; listing &amp; selling agents are CC'd (de-duped).</p>

    <div x-show="!loading" x-cloak>
        {{-- Trigger step is defined in PIPELINE SETUP (the granting step), not re-selected here. --}}
        {{-- Tick-list — one clean vertical row per COC; responsible/recipient appears under a ticked row --}}
        <template x-for="(it,i) in items" :key="it.code">
            <div style="border-top:1px solid #eee;padding:.5rem 0;">
                <label style="display:flex;align-items:center;gap:.45rem;font-size:.82rem;font-weight:600;cursor:pointer;" :style="it.status==='sent' ? 'color:#047857;' : ''">
                    <input type="checkbox" x-model="it.applies" :disabled="it.status==='sent'">
                    <span x-text="it.label"></span>
                    <span x-show="it.status==='sent'" x-cloak style="font-size:.66rem;font-weight:400;color:#047857;">✓ sent</span>
                </label>

                <div x-show="it.applies" x-cloak style="margin-top:.4rem;padding-left:1.45rem;display:flex;flex-direction:column;gap:.3rem;">
                    <div>
                        <label style="font-size:.66rem;color:#6b7280;display:block;">Responsible / recipient</label>
                        {{-- Responsible-party options are a CONSTANT enum (CocWorkOrderService::responsibleLabels),
                             identical for every COC type incl. agency-custom ones — server-rendered as real
                             <option>s so x-model binds the correct value on init for EVERY row. A nested
                             Alpine <template x-for> here desynced the <select> (options absent at bind time →
                             fell back to the first option "Seller" while the model kept its real value, e.g.
                             'supplier' → stray "Seller" beside the supplier picker). Static options = uniform. --}}
                        <select x-model="it.responsible_party" :disabled="it.status==='sent'" class="corex-input" style="width:100%;font-size:.78rem;">
                            @foreach(\App\Services\DealV2\CocWorkOrderService::responsibleLabels() as $rpVal => $rpLbl)
                                <option value="{{ $rpVal }}">{{ $rpLbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="it.responsible_party==='supplier' || it.responsible_party==='transfer_attorney'" x-cloak>
                        <label style="font-size:.66rem;color:#6b7280;display:block;">Supplier</label>
                        <select x-model="it.service_provider_id" :disabled="it.status==='sent'" class="corex-input" style="width:100%;font-size:.78rem;">
                            <option value="">— pick supplier —</option>
                            <template x-for="s in suppliersFor(it)" :key="s.id"><option :value="s.id" x-text="s.name"></option></template>
                        </select>
                        {{-- Item 2 — inline add-supplier, no navigating away. --}}
                        <button type="button" x-show="it.status!=='sent' && addingFor!==it.code" @click="openAdd(it)"
                                style="margin-top:.2rem;background:none;border:none;padding:0;cursor:pointer;color:var(--brand-icon,#0ea5e9);font-size:.68rem;font-weight:600;">＋ Add supplier</button>
                        <div x-show="addingFor===it.code" x-cloak style="margin-top:.25rem;border:1px solid #e5e7eb;border-radius:.4rem;padding:.4rem;display:flex;flex-direction:column;gap:.25rem;">
                            <input type="text" x-model="newSup.name" placeholder="Supplier name *" class="corex-input" style="font-size:.72rem;">
                            <input type="email" x-model="newSup.email" placeholder="Email (optional)" class="corex-input" style="font-size:.72rem;">
                            <div style="display:flex;gap:.4rem;align-items:center;">
                                <button type="button" @click="submitAdd(it)" :disabled="addBusy" class="corex-btn" style="font-size:.7rem;padding:.2rem .55rem;" x-text="addBusy ? 'Adding…' : 'Add & select'"></button>
                                <button type="button" @click="cancelAdd()" style="font-size:.7rem;background:none;border:none;color:#6b7280;cursor:pointer;">Cancel</button>
                                <span x-show="addErr" x-cloak x-text="addErr" style="font-size:.66rem;color:#b45309;"></span>
                            </div>
                        </div>
                        <span x-show="isSupplierFallback(it)" x-cloak style="font-size:.66rem;color:#b45309;display:block;margin-top:.15rem;">No supplier of this type — showing all, or add one above.</span>
                    </div>
                    <div style="font-size:.66rem;color:#6b7280;">
                        <span x-show="it.status==='sent'" x-cloak>→ <span x-text="it.recipient_email"></span><span x-show="it.cc_emails" x-cloak x-text="' (cc ' + it.cc_emails + ')'"></span></span>
                        <span x-show="it.status!=='sent' && !it.step_name" x-cloak style="color:#b45309;">no matching pipeline step — sends on the trigger step</span>
                        <span x-show="it.status!=='sent' && it.step_name" x-cloak x-text="'step: ' + it.step_name"></span>
                    </div>
                </div>
            </div>
        </template>

        <div style="display:flex;align-items:center;justify-content:flex-end;gap:.5rem;margin-top:.6rem;border-top:1px solid #eee;padding-top:.5rem;">
            <span x-show="msg" x-cloak x-text="msg" style="font-size:.7rem;color:#047857;margin-right:auto;"></span>
            <span x-show="err" x-cloak x-text="err" style="font-size:.7rem;color:#b91c1c;margin-right:auto;"></span>
            <button type="button" @click="save()" :disabled="busy" class="corex-btn-primary" style="font-size:.78rem;padding:.3rem .85rem;">Save work orders</button>
        </div>
    </div>
</div>
@endif
