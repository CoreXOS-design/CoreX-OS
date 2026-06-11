<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FICA Submitted — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        body { font-family: 'Figtree', sans-serif; background: #f1f5f9; }
        .conf-btn { display: inline-block; width: 100%; max-width: 320px; padding: 0.75rem 1.5rem; font-weight: 600; font-size: 0.9375rem; text-decoration: none; text-align: center; margin-bottom: 0.75rem; border-radius: 6px; }
        /* Public page loads only app.css (no corex.css) — bare var(--text-primary)
           is undefined here and renders an invisible button. Use the F.7
           var(--token, #fallback) DS pattern, same as the .fica-btn fix. */
        .conf-btn-primary { background: var(--brand-button, #0ea5e9); color: #fff; box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button, #0ea5e9) 25%, transparent); }
        .conf-btn-primary:hover { filter: brightness(1.1); box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button, #0ea5e9) 35%, transparent); }
        .conf-btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
        .conf-btn-secondary:hover { background: #e2e8f0; }
    </style>
</head>
<body>
    <div class="max-w-xl mx-auto px-4 py-16 text-center">
        <div style="background: #fff; border: 1px solid #e2e8f0; padding: 3rem 2rem;">
            @if($agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height: 50px; margin: 0 auto 1.5rem;">
            @endif

            <div style="width: 64px; height: 64px; background: #d1fae5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#059669" style="width: 32px; height: 32px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>

            <h1 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary, #111827); margin: 0 0 0.75rem;">FICA Form Submitted</h1>

            @if(!empty($returnUrl))
                <p style="color: #64748b; font-size: 0.9375rem; line-height: 1.7; max-width: 400px; margin: 0 auto;">
                    Thank you for completing your FICA verification. Your submission will be reviewed by your agent. Once approved, you will be able to sign your document.
                </p>

                <div style="margin-top: 2rem;">
                    <a href="{{ $returnUrl }}" class="conf-btn conf-btn-primary">Return to Document</a>
                </div>

                <p style="color: #94a3b8; font-size: 0.8rem; margin-top: 0.5rem; line-height: 1.5;">
                    If your FICA is still being reviewed, you will see a status page. You will receive an email when your document is ready to sign.
                </p>
            @else
                <p style="color: #64748b; font-size: 0.9375rem; line-height: 1.7; max-width: 400px; margin: 0 auto;">
                    Thank you for completing your FICA verification form. Your submission has been received and will be reviewed by your agent.
                </p>

                <p style="color: #64748b; font-size: 0.875rem; margin-top: 1.5rem;">
                    You may close this window.
                </p>
            @endif
        </div>
    </div>
@include('public.partials.privacy-footer')
</body>
</html>
