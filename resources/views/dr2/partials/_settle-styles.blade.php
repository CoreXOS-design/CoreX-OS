{{-- DR2 Settlement — page-scoped look. The page used to sit inside the legacy .hfc-card frame (card inside
     a card, global white-field rules). It now uses the corex-content frame like the Deal Register, so its
     section cards, labels and fields get theme-token styling here (light + dark). Scoped to #dr2-settle. --}}
<style>
  /* section headings + cards */
  #dr2-settle .ds-section-header{border-left:0;padding-left:0;margin-bottom:.15rem;font-size:.9375rem;font-weight:600;color:var(--text-primary)}
  #dr2-settle .ds-section-sub{padding-left:0;font-size:.75rem;color:var(--text-muted)}
  #dr2-settle .ds-status-card{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:1.25rem;box-shadow:none}
  #dr2-settle .ds-status-card:hover{box-shadow:none}
  /* the two pool cards keep their side colour (listing / selling) as a slim left edge */
  #dr2-settle .ds-status-card[style*="border-left-color"]{border-left-width:3px}

  /* labels — sentence case, like the other CoreX forms */
  #dr2-settle .ds-label{text-transform:none;letter-spacing:0;font-size:.75rem;font-weight:500;color:var(--text-secondary)}

  /* agent rows + their fields */
  #dr2-settle .settle-row{border-radius:6px;box-shadow:none;background:var(--surface-2)!important;transition:box-shadow 200ms}
  #dr2-settle .settle-row:hover{box-shadow:0 0 0 1px var(--border-hover)}
  #dr2-settle .settle-row input,#dr2-settle .settle-row select{border-radius:6px;background:var(--surface)!important;font-family:inherit}
  #dr2-settle .settle-row input::placeholder{color:var(--text-muted)}
  #dr2-settle .settle-row select option{background:var(--surface);color:var(--text-primary)}
  #dr2-settle input[type=checkbox]{accent-color:var(--brand-button,#0ea5e9);width:1rem;height:1rem}
  #dr2-settle label{color:var(--text-secondary)}

  /* checksum OK / NOT OK — the live recalc toggles these two classes */
  #dr2-settle .text-green-700{color:var(--ds-green,#059669)}
  #dr2-settle .text-red-700{color:var(--ds-red,#dc2626)}
</style>
