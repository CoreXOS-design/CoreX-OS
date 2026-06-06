{{--
    Agent live preview — standalone (no corex-app chrome). Mirrors the public
    website agent page: contact card + socials, testimonials, listings (status
    badges + beds/baths/garages), "Get to Know Me", and articles.
    Spec: .ai/specs/testimonials.md (agent linkage).
--}}
@php
    use Illuminate\Support\Str;

    $brandDefault = $agency->default_color ?? '#0b2a4a';
    $brandButton  = $agency->button_color  ?? '#0ea5e9';
    $brandIcon    = $agency->icon_color    ?? '#0ea5e9';

    $photo    = $agent->profilePhotoUrl();
    $waNumber = $agent->cell ? preg_replace('/[^0-9]/', '', $agent->cell) : null;
    $callNo   = $agent->cell ?: $agent->phone;

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

    $statusFor = function ($p) {
        $isRental = in_array((string) $p->listing_type, ['rental', 'to_let', 'to-let', 'lease'], true);
        return match ((string) $p->status) {
            'active'                 => [$isRental ? 'To Let' : 'For Sale', '#047857', 'rgba(16,185,129,.14)'],
            'pending', 'under_offer' => ['Under Offer', '#b45309', 'rgba(245,158,11,.16)'],
            'sold'                   => ['Sold', '#1e3a8a', 'rgba(30,58,138,.12)'],
            default                  => [ucfirst((string) $p->status), '#475569', 'rgba(100,116,139,.12)'],
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
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800,900|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-default: {{ $brandDefault }}; --brand-button: {{ $brandButton }}; --brand-icon: {{ $brandIcon }};
            --bg:#f1f4f9; --surface:#ffffff; --surface-2:#eef1f7; --border:rgba(15,23,42,0.08);
            --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#94a3b8; --ds-amber:#f59e0b;
        }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text-primary); margin:0; -webkit-font-smoothing:antialiased; }
        .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-variant-numeric:tabular-nums; }
        .wrap { max-width:1120px; margin:0 auto; padding:0 1.5rem; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
        .lcard { overflow:hidden; transition:transform .22s ease, box-shadow .22s ease; }
        .lcard:hover { transform:translateY(-4px); box-shadow:0 16px 40px rgba(15,23,42,.12); }
        .badge { display:inline-flex; align-items:center; padding:.3rem .7rem; border-radius:9999px; font-size:.6875rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.6rem 1.1rem; border-radius:9999px; font-weight:600; font-size:.875rem; text-decoration:none; border:0; cursor:pointer; transition:all .2s ease; }
        .btn-primary { background:var(--brand-button); color:#fff; box-shadow:0 6px 16px color-mix(in srgb,var(--brand-button) 30%,transparent); }
        .btn-primary:hover { filter:brightness(1.07); }
        .btn-wa { background:#25d366; color:#fff; box-shadow:0 6px 16px rgba(37,211,102,.3); }
        .btn-ghost { background:rgba(255,255,255,.14); color:#fff; border:1px solid rgba(255,255,255,.25); }
        .btn-ghost:hover { background:rgba(255,255,255,.22); }
        .soc { width:40px; height:40px; border-radius:9999px; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,.14); color:#fff; border:1px solid rgba(255,255,255,.22); transition:all .2s ease; }
        .soc:hover { background:#fff; color:var(--brand-default); transform:translateY(-2px); }
        .h2 { font-size:1.5rem; font-weight:800; color:var(--text-primary); letter-spacing:-.01em; }
        .eyebrow { font-size:.75rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--brand-icon); }
        .spec { display:inline-flex; align-items:center; gap:.35rem; color:var(--text-secondary); font-weight:600; font-size:.875rem; }
        .ico { width:16px; height:16px; flex:0 0 16px; }
        .chip { display:inline-block; padding:.3rem .7rem; border-radius:9999px; background:var(--surface-2); color:var(--brand-icon); font-size:.75rem; font-weight:600; }
    </style>
</head>
<body>

{{-- HERO --}}
<section style="background:linear-gradient(135deg, var(--brand-default), color-mix(in srgb, var(--brand-default) 70%, #000)); color:#fff;">
    <div class="wrap" style="padding-top:3.5rem; padding-bottom:3.5rem;">
        <div class="flex items-center gap-7 flex-wrap">
            @if($photo)
                <img src="{{ $photo }}" alt="{{ $agent->name }}" style="width:148px; height:148px; border-radius:9999px; object-fit:cover; border:5px solid rgba(255,255,255,.18); box-shadow:0 12px 30px rgba(0,0,0,.3);">
            @else
                <div style="width:148px; height:148px; border-radius:9999px; background:var(--brand-button); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:2.6rem; box-shadow:0 12px 30px rgba(0,0,0,.3);">{{ $agent->initials() }}</div>
            @endif
            <div class="min-w-0" style="flex:1;">
                <h1 style="font-size:2.4rem; font-weight:900; line-height:1.05; letter-spacing:-.02em;">{{ $agent->name }}</h1>
                @if($agent->designation)<div style="margin-top:.45rem; font-size:1.0625rem; color:rgba(255,255,255,.9); font-weight:600;">{{ $agent->designation }}</div>@endif
                <div class="mt-2 text-sm" style="color:rgba(255,255,255,.7);">
                    @if($agency?->name){{ $agency->name }}@endif @if($agent->branch) · {{ $agent->branch->name }}@endif
                </div>
                <div class="mt-4 flex items-center gap-5 flex-wrap text-sm" style="color:rgba(255,255,255,.92);">
                    @if($callNo)
                        <span class="inline-flex items-center gap-2"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.37c0-.52-.35-.97-.85-1.09l-4.42-1.11c-.44-.11-.9.06-1.17.42l-.97 1.29c-.28.38-.77.54-1.21.38a12.04 12.04 0 0 1-7.14-7.14c-.16-.44 0-.93.38-1.21l1.29-.97c.36-.27.53-.73.42-1.17L6.96 3.1A1.13 1.13 0 0 0 5.87 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5Z"/></svg><span class="num">{{ $callNo }}</span></span>
                    @endif
                    @if($agent->email)
                        <a href="mailto:{{ $agent->email }}" class="inline-flex items-center gap-2" style="color:inherit; text-decoration:none;"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5H4.5A2.25 2.25 0 0 0 2.25 6.75m19.5 0v.24a2.25 2.25 0 0 1-1.07 1.92l-7.5 4.61a2.25 2.25 0 0 1-2.36 0l-7.5-4.61A2.25 2.25 0 0 1 2.25 6.99v-.24"/></svg>{{ $agent->email }}</a>
                    @endif
                </div>
                <div class="mt-5 flex items-center gap-3 flex-wrap">
                    @if($callNo)<a href="tel:{{ $callNo }}" class="btn btn-primary">Call</a>@endif
                    @if($waNumber)<a href="https://wa.me/{{ $waNumber }}" target="_blank" class="btn btn-wa">WhatsApp</a>@endif
                    @if($agent->email)<a href="mailto:{{ $agent->email }}" class="btn btn-ghost">Email</a>@endif
                    @foreach($socials as $net => $url)
                        <a class="soc" target="_blank" rel="noopener" title="{{ ucfirst($net) }}" href="{{ Str::startsWith($url, ['http://','https://']) ? $url : 'https://'.$url }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">{!! $socialIcon[$net] !!}</svg>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

<div class="wrap" style="padding-top:3rem; padding-bottom:3.5rem;">

    {{-- TESTIMONIALS --}}
    @if($testimonials->isNotEmpty())
        <section style="margin-bottom:3rem;">
            <div class="eyebrow">Testimonials</div>
            <h2 class="h2" style="margin:.25rem 0 1.25rem;">What clients say</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @foreach($testimonials as $t)
                    <div class="card" style="padding:1.5rem;">
                        @if($t->rating)<div style="color:var(--ds-amber); margin-bottom:.5rem; letter-spacing:2px;">{{ str_repeat('★', (int) $t->rating) }}</div>@endif
                        <p style="color:var(--text-secondary); line-height:1.7; font-size:1.0625rem;">“{{ $t->body }}”</p>
                        <div style="font-weight:700; color:var(--brand-default); margin-top:.85rem;">{{ $t->display_name }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- LISTINGS --}}
    <section id="listings" style="margin-bottom:3rem;">
        <div class="eyebrow">Properties</div>
        <h2 class="h2" style="margin:.25rem 0 1.25rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} {{ $listings->count() }} Listing{{ $listings->count() === 1 ? '' : 's' }}</h2>
        @if($listings->isEmpty())
            <div class="card" style="padding:2.5rem; text-align:center; color:var(--text-muted);">No listings yet.</div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($listings as $l)
                    @php [$lbl,$lc,$lbg] = $statusFor($l); $src = $listingImg($l); @endphp
                    <a href="{{ route('corex.properties.preview', $l) }}" target="_blank" class="card lcard" style="text-decoration:none; color:inherit; display:block;">
                        <div style="position:relative; aspect-ratio:4/3; background:var(--surface-2); overflow:hidden;">
                            @if($src)<img src="{{ $src }}" alt="{{ $l->title }}" style="width:100%; height:100%; object-fit:cover;">@else<div class="flex items-center justify-center h-full" style="color:var(--text-muted); font-size:.8rem;">No image</div>@endif
                            <span class="badge" style="position:absolute; top:.75rem; left:.75rem; background:{{ $lbg }}; color:{{ $lc }}; backdrop-filter:blur(6px);">{{ $lbl }}</span>
                        </div>
                        <div style="padding:1.15rem 1.25rem 1.35rem;">
                            <div class="num" style="font-size:1.35rem; font-weight:700; color:var(--brand-default);">{{ $l->formattedPrice() }}</div>
                            @if($l->property_type)<div style="color:var(--text-secondary); margin-top:.2rem; font-weight:600;">{{ ucfirst($l->property_type) }}</div>@endif
                            <div class="text-sm inline-flex items-start gap-1.5" style="color:var(--text-muted); margin-top:.25rem;">
                                <svg class="ico" style="margin-top:2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                <span>{{ trim(collect([$l->suburb, $l->city ?: $l->region, $l->province])->filter()->unique()->take(2)->implode(', ')) ?: $l->buildDisplayAddress() }}</span>
                            </div>
                            <div class="flex items-center gap-5 mt-3 pt-3" style="border-top:1px solid var(--border);">
                                @if($l->beds !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12V6.75A.75.75 0 0 1 3.75 6h16.5a.75.75 0 0 1 .75.75V12m-18 0v6m0-6h18m0 0v6M6 12V9.75A.75.75 0 0 1 6.75 9h3a.75.75 0 0 1 .75.75V12m3 0V9.75A.75.75 0 0 1 14.25 9h3a.75.75 0 0 1 .75.75V12"/></svg>{{ (int) $l->beds }}</span>@endif
                                @if($l->baths !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 11.25V6a2.25 2.25 0 0 1 4.4-.66M3 11.25h18v2.25a5.25 5.25 0 0 1-5.25 5.25H8.25A5.25 5.25 0 0 1 3 13.5v-2.25Zm3.75 7.5L6 21m11.25-2.25L18 21"/></svg>{{ rtrim(rtrim((string) $l->baths, '0'), '.') }}</span>@endif
                                @if($l->garages !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75V9.31c0-.51.3-.97.78-1.16l8.25-3.3a1.5 1.5 0 0 1 1.12 0l8.25 3.3c.47.19.78.65.78 1.16v9.44M2.25 18.75h19.5M4.5 18.75v-6h15v6"/></svg>{{ (int) $l->garages }}</span>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- GET TO KNOW ME --}}
    @if(!empty($agent->about_me))
        <section class="card" style="padding:2rem; margin-bottom:3rem;">
            <div class="eyebrow">About</div>
            <h2 class="h2" style="margin:.25rem 0 1rem;">Get to Know Me</h2>
            <p style="color:var(--text-secondary); line-height:1.85; font-size:1.0625rem; white-space:pre-line;">{{ $agent->about_me }}</p>
        </section>
    @endif

    {{-- ARTICLES --}}
    @if($articles->isNotEmpty())
        <section>
            <div class="eyebrow">Insights</div>
            <h2 class="h2" style="margin:.25rem 0 1.25rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} Articles</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($articles as $article)
                    @php $ac = $article->coverImageUrl(); @endphp
                    <a href="{{ route('corex.agents.article.preview', [$agent, $article, $article->previewSlug()]) }}" target="_blank" class="card lcard" style="text-decoration:none; color:inherit; display:block;">
                        <div style="aspect-ratio:16/10; overflow:hidden; background:linear-gradient(135deg, var(--surface-2), color-mix(in srgb, var(--brand-icon) 14%, var(--surface-2)));">
                            @if($ac)<img src="{{ $ac }}" alt="{{ $article->title }}" style="width:100%; height:100%; object-fit:cover;">@endif
                        </div>
                        <div style="padding:1.15rem 1.25rem 1.35rem;">
                            <div style="font-weight:700; color:var(--brand-default); line-height:1.3;">{{ $article->title }}</div>
                            @if($article->excerpt)<p style="color:var(--text-secondary); font-size:.875rem; margin-top:.4rem; line-height:1.5;">{{ Str::limit($article->excerpt, 100) }}</p>@endif
                            <div class="text-xs" style="color:var(--text-muted); margin-top:.6rem;"><span class="num">{{ $article->readMinutes() }} MIN</span> • <span class="num">{{ number_format($article->wordCount()) }}</span> Words</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div style="text-align:center; padding-top:2.5rem; margin-top:3rem; border-top:1px solid var(--border); color:var(--text-muted); font-size:.8125rem;">
        {{ $agency->name ?? 'Home Finders Coastal' }}@if($agent->branch) · {{ $agent->branch->name }}@endif
    </div>
</div>

</body>
</html>
