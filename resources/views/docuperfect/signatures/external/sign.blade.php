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
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
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
                        <span x-text="signedCount"></span> / <span x-text="totalRequired"></span> markers signed
                    </span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-2.5">
                    <div class="bg-emerald-500 h-2.5 rounded-full transition-all duration-500"
                         :style="'width:' + (totalRequired > 0 ? Math.round((signedCount / totalRequired) * 100) : 0) + '%'"></div>
                </div>
            </div>

            {{-- Document viewer --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 overflow-hidden flex flex-col" style="min-height:600px;">

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

                        {{-- Render document field values (read-only overlay) — only when NOT flattened --}}
                        <template x-if="!hasFlattened">
                            <template x-for="field in fieldsForCurrentPage()" :key="field.id">
                                <div class="absolute pointer-events-none overflow-hidden"
                                     :style="`left:${field.position.x}%;top:${field.position.y}%;width:${field.size.width}%;height:${field.size.height}%;z-index:5;`">
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
                        </template>

                        {{-- Render markers for current page --}}
                        <template x-for="marker in markersForCurrentPage()" :key="marker.id">
                            {{-- When flattened, skip other parties' markers (baked in) and skip already-signed own markers --}}
                            <div x-show="!hasFlattened || (marker.is_mine && !marker.signed)"
                                 class="absolute flex items-center justify-center select-none transition-all duration-200"
                                 :id="'marker-' + marker.id"
                                 :style="`left:${marker.x_position}%;top:${marker.y_position}%;width:${marker.width}%;height:${marker.height}%;z-index:10;`"
                                 :class="markerDisplayClasses(marker)"
                                 @click="handleMarkerClick(marker)">

                                {{-- My unsigned marker (clickable) --}}
                                <template x-if="marker.is_mine && !marker.signed">
                                    <div class="flex flex-col items-center justify-center w-full h-full px-1">
                                        <span class="text-xs font-bold leading-tight truncate">Sign Here</span>
                                        <span class="text-[10px] leading-tight opacity-70 truncate" x-text="markerTypeLabel(marker)"></span>
                                    </div>
                                </template>

                                {{-- My signed marker (only shown when NOT flattened — when flattened, sig is baked in) --}}
                                <template x-if="marker.is_mine && marker.signed && !hasFlattened">
                                    <div class="flex flex-col items-center justify-center w-full h-full relative">
                                        <template x-if="marker.signature_data && marker.type !== 'date'">
                                            <img :src="marker.signature_data"
                                                 class="w-full h-full object-contain p-0.5"
                                                 alt="Signature">
                                        </template>
                                        <template x-if="marker.type === 'date'">
                                            <span class="text-xs font-medium" x-text="marker.date_value || formatDate(new Date())"></span>
                                        </template>
                                        <span class="absolute -bottom-0.5 right-0.5 text-[9px] text-emerald-700 font-semibold">Signed</span>
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

            {{-- Complete Signing --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 flex items-center justify-between">
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

            {{-- Floating progress bar for unsigned markers --}}
            <div x-show="signedCount < totalRequired && totalRequired > 0" x-cloak x-transition
                 class="fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-white shadow-lg rounded-full px-6 py-3 flex items-center gap-3 z-40 border border-slate-200">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-slate-700" x-text="`${signedCount} of ${totalRequired} signed`"></span>
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
        </div>
    </template>

    {{-- ══════════════════════════════════════════════
         WET INK PATH
    ══════════════════════════════════════════════ --}}
    <template x-if="signingMethod === 'wet_ink'">
        <div class="space-y-4">

            {{-- Step 1: Download --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-semibold text-slate-800 mb-2">Step 1: Download the Document</h2>
                <p class="text-sm text-slate-500 mb-4">
                    Download the document, print it, and sign in all marked positions by hand.
                </p>
                <a href="{{ route('signatures.external.download', $token) }}"
                   class="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white transition-colors"
                   style="background:#0b2a4a;"
                   onmouseover="this.style.background='#163d63'" onmouseout="this.style.background='#0b2a4a'">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Download Document for Signing
                </a>
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
        'date_value' => $sig && $m->type === 'date' ? $sig->signed_at : null,
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
        currentPage: 1,
        totalPages: {{ $pageCount }},
        signedCount: {{ $signedCount }},
        totalRequired: {{ $totalMarkers }},
        token: @json($token),
        partyRole: @json($request->party_role),
        signerName: @json($request->signer_name),

        // State
        signingMethod: @json($request->signing_method),
        wetInkPendingReview: {{ $wetInkPendingReview ? 'true' : 'false' }},
        showSignModal: false,
        activeMarker: null,
        captureMode: 'draw',
        typedName: @json($request->signer_name),
        applying: false,
        completing: false,
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

        init() {
            this.firstSignatureDone = this.markers.some(m => m.is_mine && m.signed);

            // Listen for method reset from wet ink back button
            this.$el.addEventListener('reset-method', () => {
                this.signingMethod = null;
            });
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
            return (this.documentFields || []).filter(f => f.pageIndex === pageIdx);
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

        // ── Marker display ──
        markerLabel(m) {
            const partyLabel = m.assigned_party.replace('_', ' ');
            const typeLabel = m.type.charAt(0).toUpperCase() + m.type.slice(1);
            return partyLabel.charAt(0).toUpperCase() + partyLabel.slice(1) + ' ' + typeLabel;
        },

        markerTypeLabel(m) {
            return m.type.charAt(0).toUpperCase() + m.type.slice(1);
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

        generateTypedSignature(name) {
            const canvas = this.$refs.typedCanvas;
            if (!canvas) return null;
            canvas.width = 400;
            canvas.height = 100;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.font = '48px "Dancing Script", cursive';
            ctx.fillStyle = '#000000';
            ctx.textBaseline = 'middle';
            ctx.fillText(name, 10, 50);
            return canvas.toDataURL('image/png');
        },

        // ── Date marker ──
        async signDateMarker(marker) {
            const dateStr = this.formatDate(new Date());
            const canvas = document.createElement('canvas');
            canvas.width = 200; canvas.height = 40;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, 200, 40);
            ctx.font = '16px sans-serif';
            ctx.fillStyle = '#000000';
            ctx.textBaseline = 'middle';
            ctx.fillText(dateStr, 5, 20);
            const dataUrl = canvas.toDataURL('image/png');
            await this.submitSignature(marker, dataUrl, 'typed');
            marker.date_value = dateStr;
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
                signatureData = this.generateTypedSignature(this.typedName.trim());
                signatureType = 'typed';
            }

            const success = await this.submitSignature(this.activeMarker, signatureData, signatureType);

            if (success) {
                this.showSignModal = false;

                const remainingSigMarkers = this.markers.filter(m =>
                    m.is_mine && !m.signed && m.type === 'signature'
                );

                if (!this.firstSignatureDone && this.activeMarker.type === 'signature' && remainingSigMarkers.length > 0) {
                    this.lastSignatureData = signatureData;
                    this.lastSignatureType = signatureType;
                    this.showApplyAll = true;
                }

                this.firstSignatureDone = true;
            }

            this.applying = false;
        },

        // ── Submit signature ──
        async submitSignature(marker, signatureData, signatureType) {
            try {
                const resp = await fetch('/sign/' + this.token + '/capture/' + marker.id, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        signature_data: signatureData,
                        signature_type: signatureType,
                    }),
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

        // ── Apply to all ──
        async applyToAllSignatureMarkers() {
            this.applyingAll = true;

            const remaining = this.markers.filter(m =>
                m.is_mine && !m.signed && m.type === 'signature'
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
            return this.markers.filter(m => m.is_mine && !m.signed && m.type === 'signature').length;
        },
        set remainingSignatureCount(v) {},

        // ── Complete signing (with guided navigation if unsigned remain) ──
        async completeSigning() {
            // Check locally first — guide to unsigned markers
            const unsignedMarkers = this.markers.filter(m => m.is_mine && !m.signed);
            if (unsignedMarkers.length > 0) {
                const first = unsignedMarkers[0];
                const typeLabel = first.type === 'initial' ? 'initial' : 'sign';
                this.showNotification(
                    `Please ${typeLabel} here — ${unsignedMarkers.length} remaining`,
                    'warning'
                );
                this.navigateToMarker(first);
                return;
            }

            if (this.completing) return;
            this.completing = true;
            try {
                const resp = await fetch('/sign/' + this.token + '/complete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const data = await resp.json();
                if (data.ok && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    this.showNotification(data.error || 'Could not complete signing. Please try again.', 'error');
                }
            } catch (err) {
                this.showNotification('Network error. Please check your connection and try again.', 'error');
                console.error('Complete signing failed:', err);
            }
            this.completing = false;
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
