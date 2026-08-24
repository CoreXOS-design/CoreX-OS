<!DOCTYPE html>
<html lang="en">
@php
    // Agency brand colours (Company Settings → Design) — the SAME per-tenant
    // custom properties shared/match.blade.php already uses. Never hardcoded
    // to any one agency; these fall back to CoreX defaults only when an
    // agency hasn't set its own.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';

    // User has no first_name/last_name split (unlike Contact) — just `name`.
    // Matches shared/match.blade.php's own existing "Your Agent" initials
    // convention (first 2 chars of the full name) rather than inventing a
    // different one for this page.
    $agentFirstName = $agent ? \Illuminate\Support\Str::before($agent->name, ' ') : null;
    $agentInitials  = $agent ? strtoupper(substr($agent->name, 0, 2)) : null;
    $agentWaNumber  = $agent?->whatsapp_number ?: $agent?->cell;
    $agentWaDigits  = $agentWaNumber ? preg_replace('/[^0-9]/', '', $agentWaNumber) : null;
    $agentCallNumber = $agent?->cell ?: $agent?->phone;
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ !empty($agency) ? $agency->name . ' — ' : '' }}Let's put a fresh list together</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --ground: #eef2f5;
            --surface: #ffffff;
            --surface-2: #f5f8fa;
            --text-primary: #0c2233;
            --text-secondary: #47606f;
            --text-muted: #7a8f9c;
            --border: #dbe4ea;
            --shadow: 0 1px 2px rgba(12,34,51,.05), 0 14px 34px -18px rgba(12,34,51,.35);
            /* Per-tenant brand — fixed regardless of light/dark, a tenant's
               colour identity doesn't change with the visitor's OS theme. */
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
            --on-brand: #eaf3f7;
            --on-brand-soft: color-mix(in srgb, var(--brand-icon) 55%, #ffffff);
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --ground: #07141d;
                --surface: #101f28;
                --surface-2: #16262f;
                --text-primary: #e6eef2;
                --text-secondary: #9db2be;
                --text-muted: #71879a;
                --border: #22333e;
                --shadow: 0 1px 2px rgba(0,0,0,.45), 0 14px 34px -18px rgba(0,0,0,.8);
            }
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--ground); color: var(--text-primary); margin: 0; -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; }
        img { max-width: 100%; }

        /* Band */
        .band { background: linear-gradient(160deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 80%, #000) 100%); color: var(--on-brand); padding: 2.25rem 1.25rem 5.5rem; position: relative; overflow: hidden; }
        .band::after { content: ""; position: absolute; inset: auto 0 0 0; height: 3px; background: linear-gradient(90deg, var(--brand-icon), transparent); }
        .band-inner { max-width: 66rem; margin: 0 auto; }
        .logo-row { margin-bottom: 2.5rem; }
        .logo-row img { height: 4rem; max-width: 300px; width: auto; object-fit: contain; display: block; }
        .eyebrow { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: var(--on-brand-soft); margin-bottom: 0.9rem; }
        .headline { font-weight: 800; font-size: clamp(1.85rem, 5vw, 3rem); line-height: 1.06; letter-spacing: -0.02em; margin: 0 0 1rem; max-width: 18ch; color: #ffffff; }
        .lede { font-size: 1.05rem; color: var(--on-brand-soft); max-width: 54ch; margin: 0 0 1.75rem; line-height: 1.55; }
        .lede b { color: var(--on-brand); font-weight: 700; }
        .band-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; }
        .btn { font-weight: 700; font-size: 0.9rem; padding: 0.78rem 1.4rem; border-radius: 8px; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: transform 120ms ease, box-shadow 150ms ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-invite { background: var(--brand-icon); color: var(--brand-default); box-shadow: 0 6px 18px color-mix(in srgb, var(--brand-icon) 35%, transparent); }
        .btn-ghost { background: transparent; color: var(--on-brand); border-color: rgba(255,255,255,0.28); }
        .btn-ghost:hover { border-color: var(--brand-icon); }
        .btn-invite:disabled { opacity: 0.7; cursor: default; transform: none; }

        /* Agent card, lifted over the band */
        .lift { max-width: 66rem; margin: -3.5rem auto 0; padding: 0 1.25rem; position: relative; z-index: 2; }
        .agent-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); padding: 1.4rem 1.6rem; display: flex; flex-wrap: wrap; align-items: center; gap: 1.25rem; }
        .av { width: 3.5rem; height: 3.5rem; flex: none; border-radius: 9999px; overflow: hidden; background: var(--brand-default); color: var(--on-brand); display: grid; place-items: center; font-weight: 700; font-size: 1.1rem; }
        .av img { width: 100%; height: 100%; object-fit: cover; }
        .who { flex: 1 1 12rem; min-width: 0; }
        .who .nm { font-weight: 700; font-size: 1.1rem; line-height: 1.25; color: var(--text-primary); }
        .who .rl { font-size: 0.9rem; color: var(--text-muted); margin-top: 0.15rem; }
        .chips { display: flex; flex-wrap: wrap; gap: 0.55rem; }
        .chip { font-weight: 600; font-size: 0.85rem; padding: 0.55rem 0.9rem; border-radius: 7px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text-primary); display: inline-flex; align-items: center; gap: 0.45rem; white-space: nowrap; transition: border-color 120ms ease, color 120ms ease; }
        .chip:hover { border-color: var(--brand-icon); color: var(--brand-icon); }
        .chip svg { width: 15px; height: 15px; flex: none; }
        .chip-lead { background: var(--brand-default); color: var(--on-brand); border-color: var(--brand-default); }
        .chip-lead:hover { color: var(--on-brand); border-color: var(--brand-default); opacity: 0.92; }

        /* Stock sections */
        .stock-sec { max-width: 66rem; margin: 0 auto; padding: 3.25rem 1.25rem 0; }
        .stock-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.4rem; }
        .stock-head h2 { font-weight: 800; font-size: 1.35rem; letter-spacing: -0.01em; margin: 0; color: var(--text-primary); }
        .stock-see-more { font-size: 0.875rem; font-weight: 600; color: var(--brand-icon); }
        .stock-see-more:hover { text-decoration: underline; }
        .listing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.15rem; max-width: 40rem; }
        .listing-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; box-shadow: var(--shadow); display: block; color: inherit; transition: transform 140ms ease, border-color 140ms ease; }
        .listing-card:hover { transform: translateY(-2px); border-color: var(--brand-icon); }
        .listing-img { aspect-ratio: 16/10; width: 100%; background: var(--surface-2); display: flex; align-items: center; justify-content: center; }
        .listing-img img { width: 100%; height: 100%; object-fit: cover; }
        .listing-body { padding: 0.9rem 1.05rem 1.05rem; display: flex; flex-direction: column; gap: 0.35rem; }
        .listing-suburb { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); }
        .listing-price { font-weight: 800; font-size: 1.1rem; letter-spacing: -0.01em; color: var(--text-primary); }
        .listing-addr { font-size: 0.875rem; color: var(--text-secondary); line-height: 1.4; }
        .listing-specs { margin-top: 0.4rem; padding-top: 0.65rem; border-top: 1px solid var(--border); font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 0.9rem; flex-wrap: wrap; }

        /* Footer */
        .page-footer { max-width: 66rem; margin: 4.25rem auto 0; padding: 1.6rem 1.25rem 3.5rem; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 1.1rem 2.5rem; align-items: flex-start; justify-content: space-between; }
        .fname { font-weight: 700; font-size: 0.9rem; color: var(--text-primary); }
        .fmeta { font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.3rem; line-height: 1.55; }
        .fmeta a { color: var(--brand-icon); }
        .fmeta a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    {{-- 1. Navy band — agency identity + the invitation. This closure is
         context, not the headline (Johan, 2026-08-24). PRIVACY: nothing on
         this page ever names the buyer, their criteria, price band, or any
         matched property — the visitor learns only that a list existed and
         has closed. --}}
    <div class="band">
        <div class="band-inner">
            <div class="logo-row">
                @if(!empty($agency) && $agency->logo_path)
                    <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}">
                @else
                    <div class="text-lg font-bold tracking-tight" style="color:#fff;">{{ $agency->name ?? 'Property Matches' }}</div>
                @endif
            </div>

            <div class="eyebrow">Your saved list has closed</div>
            <h1 class="headline">Let&rsquo;s put a fresh list together.</h1>
            <p class="lede">
                @if($agentFirstName){{ $agentFirstName }}&rsquo;s list isn&rsquo;t running any more @else This list isn&rsquo;t running any more @endif
                &mdash; but the market has moved since then, and <b>there&rsquo;s new stock every week.</b>
                Tell us what you&rsquo;re after and we&rsquo;ll start a new one today.
            </p>

            <div class="band-actions">
                @if(session('reengage_sent'))
                    <span class="btn" style="background: rgba(255,255,255,0.14); color: var(--on-brand); cursor: default;">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        Thanks — we&rsquo;ve let {{ $agentFirstName ?: 'your agent' }} know
                    </span>
                @else
                    <form method="POST" action="{{ route('shared.match.reengage', ['token' => $reengageToken]) }}">
                        @csrf
                        <button type="submit" class="btn btn-invite">Start a new list</button>
                    </form>
                @endif
                @if(!empty($agencyStockUrl))
                    <a href="{{ $agencyStockUrl }}" class="btn btn-ghost">Browse what&rsquo;s available</a>
                @endif
            </div>
        </div>
    </div>

    {{-- 2. Agent row, overlapping the band on a white/surface card. --}}
    @if($agent)
    <div class="lift">
        <div class="agent-card">
            <div class="av">
                @if($agent->agent_photo_path)
                    {{-- Same broken-file resilience as the listing cards. --}}
                    <img src="{{ asset('storage/' . $agent->agent_photo_path) }}" alt="{{ $agent->name }}"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;">{{ $agentInitials }}</span>
                @else
                    <span>{{ $agentInitials }}</span>
                @endif
            </div>
            <div class="who">
                <div class="nm">{{ $agent->name }}</div>
                <div class="rl">{{ $agent->designation ?: 'Your property practitioner' }}@if(!empty($agency)) &middot; {{ $agency->name }}@endif</div>
            </div>
            <div class="chips">
                @if(!empty($agentCardUrl))
                <a href="{{ $agentCardUrl }}" target="_blank" rel="noopener" class="chip chip-lead">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path stroke-linecap="round" d="M2 9h20M7 14h5"/></svg>
                    My business card
                </a>
                @endif
                @if($agentCallNumber)
                <a href="tel:{{ $agentCallNumber }}" class="chip">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    {{ $agentCallNumber }}
                </a>
                @endif
                @if($agentWaDigits)
                <a href="https://wa.me/{{ $agentWaDigits }}" target="_blank" rel="noopener" class="chip">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.554 4.103 1.523 5.824L0 24l6.335-1.509A11.945 11.945 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.6-.483-5.12-1.33l-.368-.214-3.76.896.952-3.656-.238-.384A10.01 10.01 0 0 1 2 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                    WhatsApp
                </a>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- 3. "My newest stock" — absent (not empty-state) when the agent has
         no listings of their own (Johan, 2026-08-24). --}}
    @if($agent && $agentListings->isNotEmpty())
    <section class="stock-sec">
        <div class="stock-head">
            <h2>My newest stock</h2>
            @if(!empty($agentCardUrl))<a href="{{ $agentCardUrl }}" target="_blank" rel="noopener" class="stock-see-more">See all {{ $agentFirstName ?: $agent->name }}&rsquo;s listings &rarr;</a>@endif
        </div>
        <div class="listing-grid">
            @foreach($agentListings as $property)
                @include('shared._match-expired-listing-card', ['property' => $property])
            @endforeach
        </div>
    </section>
    @endif

    {{-- 4. "Latest from {agency}" — same absent-if-empty rule. --}}
    @if(!empty($agency) && $agencyListings->isNotEmpty())
    <section class="stock-sec">
        <div class="stock-head">
            <h2>Latest from {{ $agency->name }}</h2>
            @if(!empty($agencyStockUrl))<a href="{{ $agencyStockUrl }}" class="stock-see-more">See all our listings &rarr;</a>@endif
        </div>
        <div class="listing-grid">
            @foreach($agencyListings as $property)
                @include('shared._match-expired-listing-card', ['property' => $property])
            @endforeach
        </div>
    </section>
    @endif

    {{-- 5. Footer — agency record only. --}}
    @if(!empty($agency))
    <footer class="page-footer">
        <div>
            <div class="fname">{{ $agency->name }}</div>
            <div class="fmeta">
                @if($agency->address){{ $agency->address }}<br>@endif
                @if($agency->website_url)<a href="{{ $agency->website_url }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', $agency->website_url) }}</a>@endif
            </div>
        </div>
        @if($fallbackPhone || $fallbackEmail)
        <div>
            <div class="fname">Get hold of us</div>
            <div class="fmeta">
                @if($fallbackPhone){{ $fallbackPhone }}<br>@endif
                @if($fallbackEmail)<a href="mailto:{{ $fallbackEmail }}">{{ $fallbackEmail }}</a>@endif
            </div>
        </div>
        @endif
    </footer>
    @endif

</body>
</html>
