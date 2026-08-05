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
<style>
    .change-initial-row { display:block; margin:.5rem 0 1.1rem; padding:.55rem .8rem; border:1px solid #fcd34d;
        background:#fffbeb; border-radius:8px; font-size:.82rem; }
    .change-initial-row .cir-label { font-weight:700; color:#92400e; margin-right:.75rem; text-transform:uppercase;
        letter-spacing:.03em; font-size:.68rem; }
    .cir-slot { display:inline-flex; align-items:center; gap:.45rem; margin:.2rem .7rem .2rem 0; padding:.25rem .55rem;
        border:1px solid #e5e7eb; border-radius:6px; background:#fff; vertical-align:middle; }
    .cir-slot .cir-name { color:#374151; font-weight:600; }
    .cir-ink { min-width:56px; min-height:28px; display:inline-flex; align-items:center; justify-content:center;
        color:#9ca3af; border-bottom:1px solid #d1d5db; font-size:.72rem; }
    .cir-ink-img { height:28px; max-height:28px; width:auto; object-fit:contain; }
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
    if (document.readyState !== 'loading') setTimeout(wire, 800);
    else document.addEventListener('DOMContentLoaded', function () { setTimeout(wire, 800); });
})();
</script>
@endif
