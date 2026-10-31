{{-- AT-525 - the CC switch of the how-to players: captions come ONLY from the .vtt track (never burned into the picture), on by default; the choice is remembered in this browser. --}}
<script>
    if (!window.__tvCaptions) {
        window.__tvCaptionsPref = function () { try { return localStorage.getItem('tv-captions') === '0' ? '0' : '1'; } catch (e) { return '1'; } };
        window.__tvCaptionsApply = function (v, btn) {
            var t = v.textTracks && v.textTracks[0]; if (!t) { return; }
            var on = window.__tvCaptionsPref() !== '0';
            t.mode = on ? 'showing' : 'hidden';
            btn.textContent = 'CC: ' + (on ? 'On' : 'Off'); btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        };
        window.__tvCaptions = function (v, btn) {
            var on = window.__tvCaptionsPref() !== '0';
            try { localStorage.setItem('tv-captions', on ? '0' : '1'); } catch (e) {}
            window.__tvCaptionsApply(v, btn);
        };
        window.__tvCaptionsInit = function (v, btn) { v.addEventListener('loadedmetadata', function () { window.__tvCaptionsApply(v, btn); }); window.__tvCaptionsApply(v, btn); };
    }
</script>
