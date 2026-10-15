{{-- AT-460 - the office job card screen's look: the SAME header / stepper / Next-bar family as the work order screen (.wo-* classes are the work order's own,
     repeated here so this screen does not depend on that file), plus the two-column body (.jc-*). One page scroll; the right column is sticky. --}}
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
    .wo-next { display: flex; flex-wrap: wrap; align-items: stretch; gap: 10px 16px; padding: 12px 16px; border-radius: 8px; border: 1px solid var(--border); border-left-width: 5px; background: var(--surface); }
    .wo-next-info { border-left-color: var(--brand-icon, #0ea5e9); }
    .wo-next-warn { border-left-color: var(--ds-amber, #f59e0b); }
    .wo-next-danger { border-left-color: var(--ds-crimson, #b3261e); }
    .wo-next-ok { border-left-color: var(--ds-green, #16a34a); }
    .wo-next-muted { border-left-color: var(--text-muted); }
    .wo-next-label { display: inline-block; margin-right: 8px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text-muted); }
    .wo-facts { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; font-size: 13px; margin: 0; }
    .wo-facts dt { color: var(--text-muted); }
    .wo-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .wo-btnrow { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .wo-btnrow .corex-btn-primary, .wo-btnrow .corex-btn-outline { min-height: 34px; display: inline-flex; align-items: center; justify-content: center; }

    /* the Returned | Next bar: one block, two halves */
    .jc-bar-half { flex: 1 1 320px; min-width: 0; font-size: 14px; display: flex; flex-direction: column; justify-content: center; gap: 2px; }
    .jc-bar-half + .jc-bar-half { flex: 1 1 320px; border-left: 1px solid var(--border); padding-left: 16px; }
    .jc-bar-next { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; justify-content: space-between; }
    .jc-bar-next .wo-btnrow { flex: none; }
    .jc-returned-link { color: inherit; text-decoration: none; border-bottom: 1px dashed var(--text-muted); }
    .jc-returned-link:hover { border-bottom-style: solid; }
    .jc-waiting { display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 999px; font-size: 11px; font-weight: 700; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 22%, transparent); color: #b45309; }
    @media (max-width: 760px) { .jc-bar-half + .jc-bar-half { border-left: 0; padding-left: 0; border-top: 1px solid var(--border); padding-top: 10px; } .wo-next .corex-btn-primary, .wo-next .corex-btn-outline { width: 100%; } }

    /* two columns: the work on the left, the money and the crew on the right (sticky). One page scroll. Below 1280px the columns stack, right column FIRST. */
    .jc-layout { display: flex; flex-direction: column; gap: 14px; align-items: stretch; }
    .jc-left { order: 2; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
    .jc-right { order: 1; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
    @media (min-width: 1280px) {
        .jc-layout { flex-direction: row; align-items: flex-start; }
        .jc-left { order: 1; flex: 1 1 0; }
        .jc-right { order: 2; flex: 0 0 360px; width: 360px; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow-y: auto; }
    }
    .jc-card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; }
    .jc-card > h2, .jc-card-head h2 { font-size: 13px; font-weight: 700; margin: 0; }
    .jc-card > h2 { margin-bottom: 8px; }
    .jc-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
    .jc-facts { display: grid; grid-template-columns: max-content minmax(0, 1fr) max-content minmax(0, 1fr); gap: 6px 14px; font-size: 13px; margin: 0; }
    .jc-facts dt { color: var(--text-muted); }
    .jc-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .jc-facts .jc-wide { grid-column: span 3; }
    @media (max-width: 760px) { .jc-facts { grid-template-columns: max-content minmax(0, 1fr); } .jc-facts .jc-wide { grid-column: span 1; } }

    /* tasks and lines: ONE column header, then a band per task with its lines under it */
    .jc-linebox { overflow-x: auto; }
    .jc-linebox > .jc-lines-inner { min-width: 560px; }
    .jc-task { border: 1px solid var(--border); border-radius: 6px; margin-top: 10px; overflow: hidden; }
    .jc-task-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 7px 10px; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)); border-bottom: 1px solid var(--border); }
    .jc-task-body { padding: 4px 10px 8px; }
    .jc-task.jc-general { border-style: dashed; }
    .jc-addrow { margin-top: 6px; padding: 6px 8px 8px; border-radius: 6px; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 7%, var(--surface)); border: 1px dashed color-mix(in srgb, var(--brand-icon, #0ea5e9) 45%, var(--border)); }
    .jc-addrow-cap { font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--brand-icon, #0ea5e9); }
    .jc-addrow form, .jc-addrow > div { padding-top: 2px !important; }
    .jc-addrow input, .jc-addrow select { height: 30px; box-sizing: border-box; }
    .jc-addrow button[type="submit"] { width: 26px !important; height: 26px !important; background: var(--brand-icon, #0ea5e9) !important; color: #fff !important; border-color: var(--brand-icon, #0ea5e9) !important; font-size: 17px !important; }
    .jc-await { margin-top: 6px; border: 1px solid #f59e0b; border-radius: 6px; padding: 4px 8px 8px; background: color-mix(in srgb, #f59e0b 9%, var(--surface)); }
    .jc-await input { height: 30px; box-sizing: border-box; }
    .jc-await-sub { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; font-size: 12px; color: var(--text-muted); }
    .jc-totals { margin-top: 12px; display: flex; justify-content: flex-end; }
    .jc-totals dl { display: grid; grid-template-columns: 1fr max-content; gap: 3px 28px; font-size: 13px; margin: 0; min-width: 260px; }
    .jc-totals dd { margin: 0; text-align: right; }
    .jc-totals .jc-total-final { font-weight: 700; border-top: 1px solid var(--border); padding-top: 4px; }
    .jc-btnrow-eq { display: flex; gap: 8px; }
    .jc-btnrow-eq > form, .jc-btnrow-eq > a, .jc-btnrow-eq > button { flex: 1 1 0; min-width: 0; }
    .jc-btnrow-eq .corex-btn-primary, .jc-btnrow-eq .corex-btn-outline { width: 100%; min-height: 34px; display: inline-flex; align-items: center; justify-content: center; }
    .jc-money-embed > div { border: 0 !important; padding: 0 !important; background: transparent !important; border-radius: 0 !important; }
    .jc-money-embed > div > * + * { margin-top: 10px; }
    .jc-cancel-link { font-size: 12px; color: var(--ds-crimson, #b3261e); text-decoration: underline; background: none; border: 0; padding: 0; cursor: pointer; }
    .jc-flash { transition: background-color .4s; background-color: rgba(14, 165, 233, .18) !important; }
    details.jc-fold > summary { cursor: pointer; font-size: 12px; color: var(--text-muted); list-style: revert; }
</style>
