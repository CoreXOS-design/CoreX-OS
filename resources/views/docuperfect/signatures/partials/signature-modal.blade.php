{{--
    Signature Capture Modal — Draw or Type mode.
    Used inside an Alpine.js component that provides:
      - showSignModal (bool)
      - activeMarker (object|null)
      - captureMode ('draw'|'type')
      - typedName (string)
      - signaturePad (SignaturePad instance)
      - applySignature()
      - clearCanvas()
--}}

{{-- Google Font for typed signatures --}}
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<div x-show="showSignModal" x-cloak x-transition.opacity
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="background:rgba(0,0,0,0.6);"
     @keydown.escape.window="showSignModal = false">

    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>

        {{-- Header --}}
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
                        :class="captureMode === 'draw' ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                    Draw
                </button>
                <button @click="captureMode = 'type'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        :class="captureMode === 'type' ? 'bg-cyan-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                    Type
                </button>
            </div>

            {{-- DRAW MODE --}}
            <div x-show="captureMode === 'draw'" x-transition>
                <div class="border-2 border-slate-300 rounded-xl bg-white overflow-hidden"
                     style="touch-action:none;">
                    <canvas x-ref="signatureCanvas"
                            class="w-full block"
                            style="height:160px; cursor:crosshair;"></canvas>
                </div>
                <div class="flex justify-between items-center mt-2">
                    <button @click="clearCanvas()"
                            class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                        Clear
                    </button>
                    <span class="text-xs text-slate-400">Draw your signature above</span>
                </div>
            </div>

            {{-- TYPE MODE --}}
            <div x-show="captureMode === 'type'" x-transition>
                <div class="mb-3">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Type your name</label>
                    <input type="text" x-model="typedName"
                           class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500"
                           :placeholder="activeMarker && activeMarker.type === 'initial' ? 'Your initials (e.g. MV)' : 'Your full name'">
                </div>
                <div class="border-2 border-slate-200 rounded-xl bg-slate-50 p-4 min-h-[80px] flex items-center">
                    <template x-if="typedName.trim()">
                        <span class="text-4xl text-slate-800" style="font-family:'Dancing Script',cursive;" x-text="typedName"></span>
                    </template>
                    <template x-if="!typedName.trim()">
                        <span class="text-sm text-slate-400 italic">Preview will appear here</span>
                    </template>
                </div>
                {{-- Hidden canvas for typed signature rendering --}}
                <canvas x-ref="typedCanvas" class="hidden" width="400" height="100"></canvas>
            </div>

            {{-- Consent text --}}
            <p class="text-xs text-slate-500 leading-relaxed">
                By signing, you confirm your identity and consent to this document.
                Your signature will be recorded with a timestamp and IP address.
            </p>

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3 pt-2">
                <button @click="showSignModal = false"
                        class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                    Cancel
                </button>
                <button @click="applySignature()"
                        class="nexus-btn-primary text-sm px-6 py-2.5"
                        :disabled="applying"
                        :class="applying ? 'opacity-50 cursor-not-allowed' : ''">
                    <span x-show="!applying">Apply Signature</span>
                    <span x-show="applying" x-cloak>Applying...</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Apply-to-all confirmation modal --}}
<div x-show="showApplyAll" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center"
     style="background:rgba(0,0,0,0.5);">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full mx-4 p-6 space-y-4" @click.stop>
        <h3 class="text-lg font-semibold text-slate-800">Apply to Remaining Markers?</h3>
        <p class="text-sm text-slate-600">
            You signed this marker. Apply the same signature to your remaining
            <span class="font-semibold" x-text="remainingSignatureCount"></span>
            signature marker<span x-show="remainingSignatureCount !== 1">s</span>?
        </p>
        <p class="text-xs text-slate-400">
            Initials and date fields still need to be signed separately.
        </p>
        <div class="flex items-center justify-end gap-3 pt-2">
            <button @click="showApplyAll = false; lastSignatureData = null;"
                    class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                No, I'll Sign Each One
            </button>
            <button @click="applyToAllSignatureMarkers()"
                    class="nexus-btn-primary text-sm px-6 py-2.5"
                    :disabled="applyingAll"
                    :class="applyingAll ? 'opacity-50 cursor-not-allowed' : ''">
                <span x-show="!applyingAll">Yes, Apply to All</span>
                <span x-show="applyingAll" x-cloak>Applying...</span>
            </button>
        </div>
    </div>
</div>
