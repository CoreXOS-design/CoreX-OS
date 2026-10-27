{{-- Finance Audit (run + deal drill-down) — shared page-scoped look. Theme tokens only (light + dark). --}}
<style>
  .fa-crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem;font-size:.75rem;color:var(--text-muted)}
  .fa-crumbs a{color:var(--text-secondary);text-decoration:none;transition:color 200ms}
  .fa-crumbs a:hover{color:var(--brand-icon,#0ea5e9)}
  .fa-crumbs svg{width:.75rem;height:.75rem;flex:0 0 auto}
  .fa-meta{display:flex;flex-wrap:wrap;gap:.25rem 1rem;font-size:.75rem;color:var(--text-muted);margin-top:.25rem}
  .fa-meta b{font-weight:500;color:var(--text-secondary)}
  .fa-card{background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden}
  .fa-card-head{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:.5rem;padding:.875rem 1.25rem;border-bottom:1px solid var(--border)}
  .fa-card-title{font-size:.9375rem;font-weight:600;color:var(--text-primary)}
  .fa-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}
  @media (min-width:768px){.fa-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}}
  .fa-tile{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:.875rem 1rem}
  .fa-tile-n{font-size:1.5rem;font-weight:700;line-height:1.1;color:var(--text-primary);font-variant-numeric:tabular-nums}
  .fa-tile-l{font-size:.75rem;color:var(--text-muted);margin-top:.15rem}
  .fa-tile.ok .fa-tile-n{color:var(--ds-green,#059669)}
  .fa-tile.bad .fa-tile-n{color:var(--ds-red,#dc2626)}
  .fa-tile.warn .fa-tile-n{color:var(--ds-amber,#f59e0b)}
  .fa-neg{color:var(--ds-red,#dc2626);font-weight:600}
  .fa-ok{color:var(--ds-green,#059669)}
  .fa-row-err{background:color-mix(in srgb,var(--ds-red,#dc2626) 7%,transparent)}
  .fa-table tbody tr{transition:background 200ms}
  .fa-table tbody tr:not(.fa-row-err):not(.fa-sub):hover{background:var(--surface-2)}
  .fa-group-btn{width:100%;display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.875rem 1.25rem;text-align:left;background:var(--surface);transition:background 200ms}
  .fa-group-btn:hover{background:var(--surface-2)}
  .fa-group-btn.has-err{background:color-mix(in srgb,var(--ds-red,#dc2626) 7%,var(--surface))}
  .fa-group-btn.has-err:hover{background:color-mix(in srgb,var(--ds-red,#dc2626) 11%,var(--surface))}
  .fa-link{color:var(--brand-icon,#0ea5e9);text-decoration:none}
  .fa-link:hover{text-decoration:underline}
</style>
