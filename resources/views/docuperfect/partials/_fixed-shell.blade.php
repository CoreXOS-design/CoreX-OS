{{--
    Page standard: left menu + top header fixed, content scrolls INSIDE its own pane. A page that carries data-fixed-shell is sized to the
    real height of the page scroller (#appScroll minus its padding) and the scroller is held at the top, so the page title and tabs can never
    scroll away (a scroll jump from focus / layout / the paginator is put straight back). Narrow screens (< 1100px) stack normally.
--}}
<script>
(function () {
    function fit() {
        var shell = document.querySelector('[data-fixed-shell]');
        var sc = document.getElementById('appScroll');
        if (!shell || !sc) return;
        if (window.innerWidth < 1100) { shell.style.height = ''; return; }
        var cs = getComputedStyle(sc);
        var h = sc.clientHeight - parseFloat(cs.paddingTop || 0) - parseFloat(cs.paddingBottom || 0);
        if (h > 200) shell.style.height = h + 'px';
        sc.scrollTop = 0;
    }
    function hold() {
        var sc = document.getElementById('appScroll');
        if (!sc) return;
        sc.addEventListener('scroll', function () {
            if (window.innerWidth >= 1100 && sc.scrollTop !== 0 && sc.scrollHeight - sc.clientHeight <= 40) sc.scrollTop = 0;
        }, { passive: true });
    }
    fit(); hold();
    window.addEventListener('resize', fit);
    window.addEventListener('load', function () { fit(); setTimeout(fit, 150); setTimeout(fit, 600); });
})();
</script>
