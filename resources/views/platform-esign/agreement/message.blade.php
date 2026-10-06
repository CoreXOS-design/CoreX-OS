<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>CoreX OS Subscription Agreement</title>
<style>
    body { margin:0; background:#e8ecf2; font-family:'Figtree',-apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#0f172a; }
    .card { max-width: 560px; margin: 12vh auto 0; background:#fff; border:1px solid #d9dee8; border-radius:10px; padding: 2rem 1.75rem; text-align:center; }
    .brand { font-size:1.6rem; font-weight:800; color:#0b2a4a; letter-spacing:-.03em; } .brand span { color:#00b4d8; }
    a.btn { display:inline-block; margin-top:1rem; background:#00b4d8; color:#fff; text-decoration:none; padding:.6rem 1.2rem; border-radius:6px; font-weight:600; }
</style></head>
<body><div class="card">
    <div class="brand">CoreX <span>OS</span></div>
    <h1 style="font-size:1.15rem;margin:1rem 0 .5rem;">CoreX OS Subscription Agreement</h1>
    <p style="color:#475569;line-height:1.55;margin:0;">{{ $message }}</p>
    @if(!empty($canDownload))<a class="btn" href="{{ route('platform-esign.agreement.download', $token) }}">Download your signed copy</a>@endif
</div></body></html>
