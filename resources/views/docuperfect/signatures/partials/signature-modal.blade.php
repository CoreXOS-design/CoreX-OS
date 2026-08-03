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

{{-- Capture modal — THE single component (agent in-app: markers + web-sig + conditions).
     Defaults map to showSignModal/captureMode/typedName/applySignature (pad variant). --}}
@include('docuperfect.signatures.partials._capture-modal')

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
                    class="corex-btn-primary text-sm px-6 py-2.5"
                    :disabled="applyingAll"
                    :class="applyingAll ? 'opacity-50 cursor-not-allowed' : ''">
                <span x-show="!applyingAll">Yes, Apply to All</span>
                <span x-show="applyingAll" x-cloak>Applying...</span>
            </button>
        </div>
    </div>
</div>
