{{-- WET-INK per-change initialing (Johan 2026-08-05): each change carries a FULL-WIDTH initial row with one
     slot per party. A party APPLIES THEIR REAL INITIAL in their OWN slot only — clicking their slot opens the
     same capture modal the rest of the document uses (draw/type), and the captured initial IMAGE is written
     into the slot. NOT a click-to-toggle. Gated: only the viewer's own slot is actionable; the server also
     resolves the acting party so a party can only fill their own slot. --}}
@php
    $__initialChangeUrl = $initialChangeUrl ?? (isset($document) ? route('docuperfect.signatures.initialChange', $document) : null);
    $__viewerPartyKey   = $viewerPartyKey ?? 'agent';
@endphp
@if($__initialChangeUrl)
{{-- Base change-mark appearance comes from the ONE canonical stylesheet (shared with the Fill & Review
     preview + PDF). This partial layers ONLY the interactive signing states (click-to-initial / filled). --}}
@include('docuperfect.shared._change-mark-styles')
<style>
    .cir-slot.cir-filled { border-color:#22c55e; background:#f0fdf4; }
    .cir-slot.cir-filled .cir-name { color:#166534; }
    .cir-slot.cir-mine:not(.cir-filled) .cir-ink { cursor:pointer; color:#0369a1; border:1px dashed #0ea5e9;
        border-radius:4px; padding:2px 8px; }
    .cir-slot.cir-mine:not(.cir-filled):hover { box-shadow:0 0 0 2px #bae6fd; }
</style>
<script>
(function () {
    const URL_   = @json($__initialChangeUrl);
    const VIEWER = @json($__viewerPartyKey);
    const CSRF   = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
    // Applies the captured REAL initial image to the acting party's slot (called by the capture modal's
    // applySignature on this surface). Returns true on success.
    window.__corexApplyChangeInitial = async function (changeId, partyKey, imageDataUrl) {
        try {
            const r = await fetch(URL_, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ change_id: changeId, initial_image: imageDataUrl }),
            });
            const d = await r.json().catch(() => ({}));
            return r.ok && !!d.ok;
        } catch (e) { return false; }
    };
    function wire() {
        document.querySelectorAll('.cir-slot[data-party-key]').forEach(function (slot) {
            const key = slot.getAttribute('data-party-key');
            const cid = slot.getAttribute('data-change-id');
            if (key !== VIEWER) return;                        // only the viewer's OWN slot is actionable
            slot.classList.add('cir-mine');
            if (slot.classList.contains('cir-filled')) return; // already applied
            if (slot.dataset.cirWired === '1') return; slot.dataset.cirWired = '1';
            const ink = slot.querySelector('.cir-ink');
            if (ink && ink.getAttribute('data-empty') === '1') { ink.textContent = 'Click to initial'; }
            slot.addEventListener('click', function (ev) {
                ev.preventDefault(); ev.stopPropagation();
                document.dispatchEvent(new CustomEvent('corex-open-change-initial', { detail: { changeId: cid, partyKey: key } }));
            });
        });
    }
    // Expose for surfaces that render the document body LATE (e.g. the external ceremony only paints the
    // doc — and therefore the change-initial rows — AFTER the recipient picks "Sign Electronically").
    window.__corexWireChangeInitials = wire;
    if (document.readyState !== 'loading') setTimeout(wire, 800);
    else document.addEventListener('DOMContentLoaded', function () { setTimeout(wire, 800); });
    // Re-wire whenever new .cir-slot rows appear (post-method-choice pagination, re-render). wire() is
    // idempotent (data-cirWired guard), so a debounced sweep is safe and keeps the initial row actionable
    // on every surface — the universal contract, not a per-screen bootstrap.
    try {
        var _cirT;
        new MutationObserver(function () { clearTimeout(_cirT); _cirT = setTimeout(wire, 200); })
            .observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
})();
</script>
@endif
