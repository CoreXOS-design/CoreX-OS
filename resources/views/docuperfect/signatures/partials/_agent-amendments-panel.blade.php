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
    // AT-386 — $agentInitials removed: it only ever prefilled the retired bespoke modal's Type-mode
    // input. The shared capture modal computes its own agent-initials prefill (typedName, review.blade.php).
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
                          :style="it.state==='accepted' ? 'color:#166534;background:#f0fdf4;' : (it.state==='rejected' ? 'color:#b91c1c;background:#fef2f2;' : 'color:#92400e;background:#fffbeb;')"
                          x-text="it.state==='accepted' ? 'Initialed ✓' : (it.state==='rejected' ? 'Rejected ✕' : 'Needs your initial')"></span>
                </div>
                <div style="font-size:12.5px; font-weight:600; color:#0f172a;" x-text="it.location"></div>
                <div style="font-size:12px; color:#475569; margin-top:2px;" x-text="it.summary"></div>
                <div x-show="it.author" style="font-size:11px; color:#64748b; margin-top:3px;" x-text="'Added by ' + (it.author||'')"></div>
                {{-- The agent has three routes for each change: ACCEPT & INITIAL (agree — places the
                     initial, the change stays), EDIT (strike / reword with the shared amend tool — itself a
                     new initialed mark), or REJECT (AT-373 reject flow, Johan 2026-08-12 — the recipient must
                     REMOVE this change; the agent never edits the recipient's words). A rejected item is NOT
                     initialed; Reject & send back (footer) hands exactly the rejected set to the recipient. --}}
                <div @click.stop style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                    <button type="button" @click="accept(it)" x-show="it.state!=='rejected'"
                            :style="it.state==='accepted' ? 'color:#166534;background:#f0fdf4;' : 'color:#fff;background:#059669;'"
                            style="font-size:12px; font-weight:600; border-radius:7px; padding:5px 12px;"
                            x-text="it.state==='accepted' ? 'Re-initial' : 'Accept &amp; Initial'"></button>
                    <button type="button" @click="edit(it)" x-show="it.state!=='rejected'"
                            style="font-size:12px; color:#b45309; border:1px solid #fed7aa; border-radius:7px; padding:5px 12px;">
                        Edit
                    </button>
                    <button type="button" @click="toggleReject(it)" :disabled="it.busy"
                            :style="it.state==='rejected' ? 'color:#fff;background:#dc2626;border:1px solid #dc2626;' : 'color:#b91c1c;border:1px solid #fecaca;'"
                            style="font-size:12px; font-weight:600; border-radius:7px; padding:5px 12px;"
                            x-text="it.state==='rejected' ? 'Rejected — undo' : 'Reject'"></button>
                    <button type="button" @click="scrollTo(it)"
                            style="font-size:12px; color:#475569; border:1px solid #cbd5e1; border-radius:7px; padding:5px 10px;">
                        View
                    </button>
                </div>
            </div>
        </template>
    </div>

    <div style="padding:12px 16px; border-top:1px solid #e2e8f0;">
        {{-- Two mutually-exclusive outcomes once EVERY change is decided:
             • ALL accepted (nothing rejected) → Approve & send to next party.
             • ANY rejected → Reject & send back to recipient (they remove the rejected changes).
             Approve is blocked while anything is outstanding OR anything is rejected. --}}
        <div style="font-size:12px; margin-bottom:8px;"
             :style="outstanding>0 ? 'color:#92400e;' : (rejectedCount>0 ? 'color:#b91c1c;' : 'color:#166534;')"
             x-text="outstanding>0
                        ? (outstanding + ' change' + (outstanding===1?'':'s') + ' still need a decision — Accept &amp; Initial, Edit, or Reject each above')
                        : (rejectedCount>0
                            ? (rejectedCount + ' change' + (rejectedCount===1?'':'s') + ' rejected — send back to the recipient to remove ' + (rejectedCount===1?'it':'them'))
                            : 'Every change accepted — ready to send on.')"></div>
        <form method="POST" action="{{ route('docuperfect.signatures.amendment.approve', $document) }}">
            @csrf
            <button type="submit" :disabled="outstanding>0 || rejectedCount>0"
                    :style="(outstanding>0 || rejectedCount>0) ? 'opacity:0.5;cursor:not-allowed;' : 'cursor:pointer;'"
                    style="width:100%; font-size:13px; font-weight:600; color:#fff; background:#059669; border-radius:9px; padding:9px 12px;"
                    @click="return (outstanding>0 || rejectedCount>0) ? $event.preventDefault() : confirm('{{ $nextParty ? 'Approve and send to ' . ($amendNextName ?? 'the next recipient') . '?' : 'Approve and finalise the document?' }}')">
                {!! $approveLabel !!} &rarr;
            </button>
        </form>
        {{-- AT-373 reject flow (Johan 2026-08-12) — when the agent has REJECTED one or more changes, send
             the doc back to the author; they get a fresh signing link and must REMOVE each rejected change
             (they own their own words) before re-signing. Armed only once every change is decided and at
             least one is rejected (canSendBack). Accepted-and-initialed changes stay. --}}
        <form method="POST" action="{{ route('docuperfect.signatures.amendment.sendBack', $document) }}" style="margin-top:8px;"
              @submit="return canSendBack ? confirm('Send this document back to {{ $completedRequest?->signer_name ?? 'the recipient' }} to REMOVE the rejected change(s) and re-sign? They will get a fresh signing link by email.') : $event.preventDefault()">
            @csrf
            <button type="submit" :disabled="!canSendBack"
                    :style="canSendBack ? 'color:#fff;background:#dc2626;border:1px solid #dc2626;cursor:pointer;' : 'color:#b91c1c;background:#fff;border:1px solid #fecaca;opacity:0.5;cursor:not-allowed;'"
                    style="width:100%; font-size:13px; font-weight:600; border-radius:9px; padding:9px 12px;">
                Reject &amp; send back to recipient
            </button>
        </form>
    </div>
</div>

{{-- AT-386 (2026-08-28) — unify the signature capture. This panel used to open its OWN self-contained
     draw/type-only modal (#agentCiModal / window.AgentCI, plain JS, no saved-signature option — the
     root cause of the missing PIN-sign control). Replaced with a bridge to the SAME shared capture modal
     sign.blade.php uses (docuperfect.signatures.partials.signature-modal, savedSignatureSupport=true,
     mounted once on review.blade.php, right after this panel's own include): capture() dispatches the
     SAME corex-open-change-initial event _change-initial-affordance.blade.php's own slot-click already
     uses, and resolves its promise off the corex-change-initialed / corex-change-initial-cancelled events
     that shared modal's Alpine component fires. No second implementation of the PIN/saved-signature
     logic — same partial, same params, same backend calls, same behaviour as the normal agent signing
     page. __corexApplyChangeInitial / __corexApplyConditionInitial are UNCHANGED below — only which
     modal captures the ink changed, not how it gets persisted. --}}
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
const REJECT_URL = @json(route('docuperfect.signatures.amendment.rejectItem', $document));
(function () {
    const CHANGE_URL = @json(route('docuperfect.signatures.initialChange', $document));
    const COND_URL_BASE = @json(url('/docuperfect/documents/' . $document->id . '/signatures/condition'));
    window.__corexApplyChangeInitial = window.__corexApplyChangeInitial || async function (changeId, partyKey, img) {
        try { const r = await fetch(CHANGE_URL, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({change_id:changeId, initial_image:img}) }); const d=await r.json().catch(()=>({})); return r.ok && !!d.ok; } catch(e){ return false; }
    };
    window.__corexApplyConditionInitial = async function (conditionId, img) {
        try { const r = await fetch(COND_URL_BASE + '/' + conditionId + '/initial', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({initial_image:img}) }); const d=await r.json().catch(()=>({})); return r.ok && !!d.ok; } catch(e){ return false; }
    };
    window.AgentCI = {
        // Returns Promise<bool> — true when the initial was captured + persisted for this item. Bridges
        // to review.blade.php's shared capture-modal Alpine component via the SAME event contract
        // _change-initial-affordance.blade.php already documents (corex-open-change-initial in,
        // corex-change-initialed out) — for BOTH item kinds, so an added Other Condition also opens the
        // real modal with PIN support, not the retired bespoke one.
        capture(item){
            return new Promise(resolve => {
                const matches = (d) => String(d.itemId) === String(item.id) && d.kind === item.kind;
                const onDone = (e) => { if (!matches(e.detail || {})) return; cleanup(); resolve(true); };
                const onCancel = (e) => { if (!matches(e.detail || {})) return; cleanup(); resolve(false); };
                function cleanup() {
                    document.removeEventListener('corex-change-initialed', onDone);
                    document.removeEventListener('corex-change-initial-cancelled', onCancel);
                }
                document.addEventListener('corex-change-initialed', onDone);
                document.addEventListener('corex-change-initial-cancelled', onCancel);
                document.dispatchEvent(new CustomEvent('corex-open-change-initial', {
                    detail: { changeId: item.id, partyKey: item.party_key, kind: item.kind, itemId: item.id },
                }));
            });
        },
    };
})();
function agentAmendmentPanel(items){
    return {
        // state per item: 'pending' (needs a decision) | 'accepted' (initialled) | 'rejected' (agent
        // rejected — recipient must remove it on send-back).
        items: (items||[]).map(function(it){ return Object.assign({}, it, { state: it.rejected ? 'rejected' : (it.initialed ? 'accepted' : 'pending'), busy:false }); }),
        get outstanding(){ return this.items.filter(function(i){ return i.state==='pending'; }).length; },
        get rejectedCount(){ return this.items.filter(function(i){ return i.state==='rejected'; }).length; },
        // Every item decided (accepted or rejected) AND at least one rejected → the send-back is armed.
        get canSendBack(){ return this.outstanding===0 && this.rejectedCount>0; },
        scrollTo(it){ const sel = it.kind==='body' ? '[data-change-id="'+it.id+'"]' : '[data-condition-id="'+it.id+'"]'; const el=document.querySelector(sel); if(el){ el.scrollIntoView({behavior:'smooth',block:'center'}); el.classList.add('amend-flash'); setTimeout(()=>el.classList.remove('amend-flash'),1600); } },
        // Accept = place the agent's initial on this change (accept IS the initial — decision i).
        async accept(it){ const ok = await window.AgentCI.capture(it); if(ok){ it.state='accepted'; } },
        // Reject / undo-reject = record the agent's rejection server-side (persists across reload so the
        // send-back transition can read it). A rejected item is NOT initialed; on send-back the recipient
        // is shown exactly these and must Remove each. Toggling accepts nothing — accept is a separate act.
        async toggleReject(it){
            if(it.busy) return; it.busy=true;
            const next = it.state!=='rejected';
            try {
                const r = await fetch(REJECT_URL, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({kind:it.kind, id:String(it.id), rejected: next}) });
                const d = await r.json().catch(()=>({}));
                if(r.ok && d.ok){ it.state = next ? 'rejected' : 'pending'; }
                else { alert(d.error || 'Could not update the rejection — please try again.'); }
            } catch(e){ alert('Could not update the rejection — please try again.'); }
            it.busy=false;
        },
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
