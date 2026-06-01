{{--
    Phase 3j E1 — SG Documents panel on the property show page.

    Five states (per spec):
      A — no erf number yet → inline form to capture + search
      B — erf present, never searched → "Search SG" button + read-only query preview
      C — searched, documents found → list with [Save] / [Open in SG] actions
      D — documents saved → ✓ Saved + [View] / [Replace]
      E — SG search failed → friendly error + retry

    Alpine handles client state; all network calls go to the controller
    routes (corex.properties.sg.*).
--}}
@php
    $sgQueryBuilder = app(\App\Support\SG\SgQueryBuilder::class);
    $sgBuild = $sgQueryBuilder->buildFromProperty($property);
    $sgDocs = $property->sgDocuments()->orderBy('sg_document_number')->orderBy('sg_page_number')->get();
    $hasErf = !empty($property->erf_number);
    $hasSearched = $property->sg_last_searched_at !== null;
@endphp

<div class="ds-status-card mb-4" id="sg-documents-panel"
     x-data="sgDocumentsPanel({
        propertyId: {{ $property->id }},
        initialDocs: @js($sgDocs->map(fn ($d) => $d->toArray())->all()),
        searchUrl: @js(route('corex.properties.sg.search', $property)),
        saveAllUrl: @js(route('corex.properties.sg.save-all', $property)),
        defaults: @js($sgBuild['defaults'] ?? new \stdClass()),
        missing: @js($sgBuild['missing'] ?? []),
        hasErf: @js($hasErf ?? false),
        hasSearched: @js($hasSearched ?? false),
        lastSearchedAt: @js($property->sg_last_searched_at?->diffForHumans()),
     })">

    <div class="flex items-start justify-between gap-4 mb-3">
        <div>
            <h2 class="ds-section-header" style="margin:0 0 4px 0;">Surveyor General Documents</h2>
            <p style="font-size:0.75rem;color:var(--text-muted);margin:0;">
                Diagrams and general plans from the Chief Surveyor General.
            </p>
        </div>
        <a href="https://csg.dlrrd.gov.za/esio/searchindex.htm" target="_blank" rel="noopener"
           style="font-size:0.6875rem;color:var(--text-muted);text-decoration:none;">
            Open SG site ↗
        </a>
    </div>

    {{-- ── State A: no erf yet ──────────────────────────────────────── --}}
    <template x-if="!hasErf">
        <div style="padding:14px;background:var(--surface-2);border:1px dashed var(--border);border-radius:6px;">
            <p style="font-size:0.875rem;color:var(--text-secondary);margin:0 0 10px 0;">
                <strong>Add the erf number to search SG.</strong>
                <span style="color:var(--text-muted);" title="The erf number is on the title deed, rates bill, or last OTP.">
                    (Where to find it?)
                </span>
            </p>
            <form @submit.prevent="saveErfAndSearch" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <div>
                    <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Erf Number</label>
                    <input type="text" x-model="formErf" maxlength="50" required
                           style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;width:140px;">
                </div>
                <div>
                    <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Portion</label>
                    <input type="text" x-model="formPortion" maxlength="20"
                           style="padding:6px 10px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;width:80px;">
                </div>
                <button type="submit" class="corex-btn-primary" style="font-size:0.75rem;padding:7px 14px;" :disabled="busy">
                    <span x-show="!busy">Save &amp; Search SG</span>
                    <span x-show="busy">Searching…</span>
                </button>
            </form>
        </div>
    </template>

    {{-- ── State B+C+D: erf present ────────────────────────────────── --}}
    <template x-if="hasErf">
        <div>
            <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:10px 12px;margin-bottom:10px;font-size:0.75rem;color:var(--text-secondary);">
                <strong style="color:var(--text-primary);">SG search query:</strong>
                <span x-text="`Province=${defaults.province ?? '—'}, Town=${defaults.town ?? '—'}, Parcel=${defaults.parcel_number ?? '—'}, Portion=${defaults.portion ?? '0'}`"></span>
                <button type="button" @click="modifyOpen = !modifyOpen" style="margin-left:10px;font-size:0.6875rem;background:transparent;border:0;color:var(--brand-button);cursor:pointer;">
                    <span x-text="modifyOpen ? '× cancel' : 'Modify search'"></span>
                </button>
                <template x-if="missing.length">
                    <span style="margin-left:10px;color:#dc2626;">Missing: <span x-text="missing.join(', ')"></span></span>
                </template>
            </div>

            <div x-show="modifyOpen" x-cloak style="background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:10px;">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">
                    <div>
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Province</label>
                        <input type="text" x-model="defaults.province" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Town</label>
                        <input type="text" x-model="defaults.town" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Parcel #</label>
                        <input type="text" x-model="defaults.parcel_number" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Portion</label>
                        <input type="text" x-model="defaults.portion" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Rural/Urban</label>
                        <select x-model="defaults.rural_urban" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                            <option value="urban">Urban</option>
                            <option value="rural">Rural</option>
                        </select>
                    </div>
                    <div x-show="defaults.rural_urban === 'rural'">
                        <label style="display:block;font-size:0.625rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Farm name</label>
                        <input type="text" x-model="defaults.farm_name" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;">
                    </div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:0.75rem;color:var(--text-secondary);">
                    <input type="checkbox" x-model="saveDefaults"> Save these as the property's defaults
                </label>
            </div>

            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <button type="button" @click="runSearch" class="corex-btn-primary" style="font-size:0.75rem;padding:7px 14px;" :disabled="busy || missing.length">
                    <span x-show="!busy && !hasSearched">Search SG</span>
                    <span x-show="!busy && hasSearched">Refresh from SG</span>
                    <span x-show="busy">Searching…</span>
                </button>
                <template x-if="lastSearchedAt">
                    <span style="font-size:0.6875rem;color:var(--text-muted);" x-text="`Last searched ${lastSearchedAt}`"></span>
                </template>
                <template x-if="docs.length && docs.some(d => !d.is_saved)">
                    <button type="button" @click="saveAll" :disabled="busy"
                            style="margin-left:auto;font-size:0.6875rem;padding:6px 12px;background:transparent;border:1px solid var(--border);color:var(--text-secondary);border-radius:4px;cursor:pointer;">
                        Save All to Drive
                    </button>
                </template>
            </div>

            {{-- Error banner --}}
            <template x-if="errorMsg">
                <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:10px 14px;font-size:0.8125rem;margin-bottom:10px;">
                    <strong>SG search problem:</strong> <span x-text="errorMsg"></span><br>
                    <span style="font-size:0.75rem;">You can <a href="https://csg.dlrrd.gov.za/esio/searchindex.htm" target="_blank" rel="noopener" style="color:#991b1b;">search SG directly</a> in the meantime.</span>
                </div>
            </template>

            {{-- Document table --}}
            <template x-if="docs.length">
                <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                        <thead>
                            <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">
                                <th style="text-align:left;padding:8px 12px;">Document #</th>
                                <th style="text-align:left;padding:8px 12px;">Type</th>
                                <th style="text-align:center;padding:8px 12px;">Page</th>
                                <th style="text-align:left;padding:8px 12px;">Status</th>
                                <th style="text-align:right;padding:8px 12px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="d in docs" :key="d.id">
                                <tr style="border-top:1px solid var(--border);">
                                    <td style="padding:8px 12px;color:var(--text-primary);font-weight:500;" x-text="d.sg_document_number"></td>
                                    <td style="padding:8px 12px;color:var(--text-secondary);font-size:0.75rem;" x-text="d.sg_doc_type.replace('_', ' ')"></td>
                                    <td style="padding:8px 12px;text-align:center;color:var(--text-secondary);font-size:0.75rem;" x-text="d.sg_page_number"></td>
                                    <td style="padding:8px 12px;font-size:0.6875rem;">
                                        <span x-show="d.is_saved" style="color:#00b594;font-weight:600;">
                                            ✓ Saved · <span x-text="formatBytes(d.file_size_bytes)"></span>
                                        </span>
                                        <span x-show="!d.is_saved" style="color:var(--text-muted);">—</span>
                                    </td>
                                    <td style="padding:8px 12px;text-align:right;">
                                        <a :href="d.sg_source_url" target="_blank" rel="noopener"
                                           style="font-size:0.6875rem;color:var(--text-muted);text-decoration:none;margin-right:8px;">Open in SG ↗</a>
                                        <template x-if="!d.is_saved">
                                            <button type="button" @click="saveOne(d)" :disabled="busyIds.includes(d.id)"
                                                    class="corex-btn-primary" style="font-size:0.6875rem;padding:4px 10px;">
                                                <span x-show="!busyIds.includes(d.id)">Save to Drive</span>
                                                <span x-show="busyIds.includes(d.id)">Saving…</span>
                                            </button>
                                        </template>
                                        <template x-if="d.is_saved">
                                            <span>
                                                <a :href="downloadUrl(d.id)" class="corex-btn-outline" style="font-size:0.6875rem;padding:4px 10px;text-decoration:none;">View</a>
                                                <button type="button" @click="saveOne(d, true)"
                                                        style="font-size:0.6875rem;padding:4px 10px;background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:4px;cursor:pointer;margin-left:4px;">
                                                    Replace
                                                </button>
                                            </span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <template x-if="hasSearched && !docs.length && !errorMsg && !busy">
                <div style="padding:16px;background:var(--surface-2);border:1px dashed var(--border);border-radius:6px;color:var(--text-muted);font-size:0.8125rem;text-align:center;">
                    No SG documents found for this parcel. Double-check the erf number or use the SG site directly.
                </div>
            </template>
        </div>
    </template>
</div>

@push('scripts')
<script>
function sgDocumentsPanel(cfg) {
    return {
        propertyId:   cfg.propertyId,
        docs:         cfg.initialDocs || [],
        searchUrl:    cfg.searchUrl,
        saveAllUrl:   cfg.saveAllUrl,
        defaults:     cfg.defaults || {},
        missing:      cfg.missing || [],
        hasErf:       cfg.hasErf,
        hasSearched:  cfg.hasSearched,
        lastSearchedAt: cfg.lastSearchedAt,
        modifyOpen:   false,
        saveDefaults: false,
        busy:         false,
        busyIds:      [],
        errorMsg:     null,
        // Inline-form state (State A).
        formErf:      '',
        formPortion:  '0',

        formatBytes(b) {
            if (!b) return '';
            if (b < 1024) return b + ' B';
            if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB';
            return (b / 1024 / 1024).toFixed(1) + ' MB';
        },

        downloadUrl(id) {
            return `/corex/properties/${this.propertyId}/sg/documents/${id}/download`;
        },

        async runSearch() {
            this.busy = true;
            this.errorMsg = null;
            try {
                const body = { ...this.defaults, save_defaults: this.saveDefaults };
                const resp = await fetch(this.searchUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify(body),
                });
                const data = await resp.json();
                if (!data.ok) {
                    this.errorMsg = data.error || 'SG search failed. Try again or use the SG site directly.';
                } else {
                    this.docs = data.documents;
                    this.hasSearched = true;
                    this.lastSearchedAt = 'just now';
                }
            } catch (e) {
                this.errorMsg = e.message || 'Network error contacting SG service.';
            } finally {
                this.busy = false;
            }
        },

        async saveErfAndSearch() {
            // Patch the property erf via the search endpoint (it accepts the
            // override). Then re-render.
            this.busy = true;
            this.errorMsg = null;
            try {
                const body = {
                    ...this.defaults,
                    parcel_number: this.formErf,
                    portion: this.formPortion || '0',
                    save_defaults: true,
                };
                // Also persist erf_number on the property via a quick PATCH
                // call against the property update route (existing endpoint).
                await fetch(`/corex/properties/${this.propertyId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                        'X-HTTP-Method-Override': 'PATCH',
                    },
                    body: JSON.stringify({ erf_number: this.formErf, erf_portion: this.formPortion }),
                });
                // Then SG search.
                const resp = await fetch(this.searchUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    body: JSON.stringify(body),
                });
                const data = await resp.json();
                if (!data.ok) {
                    this.errorMsg = data.error || 'SG search failed.';
                } else {
                    this.docs = data.documents;
                    this.hasErf = true;
                    this.hasSearched = true;
                    this.defaults.parcel_number = this.formErf;
                    this.defaults.portion = this.formPortion || '0';
                    this.lastSearchedAt = 'just now';
                }
            } catch (e) {
                this.errorMsg = e.message;
            } finally {
                this.busy = false;
            }
        },

        async saveOne(doc, isReplace = false) {
            this.busyIds.push(doc.id);
            try {
                const resp = await fetch(`/corex/properties/${this.propertyId}/sg/documents/${doc.id}/save`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                const data = await resp.json();
                if (data.ok) {
                    const idx = this.docs.findIndex(d => d.id === doc.id);
                    if (idx >= 0) this.docs.splice(idx, 1, data.document);
                } else {
                    this.errorMsg = data.error || 'TIF download failed.';
                }
            } catch (e) {
                this.errorMsg = e.message;
            } finally {
                this.busyIds = this.busyIds.filter(id => id !== doc.id);
            }
        },

        async saveAll() {
            this.busy = true;
            try {
                await fetch(this.saveAllUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                await this.runSearch(); // refresh
            } catch (e) {
                this.errorMsg = e.message;
            } finally {
                this.busy = false;
            }
        },
    };
}
</script>
@endpush
