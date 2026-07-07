{{--
    Buyer Portal — "Your Property Matches" (public, token-gated)  AT-204 redesign
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (agency-branded tokens, var(--token,#fallback))

    The buyer's personal property feed: branded header, honest preferences brief,
    photo match cards with an HONEST match-% basis, the three buyer actions
    (Interested / Not Interested / Request Viewing) preserved exactly, and the
    agent card + company footer (shared public-page components) so the buyer
    always knows who to call. Mobile-first (~390px, opened from WhatsApp).
--}}
@php
    $colors = $brand['colors'] ?? [];
    $cBrand  = $colors['default'] ?? '#0b2a4a';
    $cButton = $colors['button']  ?? '#00b4d8';
    $cIcon   = $colors['icon']    ?? '#33c4e0';

    $brief       = $primaryMatch ? $primaryMatch->presentBrief() : [];
    $basisText   = $primaryMatch ? $primaryMatch->matchBasisText() : '';
    $basisLabels = $primaryMatch ? $primaryMatch->matchBasisLabels() : [];
    $agentFirst  = !empty($agent) ? (\Illuminate\Support\Str::of($agent->name)->explode(' ')->first() ?: 'your agent') : 'your agent';

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
            --brand-default: {{ $cBrand }};
            --brand-button: {{ $cButton }};
            --brand-icon: {{ $cIcon }};
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            --border: rgba(0,0,0,0.08);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 0 1rem; }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; }
    </style>
</head>
<body class="min-h-screen">

    {{-- Branded header — fixed agency logo bar --}}
    <header style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="wrap" style="display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding-top:.875rem; padding-bottom:.875rem;">
            @if(!empty($brand['logoUrl']))
                <img src="{{ $brand['logoUrl'] }}" alt="{{ $agency->name ?? 'Agency' }}" style="max-height:38px; max-width:190px; object-fit:contain;">
            @else
                <div style="font-size:1.05rem; font-weight:800; letter-spacing:-.01em; color:#fff;">{{ $agency->name ?? 'Property Matches' }}</div>
            @endif
            <span style="font-size:.625rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#fff; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18); padding:.25rem .5rem; border-radius:9999px;">Your matches</span>
        </div>
    </header>

    <main class="wrap" style="padding-top:1.25rem; padding-bottom:2rem;">

        {{-- Greeting --}}
        <section style="margin-bottom:1rem;">
            <h1 style="font-size:1.5rem; font-weight:800; line-height:1.15; color:var(--text-primary); margin:0;">
                Hi {{ $buyer->first_name ?? 'there' }} 👋
            </h1>
            <p style="font-size:.9375rem; color:var(--text-secondary); margin:.375rem 0 0;">
                @if($totalMatches > 0)
                    Here {{ $totalMatches === 1 ? 'is' : 'are' }} <strong style="color:var(--text-primary);">{{ $totalMatches }}</strong> {{ $totalMatches === 1 ? 'property' : 'properties' }} we think you'll like.
                @else
                    We're on the lookout for properties that fit you.
                @endif
            </p>
            <p style="font-size:.6875rem; color:var(--text-muted); margin:.375rem 0 0;">Updated {{ now()->format('d M Y, H:i') }}</p>
        </section>

        {{-- Flash success (after a response) --}}
        @if(session('success'))
        <div role="status" style="background:color-mix(in srgb, var(--ds-green) 10%, #fff); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color:var(--ds-green); border-radius:10px; padding:.75rem .9rem; font-size:.8125rem; font-weight:600; margin-bottom:1rem;">
            ✓ {{ session('success') }}
        </div>
        @endif

        {{-- Honest preferences brief --}}
        @if(!empty($brief))
        <section class="surface-card" style="padding:1rem 1.125rem; margin-bottom:1rem;">
            <div style="font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:.625rem;">What you're looking for</div>
            <div style="display:flex; flex-direction:column; gap:.5rem;">
                @foreach($brief as $row)
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:1rem;">
                    <span style="font-size:.8125rem; color:var(--text-muted);">{{ $row['label'] }}</span>
                    <span style="font-size:.875rem; font-weight:600; color:var(--text-primary); text-align:right;">{{ $row['value'] }}</span>
                </div>
                @endforeach
            </div>
            {{-- Honesty nudge — few criteria means the % is based only on those.
                 Drives the buyer↔agent loop (CoreX principle) instead of a fake 100%. --}}
            @if(count($basisLabels) > 0 && count($basisLabels) <= 2)
            <div style="margin-top:.875rem; padding-top:.75rem; border-top:1px dashed var(--border); font-size:.75rem; color:var(--text-secondary); line-height:1.45;">
                These matches are scored on {{ $basisText }} — the {{ count($basisLabels) === 1 ? 'preference' : 'preferences' }} you've shared so far.
                Tell {{ $agentFirst }} more (area, bedrooms, must-haves) and your matches get sharper.
            </div>
            @endif
        </section>
        @endif

        {{-- Matches or zero-state --}}
        @if($totalMatches === 0)
            <section class="surface-card" style="padding:2rem 1.25rem; text-align:center; margin-bottom:1rem;">
                <div style="width:52px; height:52px; border-radius:9999px; margin:0 auto .875rem; display:flex; align-items:center; justify-content:center; background:color-mix(in srgb, var(--brand-icon) 12%, transparent); color:var(--brand-icon);">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z"/></svg>
                </div>
                <h2 style="font-size:1.0625rem; font-weight:700; color:var(--text-primary); margin:0 0 .375rem;">No matches just yet</h2>
                <p style="font-size:.875rem; color:var(--text-muted); margin:0 auto; max-width:24rem; line-height:1.5;">
                    New listings come in every day. As soon as something fits {{ !empty($brief) ? 'your brief' : 'what you\'re after' }}, it'll appear right here.
                    @if(!empty($agent)) Chat to {{ $agentFirst }} below to fine-tune what you're looking for. @endif
                </p>
            </section>
        @else
            @php
                $sections = [
                    ['label' => 'Your best matches', 'rows' => $best,   'collapsed' => false],
                    ['label' => 'Strong matches',    'rows' => $strong, 'collapsed' => false],
                ];
            @endphp
            @foreach($sections as $sec)
                @if($sec['rows']->isNotEmpty())
                <section style="margin-bottom:1.25rem;">
                    <h2 style="font-size:.9375rem; font-weight:700; color:var(--text-primary); margin:0 0 .625rem; display:flex; align-items:center; gap:.5rem;">
                        {{ $sec['label'] }}
                        <span style="font-size:.6875rem; font-weight:700; color:var(--brand-icon); background:color-mix(in srgb, var(--brand-icon) 12%, transparent); padding:.1rem .45rem; border-radius:9999px;">{{ $sec['rows']->count() }}</span>
                    </h2>
                    <div style="display:flex; flex-direction:column; gap:.75rem;">
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
            <details style="margin-bottom:1.25rem;">
                <summary style="cursor:pointer; font-size:.9375rem; font-weight:700; color:var(--text-secondary); list-style:none;">
                    + {{ $approx->count() }} more {{ $approx->count() === 1 ? 'property' : 'properties' }} worth a look
                </summary>
                <div style="display:flex; flex-direction:column; gap:.75rem; margin-top:.75rem;">
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
        <section style="margin-bottom:1rem;">
            @include('public.shared._agent-card', ['agent' => $agent, 'agency' => $agency, 'heading' => 'Your agent'])
        </section>

    </main>

    {{-- Company footer (shared public-page component) --}}
    @include('public.shared._company-footer', ['agency' => $agency])

</body>
</html>
