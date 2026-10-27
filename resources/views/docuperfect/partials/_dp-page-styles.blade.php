{{-- DocuPerfect pages moved off the legacy .hfc-card frame onto corex-content (Andre 2026-10-26). These pages
     were written with fixed Tailwind palette classes (bg-white, text-slate-*, bg-red-50 …) that only suit the
     light theme; inside .dp-page they map to theme tokens so the same markup reads correctly in light AND dark.
     Solid action buttons (bg-emerald-600 / bg-red-600 with white text) keep their colour. --}}
<style>
  /* section cards + headings */
  .dp-page .ds-status-card{background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:none}
  .dp-page .ds-status-card:hover{box-shadow:none}
  .dp-page h3.uppercase{text-transform:none;letter-spacing:0}

  /* neutral text */
  .dp-page .text-slate-800,.dp-page .text-slate-700{color:var(--text-primary)}
  .dp-page .text-slate-600,.dp-page .text-slate-500{color:var(--text-secondary)}
  .dp-page .text-slate-400,.dp-page .text-slate-300{color:var(--text-muted)}

  /* neutral surfaces + borders */
  .dp-page .bg-white{background:var(--surface)}
  .dp-page .bg-slate-100{background:var(--surface-2)}
  .dp-page .hover\:bg-slate-50:hover{background:var(--surface-2)}
  .dp-page .border-slate-100,.dp-page .border-slate-200,.dp-page .border-slate-300{border-color:var(--border)}

  /* green — done / success */
  .dp-page .text-emerald-500,.dp-page .text-emerald-600,.dp-page .text-emerald-700,.dp-page .text-emerald-900{color:var(--ds-green,#059669)}
  .dp-page .bg-emerald-50,.dp-page .bg-emerald-100{background:color-mix(in srgb,var(--ds-green,#059669) 12%,transparent)}
  .dp-page .bg-emerald-50\/30{background:color-mix(in srgb,var(--ds-green,#059669) 6%,var(--surface))}
  .dp-page .border-emerald-200{border-color:color-mix(in srgb,var(--ds-green,#059669) 35%,transparent)}

  /* amber — waiting / deferred */
  .dp-page .text-amber-500,.dp-page .text-amber-600,.dp-page .text-amber-700{color:var(--ds-amber,#f59e0b)}
  .dp-page .bg-amber-100{background:color-mix(in srgb,var(--ds-amber,#f59e0b) 14%,transparent)}
  .dp-page .bg-amber-50\/30{background:color-mix(in srgb,var(--ds-amber,#f59e0b) 6%,var(--surface))}
  .dp-page .border-amber-200{border-color:color-mix(in srgb,var(--ds-amber,#f59e0b) 40%,transparent)}

  /* blue — in progress / information */
  .dp-page .text-blue-400,.dp-page .text-blue-500,.dp-page .text-blue-600,.dp-page .text-blue-700,.dp-page .text-blue-800{color:var(--brand-icon,#0ea5e9)}
  .dp-page .bg-blue-50,.dp-page .bg-blue-100{background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 10%,transparent)}
  .dp-page .border-blue-100{border-color:color-mix(in srgb,var(--brand-icon,#0ea5e9) 30%,transparent)}

  /* red — errors / reject */
  .dp-page .text-red-500,.dp-page .text-red-600,.dp-page .text-red-700{color:var(--ds-red,#dc2626)}
  .dp-page .text-red-800,.dp-page .text-red-900{color:var(--text-primary)}
  .dp-page .hover\:text-red-700:hover{color:var(--ds-red,#dc2626);text-decoration:underline}
  .dp-page .bg-red-50{background:color-mix(in srgb,var(--ds-red,#dc2626) 10%,transparent)}
  .dp-page .bg-red-100,.dp-page .hover\:bg-red-100:hover{background:color-mix(in srgb,var(--ds-red,#dc2626) 16%,transparent)}
  .dp-page .border-red-200{border-color:color-mix(in srgb,var(--ds-red,#dc2626) 30%,transparent)}
  /* success boxes use emerald-900 text on emerald-50 — keep the text readable on the tint */
  .dp-page .bg-emerald-50.text-emerald-900{color:var(--text-primary)}

  /* the native dialog element defaults to a white sheet */
  .dp-page dialog{background:var(--surface);color:var(--text-primary);border:1px solid var(--border)}
  .dp-page dialog::backdrop{background:rgba(0,0,0,.5)}

  .dp-ico{width:1rem;height:1rem;flex:0 0 auto;display:inline-block}
</style>
