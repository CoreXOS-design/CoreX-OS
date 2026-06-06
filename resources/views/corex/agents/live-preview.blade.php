{{--
    Agent live preview — standalone (no corex-app chrome), mirrors
    corex/properties/live-preview.blade.php. Shows how this agent's public
    website page looks: profile, about, socials, listings, testimonials.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md (brand tokens, ds-badge, corex-btn-*)
    Spec: .ai/specs/testimonials.md (agent linkage).
--}}
@php
    use Illuminate\Support\Str;

    $brandDefault = $agency->default_color ?? '#0b2a4a';
    $brandButton  = $agency->button_color  ?? '#0ea5e9';
    $brandIcon    = $agency->icon_color    ?? '#0ea5e9';

    $photo   = $agent->profilePhotoUrl();
    $waNumber = $agent->cell ? preg_replace('/[^0-9]/', '', $agent->cell) : null;

    // Public socials (added to the User in Part 2 — null-safe until then).
    $socials = array_filter([
        'Facebook'  => $agent->website_social_facebook  ?? null,
        'Instagram' => $agent->website_social_instagram ?? null,
        'LinkedIn'  => $agent->website_social_linkedin  ?? null,
        'YouTube'   => $agent->website_social_youtube   ?? null,
    ]);

    $listingImg = function ($l) {
        $img = collect(array_merge(
            $l->gallery_images_json ?? [],
            $l->dawn_images_json    ?? [],
            $l->noon_images_json    ?? [],
            $l->dusk_images_json    ?? [],
        ))->filter()->first();
        if (!$img) return null;
        return Str::startsWith($img, ['http://', 'https://']) ? $img : asset('storage/'.ltrim($img, '/'));
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $agent->name }} — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: {
            sans: ['Inter', 'system-ui', 'sans-serif'],
            mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
        }}}}
    </script>
    <style>
        :root {
            --brand-default: {{ $brandDefault }};
            --brand-button:  {{ $brandButton }};
            --brand-icon:    {{ $brandIcon }};
            --bg:#f4f6fb; --surface:#ffffff; --surface-2:#f0f2f8;
            --border:rgba(0,0,0,0.07); --border-hover:rgba(0,0,0,0.14);
            --text-primary:#111827; --text-secondary:#4b5563; --text-muted:#9ca3af;
            --ds-green:#059669; --ds-amber:#f59e0b; --ds-crimson:#c41e3a; --ds-navy:#0b2a4a;
        }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text-primary); margin:0;
               -webkit-font-smoothing:antialiased; font-size:.875rem; }
        .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-variant-numeric:tabular-nums; font-weight:600; }
        .ds-badge { display:inline-flex; align-items:center; gap:.375rem; padding:.25rem .625rem; border-radius:9999px;
                    font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .ds-badge-success { background:color-mix(in srgb,var(--ds-green) 14%,transparent); color:var(--ds-green); }
        .ds-badge-default { background:var(--surface-2); color:var(--text-secondary); }
        .corex-btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.625rem 1rem;
            border-radius:6px; background:var(--brand-button); color:#fff; font-weight:600; font-size:.875rem; text-decoration:none;
            border:0; cursor:pointer; box-shadow:0 4px 12px color-mix(in srgb,var(--brand-button) 25%,transparent); transition:all 300ms ease; }
        .corex-btn-primary:hover { filter:brightness(1.06); }
        .corex-btn-outline { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.625rem 1rem;
            border-radius:6px; background:var(--surface); color:var(--text-primary); font-weight:600; font-size:.875rem;
            text-decoration:none; border:1px solid var(--border); cursor:pointer; transition:all 300ms ease; }
        .corex-btn-outline:hover { border-color:var(--border-hover); background:var(--surface-2); }
        .btn-block { width:100%; }
        .btn-wa { background:#25d366; box-shadow:0 4px 12px color-mix(in srgb,#25d366 25%,transparent); }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:6px; }
        .listing-card { overflow:hidden; transition:transform 200ms ease, box-shadow 200ms ease; }
        .listing-card:hover { transform:translateY(-3px); box-shadow:0 10px 28px rgba(0,0,0,.08); }
        .label { font-size:.6875rem; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); font-weight:600; }
        .preview-bar { position:sticky; top:0; z-index:50; background:var(--ds-navy); color:#fff; }
        .star { color:var(--ds-amber); }
        .star-off { color:var(--text-muted); opacity:.35; }
    </style>
</head>
<body>

{{-- Preview banner — only the agent/manager sees this; not part of the public page --}}
<div class="preview-bar px-4 py-2.5">
    <div class="max-w-6xl mx-auto flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2 text-xs">
            <span style="font-weight:700;">CoreX live preview</span>
            <span style="opacity:.7;">— this is how {{ $isSelf ? 'your' : $agent->name . "'s" }} agent page appears on the website.</span>
            @if($agent->show_on_website)
                <span class="ds-badge" style="background:rgba(255,255,255,.15); color:#fff;">● On website</span>
            @else
                <span class="ds-badge" style="background:rgba(255,255,255,.12); color:#fff;">Not published yet</span>
            @endif
        </div>
        <a href="{{ route('agent.portal') }}#profile" class="text-xs" style="color:#fff; text-decoration:underline; opacity:.9;">← Back to My Portal</a>
    </div>
</div>

{{-- HERO --}}
<section style="background:var(--brand-default); color:#fff;">
    <div class="max-w-6xl mx-auto px-4 py-12 flex items-center gap-6 flex-wrap">
        @if($photo)
            <img src="{{ $photo }}" alt="{{ $agent->name }}" class="rounded-full object-cover"
                 style="width:128px; height:128px; border:4px solid color-mix(in srgb,var(--brand-button) 35%,#fff);">
        @else
            <div class="rounded-full flex items-center justify-center font-bold text-white"
                 style="width:128px; height:128px; background:var(--brand-button); font-size:2.25rem;">{{ $agent->initials() }}</div>
        @endif
        <div class="min-w-0">
            <h1 class="font-extrabold" style="font-size:2rem; line-height:1.1;">{{ $agent->name }}</h1>
            @if($agent->designation)
                <div class="mt-2"><span class="ds-badge" style="background:rgba(255,255,255,.16); color:#fff;">{{ $agent->designation }}</span></div>
            @endif
            <div class="mt-3 flex items-center gap-4 flex-wrap text-sm" style="color:rgba(255,255,255,.85);">
                @if($agency?->name)<span>{{ $agency->name }}</span>@endif
                @if($agent->branch)<span>· {{ $agent->branch->name }}</span>@endif
            </div>
            <div class="mt-5 flex items-center gap-2.5 flex-wrap">
                @php $callNo = $agent->cell ?: $agent->phone; @endphp
                @if($callNo)
                    <a href="tel:{{ $callNo }}" class="corex-btn-primary">Call <span class="num">{{ $callNo }}</span></a>
                @endif
                @if($waNumber)
                    <a href="https://wa.me/{{ $waNumber }}" target="_blank" class="corex-btn-primary btn-wa">WhatsApp</a>
                @endif
                @if($agent->email)
                    <a href="mailto:{{ $agent->email }}" class="corex-btn-outline">Email</a>
                @endif
            </div>
        </div>
    </div>
</section>

<div class="max-w-6xl mx-auto px-4 py-10 space-y-10">

    {{-- ABOUT (data added in Part 2 — hidden until present) --}}
    @if(!empty($agent->about_me))
        <section>
            <h2 class="font-bold mb-3" style="font-size:1.125rem; color:var(--brand-default);">About {{ Str::before($agent->name, ' ') ?: $agent->name }}</h2>
            <div class="card" style="padding:1.5rem;">
                <p class="whitespace-pre-line" style="color:var(--text-secondary); line-height:1.7;">{{ $agent->about_me }}</p>
            </div>
        </section>
    @endif

    {{-- SOCIALS (data added in Part 2 — hidden until present) --}}
    @if(!empty($socials))
        <section>
            <div class="label mb-2">Connect</div>
            <div class="flex items-center gap-2 flex-wrap">
                @foreach($socials as $label => $url)
                    <a href="{{ Str::startsWith($url, ['http://','https://']) ? $url : 'https://'.$url }}" target="_blank" rel="noopener"
                       class="corex-btn-outline">{{ $label }}</a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ARTICLES (data added in Part 2 — hidden until present) --}}
    @if($articles->isNotEmpty())
        <section>
            <h2 class="font-bold mb-4" style="font-size:1.125rem; color:var(--brand-default);">Articles</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($articles as $article)
                    <div class="card listing-card" style="padding:1.25rem;">
                        <div class="font-bold" style="color:var(--brand-default);">{{ $article->title ?? '' }}</div>
                        <p class="text-sm mt-2" style="color:var(--text-secondary);">{{ Str::limit($article->excerpt ?? $article->body ?? '', 140) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- LISTINGS --}}
    <section>
        <h2 class="font-bold mb-4" style="font-size:1.125rem; color:var(--brand-default);">
            {{ $isSelf ? 'My listings' : $agent->name . "'s listings" }}
            <span class="num" style="color:var(--text-muted); font-weight:600;">({{ $listings->count() }})</span>
        </h2>
        @if($listings->isEmpty())
            <div class="card" style="padding:2rem; text-align:center; color:var(--text-muted);">No active listings yet.</div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($listings as $l)
                    @php $src = $listingImg($l); @endphp
                    <a href="{{ route('corex.properties.preview', $l) }}" target="_blank" class="card listing-card" style="text-decoration:none; color:inherit; display:block;">
                        <div style="aspect-ratio:4/3; background:var(--surface-2); overflow:hidden;">
                            @if($src)
                                <img src="{{ $src }}" alt="{{ $l->title }}" style="width:100%; height:100%; object-fit:cover;">
                            @else
                                <div class="flex items-center justify-center h-full" style="color:var(--text-muted); font-size:.75rem;">No image</div>
                            @endif
                        </div>
                        <div style="padding:1rem;">
                            <div class="num" style="font-size:1.125rem; color:var(--brand-default);">{{ $l->formattedPrice() }}</div>
                            @if($l->title)
                                <div class="text-sm font-semibold mt-1" style="color:var(--text-primary);">{{ Str::limit($l->title, 60) }}</div>
                            @endif
                            <div class="text-sm mt-0.5" style="color:var(--text-secondary);">{{ $l->buildDisplayAddress() ?: ($l->suburb ?? '') }}</div>
                            <div class="flex items-center gap-3 mt-2 text-xs" style="color:var(--text-muted);">
                                @if($l->beds !== null)<span>{{ (int) $l->beds }} bed</span>@endif
                                @if($l->baths !== null)<span>{{ rtrim(rtrim((string) $l->baths, '0'), '.') }} bath</span>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- TESTIMONIALS --}}
    <section>
        <h2 class="font-bold mb-4" style="font-size:1.125rem; color:var(--brand-default);">
            What clients say
            <span class="num" style="color:var(--text-muted); font-weight:600;">({{ $testimonials->count() }})</span>
        </h2>
        @if($testimonials->isEmpty())
            <div class="card" style="padding:2rem; text-align:center; color:var(--text-muted);">No published testimonials yet.</div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                @foreach($testimonials as $t)
                    <div class="card" style="padding:1.25rem;">
                        @if($t->rating)
                            <div class="mb-2" style="font-size:1rem;">
                                <span class="star">{{ str_repeat('★', (int) $t->rating) }}</span><span class="star-off">{{ str_repeat('★', 5 - (int) $t->rating) }}</span>
                            </div>
                        @endif
                        <p class="whitespace-pre-line" style="color:var(--text-secondary); line-height:1.6;">“{{ $t->body }}”</p>
                        <div class="text-sm font-semibold mt-3" style="color:var(--brand-default);">{{ $t->display_name }}</div>
                        @if($t->published_at)<div class="text-xs" style="color:var(--text-muted);">{{ $t->published_at->format('M Y') }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <div class="text-center pt-4" style="border-top:1px solid var(--border); color:var(--text-muted); font-size:.8125rem;">
        {{ $agency->name ?? 'Home Finders Coastal' }}@if($agent->branch) · {{ $agent->branch->name }}@endif
    </div>
</div>

</body>
</html>
