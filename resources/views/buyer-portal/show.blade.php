{{--
    Buyer Portal — "Your Property Matches" (public, token-gated)  AT-204
    Styled to match the Core Match client page (resources/views/shared/match.blade.php):
    agency-branded tokens, Figtree, surface-card / ds-badge / btn styling, branded
    gradient hero + footer. Features preserved exactly (honest match-% basis + the
    three buyer actions). Mobile-first (~390px, opened from WhatsApp).
--}}
@php
    use Illuminate\Support\Str;

    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: ($brand['colors']['default'] ?? '#0b2a4a');
    $brandIcon    = optional($agency)->icon_color    ?: ($brand['colors']['icon']   ?? '#00b4d8');
    $brandButton  = optional($agency)->button_color  ?: ($brand['colors']['button'] ?? '#00b4d8');

    $brief       = $primaryMatch ? $primaryMatch->presentBrief() : [];
    $basisText   = $primaryMatch ? $primaryMatch->matchBasisText() : '';
    $basisLabels = $primaryMatch ? $primaryMatch->matchBasisLabels() : [];
    $agentFirst  = !empty($agent) ? (Str::of($agent->name)->explode(' ')->first() ?: 'your agent') : 'your agent';

    // Plain-English quality word for a score (STANDARDS F.8) — paired with the
    // honest basis so a "100%" is never shown context-free.
    $scoreWord = function (int $s): string {
        if ($s >= 90) return 'Excellent match';
        if ($s >= 80) return 'Strong match';
        return 'Possible match';
    };

    $best   = $matches->where('tier', 'perfect')->values();
    $strong = $matches->where('tier', 'strong')->values();
    $approx = $matches->where('tier', 'approximate')->values();
    $totalMatches = $matches->count();

    $logoUrl    = $brand['logoUrl'] ?? null;
    $buyerInits = strtoupper(substr((string) $buyer->first_name, 0, 1) . substr((string) $buyer->last_name, 0, 1));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your Property Matches{{ !empty($agency) && $agency->name ? ' — ' . $agency->name : '' }}</title>
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
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
            background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);
            border-radius: 8px; font-size: 0.8125rem; font-weight: 700; cursor: pointer;
            transition: all 200ms ease; box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
        }
        .btn-primary:hover { box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent); transform: translateY(-1px); }
        .btn-outline {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
            background: var(--surface); color: var(--text-muted); border: 1px solid var(--border);
            border-radius: 8px; font-size: 0.8125rem; font-weight: 600; cursor: pointer; transition: all 200ms ease;
        }
        .btn-outline:hover { border-color: var(--border-hover); color: var(--text-secondary); }
        .ds-badge {
            display: inline-flex; align-items: center; gap: .3rem; white-space: nowrap;
            border-radius: 9999px; padding: 0.125rem 0.5rem;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 28%, transparent); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 28%, transparent); }
        .ds-badge-info    { background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); border-color: color-mix(in srgb, var(--brand-icon) 28%, transparent); }
    </style>
</head>
<body class="min-h-screen">

    {{-- Top bar --}}
    <header class="sticky top-0 z-30" style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-3xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $agency->name ?? 'Agency' }}" style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Property Matches' }}</div>
            @endif
            <span class="ds-badge" style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">Your matches</span>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 lg:px-6 py-6 space-y-6">

        {{-- Hero — personalised greeting + agent --}}
        <section class="rounded-xl px-6 py-6 relative overflow-hidden"
                 style="background: linear-gradient(135deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 82%, #000) 100%);">
            <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full opacity-20"
                 style="background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%);"></div>
            <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center flex-shrink-0 text-lg font-bold text-white shadow-lg"
                         style="background: var(--brand-icon);">
                        {{ $buyerInits ?: '·' }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em]" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">Handpicked for you</p>
                        <h1 class="text-2xl font-extrabold leading-tight text-white mt-0.5">Hi {{ $buyer->first_name ?? 'there' }}</h1>
                        <p class="text-sm" style="color: rgba(255,255,255,0.65);">
                            @if($totalMatches > 0)
                                {{ $totalMatches }} {{ $totalMatches === 1 ? 'property' : 'properties' }} we think you'll like.
                            @else
                                We're on the lookout for properties that fit you.
                            @endif
                        </p>
                    </div>
                </div>

                @if(!empty($agent))
                <div class="flex items-center gap-3 flex-shrink-0 rounded-lg px-3.5 py-2.5"
                     style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                         style="background: var(--brand-icon);">
                        {{ strtoupper(substr($agent->name, 0, 2)) }}
                    </div>
                    <div class="text-left">
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.55);">Your Agent</div>
                        <div class="text-sm font-semibold text-white leading-tight">{{ $agent->name }}</div>
                        @if($agent->cell || $agent->phone)
                        <a href="tel:{{ $agent->cell ?? $agent->phone }}" class="text-xs font-medium" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">
                            {{ $agent->cell ?? $agent->phone }}
                        </a>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </section>

        {{-- Flash success (after a response) --}}
        @if(session('success'))
        <div role="status" class="rounded-lg px-4 py-3 text-sm font-semibold"
             style="background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--brand-icon); color: var(--brand-default);">
            {{ session('success') }}
        </div>
        @endif

        {{-- Honest preferences brief --}}
        @if(!empty($brief))
        <section class="surface-card p-5">
            <div class="text-xs font-bold uppercase tracking-wider mb-3" style="color: var(--text-muted);">What you're looking for</div>
            <div class="space-y-2">
                @foreach($brief as $row)
                <div class="flex items-baseline justify-between gap-4">
                    <span class="text-sm" style="color: var(--text-muted);">{{ $row['label'] }}</span>
                    <span class="text-sm font-semibold text-right" style="color: var(--text-primary);">{{ $row['value'] }}</span>
                </div>
                @endforeach
            </div>
            @if(count($basisLabels) > 0 && count($basisLabels) <= 2)
            <div class="mt-3 pt-3 text-xs leading-relaxed" style="border-top: 1px dashed var(--border); color: var(--text-secondary);">
                These matches are scored on {{ $basisText }} — the {{ count($basisLabels) === 1 ? 'preference' : 'preferences' }} you've shared so far.
                Tell {{ $agentFirst }} more (area, bedrooms, must-haves) and your matches get sharper.
            </div>
            @endif
        </section>
        @endif

        {{-- Matches or zero-state --}}
        @if($totalMatches === 0)
            <section class="surface-card py-12 px-6 text-center">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z"/></svg>
                </div>
                <h2 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matches just yet</h2>
                <p class="text-sm mx-auto" style="color: var(--text-muted); max-width: 24rem; line-height: 1.5;">
                    New listings come in every day. As soon as something fits {{ !empty($brief) ? 'your brief' : 'what you\'re after' }}, it'll appear right here.
                    @if(!empty($agent)) Chat to {{ $agentFirst }} below to fine-tune what you're looking for. @endif
                </p>
            </section>
        @else
            @php
                $sections = [
                    ['label' => 'Your best matches', 'rows' => $best],
                    ['label' => 'Strong matches',    'rows' => $strong],
                ];
            @endphp
            @foreach($sections as $sec)
                @if($sec['rows']->isNotEmpty())
                <section>
                    <h2 class="text-lg font-bold mb-3 flex items-center gap-2" style="color: var(--text-primary);">
                        {{ $sec['label'] }}
                        <span class="ds-badge ds-badge-info">{{ $sec['rows']->count() }}</span>
                    </h2>
                    <div class="space-y-3">
                        @foreach($sec['rows'] as $match)
                            @php $prop = $properties[$match->property_id] ?? null; @endphp
                            @if($prop)
                                @include('buyer-portal._property-card', [
                                    'prop' => $prop, 'match' => $match,
                                    'resp' => $responses[$match->property_id] ?? null,
                                    'token' => $token, 'basisText' => $basisText, 'scoreWord' => $scoreWord,
                                ])
                            @endif
                        @endforeach
                    </div>
                </section>
                @endif
            @endforeach

            {{-- More to consider — collapsed by default --}}
            @if($approx->isNotEmpty())
            <details>
                <summary class="cursor-pointer text-base font-bold" style="color: var(--text-secondary); list-style: none;">
                    + {{ $approx->count() }} more {{ $approx->count() === 1 ? 'property' : 'properties' }} worth a look
                </summary>
                <div class="space-y-3 mt-3">
                    @foreach($approx as $match)
                        @php $prop = $properties[$match->property_id] ?? null; @endphp
                        @if($prop)
                            @include('buyer-portal._property-card', [
                                'prop' => $prop, 'match' => $match,
                                'resp' => $responses[$match->property_id] ?? null,
                                'token' => $token, 'basisText' => $basisText, 'scoreWord' => $scoreWord,
                            ])
                        @endif
                    @endforeach
                </div>
            </details>
            @endif
        @endif

        {{-- Agent card — who to call (shared public-page component) --}}
        @include('public.shared._agent-card', ['agent' => $agent, 'agency' => $agency, 'heading' => 'Your agent'])

    </main>

    {{-- Company footer (shared public-page component) --}}
    @include('public.shared._company-footer', ['agency' => $agency])

</body>
</html>
