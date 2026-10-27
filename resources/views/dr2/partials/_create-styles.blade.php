{{-- DR2 Add / Edit Deal — page-scoped look. The page used to sit inside the legacy .hfc-card frame, whose
     global !important rules painted every field white with a navy submit button. It now uses the
     corex-content frame like the Deal Register, so its fields, labels and section cards get theme-token
     styling here instead (light + dark). Scoped to #dr2-create; the add-new modals live inside it too. --}}
<style>
  /* section headings + cards */
  #dr2-create .ds-section-header{border-left:0;padding-left:0;margin-bottom:.15rem;font-size:.9375rem;font-weight:600;color:var(--text-primary)}
  #dr2-create .ds-section-sub{padding-left:0;font-size:.75rem;color:var(--text-muted)}
  #dr2-create .ds-status-card{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:1.25rem;box-shadow:none}
  #dr2-create .ds-status-card:hover{box-shadow:none}

  /* labels — sentence case, like the other CoreX forms */
  #dr2-create .ds-label{text-transform:none;letter-spacing:0;font-size:.75rem;font-weight:500;color:var(--text-secondary)}
  #dr2-create h3.ds-label{font-size:.875rem;font-weight:600;color:var(--text-primary);border-top:1px solid var(--border);padding-top:1rem;margin-top:.25rem!important}

  /* fields */
  #dr2-create input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=hidden]),
  #dr2-create select,
  #dr2-create textarea{font-family:inherit;font-size:.875rem;color:var(--text-primary);background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:.5rem .75rem;box-shadow:none;transition:border-color 200ms,background 200ms}
  #dr2-create input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=hidden]):hover,
  #dr2-create select:hover,
  #dr2-create textarea:hover{border-color:color-mix(in srgb,var(--brand-button,#0ea5e9) 55%,var(--border))}
  #dr2-create input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=hidden]):focus,
  #dr2-create select:focus,
  #dr2-create textarea:focus{outline:none;box-shadow:none;border-color:var(--brand-button,#0ea5e9)}
  #dr2-create input:disabled,#dr2-create select:disabled,#dr2-create input[readonly]{background:var(--surface-2);color:var(--text-secondary);cursor:not-allowed}
  #dr2-create input::placeholder,#dr2-create textarea::placeholder{color:var(--text-muted)}
  #dr2-create select option{background:var(--surface);color:var(--text-primary)}
  /* every plain field fills its grid cell, so the columns line up; fields that set their own width
     (w-24 split %, w-32 overrides, money fields) keep it */
  #dr2-create .deal-grid input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=hidden]):not([class*="w-"]):not(.money-input),
  #dr2-create .deal-grid select:not([class*="w-"]){width:100%}
  #dr2-create .multi-select{width:100%;height:11rem;padding:.25rem}
  #dr2-create .multi-select option{padding:.2rem .5rem;border-radius:4px}
  #dr2-create .multi-select option:checked{background:color-mix(in srgb,var(--brand-button,#0ea5e9) 18%,transparent);color:var(--text-primary)}
  #dr2-create input[type=checkbox],#dr2-create input[type=radio],#dr2-create input[type=range]{accent-color:var(--brand-button,#0ea5e9)}
  #dr2-create label{color:var(--text-secondary)}

  /* "+ Add a new …" links + inline errors */
  #dr2-create .dr2-addlink{color:var(--brand-icon,#0ea5e9)}
  #dr2-create .dr2-err{color:var(--ds-red,#dc2626)}
</style>
