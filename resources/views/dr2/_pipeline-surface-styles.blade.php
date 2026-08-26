{{-- Pipeline Dashboard — shared surface CSS (tabs + step tiles + modals), copied from the board so the
     Timeline and List views render the SAME tab bar and step cards. Kept as a partial (not a Vite
     rebuild) so it applies on qa1 without an asset build. Included by pipeline-timeline + pipeline-list. --}}
<style>
.dr2-tabbar { display:flex; gap:.25rem; padding:.3rem; background:var(--surface-2,#f0f2f8);
    border:1px solid var(--border,rgba(0,0,0,.08)); border-radius:12px; margin-bottom:.85rem;
    position:sticky; top:0; z-index:5; flex-wrap:wrap; }
.dr2-tab { flex:1 1 auto; min-width:120px; border:0; background:transparent; color:var(--text-muted,#6b7280);
    font-family:inherit; font-size:.78rem; font-weight:600; line-height:1.15; padding:.55rem .4rem;
    border-radius:9px; cursor:pointer; transition:background .15s,color .15s;
    display:flex; align-items:center; justify-content:center; gap:.35rem; text-align:center; }
.dr2-tab:hover { color:var(--text-primary,#111827); }
.dr2-tab.corex-tab-active { background:var(--brand-button,#0ea5e9); color:#fff; box-shadow:0 1px 3px rgba(2,20,40,.18); }
.dr2-tab:focus-visible { outline:2px solid var(--brand-button,#0ea5e9); outline-offset:2px; }
.dr2-sect-head { display:flex; align-items:center; gap:.5rem; width:100%; border:0; background:transparent;
    color:var(--text-primary,#111827); font-family:inherit; cursor:pointer; padding:0; text-align:left; }
.dr2-sect-title { font-size:.9rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#6b7280); }
.dr2-chev { transition:transform .2s; color:var(--text-muted,#9ca3af); font-size:.85rem; line-height:1; }
.dr2-chev.dr2-chev-closed { transform:rotate(-90deg); }
.dr2-stage-h { margin:.9rem .15rem .35rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; }
.dr2-stage-h span { display:block; font-weight:400; text-transform:none; letter-spacing:0; font-size:.72rem; color:#9ca3af; }
.dr2-tile { width:200px; min-height:172px; box-sizing:border-box; display:flex; flex-direction:column;
    border:1px solid var(--corex-border,#e5e7eb); border-radius:10px; padding:.5rem .55rem; background:var(--surface,#fff); position:relative; }
.dr2-tile--wide { width:100%; }
.dr2-tile--gate { background:#fffbeb; border:1px solid #fcd34d; }
.dr2-tile--done { opacity:.62; filter:grayscale(.3); }
.dr2-tile--na { opacity:.6; }
.dr2-tile--warn { border-left:3px solid #dc2626; background:#fef2f2; }
.dr2-tile.dr2-drop-ok { outline:2px dashed #2563eb; outline-offset:2px; }
.dr2-tile__head { display:flex; align-items:flex-start; gap:.35rem; }
.dr2-tile__grip { cursor:grab; color:#cbd5e1; font-size:.8rem; line-height:1.1; user-select:none; }
.dr2-tile__rag { flex:0 0 auto; width:.6rem; height:.6rem; border-radius:50%; margin-top:.18rem; }
.dr2-tile__name { flex:1 1 auto; font-weight:700; font-size:.82rem; line-height:1.15; color:#111827; word-break:break-word; }
.dr2-tile__name--done { font-weight:600; text-decoration:line-through; text-decoration-color:rgba(0,0,0,.28); color:#374151; }
.dr2-tile__name--na { text-decoration:line-through; }
.dr2-tile__check { color:#047857; font-weight:800; margin-right:.1rem; }
.dr2-tile__tags { display:flex; flex-wrap:wrap; gap:.25rem; margin:.25rem 0 0; min-height:.9rem; }
.dr2-tag { font-size:.66rem; padding:0 .3rem; border-radius:.5rem; line-height:1.4; }
.dr2-tag--off { color:#9ca3af; background:#f3f4f6; }
.dr2-tag--ms { color:#b45309; }
.dr2-tag--custom { color:#2563eb; }
.dr2-tile__meta { display:flex; align-items:center; justify-content:space-between; gap:.35rem; margin:.3rem 0 .1rem; }
.dr2-tile__date { font-size:.72rem; color:#6b7280; white-space:nowrap; }
.dr2-tile__badge { font-size:.66rem; padding:.1rem .45rem; border-radius:1rem; white-space:nowrap; }
.dr2-tile__gatenote { font-size:.7rem; color:#92400e; margin:.15rem 0; }
.dr2-tile__warnnote { font-size:.7rem; font-weight:600; color:#b91c1c; margin:.15rem 0; }
.dr2-tile__sub { font-size:.7rem; color:#6b7280; margin:.15rem 0; }
.dr2-tile__btns { margin-top:auto; display:grid; grid-template-columns:repeat(3,1fr); gap:.22rem; padding-top:.4rem; }
.dr2-tile__btns form { display:block; margin:0; }
.dr2-bt { display:block; width:100%; box-sizing:border-box; text-align:center; font-family:inherit; font-size:.68rem;
    line-height:1.1; padding:.28rem .1rem; border:1px solid var(--corex-border,#e5e7eb); border-radius:6px;
    background:#fff; color:#374151; cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dr2-bt:hover { background:#f9fafb; }
.dr2-bt--go { color:#047857; border-color:#6ee7b7; }
.dr2-bt--danger { color:#b91c1c; }
.dr2-bt--dis { color:#c7cdd6; background:#fafbfc; cursor:not-allowed; }
.dr2-tile.dr2-dragging { opacity:.5; }
.dr2-tile__grip:active { cursor:grabbing; }
.dr2-modal { position:fixed; inset:0; z-index:120; display:flex; align-items:center; justify-content:center; padding:1rem; }
.dr2-modal__bg { position:absolute; inset:0; background:rgba(15,23,42,.4); }
.dr2-modal__card { position:relative; z-index:1; background:var(--surface,#fff); border-radius:12px; padding:1rem 1.1rem; width:min(420px,92vw); box-shadow:0 10px 40px rgba(2,20,40,.28); }
.dr2-modal__card--wide { width:min(560px,94vw); }
.dr2-modal__h { margin:0 0 .7rem; font-size:.95rem; font-weight:700; color:#111827; }
.dr2-modal__lb { display:block; font-size:.78rem; color:#374151; margin-bottom:.55rem; }
.dr2-modal__row { display:flex; justify-content:flex-end; gap:.5rem; margin-top:.9rem; }
.dr2-modal__cmform { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.dr2-modal__thread { max-height:38vh; overflow-y:auto; border:1px solid var(--corex-border,#e5e7eb); border-radius:8px; padding:.5rem; }
.dr2-cmt { font-size:.8rem; margin-bottom:.35rem; color:#374151; }
.dr2-cmt__by { color:#9ca3af; font-size:.72rem; }
.dr2-cmt__empty { font-size:.78rem; color:#9ca3af; }
/* List view — step tiles laid out as a vertical, full-width stack (Johan: the board's cards, top-to-bottom). */
.dr2-listwrap .dr2-tile { width:100%; min-height:0; }
.dr2-listwrap .dr2-lrow { position:relative; }
.dr2-listwrap .dr2-lrow.dr2-drag-over { outline:2px dashed #2563eb; outline-offset:2px; border-radius:10px; }
.dr2-listwrap .dr2-lrow.dr2-dragging { opacity:.45; }

/* ── Stage-2 concurrent segments (LaneComposer): full-width sequence points + dashed concurrent bands ── */
.dr2-band { margin:.4rem 0; border:1px dashed #cbd5e1; border-radius:10px; padding:.6rem .5rem .5rem; position:relative; }
.dr2-band__tag { position:absolute; top:-.6rem; left:.7rem; background:var(--surface,#fff); padding:0 .35rem; font-size:.66rem; color:#94a3b8; letter-spacing:.03em; }
.dr2-band__lanes { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.15rem; align-items:flex-start; }
.dr2-lane { display:flex; flex-direction:column; align-items:stretch; gap:.15rem; flex:0 0 auto; }
.dr2-lane__link { text-align:center; color:#cbd5e1; font-size:.7rem; line-height:.7; }
.dr2-band__drop { font-size:.68rem; color:#94a3b8; border:1px dashed #d1d5db; border-radius:7px; padding:.25rem .5rem; margin-bottom:.4rem; text-align:center; }
.dr2-band__drop.dr2-drop-ok { border-color:#2563eb; color:#2563eb; background:#eff6ff; }
.dr2-seq { position:relative; padding-left:.55rem; margin:.4rem 0; }
.dr2-seq__rail { position:absolute; left:0; top:.15rem; bottom:.15rem; width:4px; border-radius:3px; background:#2563eb; }

/* ── Phased vertical layout (Johan's APPROVED sectioned mockup) ── */
.dr2-ph { display:flex; flex-direction:column; gap:.2rem; }
.dr2-ph-anchor { background:#eef2ff; border:1px solid #dbe4ff; border-radius:12px; padding:.15rem .35rem; margin-bottom:.4rem; }
.dr2-ph-anchor .dr2-tile--wide { border:0; background:transparent; }
.dr2-ph-arrow { text-align:center; color:#94a3b8; font-size:1.1rem; line-height:1; margin:.15rem 0; }
.dr2-ph-stage { background:var(--surface,#fff); border:1px solid var(--corex-border,#e5e7eb); border-radius:14px; padding:.85rem .95rem 1rem; box-shadow:0 1px 2px rgba(15,23,42,.04); margin:.15rem 0; }
.dr2-ph-stage.is-locked { opacity:.72; }
.dr2-ph-stage__h { display:flex; align-items:center; gap:.55rem; }
.dr2-ph-stage__n { width:1.5rem; height:1.5rem; flex:0 0 1.5rem; border-radius:7px; background:#2563eb; color:#fff; font-weight:700; font-size:.85rem; display:flex; align-items:center; justify-content:center; }
.dr2-ph-stage__t { font-size:.98rem; font-weight:700; color:#0f172a; }
.dr2-ph-stage__s { color:#64748b; font-size:.76rem; margin:.2rem 0 .8rem 2.05rem; }
.dr2-ph-lock { font-size:.72rem; color:#64748b; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; padding:.35rem .6rem; margin:.1rem 0 .7rem 2.05rem; display:inline-flex; align-items:center; gap:.35rem; }
.dr2-ph-grp { border:1px solid var(--corex-border,#e5e7eb); border-radius:11px; padding:.6rem .7rem .7rem; margin-bottom:.7rem; background:#f7f9fc; }
.dr2-ph-grp:last-child { margin-bottom:0; }
.dr2-ph-grp__h { display:flex; align-items:center; gap:.4rem; font-size:.8rem; font-weight:700; color:#1e293b; margin-bottom:.55rem; }
.dr2-ph-grp__ic { font-size:.95rem; }
.dr2-ph-grp__sub { color:#64748b; font-weight:500; }
.dr2-ph-pill { margin-left:auto; font-size:.6rem; font-weight:800; letter-spacing:.04em; border-radius:5px; padding:.12rem .4rem; }
.dr2-ph-pill--active { color:#065f46; background:#d1fae5; }
.dr2-ph-pill--done { color:#475569; background:#e2e8f0; }
.dr2-ph-note { font-size:.72rem; color:#94a3b8; font-style:italic; margin-top:.5rem; line-height:1.45; }
/* the GRANTED gate bar */
.dr2-ph-gate { margin:.55rem 0; display:flex; }
.dr2-ph-gate__inner { flex:1; display:flex; align-items:center; gap:.75rem; border-radius:12px; padding:.7rem 1.15rem;
    background:linear-gradient(90deg,#1e3a8a,#2563eb); color:#fff; box-shadow:0 3px 10px rgba(37,99,235,.28); }
.dr2-ph-gate--pending .dr2-ph-gate__inner { background:linear-gradient(90deg,#334155,#475569); box-shadow:0 3px 10px rgba(71,85,105,.24); }
.dr2-ph-gate__star { font-size:1.15rem; color:#fde68a; }
.dr2-ph-gate--pending .dr2-ph-gate__star { color:#cbd5e1; }
.dr2-ph-gate__t { font-weight:800; font-size:.95rem; letter-spacing:.02em; }
.dr2-ph-gate__s { font-size:.74rem; opacity:.92; }
.dr2-ph-addbtn { margin-top:.9rem; font-size:.78rem; color:#2563eb; background:#fff; border:1px dashed #bfdbfe; border-radius:8px; padding:.5rem .9rem; cursor:pointer; font-family:inherit; font-weight:600; }
.dr2-ph-addbtn:hover { background:#eff6ff; }
</style>
