{{--
    AT-373 — SELF-CONTAINED agent per-change initial modal for the Agent Review page.

    The agent-review surface must let the AGENT place their real initial on each amendment change
    (decision i: approval IS an initial) using the SAME "INITIAL THIS CHANGE" cir-slot rows the
    recipients use. _change-initial-affordance already wires each cir-slot: clicking the agent's own
    slot dispatches `corex-open-change-initial` and exposes window.__corexApplyChangeInitial(changeId,
    partyKey, imageDataUrl) which POSTs to the INTERNAL initial-change endpoint. On the recipient
    signing page that event is caught by the big sign.blade Alpine capture modal — which does NOT exist
    here. This partial is the missing bridge + modal, PURE vanilla JS (no Alpine host), so the agent
    review page is self-contained and never entangled with the recipient signing component.

    Draw OR type; on Apply it rasterises to a PNG data-URL and calls __corexApplyChangeInitial, then
    fills the slot in place and re-evaluates whether every required agent initial is placed (which
    enables the single Approve Amendment button, id="approveAmendmentBtn").
--}}
@php $agentInitials = collect(preg_split('/\s+/', trim((string)($user->name ?? 'Agent'))))->filter()->map(fn($p)=>mb_strtoupper(mb_substr($p,0,1)))->take(3)->implode(''); @endphp
<div id="agentCiModal" style="display:none; position:fixed; inset:0; z-index:60; align-items:center; justify-content:center; background:rgba(0,0,0,0.6);">
    <div style="background:#fff; border-radius:16px; width:100%; max-width:32rem; margin:0 1rem; overflow:hidden;" onclick="event.stopPropagation()">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; font-weight:600; color:#0f172a;">Initial this change</div>
        <div style="padding:20px;">
            <div style="display:flex; gap:8px; margin-bottom:12px;">
                <button type="button" id="agentCiTabDraw" onclick="AgentCI.setMode('draw')" style="flex:1; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">Draw</button>
                <button type="button" id="agentCiTabType" onclick="AgentCI.setMode('type')" style="flex:1; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">Type</button>
            </div>
            <div id="agentCiDraw">
                <canvas id="agentCiCanvas" width="460" height="150" style="width:100%; height:150px; border:1px dashed #94a3b8; border-radius:8px; touch-action:none; background:#f8fafc;"></canvas>
            </div>
            <div id="agentCiType" style="display:none;">
                <input id="agentCiInput" type="text" value="{{ $agentInitials }}" maxlength="6"
                       style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:28px; font-family:'Dancing Script',cursive; text-align:center;">
                <p style="font-size:12px; color:#64748b; margin-top:6px;">Your initials — edit if needed.</p>
            </div>
        </div>
        <div style="padding:14px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" onclick="AgentCI.clear()" style="padding:8px 14px; font-size:13px; color:#475569; border:1px solid #cbd5e1; border-radius:8px;">Clear</button>
            <button type="button" onclick="AgentCI.close()" style="padding:8px 14px; font-size:13px; color:#475569;">Cancel</button>
            <button type="button" id="agentCiApply" onclick="AgentCI.apply()" style="padding:8px 18px; font-size:13px; font-weight:600; color:#fff; background:#059669; border-radius:8px;">Apply Initial</button>
        </div>
    </div>
</div>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
<script>
(function () {
    const modal = document.getElementById('agentCiModal');
    if (!modal) return;
    const canvas = document.getElementById('agentCiCanvas');
    const ctx = canvas.getContext('2d');
    let drawing = false, hasInk = false, mode = 'draw', cur = null; // cur = {changeId, partyKey}

    function resetCanvas() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.lineWidth=2.5; ctx.lineCap='round'; ctx.strokeStyle='#0f172a'; hasInk=false; }
    function pos(e){ const r=canvas.getBoundingClientRect(); const t=e.touches?e.touches[0]:e; return {x:(t.clientX-r.left)*(canvas.width/r.width), y:(t.clientY-r.top)*(canvas.height/r.height)}; }
    function start(e){ drawing=true; const p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
    function move(e){ if(!drawing)return; const p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); hasInk=true; e.preventDefault(); }
    function end(){ drawing=false; }
    canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move); window.addEventListener('mouseup',end);
    canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end);

    function typedDataUrl(text){
        const c=document.createElement('canvas'); c.width=460; c.height=150; const x=c.getContext('2d');
        x.fillStyle='#0f172a'; x.font="64px 'Dancing Script', cursive"; x.textAlign='center'; x.textBaseline='middle';
        x.fillText((text||'').trim() || '—', c.width/2, c.height/2); return c.toDataURL('image/png');
    }

    window.AgentCI = {
        setMode(m){ mode=m;
            document.getElementById('agentCiDraw').style.display = m==='draw'?'block':'none';
            document.getElementById('agentCiType').style.display = m==='type'?'block':'none';
            document.getElementById('agentCiTabDraw').style.background = m==='draw'?'#e0f2fe':'#fff';
            document.getElementById('agentCiTabType').style.background = m==='type'?'#e0f2fe':'#fff';
        },
        clear(){ if(mode==='draw'){ resetCanvas(); } else { document.getElementById('agentCiInput').value=''; } },
        open(detail){ cur=detail||{}; resetCanvas(); this.setMode('draw'); modal.style.display='flex'; },
        close(){ modal.style.display='none'; cur=null; },
        async apply(){
            if(!cur||!cur.changeId){ this.close(); return; }
            let dataUrl;
            if(mode==='draw'){ if(!hasInk){ alert('Please draw your initial, or switch to Type.'); return; } dataUrl=canvas.toDataURL('image/png'); }
            else { const v=(document.getElementById('agentCiInput').value||'').trim(); if(!v){ alert('Enter your initials.'); return; } dataUrl=typedDataUrl(v); }
            const btn=document.getElementById('agentCiApply'); btn.disabled=true; btn.textContent='Applying…';
            const ok = await window.__corexApplyChangeInitial(cur.changeId, cur.partyKey, dataUrl);
            btn.disabled=false; btn.textContent='Apply Initial';
            if(!ok){ alert('Could not save your initial — please try again.'); return; }
            AgentCI.fillSlot(cur.changeId, cur.partyKey, dataUrl);
            this.close();
            AgentCI.refreshApproveGate();
        },
        fillSlot(changeId, partyKey, dataUrl){
            document.querySelectorAll('.cir-slot[data-change-id="'+changeId+'"][data-party-key="'+partyKey+'"]').forEach(function(slot){
                slot.classList.add('cir-filled');
                const ink=slot.querySelector('.cir-ink'); if(ink){ ink.removeAttribute('data-empty'); ink.innerHTML='<img src="'+dataUrl+'" class="cir-ink-img" alt="Initial">'; }
            });
        },
        // The single Approve button (id approveAmendmentBtn) is enabled only when the agent owes no
        // unfilled cir-slot of their own — i.e. every change is initialled. Server re-gates on approve.
        refreshApproveGate(){
            const outstanding = document.querySelectorAll('.cir-slot.cir-mine:not(.cir-filled)').length;
            const btn=document.getElementById('approveAmendmentBtn');
            const note=document.getElementById('approveAmendmentNote');
            if(btn){ btn.disabled = outstanding>0; btn.style.opacity = outstanding>0?'0.5':'1'; btn.style.cursor = outstanding>0?'not-allowed':'pointer'; }
            if(note){ note.textContent = outstanding>0 ? ('Initial each change above to approve ('+outstanding+' outstanding).') : 'All changes initialled — you can approve.'; }
        },
    };
    document.addEventListener('corex-open-change-initial', function(e){ AgentCI.open(e.detail||{}); });
    modal.addEventListener('click', function(){ AgentCI.close(); });
    // Re-evaluate the approve gate once the doc + rows have painted/wired.
    setTimeout(function(){ AgentCI.refreshApproveGate(); }, 1200);
    try { new MutationObserver(function(){ AgentCI.refreshApproveGate(); }).observe(document.body,{subtree:true,childList:true,attributes:true,attributeFilter:['class']}); } catch(e){}
})();
</script>
