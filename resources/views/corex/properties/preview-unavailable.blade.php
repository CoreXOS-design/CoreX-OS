<!DOCTYPE html>
<html lang="en">
@php
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing no longer available{{ !empty($agency) ? ' — ' . $agency->name : '' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #f4f6fb; --surface: #ffffff; --border: rgba(0,0,0,0.07);
            --text-primary: #111827; --text-secondary: #4b5563;
            --brand-default: {{ $brandDefault }}; --brand-icon: {{ $brandIcon }}; --brand-button: {{ $brandButton }};
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);
            border-radius: 8px; padding: 0.625rem 1.125rem; font-size: 0.875rem; font-weight: 600;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
        }
    </style>
</head>
<body>
    <header style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-2xl mx-auto px-4 lg:px-6 py-3.5">
            @if(!empty($agency) && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Property Listings' }}</div>
            @endif
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 lg:px-6 py-16">
        <div class="surface-card px-8 py-10 text-center">
            <div class="w-14 h-14 rounded-full mx-auto mb-5 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="var(--brand-icon)" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
            </div>
            <h1 class="text-xl font-bold mb-2" style="color: var(--text-primary);">This listing is no longer available</h1>
            <p class="text-sm mb-8" style="color: var(--text-secondary);">
                It may have sold, been withdrawn, or is temporarily off the market.
            </p>

            @if(!empty($agency) && $agency->website_url)
                <a href="{{ $agency->website_url }}" class="btn-primary" target="_blank" rel="noopener">
                    Browse current listings
                </a>
            @endif
        </div>
    </main>
</body>
</html>
