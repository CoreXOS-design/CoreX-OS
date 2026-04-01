<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign Document — Home Finders Coastal</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;700&display=swap" rel="stylesheet">
    @if(!empty($isWebTemplate))
    <link href="/css/corex-document.css" rel="stylesheet">
    @endif
    @include('docuperfect.signatures.partials.a4-page-styles')
    <style>
        [x-cloak] { display: none !important; }
        @keyframes pulseHighlight {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            50% { box-shadow: 0 0 0 12px rgba(239, 68, 68, 0); }
        }
        .pulse-highlight {
            animation: pulseHighlight 1s ease-in-out 3;
            border-color: #ef4444 !important;
        }

        /* Web signing view styles */
        .corex-signing-view .field[data-editable="true"] {
            background: #f0fdfa;
            border: 1px solid #0d9488;
            border-radius: 2px;
            padding: 2px 6px;
            min-width: 80px;
            display: inline-block;
        }
        .corex-signing-view .field[data-editable="true"]:hover {
            background: #ccfbf1;
            border-color: #0f766e;
        }
        .corex-signing-view .field[data-locked="true"] {
            background: #f8fafc;
            color: #475569;
            padding: 2px 6px;
            display: inline-block;
        }
        /* Interactive signature elements (web templates) */
        .web-sig-interactive {
            cursor: pointer;
            border: 2px dashed #d97706 !important;
            background: rgba(251,191,36,0.06) !important;
            min-height: 28pt;
            transition: all 0.2s;
            position: relative;
        }
        .web-sig-interactive:hover {
            background: rgba(251,191,36,0.12) !important;
            border-color: #b45309 !important;
            box-shadow: 0 0 0 3px rgba(217,119,6,0.15);
        }
        .web-sig-interactive.web-sig-signed {
            border: 2px solid #10b981 !important;
            background: rgba(16,185,129,0.06) !important;
            cursor: default;
        }
        .web-sig-other-party {
            opacity: 0.5;
            pointer-events: none;
            position: relative;
        }
        .web-sig-other-signed {
            opacity: 0.8;
            position: relative;
        }
        .web-sig-prompt {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 4px;
            font-size: 11px;
            font-weight: 600;
            color: #b45309;
        }
        .web-sig-interactive:hover .web-sig-prompt { color: #92400e; }
        .web-sig-signed-img {
            display: block;
            max-height: 50px;
            margin: 2px auto;
            object-fit: contain;
        }
        /* Page break markers with initials */
        .corex-page-break {
            margin: 16px 0;
        }
        .corex-page-initials-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            padding: 12px 0 4px 0;
        }
        .corex-page-initials {
            width: 60px;
            height: 30px;
            border: 1px solid #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
        }
        .corex-page-initials:hover {
            border-color: #d97706;
            background: rgba(251,191,36,0.06);
        }
        .corex-page-initials.initial-signed {
            border-color: #10b981;
            background: rgba(16,185,129,0.06);
            cursor: default;
        }
        /* Ceremony field highlight when incomplete */
        @keyframes ceremonyPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(217,119,6,0.6); }
            50% { box-shadow: 0 0 0 6px rgba(217,119,6,0); }
        }
        .ceremony-pulse {
            animation: ceremonyPulse 1s ease-in-out 3;
        }
        .corex-signing-view .sig-block-party[data-signer="true"] {
            background: #fffbeb;
            border: 2px dashed #d97706;
            border-radius: 4px;
            padding: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .corex-signing-view .sig-block-party[data-signer="true"]:hover {
            background: #fef3c7;
            border-color: #b45309;
        }
        .corex-signing-view .sig-block-party[data-signer="true"][data-signed="true"] {
            background: #ecfdf5;
            border: 2px solid #10b981;
            cursor: default;
        }
        .corex-signing-view .sig-block-party[data-signer="false"] {
            opacity: 0.5;
            pointer-events: none;
        }
        .corex-signing-view .corex-disclosure-row[data-editable="true"] .corex-radio-placeholder {
            cursor: pointer;
            color: #0d9488;
            font-size: 16pt;
        }
        .corex-signing-view .corex-disclosure-row[data-editable="true"] .corex-radio-placeholder:hover {
            color: #0f766e;
            transform: scale(1.2);
        }
        /* A4 page visual separation for web templates */
        .corex-page-container {
            width: 210mm;
            max-width: 100%;
            background: white;
            margin: 0 auto 24px auto;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
            border-radius: 4px;
            overflow: hidden;
        }
        .corex-page-container .corex-page {
            padding: 20mm;
            min-height: auto;
        }
        /* Clause flagging */
        .clause-flag-icon {
            display: none;
            position: absolute;
            right: 4px;
            top: 2px;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            background: #fef3c7;
            border: 1px solid #d97706;
            color: #92400e;
            cursor: pointer;
            font-size: 12px;
            line-height: 22px;
            text-align: center;
            z-index: 10;
            transition: all 0.15s;
        }
        .clause-flag-icon:hover {
            background: #fde68a;
            border-color: #b45309;
        }
        .corex-clause:hover > .clause-flag-icon {
            display: block;
        }
        .corex-clause.clause-flagged {
            background: #fefce8;
            border-left: 3px solid #d97706;
            padding-left: 6px;
            position: relative;
        }
        .corex-clause.clause-flagged > .clause-flag-icon {
            display: block;
            background: #f59e0b;
            color: white;
        }
        .clause-flag-comment {
            margin: 4px 0 8px 24px;
            padding: 6px 10px;
            background: #fffbeb;
            border: 1px solid #fbbf24;
            border-radius: 6px;
            font-size: 11px;
        }
        .clause-flag-comment input {
            width: 100%;
            border: none;
            background: transparent;
            outline: none;
            font-size: 11px;
            color: #78350f;
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

<div x-data="externalSign()" x-init="init()" class="max-w-4xl mx-auto px-4 py-6 space-y-4">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4" style="background:#0b2a4a;">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $document->name }}</h1>
                <div class="text-sm text-white/60 mt-1">
                    Sent by {{ $template->creator->name ?? 'Home Finders Coastal' }}
                    @if($request->sent_at)
                        on {{ $request->sent_at->format('d M Y') }}
                    @endif
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-white/40">Signing as</div>
                <div class="text-sm font-medium text-white">{{ $request->signer_name }}</div>
                <div class="text-xs text-white/50 capitalize">{{ str_replace('_', ' ', $request->party_role) }}</div>
            </div>
        </div>
        @if($request->token_expires_at)
            <div class="text-xs text-white/40 mt-2">
                Expires: {{ $request->token_expires_at->format('d M Y') }}
                ({{ $request->daysUntilExpiry() }} days remaining)
            </div>
        @endif
    </div>

    {{-- Notification toast --}}
    <div x-show="showNotificationBar" x-cloak x-transition
         class="fixed top-4 right-4 z-[70] max-w-sm rounded-xl px-5 py-3 shadow-lg text-sm font-medium"
         :class="{
             'bg-red-50 text-red-800 border border-red-200': notificationType === 'error',
             'bg-amber-50 text-amber-800 border border-amber-200': notificationType === 'warning',
             'bg-blue-50 text-blue-800 border border-blue-200': notificationType === 'info'
         }">
        <div class="flex items-start gap-2">
            <span x-text="notificationText" class="flex-1"></span>
            <button @click="showNotificationBar = false" class="text-current opacity-50 hover:opacity-100">&times;</button>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- Section-by-Section Navigator — DISABLED (Phase 2: needs proper clause-level rejection, not section-level) --}}

    {{-- Wet ink pending review notice --}}
    @if($wetInkPendingReview)
        <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-4">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-blue-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-blue-800">Upload Pending Review</h3>
                    <p class="text-sm text-blue-600 mt-1">
                        Your signed document has been uploaded and is awaiting review by the agent.
                        You will be notified by email once it has been reviewed.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Wet ink rejected notice --}}
    @if($wetInkRejected)
        <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-red-800">Re-upload Required</h3>
                    <p class="text-sm text-red-600 mt-1">
                        Your previous upload was reviewed and some signatures were found to be missing or unclear.
                        @if($request->wet_ink_rejection_note)
                            <br><strong>Note:</strong> {{ $request->wet_ink_rejection_note }}
                        @endif
                    </p>
                    <p class="text-sm text-red-600 mt-1">
                        Please download the document again, sign in all marked positions, and re-upload.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Method choice (show only if no method chosen yet and not pending review) --}}
    <div x-show="!signingMethod && !wetInkPendingReview" x-cloak class="space-y-4">

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-center">
            <h2 class="text-lg font-semibold text-slate-800 mb-2">How would you like to sign?</h2>
            <p class="text-sm text-slate-500 mb-6">Choose the method that works best for you.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- E-Sign option --}}
                <button @click="chooseMethod('electronic')"
                        class="p-5 rounded-xl border-2 border-slate-200 hover:border-blue-400 hover:bg-blue-50 transition-all text-left group">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                            <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                        </div>
                        <span class="text-sm font-semibold text-slate-800">Sign Electronically</span>
                    </div>
                    <p class="text-xs text-slate-500">Sign directly on screen now. Quick and easy.</p>
                </button>

                {{-- Wet ink option --}}
                <button @click="chooseMethod('wet_ink')"
                        class="p-5 rounded-xl border-2 border-slate-200 hover:border-amber-400 hover:bg-amber-50 transition-all text-left group">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center group-hover:bg-amber-200 transition-colors">
                            <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                        </div>
                        <span class="text-sm font-semibold text-slate-800">Download, Print & Sign</span>
                    </div>
                    <p class="text-xs text-slate-500">Download the PDF, sign by hand, then scan and upload.</p>
                </button>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════
         ELECTRONIC SIGNING
    ══════════════════════════════════════════════ --}}
    <template x-if="signingMethod === 'electronic'">
        <div class="space-y-4">

            {{-- Progress bar --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700">Signing Progress</span>
                    <span class="text-sm text-slate-500">
                        <span x-text="signedCount"></span> / <span x-text="totalRequired"></span> <span x-text="isWebTemplate ? 'items completed' : 'markers completed'"></span>
                    </span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2.5">
                    <div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500"
                         :style="'width:' + (totalRequired > 0 ? Math.round((signedCount / totalRequired) * 100) : 0) + '%'"></div>
                </div>
            </div>

            {{-- Completion overlay — prevents Alpine re-render issues --}}
            <div x-show="completionDone" x-cloak class="bg-white rounded-2xl shadow-sm border border-emerald-200 p-8 text-center" style="min-height:300px;">
                <div class="flex flex-col items-center justify-center gap-4 py-12">
                    <svg class="w-16 h-16 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <h3 class="text-lg font-semibold text-emerald-700">Signing Complete</h3>
                    <p class="text-sm text-gray-500">Your signatures have been saved successfully.</p>
                    <div class="flex flex-col items-center gap-3 mt-4">
                        <a href="{{ route('docuperfect.esign.myDocuments') }}"
                           style="background: #00d4aa; color: #fff; border-radius: 3px; padding: 10px 24px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; transition: opacity 0.2s;"
                           onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                            Back to My Documents
                        </a>
                        <a href="/dashboard" style="color: var(--text-muted, #64748b); font-size: 13px; text-decoration: none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                            Go to Dashboard
                        </a>
                    </div>
                </div>
            </div>

            {{-- Document viewer --}}
            <div x-show="!completionDone" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 overflow-hidden flex flex-col" style="min-height:600px;">

                {{-- Web template: render HTML directly — document elements are the interactive surface --}}
                <template x-if="isWebTemplate">
                    <div class="flex-1 overflow-auto" style="background:#e2e8f0; padding:16px 0; min-width:794px;">
                        <div x-ref="pageContainer" class="relative"
                             style="width:210mm; max-width:100%; margin:0 auto;">
                            <div x-ref="webDocContent" x-html="webTemplateHtml"></div>

                            {{-- Floating signature markers — from zones drawn in setup.
                                 Positioned with absolute % values relative to the paginated container.
                                 Container width locked to 210mm (A4) to match setup coordinate system. --}}
                            <template x-for="marker in markers" :key="'wm-' + marker.id">
                                <div x-show="!hasFlattened || (marker.is_mine && !marker.signed)"
                                     class="absolute flex items-center justify-center select-none transition-all duration-200"
                                     :id="'marker-' + marker.id"
                                     :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:40px;max-width:200px;z-index:10;`"
                                     :class="markerDisplayClasses(marker)"
                                     @click="handleMarkerClick(marker)">

                                    {{-- My unsigned marker (clickable) --}}
                                    <template x-if="marker.is_mine && !marker.signed">
                                        <div class="flex flex-col items-center justify-center w-full h-full px-1">
                                            <span class="text-xs font-bold leading-tight truncate" x-text="markerActionLabel(marker)"></span>
                                            <span class="text-[10px] leading-tight opacity-70 truncate" x-text="marker.label || markerTypeLabel(marker)"></span>
                                        </div>
                                    </template>

                                    {{-- My signed marker --}}
                                    <template x-if="marker.is_mine && marker.signed && !hasFlattened">
                                        <div class="flex flex-col items-center justify-center w-full h-full relative">
                                            <template x-if="marker.signature_data && marker.type !== 'date' && marker.type !== 'text'">
                                                <img :src="marker.signature_data"
                                                     class="w-full h-full object-contain p-0.5"
                                                     alt="Signature">
                                            </template>
                                            <template x-if="marker.type === 'date'">
                                                <span class="text-xs font-medium" x-text="marker.text_value || marker.date_value || formatDate(new Date())"></span>
                                            </template>
                                            <template x-if="marker.type === 'text'">
                                                <span class="text-xs font-medium truncate px-1" x-text="marker.text_value || ''"></span>
                                            </template>
                                            <span class="absolute -bottom-0.5 right-0.5 text-[9px] text-emerald-700 font-semibold" x-text="marker.type === 'text' ? 'Done' : 'Signed'"></span>
                                        </div>
                                    </template>

                                    {{-- Other party's marker --}}
                                    <template x-if="!marker.is_mine">
                                        <div class="flex flex-col items-center justify-center w-full h-full px-1 opacity-60">
                                            <template x-if="marker.signed">
                                                <div class="flex flex-col items-center">
                                                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span class="text-[10px] leading-tight capitalize truncate" x-text="marker.assigned_party + ' (signed)'"></span>
                                                </div>
                                            </template>
                                            <template x-if="!marker.signed">
                                                <div class="flex flex-col items-center">
                                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                    </svg>
                                                    <span class="text-[10px] leading-tight capitalize truncate" x-text="marker.assigned_party"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- PDF template: page images with overlays --}}
                <template x-if="!isWebTemplate">
                    <div>

                {{-- Page navigation --}}
                <div class="flex items-center justify-between mb-3 flex-shrink-0">
                    <button @click="prevPage()" :disabled="currentPage <= 1"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                            :class="currentPage <= 1 ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                        &larr; Previous
                    </button>
                    <span class="text-sm text-slate-600 font-medium">
                        Page <span x-text="currentPage"></span> of <span x-text="totalPages"></span>
                    </span>
                    <button @click="nextPage()" :disabled="currentPage >= totalPages"
                            class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors"
                            :class="currentPage >= totalPages ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-slate-200 text-slate-700 hover:bg-slate-300'">
                        Next &rarr;
                    </button>
                </div>

                {{-- Page display with markers --}}
                <div class="flex-1 overflow-auto flex justify-center" style="background:#e2e8f0;">
                    <div class="relative inline-block" style="max-width:800px; width:100%;">
                        <img :src="pageImages[currentPage - 1]"
                             class="w-full block select-none pointer-events-none"
                             draggable="false">

                        {{-- Render document field values --}}
                        {{-- Creator fields: shown only when NOT flattened (baked in when flattened) --}}
                        {{-- Signer-assigned fields: always shown (NOT flattened) --}}
                        <template x-for="field in fieldsForCurrentPage()" :key="field.id">
                            <div x-show="shouldShowField(field)"
                                 class="absolute overflow-hidden"
                                 :class="isMyField(field) ? '' : 'pointer-events-none'"
                                 :style="fieldDisplayStyle(field)">

                                {{-- MY signer-assigned field: interactive --}}
                                <template x-if="isMyField(field)">
                                    <div class="w-full h-full">
                                        {{-- Tick field: interactive for signer --}}
                                        <template x-if="field.type === 'tick'">
                                            <div class="w-full h-full relative" style="background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;">
                                                <template x-for="(opt, optIdx) in (field.options || [])" :key="optIdx">
                                                    <div class="absolute top-0 h-full flex items-center justify-center cursor-pointer hover:bg-amber-100/50 transition-colors"
                                                         :style="`left:${optIdx * (100 / (field.options || []).length)}%;width:${100 / (field.options || []).length}%;`"
                                                         @click="selectFieldOption(field, opt)">
                                                        <span class="font-bold text-lg"
                                                              :class="field.selectedValue === opt ? 'text-black' : 'text-slate-300'"
                                                              x-text="field.selectedValue === opt ? 'X' : opt"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        {{-- Selection field: interactive for signer --}}
                                        <template x-if="field.type === 'selection'">
                                            <div class="w-full h-full relative" style="background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;">
                                                <template x-for="(opt, optIdx) in (field.options || [])" :key="optIdx">
                                                    <div class="absolute top-0 h-full flex items-center justify-center cursor-pointer hover:bg-amber-100/50 transition-colors"
                                                         :style="`left:${optIdx * (100 / (field.options || []).length)}%;width:${100 / (field.options || []).length}%;`"
                                                         @click="selectFieldOption(field, opt)">
                                                        <span class="text-xs px-1"
                                                              :class="field.selectedValue === opt ? 'font-bold text-amber-800 underline' : 'text-slate-400'"
                                                              x-text="opt"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        {{-- Strikethrough field: interactive toggle for signer --}}
                                        <template x-if="field.type === 'strikethrough'">
                                            <div class="w-full h-full relative cursor-pointer"
                                                 :style="field.active ? 'background:rgba(239,68,68,0.08);border:2px solid rgba(239,68,68,0.4);border-radius:4px;' : 'background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;'"
                                                 @click="field.active = !field.active; fieldsDirty = true;">
                                                <template x-if="field.active && (field.strikethroughType || 'horizontal') === 'horizontal'">
                                                    <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                                </template>
                                                <template x-if="field.active && field.strikethroughType === 'diagonal'">
                                                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                        <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                                    </svg>
                                                </template>
                                                <template x-if="!field.active">
                                                    <div class="absolute inset-0 flex items-center justify-center">
                                                        <span class="text-[10px] text-amber-600 italic">Click to strike</span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        {{-- Text placeholder: editable for signer --}}
                                        <template x-if="field.type === 'placeholder'">
                                            <div class="w-full h-full" style="background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;">
                                                <input type="text" class="w-full h-full bg-transparent border-0 outline-none px-1 text-sm"
                                                       :style="fieldStyle(field)"
                                                       :value="field.value || ''"
                                                       @input="field.value = $event.target.value; fieldsDirty = true;"
                                                       placeholder="Enter text...">
                                            </div>
                                        </template>
                                        {{-- Date: editable for signer --}}
                                        <template x-if="field.type === 'date'">
                                            <div class="w-full h-full" style="background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;">
                                                <input type="date" class="w-full h-full bg-transparent border-0 outline-none px-1 text-sm"
                                                       :value="field.value || ''"
                                                       @change="field.value = $event.target.value; fieldsDirty = true;">
                                            </div>
                                        </template>
                                        {{-- Condition: editable for signer --}}
                                        <template x-if="field.type === 'condition'">
                                            <div class="w-full h-full" style="background:rgba(251,191,36,0.08);border:2px solid rgba(251,191,36,0.5);border-radius:4px;">
                                                <textarea class="w-full h-full bg-transparent border-0 outline-none px-1 text-xs resize-none"
                                                          :style="fieldStyle(field)"
                                                          @input="field.text = $event.target.value; fieldsDirty = true;"
                                                          x-text="field.text || ''"></textarea>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                {{-- Other signer's field: locked with label --}}
                                <template x-if="isOtherSignerField(field)">
                                    <div class="w-full h-full flex items-center justify-center pointer-events-none"
                                         style="background:rgba(148,163,184,0.15);border:1px dashed rgba(148,163,184,0.5);">
                                        <span class="text-[10px] text-slate-500 italic text-center leading-tight px-1"
                                              x-text="signerLabel(field.assignedTo) + ' will complete'"></span>
                                    </div>
                                </template>

                                {{-- Creator field (read-only, shown when not flattened) --}}
                                <template x-if="isCreatorField(field)">
                                    <div class="w-full h-full pointer-events-none">
                                        <template x-if="field.type === 'placeholder' && field.value">
                                            <div class="w-full h-full flex items-start px-0.5 overflow-hidden"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.value"></div>
                                        </template>
                                        <template x-if="field.type === 'date' && field.value">
                                            <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.value"></div>
                                        </template>
                                        <template x-if="field.type === 'selection' && field.selectedValue">
                                            <div class="w-full h-full flex items-center px-0.5 overflow-hidden"
                                                 :style="fieldStyle(field)">
                                                <span class="bg-cyan-100 text-cyan-800 px-1.5 py-0.5 rounded text-xs" x-text="field.selectedValue"></span>
                                            </div>
                                        </template>
                                        <template x-if="field.type === 'tick' && field.selectedValue">
                                            <div class="w-full h-full flex items-center justify-center"
                                                 :style="fieldStyle(field)">
                                                <span class="font-bold text-black" style="font-size:1.2em;">X</span>
                                            </div>
                                        </template>
                                        <template x-if="field.type === 'condition' && field.text">
                                            <div class="w-full h-full overflow-hidden px-0.5 bg-white/85"
                                                 :style="fieldStyle(field)"
                                                 x-text="field.text"></div>
                                        </template>
                                        <template x-if="field.type === 'strikethrough' && field.active">
                                            <div class="w-full h-full relative">
                                                <template x-if="(field.strikethroughType || 'horizontal') === 'horizontal'">
                                                    <div class="absolute top-1/2 left-0 w-full h-0.5 bg-red-500 -translate-y-1/2"></div>
                                                </template>
                                                <template x-if="field.strikethroughType === 'diagonal'">
                                                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                                        <line x1="0" y1="0" x2="100" y2="100" stroke="#ef4444" stroke-width="3" />
                                                    </svg>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="field.type === 'signature' || field.type === 'initial'">
                                            <div class="w-full h-full flex flex-col justify-end p-0.5">
                                                <div class="border-b border-black mb-0.5"></div>
                                                <div class="text-[8px] uppercase text-gray-500" x-text="field.type === 'initial' ? 'Initial' : 'Signature'"></div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Render markers for current page --}}
                        <template x-for="marker in markersForCurrentPage()" :key="marker.id">
                            {{-- When flattened, skip other parties' markers (baked in) and skip already-signed own markers --}}
                            <div x-show="!hasFlattened || (marker.is_mine && !marker.signed)"
                                 class="absolute flex items-center justify-center select-none transition-all duration-200"
                                 :id="'marker-' + marker.id"
                                 :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:40px;max-width:200px;z-index:10;`"
                                 :class="markerDisplayClasses(marker)"
                                 @click="handleMarkerClick(marker)">

                                {{-- My unsigned marker (clickable) --}}
                                <template x-if="marker.is_mine && !marker.signed">
                                    <div class="flex flex-col items-center justify-center w-full h-full px-1">
                                        <span class="text-xs font-bold leading-tight truncate" x-text="markerActionLabel(marker)"></span>
                                        <span class="text-[10px] leading-tight opacity-70 truncate" x-text="marker.label || markerTypeLabel(marker)"></span>
                                    </div>
                                </template>

                                {{-- My signed marker (only shown when NOT flattened — when flattened, sig is baked in) --}}
                                <template x-if="marker.is_mine && marker.signed && !hasFlattened">
                                    <div class="flex flex-col items-center justify-center w-full h-full relative">
                                        <template x-if="marker.signature_data && marker.type !== 'date' && marker.type !== 'text'">
                                            <img :src="marker.signature_data"
                                                 class="w-full h-full object-contain p-0.5"
                                                 alt="Signature">
                                        </template>
                                        <template x-if="marker.type === 'date'">
                                            <span class="text-xs font-medium" x-text="marker.text_value || marker.date_value || formatDate(new Date())"></span>
                                        </template>
                                        <template x-if="marker.type === 'text'">
                                            <span class="text-xs font-medium truncate px-1" x-text="marker.text_value || ''"></span>
                                        </template>
                                        <span class="absolute -bottom-0.5 right-0.5 text-[9px] text-emerald-700 font-semibold" x-text="marker.type === 'text' ? 'Done' : 'Signed'"></span>
                                    </div>
                                </template>

                                {{-- Other party's marker --}}
                                <template x-if="!marker.is_mine">
                                    <div class="flex flex-col items-center justify-center w-full h-full px-1 opacity-60">
                                        <template x-if="marker.signed">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <span class="text-[10px] leading-tight capitalize truncate" x-text="marker.assigned_party + ' (signed)'"></span>
                                            </div>
                                        </template>
                                        <template x-if="!marker.signed">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                </svg>
                                                <span class="text-[10px] leading-tight capitalize truncate" x-text="marker.assigned_party"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                    </div>
                </template>
            </div>

            {{-- Web Template Consent + Submit (only for live HTML signing) --}}
            <template x-if="isWebTemplate">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 space-y-4">
                    <label id="consent-checkbox-label" class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" x-model="webConsented"
                               id="consent-checkbox"
                               class="mt-0.5 w-4 h-4 text-teal-600 rounded border-slate-300 focus:ring-teal-500"
                               @change="updateIncompleteCount()">
                        <span class="text-sm text-slate-700 leading-relaxed">
                            I confirm that I have read and understood this document.
                            I consent to signing this document electronically.
                            I understand that my electronic signature has the same legal effect
                            as a handwritten signature under South African law (ECTA Section 13).
                        </span>
                    </label>

                    <div class="flex items-center justify-end gap-3">
                        <button @click="signingMethod = null"
                                class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                            &larr; Back
                        </button>
                        <button @click="completeWebSigning()"
                                :disabled="!canSubmitWeb || completing"
                                :class="canSubmitWeb && !completing
                                    ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'"
                                class="rounded-lg px-6 py-2.5 text-sm font-medium transition-colors">
                            <span x-show="!completing">Submit Signed Document</span>
                            <span x-show="completing" x-cloak>Submitting...</span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Complete Signing (standard marker-based flow — hidden for web templates) --}}
            <div x-show="!isWebTemplate" class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 flex items-center justify-between">
                <div class="text-sm text-slate-600">
                    <template x-if="signedCount < totalRequired">
                        <span>Sign all <span x-text="totalRequired - signedCount"></span> remaining marker<span x-show="(totalRequired - signedCount) !== 1">s</span> to continue.</span>
                    </template>
                    <template x-if="signedCount >= totalRequired && totalRequired > 0">
                        <span class="text-emerald-600 font-medium">All markers signed! Click to complete.</span>
                    </template>
                </div>
                <div class="flex items-center gap-3">
                    <button @click="signingMethod = null"
                            class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                        &larr; Back
                    </button>
                    <button @click="completeSigning()"
                            :disabled="signedCount < totalRequired || completing"
                            class="rounded-lg px-6 py-2.5 text-sm font-medium transition-colors"
                            :class="signedCount >= totalRequired && totalRequired > 0 && !completing
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                                : 'bg-slate-100 text-slate-400 cursor-not-allowed'">
                        <span x-show="!completing">Complete Signing</span>
                        <span x-show="completing" x-cloak>Completing...</span>
                    </button>
                </div>
            </div>

            {{-- Floating progress bar — unified incomplete tracker for web templates --}}
            <template x-if="isWebTemplate">
                <div x-show="signingMethod === 'electronic'" x-cloak x-transition
                     class="fixed bottom-4 left-1/2 transform -translate-x-1/2 shadow-lg rounded-xl px-5 py-3 flex items-center gap-3 z-40 border border-gray-700"
                     style="background:#0b2a4a;">
                    <template x-if="webIncompleteCount > 0">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <span class="text-sm font-medium text-white" x-text="webIncompleteCount + ' item' + (webIncompleteCount !== 1 ? 's' : '') + ' remaining'"></span>
                            <button @click="scrollToNextIncomplete()"
                                    class="text-sm font-semibold px-3 py-1 rounded transition-colors bg-amber-500 text-white hover:bg-amber-600">
                                Go to next
                            </button>
                        </div>
                    </template>
                    <template x-if="webIncompleteCount === 0">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm font-medium text-emerald-300">Ready to submit</span>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Floating progress bar for non-web marker-based signing --}}
            <div x-show="!isWebTemplate && signedCount < totalRequired && totalRequired > 0" x-cloak x-transition
                 class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-white shadow-lg rounded-full px-6 py-3 flex items-center gap-3 z-40 border border-slate-200">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-slate-700" x-text="`${signedCount} of ${totalRequired} completed`"></span>
                    <div class="w-24 h-2 bg-slate-200 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full transition-all duration-500"
                             :style="`width: ${totalRequired > 0 ? (signedCount / totalRequired) * 100 : 0}%`"></div>
                    </div>
                </div>
                <button @click="goToNextUnsigned()"
                        class="text-sm text-blue-600 font-medium hover:text-blue-800">
                    Next &rarr;
                </button>
            </div>

            {{-- Signature capture modal --}}
            <div x-show="showSignModal" x-cloak x-transition.opacity
                 class="fixed inset-0 z-50 flex items-center justify-center"
                 style="background:rgba(0,0,0,0.6);"
                 @keydown.escape.window="showSignModal = false">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>

                    <div class="px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
                        <h3 class="text-white font-semibold text-lg">
                            Sign: <span x-text="activeMarker ? markerLabel(activeMarker) : ''"></span>
                            <span class="text-white/50 text-sm" x-text="activeMarker ? '— Page ' + activeMarker.page_number : ''"></span>
                        </h3>
                    </div>

                    <div class="p-6 space-y-4">
                        {{-- Mode tabs --}}
                        <div class="flex gap-2">
                            <button @click="captureMode = 'draw'; $nextTick(() => initCanvas())"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                                    :class="captureMode === 'draw' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                                Draw
                            </button>
                            <button @click="captureMode = 'type'"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                                    :class="captureMode === 'type' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                                Type
                            </button>
                        </div>

                        {{-- Draw mode --}}
                        <div x-show="captureMode === 'draw'" x-transition>
                            <div class="border-2 border-slate-300 rounded-xl bg-white overflow-hidden" style="touch-action:none;">
                                <canvas x-ref="signatureCanvas" class="w-full block" style="height:160px; cursor:crosshair;"></canvas>
                            </div>
                            <div class="flex justify-between items-center mt-2">
                                <button @click="clearCanvas()" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Clear</button>
                                <span class="text-xs text-slate-400">Draw your signature above</span>
                            </div>
                        </div>

                        {{-- Type mode --}}
                        <div x-show="captureMode === 'type'" x-transition>
                            <div class="mb-3">
                                <label class="block text-xs font-medium text-slate-600 mb-1">Type your name</label>
                                <input type="text" x-model="typedName"
                                       class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-blue-500 focus:border-blue-500"
                                       :placeholder="activeMarker && activeMarker.type === 'initial' ? 'Your initials' : 'Your full name'">
                            </div>
                            <div class="border-2 border-slate-200 rounded-xl bg-slate-50 p-4 min-h-[80px] flex items-center">
                                <template x-if="typedName.trim()">
                                    <span class="text-4xl text-slate-800" style="font-family:'Dancing Script',cursive;" x-text="typedName"></span>
                                </template>
                                <template x-if="!typedName.trim()">
                                    <span class="text-sm text-slate-400 italic">Preview will appear here</span>
                                </template>
                            </div>
                            <canvas x-ref="typedCanvas" class="hidden" width="400" height="100"></canvas>
                        </div>

                        <p class="text-xs text-slate-500 leading-relaxed">
                            By signing, you confirm your identity and consent to this document.
                            Your signature will be recorded with a timestamp and IP address.
                        </p>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button @click="showSignModal = false"
                                    class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                                Cancel
                            </button>
                            <button @click="applySignature()"
                                    class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white transition-colors"
                                    style="background:#0b2a4a;"
                                    :disabled="applying"
                                    :class="applying ? 'opacity-50 cursor-not-allowed' : ''">
                                <span x-show="!applying">Apply Signature</span>
                                <span x-show="applying" x-cloak>Applying...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Apply-to-all modal --}}
            <div x-show="showApplyAll" x-cloak x-transition.opacity
                 class="fixed inset-0 z-[60] flex items-center justify-center"
                 style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
                    <h3 class="text-lg font-semibold text-slate-800">Apply to Remaining Markers?</h3>
                    <p class="text-sm text-slate-600">
                        Apply the same signature to your remaining
                        <span class="font-semibold" x-text="remainingSignatureCount"></span>
                        signature marker<span x-show="remainingSignatureCount !== 1">s</span>?
                    </p>
                    <p class="text-xs text-slate-400">Initials and date fields still need to be signed separately.</p>
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button @click="showApplyAll = false; lastSignatureData = null;"
                                class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                            No, I'll Sign Each One
                        </button>
                        <button @click="applyToAllSignatureMarkers()"
                                class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white transition-colors"
                                style="background:#0b2a4a;"
                                :disabled="applyingAll"
                                :class="applyingAll ? 'opacity-50 cursor-not-allowed' : ''">
                            <span x-show="!applyingAll">Yes, Apply to All</span>
                            <span x-show="applyingAll" x-cloak>Applying...</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Apply initial to all pages modal --}}
            <div x-show="showInitialApplyAll" x-cloak x-transition.opacity
                 class="fixed inset-0 z-[60] flex items-center justify-center"
                 style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
                    <h3 class="text-lg font-semibold text-slate-800">Apply to All Pages?</h3>
                    <p class="text-sm text-slate-600">
                        Would you like to apply this initial to all
                        <span class="font-semibold" x-text="(webInitialElements || []).filter(e => e.isMine && !e.signed).length"></span>
                        remaining page<span x-show="(webInitialElements || []).filter(e => e.isMine && !e.signed).length !== 1">s</span>?
                    </p>
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button @click="declineInitialApplyAll()"
                                class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                            No, Just This One
                        </button>
                        <button @click="applyInitialToAll()"
                                class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white transition-colors"
                                style="background:#0b2a4a;">
                            Yes, Apply to All
                        </button>
                    </div>
                </div>
            </div>

            {{-- Text input modal --}}
            <div x-show="showTextModal" x-cloak x-transition.opacity
                 class="fixed inset-0 z-50 flex items-center justify-center"
                 style="background:rgba(0,0,0,0.6);"
                 @keydown.escape.window="showTextModal = false">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden" @click.stop>
                    <div class="px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
                        <h3 class="text-white font-semibold text-lg">
                            Enter Text: <span x-text="activeMarker ? (activeMarker.label || markerLabel(activeMarker)) : ''"></span>
                            <span class="text-white/50 text-sm" x-text="activeMarker ? '— Page ' + activeMarker.page_number : ''"></span>
                        </h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Type your response</label>
                            <input type="text" x-model="textInputValue"
                                   @keydown.enter.prevent="applyTextValue()"
                                   class="w-full rounded-lg border-slate-300 text-sm px-3 py-2.5 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="Type here...">
                        </div>
                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button @click="showTextModal = false"
                                    class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                                Cancel
                            </button>
                            <button @click="applyTextValue()"
                                    class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white transition-colors"
                                    style="background:#0b2a4a;"
                                    :disabled="applying || !textInputValue.trim()"
                                    :class="(applying || !textInputValue.trim()) ? 'opacity-50 cursor-not-allowed' : ''">
                                <span x-show="!applying">Apply</span>
                                <span x-show="applying" x-cloak>Applying...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ══════════════════════════════════════════════
         WET INK PATH
    ══════════════════════════════════════════════ --}}
    <template x-if="signingMethod === 'wet_ink'">
        <div class="space-y-4">

            {{-- Step 1: Download --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6" x-data="{ dlLoading: false, dlError: false, dlDone: false }">
                <h2 class="text-lg font-semibold text-slate-800 mb-2">Step 1: Download the Document</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Download and print the document. Sign in all marked positions by hand.
                </p>
                <a href="{{ route('signing.download-pdf', $token) }}"
                   x-show="!dlLoading"
                   @click.prevent="dlLoading = true; dlError = false;
                       fetch($el.href).then(r => {
                           if (!r.ok) throw new Error();
                           return r.blob();
                       }).then(blob => {
                           const url = URL.createObjectURL(blob);
                           const a = document.createElement('a');
                           a.href = url;
                           a.download = '{{ preg_replace('/[^A-Za-z0-9_\-]/', '_', $document->name ?? 'Document') }}_{{ now()->format('Y-m-d') }}.pdf';
                           a.click();
                           URL.revokeObjectURL(url);
                           dlLoading = false;
                           dlDone = true;
                       }).catch(() => {
                           dlLoading = false;
                           dlError = true;
                       })"
                   class="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white transition-colors"
                   style="background:#0b2a4a;"
                   onmouseover="this.style.background='#163d63'" onmouseout="this.style.background='#0b2a4a'">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Download Document
                </a>
                <div x-show="dlLoading" class="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white opacity-75 cursor-wait"
                     style="background:#0b2a4a;">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    Generating PDF…
                </div>
                <div x-show="dlDone && !dlError" x-cloak class="mt-2 text-xs text-green-600 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Downloaded
                </div>
                <div x-show="dlError" x-cloak class="mt-3 rounded-lg bg-red-50 border border-red-200 p-3">
                    <p class="text-sm text-red-700">PDF generation failed. Use the print option below as an alternative.</p>
                    <a href="{{ route('signatures.external.print', $token) }}" target="_blank"
                       class="inline-flex items-center gap-1 mt-2 text-sm font-medium text-red-700 underline hover:text-red-900">
                        Open printable version
                    </a>
                </div>
            </div>

            {{-- Step 2: Upload --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-2">Step 2: Upload Your Signed Document</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Once signed, scan or photograph all pages and upload below.
                </p>

                <form action="{{ route('signatures.external.upload', $token) }}"
                      method="POST"
                      enctype="multipart/form-data"
                      x-data="uploadForm()"
                      class="space-y-4">
                    @csrf

                    {{-- Drop zone --}}
                    <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center hover:border-blue-400 hover:bg-blue-50/50 transition-colors cursor-pointer"
                         @click="$refs.fileInput.click()"
                         @dragover.prevent="dragover = true"
                         @dragleave.prevent="dragover = false"
                         @drop.prevent="handleDrop($event)"
                         :class="dragover ? 'border-blue-400 bg-blue-50/50' : ''">
                        <input type="file" name="files[]" x-ref="fileInput" multiple
                               accept=".pdf,.jpg,.jpeg,.png"
                               @change="handleFiles($event)"
                               class="hidden">
                        <svg class="w-10 h-10 mx-auto text-slate-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="text-sm text-slate-600 font-medium">Drop files here or click to browse</p>
                        <p class="text-xs text-slate-400 mt-1">Accepted: PDF, JPG, PNG (max 20MB each)</p>
                        <p class="text-xs text-slate-400">You can upload multiple files if scanned separately.</p>
                    </div>

                    {{-- File list --}}
                    <template x-if="selectedFiles.length > 0">
                        <div class="space-y-2">
                            <template x-for="(file, index) in selectedFiles" :key="index">
                                <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 border border-slate-200">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-sm text-slate-700" x-text="file.name"></span>
                                        <span class="text-xs text-slate-400" x-text="formatFileSize(file.size)"></span>
                                    </div>
                                    <button type="button" @click="removeFile(index)"
                                            class="text-red-400 hover:text-red-600">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" @click="$dispatch('reset-method')"
                                class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                            &larr; Back
                        </button>
                        <button type="submit"
                                :disabled="selectedFiles.length === 0 || uploading"
                                class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white transition-colors"
                                style="background:#0b2a4a;"
                                :class="(selectedFiles.length === 0 || uploading) ? 'opacity-50 cursor-not-allowed' : ''">
                            <span x-show="!uploading">Submit for Review</span>
                            <span x-show="uploading" x-cloak>Uploading...</span>
                        </button>
                    </div>
                </form>

                {{-- Email alternative --}}
                <div class="mt-4 pt-4 border-t border-slate-200 text-center">
                    <p class="text-xs text-slate-400 mb-1">Or email your signed copy to:</p>
                    <p class="text-sm font-medium text-slate-700">signatures@hfcoastal.co.za</p>
                    <p class="text-xs text-slate-400 mt-1">
                        Subject: <code class="bg-slate-100 px-1.5 py-0.5 rounded text-xs">SIGN-{{ substr($token, 0, 8) }}</code>
                    </p>
                </div>
            </div>
        </div>
    </template>

    {{-- Decline option --}}
    <div x-show="!wetInkPendingReview" class="text-center">
        <button @click="showDeclineModal = true"
                class="text-xs text-slate-400 hover:text-red-500 transition-colors">
            I decline to sign this document
        </button>
    </div>

    {{-- Decline modal --}}
    <div x-show="showDeclineModal" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);">
        <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
            <h3 class="text-lg font-semibold text-slate-800">Decline to Sign?</h3>
            <p class="text-sm text-slate-600">
                Are you sure? The sender will be notified that you have declined.
            </p>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Reason (optional)</label>
                <textarea x-model="declineReason" rows="3"
                          class="w-full rounded-lg border-slate-300 text-sm px-3 py-2"
                          placeholder="Let the sender know why..."></textarea>
            </div>
            <div class="flex items-center justify-end gap-3 pt-2">
                <button @click="showDeclineModal = false"
                        class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                <button @click="submitDecline()"
                        class="rounded-lg px-6 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700"
                        :disabled="declining"
                        :class="declining ? 'opacity-50 cursor-not-allowed' : ''">
                    <span x-show="!declining">Yes, Decline</span>
                    <span x-show="declining" x-cloak>Declining...</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Web signature capture modal --}}
    <div x-show="showWebSigCapture" x-cloak x-transition.opacity
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.6);"
         @keydown.escape.window="showWebSigCapture = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>
            <div class="px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
                <h3 class="text-white font-semibold text-lg">Sign Here</h3>
            </div>
            <div class="p-6 space-y-4">
                {{-- Mode tabs --}}
                <div class="flex gap-2">
                    <button @click="webSigMode = 'draw'; $nextTick(() => initWebSigCanvas())"
                            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                            :class="webSigMode === 'draw' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                        Draw
                    </button>
                    <button @click="webSigMode = 'type'"
                            class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                            :class="webSigMode === 'type' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                        Type
                    </button>
                </div>

                {{-- Draw mode --}}
                <div x-show="webSigMode === 'draw'">
                    <canvas x-ref="webSigCanvas"
                            width="400" height="150"
                            class="border border-slate-300 rounded bg-white w-full cursor-crosshair"
                            @mousedown="webStartDrawing($event)"
                            @mousemove="webDraw($event)"
                            @mouseup="webStopDrawing()"
                            @mouseleave="webStopDrawing()"
                            @touchstart.prevent="webStartDrawing($event)"
                            @touchmove.prevent="webDraw($event)"
                            @touchend="webStopDrawing()">
                    </canvas>
                </div>

                {{-- Type mode --}}
                <div x-show="webSigMode === 'type'">
                    <input type="text" x-model="webTypedSignature"
                           placeholder="Type your full name"
                           class="w-full text-2xl border border-slate-300 rounded px-3 py-2"
                           style="font-family: 'Dancing Script', cursive;">
                </div>

                <div class="flex items-center gap-3">
                    <button @click="clearWebSignature()"
                            class="text-xs text-slate-500 hover:text-slate-700">Clear</button>
                    <div class="flex-1"></div>
                    <button @click="showWebSigCapture = false"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800 font-medium">Cancel</button>
                    <button @click="applyWebSignature()"
                            class="rounded-lg px-6 py-2 text-sm font-semibold text-white bg-teal-600 hover:bg-teal-700">
                        Apply Signature
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="text-center text-xs text-slate-400 pb-6">
        Home Finders Coastal &mdash; Secure Document Signing
    </div>

</div>

@php
$markersJson = $allMarkers->map(function($m) use ($request) {
    $sig = $m->signatures->first();
    return [
        'id' => $m->id,
        'page_number' => $m->page_number,
        'x_position' => (float) $m->x_position,
        'y_position' => (float) $m->y_position,
        'width' => (float) $m->width,
        'height' => (float) $m->height,
        'type' => $m->type,
        'assigned_party' => $m->assigned_party,
        'label' => $m->label,
        'required' => (bool) $m->required,
        'is_mine' => $m->assigned_party === $request->party_role,
        'signed' => $sig !== null,
        'signature_data' => $sig ? $sig->signature_data : null,
        'signature_type' => $sig ? $sig->signature_type : null,
        'text_value' => $sig ? $sig->text_value : null,
        'date_value' => $sig && $m->type === 'date' ? ($sig->text_value ?? $sig->signed_at) : null,
    ];
})->values();
@endphp

<script>
function externalSign() {
    return {
        // Data
        markers: @json($markersJson),
        pageImages: @json($pageImages),
        documentFields: @json($document->fields_json ?? []),
        hasFlattened: {{ !empty($hasFlattened) ? 'true' : 'false' }},
        isWebTemplate: {{ !empty($isWebTemplate) ? 'true' : 'false' }},
        signingParties: @json($signingParties ?? []),
        storedInitials: @json($storedInitials ?? []),
        webTemplateHtml: @json($webTemplateHtml ?? ''),
        editableFields: @json($editableFields ?? []),
        webFieldsDirty: false,
        currentPage: 1,
        totalPages: {{ $pageCount }},
        signedCount: {{ $signedCount }},
        totalRequired: {{ $totalMarkers }},
        token: @json($token),
        partyRole: @json($request->party_role),
        signerName: @json($request->signer_name),
        fieldsDirty: false,

        // State
        signingMethod: @json($request->signing_method),
        wetInkPendingReview: {{ $wetInkPendingReview ? 'true' : 'false' }},
        showSignModal: false,
        showTextModal: false,
        textInputValue: '',
        activeMarker: null,
        captureMode: 'draw',
        typedName: @json($request->signer_name),
        applying: false,
        completing: false,
        completionDone: false,
        signaturePad: null,

        // Apply-to-all
        showApplyAll: false,
        lastSignatureData: null,
        lastSignatureType: null,
        applyingAll: false,
        firstSignatureDone: false,

        // Decline
        showDeclineModal: false,
        declineReason: '',
        declining: false,

        // Web signing (CDS/web template interactive mode)
        signerRole: @json($signerRole ?? ''),
        fieldMappings: @json($fieldMappings ?? []),
        webFieldValues: {},
        webSignatures: {},
        webDisclosureAnswers: {},
        webConsented: false,
        showWebSigCapture: false,
        currentWebSigBlockId: null,
        webSigMode: 'draw',
        webTypedSignature: '',
        webCeremonyValues: {},
        webClauseFlaggedItems: [],
        otherConditionsText: '',
        totalDisclosureRows: 0,
        webIsDrawing: false,
        webSigCtx: null,
        webInitialElements: [],
        webInitialSigData: null,
        webIncompleteCount: 0,
        showInitialApplyAll: false,
        pendingInitialSigData: null,
        pendingInitialBlockId: null,

        // Section-by-section signing
        hasSections: {{ !empty($sections) ? 'true' : 'false' }},
        sections: @json($sections ?? []),
        sectionLabels: @json(array_map(fn($s) => $s['label'] ?? '', $sections ?? [])),
        totalSections: {{ count($sections ?? []) }},
        currentSection: 0,
        sectionStates: @json(array_map(function($idx) use ($sectionAcceptances) {
            $a = $sectionAcceptances[$idx] ?? null;
            if ($a && ($a['accepted'] ?? false)) return 'accepted';
            if ($a && ($a['rejected'] ?? false)) return 'rejected';
            return 'pending';
        }, array_keys($sections ?? []))),
        acceptedSections: {{ collect($sectionAcceptances ?? [])->filter(fn($a) => $a['accepted'] ?? false)->count() }},
        sectionAccepting: false,
        sectionRejecting: false,
        showRejectModal: false,
        rejectReasonText: '',

        init() {
            this.firstSignatureDone = this.markers.some(m => m.is_mine && m.signed);

            // Listen for method reset from wet ink back button
            this.$el.addEventListener('reset-method', () => {
                this.signingMethod = null;
            });

            // For web templates: split into A4 pages, convert editable field spans to inputs, make sig elements interactive
            // Only init if the document container is already visible (signingMethod already set)
            if (this.isWebTemplate && this.signingMethod === 'electronic') {
                this.$nextTick(() => {
                    setTimeout(() => {
                        paginateDocument(this.$refs.webDocContent, this.signingParties);
                        restoreStoredInitials(this.$refs.webDocContent, this.storedInitials);
                        if (this.editableFields.length > 0) {
                            this.initWebTemplateFields();
                        }
                        this._makeWebElementsInteractive();
                        this._makeCeremonyFieldsEditable();
                        this.processWebDisclosureChecklists();
                        this._processDisclosureTable();
                        this._initClauseFlagging();
                        // Compute incomplete count after all interactive elements are set up
                        setTimeout(() => {
                            this.updateIncompleteCount();
                            // Safety re-check after DOM settles
                            setTimeout(() => this.updateIncompleteCount(), 500);
                        }, 400);
                    }, 150);
                });
            }
        },

        /**
         * Web template interactive signing: find all [data-marker-party][data-marker-type="signature"]
         * elements in the document HTML and make the current signer's elements clickable.
         * No floating overlays — the document elements ARE the signing surface.
         */
        _makeWebElementsInteractive() {
            const container = this.$refs.webDocContent || (this.$refs.pageContainer ? this.$refs.pageContainer.querySelector('[x-html]') : null);
            if (!container) return;

            const self = this;
            const partyRoleMap = {
                'owner': 'landlord', 'owner_party': 'landlord',
                'landlord': 'landlord', 'lessor': 'landlord',
                'seller': 'seller',
                'tenant': 'tenant', 'lessee': 'tenant',
                'buyer': 'buyer', 'acquiring_party': 'buyer',
                'agent': 'agent',
            };

            const tryInit = () => {
                const sigElements = container.querySelectorAll('[data-marker-party][data-marker-type="signature"]');
                if (sigElements.length === 0) return false;

                let myCount = 0;
                const partyCounters = {};

                sigElements.forEach((el) => {
                    const rawParty = (el.dataset.markerParty || '').toLowerCase();
                    const baseRole = partyRoleMap[rawParty] || rawParty;
                    if (partyCounters[baseRole] === undefined) partyCounters[baseRole] = 0;
                    const sigKey = baseRole + '-sig-' + partyCounters[baseRole];
                    partyCounters[baseRole]++;
                    const isMine = self.isMyWebSigBlock(rawParty);

                    if (isMine) {
                        myCount++;
                        el.classList.add('web-sig-interactive');
                        el.setAttribute('data-sig-id', sigKey);
                        el.innerHTML = '<div class="web-sig-prompt"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg> Click to sign</div>';

                        el.addEventListener('click', () => {
                            if (el.getAttribute('data-signed') === 'true') return;
                            self.currentWebSigBlockId = sigKey;
                            self.showWebSigCapture = true;
                            self.$nextTick(() => self.initWebSigCanvas());
                        });
                    } else {
                        // Other party — check if already signed (img embedded in HTML)
                        const existingImg = el.querySelector('img.web-sig-signed-img, img[alt="Signature"]');
                        if (existingImg) {
                            el.classList.add('web-sig-other-signed');
                            // Already has a signature image embedded — leave as is
                        } else {
                            el.classList.add('web-sig-other-party');
                            const partyLabel = rawParty.replace(/_/g, ' ');
                            if (!el.querySelector('.sig-cell-label')) {
                                el.innerHTML = '<div style="font-size:9px;color:#94a3b8;text-align:center;padding:4px;">Awaiting ' + partyLabel + '</div>';
                            }
                        }
                    }
                });

                // Track sig block count (totalRequired/signedCount set by updateIncompleteCount)
                self.webTotalSigBlocksCount = myCount;

                // Add the corex-signing-view class for CSS
                const pageContainer = container.closest('.relative') || container.parentElement;
                if (pageContainer) pageContainer.classList.add('corex-signing-view');

                // Make page-break initials interactive
                self._makeWebInitialsInteractive(container);

                return true;
            };

            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                if (tryInit() || attempts > 20) {
                    clearInterval(interval);
                    // Recompute counts after elements are interactive — ensures top/bottom agree
                    setTimeout(() => this.updateIncompleteCount(), 100);
                }
            }, 200);
        },

        /**
         * Make "Thus done and signed" ceremony fields editable for the current signer.
         * Adapted from agent sign.blade.php — filters to current signer's party only.
         */
        _makeCeremonyFieldsEditable() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;

            const ceremonyTypes = ['location', 'day', 'month', 'year', 'time', 'am_pm'];
            const now = new Date();
            const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            const prefills = {
                day: String(now.getDate()),
                month: months[now.getMonth()],
                year: String(now.getFullYear()).slice(-2),
                time: now.getHours().toString().padStart(2,'0') + ':' + now.getMinutes().toString().padStart(2,'0'),
                am_pm: now.getHours() >= 12 ? 'pm' : 'am',
            };
            const placeholders = {
                location: 'Location',
                day: 'DD',
                month: 'Month',
                year: 'YY',
                time: 'HH:MM',
                am_pm: 'am/pm',
            };

            const self = this;
            if (!this.webCeremonyValues) this.webCeremonyValues = {};

            ceremonyTypes.forEach(fieldType => {
                const selector = '[data-marker-party][data-marker-type="' + fieldType + '"]';
                container.querySelectorAll(selector).forEach(el => {
                    const rawParty = (el.dataset.markerParty || '').toLowerCase();
                    const isMine = self.isMyWebSigBlock(rawParty);

                    if (!isMine) {
                        // Other party or already-filled — leave as read-only
                        if (!el.textContent.trim()) {
                            el.style.opacity = '0.5';
                        }
                        return;
                    }

                    // Replace span with an inline input
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.setAttribute('data-marker-party', el.dataset.markerParty);
                    input.setAttribute('data-marker-type', fieldType);
                    input.setAttribute('data-ceremony-field', 'true');
                    input.value = prefills[fieldType] || '';
                    input.placeholder = placeholders[fieldType] || fieldType;
                    input.className = el.className;
                    input.style.cssText = (el.style.cssText || '') +
                        'background:rgba(251,191,36,0.08);' +
                        'border:none;border-bottom:2px solid rgba(217,119,6,0.5);' +
                        'outline:none;font:inherit;color:inherit;' +
                        'padding:1pt 4pt;box-sizing:border-box;' +
                        'min-height:14pt;';

                    input.addEventListener('input', () => {
                        self.webCeremonyValues[rawParty + '_' + fieldType] = input.value;
                        self.updateIncompleteCount();
                    });

                    // Store prefilled value
                    if (prefills[fieldType]) {
                        self.webCeremonyValues[rawParty + '_' + fieldType] = prefills[fieldType];
                    }

                    el.replaceWith(input);
                });
            });

        },

        /**
         * Make page-break initials elements interactive for the current signer.
         * Adapted from agent sign.blade.php — filters to current signer's party.
         */
        _makeWebInitialsInteractive(container) {
            if (!container) return;
            const self = this;
            const initialElements = container.querySelectorAll('[data-marker-type="initial"]');
            if (initialElements.length === 0) return;

            this.webInitialElements = [];
            let myCount = 0;
            const initPartyCounters = {};

            initialElements.forEach((el) => {
                const rawParty = (el.dataset.markerParty || '').toLowerCase();
                const isMine = self.isMyWebSigBlock(rawParty);
                if (initPartyCounters[rawParty] === undefined) initPartyCounters[rawParty] = 0;
                const initKey = rawParty + '-init-' + initPartyCounters[rawParty];
                initPartyCounters[rawParty]++;

                const entry = { el, rawParty, index: initPartyCounters[rawParty] - 1, initKey, isMine, signed: false, sigData: null };
                self.webInitialElements.push(entry);

                if (isMine) {
                    myCount++;
                    el.style.cursor = 'pointer';
                    el.style.border = '2px dashed #d97706';
                    el.style.background = 'rgba(251,191,36,0.06)';
                    el.title = 'Click to initial';
                    if (!el.querySelector('.init-prompt')) {
                        el.innerHTML = '<span class="init-prompt" style="font-size:9px;color:#b45309;font-weight:600;">Click to initial</span>';
                    }

                    el.addEventListener('click', () => {
                        if (entry.signed) return;
                        self.currentWebSigBlockId = initKey;
                        self.showWebSigCapture = true;
                        self.$nextTick(() => self.initWebSigCanvas());
                    });
                } else {
                    el.style.opacity = '0.5';
                    el.style.pointerEvents = 'none';
                    el.style.cursor = 'default';
                }
            });

            // Track initial count (totalRequired/signedCount set by updateIncompleteCount)
            if (myCount > 0) {
                this.webTotalSigBlocksCount = (this.webTotalSigBlocksCount || 0) + myCount;
            }
        },

        /**
         * Compute all incomplete items for the web template signing flow.
         * Returns array of {el, label} for each incomplete item, sorted in document order.
         */
        _computeIncompleteItems() {
            const items = [];
            const container = this.$refs.webDocContent || null;

            // 0. Unsigned DB markers (from zones drawn in setup)
            this.markers.forEach(m => {
                if (m.is_mine && !m.signed) {
                    const el = document.getElementById('marker-' + m.id);
                    items.push({ el, label: m.label || m.type || 'Signature' });
                }
            });

            // 1. Unsigned signature elements (from inline HTML)
            if (container) {
                container.querySelectorAll('.web-sig-interactive').forEach(el => {
                    if (el.getAttribute('data-signed') !== 'true') {
                        items.push({ el, label: 'Signature' });
                    }
                });
            }

            // 2. Unsigned initial elements
            (this.webInitialElements || []).forEach(entry => {
                if (entry.isMine && !entry.signed) {
                    items.push({ el: entry.el, label: 'Page Initial' });
                }
            });

            // 3. Empty ceremony fields
            if (container) {
                container.querySelectorAll('input[data-ceremony-field="true"]').forEach(inp => {
                    if (!inp.value || !inp.value.trim()) {
                        const type = inp.dataset.markerType || 'field';
                        items.push({ el: inp, label: type.charAt(0).toUpperCase() + type.slice(1) });
                    }
                });
            }

            // 4. Unanswered disclosure rows
            if (this.totalDisclosureRows > 0) {
                const answered = Object.keys(this.webDisclosureAnswers).filter(k => k.startsWith('disclosure_row_')).length;
                if (answered < this.totalDisclosureRows) {
                    if (container) {
                        const allRadioGroups = container.querySelectorAll('input[type="radio"][name^="disclosure_row_"]');
                        const answeredNames = new Set();
                        allRadioGroups.forEach(r => { if (r.checked) answeredNames.add(r.name); });
                        allRadioGroups.forEach(r => {
                            if (!answeredNames.has(r.name) && !items.find(i => i.el && i.el.name === r.name)) {
                                items.push({ el: r.closest('tr') || r, label: 'Disclosure item' });
                                answeredNames.add(r.name);
                            }
                        });
                    }
                }
            }

            // 5. Consent checkbox (always last)
            if (!this.webConsented) {
                const consentEl = document.getElementById('consent-checkbox-label');
                items.push({ el: consentEl, label: 'Consent' });
            }

            // Sort by document order so "Go to next" follows reading order, consent always last
            items.sort((a, b) => {
                if (a.label === 'Consent') return 1;
                if (b.label === 'Consent') return -1;
                if (!a.el || !b.el) return 0;
                const pos = a.el.compareDocumentPosition(b.el);
                if (pos & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
                if (pos & Node.DOCUMENT_POSITION_PRECEDING) return 1;
                return 0;
            });

            return items;
        },

        /**
         * Compute total and incomplete counts for all interactive items (web templates).
         * Single source of truth for both the top progress bar and bottom floating bar.
         */
        _computeWebCounts() {
            const container = this.$refs.webDocContent || null;
            let total = 0;
            let incomplete = 0;

            // 0. DB markers (from zones drawn in setup — works for web templates too)
            this.markers.forEach(m => {
                if (m.is_mine) {
                    total++;
                    if (!m.signed) incomplete++;
                }
            });

            // 1. Signature blocks (mine — marked with .web-sig-interactive from inline HTML)
            if (container) {
                container.querySelectorAll('.web-sig-interactive').forEach(el => {
                    total++;
                    if (el.getAttribute('data-signed') !== 'true') incomplete++;
                });
            }

            // 2. Initial blocks (mine)
            (this.webInitialElements || []).forEach(entry => {
                if (entry.isMine) {
                    total++;
                    if (!entry.signed) incomplete++;
                }
            });

            // 3. Ceremony fields (mine — inputs with data-ceremony-field)
            if (container) {
                container.querySelectorAll('input[data-ceremony-field="true"]').forEach(inp => {
                    total++;
                    if (!inp.value || !inp.value.trim()) incomplete++;
                });
            }

            // 4. Disclosure rows
            if (this.totalDisclosureRows > 0) {
                total += this.totalDisclosureRows;
                const answered = Object.keys(this.webDisclosureAnswers).filter(k => k.startsWith('disclosure_row_')).length;
                incomplete += (this.totalDisclosureRows - answered);
            }

            // 5. Consent
            total++;
            if (!this.webConsented) incomplete++;

            return { total, incomplete };
        },

        /**
         * Update the webIncompleteCount reactive property and sync top progress bar.
         * For web templates, this is the SINGLE source of truth for all counters.
         */
        updateIncompleteCount() {
            if (this.isWebTemplate) {
                const { total, incomplete } = this._computeWebCounts();
                this.webIncompleteCount = incomplete;
                this.totalRequired = total;
                this.signedCount = total - incomplete;
            }
        },

        /**
         * Scroll to the next incomplete field and highlight it.
         */
        scrollToNextIncomplete() {
            const items = this._computeIncompleteItems();
            if (items.length === 0) return;
            const item = items[0];
            if (item.el) {
                item.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                item.el.classList.add('ceremony-pulse', 'pulse-highlight');
                setTimeout(() => {
                    item.el.classList.remove('ceremony-pulse', 'pulse-highlight');
                }, 3000);
                if (item.el.tagName === 'INPUT' && item.el.type !== 'checkbox') {
                    setTimeout(() => item.el.focus(), 400);
                }
            }
        },

        // ── Section-by-section navigation ──
        goToSection(idx) {
            if (idx < 0 || idx >= this.totalSections) return;
            this.currentSection = idx;
            this.showRejectModal = false;
            // Scroll document to section boundary
            const section = this.sections[idx];
            if (section && section.startPage) {
                this.currentPage = section.startPage;
            }
        },

        async acceptCurrentSection() {
            if (this.sectionAccepting) return;
            this.sectionAccepting = true;
            try {
                const resp = await fetch(`/signatures/external/${this.token}/sections/accept`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        section_index: this.currentSection,
                        section_label: this.sectionLabels[this.currentSection],
                        initial_image: this.lastSignatureData || null,
                    }),
                });
                if (resp.ok) {
                    this.sectionStates[this.currentSection] = 'accepted';
                    this.acceptedSections = this.sectionStates.filter(s => s === 'accepted').length;
                    // Auto-advance to next pending section
                    if (this.currentSection < this.totalSections - 1) {
                        this.goToSection(this.currentSection + 1);
                    }
                }
            } catch (e) {
                console.error('Failed to accept section:', e);
            }
            this.sectionAccepting = false;
        },

        rejectCurrentSection() {
            this.rejectReasonText = '';
            this.showRejectModal = true;
        },

        async confirmRejectSection() {
            if (this.sectionRejecting || !this.rejectReasonText.trim()) return;
            this.sectionRejecting = true;
            try {
                const resp = await fetch(`/signatures/external/${this.token}/sections/reject`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        section_index: this.currentSection,
                        section_label: this.sectionLabels[this.currentSection],
                        rejection_reason: this.rejectReasonText,
                    }),
                });
                if (resp.ok) {
                    this.sectionStates[this.currentSection] = 'rejected';
                    this.showRejectModal = false;
                    this.showNotification('Section rejected. The agent has been notified.', 'info');
                }
            } catch (e) {
                console.error('Failed to reject section:', e);
            }
            this.sectionRejecting = false;
        },

        get allSectionsAccepted() {
            return this.hasSections && this.sectionStates.every(s => s === 'accepted');
        },

        // ── Web template field editing ──
        initWebTemplateFields() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;

            // Small delay to ensure x-html has rendered
            setTimeout(() => {
                const fields = container.querySelectorAll('.field[data-field]');
                fields.forEach(span => {
                    const fieldName = span.getAttribute('data-field');
                    if (this.editableFields.includes(fieldName)) {
                        // Convert to editable input
                        const input = document.createElement('input');
                        input.type = 'text';
                        input.name = fieldName;
                        input.value = span.textContent.trim();
                        input.setAttribute('data-field', fieldName);
                        input.className = span.className + ' field-editable';
                        input.style.cssText = span.style.cssText +
                            'background:rgba(251,191,36,0.08);' +
                            'border:none;border-bottom:2px solid rgba(251,191,36,0.6);' +
                            'outline:none;font:inherit;color:inherit;' +
                            'padding:0 2pt;';
                        input.placeholder = fieldName.replace(/_/g, ' ');
                        input.addEventListener('input', () => { this.webFieldsDirty = true; });
                        span.replaceWith(input);
                    } else {
                        // Locked field — add locked styling
                        span.style.opacity = '0.85';
                    }
                });
            }, 100);
        },

        // Collect web template field values from inputs
        collectWebFieldValues() {
            const container = this.$refs.webDocContent || null;
            if (!container) return {};
            const values = {};
            container.querySelectorAll('input.field-editable[data-field]').forEach(input => {
                values[input.name] = input.value;
            });
            return values;
        },

        // Save web template field values back to server
        async saveWebFields() {
            if (!this.webFieldsDirty) return true;
            const values = this.collectWebFieldValues();
            try {
                const resp = await fetch('/sign/' + this.token + '/save-web-fields', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ fields: values }),
                });
                if (!resp.ok) {
                    if (resp.status === 419) {
                        this.showNotification('Session expired. Please reload the page and try again.', 'error');
                    }
                    return false;
                }
                const data = await resp.json();
                if (data.ok) {
                    this.webFieldsDirty = false;
                    return true;
                }
                return false;
            } catch (e) {
                console.error('Save web fields exception:', e);
                return false;
            }
        },

        // ── Method choice ──
        async chooseMethod(method) {
            try {
                const resp = await fetch('/sign/' + this.token + '/choose-method', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ method: method }),
                });
                const data = await resp.json();
                if (data.ok) {
                    this.signingMethod = method;
                    // After method choice renders the document view, split into A4 pages and re-init
                    if (method === 'electronic' && this.isWebTemplate) {
                        this.$nextTick(() => {
                            setTimeout(() => {
                                paginateDocument(this.$refs.webDocContent, this.signingParties);
                                restoreStoredInitials(this.$refs.webDocContent, this.storedInitials);
                                if (this.editableFields.length > 0) {
                                    this.initWebTemplateFields();
                                }
                                this._makeWebElementsInteractive();
                                this._makeCeremonyFieldsEditable();
                                this.processWebDisclosureChecklists();
                                this._processDisclosureTable();
                                this._initClauseFlagging();
                                setTimeout(() => this.updateIncompleteCount(), 300);
                            }, 150);
                        });
                    }
                }
            } catch (err) {
                alert('Failed to set signing method. Please try again.');
            }
        },

        // ── Navigation ──
        prevPage() { if (this.currentPage > 1) this.currentPage--; },
        nextPage() { if (this.currentPage < this.totalPages) this.currentPage++; },

        markersForCurrentPage() {
            return this.markers.filter(m => m.page_number === this.currentPage);
        },

        fieldsForCurrentPage() {
            const pageIdx = this.currentPage - 1;
            return (this.documentFields || []).filter(f => f.pageIndex === pageIdx && f.position && f.size);
        },

        // Detect overlapping fields and offset non-signer fields
        fieldDisplayStyle(field) {
            const isMine = this.isMyField(field);
            let x = field.position.x;
            let y = field.position.y;
            const w = field.size.width;
            const h = field.size.height;
            const zIndex = isMine ? 8 : 5;

            // If this is NOT my field, check if it overlaps with any of my fields
            if (!isMine) {
                const pageFields = this.fieldsForCurrentPage();
                let overlapCount = 0;
                for (const other of pageFields) {
                    if (other.id === field.id) continue;
                    if (!this.isMyField(other)) continue;
                    const ox = other.position.x, oy = other.position.y;
                    if (Math.abs(x - ox) < 2 && Math.abs(y - oy) < 2) {
                        overlapCount++;
                    }
                }
                if (overlapCount > 0) {
                    y = y + (h + 0.5) * overlapCount;
                }
            }

            return `left:${x}%;top:${y}%;width:${w}%;height:${h}%;z-index:${zIndex};`;
        },

        fieldStyle(field) {
            const s = field.style || {};
            let css = 'font-size:' + (s.fontSize || 12) + 'px;';
            css += 'font-family:' + (s.fontFamily || 'Helvetica') + ';';
            css += 'color:#000;';
            if (s.bold) css += 'font-weight:bold;';
            if (s.underline) css += 'text-decoration:underline;';
            if (s.solidBackground) css += 'background:white;';
            return css;
        },

        // ── Field assignment helpers ──
        normalizeRole(role) {
            const aliases = { lessor: 'landlord', lessee: 'tenant' };
            return aliases[role] || role;
        },

        isMyField(field) {
            const at = this.normalizeRole(field.assignedTo || 'creator');
            return at !== 'creator' && at === this.partyRole;
        },

        isOtherSignerField(field) {
            const at = this.normalizeRole(field.assignedTo || 'creator');
            return at !== 'creator' && at !== this.partyRole;
        },

        isCreatorField(field) {
            return !field.assignedTo || field.assignedTo === 'creator';
        },

        shouldShowField(field) {
            const at = field.assignedTo || 'creator';
            // Signer-assigned fields: always show (they are NOT flattened into page image)
            if (at !== 'creator') return true;
            // Creator fields: show only when NOT flattened (they're baked in when flattened)
            return !this.hasFlattened;
        },

        signerLabel(role) {
            const labels = { agent: 'Agent', tenant: 'Tenant', landlord: 'Landlord', buyer: 'Buyer', seller: 'Seller', lessor: 'Landlord', lessee: 'Tenant' };
            return labels[role] || (role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Signer');
        },

        selectFieldOption(field, opt) {
            field.selectedValue = (field.selectedValue === opt) ? null : opt;
            this.fieldsDirty = true;
        },

        // Save signer-completed field values back to the server
        async saveSignerFields() {
            if (!this.fieldsDirty) return true;
            try {
                const resp = await fetch('/sign/' + this.token + '/save-fields', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ fields: this.documentFields }),
                });
                if (!resp.ok) {
                    const text = await resp.text();
                    console.error('Save fields HTTP error:', resp.status, text);
                    if (resp.status === 419) {
                        this.showNotification('Session expired. Please reload the page and try again.', 'error');
                    }
                    return false;
                }
                const data = await resp.json();
                if (data.ok) {
                    this.fieldsDirty = false;
                    return true;
                }
                console.error('Save fields server error:', data);
                return false;
            } catch (e) {
                console.error('Save fields exception:', e);
                return false;
            }
        },

        // ── Marker display ──
        markerLabel(m) {
            const partyLabel = m.assigned_party.replace('_', ' ');
            const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
            return partyLabel.charAt(0).toUpperCase() + partyLabel.slice(1) + ' ' + typeLabel;
        },

        markerTypeLabel(m) {
            return m.type.charAt(0).toUpperCase() + m.type.slice(1);
        },

        markerActionLabel(m) {
            if (m.type === 'text') return 'Enter Text';
            if (m.type === 'date') return 'Auto Date';
            if (m.type === 'initial') return 'Initial Here';
            return 'Sign Here';
        },

        markerDisplayClasses(m) {
            const base = 'rounded border-2 ';
            if (m.is_mine) {
                if (m.signed) return base + 'border-emerald-500 bg-emerald-50/80';
                return base + 'border-blue-500 bg-blue-50/80 cursor-pointer hover:bg-blue-100 hover:shadow-md';
            }
            if (m.signed) return base + 'border-emerald-300 bg-emerald-50/50 cursor-default';
            return base + 'border-slate-300 bg-slate-100/70 cursor-default';
        },

        // ── Marker interaction ──
        handleMarkerClick(marker) {
            if (!marker.is_mine || marker.signed) return;

            if (marker.type === 'date') {
                this.signDateMarker(marker);
                return;
            }

            // For text markers, show text input modal
            if (marker.type === 'text') {
                this.activeMarker = marker;
                this.textInputValue = '';
                this.showTextModal = true;
                return;
            }

            this.activeMarker = marker;
            this.captureMode = 'draw';
            this.typedName = marker.type === 'initial'
                ? this.signerName.split(' ').map(n => n.charAt(0).toUpperCase()).join('')
                : this.signerName;
            this.showSignModal = true;

            this.$nextTick(() => this.initCanvas());
        },

        // ── Canvas ──
        initCanvas() {
            const canvas = this.$refs.signatureCanvas;
            if (!canvas) return;

            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            if (this.signaturePad) {
                this.signaturePad.clear();
                this.signaturePad.off();
            }

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgba(255, 255, 255, 0)',
                penColor: 'rgb(0, 0, 0)',
                minWidth: 1,
                maxWidth: 3,
            });
        },

        clearCanvas() {
            if (this.signaturePad) this.signaturePad.clear();
        },

        generateTypedSignature(name, isInitial = false) {
            const canvas = this.$refs.typedCanvas;
            if (!canvas) return null;
            const scale = 4;
            const cW = isInitial ? 200 : 400;
            const cH = 100;
            canvas.width = cW * scale;
            canvas.height = cH * scale;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.scale(scale, scale);

            if (isInitial) {
                ctx.font = 'bold 80px Arial, Helvetica, sans-serif';
                ctx.fillStyle = '#000000';
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'center';
                ctx.imageSmoothingEnabled = true;
                ctx.fillText(name, cW / 2, cH / 2);
            } else {
                ctx.font = '48px "Dancing Script", cursive';
                ctx.fillStyle = '#000000';
                ctx.textBaseline = 'middle';
                ctx.imageSmoothingEnabled = true;
                ctx.fillText(name, 10, cH / 2);
            }

            return canvas.toDataURL('image/png');
        },

        // ── Date marker auto-sign (plain text, rendered server-side) ──
        async signDateMarker(marker) {
            const dateStr = this.formatDate(new Date());
            const success = await this.submitSignature(marker, null, 'typed', dateStr);
            if (success) {
                marker.date_value = dateStr;
                marker.text_value = dateStr;
            }
        },

        // ── Text marker input (plain text, rendered server-side) ──
        async applyTextValue() {
            if (!this.activeMarker || !this.textInputValue.trim()) return;
            this.applying = true;

            const text = this.textInputValue.trim();
            const success = await this.submitSignature(this.activeMarker, null, 'typed', text);

            if (success) {
                this.activeMarker.text_value = text;
                this.showTextModal = false;
            }

            this.applying = false;
        },

        formatDate(d) {
            return d.getFullYear() + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + String(d.getDate()).padStart(2, '0');
        },

        // ── Apply signature ──
        async applySignature() {
            if (!this.activeMarker) return;
            this.applying = true;

            let signatureData = null;
            let signatureType = 'drawn';

            if (this.captureMode === 'draw') {
                if (!this.signaturePad || this.signaturePad.isEmpty()) {
                    this.applying = false;
                    return;
                }
                signatureData = this.signaturePad.toDataURL('image/png');
            } else {
                if (!this.typedName.trim()) {
                    this.applying = false;
                    return;
                }
                const isInitial = this.activeMarker && this.activeMarker.type === 'initial';
                signatureData = this.generateTypedSignature(this.typedName.trim(), isInitial);
                signatureType = 'typed';
            }

            const success = await this.submitSignature(this.activeMarker, signatureData, signatureType);

            if (success) {
                this.showSignModal = false;

                // Offer apply-to-all for remaining markers of the same type (signature or initial)
                const currentType = this.activeMarker.type;
                const remainingMarkers = this.markers.filter(m =>
                    m.is_mine && !m.signed && m.type === currentType
                );

                if (!this.firstSignatureDone && (currentType === 'signature' || currentType === 'initial') && remainingMarkers.length > 0) {
                    this.lastSignatureData = signatureData;
                    this.lastSignatureType = signatureType;
                    this.showApplyAll = true;
                }

                this.firstSignatureDone = true;
            }

            this.applying = false;
        },

        // ── Submit signature ──
        async submitSignature(marker, signatureData, signatureType, textValue = null) {
            try {
                const body = { signature_type: signatureType };
                if (signatureData) body.signature_data = signatureData;
                if (textValue) body.text_value = textValue;

                const resp = await fetch('/sign/' + this.token + '/capture/' + marker.id, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(body),
                });

                const data = await resp.json();

                if (data.ok) {
                    marker.signed = true;
                    marker.signature_data = signatureData;
                    marker.signature_type = signatureType;
                    this.signedCount = data.signed_count;

                    // When flattened, bust the cache on the page image that was just updated
                    if (this.hasFlattened) {
                        const pageIdx = marker.page_number - 1;
                        const baseUrl = this.pageImages[pageIdx].split('?')[0];
                        this.pageImages[pageIdx] = baseUrl + '?t=' + Date.now();
                    }

                    return true;
                } else {
                    alert(data.error || 'Failed to capture signature.');
                    return false;
                }
            } catch (err) {
                alert('Network error. Please try again.');
                return false;
            }
        },

        // ── Apply to all (signatures or initials) ──
        async applyToAllSignatureMarkers() {
            this.applyingAll = true;

            // Apply to remaining markers of the same type as the one that triggered the prompt
            const applyType = this.activeMarker?.type || 'signature';
            const remaining = this.markers.filter(m =>
                m.is_mine && !m.signed && (m.type === applyType || m.type === 'signature')
            );

            for (const marker of remaining) {
                const success = await this.submitSignature(marker, this.lastSignatureData, this.lastSignatureType);
                if (!success) break;
            }

            this.showApplyAll = false;
            this.lastSignatureData = null;
            this.applyingAll = false;
        },

        get remainingSignatureCount() {
            const t = this.activeMarker?.type || 'signature';
            return this.markers.filter(m => m.is_mine && !m.signed && (m.type === t || m.type === 'signature')).length;
        },
        set remainingSignatureCount(v) {},

        // ── Complete signing (with guided navigation if unsigned remain) ──
        async completeSigning() {
            // Check locally first — guide to unsigned markers
            const unsignedMarkers = this.markers.filter(m => m.is_mine && !m.signed);
            if (unsignedMarkers.length > 0) {
                const first = unsignedMarkers[0];
                const typeLabel = first.type === 'text' ? 'enter text' : (first.type === 'initial' ? 'initial' : 'sign');
                this.showNotification(
                    `Please ${typeLabel} here — ${unsignedMarkers.length} remaining`,
                    'warning'
                );
                this.navigateToMarker(first);
                return;
            }

            if (this.completing) return;
            this.completing = true;

            // Save any signer-completed fields before finalizing
            if (this.fieldsDirty) {
                const saved = await this.saveSignerFields();
                if (!saved) {
                    this.showNotification('Could not save your field entries. Please try again.', 'error');
                    this.completing = false;
                    return;
                }
            }

            // Save web template field values before finalizing
            if (this.webFieldsDirty) {
                const saved = await this.saveWebFields();
                if (!saved) {
                    this.showNotification('Could not save your field entries. Please try again.', 'error');
                    this.completing = false;
                    return;
                }
            }

            try {
                // Include ceremony values (date, location, time) collected during signing
                const payload = {};
                if (this.webCeremonyValues && Object.keys(this.webCeremonyValues).length > 0) {
                    payload.ceremony_values = this.webCeremonyValues;
                }

                const resp = await fetch('/sign/' + this.token + '/complete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                if (data.ok && data.redirect) {
                    this.completionDone = true;
                    window.location.href = data.redirect;
                } else if (data.ok) {
                    this.completionDone = true;
                } else {
                    this.showNotification(data.error || 'Could not complete signing. Please try again.', 'error');
                }
            } catch (err) {
                if (!this.completionDone) {
                    this.showNotification('Network error. Please check your connection and try again.', 'error');
                    console.error('Complete signing failed:', err);
                }
            }
            if (!this.completionDone) this.completing = false;
        },

        // ── Navigate to next unsigned marker ──
        goToNextUnsigned() {
            const unsigned = this.markers.filter(m => m.is_mine && !m.signed);
            if (unsigned.length === 0) return;
            this.navigateToMarker(unsigned[0]);
        },

        navigateToMarker(marker) {
            if (this.currentPage !== marker.page_number) {
                this.currentPage = marker.page_number;
            }
            this.$nextTick(() => {
                const el = document.getElementById('marker-' + marker.id);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('pulse-highlight');
                    setTimeout(() => el.classList.remove('pulse-highlight'), 3000);
                }
            });
        },

        // ── Notification toast ──
        _notificationTimeout: null,
        notificationText: '',
        notificationType: '',
        showNotificationBar: false,

        showNotification(text, type = 'info') {
            this.notificationText = text;
            this.notificationType = type;
            this.showNotificationBar = true;
            if (this._notificationTimeout) clearTimeout(this._notificationTimeout);
            this._notificationTimeout = setTimeout(() => {
                this.showNotificationBar = false;
            }, 5000);
        },

        // ── Web template signing helpers ──
        webTotalSigBlocksCount: 0,

        get webTotalSigBlocks() {
            return this.webTotalSigBlocksCount;
        },

        get canSubmitWeb() {
            return this.webConsented && this.webIncompleteCount === 0;
        },

        // Legacy — replaced by _makeWebElementsInteractive()
        processWebSignatureBlocks() { /* no-op */ },

        isMyWebSigBlock(partyRole) {
            const role = (partyRole || '').toLowerCase();
            const myRole = (this.signerRole || '').toLowerCase();

            // Exact match
            if (role === myRole) return true;

            // Alias matching: strip suffix, check same group, but ONLY
            // if both have the same suffix (or both have no suffix)
            const roleSuffix = (role.match(/_(\d+)$/) || ['', ''])[1];
            const myRoleSuffix = (myRole.match(/_(\d+)$/) || ['', ''])[1];
            if (roleSuffix !== myRoleSuffix) return false;

            const roleBase = role.replace(/_\d+$/, '');
            const myRoleBase = myRole.replace(/_\d+$/, '');

            const ownerTerms = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
            const acquiringTerms = ['acquiring_party', 'lessee', 'buyer', 'tenant', 'purchaser'];
            const agentTerms = ['agent', 'property_practitioner'];
            if (ownerTerms.includes(myRoleBase) && ownerTerms.includes(roleBase)) return true;
            if (acquiringTerms.includes(myRoleBase) && acquiringTerms.includes(roleBase)) return true;
            if (agentTerms.includes(myRoleBase) && agentTerms.includes(roleBase)) return true;
            return false;
        },

        processWebDisclosureChecklists() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;

            setTimeout(() => {
                const checklists = container.querySelectorAll('.corex-disclosure-checklist');
                const self = this;

                checklists.forEach(checklist => {
                    // Check which party should fill this disclosure
                    // Default to owner_party for mandatory disclosure (seller discloses defects)
                    // Honour data-disclosure-party attribute if set on the checklist element
                    const disclosureParty = checklist.getAttribute('data-disclosure-party') || 'owner_party';

                    const rows = checklist.querySelectorAll('.corex-disclosure-row');
                    rows.forEach((row, rowIdx) => {
                        const isEditable = this.isMyWebSigBlock(disclosureParty);
                        row.setAttribute('data-editable', isEditable ? 'true' : 'false');

                        if (isEditable) {
                            const radios = row.querySelectorAll('.corex-radio-placeholder');
                            radios.forEach(radio => {
                                radio.setAttribute('data-selected', 'false');
                                radio.style.cursor = 'pointer';
                                radio.style.fontSize = '16pt';
                                radio.textContent = '\u25CB';

                                radio.addEventListener('click', () => {
                                    radios.forEach(r => {
                                        r.setAttribute('data-selected', 'false');
                                        r.textContent = '\u25CB';
                                    });
                                    radio.setAttribute('data-selected', 'true');
                                    radio.textContent = '\u25CF';
                                    self.webDisclosureAnswers['row-' + rowIdx] = radio.dataset.value || '';
                                });
                            });
                        }
                    });
                });
            }, 150);
        },

        /**
         * Attach clause-level flagging to numbered clauses in the document.
         * Signer can flag individual clauses with a concern — does NOT block signing.
         */
        _initClauseFlagging() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;

            const self = this;
            const clauses = container.querySelectorAll('.corex-clause');

            clauses.forEach((clause, idx) => {
                // Only flag clauses that have a clause number
                const numEl = clause.querySelector('.corex-clause-number');
                if (!numEl) return;
                const clauseNum = numEl.textContent.trim();

                // Make the clause container relative for positioning
                clause.style.position = 'relative';

                // Create flag icon
                const flagBtn = document.createElement('span');
                flagBtn.className = 'clause-flag-icon';
                flagBtn.title = 'Flag this clause';
                flagBtn.textContent = '⚑';
                flagBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    e.preventDefault();
                    console.log('[ClauseFlag] Clicked clause', clauseNum, 'idx', idx);
                    self._toggleClauseFlag(clause, clauseNum, idx);
                });

                clause.appendChild(flagBtn);
            });

        },

        _toggleClauseFlag(clauseEl, clauseNum, idx) {
            const isFlagged = clauseEl.classList.contains('clause-flagged');
            if (isFlagged) {
                // Remove flag
                clauseEl.classList.remove('clause-flagged');
                const commentDiv = clauseEl.querySelector('.clause-flag-comment');
                if (commentDiv) commentDiv.remove();
                this.webClauseFlaggedItems = this.webClauseFlaggedItems.filter(f => f.clauseNum !== clauseNum);
            } else {
                // Add flag
                clauseEl.classList.add('clause-flagged');

                const commentDiv = document.createElement('div');
                commentDiv.className = 'clause-flag-comment';

                const input = document.createElement('input');
                input.type = 'text';
                input.placeholder = 'What is your concern with clause ' + clauseNum + '?';
                input.addEventListener('input', () => {
                    const existing = this.webClauseFlaggedItems.find(f => f.clauseNum === clauseNum);
                    if (existing) {
                        existing.concern = input.value;
                    }
                });
                input.addEventListener('click', (e) => e.stopPropagation());

                commentDiv.appendChild(input);
                clauseEl.appendChild(commentDiv);

                this.webClauseFlaggedItems.push({
                    clauseNum: clauseNum,
                    clauseIndex: idx,
                    concern: '',
                });

                // Focus input
                setTimeout(() => input.focus(), 50);
            }
        },

        /**
         * Process disclosure tables (corex-table with YES/NO/N/A headers)
         * and inject radio button inputs into each data row.
         */
        _processDisclosureTable() {
            const container = this.$refs.webDocContent || null;
            if (!container) return;

            const self = this;
            const tables = container.querySelectorAll('table.corex-table, table');
            let totalRows = 0;

            tables.forEach(table => {
                // Check if this table has YES/NO/N/A headers
                const headers = table.querySelectorAll('thead th');
                if (headers.length < 2) return;
                const headerTexts = Array.from(headers).map(h => h.textContent.trim().toUpperCase());
                const yesIdx = headerTexts.indexOf('YES');
                const noIdx = headerTexts.indexOf('NO');
                const naIdx = headerTexts.indexOf('N/A');
                if (yesIdx === -1 || noIdx === -1) return; // Not a disclosure table

                const optionCols = [];
                if (yesIdx >= 0) optionCols.push({ idx: yesIdx, value: 'YES' });
                if (noIdx >= 0) optionCols.push({ idx: noIdx, value: 'NO' });
                if (naIdx >= 0) optionCols.push({ idx: naIdx, value: 'N/A' });

                // Process each body row
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach((row, rowIdx) => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length < headers.length) return; // Skip spacer/header rows

                    // Skip single-cell rows (sub-headers like "EXTRA INFORMATION", "ADDITIONAL INFORMATION")
                    if (cells.length === 1) return;

                    // Certificate compliance rows: 5 cells, cells[0] empty, cells[1] has cert text
                    // e.g. [empty] | [Cert Name – If Yes, when was it issued?] | [] | [] | []
                    const cell0Text = (cells[0]?.textContent || '').trim();
                    const cell1Text = (cells[1]?.textContent || '').trim();

                    if (cells.length > headers.length && !cell0Text && cell1Text.includes('If Yes, when was it issued')) {
                        self._processCertificateRow(row, cells, rowIdx);
                        totalRows++;
                        return;
                    }

                    // Normal disclosure rows — need question text in cells[0]
                    if (!cell0Text) return;

                    // Skip sub-header rows like "Are you in possession of..." that end with ":"
                    const isSubHeader = !cells[yesIdx]?.textContent.trim() &&
                        !cells[noIdx]?.textContent.trim() &&
                        cell0Text.endsWith(':');
                    if (isSubHeader) return;

                    const radioGroupName = 'disclosure_row_' + rowIdx;
                    totalRows++;

                    optionCols.forEach(opt => {
                        const cell = cells[opt.idx];
                        if (!cell) return;

                        const label = document.createElement('label');
                        label.style.cssText = 'display:flex;align-items:center;justify-content:center;cursor:pointer;width:100%;height:100%;min-height:20px;';

                        const radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = radioGroupName;
                        radio.value = opt.value;
                        radio.style.cssText = 'width:16px;height:16px;cursor:pointer;accent-color:#0d9488;';

                        radio.addEventListener('change', () => {
                            self.webDisclosureAnswers[radioGroupName] = opt.value;
                            self.updateIncompleteCount();
                        });

                        label.appendChild(radio);
                        cell.innerHTML = '';
                        cell.style.textAlign = 'center';
                        cell.style.verticalAlign = 'middle';
                        cell.appendChild(label);
                    });
                });

                // Process Additional Information rows — make them editable
                self._processAdditionalInfoSection(table);
            });

            this.totalDisclosureRows = totalRows;
        },

        /**
         * Process a certificate compliance row with YES/NO radios + conditional date input.
         * These rows have 5 cells: [spacer] | [Cert Name – If Yes, when was it issued?] | [] | [] | []
         * Radio buttons go in cells[2] (YES) and cells[3] (NO). Date input in cells[4].
         */
        _processCertificateRow(row, cells, rowIdx) {
            const self = this;
            const radioGroupName = 'disclosure_row_' + rowIdx;

            // Extract certificate name from cells[1] (everything before the dash)
            const fullText = cells[1].textContent.trim();
            const certName = fullText.replace(/\s*[–-]\s*If Yes.*$/i, '').trim();

            // Replace cells[1] with just the certificate name
            cells[1].textContent = certName;
            cells[1].style.cssText = 'font-size:12px;padding:4px 8px;';

            // YES radio in cells[2]
            const yesOpts = [
                { cell: cells[2], value: 'YES' },
                { cell: cells[3], value: 'NO' },
            ];

            yesOpts.forEach(opt => {
                if (!opt.cell) return;
                const label = document.createElement('label');
                label.style.cssText = 'display:flex;align-items:center;justify-content:center;cursor:pointer;width:100%;height:100%;min-height:20px;gap:2px;';

                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = radioGroupName;
                radio.value = opt.value;
                radio.style.cssText = 'width:16px;height:16px;cursor:pointer;accent-color:#0d9488;';

                const labelText = document.createElement('span');
                labelText.textContent = opt.value;
                labelText.style.cssText = 'font-size:10px;color:#64748b;';

                radio.addEventListener('change', () => {
                    self.webDisclosureAnswers[radioGroupName] = opt.value;
                    // Show/hide date input based on YES/NO
                    const dateWrapper = row.querySelector('.cert-date-wrapper');
                    if (dateWrapper) {
                        if (opt.value === 'YES') {
                            dateWrapper.style.display = 'flex';
                        } else {
                            dateWrapper.style.display = 'none';
                            const dateInput = dateWrapper.querySelector('input[type="date"]');
                            if (dateInput) {
                                dateInput.value = '';
                                delete self.webDisclosureAnswers['disclosure_date_' + rowIdx];
                            }
                        }
                    }
                });

                label.appendChild(radio);
                label.appendChild(labelText);
                opt.cell.innerHTML = '';
                opt.cell.style.textAlign = 'center';
                opt.cell.style.verticalAlign = 'middle';
                opt.cell.appendChild(label);
            });

            // Date input in cells[4] (or cells[3] if only 4 cells) — hidden until YES
            const dateCell = cells[4] || cells[3];
            if (dateCell && dateCell !== cells[3]) {
                const wrapper = document.createElement('div');
                wrapper.className = 'cert-date-wrapper';
                wrapper.style.cssText = 'display:none;align-items:center;gap:4px;';

                const dateLabel = document.createElement('span');
                dateLabel.style.cssText = 'font-size:10px;color:#475569;white-space:nowrap;';
                dateLabel.textContent = 'Date issued:';

                const dateInput = document.createElement('input');
                dateInput.type = 'date';
                dateInput.style.cssText = 'border:1px solid #cbd5e1;border-radius:4px;padding:2px 4px;font-size:11px;width:130px;';

                dateInput.addEventListener('change', () => {
                    self.webDisclosureAnswers['disclosure_date_' + rowIdx] = dateInput.value;
                });

                wrapper.appendChild(dateLabel);
                wrapper.appendChild(dateInput);
                dateCell.innerHTML = '';
                dateCell.style.verticalAlign = 'middle';
                dateCell.appendChild(wrapper);
            }
        },

        /**
         * Issue 3: Find the "ADDITIONAL INFORMATION" section in the disclosure table
         * and replace empty rows with an editable textarea for signer input.
         */
        _processAdditionalInfoSection(table) {
            const rows = table.querySelectorAll('tbody tr');
            let inAdditionalInfo = false;
            let additionalInfoStartRow = null;
            const emptyRowsToRemove = [];

            rows.forEach((row, idx) => {
                const cells = row.querySelectorAll('td');
                if (cells.length === 1) {
                    const text = cells[0].textContent.trim().toUpperCase();
                    if (text === 'ADDITIONAL INFORMATION') {
                        inAdditionalInfo = true;
                        additionalInfoStartRow = row;
                        return;
                    }
                }

                if (inAdditionalInfo && cells.length === 1) {
                    const text = cells[0].textContent.trim();
                    // Check if this row has a field value (pre-filled by agent)
                    const fieldEl = cells[0].querySelector('[data-field="additional_information"]');
                    if (fieldEl) {
                        // Replace with textarea containing existing content
                        const existingText = fieldEl.textContent.trim();
                        this._insertOtherConditionsTextarea(cells[0], existingText);
                        inAdditionalInfo = false;
                        return;
                    }
                    // Empty row — mark for removal
                    if (!text) {
                        emptyRowsToRemove.push(row);
                    }
                }
            });

            // If we found the Additional Information header but no field element,
            // insert a textarea after the header row
            if (additionalInfoStartRow && inAdditionalInfo) {
                // Remove empty placeholder rows
                emptyRowsToRemove.forEach(r => r.remove());

                // Insert a new row with a textarea
                const newRow = document.createElement('tr');
                const newCell = document.createElement('td');
                newCell.setAttribute('colspan', '4');
                this._insertOtherConditionsTextarea(newCell, '');
                newRow.appendChild(newCell);

                // Insert after the header row
                if (additionalInfoStartRow.nextSibling) {
                    additionalInfoStartRow.parentNode.insertBefore(newRow, additionalInfoStartRow.nextSibling);
                } else {
                    additionalInfoStartRow.parentNode.appendChild(newRow);
                }
            }
        },

        _insertOtherConditionsTextarea(container, existingText) {
            const self = this;
            container.innerHTML = '';

            const textarea = document.createElement('textarea');
            textarea.style.cssText = 'width:100%;min-height:80px;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:12px;font-family:inherit;resize:vertical;';
            textarea.placeholder = 'Enter any additional information or conditions here...';
            textarea.value = existingText;
            self.otherConditionsText = existingText;

            textarea.addEventListener('input', () => {
                self.otherConditionsText = textarea.value;
            });

            container.appendChild(textarea);
        },

        // Signature canvas methods for web signing
        initWebSigCanvas() {
            const canvas = this.$refs.webSigCanvas;
            if (!canvas) return;
            this.webSigCtx = canvas.getContext('2d');
            this.webSigCtx.strokeStyle = '#1e293b';
            this.webSigCtx.lineWidth = 2;
            this.webSigCtx.lineCap = 'round';
            this.webSigCtx.lineJoin = 'round';
            this.clearWebSignature();
        },

        webStartDrawing(e) {
            this.webIsDrawing = true;
            const pos = this.getWebCanvasPos(e);
            this.webSigCtx.beginPath();
            this.webSigCtx.moveTo(pos.x, pos.y);
        },

        webDraw(e) {
            if (!this.webIsDrawing) return;
            const pos = this.getWebCanvasPos(e);
            this.webSigCtx.lineTo(pos.x, pos.y);
            this.webSigCtx.stroke();
        },

        webStopDrawing() {
            this.webIsDrawing = false;
        },

        getWebCanvasPos(e) {
            const canvas = this.$refs.webSigCanvas;
            const rect = canvas.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return {
                x: (clientX - rect.left) * (canvas.width / rect.width),
                y: (clientY - rect.top) * (canvas.height / rect.height),
            };
        },

        clearWebSignature() {
            if (this.webSigCtx) {
                const canvas = this.$refs.webSigCanvas;
                this.webSigCtx.clearRect(0, 0, canvas.width, canvas.height);
            }
            this.webTypedSignature = '';
        },

        applyWebSignature() {
            const canvas = this.$refs.webSigCanvas;
            let sigData;

            if (this.webSigMode === 'draw') {
                sigData = canvas.toDataURL('image/png');
                // Check if canvas is effectively blank
                const ctx = canvas.getContext('2d');
                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                const hasContent = imageData.data.some((val, idx) => idx % 4 === 3 && val > 0);
                if (!hasContent) {
                    this.showNotification('Please draw your signature first.', 'warning');
                    return;
                }
            } else {
                if (!this.webTypedSignature.trim()) {
                    this.showNotification('Please type your name.', 'warning');
                    return;
                }
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.font = '32px "Dancing Script", cursive';
                ctx.fillStyle = '#1e293b';
                ctx.fillText(this.webTypedSignature, 20, 90);
                sigData = canvas.toDataURL('image/png');
            }

            const sigId = this.currentWebSigBlockId;
            const isInitial = sigId && sigId.includes('-init-');

            this.webSignatures[sigId] = sigData;

            if (isInitial) {
                // Update the webInitialElements entry AND the DOM for the clicked initial
                const entry = (this.webInitialElements || []).find(e => e.initKey === sigId);
                if (entry) {
                    entry.signed = true;
                    entry.sigData = sigData;
                    entry.el.innerHTML = '<img src="' + sigData + '" style="max-height:26px;margin:auto;display:block;object-fit:contain;" alt="Initial">';
                    entry.el.classList.add('initial-signed');
                    entry.el.style.border = '2px solid #10b981';
                    entry.el.style.background = 'rgba(16,185,129,0.06)';
                    entry.el.style.cursor = 'default';
                }
            } else {
                // Update the signature element in the document via data-sig-id
                const container = this.$refs.webDocContent || null;
                if (container) {
                    const block = container.querySelector('[data-sig-id="' + sigId + '"]');
                    if (block) {
                        block.setAttribute('data-signed', 'true');
                        block.classList.add('web-sig-signed');
                        block.innerHTML = '<img src="' + sigData + '" class="web-sig-signed-img" alt="Signature">';
                    }
                }
            }

            this.showWebSigCapture = false;
            // updateIncompleteCount is the single source of truth for all counters
            this.updateIncompleteCount();

            // For initials: offer apply-to-all prompt if there are remaining unsigned initials
            if (isInitial) {
                const unsignedInitials = (this.webInitialElements || []).filter(e => e.isMine && !e.signed);
                if (unsignedInitials.length > 0 && !this.webInitialSigData) {
                    this.pendingInitialSigData = sigData;
                    this.pendingInitialBlockId = sigId;
                    this.showInitialApplyAll = true;
                } else {
                    this.showNotification('Initial applied.', 'info');
                }
            } else {
                this.showNotification('Signature applied.', 'info');
            }
        },

        // Apply initial to all remaining unsigned initial blocks
        applyInitialToAll() {
            const sigData = this.pendingInitialSigData;
            if (!sigData) { this.showInitialApplyAll = false; return; }
            this.webInitialSigData = sigData;
            const unsignedInitials = (this.webInitialElements || []).filter(e => e.isMine && !e.signed);
            unsignedInitials.forEach(entry => {
                entry.signed = true;
                entry.sigData = sigData;
                this.webSignatures[entry.initKey] = sigData;
                entry.el.innerHTML = '<img src="' + sigData + '" style="max-height:26px;margin:auto;display:block;object-fit:contain;" alt="Initial">';
                entry.el.classList.add('initial-signed');
                entry.el.style.border = '2px solid #10b981';
                entry.el.style.background = 'rgba(16,185,129,0.06)';
                entry.el.style.cursor = 'default';
            });
            this.showInitialApplyAll = false;
            this.pendingInitialSigData = null;
            // updateIncompleteCount is the single source of truth for all counters
            this.updateIncompleteCount();
            this.showNotification('Initial applied to all page breaks.', 'info');
        },

        // Decline apply-to-all — keep only the one that was just signed
        declineInitialApplyAll() {
            this.showInitialApplyAll = false;
            this.pendingInitialSigData = null;
            this.updateIncompleteCount();
            this.showNotification('Initial applied.', 'info');
        },

        // Collect web field values from inline inputs
        collectWebFieldValuesAll() {
            const container = this.$refs.webDocContent || null;
            if (!container) return {};
            const values = {};
            container.querySelectorAll('input.field-editable[data-field]').forEach(input => {
                values[input.name || input.getAttribute('data-field')] = input.value;
            });
            return values;
        },

        async completeWebSigning() {
            if (!this.canSubmitWeb) {
                if (!this.webConsented) {
                    this.showNotification('Please accept the consent checkbox to continue.', 'warning');
                    return;
                }
                const answeredRows = Object.keys(this.webDisclosureAnswers).filter(k => k.startsWith('disclosure_row_')).length;
                if (this.totalDisclosureRows > 0 && answeredRows < this.totalDisclosureRows) {
                    this.showNotification('Please complete all disclosure items before signing. (' + answeredRows + ' of ' + this.totalDisclosureRows + ' answered)', 'warning');
                    return;
                }
                // Show specific counts for what's remaining
                const items = this._computeIncompleteItems();
                const sigs = items.filter(i => i.label === 'Signature').length;
                const inits = items.filter(i => i.label === 'Page Initial').length;
                const ceremony = items.filter(i => !['Signature', 'Page Initial', 'Consent', 'Disclosure item'].includes(i.label)).length;
                const parts = [];
                if (sigs > 0) parts.push(sigs + ' signature' + (sigs > 1 ? 's' : ''));
                if (inits > 0) parts.push(inits + ' initial' + (inits > 1 ? 's' : ''));
                if (ceremony > 0) parts.push('signing location');
                if (parts.length > 0) {
                    this.showNotification(parts.join(', ') + ' remaining. Complete all before submitting.', 'warning');
                    this.scrollToNextIncomplete();
                } else {
                    this.showNotification('Please complete all required fields.', 'warning');
                }
                return;
            }

            if (this.completing) return;
            this.completing = true;

            // Collect field values
            const fieldValues = this.collectWebFieldValuesAll();

            try {
                const resp = await fetch('/sign/' + this.token + '/complete-web', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        field_values: fieldValues,
                        signatures: this.webSignatures,
                        disclosure_answers: this.webDisclosureAnswers,
                        ceremony_values: this.webCeremonyValues,
                        clause_flags: this.webClauseFlaggedItems,
                        other_conditions_text: this.otherConditionsText,
                        consented: this.webConsented,
                        consent_timestamp: new Date().toISOString(),
                        initials: Object.fromEntries(
                            (this.webInitialElements || [])
                                .filter(e => e.isMine && e.signed && e.sigData)
                                .map(e => [e.initKey, e.sigData])
                        ),
                    }),
                });
                const data = await resp.json();
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    this.showNotification(data.message || data.error || 'Could not complete signing. Please try again.', 'error');
                }
            } catch (err) {
                this.showNotification('Network error. Please check your connection and try again.', 'error');
                console.error('Web signing complete failed:', err);
            }
            this.completing = false;
        },

        // ── Decline ──
        async submitDecline() {
            this.declining = true;
            try {
                const resp = await fetch('/sign/' + this.token + '/decline', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ reason: this.declineReason }),
                });
                const data = await resp.json();
                if (data.ok) {
                    window.location.reload();
                }
            } catch (err) {
                alert('Failed to decline. Please try again.');
            }
            this.declining = false;
        },
    };
}

function uploadForm() {
    return {
        selectedFiles: [],
        dragover: false,
        uploading: false,

        handleFiles(event) {
            this.selectedFiles = Array.from(event.target.files);
        },

        handleDrop(event) {
            this.dragover = false;
            const dt = event.dataTransfer;
            if (dt.files.length) {
                this.$refs.fileInput.files = dt.files;
                this.selectedFiles = Array.from(dt.files);
            }
        },

        removeFile(index) {
            this.selectedFiles.splice(index, 1);
            // Reset file input
            const dt = new DataTransfer();
            this.selectedFiles.forEach(f => dt.items.add(f));
            this.$refs.fileInput.files = dt.files;
        },

        formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },
    };
}
</script>

</body>
</html>
