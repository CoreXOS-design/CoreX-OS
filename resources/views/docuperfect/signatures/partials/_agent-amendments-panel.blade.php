{{--
    AT-373 — Agent Review: the ONE unified "Amendments" surface. A sticky right-rail panel listing BOTH
    wet-ink body/clause amendments AND recipient-added Other Conditions together, each navigable
    (click → scroll the document to it + flash) and actionable (the agent initials it via the capture
    modal). The footer holds the outstanding count and the SINGLE Approve action (labelled for the real
    next party). No competing approve controls elsewhere.

    Style matches cc6's right-rail card (0c97b06a): white bg, #e2e8f0 border, 14px radius, slate type.
    NO MutationObserver — Alpine tracks `items` reactively; the capture modal is a promise (AgentCI.capture).
--}}
@php
    $items = $amendmentItems ?? [];
    $agentInitials = collect(preg_split('/\s+/', trim((string)($user->name ?? 'Agent'))))->filter()->map(fn($p)=>mb_strtoupper(mb_substr($p,0,1)))->take(3)->implode('');
    // The REAL next step drives the label: a prior recipient re-initials FIRST (even when the amender was
    // the LAST recipient), so it reads "Send to <prior> to initial" — never "Finalise" while a prior owes.
    $amendNextName = $nextPartyDisplayName ?? 'the next recipient';
    $amendNextVerb = (($amendNextAction ?? null) === 'initial') ? ' to initial' : '';
    $approveLabel  = $nextParty
        ? ('Approve &amp; Send to ' . e($amendNextName) . $amendNextVerb)
        : 'Approve &amp; Finalise';
@endphp

<style>
    .amend-flash { animation: amendFlash 1.6s ease-out; }
    @keyframes amendFlash { 0%,100%{ background:transparent; } 15%,60%{ background:#fef9c3; box-shadow:0 0 0 3px #fde047; } }
    #agentAmendPanel .amend-item { cursor:pointer; }
    #agentAmendPanel .amend-item:hover { background:#f8fafc; }
</style>

{{-- The panel is a real COLUMN element (its parent .review-aside is a flex column beside the document).
     position:sticky (set on #agentAmendPanel by the review page) keeps it in view while the document
     scrolls — it is part of the page flow, NOT a floating overlay over the document. --}}
<div id="agentAmendPanel" x-data="agentAmendmentPanel(@js($items))"
     style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px;
            box-shadow:0 4px 16px rgba(15,23,42,0.06); display:flex; flex-direction:column;">
    <div style="padding:14px 16px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:8px;">
        <span style="font-weight:600; color:#0f172a;">Amendments</span>
        <span style="margin-left:auto; font-size:12px; font-weight:600; color:#fff; background:#f59e0b; border-radius:999px; padding:2px 9px;" x-text="items.length"></span>
    </div>

    <div style="padding:8px; flex:1; overflow:auto;">
        <template x-if="items.length === 0">
            <div style="padding:16px; font-size:13px; color:#64748b;">No changes to review.</div>
        </template>
        <template x-for="(it, idx) in items" :key="it.kind + '-' + it.id">
            <div class="amend-item" @click="scrollTo(it)"
                 style="border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                    <span :style="it.kind==='condition'
                            ? 'font-size:10px;font-weight:700;text-transform:uppercase;color:#7c3aed;background:#f5f3ff;border-radius:6px;padding:1px 6px;'
                            : 'font-size:10px;font-weight:700;text-transform:uppercase;color:#0369a1;background:#f0f9ff;border-radius:6px;padding:1px 6px;'"
                          x-text="it.badge"></span>
                    <span style="margin-left:auto; font-size:11px; font-weight:600; border-radius:999px; padding:1px 8px;"
                          :style="it.state==='accepted' ? 'color:#166534;background:#f0fdf4;' : 'color:#92400e;background:#fffbeb;'"
                          x-text="it.state==='accepted' ? 'Initialed ✓' : 'Needs your initial'"></span>
                </div>
                <div style="font-size:12.5px; font-weight:600; color:#0f172a;" x-text="it.location"></div>
                <div style="font-size:12px; color:#475569; margin-top:2px;" x-text="it.summary"></div>
                <div x-show="it.author" style="font-size:11px; color:#64748b; margin-top:3px;" x-text="'Added by ' + (it.author||'')"></div>
                {{-- SYMMETRIC edit-upon-edit (Johan 2026-08-10): the agent AGREES (Accept & Initial) OR EDITS
                     the change with the SAME amend tool a recipient uses — a strike / reword, itself a new
                     initialed mark. There is NO "reject": disagreeing IS editing. Nothing is ever removed. --}}
                <div @click.stop style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                    <button type="button" @click="accept(it)"
                            :style="it.state==='accepted' ? 'color:#166534;background:#f0fdf4;' : 'color:#fff;background:#059669;'"
                            style="font-size:12px; font-weight:600; border-radius:7px; padding:5px 12px;"
                            x-text="it.state==='accepted' ? 'Re-initial' : 'Accept &amp; Initial'"></button>
                    <button type="button" @click="edit(it)"
                            style="font-size:12px; color:#b45309; border:1px solid #fed7aa; border-radius:7px; padding:5px 12px;">
                        Edit
                    </button>
                    <button type="button" @click="scrollTo(it)"
                            style="font-size:12px; color:#475569; border:1px solid #cbd5e1; border-radius:7px; padding:5px 10px;">
                        View
                    </button>
                </div>
            </div>
        </template>
    </div>

    <div style="padding:12px 16px; border-top:1px solid #e2e8f0;">
        {{-- The single Approve enables ONLY once EVERY change has been actioned individually
             (accepted+initialled, or rejected) — nothing outstanding. No accept-all / reject-all. --}}
        <div style="font-size:12px; margin-bottom:8px;"
             :style="outstanding>0 ? 'color:#92400e;' : 'color:#166534;'"
             x-text="outstanding>0 ? (outstanding + ' change' + (outstanding===1?'':'s') + ' still need your initial (Accept &amp; Initial, or Edit each above)') : 'Every change actioned — ready to send on.'"></div>
        <form method="POST" action="{{ route('docuperfect.signatures.amendment.approve', $document) }}">
            @csrf
            <button type="submit" :disabled="outstanding>0"
                    :style="outstanding>0 ? 'opacity:0.5;cursor:not-allowed;' : 'cursor:pointer;'"
                    style="width:100%; font-size:13px; font-weight:600; color:#fff; background:#059669; border-radius:9px; padding:9px 12px;"
                    @click="return outstanding>0 ? $event.preventDefault() : confirm('{{ $nextParty ? 'Approve and send to ' . ($amendNextName ?? 'the next recipient') . '?' : 'Approve and finalise the document?' }}')">
                {!! $approveLabel !!} &rarr;
            </button>
        </form>
        {{-- AT-373 (Part 3) — agent bounce-back. When the agent does NOT want to accept/edit a
             recipient's change (e.g. a struck-out edit there is no natural way to amend), send the doc
             BACK to the author so THEY remove or adjust their own change and re-sign clean. Always
             available (never gated on initialing) so the agent always has a way to act. --}}
        <form method="POST" action="{{ route('docuperfect.signatures.amendment.sendBack', $document) }}" style="margin-top:8px;"
              onsubmit="return confirm('Send this document back to {{ $completedRequest?->signer_name ?? 'the recipient' }} so they can remove or adjust their change and re-sign? They will get a fresh signing link by email.');">
            @csrf
            <button type="submit"
                    style="width:100%; font-size:13px; font-weight:500; color:#b45309; background:#fff; border:1px solid #fcd34d; border-radius:9px; padding:9px 12px; cursor:pointer;">
                Send back to recipient
            </button>
        </form>
    </div>
</div>

{{-- Self-contained capture modal (draw or type) — promise-based AgentCI.capture(item); handles BOTH a
     body cir-slot (change_id) and an Other Condition (condition_id). No MutationObserver. --}}
<div id="agentCiModal" style="display:none; position:fixed; inset:0; z-index:70; align-items:center; justify-content:center; background:rgba(0,0,0,0.6);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:32rem; margin:0 1rem; overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; font-weight:600; color:#0f172a;">Initial this change</div>
        <div style="padding:20px;">
            <div style="display:flex; gap:8px; margin-bottom:12px;">
                <button type="button" id="agentCiTabDraw" onclick="AgentCI.setMode('draw')" style="flex:1; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">Draw</button>
                <button type="button" id="agentCiTabType" onclick="AgentCI.setMode('type')" style="flex:1; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">Type</button>
            </div>
            <div id="agentCiDraw"><canvas id="agentCiCanvas" width="460" height="150" style="width:100%; height:150px; border:1px dashed #94a3b8; border-radius:8px; touch-action:none; background:#f8fafc;"></canvas></div>
            <div id="agentCiType" style="display:none;">
                <input id="agentCiInput" type="text" value="{{ $agentInitials }}" maxlength="6"
                       style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:28px; font-family:'Dancing Script',cursive; text-align:center;">
                <p style="font-size:12px; color:#64748b; margin-top:6px;">Your initials — edit if needed.</p>
            </div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" onclick="AgentCI.clear()" style="padding:8px 14px; font-size:13px; color:#475569; border:1px solid #cbd5e1; border-radius:8px;">Clear</button>
            <button type="button" onclick="AgentCI.cancel()" style="padding:8px 14px; font-size:13px; color:#475569;">Cancel</button>
            <button type="button" id="agentCiApply" onclick="AgentCI.apply()" style="padding:8px 18px; font-size:13px; font-weight:600; color:#fff; background:#059669; border-radius:8px;">Apply Initial</button>
        </div>
    </div>
</div>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
<script>
(function () {
    const CSRF = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
    const CHANGE_URL = @json(route('docuperfect.signatures.initialChange', $document));
    const COND_URL_BASE = @json(url('/docuperfect/documents/' . $document->id . '/signatures/condition'));
    // Reuse the affordance's body-change apply if present; else define it here (POST internal initial-change).
    window.__corexApplyChangeInitial = window.__corexApplyChangeInitial || async function (changeId, partyKey, img) {
        try { const r = await fetch(CHANGE_URL, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({change_id:changeId, initial_image:img}) }); const d=await r.json().catch(()=>({})); return r.ok && !!d.ok; } catch(e){ return false; }
    };
    window.__corexApplyConditionInitial = async function (conditionId, img) {
        try { const r = await fetch(COND_URL_BASE + '/' + conditionId + '/initial', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({initial_image:img}) }); const d=await r.json().catch(()=>({})); return r.ok && !!d.ok; } catch(e){ return false; }
    };
    const modal=document.getElementById('agentCiModal'), canvas=document.getElementById('agentCiCanvas'), ctx=canvas.getContext('2d');
    let drawing=false, hasInk=false, mode='draw', pending=null; // pending = {item, resolve}
    function resetCanvas(){ ctx.clearRect(0,0,canvas.width,canvas.height); ctx.lineWidth=2.5; ctx.lineCap='round'; ctx.strokeStyle='#0f172a'; hasInk=false; }
    function pos(e){ const r=canvas.getBoundingClientRect(); const t=e.touches?e.touches[0]:e; return {x:(t.clientX-r.left)*(canvas.width/r.width), y:(t.clientY-r.top)*(canvas.height/r.height)}; }
    canvas.addEventListener('mousedown',e=>{drawing=true;const p=pos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);e.preventDefault();});
    canvas.addEventListener('mousemove',e=>{if(!drawing)return;const p=pos(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasInk=true;e.preventDefault();});
    window.addEventListener('mouseup',()=>{drawing=false;});
    canvas.addEventListener('touchstart',e=>{drawing=true;const p=pos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);e.preventDefault();},{passive:false});
    canvas.addEventListener('touchmove',e=>{if(!drawing)return;const p=pos(e);ctx.lineTo(p.x,p.y);ctx.stroke();hasInk=true;e.preventDefault();},{passive:false});
    canvas.addEventListener('touchend',()=>{drawing=false;});
    function typedUrl(t){ const c=document.createElement('canvas');c.width=460;c.height=150;const x=c.getContext('2d');x.fillStyle='#0f172a';x.font="64px 'Dancing Script',cursive";x.textAlign='center';x.textBaseline='middle';x.fillText((t||'').trim()||'—',230,75);return c.toDataURL('image/png'); }
    function settle(v){ if(pending){ const r=pending.resolve; pending=null; modal.style.display='none'; r(v); } }
    window.AgentCI = {
        setMode(m){ mode=m; document.getElementById('agentCiDraw').style.display=m==='draw'?'block':'none'; document.getElementById('agentCiType').style.display=m==='type'?'block':'none'; document.getElementById('agentCiTabDraw').style.background=m==='draw'?'#e0f2fe':'#fff'; document.getElementById('agentCiTabType').style.background=m==='type'?'#e0f2fe':'#fff'; },
        clear(){ if(mode==='draw') resetCanvas(); else document.getElementById('agentCiInput').value=''; },
        cancel(){ settle(false); },
        // Returns Promise<bool> — true when the initial was captured + persisted for this item.
        capture(item){ return new Promise(resolve=>{ pending={item,resolve}; resetCanvas(); this.setMode('draw'); modal.style.display='flex'; }); },
        async apply(){
            if(!pending){ modal.style.display='none'; return; }
            let url; if(mode==='draw'){ if(!hasInk){ alert('Please draw your initial, or switch to Type.'); return; } url=canvas.toDataURL('image/png'); }
            else { const v=(document.getElementById('agentCiInput').value||'').trim(); if(!v){ alert('Enter your initials.'); return; } url=typedUrl(v); }
            const btn=document.getElementById('agentCiApply'); btn.disabled=true; btn.textContent='Applying…';
            const it=pending.item;
            const ok = it.kind==='condition' ? await window.__corexApplyConditionInitial(it.id, url) : await window.__corexApplyChangeInitial(it.id, it.party_key, url);
            btn.disabled=false; btn.textContent='Apply Initial';
            if(!ok){ alert('Could not save your initial — please try again.'); return; }
            // Paint a body cir-slot in place (the OC ink is server-adopted + shows on reload).
            if(it.kind==='body'){ document.querySelectorAll('.cir-slot[data-change-id="'+it.id+'"][data-party-key="'+it.party_key+'"]').forEach(s=>{ s.classList.add('cir-filled'); const ink=s.querySelector('.cir-ink'); if(ink){ ink.removeAttribute('data-empty'); ink.innerHTML='<img src="'+url+'" class="cir-ink-img" alt="Initial">'; } }); }
            settle(true);
        },
    };
    modal.addEventListener('click', ()=>settle(false));
})();
function agentAmendmentPanel(items){
    return {
        // state per item: 'pending' (needs a decision) | 'accepted' (initialled) | 'rejected' (reverted).
        items: (items||[]).map(function(it){ return Object.assign({}, it, { state: it.initialed ? 'accepted' : 'pending' }); }),
        get outstanding(){ return this.items.filter(function(i){ return i.state==='pending'; }).length; },
        scrollTo(it){ const sel = it.kind==='body' ? '[data-change-id="'+it.id+'"]' : '[data-condition-id="'+it.id+'"]'; const el=document.querySelector(sel); if(el){ el.scrollIntoView({behavior:'smooth',block:'center'}); el.classList.add('amend-flash'); setTimeout(()=>el.classList.remove('amend-flash'),1600); } },
        // Accept = place the agent's initial on this change (accept IS the initial — decision i).
        async accept(it){ const ok = await window.AgentCI.capture(it); if(ok){ it.state='accepted'; } },
        // Edit = open the SHARED amend tool (cc6's selectionEditor, ported onto this page) focused on this
        // change so the agent can strike / reword it — itself a new initialed mark. This REPLACES "reject":
        // disagreeing is just editing; nothing is removed. cc6's partial exposes window.CoreXAgentEdit(it);
        // until it is wired, fall back to scrolling to the change and prompting the agent to highlight it and
        // use the "✎ Amend" tool. The page reloads after an edit so the new mark + initial row render.
        edit(it){
            this.scrollTo(it);
            if (typeof window.CoreXAgentEdit === 'function') { window.CoreXAgentEdit(it); return; }
            alert('Highlight the text in the document to change, then click “✎ Amend” to strike or reword it. Your edit becomes a new mark everyone re-initials.');
        },
    };
}
</script>
