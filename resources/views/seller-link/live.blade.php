{{--
    Seller Live Link — "Live Marketing Update" (public, token-gated)
    Styled to match the Core Match client page / buyer portal: agency-branded tokens,
    Figtree, surface-card / ds-badge styling, branded gradient hero + shared agent
    card and company footer. All data sections preserved.
--}}
@php
    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';

    $agent = $link->generatedBy ?? null;

    $daysListed = $compliance['days_on_market'] ?? null;
    $published  = $compliance['published'] ?? false;
    $mandateExp = $compliance['mandate_expired'] ?? true;

    $stats = collect([
        ['v' => $feedbackRollup['total_viewings'] ?? 0,        'l' => 'Viewings'],
        ['v' => $daysListed !== null ? $daysListed : '—',      'l' => 'Days listed'],
    ]);
    if (!empty($marketPosition)) {
        $stats->push(['v' => 'R ' . number_format($marketPosition['recommended_price'] ?? 0), 'l' => 'Market value']);
        $stats->push(['v' => 'R ' . number_format($marketPosition['area_avg_price'] ?? 0),    'l' => 'Area average']);
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $property->title ?? 'Property' }} — Live Marketing Update{{ !empty($agency) && $agency->name ? ' · ' . $agency->name : '' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            --border: rgba(0,0,0,0.07);
            --border-hover: rgba(0,0,0,0.14);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            /* Agency brand colours — injected from Company Settings */
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .ds-badge {
            display: inline-flex; align-items: center; gap: .3rem; white-space: nowrap;
            border-radius: 9999px; padding: 0.2rem 0.6rem;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 28%, transparent); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 28%, transparent); }
    </style>
</head>
<body class="min-h-screen">

    {{-- Top bar --}}
    <header class="sticky top-0 z-30" style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-4xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if($agency && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Marketing Update' }}</div>
            @endif
            <span class="ds-badge" style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">Live update</span>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 lg:px-6 py-6 space-y-6">

        {{-- Hero --}}
        <section class="rounded-xl px-6 py-6 relative overflow-hidden"
                 style="background: linear-gradient(135deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 82%, #000) 100%);">
            <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full opacity-20"
                 style="background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%);"></div>
            <div class="relative flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em]" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">Live marketing update</p>
                    <h1 class="text-2xl font-extrabold leading-tight text-white mt-1">{{ $property->title ?? 'Your Property' }}</h1>
                    <p class="text-sm mt-1" style="color: rgba(255,255,255,0.65);">Hi {{ $seller->first_name ?? 'there' }}, here's what's happening with your listing.</p>
                    <p class="text-[0.6875rem] mt-2" style="color: rgba(255,255,255,0.4);">Last refreshed: <span id="refresh-time">just now</span></p>
                </div>

                @if($agent)
                <div class="flex items-center gap-3 flex-shrink-0 rounded-lg px-3.5 py-2.5"
                     style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0" style="background: var(--brand-icon);">
                        {{ strtoupper(substr($agent->name, 0, 2)) }}
                    </div>
                    <div class="text-left">
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.55);">Your Agent</div>
                        <div class="text-sm font-semibold text-white leading-tight">{{ $agent->name }}</div>
                    </div>
                </div>
                @endif
            </div>
        </section>

        {{-- Performance stats --}}
        <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($stats as $s)
            <div class="surface-card p-4 text-center">
                <div class="text-xl font-extrabold" style="color: var(--brand-default);">{{ $s['v'] }}</div>
                <div class="text-[0.625rem] font-semibold uppercase tracking-wider mt-1" style="color: var(--text-muted);">{{ $s['l'] }}</div>
            </div>
            @endforeach
        </section>

        {{-- Agent Insights (seller-facing recommendations) --}}
        @if($recommendations->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Agent insights</h2>
            <div class="space-y-3">
                @foreach($recommendations as $rec)
                    <div class="flex items-start gap-3">
                        <span class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" style="background: var(--brand-icon);"></span>
                        <div>
                            <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $rec->seller_facing_title }}</div>
                            @if($rec->seller_facing_reasoning)
                                <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $rec->seller_facing_reasoning }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Viewing feedback summary --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Viewing feedback</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">Total viewings</span>
                    <span class="font-semibold" style="color: var(--text-primary);">{{ $feedbackRollup['total_viewings'] ?? 0 }}</span>
                </div>
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">Feedback captured</span>
                    <span class="font-semibold" style="color: var(--text-primary);">{{ $feedbackRollup['total_feedback_rows'] ?? 0 }}</span>
                </div>
            </div>
        </section>

        {{-- Marketing activity --}}
        @if($marketing->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Marketing activity</h2>
            <div class="space-y-2.5">
                @foreach($marketing as $ma)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="text-xs w-16 flex-shrink-0 font-medium" style="color: var(--text-muted);">{{ $ma->occurred_at->format('d M') }}</span>
                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background: var(--brand-icon);"></span>
                        <span style="color: var(--text-secondary);">{{ str_replace('_', ' ', ucfirst($ma->activity_type)) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Comparable listings --}}
        @if($comparables->isNotEmpty())
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Similar properties in your area</h2>
            <div class="divide-y" style="border-color: var(--border);">
                @foreach($comparables as $comp)
                    <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span style="color: var(--text-secondary);">{{ $comp['title'] }} <span class="text-xs" style="color: var(--text-muted);">{{ $comp['suburb'] }}</span></span>
                        <span class="font-semibold flex-shrink-0" style="color: var(--brand-default);">R {{ number_format($comp['price'] ?? 0) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- Presentation --}}
        @if($presentations->isNotEmpty())
        @php $latest = $presentations->first(); @endphp
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-1" style="color: var(--text-primary);">Market presentation</h2>
            <p class="text-sm" style="color: var(--text-secondary);">{{ $latest->title }}</p>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Generated {{ \Carbon\Carbon::parse($latest->created_at)->format('d M Y') }}</p>
        </section>
        @endif

        {{-- Listing status --}}
        <section class="surface-card p-5">
            <h2 class="text-base font-bold mb-3" style="color: var(--text-primary);">Listing status</h2>
            <div class="flex flex-wrap gap-2">
                <span class="ds-badge {{ $published ? 'ds-badge-success' : 'ds-badge-warning' }}">
                    Listing: {{ $published ? 'Active' : 'Unpublished' }}
                </span>
                <span class="ds-badge {{ $mandateExp ? 'ds-badge-warning' : 'ds-badge-success' }}">
                    Mandate: {{ $mandateExp ? 'Review needed' : 'Active' }}
                </span>
            </div>
        </section>

        {{-- Agent card — who to call (shared public-page component) --}}
        @include('public.shared._agent-card', ['agent' => $agent, 'agency' => $agency, 'heading' => 'Your agent'])

    </main>

    {{-- Company footer (shared public-page component) --}}
    @include('public.shared._company-footer', ['agency' => $agency])

    <script>
        // Auto-refresh indicator — reloads the live update every 60s.
        setInterval(function () {
            var el = document.getElementById('refresh-time');
            if (el) el.textContent = 'refreshing…';
            setTimeout(function () { window.location.reload(); }, 500);
        }, 60000);
    </script>
</body>
</html>
