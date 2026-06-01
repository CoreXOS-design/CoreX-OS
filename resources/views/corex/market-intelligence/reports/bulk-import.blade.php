{{-- MIC Phase 3c — Bulk multi-file import for market reports.
     Drag-drop up to 20 PDFs; per-file dropdown override; one POST per file
     so the UI shows real-time row updates and isolates failures.

     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     (Figtree font, brand-navy header + token palette, rounded-md radius,
     pill status badges, no emojis). --}}
@extends('layouts.corex-app')

@push('head')
<style>
    .bi-dropzone {
        border: 2px dashed var(--border);
        border-radius: 6px;
        background: var(--surface);
        padding: 32px 16px;
        text-align: center;
        transition: border-color .15s ease, background-color .15s ease;
        cursor: pointer;
    }
    .bi-dropzone[data-dragover="true"] {
        border-color: var(--brand-button);
        background: color-mix(in srgb, var(--brand-button) 6%, var(--surface));
    }
    .bi-badge {
        display: inline-block;
        padding: 2px 8px;
        font-size: 0.6875rem;
        font-weight: 600;
        border-radius: 9999px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        white-space: nowrap;
    }
    .bi-badge-pending  { background: color-mix(in srgb, var(--text-muted) 18%, transparent); color: var(--text-muted); }
    .bi-badge-uploading{ background: color-mix(in srgb, var(--brand-button) 18%, transparent); color: var(--brand-button); }
    .bi-badge-success  { background: color-mix(in srgb, var(--ds-green, #10b981) 18%, transparent); color: var(--ds-green, #10b981); }
    .bi-badge-duplicate{ background: color-mix(in srgb, var(--ds-amber, #d97706) 18%, transparent); color: var(--ds-amber, #d97706); }
    .bi-badge-failed   { background: color-mix(in srgb, var(--ds-crimson, #dc2626) 18%, transparent); color: var(--ds-crimson, #dc2626); }
    .bi-action-bar {
        position: sticky; bottom: 0; z-index: 5;
        background: var(--surface);
        border-top: 1px solid var(--border);
        padding: 12px 16px;
    }
</style>
@endpush

@section('corex-content')
<div style="width: 100%;"
     x-data="bulkImportPage({
        storeUrl: '{{ route('market-intelligence.reports.bulk-import.store') }}',
        showUrlBase: '{{ url('corex/market-intelligence/reports') }}',
        indexUrl: '{{ route('market-intelligence.reports.index') }}',
        csrf: '{{ csrf_token() }}',
        maxFiles: 20,
        maxBytes: 20480 * 1024,
     })">

    {{-- Branded header → tabs → content (matches all MIC pages). --}}
    <x-mic-page-header
        title="Importer"
        subtitle="Bulk import market reports — drag-drop up to 20 PDFs, auto-detected with per-file override.">
        <x-slot:actions>
            <a href="{{ route('market-intelligence.reports.index') }}"
               class="corex-btn-outline text-sm"
               style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">All reports</a>
        </x-slot:actions>
    </x-mic-page-header>

    @include('corex.market-intelligence.partials.tabs')

    {{-- Drop zone --}}
    <div class="bi-dropzone"
         @click="$refs.fileInput.click()"
         @dragover.prevent="dragover = true"
         @dragleave.prevent="dragover = false"
         @drop.prevent="onDrop($event)"
         :data-dragover="dragover ? 'true' : 'false'">
        <input type="file" multiple accept="application/pdf" x-ref="fileInput"
               style="display: none;"
               @change="addFiles($event.target.files); $event.target.value = ''">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
             style="color: var(--text-muted); margin-bottom: 8px;">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <div style="font-size: 0.875rem; color: var(--text-primary); margin-bottom: 4px;">
            Drop PDFs here or <span style="color: var(--brand-button); text-decoration: underline;">click to browse</span>
        </div>
        <div style="font-size: 0.75rem; color: var(--text-muted);">
            PDF only, max 20 MB each, up to 20 files.
        </div>
    </div>

    {{-- Inline warning area --}}
    <div x-show="warnings.length > 0" x-cloak
         style="margin-top: 10px; padding: 8px 12px; font-size: 0.8125rem;
                background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent);
                color: var(--ds-crimson, #dc2626);
                border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent);
                border-radius: 6px;">
        <ul style="margin: 0; padding-left: 18px;">
            <template x-for="(w, idx) in warnings" :key="idx">
                <li x-text="w"></li>
            </template>
        </ul>
    </div>

    {{-- Selected files table --}}
    <div x-show="files.length > 0" x-cloak
         style="margin-top: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
            <thead>
                <tr style="background: var(--surface-2); color: var(--text-muted); font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.04em;">
                    <th style="text-align: left; padding: 8px 12px; width: 32px;">#</th>
                    <th style="text-align: left; padding: 8px 12px;">Filename</th>
                    <th style="text-align: left; padding: 8px 12px; width: 280px;">Detected Type</th>
                    <th style="text-align: right; padding: 8px 12px; width: 90px;">Size</th>
                    <th style="text-align: left; padding: 8px 12px; width: 140px;">Status</th>
                    <th style="text-align: right; padding: 8px 12px; width: 60px;"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(f, idx) in files" :key="f.id">
                    <tr style="border-top: 1px solid var(--border);">
                        <td style="padding: 8px 12px; color: var(--text-muted);" x-text="idx + 1"></td>
                        <td style="padding: 8px 12px; color: var(--text-primary);">
                            <div x-text="f.name"></div>
                            <div x-show="f.message" x-cloak
                                 style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;"
                                 x-text="f.message"></div>
                        </td>
                        <td style="padding: 8px 12px;">
                            <select x-model="f.typeId"
                                    :disabled="f.status === 'uploading' || f.status === 'success' || f.status === 'duplicate'"
                                    style="width: 100%; padding: 4px 6px; font-size: 0.8125rem;
                                           background: var(--surface-2); color: var(--text-primary);
                                           border: 1px solid var(--border); border-radius: 6px;">
                                <option value="">Auto-detect (recommended)</option>
                                @foreach($reportTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->display_name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="padding: 8px 12px; text-align: right; color: var(--text-muted); font-variant-numeric: tabular-nums;"
                            x-text="formatBytes(f.size)"></td>
                        <td style="padding: 8px 12px;">
                            <span class="bi-badge"
                                  :class="badgeClass(f.status)"
                                  x-text="badgeLabel(f.status)"></span>
                            <a x-show="f.reportId" x-cloak
                               :href="showUrl(f.reportId)"
                               style="margin-left: 6px; font-size: 0.6875rem; color: var(--brand-button); text-decoration: underline;">View</a>
                        </td>
                        <td style="padding: 8px 12px; text-align: right;">
                            <button type="button"
                                    @click="removeFile(f.id)"
                                    :disabled="f.status === 'uploading'"
                                    style="background: none; border: none; padding: 4px;
                                           color: var(--text-muted); cursor: pointer; font-size: 0.875rem;">×</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        {{-- Action bar --}}
        <div class="bi-action-bar"
             style="display: flex; align-items: center; justify-content: space-between;">
            <div style="font-size: 0.75rem; color: var(--text-muted);">
                <span x-text="`${files.length} file${files.length === 1 ? '' : 's'}`"></span>
                · <span x-text="formatBytes(totalSize)"></span>
                <span x-show="processed > 0" x-cloak> · <span x-text="`${processed} of ${files.length} processed`"></span></span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button" @click="clearAll()" :disabled="isSubmitting"
                        class="corex-btn-outline"
                        :style="isSubmitting ? 'opacity:0.6;cursor:not-allowed;' : ''">Clear</button>
                <button type="button" @click="submit()" :disabled="isSubmitting || allDone"
                        class="corex-btn-primary"
                        :style="(isSubmitting || allDone) ? 'opacity:0.6;cursor:not-allowed;' : ''">
                    <span x-show="!isSubmitting" x-text="allDone ? 'All processed' : `Import all (${pendingCount})`"></span>
                    <span x-show="isSubmitting" x-cloak>Importing…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Summary banner --}}
    <div x-show="summary.shown" x-cloak
         style="margin-top: 16px; padding: 12px 16px;
                background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
                display: flex; align-items: center; justify-content: space-between;">
        <div style="font-size: 0.875rem; color: var(--text-primary);">
            <span x-text="`${summary.success} imported, ${summary.duplicate} duplicates, ${summary.failed} failed.`"></span>
        </div>
        <a :href="indexUrl" class="corex-btn-primary">View all reports</a>
    </div>
</div>

<script>
    if (typeof window.bulkImportPage !== 'function') {
        window.bulkImportPage = function(config) {
            return {
                files: [],
                warnings: [],
                dragover: false,
                isSubmitting: false,
                indexUrl: config.indexUrl,
                summary: { shown: false, success: 0, duplicate: 0, failed: 0 },

                get pendingCount() {
                    return this.files.filter(f => f.status === 'pending').length;
                },
                get processed() {
                    return this.files.filter(f => ['success','duplicate','failed','detection_failed'].includes(f.status)).length;
                },
                get totalSize() {
                    return this.files.reduce((s, f) => s + (f.size || 0), 0);
                },
                get allDone() {
                    return this.files.length > 0 && this.pendingCount === 0;
                },

                showUrl(reportId) {
                    return config.showUrlBase + '/' + reportId;
                },

                onDrop(event) {
                    this.dragover = false;
                    if (event.dataTransfer?.files) this.addFiles(event.dataTransfer.files);
                },

                addFiles(fileList) {
                    this.warnings = [];
                    for (const file of fileList) {
                        if (this.files.length >= config.maxFiles) {
                            this.warnings.push(`Maximum ${config.maxFiles} files reached. "${file.name}" not added.`);
                            continue;
                        }
                        // Reject non-PDF MIME early.
                        if (file.type && file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                            this.warnings.push(`"${file.name}" is not a PDF — skipped.`);
                            continue;
                        }
                        if (file.size > config.maxBytes) {
                            this.warnings.push(`"${file.name}" exceeds 20 MB — skipped.`);
                            continue;
                        }
                        // Dedupe by name+size in this session.
                        if (this.files.some(f => f.name === file.name && f.size === file.size)) {
                            this.warnings.push(`"${file.name}" already in the list.`);
                            continue;
                        }
                        this.files.push({
                            id: this.uuid(),
                            file,
                            name: file.name,
                            size: file.size,
                            typeId: '',
                            status: 'pending',
                            message: '',
                            reportId: null,
                        });
                    }
                },

                removeFile(id) {
                    this.files = this.files.filter(f => f.id !== id);
                },

                clearAll() {
                    this.files = [];
                    this.warnings = [];
                    this.summary = { shown: false, success: 0, duplicate: 0, failed: 0 };
                },

                async submit() {
                    if (this.isSubmitting) return;
                    if (this.pendingCount === 0) return;
                    this.isSubmitting = true;
                    this.summary = { shown: false, success: 0, duplicate: 0, failed: 0 };

                    for (const f of this.files) {
                        if (f.status !== 'pending') continue;
                        f.status = 'uploading';
                        f.message = '';
                        try {
                            const body = new FormData();
                            body.append('_token', config.csrf);
                            body.append('file', f.file);
                            if (f.typeId) body.append('report_type_id', String(f.typeId));

                            const r = await fetch(config.storeUrl, {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body,
                            });
                            const data = await r.json().catch(() => null);

                            if (!r.ok) {
                                f.status = 'failed';
                                f.message = data?.message || `HTTP ${r.status}`;
                                this.summary.failed++;
                                continue;
                            }

                            const status = data?.status || 'failed';
                            f.status = status;
                            f.message = data?.message || '';
                            f.reportId = data?.report_id || null;
                            if (status === 'queued')      this.summary.success++;
                            else if (status === 'duplicate') this.summary.duplicate++;
                            else                          this.summary.failed++;
                        } catch (e) {
                            f.status = 'failed';
                            f.message = e.message;
                            this.summary.failed++;
                        }
                    }

                    this.isSubmitting = false;
                    this.summary.shown = true;
                },

                uuid() {
                    return 'xxxxxxxxxxxx4xxxyxxx'.replace(/[xy]/g, (c) => {
                        const r = (Math.random() * 16) | 0;
                        const v = c === 'x' ? r : (r & 0x3) | 0x8;
                        return v.toString(16);
                    });
                },

                badgeClass(status) {
                    const map = {
                        pending:           'bi-badge-pending',
                        uploading:         'bi-badge-uploading',
                        queued:            'bi-badge-success',
                        success:           'bi-badge-success',
                        duplicate:         'bi-badge-duplicate',
                        failed:            'bi-badge-failed',
                        detection_failed:  'bi-badge-failed',
                    };
                    return map[status] || 'bi-badge-pending';
                },
                badgeLabel(status) {
                    const map = {
                        pending:           'Pending',
                        uploading:         'Uploading',
                        queued:            'Imported',
                        success:           'Imported',
                        duplicate:         'Duplicate',
                        failed:            'Failed',
                        detection_failed:  'No parser',
                    };
                    return map[status] || status;
                },
                formatBytes(b) {
                    if (!b && b !== 0) return '—';
                    const kb = b / 1024;
                    if (kb < 1024) return kb.toFixed(0) + ' KB';
                    return (kb / 1024).toFixed(1) + ' MB';
                },
            };
        };
    }
</script>
@endsection
