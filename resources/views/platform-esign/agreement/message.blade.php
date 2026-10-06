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
    @if(session('agr_notice'))<p style="color:#166534;font-weight:600;">{{ session('agr_notice') }}</p>@endif
    @if($errors->any())<p style="color:#b91c1c;">{{ $errors->first() }}</p>@endif
    @if(!empty($canReplaceUpload))
        <div style="text-align:left;margin-top:1rem;font-size:.9rem;">
            <div style="font-weight:600;margin-bottom:.3rem;">Received from you</div>
            <ul style="margin:0 0 .8rem 1.1rem;padding:0;color:#334155;">@foreach($files as $f)<li>{{ $f->original_name }} · {{ number_format($f->size / 1024, 0) }} KB · {{ $f->created_at?->format('j M Y H:i') }}</li>@endforeach</ul>
            <form method="POST" action="{{ route('platform-esign.agreement.upload', $token) }}" enctype="multipart/form-data">@csrf
                <label style="display:block;margin-bottom:.4rem;">Replace it with a new signed copy (PDF, JPG or PNG, up to 10 MB each)</label>
                <input type="file" name="files[]" multiple required accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png">
                <button type="submit" style="margin-top:.5rem;background:#00b4d8;color:#fff;border:0;padding:.5rem 1rem;border-radius:6px;font-weight:600;cursor:pointer;">Upload new copy</button>
            </form>
        </div>
    @endif
    @if(!empty($canDownload))<a class="btn" href="{{ route('platform-esign.agreement.download', $token) }}">Download your signed copy</a>@endif
</div></body></html>
