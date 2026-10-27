{{-- Embed mode (road-to-sold.md 22.9): the bar at the bottom of a screen shown inside the Road to Sold pop-up. "Done" tells the page
     (same origin only) the step is finished; the page closes the pop-up and reloads the card. Nothing else about the screen changes. --}}
<div id="corex-embed-bar" style="position:fixed; left:0; right:0; bottom:0; z-index:60; display:flex; justify-content:flex-end; gap:.5rem; padding:.5rem 1rem; background:var(--surface, #fff); border-top:1px solid var(--border, rgba(0,0,0,.12));">
    <button type="button" class="corex-btn-primary" data-embed-done>Done - back to Road to Sold</button>
</div>
<style>#appScroll { padding-bottom: 4.5rem !important; }</style>
<script>
(function () {
    var btn = document.querySelector('[data-embed-done]');
    if (!btn) { return; }
    var q = new URLSearchParams(window.location.search);
    btn.addEventListener('click', function () {
        try { window.parent.postMessage({ rts: 'done', prospect: q.get('rts_prospect') || null, stage: q.get('rts_stage') || null }, window.location.origin); } catch (e) { /* not framed */ }
    });
})();
</script>
