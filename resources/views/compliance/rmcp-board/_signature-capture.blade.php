{{--
    The signature step of the RMCP board approval letter: the same Type / Draw choice and the same signature_pad library the
    staff RMCP acknowledgement uses (compliance/rmcp-ack/sign), written without Alpine so it also works on the public
    emailed-link page, which has no app layout. Posts a plain form: signature_type, signature_data | typed_name, declaration_acknowledged.
    Needs: $action (POST url), $agencyName.
--}}
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
<form method="POST" action="{{ $action }}" id="boardSignForm" style="margin-top:18px;">
    @csrf
    <input type="hidden" name="signature_type" id="bsType" value="typed">
    <input type="hidden" name="signature_data" id="bsData">

    <h3 style="font-size:15px; margin:0 0 10px;">Your signature</h3>
    <div style="display:flex; gap:8px; margin-bottom:12px;">
        <button type="button" id="bsTabTyped" class="bs-tab bs-tab-on">Type</button>
        <button type="button" id="bsTabDrawn" class="bs-tab">Draw</button>
    </div>

    <div id="bsTyped">
        <input type="text" name="typed_name" id="bsName" maxlength="200" autocomplete="name" placeholder="Type your full name" value="{{ old('typed_name') }}" class="bs-input">
        <div id="bsPreview" style="display:none; margin-top:10px; padding:10px; text-align:center; border:1px dashed #cbd5e1; border-radius:6px;">
            <span id="bsPreviewText" style="font-family:'Dancing Script',cursive; font-size:1.6rem;"></span>
        </div>
    </div>

    <div id="bsDrawn" style="display:none;">
        <div style="border:2px solid #cbd5e1; border-radius:6px; overflow:hidden; background:#fff;">
            <canvas id="bsCanvas" style="width:100%; height:180px; display:block; touch-action:none; cursor:crosshair;"></canvas>
        </div>
        <div style="display:flex; justify-content:space-between; margin-top:6px; font-size:12px; color:#64748b;">
            <button type="button" id="bsClear" style="background:none; border:0; color:#64748b; font-weight:600; cursor:pointer; padding:8px 0;">Clear</button>
            <span>Draw your signature above</span>
        </div>
    </div>

    <label style="display:flex; gap:12px; align-items:flex-start; margin:18px 0 6px; cursor:pointer; font-size:13px; line-height:1.5;">
        <input type="checkbox" name="declaration_acknowledged" value="1" id="bsAck" style="width:22px; height:22px; margin-top:1px; flex-shrink:0;">
        <span>I have read the letter above, and I sign it on behalf of the board / senior management of {{ $agencyName }}.</span>
    </label>

    <button type="submit" id="bsSubmit" disabled class="bs-submit">Sign the letter</button>
</form>
<style>
    .bs-tab { padding:9px 18px; font-size:13px; font-weight:600; border:0; border-radius:6px; background:#f1f5f9; color:#64748b; cursor:pointer; }
    .bs-tab-on { background:#0b2a4a; color:#fff; }
    .bs-input { width:100%; box-sizing:border-box; padding:11px 12px; font-size:15px; border:1px solid #cbd5e1; border-radius:8px; }
    .bs-submit { margin-top:12px; padding:13px 26px; font-size:15px; font-weight:700; color:#fff; background:#0b2a4a; border:0; border-radius:8px; cursor:pointer; }
    .bs-submit:disabled { background:#e2e8f0; color:#94a3b8; cursor:not-allowed; }
</style>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<script>
(function () {
    var mode = 'typed', pad = null, drawn = false;
    var $ = function (id) { return document.getElementById(id); };
    var typed = $('bsName'), ack = $('bsAck'), submit = $('bsSubmit');

    function refresh() {
        var has = mode === 'typed' ? typed.value.trim().length > 0 : drawn;
        submit.disabled = !(has && ack.checked);
    }
    function initPad() {
        if (pad || typeof SignaturePad === 'undefined') return;
        var c = $('bsCanvas'), ratio = Math.max(window.devicePixelRatio || 1, 1);
        c.width = c.offsetWidth * ratio; c.height = 180 * ratio; c.getContext('2d').scale(ratio, ratio);
        pad = new SignaturePad(c, { backgroundColor: 'rgb(255,255,255)', penColor: 'rgb(15,23,42)', minWidth: 1.5, maxWidth: 3 });
        pad.addEventListener('endStroke', function () { drawn = !pad.isEmpty(); refresh(); });
    }
    function setMode(m) {
        mode = m;
        $('bsType').value = m;
        $('bsTyped').style.display = m === 'typed' ? '' : 'none';
        $('bsDrawn').style.display = m === 'drawn' ? '' : 'none';
        $('bsTabTyped').className = 'bs-tab' + (m === 'typed' ? ' bs-tab-on' : '');
        $('bsTabDrawn').className = 'bs-tab' + (m === 'drawn' ? ' bs-tab-on' : '');
        if (m === 'drawn') initPad();
        refresh();
    }
    $('bsTabTyped').addEventListener('click', function () { setMode('typed'); });
    $('bsTabDrawn').addEventListener('click', function () { setMode('drawn'); });
    $('bsClear').addEventListener('click', function () { if (pad) { pad.clear(); drawn = false; refresh(); } });
    typed.addEventListener('input', function () {
        $('bsPreview').style.display = typed.value.trim() ? '' : 'none';
        $('bsPreviewText').textContent = typed.value;
        refresh();
    });
    ack.addEventListener('change', refresh);
    $('boardSignForm').addEventListener('submit', function () {
        if (mode === 'drawn' && pad) { $('bsData').value = pad.toDataURL('image/png'); }
    });
    refresh();
})();
</script>
