{{-- WET-INK per-change initialing (esign-returned-doc-edit-flow.md item 4). Scans the rendered document
     for change marks (data-change-id — cc1's field/clause marks AND cc6's authored clause strikes) and,
     for each not-yet-initialed change, offers the current party an "Initial this change" button. On click
     it POSTs the change_id → cc6 writes web_template_data['change_initials'][id]={name,at} (cc1 contract)
     → the page reloads showing "Initialed by {name}" on that change. Prior signatures are untouched. --}}
@php $__initialChangeUrl = $initialChangeUrl ?? (isset($document) ? route('docuperfect.signatures.initialChange', $document) : null); @endphp
@if($__initialChangeUrl)
<style>
    .change-initialed { display:inline-block; margin-left:.4rem; padding:1px 8px; border-radius:9999px;
        background:#dcfce7; color:#166534; font-size:.68rem; font-weight:600; }
    .wetink-initial-btn:hover { background:#0b2a4a !important; color:#fff !important; }
</style>
<script>
(function () {
    const URL_ = @json($__initialChangeUrl);
    const CSRF = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
    function alreadyInitialed(el) {
        const host = el.closest('[data-change-id]') || el;
        return /Initialed by/i.test(host.textContent || '') || host.querySelector('.change-initialed');
    }
    function wire() {
        const seen = new Set();
        document.querySelectorAll('[data-change-id]').forEach(function (el) {
            const id = el.getAttribute('data-change-id');
            if (!id || seen.has(id)) return;
            seen.add(id);
            if (alreadyInitialed(el)) return;
            if (document.querySelector('button.wetink-initial-btn[data-cid="' + id + '"]')) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wetink-initial-btn';
            btn.dataset.cid = id;
            btn.textContent = 'Initial this change';
            btn.style.cssText = 'margin-left:.4rem;font-size:.7rem;padding:1px 8px;border:1px solid #0b2a4a;border-radius:4px;background:#fff;color:#0b2a4a;cursor:pointer;vertical-align:middle;';
            btn.addEventListener('click', async function () {
                btn.disabled = true; btn.textContent = '…';
                try {
                    const r = await fetch(URL_, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ change_id: id }),
                    });
                    const d = await r.json().catch(function () { return {}; });
                    if (r.ok && d.ok) { window.location.reload(); }
                    else { btn.disabled = false; btn.textContent = 'Initial this change'; alert(d.error || 'Could not record the initial.'); }
                } catch (e) { btn.disabled = false; btn.textContent = 'Initial this change'; alert('Network error — please retry.'); }
            });
            (el.tagName === 'INS' || el.tagName === 'DEL' || el.tagName === 'SPAN' ? el : el).insertAdjacentElement('afterend', btn);
        });
    }
    if (document.readyState !== 'loading') setTimeout(wire, 700);
    else document.addEventListener('DOMContentLoaded', function () { setTimeout(wire, 700); });
})();
</script>
@endif
