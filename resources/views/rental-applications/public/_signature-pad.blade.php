{{--
    AT-392 — lean, self-contained signature capture (plain canvas +
    toDataURL), deliberately NOT the full e-sign capture modal
    (_capture-modal.blade.php): that component is tightly coupled to the
    e-sign Alpine state machine and the SignaturePad library load, and this
    is a simple two-signature intake form, not a multi-marker ceremony.
    Produces the same payload shape the backend expects either way:
    a `data:image/png;base64,...` string in the named hidden input.
--}}
@php $canvasId = 'sig-canvas-' . $field; $oldSignature = old($field); @endphp
{{--
    Johan, QA1 — "no user action may EVER discard typed input." A
    validation failure on an UNRELATED field used to wipe both signature
    canvases on redisplay: nothing restored the drawing, and the hidden
    input carried no old() either, so a re-drawn signature was needed even
    though the applicant had already signed. old() now seeds the hidden
    input (so the DATA survives a resubmit even untouched) and, if present,
    is drawn back onto the canvas on load so the applicant sees their own
    signature still there, not a blank pad.
--}}
<div class="border-2 border-slate-300 rounded-xl bg-white overflow-hidden" style="touch-action:none;">
    <canvas id="{{ $canvasId }}" width="600" height="150" class="w-full block" style="height:120px; cursor:crosshair;"></canvas>
</div>
<div class="flex justify-between items-center mt-1 mb-4">
    <button type="button" onclick="clearRentalSignature('{{ $field }}')" class="text-xs text-slate-500 hover:text-slate-700 font-medium px-3 py-3 -mx-3 -my-3 inline-flex items-center">Clear</button>
    <span class="text-xs text-slate-400">Draw your {{ $label }} signature above</span>
</div>

<script>
(function () {
    function init() {
        const canvas = document.getElementById('{{ $canvasId }}');
        if (!canvas || canvas.dataset.rentalSigInit) return;
        canvas.dataset.rentalSigInit = '1';

        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
        const ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#1a365d';

        const oldSignature = @js($oldSignature ?: '');
        if (oldSignature) {
            const img = new Image();
            img.onload = () => ctx.drawImage(img, 0, 0, canvas.clientWidth, canvas.clientHeight);
            img.src = oldSignature;
        }

        let drawing = false;

        function pos(e) {
            const rect = canvas.getBoundingClientRect();
            const point = e.touches ? e.touches[0] : e;
            return { x: point.clientX - rect.left, y: point.clientY - rect.top };
        }
        function start(e) { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            const p = pos(e);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            syncRentalSignature('{{ $field }}', canvas);
        }
        function stop() { drawing = false; }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', stop);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', stop);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

function syncRentalSignature(field, canvas) {
    const input = document.querySelector('[name="' + field + '"]');
    if (input) input.value = canvas.toDataURL('image/png');
}

function clearRentalSignature(field) {
    const canvasId = 'sig-canvas-' + field;
    const canvas = document.getElementById(canvasId);
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const input = document.querySelector('[name="' + field + '"]');
    if (input) input.value = '';
}
</script>
