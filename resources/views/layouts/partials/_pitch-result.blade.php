{{-- Road to Sold 23.23: tells the pitch popup (when this screen is inside it) that the agent pitched or logged a contact, so Done can say so and the list can refresh.
     Same origin only; outside the popup it does nothing. --}}
<script>
window.corexPitchResult = function (kind, address, detail) {
    try { if (window.parent && window.parent !== window) { window.parent.postMessage({ pitch: 'result', kind: kind, address: address || '', detail: detail || '' }, window.location.origin); } } catch (e) { /* not framed */ }
};
window.corexPitchBook = function (url) {
    try { if (window.parent && window.parent !== window) { window.parent.postMessage({ pitch: 'book', url: url || '' }, window.location.origin); } } catch (e) { /* not framed */ }
};
</script>
