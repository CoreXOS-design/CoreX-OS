{{--
    Public, logged-out CoreX Training Videos page.
    Spec: .ai/specs/training-videos.md §4.3

    Standalone rather than extending public/legal/layout (that layout always prints a
    "Last updated" line); the styling is the same so CoreX's public pages look like one
    family. CoreX-branded only — never an agency's name or branding (multi-agency rule).

    Players are click-to-play: only the thumbnail loads until the visitor presses play,
    then the youtube-nocookie iframe is swapped in (spec D3).
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
            --text: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --surface: #ffffff;
            --surface-2: #f9fafb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text); background: var(--surface-2);
            line-height: 1.6;
        }
        header.brand-bar {
            background: var(--brand); color: #fff;
            padding: 18px 24px;
            display: flex; align-items: center; gap: 12px;
        }
        header.brand-bar .logo { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.5px; }
        header.brand-bar .logo .os { color: var(--accent); }
        main.content {
            max-width: 960px; margin: 32px auto; padding: 32px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
        }
        h1 { font-size: 1.6rem; margin: 0 0 16px; border-bottom: 2px solid var(--brand); padding-bottom: 8px; }
        h2 { font-size: 1.2rem; margin: 36px 0 12px; color: var(--brand); scroll-margin-top: 16px; }
        a { color: var(--accent); }
        .search { display: flex; gap: 8px; margin: 0 0 16px; }
        .search input {
            flex: 1; padding: 8px 12px; font-size: 0.95rem;
            border: 1px solid var(--border); border-radius: 6px; background: var(--surface-2); color: var(--text);
        }
        .search button {
            padding: 8px 16px; font-size: 0.9rem; border: 0; border-radius: 6px;
            background: var(--brand); color: #fff; cursor: pointer;
        }
        .jump { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 8px; padding: 0; list-style: none; }
        .jump a {
            display: inline-block; padding: 4px 12px; font-size: 0.85rem; text-decoration: none;
            border: 1px solid var(--border); border-radius: 999px; color: var(--brand); background: var(--surface-2);
        }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        .video h3 { font-size: 1rem; margin: 10px 0 4px; }
        .video p { margin: 0 0 6px; font-size: 0.9rem; white-space: pre-line; }
        .video .watch { font-size: 0.8rem; }
        .player {
            position: relative; display: block; width: 100%; aspect-ratio: 16 / 9; padding: 0;
            border: 0; border-radius: 6px; overflow: hidden; background: #000; cursor: pointer;
        }
        .player img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .player iframe { width: 100%; height: 100%; border: 0; display: block; }
        .player .play {
            position: absolute; inset: 0; margin: auto; width: 64px; height: 44px; border-radius: 12px;
            background: rgba(0, 0, 0, 0.75); display: flex; align-items: center; justify-content: center;
        }
        .player:hover .play, .player:focus-visible .play { background: #ff0000; }
        .player .play::after {
            content: ''; border-style: solid; border-width: 10px 0 10px 17px;
            border-color: transparent transparent transparent #fff; margin-left: 4px;
        }
        .empty { text-align: center; color: var(--text-muted); padding: 40px 0; }
        footer.doc-footer {
            max-width: 960px; margin: 0 auto 40px; padding: 0 32px;
            font-size: 0.75rem; color: var(--text-muted); text-align: center;
        }
        footer.doc-footer a { color: var(--text-muted); }
        @media (max-width: 640px) {
            main.content { margin: 16px; padding: 20px 16px; }
        }
    </style>
</head>
<body>
    <header class="brand-bar">
        <span class="logo">corex<span class="os"> os</span></span>
    </header>

    <main class="content">
        <h1>Training Videos</h1>

        @if (! $hasAny)
            <p class="empty">Training videos are coming soon.</p>
        @else
            <form class="search" method="GET" action="{{ route('public.training-videos') }}" role="search">
                <input type="search" name="q" value="{{ $q }}" placeholder="Search videos" aria-label="Search videos">
                <button type="submit">Search</button>
            </form>

            @if ($sections->isEmpty())
                <p class="empty">
                    No videos match “{{ $q }}”.
                    <a href="{{ route('public.training-videos') }}">Show all videos</a>
                </p>
            @else
                @if ($sections->count() > 1)
                    <ul class="jump">
                        @foreach ($sections as $i => $section)
                            <li><a href="#section-{{ $i }}">{{ $section['name'] }}</a></li>
                        @endforeach
                    </ul>
                @endif

                @foreach ($sections as $i => $section)
                    <h2 id="section-{{ $i }}">{{ $section['name'] }}</h2>
                    <div class="grid">
                        @foreach ($section['videos'] as $video)
                            <article class="video">
                                <button type="button" class="player" data-embed="{{ $video->embedUrl() }}" data-title="{{ $video->title }}"
                                        aria-label="Play {{ $video->title }}">
                                    <img src="{{ $video->thumbnailUrl() }}" alt="" loading="lazy">
                                    <span class="play" aria-hidden="true"></span>
                                </button>
                                <h3>{{ $video->title }}</h3>
                                @if ($video->description)
                                    <p>{{ $video->description }}</p>
                                @endif
                                <a class="watch" href="{{ $video->watchUrl() }}" target="_blank" rel="noopener">Watch on YouTube ↗</a>
                            </article>
                        @endforeach
                    </div>
                @endforeach
            @endif
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
