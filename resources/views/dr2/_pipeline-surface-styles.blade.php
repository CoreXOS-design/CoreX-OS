{{-- Pipeline Dashboard — shared surface CSS (tabs + step tiles + modals), copied from the board so the
     Timeline and List views render the SAME tab bar and step cards. Kept as a partial (not a Vite
     rebuild) so it applies on qa1 without an asset build. Included by pipeline-timeline + pipeline-list.
     Theme tokens only (var(--surface) / --border / --text-* / --brand-button / --ds-*) so both views follow
     the light/dark theme like the rest of CoreX. --}}
<style>
.dr2-tabbar { display:flex; gap:.25rem; padding:.3rem; background:var(--surface-2,#f0f2f8);
    border:1px solid var(--border,rgba(0,0,0,.08)); border-radius:6px; margin-bottom:.85rem;
    position:sticky; top:0; z-index:5; flex-wrap:wrap; }
.dr2-tab { flex:1 1 auto; min-width:120px; border:0; background:transparent; color:var(--text-secondary,#4b5563);
    font-family:inherit; font-size:.78rem; font-weight:600; line-height:1.15; padding:.55rem .4rem;
    border-radius:5px; cursor:pointer; transition:background 200ms,color 200ms;
    display:flex; align-items:center; justify-content:center; gap:.35rem; text-align:center; }
.dr2-tab:hover { color:var(--text-primary,#111827); background:color-mix(in srgb,var(--text-primary,#111827) 5%,transparent); }
.dr2-tab.corex-tab-active { background:var(--brand-button,#0ea5e9); color:#fff; box-shadow:0 1px 3px var(--shadow,rgba(0,0,0,.08)); }
.dr2-tab:focus-visible { outline:2px solid var(--brand-button,#0ea5e9); outline-offset:2px; }
.dr2-sect-head { display:flex; align-items:center; gap:.5rem; width:100%; border:0; background:transparent;
    color:var(--text-primary,#111827); font-family:inherit; cursor:pointer; padding:0; text-align:left; }
.dr2-sect-title { font-size:.9rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted,#9ca3af); }
.dr2-chev { transition:transform .2s; color:var(--text-muted,#9ca3af); font-size:.85rem; line-height:1; }
.dr2-chev.dr2-chev-closed { transform:rotate(-90deg); }
.dr2-stage-h { margin:.9rem .15rem .35rem; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-secondary,#4b5563); }
.dr2-stage-h span { display:block; font-weight:400; text-transform:none; letter-spacing:0; font-size:.72rem; color:var(--text-muted,#9ca3af); }
.dr2-tile { width:200px; min-height:172px; box-sizing:border-box; display:flex; flex-direction:column;
    border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.5rem .55rem; background:var(--surface,#fff); position:relative;
    transition:border-color 200ms,box-shadow 200ms; }
.dr2-tile:hover { border-color:var(--border-hover,rgba(0,0,0,.14)); }
.dr2-tile--wide { width:100%; }
.dr2-tile--gate { background:color-mix(in srgb,var(--ds-amber,#f59e0b) 8%,var(--surface,#fff)); border:1px solid color-mix(in srgb,var(--ds-amber,#f59e0b) 45%,transparent); }
.dr2-tile--done { opacity:.62; filter:grayscale(.3); }
.dr2-tile--na { opacity:.6; }
.dr2-tile--warn { border-left:3px solid var(--ds-red,#dc2626); background:color-mix(in srgb,var(--ds-red,#dc2626) 7%,var(--surface,#fff)); }
.dr2-tile.dr2-drop-ok { outline:2px dashed var(--brand-button,#0ea5e9); outline-offset:2px; }
.dr2-tile__head { display:flex; align-items:flex-start; gap:.35rem; }
.dr2-tile__grip { cursor:grab; color:var(--text-muted,#9ca3af); font-size:.8rem; line-height:1.1; user-select:none; }
.dr2-tile__rag { flex:0 0 auto; width:.6rem; height:.6rem; border-radius:50%; margin-top:.18rem; }
.dr2-tile__name { flex:1 1 auto; font-weight:600; font-size:.82rem; line-height:1.15; color:var(--text-primary,#111827); word-break:break-word; }
.dr2-tile__name--done { text-decoration:line-through; text-decoration-color:var(--border-hover,rgba(0,0,0,.28)); color:var(--text-secondary,#4b5563); }
.dr2-tile__name--na { text-decoration:line-through; }
.dr2-tile__check { color:var(--ds-green,#059669); display:inline-flex; vertical-align:-1px; margin-right:.15rem; }
.dr2-tile__check svg, .dr2-tile__date svg, .dr2-tag svg, .dr2-tile__warnnote svg { width:.75rem; height:.75rem; flex:0 0 auto; }
.dr2-tile__tags { display:flex; flex-wrap:wrap; gap:.25rem; margin:.25rem 0 0; min-height:.9rem; }
.dr2-tag { display:inline-flex; align-items:center; gap:.2rem; font-size:.66rem; padding:0 .3rem; border-radius:.5rem; line-height:1.4; }
.dr2-tag--off { color:var(--text-secondary,#4b5563); background:var(--surface-2,#f0f2f8); }
.dr2-tag--ms { color:var(--ds-amber,#f59e0b); }
.dr2-tag--lock { color:var(--text-muted,#9ca3af); }
.dr2-tag--custom { color:var(--brand-button,#0ea5e9); }
.dr2-tile__meta { display:flex; align-items:center; justify-content:space-between; gap:.35rem; margin:.3rem 0 .1rem; }
.dr2-tile__date { display:inline-flex; align-items:center; gap:.25rem; font-size:.72rem; color:var(--text-secondary,#4b5563); white-space:nowrap; }
.dr2-tile__badge { font-size:.66rem; font-weight:600; padding:.1rem .45rem; border-radius:1rem; white-space:nowrap; }
/* the badge palette arrives as inline light-pastel backgrounds — tint from the text colour instead so the
   pill reads the same in light and dark */
.dr2-listwrap .dr2-tile__badge { background:color-mix(in srgb,currentColor 14%,transparent) !important; }
.dr2-tile__gatenote { font-size:.7rem; color:var(--ds-amber,#f59e0b); margin:.15rem 0; }
.dr2-tile__warnnote { display:flex; align-items:center; gap:.3rem; font-size:.7rem; font-weight:600; color:var(--ds-red,#dc2626); margin:.15rem 0; }
.dr2-tile__sub { font-size:.7rem; color:var(--text-muted,#9ca3af); margin:.15rem 0; }
.dr2-tile__btns { margin-top:auto; display:grid; grid-template-columns:repeat(3,1fr); gap:.22rem; padding-top:.4rem; }
.dr2-tile__btns form { display:block; margin:0; }
.dr2-bt { display:block; width:100%; box-sizing:border-box; text-align:center; font-family:inherit; font-size:.68rem;
    line-height:1.1; padding:.28rem .1rem; border:1px solid var(--border,#e5e7eb); border-radius:6px;
    background:var(--surface,#fff); color:var(--text-secondary,#4b5563); cursor:pointer; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    transition:background 200ms,border-color 200ms,color 200ms; }
.dr2-bt:hover { background:var(--surface-2,#f0f2f8); color:var(--text-primary,#111827); }
.dr2-bt--go { color:var(--ds-green,#059669); border-color:color-mix(in srgb,var(--ds-green,#059669) 45%,transparent); }
.dr2-bt--danger { color:var(--ds-red,#dc2626); }
.dr2-bt--dis { color:var(--text-muted,#9ca3af); background:var(--surface-2,#f0f2f8); cursor:not-allowed; opacity:.6; }
.dr2-tile.dr2-dragging { opacity:.5; }
.dr2-tile__grip:active { cursor:grabbing; }
.dr2-modal { position:fixed; inset:0; z-index:120; display:flex; align-items:center; justify-content:center; padding:1rem; }
.dr2-modal__bg { position:absolute; inset:0; background:rgba(0,0,0,.5); }
.dr2-modal__card { position:relative; z-index:1; background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:1rem 1.1rem; width:min(420px,92vw); box-shadow:0 10px 30px rgba(0,0,0,.18); color:var(--text-primary,#111827); }
.dr2-modal__card--wide { width:min(560px,94vw); }
.dr2-modal__h { margin:0 0 .7rem; font-size:.95rem; font-weight:700; color:var(--text-primary,#111827); }
.dr2-modal__lb { display:block; font-size:.78rem; color:var(--text-secondary,#4b5563); margin-bottom:.55rem; }
.dr2-modal__row { display:flex; justify-content:flex-end; gap:.5rem; margin-top:.9rem; }
.dr2-modal__cmform { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.dr2-modal__thread { max-height:38vh; overflow-y:auto; border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.5rem; }
.dr2-modal .corex-input { display:block; width:100%; margin-top:.25rem; font-family:inherit; font-size:.82rem; color:var(--text-primary,#111827); background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.4rem .55rem; transition:border-color 200ms; }
.dr2-modal .corex-input:focus { outline:none; border-color:var(--brand-button,#0ea5e9); }
.dr2-cmt { font-size:.8rem; margin-bottom:.35rem; color:var(--text-primary,#111827); }
.dr2-cmt__by { color:var(--text-muted,#9ca3af); font-size:.72rem; }
.dr2-cmt__empty { font-size:.78rem; color:var(--text-muted,#9ca3af); }
/* List view — step tiles laid out as a vertical, full-width stack (Johan: the board's cards, top-to-bottom). */
.dr2-listwrap .dr2-tile { width:100%; min-height:0; }
.dr2-listwrap .dr2-lrow { position:relative; }
.dr2-listwrap .dr2-lrow.dr2-drag-over { outline:2px dashed var(--brand-button,#0ea5e9); outline-offset:2px; border-radius:6px; }
.dr2-listwrap .dr2-lrow.dr2-dragging { opacity:.45; }

/* ── Stage-2 concurrent segments (LaneComposer): full-width sequence points + dashed concurrent bands ── */
.dr2-band { margin:.4rem 0; border:1px dashed var(--border-hover,#cbd5e1); border-radius:6px; padding:.6rem .5rem .5rem; position:relative; }
.dr2-band__tag { position:absolute; top:-.6rem; left:.7rem; background:var(--surface,#fff); padding:0 .35rem; font-size:.66rem; color:var(--text-muted,#9ca3af); letter-spacing:.03em; }
.dr2-band__lanes { display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.15rem; align-items:flex-start; }
.dr2-lane { display:flex; flex-direction:column; align-items:stretch; gap:.15rem; flex:0 0 auto; }
.dr2-lane__link { text-align:center; color:var(--text-muted,#9ca3af); font-size:.7rem; line-height:.7; }
.dr2-band__drop { font-size:.68rem; color:var(--text-muted,#9ca3af); border:1px dashed var(--border-hover,#d1d5db); border-radius:6px; padding:.25rem .5rem; margin-bottom:.4rem; text-align:center; }
.dr2-band__drop.dr2-drop-ok { border-color:var(--brand-button,#0ea5e9); color:var(--brand-button,#0ea5e9); background:color-mix(in srgb,var(--brand-button,#0ea5e9) 8%,transparent); }
.dr2-seq { position:relative; padding-left:.55rem; margin:.4rem 0; }
.dr2-seq__rail { position:absolute; left:0; top:.15rem; bottom:.15rem; width:4px; border-radius:3px; background:var(--brand-button,#0ea5e9); }

/* ── Phased vertical layout (Johan's APPROVED sectioned mockup) ── */
.dr2-ph { display:flex; flex-direction:column; gap:.2rem; }
.dr2-ph-anchor { background:color-mix(in srgb,var(--brand-button,#0ea5e9) 8%,var(--surface,#fff)); border:1px solid color-mix(in srgb,var(--brand-button,#0ea5e9) 25%,transparent); border-radius:6px; padding:.15rem .35rem; margin-bottom:.4rem; }
.dr2-ph-anchor .dr2-tile--wide { border:0; background:transparent; }
.dr2-ph-arrow { display:flex; justify-content:center; color:var(--text-muted,#9ca3af); line-height:1; margin:.15rem 0; }
.dr2-ph-arrow svg { width:1rem; height:1rem; }
.dr2-ph-stage { background:var(--surface,#fff); border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.85rem .95rem 1rem; margin:.15rem 0; }
.dr2-ph-stage.is-locked { opacity:.72; }
.dr2-ph-stage__h { display:flex; align-items:center; gap:.55rem; }
.dr2-ph-stage__n { width:1.5rem; height:1.5rem; flex:0 0 1.5rem; border-radius:6px; background:var(--brand-button,#0ea5e9); color:#fff; font-weight:700; font-size:.85rem; display:flex; align-items:center; justify-content:center; }
.dr2-ph-stage__t { font-size:.98rem; font-weight:700; color:var(--text-primary,#111827); }
.dr2-ph-stage__s { color:var(--text-muted,#9ca3af); font-size:.76rem; margin:.2rem 0 .8rem 2.05rem; }
.dr2-ph-lock { font-size:.72rem; color:var(--text-secondary,#4b5563); background:var(--surface-2,#f0f2f8); border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.35rem .6rem; margin:.1rem 0 .7rem 2.05rem; display:inline-flex; align-items:center; gap:.35rem; }
.dr2-ph-lock svg { width:.85rem; height:.85rem; flex:0 0 auto; }
.dr2-ph-grp { border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:.6rem .7rem .7rem; margin-bottom:.7rem; background:var(--surface-2,#f0f2f8); }
.dr2-ph-grp:last-child { margin-bottom:0; }
.dr2-ph-grp__h { display:flex; align-items:center; gap:.4rem; font-size:.8rem; font-weight:700; color:var(--text-primary,#111827); margin-bottom:.55rem; }
.dr2-ph-grp__ic { display:inline-flex; color:var(--brand-icon,#0ea5e9); }
.dr2-ph-grp__ic svg { width:1rem; height:1rem; }
.dr2-ph-grp__sub { color:var(--text-muted,#9ca3af); font-weight:500; }
.dr2-ph-pill { margin-left:auto; font-size:.6rem; font-weight:700; letter-spacing:.04em; border-radius:5px; padding:.12rem .4rem; }
.dr2-ph-pill--active { color:var(--ds-green,#059669); background:color-mix(in srgb,var(--ds-green,#059669) 14%,transparent); }
.dr2-ph-pill--done { color:var(--text-secondary,#4b5563); background:color-mix(in srgb,var(--text-primary,#111827) 8%,transparent); }
.dr2-ph-note { font-size:.72rem; color:var(--text-muted,#9ca3af); font-style:italic; margin-top:.5rem; line-height:1.45; }
/* the GRANTED gate bar */
.dr2-ph-gate { margin:.55rem 0; display:flex; }
.dr2-ph-gate__inner { flex:1; display:flex; align-items:center; gap:.75rem; border-radius:6px; padding:.7rem 1.15rem;
    background:color-mix(in srgb,var(--ds-green,#059669) 10%,var(--surface,#fff)); border:1px solid color-mix(in srgb,var(--ds-green,#059669) 35%,transparent); border-left:3px solid var(--ds-green,#059669); color:var(--text-primary,#111827); }
.dr2-ph-gate--pending .dr2-ph-gate__inner { background:var(--surface-2,#f0f2f8); border-color:var(--border,#e5e7eb); border-left-color:var(--text-muted,#9ca3af); }
.dr2-ph-gate__star { display:inline-flex; color:var(--ds-green,#059669); }
.dr2-ph-gate__star svg { width:1.15rem; height:1.15rem; }
.dr2-ph-gate--pending .dr2-ph-gate__star { color:var(--text-muted,#9ca3af); }
.dr2-ph-gate__t { font-weight:700; font-size:.95rem; letter-spacing:.02em; }
.dr2-ph-gate__s { font-size:.74rem; color:var(--text-secondary,#4b5563); }
.dr2-ph-addbtn { margin-top:.9rem; font-size:.78rem; color:var(--brand-button,#0ea5e9); background:var(--surface,#fff); border:1px dashed var(--border-hover,#bfdbfe); border-radius:6px; padding:.5rem .9rem; cursor:pointer; font-family:inherit; font-weight:600; }
.dr2-ph-addbtn:hover { background:var(--surface-2,#f0f2f8); }
</style>
