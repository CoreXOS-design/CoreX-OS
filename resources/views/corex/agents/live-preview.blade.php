{{--
    Agent live preview — standalone (no corex-app chrome). "Coastal" light-on-white
    editorial style: profile + socials, testimonials, listings, About, articles.
    Spec: .ai/specs/testimonials.md (agent linkage).
--}}
@php
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\Storage;

    $brandDefault = $agency->default_color ?? '#0b2a4a';
    $brandButton  = $agency->button_color  ?? '#0ea5e9';
    $brandIcon    = $agency->icon_color    ?? '#0ea5e9';

    // Robust photo resolution — profilePhotoUrl() gates on Storage::exists(),
    // which can be falsely negative on some hosts. Fall back to the stored
    // path's public URL so an uploaded photo still renders.
    $photo = $agent->profilePhotoUrl();
    if (!$photo) {
        $doc  = $agent->documents()->where('document_type', 'profile_photo')->latest()->first();
        $path = $doc->file_path ?? $agent->agent_photo_path;
        if ($path) {
            $photo = Str::startsWith($path, ['http://', 'https://']) ? $path : Storage::disk('public')->url(ltrim($path, '/'));
        }
    }

    $waNumber = $agent->cell ? preg_replace('/[^0-9]/', '', $agent->cell) : null;
    $callNo   = $agent->cell ?: $agent->phone;

    // ── Social-share (Open Graph) preview ───────────────────────────────────
    // Drives the rich card shown when the public profile URL is pasted into
    // WhatsApp / Facebook / iMessage / Slack. Image + URL must be absolute and
    // publicly reachable — profilePhotoUrl()/asset() already return absolute URLs.
    $ogImage = $photo ?: (optional($agency)->logo_path ? asset('storage/'.$agency->logo_path) : null);
    $ogImageType = null;
    if ($ogImage) {
        $ext = strtolower(pathinfo((string) parse_url($ogImage, PHP_URL_PATH), PATHINFO_EXTENSION));
        $ogImageType = ['png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'image/jpeg';
    }
    $ogParts = collect([$agent->designation, optional($agency)->name, optional($agent->branch)->name])->filter();
    $ogDescription = $ogParts->isNotEmpty() ? $ogParts->implode(' · ') : 'Property Practitioner';
    if (!empty($agent->about_me)) {
        $ogDescription .= ' — ' . Str::limit(trim(preg_replace('/\s+/', ' ', $agent->about_me)), 140);
    }
    $ogUrl = $agent->publicProfileUrl();

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
            'active'                 => [$isRental ? 'To Let' : 'For Sale', '#047857', 'rgba(16,185,129,.12)'],
            'pending', 'under_offer' => ['Under Offer', '#b45309', 'rgba(245,158,11,.14)'],
            'sold'                   => ['Sold', '#1d4ed8', 'rgba(37,99,235,.12)'],
            default                  => [ucfirst((string) $p->status), '#64748b', 'rgba(100,116,139,.1)'],
        };
    };
    // Exclusive = sole mandate (the agency's protected stock).
    $isExclusive = fn ($p) => in_array(strtolower((string) $p->mandate_type), ['sole', 'sole mandate'], true);
    $listingImg = function ($l) {
        $img = collect(array_merge(
            $l->gallery_images_json ?? [], $l->dawn_images_json ?? [], $l->noon_images_json ?? [], $l->dusk_images_json ?? [],
        ))->filter()->first();
        if (!$img) return null;
        if (Str::startsWith($img, ['http://', 'https://'])) return $img;
        // Paths may be stored already-rooted ("/storage/…" or "storage/…") or
        // bare ("properties/…"). Only prepend the storage/ prefix when it's not
        // there already — otherwise we get a dead /storage/storage/… URL.
        $img = ltrim($img, '/');
        return Str::startsWith($img, 'storage/') ? asset($img) : asset('storage/'.$img);
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $agent->name }} — {{ $agency->name ?? 'Home Finders Coastal' }}</title>
    <meta name="robots" content="noindex">

    {{-- Open Graph — rich link preview (WhatsApp / Facebook / iMessage / Slack) --}}
    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="{{ $agency->name ?? 'Home Finders Coastal' }}">
    <meta property="og:title" content="{{ $agent->name }}{{ optional($agency)->name ? ' — '.$agency->name : '' }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $ogUrl }}">
    @if($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:secure_url" content="{{ $ogImage }}">
        @if($ogImageType)<meta property="og:image:type" content="{{ $ogImageType }}">@endif
        <meta property="og:image:alt" content="{{ $agent->name }}">
    @endif

    {{-- Twitter card (also honoured by some unfurlers) --}}
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $agent->name }}{{ optional($agency)->name ? ' — '.$agency->name : '' }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    @if($ogImage)<meta name="twitter:image" content="{{ $ogImage }}">@endif

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --navy:#141a4d;        /* headings */
            --marine:#3ba1e6;      /* fine accent — eyebrows, hovers, icons */
            --red:#df1f2c;         /* primary buttons */
            --red-bright:#f5404d;  /* primary hover */
            --text:#525252;        /* neutral-600 body */
            --muted:#737373;       /* neutral-500 */
            --muted-2:#a3a3a3;     /* neutral-400 */
            --border:#e2e8f0;      /* slate-200 */
            --slate-50:#f8fafc;
            --slate-100:#f1f5f9;
            --white:#ffffff;
            --brand-default:{{ $brandDefault }};  /* agency navy — listing tags */
            --brand-icon:{{ $brandIcon }};        /* agency icon colour — Call button */
        }
        * { box-sizing:border-box; }
        html { scroll-behavior:smooth; }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--white); color:var(--text); margin:0; -webkit-font-smoothing:antialiased; font-size:15px; line-height:1.6; }
        .num { font-family:'JetBrains Mono',ui-monospace,monospace; font-variant-numeric:tabular-nums; }

        .wrap { max-width:80rem; margin:0 auto; padding:0 1rem; }
        @media (min-width:640px){ .wrap{ padding:0 1.5rem; } }
        @media (min-width:1024px){ .wrap{ padding:0 2rem; } }

        /* eyebrow micro-label — wide-tracked uppercase marine */
        .label { font-size:.75rem; font-weight:500; letter-spacing:.3em; text-transform:uppercase; color:var(--marine); }
        /* section heading — thin navy */
        .title { font-size:1.5rem; font-weight:300; letter-spacing:-.01em; color:var(--navy); }
        @media (min-width:640px){ .title{ font-size:1.875rem; } }

        /* hero */
        .agent-hero { display:grid; gap:2rem; align-items:center; }
        @media (min-width:640px){ .agent-hero{ grid-template-columns:auto 1fr; } }
        .agent-photo { height:10rem; width:10rem; }
        @media (min-width:640px){ .agent-photo{ height:12rem; width:12rem; } }
        .agent-name { font-size:2.25rem; }
        @media (min-width:640px){ .agent-name{ font-size:3rem; } }

        /* cards — rounded-sm, slate borders, marine hover */
        .card { background:var(--white); border:1px solid var(--border); border-radius:.125rem; }
        .lcard { overflow:hidden; transition:border-color .25s ease, box-shadow .25s ease, transform .25s ease; }
        .lcard:hover { border-color:rgba(59,161,230,.5); box-shadow:0 12px 30px rgba(20,26,77,.07); transform:translateY(-2px); }

        /* buttons */
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:.5rem; padding:.75rem 1.75rem; border-radius:9999px; font-weight:600; font-size:.875rem; letter-spacing:.02em; text-decoration:none; border:1px solid transparent; cursor:pointer; transition:all .2s ease; }
        .btn-primary { background:var(--brand-icon); color:#fff; }
        .btn-primary:hover { filter:brightness(.92); }
        .btn-soft { background:#fff; color:var(--text); border-color:var(--border); }
        .btn-soft:hover { border-color:rgba(59,161,230,.5); color:var(--marine); }

        /* social — circular outlined, hover marine */
        .soc { width:2.75rem; height:2.75rem; border-radius:9999px; display:inline-flex; align-items:center; justify-content:center; background:transparent; color:var(--muted); border:1px solid var(--border); transition:all .2s ease; }
        .soc:hover { border-color:rgba(59,161,230,.5); color:var(--marine); }

        /* contact item — neutral text, neutral-400 icon, hover marine */
        .spec { display:inline-flex; align-items:center; gap:.5rem; color:var(--text); font-weight:500; font-size:.875rem; text-decoration:none; transition:color .2s ease; }
        .spec:hover { color:var(--marine); }
        .spec .ico { color:var(--muted-2); transition:color .2s ease; }
        .spec:hover .ico { color:var(--marine); }

        .ico { width:1rem; height:1rem; flex:0 0 1rem; }
        /* listing tag — matches the status pill on the Properties page */
        .badge { display:inline-flex; align-items:center; padding:.25rem .625rem; border-radius:9999px; font-size:.75rem; font-weight:600; }

        /* share — dropdown anchored to the Share button */
        .share-wrap { position:relative; display:inline-block; }
        .share-menu { position:absolute; z-index:40; top:calc(100% + .5rem); left:0; min-width:12.5rem; background:var(--white); border:1px solid var(--border); border-radius:.5rem; box-shadow:0 16px 40px rgba(20,26,77,.14); padding:.375rem; opacity:0; transform:translateY(-4px); pointer-events:none; transition:opacity .16s ease, transform .16s ease; }
        .share-wrap.open .share-menu { opacity:1; transform:translateY(0); pointer-events:auto; }
        .share-item { display:flex; align-items:center; gap:.65rem; width:100%; padding:.55rem .7rem; border-radius:.375rem; background:transparent; border:0; text-align:left; cursor:pointer; color:var(--text); font-size:.875rem; font-weight:500; text-decoration:none; font-family:inherit; }
        .share-item:hover { background:var(--slate-100); color:var(--navy); }
        .share-item svg { width:1.1rem; height:1.1rem; flex:0 0 1.1rem; color:var(--muted); }
        .share-item:hover svg { color:var(--marine); }

        /* listing filter tabs — pill chips above the grid */
        .tabs { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1.75rem; }
        .tab { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem 1rem; border-radius:9999px; border:1px solid var(--border); background:#fff; color:var(--text); font-size:.8125rem; font-weight:600; letter-spacing:.01em; cursor:pointer; font-family:inherit; transition:all .18s ease; }
        .tab:hover { border-color:rgba(59,161,230,.5); color:var(--marine); }
        .tab.active { background:var(--brand-default); border-color:var(--brand-default); color:#fff; }
        .tab .cnt { font-family:'JetBrains Mono',ui-monospace,monospace; font-size:.7rem; opacity:.7; }
        .tab.active .cnt { opacity:.85; }
    </style>
</head>
<body>

{{-- HERO — light on white --}}
<section style="border-bottom:1px solid var(--border);">
    <div class="wrap" style="padding-top:2.75rem; padding-bottom:2.75rem;">
        <div class="agent-hero">
            <div>
                @if($photo)
                    <img src="{{ $photo }}" alt="{{ $agent->name }}" class="agent-photo" style="border-radius:1rem; object-fit:cover; background:var(--slate-100); border:1px solid var(--border);">
                @else
                    <div class="agent-photo" style="border-radius:1rem; background:var(--slate-100); color:var(--navy); display:flex; align-items:center; justify-content:center; font-weight:300; font-size:2.75rem;">{{ $agent->initials() }}</div>
                @endif
            </div>
            <div class="min-w-0">
                @if(optional($agency)->name)<div class="label" style="margin-bottom:.6rem;">{{ $agency->name }}</div>@endif
                <h1 class="agent-name" style="font-weight:300; letter-spacing:-.02em; line-height:1.05; color:var(--navy);">{{ $agent->name }}</h1>
                @if($agent->designation)<div style="margin-top:.5rem; color:var(--muted); font-size:1.125rem;">{{ $agent->designation }}</div>@endif
                @if(optional($agent->branch)->name)<div class="mt-1 text-sm" style="color:var(--muted-2);">{{ $agent->branch->name }}</div>@endif

                <div class="mt-4 flex flex-wrap" style="column-gap:2rem; row-gap:.5rem;">
                    @if($callNo)<a href="tel:{{ $callNo }}" class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.37c0-.52-.35-.97-.85-1.09l-4.42-1.11c-.44-.11-.9.06-1.17.42l-.97 1.29c-.28.38-.77.54-1.21.38a12.04 12.04 0 0 1-7.14-7.14c-.16-.44 0-.93.38-1.21l1.29-.97c.36-.27.53-.73.42-1.17L6.96 3.1A1.13 1.13 0 0 0 5.87 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5Z"/></svg><span class="num">{{ $callNo }}</span></a>@endif
                    @if($agent->outward_email)<a href="mailto:{{ $agent->outward_email }}" class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5H4.5A2.25 2.25 0 0 0 2.25 6.75m19.5 0v.24a2.25 2.25 0 0 1-1.07 1.92l-7.5 4.61a2.25 2.25 0 0 1-2.36 0l-7.5-4.61A2.25 2.25 0 0 1 2.25 6.99v-.24"/></svg>{{ $agent->outward_email }}</a>@endif
                </div>

                <div class="mt-5 flex items-center gap-3 flex-wrap">
                    @if($callNo)<a href="tel:{{ $callNo }}" class="btn btn-primary">Call</a>@endif
                    @if($waNumber)<a href="https://wa.me/{{ $waNumber }}" target="_blank" class="btn btn-soft">WhatsApp</a>@endif
                    @if($agent->outward_email)<a href="mailto:{{ $agent->outward_email }}" class="btn btn-soft">Email</a>@endif
                    @foreach($socials as $net => $url)
                        <a class="soc" target="_blank" rel="noopener" title="{{ ucfirst($net) }}" href="{{ Str::startsWith($url, ['http://','https://']) ? $url : 'https://'.$url }}">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">{!! $socialIcon[$net] !!}</svg>
                        </a>
                    @endforeach

                    {{-- SHARE — copy link / WhatsApp / Email / Facebook / X (uses native share sheet on mobile) --}}
                    @php
                        $shareUrl   = $ogUrl;
                        $shareTitle = trim($agent->name.(optional($agency)->name ? ' — '.$agency->name : ''));
                        $shareText  = $shareTitle.' · '.$ogDescription;
                    @endphp
                    <div class="share-wrap" id="shareWrap"
                         data-url="{{ $shareUrl }}" data-title="{{ $shareTitle }}" data-text="{{ $shareText }}">
                        <button type="button" class="btn btn-soft" id="shareBtn" aria-haspopup="true" aria-expanded="false">
                            <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 12a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm0 0 9-5.25M7.5 12l9 5.25m0 0a2.25 2.25 0 1 0 4.5 0 2.25 2.25 0 0 0-4.5 0Zm0-10.5a2.25 2.25 0 1 0 4.5 0 2.25 2.25 0 0 0-4.5 0Z"/></svg>
                            Share
                        </button>
                        <div class="share-menu" role="menu" aria-labelledby="shareBtn">
                            <a class="share-item" role="menuitem" data-share="whatsapp" target="_blank" rel="noopener" href="#">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M.05 24l1.69-6.16A11.9 11.9 0 0 1 .15 11.9C.15 5.34 5.49 0 12.05 0a11.82 11.82 0 0 1 8.41 3.49 11.82 11.82 0 0 1 3.48 8.42c0 6.56-5.34 11.9-11.89 11.9a11.9 11.9 0 0 1-5.7-1.45L.05 24Zm6.6-3.8c1.68.99 3.28 1.59 5.4 1.59 5.44 0 9.87-4.43 9.88-9.88a9.87 9.87 0 0 0-16.85-6.99A9.82 9.82 0 0 0 2.2 11.9c0 2.2.61 3.85 1.65 5.57l-.99 3.6 3.79-.87Zm11.36-5.29c-.07-.12-.26-.2-.55-.34-.29-.14-1.72-.85-1.99-.95-.26-.1-.46-.14-.65.14-.19.29-.74.95-.91 1.14-.17.19-.34.22-.62.07-.29-.14-1.22-.45-2.32-1.43-.86-.77-1.44-1.72-1.61-2-.17-.29-.02-.45.12-.59.13-.13.29-.34.43-.51.15-.17.19-.29.29-.48.1-.19.05-.36-.02-.51-.07-.14-.65-1.57-.89-2.15-.24-.56-.47-.48-.65-.49l-.55-.01c-.19 0-.5.07-.76.36-.26.29-1 .98-1 2.38 0 1.41 1.02 2.77 1.17 2.96.14.19 2.01 3.06 4.86 4.29.68.29 1.21.47 1.62.6.68.22 1.3.19 1.79.11.55-.08 1.72-.7 1.96-1.38.24-.68.24-1.26.17-1.38Z"/></svg>
                                WhatsApp
                            </a>
                            <a class="share-item" role="menuitem" data-share="email" href="#">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5H4.5A2.25 2.25 0 0 0 2.25 6.75m19.5 0v.24a2.25 2.25 0 0 1-1.07 1.92l-7.5 4.61a2.25 2.25 0 0 1-2.36 0l-7.5-4.61A2.25 2.25 0 0 1 2.25 6.99v-.24"/></svg>
                                Email
                            </a>
                            <a class="share-item" role="menuitem" data-share="facebook" target="_blank" rel="noopener" href="#">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z"/></svg>
                                Facebook
                            </a>
                            <a class="share-item" role="menuitem" data-share="x" target="_blank" rel="noopener" href="#">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.22-6.82-5.97 6.82H.9l7.73-8.83L.06 2.25h6.83l4.71 6.23 5.44-6.23Zm-1.16 17.52h1.83L7.02 4.13H5.05l12.03 15.64Z"/></svg>
                                X (Twitter)
                            </a>
                            <button type="button" class="share-item" role="menuitem" data-share="copy" id="shareCopy">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.69 15 6.88a4.5 4.5 0 1 1 6.36 6.37l-3.18 3.18a4.5 4.5 0 0 1-6.37 0 4.51 4.51 0 0 1-.66-.82m1.66-6.6a4.5 4.5 0 0 0-6.37 0l-3.18 3.18a4.5 4.5 0 1 0 6.36 6.37L10.81 15"/></svg>
                                <span id="shareCopyLabel">Copy link</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="wrap" style="padding-top:4rem; padding-bottom:4rem;">

    {{-- ABOUT --}}
    @if(!empty($agent->about_me))
        <section style="margin-bottom:4rem;">
            <div class="label">About</div>
            <h2 class="title" style="margin:.6rem 0 1.5rem;">Get to Know Me</h2>
            <p style="color:var(--text); line-height:1.85; white-space:pre-line; max-width:48rem;">{{ $agent->about_me }}</p>
        </section>
    @endif

    {{-- LISTINGS --}}
    @php
        $cntAll       = $listings->count();
        $cntActive    = $listings->filter(fn ($p) => (string) $p->status === 'active')->count();
        $cntSold      = $listings->filter(fn ($p) => (string) $p->status === 'sold')->count();
        $cntExclusive = $listings->filter($isExclusive)->count();
        $perPage      = 20;
    @endphp
    <section id="listings" style="margin-bottom:4rem;">
        <div class="label">Properties</div>
        <h2 class="title" style="margin:.6rem 0 1.25rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} <span id="listingCount">{{ $cntAll }}</span> <span id="listingNoun">Listing{{ $cntAll === 1 ? '' : 's' }}</span></h2>
        @if($listings->isEmpty())
            <div class="card" style="padding:2.5rem; text-align:center; color:var(--muted-2);">No listings yet.</div>
        @else
            {{-- Filter tabs — All / Active / Sold / Exclusive (sole mandate) --}}
            <div class="tabs" id="listingTabs" role="tablist">
                <button type="button" class="tab active" data-filter="all" role="tab" aria-selected="true">All <span class="cnt">{{ $cntAll }}</span></button>
                @if($cntActive)<button type="button" class="tab" data-filter="active" role="tab" aria-selected="false">Active <span class="cnt">{{ $cntActive }}</span></button>@endif
                @if($cntSold)<button type="button" class="tab" data-filter="sold" role="tab" aria-selected="false">Sold <span class="cnt">{{ $cntSold }}</span></button>@endif
                @if($cntExclusive)<button type="button" class="tab" data-filter="exclusive" role="tab" aria-selected="false">Exclusive <span class="cnt">{{ $cntExclusive }}</span></button>@endif
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" id="listingGrid" data-per-page="{{ $perPage }}">
                @foreach($listings as $l)
                    @php [$lbl,$lc,$lbg] = $statusFor($l); $src = $listingImg($l); @endphp
                    <a href="{{ route('corex.properties.preview', $l) }}" target="_blank"
                       class="card lcard listing-card"
                       data-status="{{ (string) $l->status }}"
                       data-exclusive="{{ $isExclusive($l) ? '1' : '0' }}"
                       style="text-decoration:none; color:inherit; display:block;">
                        <div style="position:relative; aspect-ratio:4/3; background:var(--slate-100); overflow:hidden;">
                            @if($src)<img src="{{ $src }}" alt="{{ $l->title }}" loading="lazy" style="width:100%; height:100%; object-fit:cover;">@else<div class="flex items-center justify-center h-full" style="color:var(--muted-2); font-size:.8rem;">No image</div>@endif
                            <span class="badge" style="position:absolute; top:.7rem; left:.7rem; {{ (string) $l->status === 'sold' ? 'background:var(--red);' : 'background:var(--brand-default);' }} color:#fff;">{{ $lbl }}</span>
                        </div>
                        <div style="padding:1.1rem 1.25rem 1.35rem;">
                            <div style="font-size:1.35rem; font-weight:300; letter-spacing:-.01em; color:var(--navy);">{{ $l->formattedPrice() }}</div>
                            @if($l->property_type)<div style="color:var(--muted); margin-top:.15rem;">{{ ucfirst($l->property_type) }}</div>@endif
                            <div class="text-sm inline-flex items-start gap-1.5" style="color:var(--muted-2); margin-top:.25rem;">
                                <svg class="ico" style="margin-top:2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                                <span>{{ trim(collect([$l->suburb, $l->city ?: $l->region, $l->province])->filter()->unique()->take(2)->implode(', ')) ?: $l->buildDisplayAddress() }}</span>
                            </div>
                            <div class="flex items-center gap-5 mt-3 pt-3" style="border-top:1px solid var(--border); color:var(--text);">
                                @if($l->beds !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12V6.75A.75.75 0 0 1 3.75 6h16.5a.75.75 0 0 1 .75.75V12m-18 0v6m0-6h18m0 0v6M6 12V9.75A.75.75 0 0 1 6.75 9h3a.75.75 0 0 1 .75.75V12m3 0V9.75A.75.75 0 0 1 14.25 9h3a.75.75 0 0 1 .75.75V12"/></svg>{{ (int) $l->beds }}</span>@endif
                                @if($l->baths !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 11.25V6a2.25 2.25 0 0 1 4.4-.66M3 11.25h18v2.25a5.25 5.25 0 0 1-5.25 5.25H8.25A5.25 5.25 0 0 1 3 13.5v-2.25Zm3.75 7.5L6 21m11.25-2.25L18 21"/></svg>{{ rtrim(rtrim((string) $l->baths, '0'), '.') }}</span>@endif
                                @if($l->garages !== null)<span class="spec"><svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75V9.31c0-.51.3-.97.78-1.16l8.25-3.3a1.5 1.5 0 0 1 1.12 0l8.25 3.3c.47.19.78.65.78 1.16v9.44M2.25 18.75h19.5M4.5 18.75v-6h15v6"/></svg>{{ (int) $l->garages }}</span>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Empty state per-filter (JS toggles when a tab has no on-screen matches) --}}
            <div id="listingEmpty" class="card" style="display:none; padding:2.5rem; text-align:center; color:var(--muted-2);">No listings in this view.</div>

            {{-- Load more — reveals 20 at a time within the active filter --}}
            <div id="loadMoreWrap" style="display:none; text-align:center; margin-top:2.25rem;">
                <button type="button" id="loadMoreBtn" class="btn btn-soft">Show more</button>
            </div>
        @endif
    </section>

    {{-- TESTIMONIALS --}}
    @if($testimonials->isNotEmpty())
        <section style="margin-bottom:4rem;">
            <div class="label">Testimonials</div>
            <h2 class="title" style="margin:.6rem 0 1.75rem;">What clients say</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($testimonials as $t)
                    <div class="card" style="padding:1.5rem; background:var(--slate-50);">
                        <svg style="width:2rem; height:2rem; color:rgba(59,161,230,.6); margin-bottom:.85rem;" viewBox="0 0 24 24" fill="currentColor"><path d="M9.983 3v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983zm14.017 0v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983z"/></svg>
                        @if($t->rating)
                            <div class="flex items-center" style="gap:.2rem; margin-bottom:.65rem;">
                                @for($i = 0; $i < (int) $t->rating; $i++)
                                    <svg style="width:1rem; height:1rem; color:var(--marine);" viewBox="0 0 24 24" fill="currentColor"><path d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.12 5.11a.56.56 0 0 0 .48.35l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 0 0-.18.56l1.28 5.38a.56.56 0 0 1-.84.61l-4.72-2.88a.56.56 0 0 0-.59 0l-4.72 2.88a.56.56 0 0 1-.84-.61l1.28-5.38a.56.56 0 0 0-.18-.56l-4.2-3.6a.56.56 0 0 1 .32-.99l5.52-.44a.56.56 0 0 0 .48-.35L11.48 3.5Z"/></svg>
                                @endfor
                            </div>
                        @endif
                        <p style="color:var(--text); line-height:1.75;">“{{ $t->body }}”</p>
                        <div class="text-sm" style="color:var(--navy); margin-top:1rem;">— {{ $t->display_name }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ARTICLES --}}
    @if($articles->isNotEmpty())
        <section>
            <div class="label">Insights</div>
            <h2 class="title" style="margin:.6rem 0 1.75rem;">{{ $isSelf ? 'My' : $agent->name."'s" }} Articles</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($articles as $article)
                    @php
                        $ac = $article->coverImageUrl();
                        $articleHref = ($isPublic ?? false)
                            ? route('corex.agents.public.article', [$agent->nameSlug(), $publicTag, $article])
                            : route('corex.agents.article.preview', [$agent, $article, $article->previewSlug()]);
                    @endphp
                    <a href="{{ $articleHref }}" target="_blank" class="card lcard" style="text-decoration:none; color:inherit; display:block;">
                        <div style="aspect-ratio:16/10; overflow:hidden; background:var(--slate-100);">
                            @if($ac)<img src="{{ $ac }}" alt="{{ $article->title }}" style="width:100%; height:100%; object-fit:cover;">@endif
                        </div>
                        <div style="padding:1.1rem 1.25rem 1.35rem;">
                            <div style="font-weight:600; color:var(--navy); line-height:1.35;">{{ $article->title }}</div>
                            @if($article->excerpt)<p style="color:var(--text); font-size:.875rem; margin-top:.35rem; line-height:1.6;">{{ Str::limit($article->excerpt, 96) }}</p>@endif
                            <div class="text-xs" style="color:var(--muted-2); margin-top:.7rem;"><span class="num">{{ $article->readMinutes() }} MIN</span> • <span class="num">{{ number_format($article->wordCount()) }}</span> Words</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div style="text-align:center; padding-top:2.5rem; margin-top:4rem; border-top:1px solid var(--border); color:var(--muted-2); font-size:.8125rem;">
        {{ $agency->name ?? 'Home Finders Coastal' }}@if($agent->branch) · {{ $agent->branch->name }}@endif
        <div style="margin-top:.4rem;">Registered with the PPRA</div>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ── SHARE ────────────────────────────────────────────────────────────────
    var wrap = document.getElementById('shareWrap');
    if (wrap) {
        var url   = wrap.dataset.url || window.location.href;
        var title = wrap.dataset.title || document.title;
        var text  = wrap.dataset.text || title;
        var eu = encodeURIComponent(url), et = encodeURIComponent(text), etitle = encodeURIComponent(title);

        wrap.querySelectorAll('[data-share]').forEach(function (el) {
            var kind = el.getAttribute('data-share');
            if (kind === 'whatsapp') el.href = 'https://wa.me/?text=' + encodeURIComponent(text + ' ' + url);
            else if (kind === 'facebook') el.href = 'https://www.facebook.com/sharer/sharer.php?u=' + eu;
            else if (kind === 'x') el.href = 'https://twitter.com/intent/tweet?url=' + eu + '&text=' + et;
            else if (kind === 'email') el.href = 'mailto:?subject=' + etitle + '&body=' + encodeURIComponent(text + '\n\n' + url);
        });

        var btn = document.getElementById('shareBtn');
        var closeMenu = function () { wrap.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };
        var openMenu  = function () { wrap.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); };

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            // Prefer the OS share sheet on devices that support it (mobile).
            if (navigator.share) {
                navigator.share({ title: title, text: text, url: url }).catch(function () {});
                return;
            }
            wrap.classList.contains('open') ? closeMenu() : openMenu();
        });

        var copyBtn = document.getElementById('shareCopy');
        if (copyBtn) {
            copyBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var done = function () {
                    var lbl = document.getElementById('shareCopyLabel');
                    if (lbl) { var t = lbl.textContent; lbl.textContent = 'Copied!'; setTimeout(function () { lbl.textContent = t; }, 1500); }
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done).catch(done);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = url; document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); } catch (err) {}
                    document.body.removeChild(ta); done();
                }
            });
        }

        // Clicking a real share link should also close the menu.
        wrap.querySelectorAll('a.share-item').forEach(function (a) {
            a.addEventListener('click', function () { setTimeout(closeMenu, 0); });
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) closeMenu(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    }

    // ── LISTINGS: filter tabs + reveal 20 at a time ──────────────────────────
    var grid = document.getElementById('listingGrid');
    if (grid) {
        var perPage   = parseInt(grid.dataset.perPage, 10) || 20;
        var cards     = Array.prototype.slice.call(grid.querySelectorAll('.listing-card'));
        var tabs      = Array.prototype.slice.call(document.querySelectorAll('#listingTabs .tab'));
        var loadWrap  = document.getElementById('loadMoreWrap');
        var loadBtn   = document.getElementById('loadMoreBtn');
        var emptyBox  = document.getElementById('listingEmpty');
        var countEl   = document.getElementById('listingCount');
        var nounEl    = document.getElementById('listingNoun');
        var filter    = 'all';
        var shown     = perPage;

        var matches = function (card) {
            if (filter === 'all') return true;
            if (filter === 'exclusive') return card.dataset.exclusive === '1';
            return card.dataset.status === filter;
        };

        var render = function () {
            var seen = 0;
            cards.forEach(function (card) {
                if (matches(card)) {
                    seen++;
                    card.style.display = seen <= shown ? '' : 'none';
                } else {
                    card.style.display = 'none';
                }
            });
            var total = seen;
            if (countEl) countEl.textContent = total;
            if (nounEl)  nounEl.textContent = 'Listing' + (total === 1 ? '' : 's');
            if (emptyBox) emptyBox.style.display = total === 0 ? '' : 'none';
            if (loadWrap) loadWrap.style.display = total > shown ? '' : 'none';
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');
                filter = tab.dataset.filter;
                shown = perPage;
                render();
                document.getElementById('listings').scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        if (loadBtn) loadBtn.addEventListener('click', function () { shown += perPage; render(); });

        render();
    }
})();
</script>

</body>
</html>
