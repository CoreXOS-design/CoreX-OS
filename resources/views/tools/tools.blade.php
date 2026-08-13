@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
@php
  $activeTab = ($defaultTab ?? 'calc');
  if ($activeTab === 'commission') $activeTab = 'calc';
  if (request()->get('section') === 'history') $activeTab = 'history';
  elseif (request()->get('section') === 'cma' || $activeTab === 'cma') $activeTab = 'cma';
@endphp
<style>
/* ===== Tools — CoreX Design System =====
   Scoped to #hf-tool-root only. Theme-aware via CSS variables.
*/
#hf-tool-root, #hf-tool-root * { box-sizing: border-box; }

#hf-tool-root {
  color: var(--text-primary);
}

/* Full-width content — matches Contacts / Core Matches / Listings index pages.
   The corex layout's <main> already supplies horizontal padding (p-4 lg:p-6),
   so the wrap spans the full content area instead of a centred 980px column. */
#hf-tool-root .wrap {
  width: 100%;
  margin: 0;
  padding: 0;
}

/* Tab navigation — full-width menu that scrolls (never wraps/overflows) on
   narrow screens so the tab row always renders correctly. */
#hf-tool-root .tab-nav {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--border);
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
#hf-tool-root .tab-nav::-webkit-scrollbar { display: none; }

#hf-tool-root .tab-btn {
  padding: 0.625rem 1.25rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--text-secondary);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  cursor: pointer;
  transition: all 300ms;
  white-space: nowrap;
}

#hf-tool-root .tab-btn:hover { color: var(--text-primary); }

#hf-tool-root .tab-btn.active {
  color: var(--text-primary);
  border-bottom-color: var(--brand-icon, #0ea5e9);
}

/* Sections show/hide */
#hf-tool-root .section { display: none !important; }
#hf-tool-root .section.active { display: block !important; }
#hf-tool-root #historySection.active { display: flex !important; flex-direction: column; gap: 1rem; }

/* Layout helpers */
#hf-tool-root .inlineRow { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
#hf-tool-root .inlineRow + .inlineRow { margin-top: 1rem; }
#hf-tool-root .field { flex: 1; min-width: 220px; }
#hf-tool-root .field.small { flex: 0 0 220px; }
#hf-tool-root .field.tiny { flex: 0 0 120px; }

#hf-tool-root .divider {
  height: 1px;
  background: var(--border);
  margin: 1.25rem 0;
}

/* Labels */
#hf-tool-root label {
  display: block;
  color: var(--text-secondary);
  font-size: 0.6875rem;
  font-weight: 500;
  margin-bottom: 4px;
}

/* Inputs */
#hf-tool-root input[type="number"],
#hf-tool-root input[type="text"],
#hf-tool-root input[type="date"],
#hf-tool-root select,
#hf-tool-root textarea {
  width: 100%;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-primary);
  padding: 0.625rem 0.75rem;
  border-radius: 6px;
  font-size: 0.875rem;
  outline: none;
  transition: border-color 300ms, box-shadow 300ms;
}

#hf-tool-root textarea { min-height: 90px; resize: vertical; }

#hf-tool-root input:focus,
#hf-tool-root select:focus,
#hf-tool-root textarea:focus {
  border-color: var(--brand-button, #0ea5e9);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);
}

/* Pill tags */
#hf-tool-root .pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface-2, var(--surface));
  color: var(--text-primary);
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
}

/* Buttons — primary uses --brand-button */
#hf-tool-root .btn {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 1rem;
  border: none;
  border-radius: 6px;
  background: var(--brand-button, #0ea5e9);
  color: #fff;
  font-size: 0.8125rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 300ms;
  box-shadow: 0 4px 6px -1px color-mix(in srgb, var(--brand-button, #0ea5e9) 20%, transparent);
}

#hf-tool-root .btn:hover {
  filter: brightness(1.1);
  box-shadow: 0 6px 10px -2px color-mix(in srgb, var(--brand-button, #0ea5e9) 30%, transparent);
}

/* Secondary */
#hf-tool-root .btn.secondary {
  background: var(--surface);
  color: var(--text-primary);
  border: 1px solid var(--border);
  box-shadow: none;
}

#hf-tool-root .btn.secondary:hover {
  background: var(--surface-2, var(--surface));
  border-color: var(--text-muted);
  filter: none;
}

#hf-tool-root .btn.danger {
  background: transparent;
  color: var(--ds-crimson, #c41e3a);
  border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 40%, transparent);
  font-size: 0.6875rem;
  padding: 0.25rem 0.625rem;
  border-radius: 6px;
  box-shadow: none;
}

#hf-tool-root .btn.danger:hover {
  background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
  filter: none;
}

/* Results grid */
#hf-tool-root .results {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 1rem;
  margin-top: 1rem;
}

#hf-tool-root .result {
  grid-column: span 4;
  background: var(--surface);
  border: 1px solid var(--border);
  border-left: 4px solid var(--brand-icon, #0ea5e9);
  border-radius: 6px;
  padding: 1rem;
  transition: all 300ms;
}

#hf-tool-root .result:hover {
  border-color: color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, var(--border));
}

@media (max-width: 950px) {
  #hf-tool-root .result { grid-column: span 12; }
}

/* KPI labels/values */
#hf-tool-root .k {
  color: var(--text-secondary);
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  margin-bottom: 0.5rem;
  font-weight: 600;
}

#hf-tool-root .v {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.2;
}

#hf-tool-root .mono {
  font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.6875rem;
  color: var(--text-muted);
  margin-top: 0.375rem;
}

/* History table */
#hf-tool-root .history-table {
  width: 100%;
  border-collapse: collapse;
}

#hf-tool-root .history-table thead th {
  background: var(--surface-2, var(--surface));
  text-transform: uppercase;
  font-size: 0.6875rem;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  font-weight: 600;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
  text-align: left;
  white-space: nowrap;
}

#hf-tool-root .history-table tbody tr {
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  transition: all 300ms;
}

#hf-tool-root .history-table tbody tr:last-child {
  border-bottom: none;
}

#hf-tool-root .history-table td {
  padding: 0.75rem 1rem;
  font-size: 0.8125rem;
  color: var(--text-primary);
}

/* Reference number must never wrap — it is a single token identifier. */
#hf-tool-root .history-table td.mono {
  white-space: nowrap;
}

#hf-tool-root .history-table td.actions-cell {
  text-align: right;
  white-space: nowrap;
}

#hf-tool-root .history-table tbody tr:hover {
  background: var(--surface-2, var(--surface));
}

/* Empty state */
#hf-tool-root .history-empty {
  text-align: center;
  padding: 2.5rem 1rem;
  color: var(--text-muted);
  font-size: 0.8125rem;
}

/* Agent tag */
#hf-tool-root .agent-tag {
  display: inline-flex;
  align-items: center;
  padding: 0.125rem 0.5rem;
  border-radius: 6px;
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.025em;
  white-space: nowrap;
}

/* Sub text */
#hf-tool-root .sub {
  color: var(--text-secondary);
  font-size: 0.8125rem;
}

/* CMA preview */
#hf-tool-root .cma-preview {
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 2rem;
  background: var(--surface);
  color: var(--text-primary);
  max-width: 820px;
  margin: 1.25rem auto 0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Section cards */
#hf-tool-root .tool-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 1.25rem;
}

#hf-tool-root .tool-card + .tool-card {
  margin-top: 1rem;
}

#hf-tool-root .tool-card-header {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 1.25rem;
}

/* Pill inline layout fix */
#hf-tool-root .pill-group {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: center;
}
</style>

<div id="hf-tool-root">
<div class="wrap flex flex-col gap-6">

  {{-- Page Header --}}
  <div style="background: var(--brand-default, #0b2a4a);" class="rounded-md px-6 py-5" data-tour="tools-header">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold text-white leading-tight">Tools</h1>
        <p class="text-sm text-white/60">Commission Calculator &middot; CMA Certificate &middot; History</p>
      </div>
      <div class="flex items-center gap-3">
        @include('layouts.partials.tour-header-launcher')
        <div id="activeAgentDisplay" class="text-sm text-white/80 font-medium">
          <span id="currentAgentName">{{ auth()->user()?->name ?? "User" }}</span>
        </div>
        <button type="button" class="corex-btn-outline" id="btnReset" style="background:transparent; color:#ffffff; border-color:rgba(255,255,255,0.3);">Clear Form</button>
      </div>
    </div>
  </div>

  {{-- Tab navigation --}}
  <div class="tab-nav" id="toolTabs">
    <button class="tab-btn {{ $activeTab === 'calc' ? 'active' : '' }}"
            data-tour="tools-commission-tab"
            onclick="activateSection('calcSection')">
      Commission Calculator
    </button>
    <button class="tab-btn {{ $activeTab === 'cma' ? 'active' : '' }}"
            data-tour="tools-cma-tab"
            onclick="activateSection('certSection')">
      CMA Certificate
    </button>
    <button class="tab-btn {{ $activeTab === 'history' ? 'active' : '' }}"
            onclick="activateSection('historySection')">
      History &amp; Logs
    </button>
  </div>

  <!-- Calculator Section -->
  <div id="calcSection" class="section {{ $activeTab === 'calc' ? 'active' : '' }}" data-tour="tools-commission-section">
    <div class="tool-card">
      <h3 class="tool-card-header">Commission Calculator</h3>

      <div class="inlineRow">
        <div class="field" style="flex:2">
          <label>Property Address</label>
          <input id="propAddress" type="text" value="" placeholder="e.g. 12 Smith Street, Shelly Beach"/>
        </div>
        <div class="field small">
          <label>Property Type</label>
          <select id="propType">
            <option value="res">Residential (7.5%)</option>
            <option value="land">Vacant land (10%)</option>
            <option value="comm">Commercial (10%)</option>
          </select>
        </div>
      </div>

      <div class="inlineRow" data-tour="tools-commission-inputs">
        <div class="field">
          <label id="priceLabel">Advertised Price (R)</label>
          <input id="price" type="number" value="0" min="0" step="1000"/>
          <input id="ownerPocket" type="number" value="0" min="0" step="1000" style="display:none"/>
        </div>
        <div class="field tiny">
          <label>Commission %</label>
          <input id="commPct" type="number" value="7.5" min="0" step="0.1"/>
        </div>
        <div class="field tiny">
          <label>VAT %</label>
          <input id="vatRate" type="number" value="15" min="0" step="0.5"/>
        </div>
        <div class="field small">
          <label>VAT Mode</label>
          <div class="pill-group">
            <label class="pill" style="margin:0;"><input type="checkbox" id="vatIncl" style="margin-right:8px">VAT included in comm</label>
          </div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="inlineRow">
        <div class="field small">
          <label>Input Mode</label>
          <div class="pill-group">
            <label class="pill" style="margin:0;"><input type="radio" name="mode" id="modePrice" checked style="margin-right:8px">Price</label>
            <label class="pill" style="margin:0;"><input type="radio" name="mode" id="modePocket" style="margin-right:8px">Owner Pocket</label>
          </div>
        </div>
        <div class="field small">
          <label>Override Commission</label>
          <div class="pill-group">
            <label class="pill" style="margin:0;"><input type="checkbox" id="commOverrideOn" style="margin-right:8px">Enable override</label>
          </div>
        </div>
        <div class="field" id="commOverrideWrap" style="display:none; flex:2">
          <label>Override Amount</label>
          <div class="inlineRow">
            <div class="field">
              <input id="commOverrideAmt" type="number" value="60000" min="0" step="100"/>
            </div>
            <div class="field small">
              <select id="commOverrideMode">
                <option value="inc">VAT inclusive</option>
                <option value="ex">VAT exclusive</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="results" data-tour="tools-commission-results">
        <div class="result">
          <div class="k">Selling Price</div>
          <div class="v" id="rSellingPrice">&mdash;</div>
        </div>
        <div class="result">
          <div class="k">Owner Pocket</div>
          <div class="v" id="rOwnerPocket" style="color: var(--ds-green, #059669);">&mdash;</div>
        </div>
        <div class="result">
          <div class="k">Commission (VAT Incl)</div>
          <div class="v" id="rTotalInc">&mdash;</div>
        </div>

        <div class="result">
          <div class="k">Discount vs Default</div>
          <div class="v" id="rLostInc" style="color: var(--ds-amber, #f59e0b);">&mdash;</div>
          <div class="mono">Lost: <span id="rLostVsDefault">0%</span></div>
        </div>
        <div class="result" style="grid-column:span 8">
          <div class="k">Notes</div>
          <div class="mono" id="discNote">&mdash;</div>
        </div>
      </div>

      <div class="divider"></div>

      <div class="inlineRow">
        <div class="field small">
          <label>Certificate Date</label>
          <input id="certDate" type="date" />
        </div>
        <div class="field" style="display:flex; align-items:flex-end;">
          <button class="corex-btn-primary" id="btnPrint" data-tour="tools-commission-print">Print Commission Summary</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Evaluation Certificate Section -->
  <div id="certSection" class="section {{ $activeTab === 'cma' ? 'active' : '' }}" data-tour="tools-cma-section">
    @php
      $evalCertUser = auth()->user();
      $evalCertConfigured = $evalCertUser ? app(\App\Services\AgentSignatureService::class)->isConfigured($evalCertUser) : false;
      $evalCertIsCandidate = \Illuminate\Support\Str::lower(trim((string) ($evalCertUser?->designation ?? ''))) === 'candidate property practitioner';
      $evalCertCanAuthorise = $evalCertUser ? app(\App\Services\CandidatePractitionerService::class)->canAuthorise($evalCertUser) : false;
    @endphp
    <style>#hf-tool-root [x-cloak]{display:none!important;}</style>
    <div class="tool-card" x-data="evalCert()"
         x-init="init({ savedSigConfigured: {{ $evalCertConfigured ? 'true' : 'false' }}, isCandidate: {{ $evalCertIsCandidate ? 'true' : 'false' }}, canAuthorise: {{ $evalCertCanAuthorise ? 'true' : 'false' }} })">

      <h3 class="tool-card-header">Evaluation Certificate</h3>

      {{-- ── Candidate authorisation queue ──────────────────────────────────────
           CANDIDATE ONLY — their submitted evaluations + status (pending/authorised/rejected).
           Authorisers use the dedicated Pending Authorisations screen (list → read-only
           review → authorise), never this editable builder. --}}
      <div x-show="queue.length && queueRole === 'candidate'" x-cloak class="pill" style="display:block; margin-bottom:1rem; background:#f1f5f9;">
        <div style="font-weight:700; color:#0b2a4a; margin-bottom:.5rem;">My submitted evaluations</div>
        <template x-for="q in queue" :key="q.id">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.4rem 0; border-top:1px solid var(--border);">
            <div style="min-width:0;">
              <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" x-text="q.address"></div>
              <div style="font-size:.72rem; color:var(--text-secondary);">
                <span x-show="q.estimated_market_value" x-text="q.estimated_market_value ? ('R ' + Number(q.estimated_market_value).toLocaleString('en-ZA')) : ''"></span>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:.4rem; flex-shrink:0;">
              <span class="agent-tag" x-text="statusLabel(q.status)"
                    :style="'color:#fff;background:' + statusColour(q.status)"></span>
              <button class="corex-btn-outline" style="padding:.25rem .6rem;" @click="reviewCert(q)"
                      x-text="q.status === 'rejected' ? 'Fix &amp; resubmit' : 'Open'"></button>
            </div>
          </div>
        </template>
      </div>

      {{-- The builder fields — disabled (read-only) once the certificate is submitted or
           authorised; a draft or a returned (rejected) certificate stays editable. --}}
      <fieldset :disabled="formLocked" style="border:0; padding:0; margin:0; min-width:0;">

      {{-- Property link / search — selecting a result prefills the (still-editable) fields --}}
      <div class="inlineRow">
        <div class="field" style="flex:2; position:relative;">
          <label>Find a property (optional — or capture manually below)</label>
          <input type="text" x-model="propQuery" @input.debounce.300ms="searchProperties()" @focus="searchProperties()"
                 placeholder="Search your listings by address or ref…" autocomplete="off">
          <div x-show="propResults.length" @click.outside="propResults=[]" x-cloak
               style="position:absolute; z-index:30; left:0; right:0; top:100%; background:#fff; border:1px solid var(--border); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:260px; overflow:auto;">
            <template x-for="r in propResults" :key="r.id">
              <div @click="selectProperty(r)" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid var(--border);"
                   onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                <div style="font-weight:600;" x-text="r.label || r.address"></div>
                <div style="font-size:.75rem; color:var(--text-secondary);">
                  <span x-text="r.ref ? ('Ref ' + r.ref) : ''"></span>
                  <span x-show="r.price" x-text="r.price ? (' • R ' + Number(r.price).toLocaleString('en-ZA')) : ''"></span>
                </div>
              </div>
            </template>
          </div>
        </div>
        <div class="field small" style="display:flex; align-items:flex-end;">
          <span class="agent-tag" x-show="propertyId" x-cloak style="background:#0b2a4a;color:#fff;">
            Linked <a href="#" @click.prevent="unlinkProperty()" style="color:#fff; margin-left:6px; text-decoration:underline;">clear</a>
          </span>
        </div>
      </div>

      <div class="inlineRow">
        <div class="field" style="flex:2">
          <label>Property Address</label>
          <input type="text" x-model="form.address" placeholder="e.g. 12 Smith Street, Shelly Beach">
        </div>
        <div class="field small">
          <label>Property Type</label>
          <select x-model="form.property_type">
            <option value="">—</option>
            <option>House</option><option>Townhouse</option><option>Apartment</option>
            <option>Vacant Land</option><option>Commercial</option><option>Farm</option>
          </select>
        </div>
        <div class="field small">
          <label>Analysis Date</label>
          <input type="date" x-model="form.analysis_date">
        </div>
      </div>

      <div class="inlineRow" data-tour="tools-cma-value">
        <div class="field">
          <label>Estimated Market Value (R)</label>
          <input type="number" min="0" step="1000" x-model.number="form.estimated_market_value">
        </div>
        <div class="field"><label>Bedrooms</label><input type="number" min="0" x-model.number="form.bedrooms"></div>
        <div class="field"><label>Bathrooms</label><input type="number" min="0" x-model.number="form.bathrooms"></div>
        <div class="field"><label>Parking</label><input type="number" min="0" x-model.number="form.parking"></div>
      </div>

      <div class="inlineRow" data-tour="tools-cma-notes">
        <div class="field">
          <label>Key Features / Notes</label>
          <textarea x-model="form.key_features" placeholder="e.g. Sea views, renovated kitchen, walking distance to beach…"></textarea>
        </div>
      </div>

      {{-- Contact link — reuses the same match-or-create search the property page + DR2 use --}}
      <div class="inlineRow">
        <div class="field" style="flex:2; position:relative;">
          <label>Client / contact (optional)</label>
          <template x-if="contact">
            <div class="pill" style="display:flex; align-items:center; justify-content:space-between;">
              <span><b x-text="contact.name"></b> <span style="color:var(--text-secondary)" x-text="contact.phone ? ('• ' + contact.phone) : ''"></span></span>
              <a href="#" @click.prevent="clearContact()" style="text-decoration:underline;">change</a>
            </div>
          </template>
          <template x-if="!contact">
            <div>
              <input type="text" x-model="contactQuery" @input.debounce.300ms="searchContacts()"
                     placeholder="Search contacts by name, phone or email…" autocomplete="off">
              <div x-show="contactResults.length || contactQuery.trim().length>1" @click.outside="contactResults=[]" x-cloak
                   style="position:absolute; z-index:30; left:0; right:0; top:100%; background:#fff; border:1px solid var(--border); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:220px; overflow:auto;">
                <template x-for="r in contactResults" :key="r.id">
                  <div @click="selectContact(r)" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid var(--border);"
                       onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">
                    <div style="font-weight:600;" x-text="r.label || r.name"></div>
                    <div style="font-size:.75rem; color:var(--text-secondary);" x-text="r.identifier || r.phone || r.email || ''"></div>
                  </div>
                </template>
                <div @click="addInlineContact()" x-show="contactQuery.trim().length>1"
                     style="padding:8px 12px; cursor:pointer; color:#0b2a4a; font-weight:600;">
                  + Add “<span x-text="contactQuery"></span>” as a new contact
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      </fieldset>

      <div class="divider"></div>

      {{-- Actions — state-aware: Save (while editable) · Download/Print (any saved cert) ·
           candidate Sign+submit · full-status Sign+finalise · authoriser Authorise/Reject ·
           Share (once authorised). --}}
      <div class="inlineRow" style="align-items:center; gap:.75rem; flex-wrap:wrap;" data-tour="tools-cma-print">
        <template x-if="certId">
          <span class="agent-tag" x-text="statusLabel(status)" :style="'color:#fff;background:' + statusColour(status)"></span>
        </template>

        <button class="corex-btn-primary" x-show="!formLocked" @click="save()" :disabled="saving || !form.address">
          <span x-show="!saving" x-text="certId ? 'Save changes' : 'Save evaluation'"></span>
          <span x-show="saving" x-cloak>Saving…</span>
        </button>

        <button class="corex-btn-outline" x-show="certId" x-cloak @click="download()">Download</button>
        <button class="corex-btn-outline" x-show="certId" x-cloak @click="printCert()">Print</button>

        {{-- Candidate: sign their part + submit for authorisation (draft or after a rejection) --}}
        <template x-if="certId && isCandidate && (status === 'draft' || status === 'rejected')">
          <button class="corex-btn-primary" style="background:#0b7d3b;" @click="openSign()"
                  x-text="status === 'rejected' ? 'Sign &amp; resubmit' : 'Sign &amp; submit for authorisation'"></button>
        </template>

        {{-- Full-status: finalise their own certificate directly --}}
        <template x-if="certId && !isCandidate && status === 'draft'">
          <button class="corex-btn-primary" style="background:#0b7d3b;" @click="openSign()">Sign &amp; finalise</button>
        </template>

        {{-- Authorising a candidate's cert happens on the dedicated Pending Authorisations
             screen (read-only review), never in this editable builder. --}}

        <button class="corex-btn-outline" x-show="status === 'authorised'" x-cloak @click="share()">Share</button>

        <span x-show="dirty && certId && !formLocked" x-cloak style="font-size:.75rem; color:#b45309;">Unsaved changes — Save first.</span>
        <span x-show="flash" x-cloak x-text="flash" style="font-size:.8rem; color:#0b7d3b;"></span>
      </div>

      {{-- Why it was returned (candidate sees the authoriser's note) --}}
      <div x-show="certId && status === 'rejected' && rejectNote" x-cloak class="pill"
           style="background:#fef2f2; color:#991b1b; margin-top:.6rem;">
        <b>Returned for changes:</b> <span x-text="rejectNote"></span>
      </div>

      {{-- Sign — PIN once. signMode: 'submit' (candidate→queue), 'finalise' (full-status direct),
           'authorise' (full-status accepts a candidate's cert). Reuses the saved-sig PIN machinery. --}}
      <div x-show="signOpen" x-cloak @keydown.escape.window="signOpen=false"
           style="position:fixed; inset:0; z-index:60; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center;">
        <div class="tool-card" style="max-width:440px; width:90%; background:#fff;" @click.stop>
          <h3 class="tool-card-header" x-text="signMode === 'submit' ? 'Sign &amp; submit for authorisation' : (signMode === 'authorise' ? 'Authorise &amp; sign' : 'Sign this evaluation certificate')"></h3>
          <template x-if="!savedSigConfigured">
            <p style="font-size:.85rem;">Set up your saved signature and signing PIN in
              <a href="/my-portal#signature" style="text-decoration:underline;">My Portal</a> first, then reopen this to sign.</p>
          </template>
          <template x-if="savedSigConfigured">
            <div>
              <p style="font-size:.85rem;" x-show="signMode === 'submit'">Enter your signing PIN to sign your evaluation and submit it to a full-status practitioner for authorisation.</p>
              <p style="font-size:.85rem;" x-show="signMode === 'authorise'">Enter your signing PIN to authorise and sign this candidate's evaluation. This produces the final, filed certificate.</p>
              <p style="font-size:.85rem;" x-show="signMode === 'finalise'">Enter your signing PIN to place your saved signature. This produces the final, filed certificate.</p>
              <div class="field"><label>Signing PIN</label>
                <input type="password" x-model="pin" @keydown.enter="submitSign()" autocomplete="off" inputmode="numeric">
              </div>
              <div x-show="pinError" x-cloak x-text="pinError" style="color:#b91c1c; font-size:.8rem; margin-bottom:.5rem;"></div>
            </div>
          </template>
          <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.75rem;">
            <button class="corex-btn-outline" @click="signOpen=false">Cancel</button>
            <button class="corex-btn-primary" x-show="savedSigConfigured" @click="submitSign()" :disabled="pinLoading || !pin">
              <span x-show="!pinLoading" x-text="signMode === 'submit' ? 'Sign &amp; submit' : (signMode === 'authorise' ? 'Authorise &amp; sign' : 'Sign &amp; finalise')"></span>
              <span x-show="pinLoading" x-cloak>Working…</span>
            </button>
          </div>
        </div>
      </div>

      {{-- Reject — authoriser returns a pending certificate to the candidate with a note. --}}
      <div x-show="rejectOpen" x-cloak @keydown.escape.window="rejectOpen=false"
           style="position:fixed; inset:0; z-index:60; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center;">
        <div class="tool-card" style="max-width:440px; width:90%; background:#fff;" @click.stop>
          <h3 class="tool-card-header">Return to the candidate</h3>
          <p style="font-size:.85rem;">Tell the candidate what to fix. They will see this note and can amend and resubmit.</p>
          <div class="field"><label>Note to candidate</label>
            <textarea x-model="rejectInput" placeholder="e.g. Estimated value looks high — please re-check the comparables."></textarea>
          </div>
          <div style="display:flex; justify-content:flex-end; gap:.5rem; margin-top:.75rem;">
            <button class="corex-btn-outline" @click="rejectOpen=false">Cancel</button>
            <button class="corex-btn-primary" style="background:#b91c1c;" @click="submitReject()" :disabled="rejectLoading || !rejectInput.trim()">
              <span x-show="!rejectLoading">Return to candidate</span><span x-show="rejectLoading" x-cloak>Returning…</span>
            </button>
          </div>
        </div>
      </div>

      {{-- Share — WhatsApp did-you-send confirm (same shared modal Core Matches / the contact page use). --}}
      @include('partials.whatsapp-send-confirm-modal')

    </div>
  </div>

  <script>
  function evalCert() {
    const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
    const U = {
      searchProps:    @json(route('tools.cma.evaluation.search-properties')),
      searchContacts: @json(route('tools.cma.evaluation.search-contacts')),
      contactInline:  @json(route('tools.cma.evaluation.contact-inline')),
      store:          @json(route('tools.cma.evaluation.store')),
      propContactTpl: @json(route('tools.cma.evaluation.property-contact', ['property' => '__ID__'])),
      updateTpl:      @json(route('tools.cma.evaluation.update',   ['certificate' => '__ID__'])),
      downloadTpl:    @json(route('tools.cma.evaluation.download', ['certificate' => '__ID__'])),
      signTpl:        @json(route('tools.cma.evaluation.sign',     ['certificate' => '__ID__'])),
      submitTpl:      @json(route('tools.cma.evaluation.submit',    ['certificate' => '__ID__'])),
      authoriseTpl:   @json(route('tools.cma.evaluation.authorise', ['certificate' => '__ID__'])),
      rejectTpl:      @json(route('tools.cma.evaluation.reject',    ['certificate' => '__ID__'])),
      queue:          @json(route('tools.cma.evaluation.queue')),
      shareMetaTpl:   @json(route('tools.cma.evaluation.share-meta', ['certificate' => '__ID__'])),
    };
    const withId = (tpl, id) => tpl.replace('__ID__', id);
    const jhead = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    return {
      savedSigConfigured: false, isCandidate: false, canAuthorise: false,
      form: { address: '', property_type: '', analysis_date: '', estimated_market_value: null, bedrooms: null, bathrooms: null, parking: null, key_features: '' },
      propertyId: null, contact: null, contactId: null,
      propQuery: '', propResults: [], contactQuery: '', contactResults: [],
      certId: null, status: 'draft', isSigned: false, signedBy: null, rejectNote: '',
      saving: false, dirty: false, flash: '',
      signOpen: false, signMode: 'finalise', pin: '', pinError: '', pinLoading: false,
      rejectOpen: false, rejectInput: '', rejectLoading: false,
      queue: [], queueRole: '',
      sentConfirm: { open: false, communicationId: null }, markSentBase: '',

      // The builder is read-only once the certificate is submitted/authorised — only a
      // draft or a rejected (own) certificate can be edited.
      get formLocked() {
        return !!this.certId && (this.status === 'pending_authorisation' || this.status === 'authorised');
      },

      init(cfg) {
        this.savedSigConfigured = !!cfg.savedSigConfigured;
        this.isCandidate = !!cfg.isCandidate;
        this.canAuthorise = !!cfg.canAuthorise;
        this.$watch('form', () => { if (this.certId) this.dirty = true; }, { deep: true });
        this.loadQueue();
      },

      statusLabel(s) { return ({ draft: 'Draft', pending_authorisation: 'Pending authorisation', authorised: 'Authorised', rejected: 'Rejected' })[s] || s; },
      statusColour(s) { return ({ draft: '#64748b', pending_authorisation: '#b45309', authorised: '#0b7d3b', rejected: '#b91c1c' })[s] || '#64748b'; },

      async loadQueue() {
        try {
          const r = await fetch(U.queue, { headers: jhead });
          if (!r.ok) return;
          const j = await r.json();
          this.queueRole = j.role; this.queue = j.items || [];
        } catch (e) {}
      },

      // Load a queue row into the builder — candidate opens their own cert (edit if
      // rejected, else view), authoriser opens a pending cert to review.
      reviewCert(q) {
        this.certId = q.id; this.status = q.status; this.isSigned = !!q.is_signed; this.rejectNote = q.reject_note || '';
        this.form = {
          address: q.address || '', property_type: q.property_type || '', analysis_date: q.analysis_date || '',
          estimated_market_value: q.estimated_market_value, bedrooms: q.bedrooms, bathrooms: q.bathrooms,
          parking: q.parking, key_features: q.key_features || '',
        };
        this.propertyId = q.property_id || null;
        this.contact = q.contact ? { id: q.contact.id, name: q.contact.name, phone: q.contact.phone } : null;
        this.contactId = q.contact ? q.contact.id : null;
        this.dirty = false; this.flash = '';
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) {}
      },

      async searchProperties() {
        const q = this.propQuery.trim();
        if (q.length < 2) { this.propResults = []; return; }
        const r = await fetch(U.searchProps + '?q=' + encodeURIComponent(q), { headers: jhead });
        this.propResults = r.ok ? await r.json() : [];
      },
      async selectProperty(r) {
        this.propertyId = r.id; this.propResults = []; this.propQuery = '';
        this.form.address = r.address || r.label || '';
        this.form.property_type = r.property_type || '';
        this.form.estimated_market_value = r.price != null ? Number(r.price) : null;
        this.form.bedrooms = r.beds  != null ? Number(r.beds)  : null;
        this.form.bathrooms = r.baths != null ? Number(r.baths) : null;
        this.form.parking = r.garages != null ? Number(r.garages) : null;
        try {
          const cr = await fetch(withId(U.propContactTpl, r.id), { headers: jhead });
          if (cr.ok) { const j = await cr.json(); if (j.contact) { this.contact = { id: j.contact.id, name: j.contact.name, phone: j.contact.phone, email: j.contact.email }; this.contactId = j.contact.id; } }
        } catch (e) {}
      },
      unlinkProperty() { this.propertyId = null; },
      clearContact() { this.contact = null; this.contactId = null; this.contactQuery = ''; this.contactResults = []; },

      async searchContacts() {
        const q = this.contactQuery.trim();
        if (q.length < 2) { this.contactResults = []; return; }
        const r = await fetch(U.searchContacts + '?q=' + encodeURIComponent(q), { headers: jhead });
        this.contactResults = r.ok ? await r.json() : [];
      },
      selectContact(r) { this.contact = { id: r.id, name: r.label || r.name, phone: r.phone, email: r.email }; this.contactId = r.id; this.contactResults = []; this.contactQuery = ''; },
      async addInlineContact() {
        const parts = this.contactQuery.trim().split(/\s+/);
        const first = parts.shift() || ''; const last = parts.join(' ');
        const r = await fetch(U.contactInline, { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ first_name: first, last_name: last }) });
        if (r.status === 201) { const j = await r.json(); this.contact = { id: j.id, name: j.name }; this.contactId = j.id; }
        else if (r.status === 409) { const j = await r.json(); const d = j.duplicate_detected?.duplicates?.[0]; if (d) { this.contact = { id: d.id, name: d.name, phone: d.phone }; this.contactId = d.id; } }
        else { alert('Could not add contact.'); return; }
        this.contactResults = []; this.contactQuery = '';
      },

      payload() {
        return {
          address: this.form.address, property_type: this.form.property_type || null,
          analysis_date: this.form.analysis_date || null,
          estimated_market_value: this.form.estimated_market_value === '' ? null : this.form.estimated_market_value,
          bedrooms: this.form.bedrooms, bathrooms: this.form.bathrooms, parking: this.form.parking,
          key_features: this.form.key_features || null,
          property_id: this.propertyId, contact_id: this.contactId,
        };
      },
      async save() {
        if (!this.form.address) return;
        this.saving = true; this.flash = '';
        try {
          const url = this.certId ? withId(U.updateTpl, this.certId) : U.store;
          const method = this.certId ? 'PUT' : 'POST';
          const r = await fetch(url, { method, headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(this.payload()) });
          if (!r.ok) { const j = await r.json().catch(() => ({})); alert(j.message || 'Could not save.'); return; }
          const j = await r.json();
          this.certId = j.id; this.status = j.status; this.isSigned = j.is_signed; this.signedBy = j.signed_by; this.dirty = false;
          this.flash = 'Saved.'; setTimeout(() => { this.flash = ''; }, 2500);
        } finally { this.saving = false; }
      },
      download() { if (this.certId) window.open(withId(U.downloadTpl, this.certId), '_blank'); },
      printCert() { if (this.certId) window.open(withId(U.downloadTpl, this.certId) + '?inline=1', '_blank'); },

      // Share to the linked client via WhatsApp — opens WhatsApp FIRST (click gesture),
      // then records a provisional Communication and asks did-you-send (AT-323 model,
      // identical to Core Matches). The link is a time-limited signed public URL.
      async share() {
        // Share/Download/Print are READ actions on a submitted/authorised cert — never
        // gated on unsaved edits (the form is read-only once submitted).
        if (!this.certId) return;
        if (!this.contactId) { alert('Link a contact before sharing.'); return; }
        const r = await fetch(withId(U.shareMetaTpl, this.certId), { headers: jhead });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { alert(j.message || 'Could not prepare the share.'); return; }
        if (!j.wa_phone) { alert('This contact has no WhatsApp number.'); return; }
        window.open('https://wa.me/' + j.wa_phone + '?text=' + encodeURIComponent(j.message), '_blank', 'noopener');
        try {
          const ir = await fetch(j.increment_url, { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ channel: 'whatsapp', body: j.message }) });
          const idata = await ir.json().catch(() => ({}));
          if (idata && idata.communication_id) {
            this.markSentBase = j.mark_sent_base;
            this.sentConfirm = { open: true, communicationId: idata.communication_id };
          }
        } catch (e) {}
      },
      async confirmSent(didSend) {
        const commId = this.sentConfirm.communicationId;
        this.sentConfirm.open = false;
        if (!commId || !didSend) return; // No answer: the WhatsApp row stays not_delivered (uncounted).
        try {
          await fetch(this.markSentBase + '/' + commId + '/mark-sent', { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: '{}' });
        } catch (e) {}
      },

      // Decide the PIN action from role + state: candidate→submit, authoriser on a
      // pending cert→authorise, otherwise full-status direct→finalise.
      openSign() {
        if (this.dirty) { alert('Save your changes first.'); return; }
        this.pin = ''; this.pinError = '';
        this.signMode = this.isCandidate ? 'submit' : (this.status === 'pending_authorisation' ? 'authorise' : 'finalise');
        this.signOpen = true;
      },
      async submitSign() {
        if (!this.pin) return;
        this.pinLoading = true; this.pinError = '';
        const tpl = { submit: U.submitTpl, authorise: U.authoriseTpl, finalise: U.signTpl }[this.signMode];
        try {
          const r = await fetch(withId(tpl, this.certId), { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ pin: this.pin }) });
          const j = await r.json().catch(() => ({}));
          if (!r.ok) { this.pinError = j.message || 'Could not complete.'; return; }
          this.status = j.status; this.signOpen = false;
          if (this.signMode !== 'submit') { this.isSigned = true; this.signedBy = j.signed_by || this.signedBy; }
          this.flash = ({ submit: 'Submitted for authorisation.', authorise: 'Authorised & filed.', finalise: 'Signed & filed.' })[this.signMode];
          setTimeout(() => { this.flash = ''; }, 3000);
          this.loadQueue();
        } finally { this.pinLoading = false; }
      },

      openReject() { this.rejectInput = ''; this.rejectOpen = true; },
      async submitReject() {
        if (!this.rejectInput.trim()) return;
        this.rejectLoading = true;
        try {
          const r = await fetch(withId(U.rejectTpl, this.certId), { method: 'POST', headers: { ...jhead, 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ note: this.rejectInput }) });
          const j = await r.json().catch(() => ({}));
          if (!r.ok) { alert(j.message || 'Could not return the certificate.'); return; }
          this.status = j.status; this.rejectOpen = false;
          this.flash = 'Returned to the candidate.'; setTimeout(() => { this.flash = ''; }, 3000);
          this.loadQueue();
        } finally { this.rejectLoading = false; }
      },
    };
  }
  </script>

  <!-- History Section -->
  <div id="historySection" class="section {{ $activeTab === 'history' ? 'active' : '' }}">

    {{-- History table card --}}
    <div class="tool-card">
      <div style="margin-bottom:1rem;">
        <h3 class="tool-card-header" style="margin-bottom:0.25rem;">History &amp; Logs</h3>
        <div class="sub">Click a row to reload, or delete entries.</div>
      </div>

      <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
        <div class="overflow-x-auto" id="historyTableWrap">
          <table class="history-table">
            <thead>
              <tr>
                <th>Ref</th><th>Date</th><th>Type</th><th>Property</th><th>Agent</th><th style="text-align:right;">Value</th><th style="width:1%; text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody id="historyBody"></tbody>
          </table>
        </div>
        <div id="historyEmpty" class="history-empty" style="display:none;">
          <div class="rounded-full mx-auto mb-3 flex items-center justify-center" style="width:3rem; height:3rem; background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.5rem; height:1.5rem;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No history yet</h3>
          <p class="text-sm mb-4" style="color: var(--text-muted);">Use the Commission Calculator or CMA Certificate to log your first entry.</p>
          <div class="flex items-center justify-center gap-2 flex-wrap">
            <button type="button" class="corex-btn-primary" onclick="activateSection('calcSection')">Open Commission Calculator</button>
            <button type="button" class="corex-btn-outline" onclick="activateSection('certSection')">Open CMA Certificate</button>
          </div>
        </div>
      </div>
    </div>

    {{-- Logged-in User --}}
    <div class="tool-card">
      <h3 class="tool-card-header" style="margin-bottom:0.25rem;">Logged-in User</h3>
      <div class="sub">This tool uses the current logged-in account for printing &amp; history.</div>

      <div class="pill" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; width:100%;">
        <div>
          <div style="font-weight:700; color:var(--text-primary);" id="authUserName">{{ auth()->user()?->name ?? "User" }}</div>
          <div style="font-size:0.6875rem; color:var(--text-secondary); margin-top:0.125rem;" id="authUserEmail">{{ auth()->user()?->email ?? "" }}</div>
        </div>
        <div class="agent-tag" id="authUserRole" style="background:var(--brand-default, #0b2a4a); color:#fff;">{{ strtolower(trim((string)(auth()->user()?->effectiveRole() ?? (auth()->user()?->role ?? "")))) }}</div>
      </div>

      <div class="divider"></div>

      <div class="sub">Preview Logo:</div>
      <div class="pill" style="margin-top:0.5rem;">
        <span id="prevCompanyName" style="font-weight:700; color:var(--text-primary);">Home Finders Coastal</span>
        <img id="prevLogo" style="display:none; max-height:30px; margin-left:10px;" />
      </div>
    </div>
  </div>

</div>
</div>

<script>
  window.DEFAULT_TAB = @json($defaultTab);
  window.PRINT_SETTINGS = @json($printSettings ?? null);
</script>

  @php
    $AUTH_USER = [
      "id" => auth()->id(),
      "name" => auth()->user()?->name,
      "email" => auth()->user()?->email,
      "role" => strtolower(trim((string)(auth()->user()?->effectiveRole() ?? (auth()->user()?->role ?? "")))),
      "designation" => auth()->user()?->designation,
    ];
  @endphp
  <script>
    window.AUTH_USER = @json($AUTH_USER);
  </script>


<script>
/**
 * Home Finders Coastal Portal
 * Core logic for Calculator, CMA, History and Settings.
 */

const el = (id) => document.getElementById(id);
const fmtZAR = (n) => isFinite(n) ? n.toLocaleString("en-ZA", { style: "currency", currency: "ZAR" }) : "—";
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

// --- STATE ---

const DEFAULT_SETTINGS = {
  companyName: "Home Finders Coastal",
  address: "The Emporium Shop 5, Shelly Beach, Margate",
  tel: "(039) 315 0857",
  ffc: "2023116041",
  logoUrl: "",
};

let SETTINGS = (window.PRINT_SETTINGS || null) || DEFAULT_SETTINGS;

const CALC_DATA = {
  sellingPrice: 0,
  ownerPocket: 0,
  commEx: 0,
  commInc: 0,
  vat: 0,
  discountedAmt: 0,
  discountPct: 0,
  discountNote: ""
};


// ===== Server-backed History =====

let HISTORY_ITEMS = [];

function getCsrfToken() {
  const elMeta = document.querySelector('meta[name="csrf-token"]');
  return elMeta ? elMeta.getAttribute('content') : '';
}

async function apiFetch(url, opts = {}) {
  const headers = Object.assign({}, opts.headers || {});
  if (!headers['Accept']) headers['Accept'] = 'application/json';
  // For JSON requests
  if (opts.body && !headers['Content-Type']) headers['Content-Type'] = 'application/json';
  // Laravel CSRF for state-changing
  if (!headers['X-CSRF-TOKEN']) headers['X-CSRF-TOKEN'] = getCsrfToken();

  const res = await fetch(url, Object.assign({}, opts, { headers }));
  // Try to parse JSON; if not, throw generic error
  let json = null;
  try { json = await res.json(); } catch (e) {}

  if (!res.ok) {
    const msg = (json && (json.message || json.error)) ? (json.message || json.error) : (res.status + " " + res.statusText);
    throw new Error(msg);
  }
  return json;
}

function renderHistory() {
  const body = el("historyBody");
  if (!body) return;
  body.innerHTML = "";

  const emptyEl = document.getElementById("historyEmpty");
  const tableWrap = document.getElementById("historyTableWrap");
  if (!HISTORY_ITEMS || HISTORY_ITEMS.length === 0) {
    if (emptyEl) emptyEl.style.display = "block";
    if (tableWrap) tableWrap.style.display = "none";
    return;
  }
  if (emptyEl) emptyEl.style.display = "none";
  if (tableWrap) tableWrap.style.display = "block";

  (HISTORY_ITEMS || []).forEach(item => {
    const tr = document.createElement("tr");
    tr.onclick = () => window.loadHistoryItem(item.id);

    const d = item.occurred_at ? new Date(item.occurred_at) : null;
    const dateText = d ? d.toLocaleDateString("en-ZA") : "—";

    // Plain-English label for the type code (UI_DESIGN_SYSTEM.md §F.8 — no raw
    // codes as visible labels). Underlying item.type is left untouched.
    const typeLabel = ({ CALC: "Commission", CMA: "CMA" })[item.type] || (item.type || "");

    tr.innerHTML = `
      <td class="mono">${escapeHtml(item.ref || "")}</td>
      <td style="white-space:nowrap;">${escapeHtml(dateText)}</td>
      <td><span class="ds-badge ds-badge-info" title="${escapeHtml(item.type || "")}">${escapeHtml(typeLabel)}</span></td>
      <td>${escapeHtml(item.property || "")}</td>
      <td style="white-space:nowrap;">${escapeHtml(item.agent_name || "")}</td>
      <td style="font-weight:700; white-space:nowrap; text-align:right;">${fmtZAR(Number(item.value || 0))}</td>
      <td class="actions-cell">
        <button class="btn danger" onclick="event.stopPropagation(); window.deleteHistoryItem(${item.id})">Delete</button>
      </td>
    `;
    body.appendChild(tr);
  });
}

async function refreshHistory() {
  try {
    const json = await apiFetch("/tools/history", { method: "GET" });
    HISTORY_ITEMS = (json && json.items) ? json.items : [];
    renderHistory();
  } catch (e) {
    console.warn("Could not load history:", e);
    // keep UI usable
  }
}

window.loadHistoryItem = async (id) => {
  try {
    const json = await apiFetch(`/tools/history/${id}`, { method: "GET" });
    const item = json && json.item ? json.item : null;
    if (!item || !item.payload) return;

    const data = item.payload || {};

    if (item.type === "CALC") {
      el("propAddress").value = data.propAddress || "";
      el("propType").value = data.propType || "res";
      el("price").value = data.price ?? 0;
      el("ownerPocket").value = data.ownerPocket ?? 0;
      el("commPct").value = data.commPct ?? 7.5;
      el("vatRate").value = data.vatRate ?? 15;
      el("vatIncl").checked = !!data.vatIncl;
      el("commOverrideOn").checked = !!data.commOverrideOn;
      el("commOverrideAmt").value = data.commOverrideAmt ?? 60000;
      el("commOverrideMode").value = data.commOverrideMode ?? "inc";
      el("modePrice").checked = data.mode === "price";
      el("modePocket").checked = data.mode === "pocket";
      el("certDate").value = data.certDate || "";

      el("commOverrideWrap").style.display = el("commOverrideOn").checked ? "block" : "none";
      if (el("modePocket").checked) {
        el("price").style.display = "none";
        el("ownerPocket").style.display = "block";
        el("priceLabel").textContent = "Net Pocket Target (R)";
      } else {
        el("price").style.display = "block";
        el("ownerPocket").style.display = "none";
        el("priceLabel").textContent = "Advertised Price (R)";
      }

      activateSection("calcSection");
      calcAll();
    } else {
      // Legacy client-side "CMA" history rows: the CMA generator is now the
      // persisted Evaluation Certificate (server-side). Old rows just open the tab.
      activateSection("certSection");
    }
  } catch (e) {
    alert("Could not load history entry.");
  }
};

window.deleteHistoryItem = async (id) => {
  if (!confirm("Delete this history entry?")) return;
  try {
    await apiFetch(`/tools/history/${id}`, { method: "DELETE" });
    await refreshHistory();
  } catch (e) {
    alert("Could not delete history entry.");
  }
};

async function saveHistoryEntry(type, property, value, payload) {
  // Must NOT block printing if it fails.
  try {
    await apiFetch("/tools/history", {
      method: "POST",
      body: JSON.stringify({
        type,
        property: property || "—",
        value: Number(value || 0),
        payload: payload || {},
        occurred_at: new Date().toISOString(),
      }),
    });
    // Update list in background-ish (best effort)
    refreshHistory();
    return true;
  } catch (e) {
    console.warn("Could not save history:", e);
    alert("Could not save history");
    return false;
  }
}


// --- FUNCTIONS ---


function updateUIFromSettings() {
      const user = (window.AUTH_USER || {});
  if (user && user.name) {
    if (el("currentAgentName")) el("currentAgentName").textContent = user.name || "User";
    if (el("authUserName")) el("authUserName").textContent = user.name || "User";
    if (el("authUserEmail")) el("authUserEmail").textContent = user.email || "";
    if (el("authUserRole")) el("authUserRole").textContent = (user.role || "").replace(/_/g," ");
    if (el("userSigName")) el("userSigName").textContent = user.name || "User";
  }
    renderHistory();
}


function calcAll() {
  const vatRate = Math.max(0, Number(el("vatRate").value)) / 100;
  const isPocketMode = el("modePocket").checked;
  const isOverride = el("commOverrideOn").checked;
  const isVatIncl = el("vatIncl").checked;

  let sellingPrice = 0;
  let ownerPocket = 0;
  let commInc = 0;
  let commEx = 0;

  if (isOverride) {
    const amt = Math.max(0, Number(el("commOverrideAmt").value));
    const mode = el("commOverrideMode").value;
    if (mode === "inc") {
      commInc = amt;
      commEx = vatRate > 0 ? (commInc / (1 + vatRate)) : commInc;
    } else {
      commEx = amt;
      commInc = commEx * (1 + vatRate);
    }
    sellingPrice = isPocketMode ? (Number(el("ownerPocket").value) + commInc) : Number(el("price").value);
    ownerPocket = sellingPrice - commInc;
  } else {
    const commPct = Math.max(0, Number(el("commPct").value)) / 100;
    if (isPocketMode) {
      ownerPocket = Number(el("ownerPocket").value);
      const denom = isVatIncl ? (1 - commPct) : (1 - (commPct * (1 + vatRate)));
      sellingPrice = denom > 0 ? (ownerPocket / denom) : 0;
    } else {
      sellingPrice = Number(el("price").value);
    }
    const commBase = sellingPrice * commPct;
    if (isVatIncl) {
      commInc = commBase;
      commEx = commInc / (1 + vatRate);
    } else {
      commEx = commBase;
      commInc = commEx * (1 + vatRate);
    }
    ownerPocket = sellingPrice - commInc;
  }

  CALC_DATA.sellingPrice = sellingPrice;
  CALC_DATA.ownerPocket = ownerPocket;
  CALC_DATA.commInc = commInc;
  CALC_DATA.commEx = commEx;
  CALC_DATA.vat = commInc - commEx;

  el("rSellingPrice").textContent = fmtZAR(sellingPrice);
  el("rOwnerPocket").textContent = fmtZAR(ownerPocket);
  el("rTotalInc").textContent = fmtZAR(commInc);

  const propType = el("propType").value;
  const defRate = propType === "res" ? 0.075 : 0.10;
  const defCommEx = sellingPrice * defRate;
  const defCommInc = defCommEx * (1 + vatRate);
  const lostInc = Math.max(0, defCommInc - commInc);

  CALC_DATA.discountedAmt = lostInc;
  CALC_DATA.discountPct = defCommInc > 0 ? (lostInc / defCommInc) * 100 : 0;
  CALC_DATA.discountNote = `Target: ${(defRate*100).toFixed(1)}% vs Effective: ${sellingPrice > 0 ? ((commEx/sellingPrice)*100).toFixed(2) : "0"}% VAT-excl.`;

  el("rLostInc").textContent = fmtZAR(CALC_DATA.discountedAmt);
  el("rLostVsDefault").textContent = CALC_DATA.discountPct.toFixed(1) + "%";
  el("discNote").textContent = CALC_DATA.discountNote;
}

function handlePrint(html) {
  const w = window.open("", "_blank", "width=900,height=650");
  if (!w) return alert("Pop-up blocked. Please allow pop-ups for printing.");

  w.document.open();
  w.document.write(html);
  w.document.close();

  const doPrint = () => {
    try {
      w.focus();
      w.print();
    } catch (e) {
      // If printing is blocked, do not auto-close; user can print manually.
      console.warn("Print blocked or failed:", e);
    }
  };

  // Print after the new window finishes loading (more reliable on slower laptops)
  w.onload = () => {
    setTimeout(doPrint, 300);
  };

  // Only close after print completes (avoid the "flash then disappear" problem)
  w.onafterprint = () => {
    setTimeout(() => {
      try { w.close(); } catch (e) {}
    }, 200);
  };
}



function generateCalculatorPrintHtml() {    const user = (window.AUTH_USER || {});
  const isCandidatePP = String(user.designation || '').trim().toLowerCase() === 'candidate property practitioner';
  const certDate = el("certDate").value ? new Date(el("certDate").value).toLocaleDateString("en-ZA") : new Date().toLocaleDateString("en-ZA");
  const property = el("propAddress").value || "—";
  const commPct = Number(el("commPct").value || 0).toFixed(2);
  const vatRate = Number(el("vatRate").value || 0).toFixed(2);
  return `
    <html>
    <head>
      <title>Commission Summary</title>
      <style>
        body{ font-family: Arial, sans-serif; padding: 30px; color:#111; }
        .top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;}
        .company{font-size:18px;font-weight:900;}
        .muted{color:#444;font-size:12px;line-height:1.4;}
        .title{font-size:22px;font-weight:900;margin:20px 0 8px 0;}
        .box{border:1px solid #ddd;border-radius:10px;padding:14px;margin:10px 0;}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
        .k{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.5px;}
        .v{font-size:16px;font-weight:800;}
        .sig{margin-top:28px;display:flex;justify-content:flex-end;}
        .sigbox{width:260px; text-align:center;}
          .sigline{border-top:1px solid #000; margin-top:0;}
          .sigtext{padding-top:4px;}
        .sigimg{max-height:80px; display:block; margin:0 auto 6px auto;}
        .ppra-footer{margin-top:18px;text-align:center;font-size:11px;color:#555;}
        @media print{
          body{background:#fff !important;}
          a[href]:after{content:"" !important;}
        }
      </style>
    </head>
    <body>
      <div class="top">
        <div>
          <div class="company">${escapeHtml(SETTINGS.companyName)}</div>
          <div class="muted">${escapeHtml(SETTINGS.address)}<br>${escapeHtml(SETTINGS.tel)}<br>FFC: ${escapeHtml(SETTINGS.ffc)}</div>
        </div>
        <div>${SETTINGS.logoUrl ? `<img src="${SETTINGS.logoUrl}" style="max-height:70px;">` : ""}</div>
      </div>

      <div class="title">Commission Summary</div>
      <div class="muted">Date: ${certDate}</div>

      <div class="box">
        <div class="k">Property</div>
        <div class="v">${escapeHtml(property)}</div>
      </div>

      <div class="box grid">
        <div>
          <div class="k">Selling Price</div>
          <div class="v">${fmtZAR(CALC_DATA.sellingPrice)}</div>
        </div>
        <div>
          <div class="k">Owner Pocket</div>
          <div class="v">${fmtZAR(CALC_DATA.ownerPocket)}</div>
        </div>
        <div>
          <div class="k">Commission (VAT incl)</div>
          <div class="v">${fmtZAR(CALC_DATA.commInc)}</div>
        </div>
        <div>
          <div class="k">Commission (VAT excl)</div>
          <div class="v">${fmtZAR(CALC_DATA.commEx)}</div>
        </div>
        <div>
          <div class="k">VAT (${vatRate}%)</div>
          <div class="v">${fmtZAR(CALC_DATA.vat)}</div>
        </div>
        <div>
          <div class="k">Commission %</div>
          <div class="v">${commPct}%</div>
        </div>
      </div>

            <div class="sig" style="gap:18px;">
        <div class="sigbox">
          <div style="height:55px"></div>
          <div class="sigline"></div>
          <div class="sigtext">
            <div style="font-weight:900">${escapeHtml(user.name || "User")}</div>
            <div class="muted">${escapeHtml(user.designation || "Property Practitioner")}</div>
          </div>
        </div>
        ${isCandidatePP ? `
        <div class="sigbox">
          <div style="height:55px"></div>
          <div class="sigline"></div>
          <div class="sigtext">
            <div style="font-weight:900">Property Practitioner</div>
            <div class="muted">&nbsp;</div>
          </div>
        </div>
        ` : ``}
      </div>
<div class="ppra-footer">Registered with the PPRA.</div>
    </body>
    </html>
  `;
}


// --- INITIALIZATION ---

// Global — available immediately for inline onclick handlers
function activateSection(targetId) {
  // Remove .active from ALL section panels
  var sections = document.querySelectorAll('#hf-tool-root .section');
  for (var i = 0; i < sections.length; i++) {
    sections[i].classList.remove('active');
  }
  // Add .active to the target section panel
  var target = document.getElementById(targetId);
  if (target) {
    target.classList.add('active');
  }
  // Sync tab button highlights
  var m = {calcSection:0, certSection:1, historySection:2};
  var tabs = document.querySelectorAll('#hf-tool-root .tab-btn');
  for (var j = 0; j < tabs.length; j++) {
    if (j === (m[targetId] !== undefined ? m[targetId] : 0)) {
      tabs[j].classList.add('active');
    } else {
      tabs[j].classList.remove('active');
    }
  }
  calcAll();
}
window.activateSection = activateSection;

window.addEventListener("DOMContentLoaded", () => {
    // Activate correct tab based on DEFAULT_TAB and URL params
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');

    let tabToActivate = 'calcSection'; // default

    if (sectionParam === 'cma' || window.DEFAULT_TAB === 'cma') {
        tabToActivate = 'certSection';
    } else if (sectionParam === 'history') {
        tabToActivate = 'historySection';
    } else if (window.DEFAULT_TAB === 'commission' || window.DEFAULT_TAB === 'calc') {
        tabToActivate = 'calcSection';
    }

    activateSection(tabToActivate);

const calcInputs = ["price", "ownerPocket", "commPct", "vatRate", "vatIncl", "commOverrideOn", "commOverrideAmt", "commOverrideMode", "propType", "propAddress", "certDate"];
  calcInputs.forEach(id => {
    const input = el(id);
    if (input) {
      input.oninput = calcAll;
      input.onchange = calcAll;
    }
  });

  if (el("commOverrideOn")) el("commOverrideOn").onchange = (e) => {
    el("commOverrideWrap").style.display = e.target.checked ? "block" : "none";
    calcAll();
  };
  if (el("modePrice")) el("modePrice").onchange = () => { el("price").style.display = "block"; el("priceLabel").textContent = "Advertised Price (R)"; el("ownerPocket").style.display = "none"; calcAll(); };
  if (el("modePocket")) el("modePocket").onchange = () => { el("price").style.display = "none"; el("priceLabel").textContent = "Net Pocket Target (R)"; el("ownerPocket").style.display = "block"; calcAll(); };
  if (el("propType")) el("propType").onchange = () => { el("commPct").value = el("propType").value === "res" ? "7.5" : "10"; calcAll(); };

  if (el("btnReset")) el("btnReset").onclick = () => {
    if(confirm("Reset current form inputs?")) {
        el("propAddress").value = "";
        el("propType").value = "res";
        el("price").value = 0;
        el("ownerPocket").value = 0;
        el("commPct").value = 7.5;
        el("vatRate").value = 15;
        el("vatIncl").checked = false;
        el("commOverrideOn").checked = false;
        el("commOverrideAmt").value = 60000;
        el("commOverrideMode").value = "inc";
        el("modePrice").checked = true;
        el("modePocket").checked = false;
        el("certDate").value = "";

        el("commOverrideWrap").style.display = "none";
        el("price").style.display = "block";
        el("ownerPocket").style.display = "none";
        el("priceLabel").textContent = "Advertised Price (R)";

        calcAll();
    }
  };
  if (el("btnPrint")) el("btnPrint").onclick = async () => {
    // snapshot payload for reload
    const payload = {
      propAddress: el("propAddress").value,
      propType: el("propType").value,
      price: Number(el("price").value),
      ownerPocket: Number(el("ownerPocket").value),
      commPct: Number(el("commPct").value),
      vatRate: Number(el("vatRate").value),
      vatIncl: el("vatIncl").checked,
      commOverrideOn: el("commOverrideOn").checked,
      commOverrideAmt: Number(el("commOverrideAmt").value),
      commOverrideMode: el("commOverrideMode").value,
      mode: el("modePocket").checked ? "pocket" : "price",
      certDate: el("certDate").value
    };

    // save history (non-blocking for print)
    await saveHistoryEntry("CALC", el("propAddress").value || "—", CALC_DATA.sellingPrice, payload);

    handlePrint(generateCalculatorPrintHtml());
  };
updateUIFromSettings();
  refreshHistory();
  calcAll();
});
</script>

@endsection