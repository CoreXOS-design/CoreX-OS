{{-- The page scripts the Adverts card needs (the match tooltip, the "not the same property" button, the popover placement), shared by the Work page and the one-card page
     the pitch popup shows (road-to-sold.md 23.23). They register Alpine components by name; a card without them leaves every popover open. --}}
<style>[x-cloak] { display: none !important; }</style>
<script>
    (function () {
        var setHeaderHeight = function () {
            var h = document.querySelector('.mi-header');
            if (!h) return;
            document.documentElement.style.setProperty('--mi-header-h', h.offsetHeight + 'px');
        };
        setHeaderHeight();
        window.addEventListener('resize', setHeaderHeight);
        requestAnimationFrame(setHeaderHeight);
    })();

    // Phase E3 — per-listing "why this matches" tooltip.
    // Cache per-listing in-memory so repeated hovers don't refetch.
    //
    // 2026-08-14: `open` now gates visibility (set by mouseenter/focusin,
    // cleared by mouseleave/focusout in the blade) — a true hover-reveal,
    // transient tooltip instead of the old one-way latch that stayed stuck
    // open forever after the first hover. The fetched text is still cached
    // per-listing so re-hovering the same row never re-fetches.
    window.__micMatchTooltipCache = window.__micMatchTooltipCache || {};
    window.micMatchTooltip = function (listingId) {
        return {
            tooltip: '',
            loading: false,
            loaded: false,
            inflight: false,
            open: false,
            show() {
                this.open = true;
                this.ensureLoaded();
            },
            hide() {
                this.open = false;
            },
            ensureLoaded() {
                if (this.loaded || this.inflight) return;
                if (window.__micMatchTooltipCache[listingId]) {
                    this.tooltip = window.__micMatchTooltipCache[listingId];
                    this.loaded = true;
                    return;
                }
                this.inflight = true;
                this.loading = true;
                fetch('/corex/market-intelligence/listing/' + listingId + '/match-tooltip', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
                .then(data => {
                    this.tooltip = data.tooltip || '';
                    window.__micMatchTooltipCache[listingId] = this.tooltip;
                    this.loaded = true;
                })
                .catch(() => { this.tooltip = ''; })
                .finally(() => { this.loading = false; this.inflight = false; });
            },
        };
    };
</script>
{{-- mic-work-tab.md 13.12: the "Not the same property" button in the "Listed by N agencies" popover of an Adverts card. First press arms it (amber, "Confirm - not the same property", back to normal after 6 s or a click elsewhere); the second press splits that advert off; an Undo note shows for 10 s. No browser confirm(). --}}
<script>
(function () {
    function register() {
        window.Alpine.data('miSplit', function (cardId, count, ids, base, fileUrl) {
            var st = {};
            ids.forEach(function (id) { st[id] = { armed: false, busy: false, gone: false, msg: '' }; });
            var post = function (url) {
                var meta = document.querySelector('meta[name="csrf-token"]');
                return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': meta ? meta.content : '' } })
                    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
            };
            return {
                n: count, st: st, note: null, noteAdvert: null, viewHref: null, timers: {}, noteTimer: null,
                // a hairline goes above an entry only when an entry above it is still showing (ids are in the popover's order, newest first)
                rule: function (id) {
                    for (var i = 0; i < ids.length; i++) { if (ids[i] === id) { return false; } if (!this.st[ids[i]].gone) { return true; } }
                    return false;
                },
                label: function (id) {
                    var s = this.st[id];
                    return s.msg !== '' ? s.msg : (s.armed ? 'Confirm - not the same property' : 'Not the same property');
                },
                disarm: function () { var self = this; Object.keys(this.st).forEach(function (id) { self.st[id].armed = false; clearTimeout(self.timers[id]); }); },
                press: function (id, ev) {
                    ev.splitBtn = id;
                    var s = this.st[id];
                    if (s.busy) { return; }
                    if (!s.armed) {
                        this.disarm(); s.msg = ''; s.armed = true;
                        this.timers[id] = setTimeout(function () { s.armed = false; }, 6000);
                        return;
                    }
                    s.armed = false; clearTimeout(this.timers[id]); s.busy = true;
                    var me = this;
                    post(base + '/' + id + '/split').then(function (d) {
                        s.busy = false;
                        if (!d || !d.ok) { s.msg = (d && d.message) || "Couldn't split - try again"; return; }
                        s.gone = true;
                        me.n = (typeof d.agencies_left_on_file === 'number') ? d.agencies_left_on_file : me.n - 1;
                        me.viewHref = d.new_file_id ? fileUrl.replace('__ID__', d.new_file_id) : null;
                        me.note = d.split_id; me.noteAdvert = id;
                        clearTimeout(me.noteTimer);
                        me.noteTimer = setTimeout(function () { me.note = null; }, 10000);
                    }).catch(function () { s.busy = false; s.msg = "Couldn't split - try again"; });
                },
                undo: function () {
                    var me = this, id = this.noteAdvert, splitId = this.note;
                    if (!splitId) { return; }
                    post(base + '/split/' + splitId + '/undo').then(function (d) {
                        if (!d || !d.ok) { me.note = null; return; }
                        me.st[id].gone = false; me.n = (typeof d.agencies_left_on_file === 'number') ? d.agencies_left_on_file : me.n + 1; me.note = null; clearTimeout(me.noteTimer);
                    }).catch(function () { me.note = null; });
                },
            };
        });
        // 13.12b: opens down when the whole panel fits below the trigger in the window, else up; if neither fits, the larger side with the content scrolling; placed again on resize and scroll
        window.Alpine.data('micPop', function (align) {
            return {
                open: false, side: 'down', align: align, h: null,
                pos: { top: '100%', bottom: 'auto', left: '0px', right: 'auto', marginTop: '8px', marginBottom: '0px', transformOrigin: 'top ' + align, '--pop-max': '22rem' },
                init: function () {
                    var self = this;
                    this.h = function (e) { if (self.open && !(e && e.target && e.target.nodeType === 1 && self.$refs.panel.contains(e.target))) { self.place(); } };
                    if (window.ResizeObserver) { new ResizeObserver(function () { if (self.open) { self.place(); } }).observe(this.$refs.inner); }
                    this.$watch('open', function (v) {
                        if (v) { self.$nextTick(function () { self.place(); }); window.addEventListener('resize', self.h); window.addEventListener('scroll', self.h, true); }
                        else { window.removeEventListener('resize', self.h); window.removeEventListener('scroll', self.h, true); }
                    });
                },
                place: function () {
                    var t = this.$refs.trigger.getBoundingClientRect(), panel = this.$refs.panel, inner = this.$refs.inner, c = inner.firstElementChild;
                    var gap = 8, edge = 8, vh = window.innerHeight, vw = window.innerWidth;
                    var chrome = inner.offsetHeight - (c ? c.offsetHeight : 0);
                    var cap = parseFloat(getComputedStyle(document.documentElement).fontSize) * 22;
                    var want = Math.min(c ? c.scrollHeight : inner.offsetHeight, cap) + chrome;
                    var below = vh - t.bottom - gap - edge, above = t.top - gap - edge;
                    var down = want <= below || (want > above && below >= above);
                    var room = down ? below : above;
                    var maxContent = want <= room ? cap : Math.max(80, room - chrome);
                    var w = panel.offsetWidth, base = this.align === 'left' ? 0 : t.width - w, abs = t.left + base, dx = 0;
                    if (abs < edge) { dx = edge - abs; } else if (abs + w > vw - edge) { dx = vw - edge - (abs + w); }
                    this.side = down ? 'down' : 'up';
                    this.pos = { top: down ? '100%' : 'auto', bottom: down ? 'auto' : '100%', left: (base + dx) + 'px', right: 'auto', marginTop: down ? gap + 'px' : '0px', marginBottom: down ? '0px' : gap + 'px', transformOrigin: (down ? 'top ' : 'bottom ') + this.align, '--pop-max': maxContent + 'px' };
                },
            };
        });
    }
    if (window.Alpine && window.Alpine.data) { register(); } else { document.addEventListener('alpine:init', register); }
})();
</script>
