{{--
    Single agent article — standalone preview (the "click to read" view).
    Mirrors the public website article page. Spec: .ai/specs/testimonials.md.
--}}
@php
    use Illuminate\Support\Str;
    $brandDefault = $agency->default_color ?? '#0b2a4a';
    $brandButton  = $agency->button_color  ?? '#0ea5e9';
    $brandIcon    = $agency->icon_color    ?? '#0ea5e9';
    $photo  = $agent->profilePhotoUrl();
    $cover  = $article->coverImageUrl();
    $tags   = $article->tagList();
    $shareUrl = route('corex.agents.article.preview', [$agent, $article, $article->previewSlug()]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $article->title }} — {{ $agent->name }}</title>
    <meta name="robots" content="noindex">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-default: {{ $brandDefault }}; --brand-button: {{ $brandButton }}; --brand-icon: {{ $brandIcon }};
            --bg:#f4f6fb; --surface:#ffffff; --surface-2:#f0f2f8; --border:rgba(0,0,0,0.08);
            --text-primary:#111827; --text-secondary:#4b5563; --text-muted:#9ca3af;
        }
        * { box-sizing:border-box; }
        body { font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text-primary); margin:0; -webkit-font-smoothing:antialiased; }
        .wrap { max-width:760px; margin:0 auto; padding:2.5rem 1.25rem 4rem; }
        .num { font-family:'JetBrains Mono',ui-monospace,monospace; }
        .body-copy { color:var(--text-secondary); line-height:1.8; font-size:1.0625rem; white-space:pre-line; }
        .chip { display:inline-block; padding:.25rem .625rem; border-radius:9999px; background:var(--surface-2); color:var(--brand-icon); font-size:.75rem; font-weight:600; margin:.15rem; }
        .corex-btn-primary { display:inline-flex; align-items:center; gap:.5rem; padding:.625rem 1rem; border-radius:6px; background:var(--brand-button); color:#fff; font-weight:600; font-size:.875rem; text-decoration:none; border:0; cursor:pointer; }
        .corex-btn-outline { display:inline-flex; align-items:center; gap:.5rem; padding:.5rem .875rem; border-radius:6px; background:var(--surface); color:var(--text-primary); font-weight:600; font-size:.8125rem; text-decoration:none; border:1px solid var(--border); }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:10px; }
        a.back { color:#fff; opacity:.9; text-decoration:underline; font-size:.8125rem; }
    </style>
</head>
<body>
    <div style="position:sticky; top:0; z-index:30; background:var(--brand-default); color:#fff;" class="px-4 py-2.5">
        <div style="max-width:760px; margin:0 auto;" class="flex items-center justify-between">
            <span class="text-xs" style="font-weight:700;">Article preview</span>
            <a class="back" href="{{ route('corex.agents.preview', $agent) }}">← Back to agent page</a>
        </div>
    </div>

    <div class="wrap">
        <h1 style="font-size:2rem; font-weight:800; line-height:1.15; color:var(--brand-default);">{{ $article->title }}</h1>
        <div class="mt-3 text-sm" style="color:var(--text-muted);">
            by <span style="color:var(--text-secondary); font-weight:600;">{{ $agent->name }}</span>
            · <span class="num">{{ $article->readMinutes() }} MIN</span>
            · <span class="num">{{ number_format($article->wordCount()) }}</span> Words
        </div>

        @if($cover)
            <img src="{{ $cover }}" alt="{{ $article->title }}" style="width:100%; border-radius:10px; margin-top:1.5rem; object-fit:cover; max-height:420px;">
        @endif

        @if($article->excerpt)
            <p style="margin-top:1.5rem; font-size:1.1875rem; font-weight:600; color:var(--text-primary); line-height:1.6;">{{ $article->excerpt }}</p>
        @endif

        <div class="body-copy" style="margin-top:1.25rem;">{{ $article->body }}</div>

        @if($article->link_url)
            <div style="margin-top:1.75rem;">
                <a href="{{ $article->link_url }}" target="_blank" rel="noopener" class="corex-btn-primary">Read more ↗</a>
            </div>
        @endif

        @if(!empty($tags))
            <div style="margin-top:1.75rem;">
                @foreach($tags as $tag)<span class="chip">#{{ $tag }}</span>@endforeach
            </div>
        @endif

        {{-- Share --}}
        <div style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--border); text-align:center;">
            <div style="font-size:.6875rem; letter-spacing:.3em; color:var(--text-muted); font-weight:700;">• S H A R E •</div>
            <div class="flex items-center justify-center gap-2 mt-3">
                <a class="corex-btn-outline" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}">Facebook</a>
                <a class="corex-btn-outline" target="_blank" rel="noopener" href="https://wa.me/?text={{ urlencode($article->title.' '.$shareUrl) }}">WhatsApp</a>
                <a class="corex-btn-outline" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode($shareUrl) }}">LinkedIn</a>
            </div>
        </div>

        {{-- Author card --}}
        <div class="card mt-8" style="padding:1.5rem; text-align:center;">
            @if($photo)
                <img src="{{ $photo }}" alt="{{ $agent->name }}" style="width:72px; height:72px; border-radius:9999px; object-fit:cover; margin:0 auto;">
            @else
                <div style="width:72px; height:72px; border-radius:9999px; background:var(--brand-button); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.25rem; margin:0 auto;">{{ $agent->initials() }}</div>
            @endif
            <div style="font-weight:700; color:var(--brand-default); margin-top:.75rem;">{{ $agent->name }}</div>
            @if($agent->designation)<div class="text-sm" style="color:var(--text-muted);">{{ $agent->designation }}</div>@endif
            <a href="{{ route('corex.agents.preview', $agent) }}" class="corex-btn-primary" style="margin-top:1rem;">View My Profile</a>
        </div>
    </div>
</body>
</html>
