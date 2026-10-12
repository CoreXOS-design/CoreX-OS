<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign to collect your title deed</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
        main { max-width: 480px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 1px 4px rgba(15,23,42,.12); padding: 20px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .agency { font-size: 13px; color: #64748b; margin-bottom: 14px; }
        .prop { font-weight: 700; }
        label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin: 14px 0 4px; }
        input[type=text] { width: 100%; padding: 14px 12px; font-size: 16px; border: 1px solid #cbd5e1; border-radius: 10px; }
        canvas { width: 100%; height: 180px; display: block; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff; touch-action: none; }
        .clear { background: none; border: 0; color: #64748b; text-decoration: underline; font-size: 13px; padding: 6px 0; }
        .confirm { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; margin-top: 14px; }
        .confirm input { width: 22px; height: 22px; margin-top: 2px; }
        button.go { width: 100%; margin-top: 16px; padding: 16px; font-size: 17px; font-weight: 700; color: #fff; background: #276749; border: 0; border-radius: 12px; }
        button.go:disabled { opacity: .6; }
        .err { color: #b91c1c; font-size: 14px; margin-top: 10px; min-height: 1em; }
        .ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 14px; font-size: 15px; }
    </style>
</head>
<body>
<main>
    <div class="card">
    @if($state === 'open')
        <h1>Confirm and sign</h1>
        <div class="agency">{{ $agency }}</div>

        <form id="sign-form" onsubmit="return false;" autocomplete="off">
            <p style="font-size:16px; margin:0;">I, <strong>{{ $collector }}</strong>, confirm I am collecting the title deed for <span class="prop">{{ $property }}</span>.</p>

            <label for="typed_name">Your full name</label>
            <input id="typed_name" type="text" maxlength="191" autocomplete="name" placeholder="Type your full name">

            <label>Your signature - draw with your finger</label>
            <canvas id="pad" aria-label="Signature box"></canvas>
            <button type="button" class="clear" id="clear">Clear the signature</button>

            <label class="confirm" style="margin:14px 0 0; color:#0f172a; font-size:15px; font-weight:400;">
                <input type="checkbox" id="confirmed"> <span>I confirm that I am collecting this title deed.</span>
            </label>

            <div class="err" id="err" role="alert"></div>
            <button type="button" class="go" id="go">Confirm and sign</button>
            <div style="font-size:12px;color:#94a3b8;margin-top:10px;">This link works once and stops working at {{ $expires }}.</div>
        </form>

        @include('title-deeds._signature-pad')
        <script>
        (function () {
            var canvas = document.getElementById('pad');
            var pad = window.tdSignaturePad.attach(canvas, {});
            var err = document.getElementById('err'), go = document.getElementById('go');
            document.getElementById('clear').addEventListener('click', function () { pad.clear(); });
            go.addEventListener('click', async function () {
                err.textContent = '';
                var name = document.getElementById('typed_name').value.trim();
                if (name.length < 2) { err.textContent = 'Please type your full name.'; return; }
                if (!pad.ready()) { err.textContent = 'The signature box could not start. Please reload this page.'; return; }
                if (pad.isEmpty()) { err.textContent = 'Please draw your signature in the box.'; return; }
                if (!document.getElementById('confirmed').checked) { err.textContent = 'Please tick the box to confirm.'; return; }
                go.disabled = true; go.textContent = 'Saving...';
                try {
                    var res = await fetch(@json(route('title-deeds.sign.submit', $token)), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ typed_name: name, signature_image: pad.toDataURL(), confirmed: true }),
                    });
                    var data = await res.json().catch(function () { return {}; });
                    if (res.status === 201 || data.state === 'signed') {
                        document.getElementById('sign-form').innerHTML = '<div class="ok" id="done">' + (data.message || 'Thank you - your signature is recorded.') + '</div>';
                        return;
                    }
                    err.textContent = data.message || 'That did not save. Please try again.';
                    if (data.state && data.state !== 'error') { document.getElementById('sign-form').innerHTML = '<div class="err" style="font-size:16px;">' + err.textContent + '</div>'; return; }
                } catch (e) {
                    err.textContent = 'We could not reach the server. Please check your connection and try again.';
                }
                go.disabled = false; go.textContent = 'Confirm';
            });
        })();
        </script>
    @elseif($state === 'signed')
        <h1>Thank you</h1>
        <div class="ok" id="done">You have signed. The staff member will finish the handover now.</div>
    @elseif($state === 'used')
        <h1>Already done</h1>
        <p>This title deed has already been handed over. Thank you.</p>
    @elseif($state === 'expired')
        <h1>This link has run out</h1>
        <p>Please ask the staff member for a new one.</p>
    @elseif($state === 'cancelled')
        <h1>This link was cancelled</h1>
        <p>Please ask the staff member for a new one.</p>
    @else
        <h1>This link is not available</h1>
    @endif
    </div>
</main>
</body>
</html>
