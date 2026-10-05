@props(['label' => 'Actions ▾'])

{{--
    Shared row-action popup for any table that has its own scroll container
    and/or a sticky Actions column — that combination is exactly what traps
    an `absolute`/`position:fixed` dropdown inside the row's own stacking
    context, so it renders underneath sibling rows' sticky cells (confirmed
    on the Command Centre properties table, 2026-10-05: a position:fixed
    attempt computed the right coordinates but was still painted BEHIND
    every later row's own sticky Actions cell).

    The fix: on open, the panel is physically moved (teleported) to be a
    direct child of <body> — once it is no longer a descendant of the
    table/row/sticky-cell at all, there is no ancestor stacking context left
    to trap it in, full stop, regardless of what z-index math says. Position
    is computed from the trigger button's real getBoundingClientRect() at
    open time (so it's correct even if opened mid-scroll), right-aligned to
    the button and flipping upward near the bottom of the viewport. Closes
    on scroll (any ancestor's scroll, via a capture-phase listener — this is
    what makes it correct inside an independently-scrolling panel too),
    resize, Escape, and outside click; only one instance is ever open.

    One shared, once-per-page script block handles every
    instance via event delegation — no per-row JS wiring needed, so this
    scales to any number of table rows with zero extra listeners.
--}}
<details class="corex-rap relative inline-block">
    <summary class="corex-btn-outline text-xs cursor-pointer list-none" style="display: inline-block;">{{ $label }}</summary>
    <div class="corex-rap-panel rounded-md text-xs" style="background: var(--surface); border: 1px solid var(--border); min-width: 160px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); display: none;">
        {{ $slot }}
    </div>
</details>

@once
    <script>
    (function () {
        if (window.__corexRowActionsPopupInit) { return; }
        window.__corexRowActionsPopupInit = true;

        function allMenus() {
            return document.querySelectorAll('.corex-rap');
        }

        // The panel is teleported to <body> the first time it opens (see
        // positionPanel below) — after that, details.querySelector('.corex-
        // rap-panel') finds NOTHING, because the panel is no longer a
        // descendant of `details` at all. Every lookup after the first open
        // MUST go through this cached reference, not a fresh querySelector —
        // getting this wrong is exactly what silently broke Escape/outside-
        // click/scroll-close here the first time (details.open flipped to
        // false, but the now-orphaned querySelector returned null, so the
        // panel itself never actually got display:none and stayed visible,
        // intercepting clicks on whatever was underneath it).
        function panelFor(details) {
            if (!details.__corexPanel) {
                details.__corexPanel = details.querySelector('.corex-rap-panel');
            }
            return details.__corexPanel;
        }

        function closeMenu(details) {
            if (!details.open) { return; }
            details.open = false;
            var panel = panelFor(details);
            if (panel) {
                panel.style.display = 'none';
            }
        }

        function closeAll() {
            allMenus().forEach(closeMenu);
        }

        function closeAllExcept(except) {
            allMenus().forEach(function (d) {
                if (d !== except) { closeMenu(d); }
            });
        }

        function positionPanel(details) {
            var summary = details.querySelector('summary');
            var panel = panelFor(details);
            if (!summary || !panel) { return; }

            // Teleport once, first time this panel is ever opened — stays a
            // direct child of <body> for the rest of the page's life. A
            // display:none body-level div is harmless when closed, and
            // re-parenting back and forth every toggle buys nothing.
            if (panel.parentNode !== document.body) {
                document.body.appendChild(panel);
            }

            panel.style.display = 'block';
            panel.style.position = 'fixed';
            panel.style.margin = '0';
            panel.style.zIndex = '10000';

            var rect = summary.getBoundingClientRect();
            var panelHeight = panel.offsetHeight;
            var panelWidth = panel.offsetWidth;
            var opensUpward = (rect.bottom + panelHeight + 4) > window.innerHeight;

            panel.style.top = opensUpward
                ? Math.max(4, rect.top - panelHeight - 4) + 'px'
                : Math.min(rect.bottom + 4, window.innerHeight - panelHeight - 4) + 'px';

            // Right-aligned to the trigger button, opening leftward if that
            // would run off the left edge of the viewport.
            var left = rect.right - panelWidth;
            panel.style.left = Math.max(4, Math.min(left, window.innerWidth - panelWidth - 4)) + 'px';
            panel.style.right = 'auto';
        }

        // Capture phase: `toggle` doesn't bubble in every browser version we
        // still support, but capture always sees it on the way down.
        document.addEventListener('toggle', function (e) {
            var details = e.target;
            if (!details.classList || !details.classList.contains('corex-rap')) { return; }
            if (details.open) {
                closeAllExcept(details);
                positionPanel(details);
            } else {
                closeMenu(details);
            }
        }, true);

        document.addEventListener('click', function (e) {
            allMenus().forEach(function (d) {
                if (!d.open) { return; }
                var panel = panelFor(d);
                if (d.contains(e.target) || (panel && panel.contains(e.target))) { return; }
                closeMenu(d);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeAll(); }
        });

        // Capture phase so a scroll on ANY nested scroll container (not
        // just window) is seen here — this is what keeps the popup correct
        // when it's opened from inside an independently-scrolling panel.
        window.addEventListener('scroll', closeAll, true);
        window.addEventListener('resize', closeAll);
    })();
    </script>
@endonce
