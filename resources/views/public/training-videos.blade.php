{{--
    Public, logged-out CoreX Training Videos page.
    Spec: .ai/specs/training-videos.md §4.3

    Standalone rather than extending public/legal/layout (that layout always prints a
    "Last updated" line). CoreX-branded only — never an agency's name or branding (multi-agency rule).

    Look: "Clean Light" (chosen by Andre 2026-10-27 from five directions): airy hero with the search,
    group chips, soft rounded cards that lift on hover. System font stack on purpose — no web-font
    request to a third party from a public page (same POPIA-friendly stance as the click-to-play players).

    Players are click-to-play: only the thumbnail loads until the visitor presses play,
    then the youtube-nocookie iframe is swapped in (spec D3). Uploaded files use the browser's
    own player with preload="none" (spec §8 U10).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Videos — CoreX OS</title>
    <meta name="description" content="CoreX OS training videos — watch how to use CoreX, step by step.">
    <style>
        :root {
            --brand: #0b2a4a;
            --accent: #33c4e0;
            --accent-text: #0b6f84;
            --accent-soft: #e6f7fb;
            --text: #0f2238;
            --text-muted: #4a5d73;
            --text-faint: #5b6e84;
            --border: #e1e9f2;
            --border-strong: #d3dee9;
            --surface: #ffffff;
            --page: #f6f9fc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text); background: var(--page);
            line-height: 1.6;
        }
        a { color: var(--accent-text); }
        a:hover { color: var(--brand); }

        .wrap { max-width: 1240px; margin: 0 auto; padding-left: clamp(20px, 5vw, 72px); padding-right: clamp(20px, 5vw, 72px); }

        header.brand-bar {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding-top: 20px; padding-bottom: 20px;
        }
        header.brand-bar .logo { font-size: 1.25rem; font-weight: 800; letter-spacing: -0.3px; color: var(--brand); }
        header.brand-bar .logo .os { color: #1aa6c4; }
        header.brand-bar .signin {
            font-size: 0.875rem; font-weight: 600; text-decoration: none; padding: 9px 18px;
            border-radius: 999px; border: 1px solid var(--border-strong); background: var(--surface); color: var(--brand);
        }
        header.brand-bar .signin:hover { border-color: var(--accent); }

        .hero { padding-top: clamp(36px, 6vw, 84px); padding-bottom: 36px; }
        .eyebrow {
            display: inline-block; font-size: 0.8rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--accent-text); background: var(--accent-soft); padding: 6px 14px; border-radius: 999px;
        }
        h1 {
            margin: 18px 0 14px; font-size: clamp(2.1rem, 5.2vw, 4rem); line-height: 1.04; letter-spacing: -0.035em;
            font-weight: 800; color: var(--brand); max-width: 820px;
        }
        .lede { margin: 0 0 30px; font-size: clamp(1rem, 1.6vw, 1.25rem); line-height: 1.55; color: var(--text-muted); max-width: 640px; }

        .search {
            display: flex; align-items: center; gap: 10px; max-width: 640px; padding: 8px;
            background: var(--surface); border: 1px solid #d9e3ee; border-radius: 999px;
            box-shadow: 0 10px 30px -18px rgba(11, 42, 74, 0.35);
        }
        .search svg { flex: none; margin-left: 14px; color: #6a7d92; }
        .search input {
            flex: 1; min-width: 0; border: 0; outline: 0; background: transparent;
            font-family: inherit; font-size: 1rem; font-weight: 500; color: var(--text);
        }
        .search:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(51, 196, 224, 0.25); }
        .search button {
            border: 0; cursor: pointer; padding: 12px 26px; border-radius: 999px;
            background: var(--brand); color: #fff; font-family: inherit; font-size: 0.95rem; font-weight: 600;
        }
        .search button:hover { background: #12406d; }

        .jump { display: flex; flex-wrap: wrap; gap: 10px; margin: 26px 0 0; padding: 0; list-style: none; }
        .jump a {
            display: inline-block; padding: 9px 18px; font-size: 0.875rem; font-weight: 600; text-decoration: none;
            border: 1px solid var(--border-strong); border-radius: 999px; color: var(--brand); background: var(--surface);
        }
        .jump a:hover { background: var(--accent-soft); border-color: var(--accent); }

        main.content { padding-bottom: 72px; }
        section.group { padding-top: 44px; scroll-margin-top: 16px; }
        .group-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 20px; }
        h2 { margin: 0; font-size: 1.65rem; letter-spacing: -0.02em; font-weight: 700; color: var(--brand); }
        .count { font-size: 0.875rem; color: var(--text-faint); font-weight: 500; }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 26px; }
        .video {
            background: var(--surface); border: 1px solid var(--border); border-radius: 18px; overflow: hidden;
            box-shadow: 0 2px 6px rgba(11, 42, 74, 0.05);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .video:hover { transform: translateY(-4px); box-shadow: 0 18px 40px -16px rgba(11, 42, 74, 0.35); }
        .video .body { padding: 18px 20px 22px; }
        .video h3 { font-size: 1.06rem; line-height: 1.3; letter-spacing: -0.01em; font-weight: 700; color: var(--brand); margin: 0 0 6px; }
        .video p { margin: 0; font-size: 0.9rem; line-height: 1.55; color: var(--text-muted); white-space: pre-line; }
        .video .meta { margin-top: 12px; font-size: 0.8rem; font-weight: 600; color: var(--accent-text); }
        .video .watch { display: inline-block; margin-top: 12px; font-size: 0.8rem; font-weight: 600; }

        .player {
            position: relative; display: block; width: 100%; aspect-ratio: 16 / 9; padding: 0;
            border: 0; overflow: hidden; background: #000; cursor: pointer;
        }
        .player img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .player iframe { width: 100%; height: 100%; border: 0; display: block; }
        .player .play {
            position: absolute; inset: 0; margin: auto; width: 58px; height: 58px; border-radius: 50%;
            background: var(--brand); display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 20px rgba(11, 42, 74, 0.4); transition: transform 0.15s ease, background 0.15s ease;
        }
        .player:hover .play, .player:focus-visible .play { background: #12406d; transform: scale(1.08); }
        .player:focus-visible { outline: 3px solid var(--accent); outline-offset: -3px; }
        .player .play::after {
            content: ''; border-style: solid; border-width: 10px 0 10px 17px;
            border-color: transparent transparent transparent #fff; margin-left: 4px;
        }
        /* Uploaded files: the browser's own player; preload="none" so nothing downloads until play (spec §8 U10). */
        .file-player { display: block; width: 100%; aspect-ratio: 16 / 9; background: #000; }

        .empty { text-align: center; color: var(--text-muted); padding: 56px 0; font-size: 1.05rem; }
        footer.doc-footer {
            text-align: center; padding: 28px 20px 44px; font-size: 0.8rem; color: var(--text-faint);
            border-top: 1px solid var(--border);
        }
        footer.doc-footer a { color: var(--text-faint); }

        @media (prefers-reduced-motion: reduce) {
            .video, .player .play { transition: none; }
            .video:hover, .player:hover .play { transform: none; }
        }
        @media (max-width: 640px) {
            .search button { padding: 12px 18px; }
            .group-head { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <header class="brand-bar wrap">
        <span class="logo">corex<span class="os"> os</span></span>
        <a class="signin" href="{{ route('login') }}">Sign in</a>
    </header>

    <section class="hero wrap">
        <span class="eyebrow">Training</span>
        <h1>Learn CoreX, step by step.</h1>
        <p class="lede">Walkthroughs for agents, admins and new agencies. Watch any time — no login needed.</p>

        @if ($hasAny)
            <form class="search" method="GET" action="{{ route('public.training-videos') }}" role="search">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="{{ $q }}" placeholder="Search videos" aria-label="Search videos">
                <button type="submit">Search</button>
            </form>

            @if ($sections->count() > 1)
                <ul class="jump">
                    @foreach ($sections as $i => $section)
                        <li><a href="#section-{{ $i }}">{{ $section['name'] }}</a></li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>

    <main class="content wrap">
        @if (! $hasAny)
            <p class="empty">Training videos are coming soon.</p>
        @elseif ($sections->isEmpty())
            <p class="empty">
                No videos match “{{ $q }}”.
                <a href="{{ route('public.training-videos') }}">Show all videos</a>
            </p>
        @else
            @foreach ($sections as $i => $section)
                <section class="group" id="section-{{ $i }}">
                    <div class="group-head">
                        <h2>{{ $section['name'] }}</h2>
                        <span class="count">{{ $section['videos']->count() }} {{ \Illuminate\Support\Str::plural('video', $section['videos']->count()) }}</span>
                    </div>
                    <div class="grid">
                        @foreach ($section['videos'] as $video)
                            <article class="video">
                                @if ($video->isUpload())
                                    <video class="file-player" controls preload="none" playsinline
                                           poster="{{ $video->posterUrl() }}" src="{{ $video->fileUrl() }}"
                                           aria-label="{{ $video->title }}"></video>
                                @else
                                    <button type="button" class="player" data-embed="{{ $video->embedUrl() }}" data-title="{{ $video->title }}"
                                            aria-label="Play {{ $video->title }}">
                                        <img src="{{ $video->thumbnailUrl() }}" alt="" loading="lazy">
                                        <span class="play" aria-hidden="true"></span>
                                    </button>
                                @endif
                                <div class="body">
                                    <h3>{{ $video->title }}</h3>
                                    @if ($video->description)
                                        <p>{{ $video->description }}</p>
                                    @endif
                                    @if ($video->isUpload() && $video->durationLabel())
                                        <div class="meta">{{ $video->durationLabel() }} long</div>
                                    @endif
                                    @unless ($video->isUpload())
                                        <a class="watch" href="{{ $video->watchUrl() }}" target="_blank" rel="noopener">Watch on YouTube ↗</a>
                                    @endunless
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </main>

    <footer class="doc-footer">
        CoreX OS · <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
    </footer>

    <script>
        document.querySelectorAll('.player[data-embed]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var frame = document.createElement('iframe');
                frame.src = btn.getAttribute('data-embed');
                frame.title = btn.getAttribute('data-title');
                frame.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
                frame.allowFullscreen = true;
                frame.referrerPolicy = 'strict-origin-when-cross-origin';
                var holder = document.createElement('div');
                holder.className = 'player';
                holder.appendChild(frame);
                btn.replaceWith(holder);
            });
        });
    </script>
</body>
</html>
