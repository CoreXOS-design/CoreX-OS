{{-- Screen styling for the paginated agreement sheets (shared by the recipient page and the owner review/countersign page). --}}
<style>
    .agr-wrap { max-width: 860px; margin: 0 auto; padding: 0 0 6rem; }
    .sheet { background:#fff; border:1px solid #d9dee8; box-shadow: 0 1px 3px rgba(15,23,42,.08), 0 8px 24px rgba(15,23,42,.05); margin: 18px auto; padding: 22px 44px 0; border-radius: 4px; }
    .sheet-head { display:flex; align-items:center; gap: 12px; padding-bottom: 12px; border-bottom: 2px solid #0b2a4a; margin-bottom: 18px; }
    .sheet-head .sheet-logo { height: 40px; width: auto; max-width: 220px; object-fit: contain; object-position: left center; flex: none; }
    .sheet-head .brand { font-size: 1.35rem; font-weight: 800; color:#0b2a4a; letter-spacing:-.02em; }
    .sheet-head .co { margin-left:auto; text-align:right; font-size: .68rem; line-height: 1.4; color:#334155; }
    .sheet-head .co b { font-size: .78rem; color:#0b2a4a; }
    .sheet-body { min-height: 320px; padding-bottom: 14px; }
    .sheet-foot { display:flex; flex-wrap:wrap; gap:8px 16px; justify-content:space-between; align-items:center; border-top:1px solid #cbd5e1; padding: 10px 0 12px; font-size:.72rem; color:#475569; }
    .ini-box { display:inline-flex; align-items:center; gap:6px; }
    .ini-chip { display:inline-block; min-width: 34px; text-align:center; border:1px solid #64748b; padding:2px 8px; font-weight:700; color:#0b2a4a; border-radius:3px; background:#f8fafc; }
    .ini-chip.done { border-color:#16a34a; color:#166534; background:#f0fdf4; }
    .btn { appearance:none; border:0; border-radius:6px; padding:.55rem 1rem; font-weight:600; font-size:.85rem; cursor:pointer; background:#00b4d8; color:#fff; }
    .btn:disabled { opacity:.45; cursor:not-allowed; }
    .btn.ghost { background:#fff; color:#0b2a4a; border:1px solid #94a3b8; }
    .btn.sm { padding:.3rem .65rem; font-size:.75rem; }
    .agr .fld { font: inherit; border:1px solid #94a3b8; border-radius:4px; padding:.32rem .45rem; background:#fff; color:#0f172a; max-width:100%; box-sizing:border-box; }
    .agr .fld:focus { outline:2px solid #00b4d8; outline-offset:0; border-color:#00b4d8; }
    .agr .fld.err, .agr .opt.err .tick, .agr .sigpad.err { border-color:#dc2626; background:#fef2f2; }
    .agr td .fld, .agr li .fld { width:100%; }
    .agr td .fld + .fld, .agr td .fld + br + .fld { margin-top:4px; }
    .agr td .fld[type=date] { width:auto; }
    .agr td input.num { width: 6.5em; }
    .agr p .fld { width: min(100%, 17em); }
    .agr p .fld.num { width: 5em; }
    .agr textarea.fld { width:100%; min-height: 3.6em; resize: vertical; }
    .agr .opt { display:inline-block; vertical-align:middle; cursor:pointer; margin-right:2px; }
    .agr .opt input { position:absolute; opacity:0; width:1px; height:1px; }
    .agr .opt .tick { display:inline-block; width:18px; height:18px; border:2px solid #475569; border-radius:4px; vertical-align:-3px; background:#fff; }
    .agr .opt input:checked + .tick { background:#00b4d8; border-color:#0b2a4a; box-shadow: inset 0 0 0 3px #fff; }
    .agr .opt input:focus-visible + .tick { outline:2px solid #00b4d8; outline-offset:2px; }
    .agr .sigpad { border:1px dashed #64748b; border-radius:6px; background:#fff; padding:4px; max-width: 360px; }
    .agr .sigpad canvas { width:100%; height: 96px; display:block; touch-action:none; background: repeating-linear-gradient(transparent, transparent 94px, #e2e8f0 95px); }
    .agr .sigtools { display:flex; flex-wrap:wrap; gap:6px; margin-top:4px; }
    .agr .mini { appearance:none; border:1px solid #94a3b8; background:#f8fafc; border-radius:4px; font-size:.7rem; padding:2px 8px; cursor:pointer; color:#0b2a4a; }
    .agr .ctl { background:#f0f9ff; border:1px solid #bae6fd; border-radius:6px; padding:.6rem .8rem; }
    .agr .ctl .fld { width: 6em; margin-left:.4rem; }
    .agr .ctl-pair { display:flex; flex-wrap:wrap; gap:.6rem 1.6rem; align-items:center; }
    .agr .ctl-item { white-space:nowrap; }
    .agr .fld[data-derived] { background:#f3f4f6; color:#374151; cursor:default; }
    .agr .auto-tip { display:inline-block; vertical-align:middle; margin-left:.6rem; font-size:.76rem; line-height:1.3; color:#64748b; max-width:34em; }
    .agr .auto-tip::before { content:'i'; display:inline-block; width:1.15em; height:1.15em; line-height:1.15em; margin-right:.4em; text-align:center; font-weight:700; font-style:italic; font-size:.9em; color:#0369a1; border:1px solid #7dd3fc; border-radius:50%; background:#f0f9ff; }
    .agr .auto-tip a { color:#0369a1; text-decoration:underline; cursor:pointer; }
    .agr .auto-tip-float { float:right; max-width:52%; margin:0 0 .3rem .8rem; text-align:left; }
    .agr textarea.fld[readonly] { resize:none; }
    .agr .fld[readonly] { background:#f3f4f6; color:#374151; border-style:dashed; cursor:not-allowed; }
    .agr .opt input:disabled + .tick { background:#f3f4f6; border-style:dashed; border-color:#94a3b8; cursor:not-allowed; }
    .agr .opt input:disabled:checked + .tick { background:#7dd3fc; border-color:#0369a1; box-shadow: inset 0 0 0 3px #f3f4f6; }
    .agr .opt:has(input:disabled) { cursor:not-allowed; }
    .agr .ctl-note { display:block; font-size:.78rem; color:#92400e; margin-top:.25rem; }
    .agr .masked { cursor: pointer; }
    .agr [data-calc] { font-variant-numeric: tabular-nums; }
    .topbar { position: sticky; top: 0; z-index: 30; background:#0b2a4a; color:#fff; padding: .55rem 1rem; display:flex; flex-wrap:wrap; align-items:center; gap:.5rem 1rem; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
    .topbar .brand { font-weight:800; letter-spacing:-.02em; } .topbar .brand span { color:#33c4e0; }
    .topbar .sp { margin-left:auto; display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
    .topbar .stat { font-size:.78rem; opacity:.9; }
    .topbar .btn { background:#00b4d8; } .topbar .btn.ghost { background:transparent; color:#fff; border-color:#7aa0c4; }
    .panel { background:#fff; border:1px solid #d9dee8; border-radius:8px; padding:1rem 1.25rem; margin: 18px auto; }
    .todo { position: fixed; right: 12px; top: 56px; width: min(380px, calc(100vw - 24px)); max-height: 70vh; overflow:auto; background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow: 0 12px 32px rgba(15,23,42,.25); z-index: 40; padding: .75rem 1rem; color:#0f172a; }
    .todo[hidden] { display:none; }
    .todo a { display:block; padding:.3rem 0; border-bottom:1px solid #eef2f7; color:#b91c1c; text-decoration:none; font-size:.85rem; cursor:pointer; }
    .toast { position: fixed; left: 50%; transform: translateX(-50%); bottom: 14px; background:#0f172a; color:#fff; padding:.55rem 1rem; border-radius:6px; font-size:.82rem; z-index:50; max-width: calc(100vw - 24px); }
    .toast[hidden] { display:none; }
    @media (max-width: 640px) {
        .sheet { padding: 14px 12px 0; margin: 10px 0; border-left:0; border-right:0; border-radius:0; }
        .sheet-head .co { display:none; }
        .agr { font-size: 13px; }
        .agr table { display:block; overflow-x:auto; }
        .agr td, .agr th { min-width: 5em; }
        .agr td .fld, .agr p .fld { width:100%; }
        .topbar { padding:.45rem .6rem; }
    }
</style>
