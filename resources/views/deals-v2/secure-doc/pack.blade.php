<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure documents{{ $rows->count() > 1 ? ' ('.$rows->count().')' : '' }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #eef2f7; color: #1a2733; margin: 0; padding: 0; }
        .card { max-width: 460px; margin: 48px auto; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.08); overflow: hidden; }
        .head { background: #0b2a4a; color: #fff; padding: 22px 26px; }
        .head h1 { margin: 0; font-size: 18px; }
        .head p { margin: 6px 0 0; font-size: 13px; opacity: .85; }
        .body { padding: 26px; }
        .msg { padding: 11px 13px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
        .ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        label { display:block; font-size: 12px; color: #64748b; margin-bottom: 6px; }
        input[type=text] { width: 100%; box-sizing: border-box; padding: 11px 12px; font-size: 18px; letter-spacing: 3px; text-align:center; border: 1px solid #cbd5e1; border-radius: 8px; }
        button { width: 100%; padding: 12px; font-size: 15px; font-weight: bold; color: #fff; background: #0b2a4a; border: none; border-radius: 8px; cursor: pointer; margin-top: 14px; }
        .muted { font-size: 12px; color: #94a3b8; margin-top: 16px; line-height: 1.5; }
        .doc { display:flex; align-items:center; justify-content:space-between; gap:12px; padding: 12px 0; border-bottom: 1px solid #eef2f7; font-size: 14px; }
        .doc:last-child { border-bottom: none; }
        .doc .name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .doc a { text-decoration:none; color:#0b2a4a; font-weight:bold; font-size:13px; white-space:nowrap; }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <h1>{{ $rows->count() > 1 ? $rows->count().' secure documents' : 'Secure document' }}</h1>
            <p>{{ $rep->document->documentType->label ?? 'Documents' }} shared with you</p>
        </div>
        <div class="body">
            @if(session('status'))
                <div class="msg ok">{{ session('status') }}</div>
            @endif
            @if(session('otp_error'))
                <div class="msg err">{{ session('otp_error') }}</div>
            @endif

            @if($verified || ! $otpRequired)
                <p>@if($otpRequired)Your identity is verified. @endif You can now download {{ $rows->count() > 1 ? 'these documents' : 'this document' }}:</p>
                <div>
                    @foreach($rows as $row)
                        <div class="doc">
                            <span class="name">{{ $row->document->original_name ?? ($row->document->documentType->label ?? 'Document') }}</span>
                            <a href="{{ route('deals-v2.secure-doc.pack.download', ['groupKey' => $groupKey, 'distribution' => $row->id]) }}">Download</a>
                        </div>
                    @endforeach
                </div>
            @elseif(session('otp_sent'))
                <p>Enter the one-time PIN we emailed to <strong>{{ \Illuminate\Support\Str::mask($rep->recipient_email, '*', 2, max(0, strpos($rep->recipient_email,'@') - 3)) }}</strong>. It unlocks all {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('document', $rows->count()) }}.</p>
                <form method="POST" action="{{ route('deals-v2.secure-doc.pack.verify', $groupKey) }}">
                    @csrf
                    <label for="code">One-time PIN</label>
                    <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                    <button type="submit">Verify &amp; continue</button>
                </form>
                <form method="POST" action="{{ route('deals-v2.secure-doc.pack.otp', $groupKey) }}" style="margin-top:10px;">
                    @csrf
                    <button type="submit" style="background:#e2e8f0; color:#0b2a4a;">Resend PIN</button>
                </form>
            @else
                <p>For your protection, we'll email one PIN to your address that unlocks all {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('document', $rows->count()) }}.</p>
                <form method="POST" action="{{ route('deals-v2.secure-doc.pack.otp', $groupKey) }}">
                    @csrf
                    <button type="submit">Email me a PIN</button>
                </form>
            @endif

            <p class="muted">This link is unique to you — please don't forward it. Every access is logged for compliance.</p>
        </div>
    </div>
</body>
</html>
