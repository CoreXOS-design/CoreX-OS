<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Letterhead — {{ $agency->trading_name ?? $agency->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; }
        .page { padding: 40px 48px; min-height: 700px; position: relative; }
        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #0d9488; padding-bottom: 16px; margin-bottom: 24px; }
        .header img.logo { max-height: 70px; }
        .header .agency-name { font-size: 18px; font-weight: 700; color: #0f172a; }
        .header .agency-legal { font-size: 11px; color: #64748b; }
        .body-area { min-height: 460px; }
        .footer { position: absolute; bottom: 40px; left: 48px; right: 48px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 10px; color: #94a3b8; }
        .footer .ppra { font-weight: 700; color: #334155; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="agency-name">{{ $agency->trading_name ?? $agency->name }}</div>
            @if($agency->trading_name && $agency->trading_name !== $agency->name)
                <div class="agency-legal">{{ $agency->name }}</div>
            @endif
        </div>
        @if($agency->logo_path)
            <img class="logo" src="{{ public_path('storage/' . $agency->logo_path) }}" alt="Logo">
        @endif
    </div>

    <div class="body-area"></div>

    <div class="footer">
        <div>
            {{ $agency->trading_name ?? $agency->name }}
            @if($agency->address) &bull; {{ $agency->address }} @endif
        </div>
        <div>
            @if($agency->phone){{ $agency->phone }} @endif
            @if($agency->email) &bull; {{ $agency->email }} @endif
            @if($agency->ppra_number) &bull; <span class="ppra">PPRA Reg. No: {{ $agency->ppra_number }}</span> @endif
        </div>
        @if($agency->email_disclaimer)
            <div style="margin-top:6px;">{{ $agency->email_disclaimer }}</div>
        @endif
        @if($agency->popi_url)
            <div style="margin-top:4px;">Privacy policy: {{ $agency->popi_url }}</div>
        @endif
    </div>
</div>
</body>
</html>
