<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wet-Ink Signing â€” {{ $document->name ?? 'Document' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="/css/corex-document.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        [x-cloak] { display: none !important; }

        body {
            margin: 0; padding: 0;
            background: #0f172a;
            font-family: 'Figtree', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #e2e8f0;
        }

        /* Top bar */
        .wi-topbar {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky; top: 0; z-index: 40;
        }
        .wi-topbar-back {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 500; color: #94a3b8;
            text-decoration: none;
        }
        .wi-topbar-back:hover { color: #e2e8f0; }
        .wi-topbar-center { text-align: center; }
        .wi-topbar-title { font-size: 14px; font-weight: 600; color: #f1f5f9; }
        .wi-topbar-sub { font-size: 11px; color: #64748b; margin-top: 1px; }

        /* Layout */
        .wi-layout {
            display: flex; gap: 0;
            min-height: calc(100vh - 46px);
        }

        /* Left: document */
        .wi-doc-panel {
            flex: 1; padding: 20px;
            overflow-y: auto; background: #0f172a;
        }
        .wi-doc-area {
            max-width: 210mm; margin: 0 auto;
            background: white;
            box-shadow: 0 4px 24px rgba(0,0,0,0.4);
            border-radius: 2px;
            overflow: hidden;
        }

        /* Right: action panel */
        .wi-panel {
            width: 360px; min-width: 360px;
            background: #1e293b;
            border-left: 1px solid #334155;
            padding: 20px;
            overflow-y: auto;
            display: flex; flex-direction: column; gap: 16px;
        }

        /* Step indicator */
        .wi-steps {
            display: flex; gap: 4px;
            margin-bottom: 4px;
        }
        .wi-step-dot {
            flex: 1; height: 3px;
            background: #334155; border-radius: 1px;
        }
        .wi-step-dot.active { background: #00d4aa; }
        .wi-step-dot.done { background: #00d4aa; opacity: 0.5; }
        .wi-step-label {
            font-size: 11px; font-weight: 600; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .wi-step-title {
            font-size: 16px; font-weight: 700; color: #f1f5f9;
            margin: 4px 0 12px 0;
        }

        /* Cards */
        .wi-card {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius:6px;
            padding: 14px 16px;
        }
        .wi-card-teal {
            background: rgba(0,212,170,0.06);
            border-color: color-mix(in srgb, var(--brand-icon) 20%, transparent);
        }
        .wi-card-amber {
            background: rgba(245,158,11,0.06);
            border-color: rgba(245,158,11,0.2);
        }
        .wi-card-green {
            background: rgba(16,185,129,0.06);
            border-color: rgba(16,185,129,0.2);
        }
        .wi-card-red {
            background: color-mix(in srgb, var(--ds-crimson) 6%, transparent);
            border-color: rgba(239,68,68,0.2);
        }

        /* Buttons */
        .wi-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; width: 100%;
            padding: 10px 18px;
            font-size: 13px; font-weight: 600;
            border-radius:6px; cursor: pointer;
            transition: all 0.15s;
            text-decoration: none; border: none;
        }
        .wi-btn-primary {
            background: #00d4aa; color: #0f172a;
        }
        .wi-btn-primary:hover { background: #00e6b8; }
        .wi-btn-secondary {
            background: transparent; color: #94a3b8;
            border: 1px solid #475569;
        }
        .wi-btn-secondary:hover {
            color: #e2e8f0; border-color: #64748b;
        }
        .wi-btn-danger {
            background: transparent; color: #f87171;
            border: 1px solid #7f1d1d;
        }
        .wi-btn-danger:hover { background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); }
        .wi-btn:disabled, .wi-btn.disabled {
            opacity: 0.4; cursor: not-allowed;
        }

        /* Upload zone */
        .wi-upload-zone {
            border: 2px dashed #475569;
            border-radius:6px;
            padding: 20px 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
        }
        .wi-upload-zone:hover, .wi-upload-zone.dragover {
            border-color: #00d4aa;
            background: rgba(0,212,170,0.04);
        }

        .wi-file-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 8px 10px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 2px;
            font-size: 12px;
        }

        /* Status */
        .wi-status {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            padding: 5px 10px; border-radius: 2px;
        }
        .wi-status-pending { background: #1e293b; color: #f59e0b; border: 1px solid #78350f; }
        .wi-status-done { background: #1e293b; color: #00d4aa; border: 1px solid #065f46; }

        /* Text helpers */
        .wi-text-muted { color: #64748b; font-size: 13px; line-height: 1.5; }
        .wi-text-sm { font-size: 12px; }
        .wi-divider { border: none; border-top: 1px solid #334155; margin: 4px 0; }

        /* Recipient list */
        .wi-recipient {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 2px;
        }
        .wi-recipient-avatar {
            width: 32px; height: 32px;
            border-radius: 2px;
            background: #334155;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; color: #94a3b8;
        }

        @media (max-width: 900px) {
            .wi-layout { flex-direction: column; }
            .wi-panel { width: 100%; min-width: 0; border-left: none; border-top: 1px solid #334155; }
        }
        @media print {
            .wi-topbar, .wi-panel { display: none !important; }
            .wi-layout { display: block; }
            .wi-doc-panel { padding: 0; background: white; }
            .wi-doc-area { max-width: 100%; margin: 0; box-shadow: none; }
            .corex-document-wrapper { zoom: 0.82 !important; }
            body { background: white; }
            @page { size: A4; margin: 18mm 20mm; }
        }
    </style>
</head>
<body>

@php
    $currentState = $state ?? 1;
    $recipientName = $recipientRequests->first()?->signer_name ?? 'Recipient';
    $recipientRole = $recipientRequests->first()?->party_role ?? '';
    $recipientStatus = $recipientRequests->first()?->wet_ink_status ?? '';
    $recipientReq = $recipientRequests->first();
    $agentUploaded = $agentRequest && $agentRequest->wet_ink_upload_path;
    $agentUploadPaths = $agentUploaded ? json_decode($agentRequest->wet_ink_upload_path, true) : [];
@endphp

<div class="wi-topbar">
    <a href="{{ route('docuperfect.esign.create') }}" class="wi-topbar-back">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        E-Sign
    </a>
    <div class="wi-topbar-center">
        <div class="wi-topbar-title">{{ $document->name ?? 'Document' }}</div>
        <div class="wi-topbar-sub">Wet-Ink Signing</div>
    </div>
    <div style="width:60px;"></div>
</div>

<div class="wi-layout" x-data="wetInkFlow({{ $currentState }})">

    {{-- Left: Document preview --}}
    <div class="wi-doc-panel">
        <div class="wi-doc-area">
            @if($mergedHtml)
                {!! $mergedHtml !!}
            @else
                <div style="text-align:center; padding:60px 24px; color:#94a3b8;">
                    <p style="font-size:16px; font-weight:600; margin-bottom:6px;">No preview available</p>
                    <p style="font-size:13px;">Download the PDF using the button on the right.</p>
                </div>
            @endif
        </div>
    </div>

    {{-- Right: Action panel --}}
    <div class="wi-panel">

        {{-- Step progress dots --}}
        <div>
            <div class="wi-steps">
                <div class="wi-step-dot" :class="state >= 1 ? (state > 1 ? 'done' : 'active') : ''"></div>
                <div class="wi-step-dot" :class="state >= 2 ? (state > 2 ? 'done' : 'active') : ''"></div>
                <div class="wi-step-dot" :class="state >= 3 ? (state > 3 ? 'done' : 'active') : ''"></div>
                <div class="wi-step-dot" :class="state >= 4 ? (state > 4 ? 'done' : 'active') : ''"></div>
            </div>
        </div>

        {{-- === STATE 1: Download & Sign === --}}
        <template x-if="state === 1">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Step 1 of 4</div>
                    <div class="wi-step-title">Download & Sign</div>
                </div>

                <p class="wi-text-muted">Print this document, sign where indicated, then scan or photograph the signed pages.</p>

                @if($document)
                <a href="{{ route('docuperfect.esign.downloadDocumentPdf', $document->id) }}" class="wi-btn wi-btn-primary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
                    Download PDF
                </a>
                <button type="button" onclick="document.title='{{ addslashes($document->name ?? 'Document') }}'; window.print();" class="wi-btn wi-btn-secondary">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print
                </button>
                @endif

                <hr class="wi-divider">

                <button @click="state = 2" class="wi-btn wi-btn-primary" style="background:#475569; color:#e2e8f0;">
                    I've signed it â€” Continue to Upload
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </template>

        {{-- === STATE 2: Upload === --}}
        <template x-if="state === 2">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Step 2 of 4</div>
                    <div class="wi-step-title">Upload Signed Copy</div>
                </div>

                @if($document)
                <form action="{{ route('docuperfect.esign.wetInkAgentUpload', $document->id) }}"
                      method="POST" enctype="multipart/form-data"
                      @submit="uploading = true">
                    @csrf

                    <div class="wi-upload-zone"
                         :class="dragover ? 'dragover' : ''"
                         @click="$refs.fileInput.click()"
                         @dragover.prevent="dragover = true"
                         @dragleave.prevent="dragover = false"
                         @drop.prevent="handleDrop($event)">
                        <input type="file" name="files[]" x-ref="fileInput" multiple
                               accept=".pdf,.jpg,.jpeg,.png"
                               @change="handleFiles($event)"
                               style="display:none;">
                        <svg width="28" height="28" style="margin:0 auto 6px;" fill="none" stroke="#64748b" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <p style="font-size:12px; font-weight:500; color:#94a3b8;">Drop files here or click to browse</p>
                        <p style="font-size:11px; color:#475569; margin-top:3px;">PDF, JPG, PNG â€” max 20MB each</p>
                    </div>

                    <template x-if="selectedFiles.length > 0">
                        <div style="margin-top:8px; display:flex; flex-direction:column; gap:4px;">
                            <template x-for="(file, idx) in selectedFiles" :key="idx">
                                <div class="wi-file-item">
                                    <span style="color:#e2e8f0;" x-text="file.name"></span>
                                    <button type="button" @click="removeFile(idx)" style="color:#f87171; background:none; border:none; cursor:pointer; padding:2px;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    <button type="submit" :disabled="selectedFiles.length === 0 || uploading"
                            class="wi-btn wi-btn-primary" style="margin-top:10px;">
                        <span x-show="!uploading">Upload</span>
                        <span x-show="uploading" x-cloak>Uploading...</span>
                    </button>
                </form>
                @endif

                <button @click="state = 1" class="wi-btn wi-btn-secondary wi-text-sm" style="padding:6px 12px;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    Back to Download
                </button>
            </div>
        </template>

        {{-- === STATE 3: Approve & Send === --}}
        <template x-if="state === 3">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Step 3 of 4</div>
                    <div class="wi-step-title">Approve & Send</div>
                </div>

                <div class="wi-card wi-card-green">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                        <svg width="16" height="16" fill="none" stroke="#10b981" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <span style="font-size:13px; font-weight:600; color:#10b981;">Your signed copy is uploaded</span>
                    </div>
                    @if(!empty($agentUploadPaths))
                    <p style="font-size:11px; color:#64748b;">{{ count($agentUploadPaths) }} file(s) uploaded</p>
                    @endif
                </div>

                <p class="wi-text-muted">Review your upload, then send the document to the recipient for their signature.</p>

                @if($recipientRequests->isNotEmpty())
                <div>
                    <div class="wi-text-sm" style="color:#64748b; margin-bottom:6px;">Sending to:</div>
                    @foreach($recipientRequests as $r)
                    <div class="wi-recipient">
                        <div class="wi-recipient-avatar">{{ strtoupper(substr($r->signer_name ?? 'R', 0, 1)) }}</div>
                        <div>
                            <div style="font-size:13px; font-weight:600; color:#f1f5f9;">{{ $r->signer_name }}</div>
                            <div style="font-size:11px; color:#64748b;">{{ ucfirst(str_replace('_', ' ', $r->party_role)) }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                @if($document)
                <form action="{{ route('docuperfect.esign.wetInkAgentApprove', $document->id) }}" method="POST">
                    @csrf
                    <button type="submit" class="wi-btn wi-btn-primary">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Approve & Send to Recipient
                    </button>
                </form>
                @endif
            </div>
        </template>

        {{-- === STATE 4: Awaiting Recipient === --}}
        <template x-if="state === 4">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Step 4 of 4</div>
                    <div class="wi-step-title">Awaiting Recipient</div>
                </div>

                <div class="wi-card wi-card-amber">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="#f59e0b" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span style="font-size:13px; font-weight:600; color:#f59e0b;">Waiting for {{ $recipientName }}</span>
                    </div>
                    <p style="font-size:12px; color:#64748b; margin-top:6px;">
                        The document has been sent. You will be notified when they upload their signed copy.
                    </p>
                </div>

                @if($recipientRequests->isNotEmpty())
                @foreach($recipientRequests as $r)
                <div class="wi-recipient">
                    <div class="wi-recipient-avatar">{{ strtoupper(substr($r->signer_name ?? 'R', 0, 1)) }}</div>
                    <div style="flex:1;">
                        <div style="font-size:13px; font-weight:600; color:#f1f5f9;">{{ $r->signer_name }}</div>
                        <div style="font-size:11px; color:#64748b;">{{ ucfirst(str_replace('_', ' ', $r->party_role)) }}</div>
                    </div>
                    <div class="wi-status wi-status-pending">Pending</div>
                </div>
                @endforeach
                @endif
            </div>
        </template>

        {{-- === STATE 5: Review Recipient Upload === --}}
        <template x-if="state === 5">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Review Required</div>
                    <div class="wi-step-title">{{ $recipientName }} Uploaded</div>
                </div>

                <div class="wi-card wi-card-teal">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="#00d4aa" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span style="font-size:13px; font-weight:600; color:var(--brand-icon);">Signed copy received</span>
                    </div>
                    <p style="font-size:12px; color:#64748b; margin-top:6px;">Review the uploaded document and approve or reject it.</p>
                </div>

                @if($document && $recipientReq)
                <a href="{{ route('docuperfect.signatures.wetInkReview', ['document' => $document->id, 'signingRequest' => $recipientReq->id]) }}"
                   class="wi-btn wi-btn-primary">
                    Review Upload
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                @endif
            </div>
        </template>

        {{-- === STATE 6: Complete === --}}
        <template x-if="state === 6">
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <div class="wi-step-label">Complete</div>
                    <div class="wi-step-title">All Parties Signed</div>
                </div>

                <div class="wi-card wi-card-green">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="#10b981" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <span style="font-size:13px; font-weight:600; color:#10b981;">Document fully signed</span>
                    </div>
                </div>

                @if($document)
                <a href="{{ route('docuperfect.documents.edit', $document->id) }}" class="wi-btn wi-btn-primary">
                    View Completed Document
                </a>
                @endif
                <a href="{{ route('docuperfect.esign.create') }}" class="wi-btn wi-btn-secondary">
                    Create Another Document
                </a>
            </div>
        </template>

        {{-- Flash messages --}}
        @if(session('status'))
        <div class="wi-card wi-card-green" style="margin-top:auto;">
            <p style="font-size:12px; color:#10b981;">{{ session('status') }}</p>
        </div>
        @endif
        @if(session('error'))
        <div class="wi-card wi-card-red" style="margin-top:auto;">
            <p style="font-size:12px; color:#f87171;">{{ session('error') }}</p>
        </div>
        @endif

        {{-- Quick links --}}
        <div style="margin-top:auto; padding-top:12px; border-top:1px solid #334155;">
            @if($document)
            <a href="{{ route('docuperfect.documents.edit', $document->id) }}"
               style="display:block; font-size:12px; color:#475569; text-decoration:none; margin-bottom:4px;">
                View in Documents
            </a>
            @endif
            <a href="{{ route('docuperfect.esign.create') }}"
               style="display:block; font-size:12px; color:#475569; text-decoration:none;">
                Create Another
            </a>
        </div>

    </div>
</div>

<script>
function wetInkFlow(initialState) {
    return {
        state: initialState,
        dragover: false,
        selectedFiles: [],
        uploading: false,
        handleFiles(event) {
            this.selectedFiles = [...this.selectedFiles, ...Array.from(event.target.files)];
        },
        handleDrop(event) {
            this.dragover = false;
            this.selectedFiles = [...this.selectedFiles, ...Array.from(event.dataTransfer.files)];
        },
        removeFile(index) {
            this.selectedFiles.splice(index, 1);
        },
    };
}
</script>
</body>
</html>
