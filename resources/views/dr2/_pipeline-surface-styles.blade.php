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
</style>
