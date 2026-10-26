{{--
    THE single signature/initial capture modal — Draw or Type, with typed preview
    and the consent line. ONE component; every signing surface renders THIS file so
    the recipient never sees two different modals in one flow (AT — Johan 2026-08-03).

    Rendered at three sites, each passing the Alpine state/handler names it owns:
      • resources/views/docuperfect/signatures/external/sign.blade.php  (recipient) ×2
          – showSignModal     (markers + conditions)  → variant 'pad'    (SignaturePad)
          – showWebSigCapture (inline web-sig blocks)  → variant 'manual' (raw canvas)
      • resources/views/docuperfect/signatures/partials/signature-modal.blade.php
          – showSignModal (agent in-app)              → variant 'pad'

    The two capture ENGINES stay distinct on purpose (the web-sig path keeps its
    uniform-size typed render + disclosure-amendment handling); only the MODAL
    MARKUP is unified here. The condition-initial empty-seed lives in each host's
    corex-open-condition-initial handler and is unaffected by this partial.

    Params (defaults suit the showSignModal/pad case):
      $show, $mode, $typed, $apply, $clear, $init, $canvasRef, $variant('pad'|'manual'),
      $title (Alpine expr), $placeholder (Alpine expr), $showTypedCanvas (bool)
--}}
@php
    $show            = $show            ?? 'showSignModal';
    $mode            = $mode            ?? 'captureMode';
    $typed           = $typed           ?? 'typedName';
    $apply           = $apply           ?? 'applySignature';
    $clear           = $clear           ?? 'clearCanvas';
    $init            = $init            ?? 'initCanvas';
    $canvasRef       = $canvasRef       ?? 'signatureCanvas';
    $variant         = $variant         ?? 'pad';
    $title           = $title           ?? "activeMarker ? ('Sign: ' + markerLabel(activeMarker) + (activeMarker.page_number ? ' — Page ' + activeMarker.page_number : '')) : 'Sign Here'";
    $placeholder     = $placeholder     ?? "activeMarker && activeMarker.type === 'initial' ? 'Your initials' : 'Your full name'";
    $showTypedCanvas = $showTypedCanvas ?? ($variant === 'pad');
    // Saved-signature support — only the e-sign signing surface passes this true.
    // When false the saved markup is not rendered at all (other consumers untouched).
    $savedSignatureSupport = $savedSignatureSupport ?? false;
    // The saved-tab handler + "is this an initial marker?" expression differ per host modal:
    //   • showSignModal (markers/conditions): chooseSavedSignature + activeMarker.type
    //   • showWebSigCapture (inline web-sig blocks): chooseSavedSignatureWeb + currentWebSigBlockId
    // so the saved option works in BOTH the modals the ceremony actually opens.
    $chooseSaved    = $chooseSaved    ?? 'chooseSavedSignature';
    $savedIsInitial = $savedIsInitial ?? "activeMarker && activeMarker.type === 'initial'";
@endphp

{{-- Google Font for typed signatures (idempotent — browser dedupes) --}}
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">

<div x-show="{{ $show }}" x-cloak x-transition.opacity
     class="fixed inset-0 z-50 flex items-center justify-center"
     style="background:rgba(0,0,0,0.6);"
     @keydown.escape.window="{{ $show }} = false">
    <div class="corex-capture-sheet bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden" @click.stop>

        {{-- Header --}}
        <div class="corex-capture-head px-6 py-4 border-b border-slate-200" style="background:#0b2a4a;">
            <h3 class="text-white font-semibold text-lg" x-text="{{ $title }}"></h3>
        </div>

        <div class="corex-capture-body p-6 space-y-4">

            {{-- Mode tabs --}}
            <div class="flex gap-2">
                <button @click="{{ $mode }} = 'draw'; $nextTick(() => {{ $init }}())"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        :class="{{ $mode }} === 'draw' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                    Draw
                </button>
                <button @click="{{ $mode }} = 'type'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        :class="{{ $mode }} === 'type' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                    Type
                </button>
                @if($savedSignatureSupport)
                {{-- Agent-only: place the saved signature (unlock once with the signing PIN). --}}
                <button x-show="savedSigConfigured && !savedSigImpersonating" @click="{{ $chooseSaved }}()"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                        :class="{{ $mode }} === 'saved' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
                    <span x-text="savedSigUnlocked ? 'Use my saved signature' : 'Use my saved signature 🔒'"></span>
                </button>
                @endif
            </div>

            {{-- Draw mode --}}
            <div x-show="{{ $mode }} === 'draw'" x-transition>
                <div class="corex-capture-pad border-2 border-slate-300 rounded-xl bg-white overflow-hidden" style="touch-action:none;">
                    @if($variant === 'manual')
                        <canvas x-ref="{{ $canvasRef }}"
                                width="400" height="150"
                                class="w-full block" style="height:160px; cursor:crosshair;"
                                @mousedown="webStartDrawing($event)"
                                @mousemove="webDraw($event)"
                                @mouseup="webStopDrawing()"
                                @mouseleave="webStopDrawing()"
                                @touchstart.prevent="webStartDrawing($event)"
                                @touchmove.prevent="webDraw($event)"
                                @touchend="webStopDrawing()"></canvas>
                    @else
                        <canvas x-ref="{{ $canvasRef }}" data-corex-pad="pad" class="w-full block" style="height:160px; cursor:crosshair;"></canvas>
                    @endif
                </div>
                <div class="flex justify-between items-center mt-2">
                    <button @click="{{ $clear }}()" class="text-sm text-slate-500 hover:text-slate-700 font-medium">Clear</button>
                    <span class="text-xs text-slate-400">Draw your signature above</span>
                </div>
            </div>

            {{-- Type mode — FIX 1 (Johan 2026-08-06): the TYPE INPUT is the prominent, obvious click target
                 (large, bordered, auto-focused); the rendered PREVIEW is de-emphasised (small, dashed, clearly
                 labelled) so it never looks like where you act. --}}
            <div x-show="{{ $mode }} === 'type'" x-transition>
                <div class="mb-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1"
                           x-text="activeMarker && activeMarker.type === 'initial' ? 'Type your initials here' : 'Type your full name here'"></label>
                    <input type="text" x-model="{{ $typed }}"
                           x-effect="if ({{ $mode }} === 'type' && {{ $show }}) $nextTick(() => $el.focus())"
                           class="w-full rounded-lg border-2 border-blue-500 text-xl font-medium text-slate-800 px-4 py-3 shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-600"
                           :placeholder="{{ $placeholder }}">
                </div>
                <span class="block text-[11px] uppercase tracking-wide text-slate-400 mb-1">Preview</span>
                <div class="border border-dashed border-slate-200 rounded-lg bg-slate-50/60 px-3 py-2 min-h-[44px] flex items-center">
                    <template x-if="{{ $typed }}.trim()">
                        <span class="text-2xl text-slate-500" style="font-family:'Dancing Script',cursive;" x-text="{{ $typed }}"></span>
                    </template>
                    <template x-if="!{{ $typed }}.trim()">
                        <span class="text-xs text-slate-300 italic">Your typed signature appears here</span>
                    </template>
                </div>
                @if($showTypedCanvas)
                    {{-- Hidden canvas for typed-signature rendering (pad variant) --}}
                    <canvas x-ref="typedCanvas" class="hidden" width="400" height="100"></canvas>
                @endif
            </div>

            @if($savedSignatureSupport)
            {{-- Saved-signature mode — place the agent's unlocked saved signature/initial. --}}
            <div x-show="{{ $mode }} === 'saved'" x-transition>
                <div class="border-2 border-slate-200 rounded-xl bg-slate-50 p-3 flex items-center justify-center" style="min-height:120px;">
                    <template x-if="({{ $savedIsInitial }}) ? savedInitialImg : savedSignatureImg">
                        <img :src="({{ $savedIsInitial }}) ? savedInitialImg : savedSignatureImg"
                             alt="Your saved mark" style="max-height:120px; max-width:100%;">
                    </template>
                    <template x-if="!(({{ $savedIsInitial }}) ? savedInitialImg : savedSignatureImg)">
                        <span class="text-xs text-slate-400">Unlock your saved signature to place it.</span>
                    </template>
                </div>
                <p class="text-xs text-slate-500 mt-2">
                    Your saved <span x-text="({{ $savedIsInitial }}) ? 'initial' : 'signature'"></span> will be placed.
                    Click Apply — you can then place it on the rest without re-entering your PIN.
                </p>
            </div>
            @endif

            {{-- Consent line --}}
            <p class="text-xs text-slate-500 leading-relaxed">
                By signing, you confirm your identity and consent to this document.
                Your signature will be recorded with a timestamp and IP address.
            </p>

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3 pt-2">
                <button @click="{{ $show }} = false"
                        class="px-4 py-2.5 text-sm text-slate-600 hover:text-slate-800 font-medium">
                    Cancel
                </button>
                <button @click="{{ $apply }}()"
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

{{-- Landscape pad (e-sign signing modes, build step 6). A phone held sideways is ~320-430px tall: the portrait sheet (header,
     tabs, 160px pad, consent line, buttons) overflowed it and the Apply button fell off the screen. In a short landscape
     viewport the sheet becomes wide and compact, the pad takes the height that is left, and the sheet scrolls rather than
     clip. The pad's drawing surface is re-fitted when the phone rotates (below), keeping the ink already drawn. One copy of
     this block per page: the partial is rendered more than once and the rules are idempotent. --}}
<style>
    .corex-capture-sheet { max-height: 100vh; max-height: 100dvh; overflow-y: auto !important; }
    @media (orientation: landscape) and (max-height: 540px) {
        .corex-capture-sheet { max-width: min(46rem, calc(100vw - 1rem)); margin-left: .5rem; margin-right: .5rem; }
        .corex-capture-head { padding: .5rem 1rem; }
        .corex-capture-head h3 { font-size: 1rem; line-height: 1.25rem; }
        .corex-capture-body { padding: .75rem 1rem; }
        .corex-capture-body > * + * { margin-top: .5rem; }
        .corex-capture-pad canvas { height: clamp(96px, calc(100dvh - 230px), 220px) !important; }
    }
</style>
<script>
(function () {
    if (window.__corexPadFit) return;
    window.__corexPadFit = true;
    var timer = null;

    // The pad's bitmap is sized to its on-screen box when it opens (initCanvas). After a rotation the box is a different size:
    // re-fit it and carry the strokes across, scaled to the new box, so a signer who turns the phone loses nothing.
    function refit() {
        document.querySelectorAll('canvas[data-corex-pad]').forEach(function (c) {
            try {
                if (!c.offsetWidth) return;                      // modal closed
                var root = c.closest('[x-data]');
                var d = root && window.Alpine && Alpine.$data ? Alpine.$data(root) : null;
                if (!d || !d.signaturePad || typeof d.initCanvas !== 'function') return;
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                var oldW = c.width / ratio, oldH = c.height / ratio;
                var newW = c.offsetWidth, newH = c.offsetHeight;
                if (Math.abs(oldW - newW) < 2 && Math.abs(oldH - newH) < 2) return;
                var strokes = d.signaturePad.toData();
                d.initCanvas();
                if (strokes && strokes.length && oldW > 0 && oldH > 0) {
                    var sx = newW / oldW, sy = newH / oldH;
                    strokes.forEach(function (g) { (g.points || []).forEach(function (p) { p.x *= sx; p.y *= sy; }); });
                    d.signaturePad.fromData(strokes);
                }
            } catch (e) { /* a failed re-fit leaves the pad exactly as it was */ }
        });
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(refit, 250); }
    window.addEventListener('orientationchange', schedule);
    window.addEventListener('resize', schedule);
})();
</script>
