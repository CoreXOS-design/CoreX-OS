{{-- .ai/specs/rentals-faults-work-orders.md section 28.1 - the ONE shared look for the stage strip, the Returned | Next bar and the folded panels (<x-wo-panel>).
     Used by the fault page. (The work order and job card pages still carry their own copies of these rules - folding them into this partial is a follow-up.) --}}
<style>
    .wo-strip { display: flex; list-style: none; margin: 0; padding: 12px 8px 10px; overflow-x: auto; gap: 0; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
    .wo-step { flex: 1 0 auto; min-width: 104px; position: relative; text-align: center; padding: 0 6px; font-size: 12px; line-height: 1.25; }
    .wo-step + .wo-step::before { content: ''; position: absolute; top: 11px; left: -50%; width: 100%; height: 2px; background: var(--border); }
    .wo-step[data-state="done"] + .wo-step::before, .wo-step[data-state="done"] + .wo-step[data-state="current"]::before { background: var(--ds-green, #16a34a); }
    .wo-dot { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--surface); border: 2px solid var(--border); color: var(--text-muted); }
    .wo-step[data-state="done"] .wo-dot { background: var(--ds-green, #16a34a); border-color: var(--ds-green, #16a34a); color: #fff; }
    .wo-step[data-state="current"] .wo-dot { background: var(--brand-icon, #0ea5e9); border-color: var(--brand-icon, #0ea5e9); color: #fff; box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand-icon, #0ea5e9) 22%, transparent); }
    .wo-step-label { display: block; margin-top: 4px; font-weight: 600; color: var(--text-primary); }
    .wo-step[data-state="todo"] .wo-step-label { font-weight: 500; color: var(--text-muted); opacity: .75; }
    .wo-step[data-state="current"] .wo-step-label { color: var(--brand-icon, #0ea5e9); }
    .wo-step-at { display: block; color: var(--text-muted); font-size: 11px; }
    .wo-next { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); border-left-width: 5px; background: var(--surface); }
    .wo-next-info { border-left-color: var(--brand-icon, #0ea5e9); }
    .wo-next-warn { border-left-color: var(--ds-amber, #f59e0b); }
    .wo-next-danger { border-left-color: var(--ds-crimson, #b3261e); }
    .wo-next-ok { border-left-color: var(--ds-green, #16a34a); }
    .wo-next-muted { border-left-color: var(--text-muted); }
    .wo-next-text { flex: 1 1 320px; font-size: 14px; }
    .wo-next-label { display: inline-block; margin-right: 8px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
    .wo-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; font-size: 13px; margin: 0; }
    .wo-facts dt { color: var(--text-muted); }
    .wo-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .wo-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
    .wo-panel-head { display: flex; align-items: center; gap: 10px; padding: 11px 16px; cursor: pointer; list-style: none; }
    .wo-panel-head::-webkit-details-marker { display: none; }
    .wo-chev { width: 8px; height: 8px; border-right: 2px solid var(--text-muted); border-bottom: 2px solid var(--text-muted); transform: rotate(-45deg); transition: transform .12s; flex: none; }
    .wo-panel[open] > .wo-panel-head .wo-chev { transform: rotate(45deg); }
    .wo-panel-title { font-size: 14px; font-weight: 700; flex: none; }
    .wo-panel-sum { font-size: 12px; color: var(--text-muted); min-width: 0; flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .wo-panel[open] > .wo-panel-head { border-bottom: 1px solid var(--border); }
    .wo-panel-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }
    .wo-panel-body > div, .wo-panel-body > [id] { border: 0 !important; padding: 0 !important; background: transparent !important; border-radius: 0 !important; }
    .wo-panel-body > * + * { border-top: 1px solid var(--border) !important; padding-top: 12px !important; }
    .wo-panel-body .wo-embedded-title { display: none; }
    .wo-btnrow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .wo-btnrow .corex-btn-primary, .wo-btnrow .corex-btn-outline, .wo-next .corex-btn-primary, .wo-next .corex-btn-outline, .wo-foot .corex-btn-outline { min-height: 34px; display: inline-flex; align-items: center; justify-content: center; }
    .wo-lbl { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }

    /* the Returned | Next bar: one block, two halves (the job card's pattern) */
    .wo-next { align-items: stretch; }
    .jc-bar-half { flex: 1 1 320px; min-width: 0; font-size: 14px; display: flex; flex-direction: column; justify-content: center; gap: 2px; }
    .jc-bar-half + .jc-bar-half { flex: 1 1 320px; border-left: 1px solid var(--border); padding-left: 16px; }
    .jc-bar-next { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; justify-content: space-between; }
    .jc-bar-next .wo-btnrow { flex: none; }
    .jc-returned-link { color: inherit; text-decoration: none; border-bottom: 1px dashed var(--text-muted); }
    .jc-returned-link:hover { border-bottom-style: solid; }
    .jc-waiting { display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 999px; font-size: 11px; font-weight: 700; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 22%, transparent); color: #b45309; }
    .jc-flash { transition: background-color .4s; background-color: rgba(14, 165, 233, .18) !important; }
    @media (max-width: 760px) { .jc-bar-half + .jc-bar-half { border-left: 0; padding-left: 0; border-top: 1px solid var(--border); padding-top: 10px; } .wo-next .corex-btn-primary, .wo-next .corex-btn-outline { width: 100%; } }
    @media (max-width: 600px) { .wo-panel-head { flex-wrap: wrap; } .wo-panel-sum { flex-basis: 100%; white-space: normal; padding-left: 18px; } }
</style>
