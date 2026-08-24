<!DOCTYPE html>
<html lang="en">
@php
    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    // Same convention as shared/match-expired.blade.php — kept consistent on purpose.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';

    $isSold = $reason === 'sold';
    $title  = $isSold ? 'This property has sold' : 'This property is no longer available';
    $body   = $isSold
        ? 'The link you followed pointed to a listing that has since sold. It is no longer being marketed.'
        : 'The link you followed pointed to a property listing that no longer exists.';
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}{{ !empty($agency) ? ' — ' . $agency->name : '' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --border: rgba(0,0,0,0.07);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);
            border-radius: 8px; padding: 0.625rem 1.125rem; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
        }
        .btn-primary:hover { box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent); transform: translateY(-1px); }
        .btn-outline {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--surface); color: var(--text-secondary);
            border: 1px solid var(--border); border-radius: 8px;
            padding: 0.625rem 1.125rem; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
        }
        .btn-outline:hover { border-color: var(--brand-button); color: var(--brand-button); }
    </style>
</head>
<body>

    <header style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-2xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if(!empty($agency) && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}"
                     style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Property Report' }}</div>
            @endif
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 lg:px-6 py-16">
        <div class="surface-card px-8 py-10 text-center">
            <div class="w-14 h-14 rounded-full mx-auto mb-5 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent);">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="var(--brand-icon)" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>

            <h1 class="text-xl font-bold mb-2" style="color: var(--text-primary);">{{ $title }}</h1>
            <p class="text-sm mb-8" style="color: var(--text-secondary);">{{ $body }}</p>

            @if($isSold && !empty($agency) && $agency->slug)
                <a href="{{ route('public.agency.properties.index', ['agencySlug' => $agency->slug]) }}" class="btn-primary w-full sm:w-auto">
                    Looking for something similar? View current listings
                </a>
            @endif

            <div class="mt-8 pt-8 flex flex-col sm:flex-row items-center justify-center gap-3" style="border-top: 1px solid var(--border);">
                @if(!empty($agency) && $agency->website_url)
                    <a href="{{ $agency->website_url }}" class="btn-outline w-full sm:w-auto" target="_blank" rel="noopener">
                        Visit our site
                    </a>
                @endif
                @if($agent)
                    <a href="tel:{{ $agent->cell ?? $agent->phone }}" class="btn-outline w-full sm:w-auto">
                        Call {{ $agent->name }}
                    </a>
                @elseif($fallbackPhone)
                    <a href="tel:{{ $fallbackPhone }}" class="btn-outline w-full sm:w-auto">
                        Call {{ $agency->name ?? 'us' }}
                    </a>
                @elseif($fallbackEmail)
                    <a href="mailto:{{ $fallbackEmail }}" class="btn-outline w-full sm:w-auto">
                        Email {{ $agency->name ?? 'us' }}
                    </a>
                @endif
            </div>
        </div>
    </main>

</body>
</html>
