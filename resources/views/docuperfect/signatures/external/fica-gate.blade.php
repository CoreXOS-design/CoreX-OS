<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FICA Verification Required</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Figtree', sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .gate-card { background: #fff; border: 1px solid #e2e8f0; max-width: 480px; width: 100%; padding: 2.5rem; text-align: center; }
        .gate-card img { max-height: 50px; margin-bottom: 1.5rem; }
        .gate-icon { width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; }
        .gate-icon svg { width: 32px; height: 32px; }
        .gate-icon-warn { background: #fef3c7; }
        .gate-icon-warn svg { color: #d97706; }
        .gate-icon-info { background: #dbeafe; }
        .gate-icon-info svg { color: #2563eb; }
        h1 { font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-bottom: 0.75rem; }
        .gate-text { font-size: 0.9375rem; color: #64748b; line-height: 1.6; margin-bottom: 1.5rem; }
        .gate-btn { display: inline-block; width: 100%; padding: 0.875rem; font-weight: 600; font-size: 0.9375rem; text-decoration: none; text-align: center; margin-bottom: 0.75rem; border: none; cursor: pointer; }
        .gate-btn-primary { background: {{ $agencyColor ?? '#0f172a' }}; color: #fff; }
        .gate-btn-primary:hover { opacity: 0.9; }
        .gate-btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
        .gate-btn-secondary:hover { background: #e2e8f0; }
        .gate-footer { font-size: 0.8rem; color: #94a3b8; margin-top: 1.5rem; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="gate-card">
        @if(!empty($agencyLogo))
            <img src="{{ asset('storage/' . $agencyLogo) }}" alt="{{ $agencyName }}">
        @endif

        @if(($ficaStatus ?? 'none') === 'pending_review')
            {{-- FICA submitted, awaiting agent/CO review --}}
            <div class="gate-icon gate-icon-info">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </div>

            <h1>FICA Verification Under Review</h1>

            <p class="gate-text">
                Your FICA form has been submitted and is being reviewed. You will receive an email when your document is ready to sign.
            </p>

            <a href="{{ $signingUrl ?? url()->current() }}" class="gate-btn gate-btn-secondary">
                Check again
            </a>
        @else
            {{-- No FICA submitted yet — needs to complete the form --}}
            <div class="gate-icon gate-icon-warn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                </svg>
            </div>

            <h1>FICA Verification Required</h1>

            <p class="gate-text">
                Before you can sign this document, you must complete your FICA (Financial Intelligence Centre Act) verification. This is a legal requirement for property transactions in South Africa.
            </p>

            @if($ficaUrl)
                <a href="{{ $ficaUrl }}" class="gate-btn gate-btn-primary">Complete FICA Form</a>
            @else
                <p class="gate-text" style="color: #dc2626; font-weight: 600;">
                    Please contact your agent to receive your FICA verification form.
                </p>
            @endif

            <a href="{{ $signingUrl ?? url()->current() }}" class="gate-btn gate-btn-secondary">
                I've already submitted my FICA — check again
            </a>
        @endif

        <div class="gate-footer">
            <p>Once your FICA verification has been approved, you will be able to proceed to sign the document.</p>
            <p style="margin-top: 0.5rem;">{{ $agencyName ?? 'Home Finders Coastal' }}</p>
        </div>
    </div>
</body>
</html>
