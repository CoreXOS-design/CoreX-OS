{{-- Wording screens: the document column + field markers + clause editor chrome (spec §11.13). --}}
<style>
    .doc-col { background:#fff; border:1px solid #d9dee8; border-radius:4px; box-shadow:0 1px 3px rgba(15,23,42,.08); padding: 22px 44px; color:#111827; }
    .doc-col.agr { font-size: 14px; }
    .agr .tok { display:inline-block; background:#e2e8f0; color:#334155; border:1px solid #cbd5e1; border-radius:4px; padding:0 .4em; font-size:.82em; line-height:1.5; white-space:nowrap; vertical-align:baseline; }
    .agr .tok::before { content:'▢ '; color:#64748b; }
    .part-tabs { display:flex; flex-wrap:wrap; gap:.35rem; }
    .part-tabs a { padding:.3rem .8rem; border-radius:999px; font-size:.82rem; border:1px solid var(--border); color: var(--text-secondary); background: var(--surface); }
    .part-tabs a.on { background: var(--brand-icon); border-color: var(--brand-icon); color:#fff; }
    ins { background:#dcfce7; color:#14532d; text-decoration:none; padding:0 1px; border-radius:2px; }
    del { background:#fee2e2; color:#7f1d1d; padding:0 1px; border-radius:2px; }
    @media (max-width: 640px) { .doc-col { padding: 14px 14px; } }
</style>
