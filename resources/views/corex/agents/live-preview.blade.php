{{--
    Agent live preview — standalone (no corex-app chrome). Mirrors the public
    website agent page: contact card + socials, testimonials, listings (with
    status badges + beds/baths/garages), "Get to Know Me", "How Can I Help?",
    and the agent's articles. Spec: .ai/specs/testimonials.md (agent linkage).
--}}
@php
    use Illuminate\Support\Str;

    $brandDefault = $agency->default_color ?? '#0b2a4a';
    $brandButton  = $agency->button_color  ?? '#0ea5e9';
    $brandIcon    = $agency->icon_color    ?? '#0ea5e9';

    $photo    = $agent->profilePhotoUrl();
    $waNumber = $agent->cell ? preg_replace('/[^0-9]/', '', $agent->cell) : null;
    $callNo   = $agent->cell ?: $agent->phone;

    // Personal public socials (My Portal → Profile). icon key => url.
    $socials = array_filter([
        'facebook'  => $agent->website_social_facebook,
        'instagram' => $agent->website_social_instagram,
        'linkedin'  => $agent->website_social_linkedin,
        'youtube'   => $agent->website_social_youtube,
    ]);
    $socialIcon = [
        'facebook'  => '<path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/>',
        'instagram' => '<path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85C2.38 3.92 3.9 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16Zm0 3.68A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84Zm0 10.16A4 4 0 1 1 16 12a4 4 0 0 1-4 4Zm6.41-10.4a1.44 1.44 0 1 0 1.44 1.44 1.44 1.44 0 0 0-1.44-1.44Z"/>',
        'linkedin'  => '<path d="M4.98 3.5A2.5 2.5 0 1 1 0 3.5a2.5 2.5 0 0 1 4.98 0ZM.22 8.25h4.52V24H.22ZM8.34 8.25h4.33v2.15h.06a4.75 4.75 0 0 1 4.28-2.35c4.58 0 5.42 3.01 5.42 6.93V24h-4.52v-6.99c0-1.67-.03-3.81-2.32-3.81s-2.68 1.81-2.68 3.69V24H8.34Z"/>',
        'youtube'   => '<path d="M23.5 6.2a3 3 0 0 0-2.12-2.13C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.52A3 3 0 0 0 .5 6.2 31.3 31.3 0 0 0 0 12a31.3 31.3 0 0 0 .5 5.8 3 3 0 0 0 2.12 2.13c1.88.52 9.38.52 9.38.52s7.5 0 9.38-.52a3 3 0 0 0 2.12-2.13A31.3 31.3 0 0 0 24 12a31.3 31.3 0 0 0-.5-5.8ZM9.6 15.6V8.4l6.2 3.6Z"/>',
    ];

    // Listing status → [label, text colour, bg colour]
    $statusFor = function ($p) {
        $isRental = in_array((string) $p->listing_type, ['rental', 'to_let', 'to-let', 'lease'], true);
        return match ((string) $p->status) {
            'active'                 => [$isRental ? 'To Let' : 'For Sale', '#059669', 'color-mix(in srgb,#059669 12%,transparent)'],
            'pending', 'under_offer' => ['Under Offer', '#b45309', 'color-mix(in srgb,#f59e0b 16%,transparent)'],
            'sold'                   => ['Sold', '#0b2a4a', 'color-mix(in srgb,#0b2a4a 12%,transparent)'],
            default                  => [ucfirst((string) $p->status), '#4b5563', 'var(--surface-2)'],
        };
    };
    $listingImg = function ($l) {
        $img = collect(array_merge(
            $l->gallery_images_json ?? [], $l->dawn_images_json ?? [], $l->noon_images_json ?? [], $l->dusk_images_json ?? [],
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
    <style>
        :root {
            --brand-default: {{ $brandDefault }}; --brand-button: {{ $brandButton }}; --brand-icon: {{ $brandIcon }};
            --bg:#f4f6fb; --surface:#ffffff; --surface-2:#f0f2f8; --border:rgba(0,0,0,0.08);
            --text-primary:#111827; --text-secondary:#4b5563; --text-muted:#9ca3af; --ds-amber:#f59e0b;
        }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text-primary); margin:0; -webkit-font-smoothing:antialiased; font-size:.9375rem; }
        .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-variant-numeric:tabular-nums; }
        .wrap { max-width:1100px; margin:0 auto; padding:0 1.25rem; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:10px; }
        .listing-card { overflow:hidden; transition:transform .2s ease, box-shadow .2s ease; }
        .listing-card:hover { transform:translateY(-3px); box-shadow:0 12px 30px rgba(0,0,0,.09); }
        .badge { display:inline-flex; align-items:center; padding:.25rem .625rem; border-radius:9999px; font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .corex-btn-primary { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.625rem 1rem; border-radius:6px; background:var(--brand-button); color:#fff; font-weight:600; font-size:.875rem; text-decoration:none; border:0; cursor:pointer; }
        .corex-btn-outline { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.625rem 1rem; border-radius:6px; background:var(--surface); color:var(--text-primary); font-weight:600; font-size:.875rem; text-decoration:none; border:1px solid var(--border); }
        .btn-wa { background:#25d366; color:#fff; }
        .social-ico { width:38px; height:38px; border-radius:9999px; display:inline-flex; align-items:center; justify-content:center; background:var(--surface-2); color:var(--brand-default); transition:all .2s ease; }
        .social-ico:hover { background:var(--brand-default); color:#fff; }
        .h2 { font-size:1.375rem; font-weight:800; color:var(--brand-default); }
        .specs span { display:inline-flex; align-items:center; gap:.3rem; }
    </style>
</head>
<body>

{{-- Preview banner --}}
<div style="position:sticky; top:0; z-index:50; background:var(--brand-default); color:#fff;" class="px-4 py-2.5">
    <div class="wrap flex items-center justify-between gap-3 flex-wrap" style="padding-left:0;padding-right:0;">
        <div class="flex items-center gap-2 text-xs">
            <span style="font-weight:700;">CoreX live preview</span>
            <span style="opacity:.7;">— how {{ $isSelf ? 'your' : $agent->name."'s" }} agent page appears on the website.</span>
            <span class="badge" style="background:rgba(255,255,255,.16); color:#fff;">{{ $agent->show_on_website ? '● On website' : 'Not published yet' }}</span>
        </div>
        <a href="{{ route('agent.portal') }}#profile" style="color:#fff; text-decoration:underline; opacity:.9; font-size:.8125rem;">← Back to My Portal</a>
    </div>
</div>

{{-- CONTACT CARD --}}
<section style="background:var(--brand-default); color:#fff;">
    <div class="wrap" style="padding-top:2.5rem; padding-bottom:2.5rem;">
        <div class="flex items-center gap-6 flex-wrap">
            @if($photo)
                <img src="{{ $photo }}" alt="{{ $agent->name }}" style="width:132px; height:132px; border-radius:9999px; object-fit:cover; border:4px solid color-mix(in srgb,var(--brand-button) 35%,#fff);">
            @else
                <div style="width:132px; height:132px; border-radius:9999px; background:var(--brand-button); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:2.25rem;">{{ $agent->initials() }}</div>
            @endif
            <div class="min-w-0">
                <h1 style="font-size:2rem; font-weight:800; line-height:1.1;">{{ $agent->name }}</h1>
                @if($agent->designation)<div style="margin-top:.35rem; color:rgba(255,255,255,.85); font-weight:600;">{{ $agent->designation }}</div>@endif
                <div class="mt-2 text-sm" style="color:rgba(255,255,255,.75);">
                    @if($agency?->name){{ $agency->name }}@endif @if($agent->branch) · {{ $agent->branch->name }}@endif
                </div>
                <div class="mt-3 flex items-center gap-4 flex-wrap text-sm" style="color:rgba(255,255,255,.9);">
                    @if($callNo)<a href="tel:{{ $callNo }}" style="color:#fff; text-decoration:none;">📞 <span class="num">{{ $callNo }}</span></a>@endif
                    @if($agent->email)<a href="mailto:{{ $agent->email }}" style="color:#fff; text-decoration:none;">✉ {{ $agent->email }}</a>@endif
                </div>
                <div class="mt-4 flex items-center gap-3 flex-wrap">
                    @if($callNo)<a href="tel:{{ $callNo }}" class="corex-btn-primary">Call</a>@endif
                    @if($waNumber)<a href="https://wa.me/{{ $waNumber }}" target="_blank" class="corex-btn-primary btn-wa">WhatsApp</a>@endif
                    @foreach($socials as $net => $url)
                        <a class="social-ico" target="_blank" rel="noopener" title="{{ ucfirst($net) }}"
                           href="{{ Str::startsWith($url, ['http://','https://']) ? $url : 'https://'.$url }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">{!! $socialIcon[$net] !!}</svg>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

<div class="wrap" style="padding-top:2.5rem; padding-bottom:3rem;">

    {{-- TESTIMONIALS --}}
    @if($testimonials->isNotEmpty())
        <section style="margin-bottom:2.5rem;">
            <h2 class="h2" style="margin-bottom:1rem;">Testimonials</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($testimonials as $t)
                    <div class="card" style="padding:1.25rem;">
                        @if($t->rating)<div style="color:var(--ds-amber); margin-bottom:.4rem;">{{ str_repeat('★', (int) $t->rating) }}</div>@endif
                        <p style="color:var(--text-secondary); line-height:1.6;">“{{ $t->body }}”</p>
                        <div style="font-weight:700; color:var(--brand-default); margin-top:.6rem;">{{ $t->display_name }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- LISTINGS --}}
    <section style="margin-bottom:2.5rem;">
        <h2 class="h2" style="margin-bottom:1rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} {{ $listings->count() }} Listing{{ $listings->count() === 1 ? '' : 's' }}</h2>
        @if($listings->isEmpty())
            <div class="card" style="padding:2rem; text-align:center; color:var(--text-muted);">No listings yet.</div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($listings as $l)
                    @php [$lbl,$lc,$lbg] = $statusFor($l); $src = $listingImg($l); @endphp
                    <a href="{{ route('corex.properties.preview', $l) }}" target="_blank" class="card listing-card" style="text-decoration:none; color:inherit; display:block;">
                        <div style="position:relative; aspect-ratio:4/3; background:var(--surface-2); overflow:hidden;">
                            @if($src)<img src="{{ $src }}" alt="{{ $l->title }}" style="width:100%; height:100%; object-fit:cover;">@else<div class="flex items-center justify-center h-full" style="color:var(--text-muted); font-size:.75rem;">No image</div>@endif
                            <span class="badge" style="position:absolute; top:.6rem; left:.6rem; background:{{ $lbg }}; color:{{ $lc }};">{{ $lbl }}</span>
                        </div>
                        <div style="padding:1rem;">
                            <div class="num" style="font-size:1.25rem; font-weight:700; color:var(--brand-default);">{{ $l->formattedPrice() }}</div>
                            @if($l->property_type)<div class="text-sm" style="color:var(--text-secondary); margin-top:.15rem;">{{ ucfirst($l->property_type) }}</div>@endif
                            <div class="text-sm" style="color:var(--text-muted); margin-top:.15rem;">📍 {{ trim(collect([$l->suburb, $l->city ?: $l->region, $l->province])->filter()->unique()->take(2)->implode(', ')) ?: $l->buildDisplayAddress() }}</div>
                            <div class="specs flex items-center gap-4 mt-2 text-sm" style="color:var(--text-primary); font-weight:600;">
                                @if($l->beds !== null)<span>🛏 {{ (int) $l->beds }}</span>@endif
                                @if($l->baths !== null)<span>🛁 {{ rtrim(rtrim((string) $l->baths, '0'), '.') }}</span>@endif
                                @if($l->garages !== null)<span>🚗 {{ (int) $l->garages }}</span>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- GET TO KNOW ME --}}
    @if(!empty($agent->about_me))
        <section class="card" style="padding:1.75rem; margin-bottom:2.5rem;">
            <h2 class="h2" style="margin-bottom:.75rem;">Get to Know Me</h2>
            <p style="color:var(--text-secondary); line-height:1.8; white-space:pre-line;">{{ $agent->about_me }}</p>
        </section>
    @endif

    {{-- HOW CAN I HELP --}}
    <section style="margin-bottom:2.5rem;">
        <h2 class="h2" style="margin-bottom:1rem;">How Can I Help?</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            @foreach(['Sell a Property', 'Calculators', 'Properties For Sale By Me', 'Rental Properties'] as $i => $help)
                <a href="@if($i===2)#listings @else# @endif" class="card" style="padding:1.1rem; text-align:center; text-decoration:none; color:var(--brand-default); font-weight:700; font-size:.875rem;">{{ $help }}</a>
            @endforeach
        </div>
    </section>

    {{-- ARTICLES --}}
    @if($articles->isNotEmpty())
        <section>
            <h2 class="h2" style="margin-bottom:1rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} Articles</h2>
            <div class="card" style="overflow:hidden;">
                @foreach($articles as $i => $article)
                    <a href="{{ route('corex.agents.article.preview', [$agent, $article, $article->previewSlug()]) }}" target="_blank"
                       style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.25rem; text-decoration:none; color:inherit; {{ $i > 0 ? 'border-top:1px solid var(--border);' : '' }}">
                        <div style="min-width:0;">
                            <div style="font-weight:700; color:var(--brand-default);">{{ $article->title }}</div>
                            <div class="text-xs" style="color:var(--text-muted); margin-top:.2rem;"><span class="num">{{ $article->readMinutes() }} MIN</span> • <span class="num">{{ number_format($article->wordCount()) }}</span> Words</div>
                        </div>
                        <span style="color:var(--brand-icon); font-weight:700;">→</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div style="text-align:center; padding-top:2rem; margin-top:2.5rem; border-top:1px solid var(--border); color:var(--text-muted); font-size:.8125rem;">
        {{ $agency->name ?? 'Home Finders Coastal' }}@if($agent->branch) · {{ $agent->branch->name }}@endif
    </div>
</div>

</body>
</html>
