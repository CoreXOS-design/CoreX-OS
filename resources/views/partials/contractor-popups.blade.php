{{--
    AT-452 round 2, item H (.ai/specs/rentals-faults-work-orders.md §19.9) - "Add a contractor" and "Add <type> to an existing contractor" open OVER the
    screen you are on, never navigate away: nothing typed on the page is lost, Save closes the popup, a confirmation shows, and the warning on the page
    clears by itself (no reload). Include ONCE per page, outside any <form>, and only for a user who may manage contractors.

    Opened by any element with  data-contractor-popup="add|types"  and  data-fault-id / data-work-order-id, data-type, data-trade-code.
    Announces a change with a window event  cx-contractor-changed  {covered, message}  and re-reads every  [data-refresh-on-contractor-change]  region.

    "Add a contractor" is the Supplier Directory's own form (deals-v2/suppliers/_add-form), saved through the same SupplierDirectoryController::store -
    the same fields and the same validation, not a second form.
--}}
@php
    $cpServiceTypes = \App\Models\DealV2\AgencyServiceType::active()->orderBy('sort_order')->orderBy('id')->get(['code', 'label']);
@endphp
<div id="cx-contractor-popups" data-contractor-popups>
    {{-- Add a contractor --}}
    <div class="cxcp-overlay" data-cp-modal="add" hidden role="dialog" aria-modal="true" aria-labelledby="cxcp-add-title">
        <div class="cxcp-box">
            <h3 id="cxcp-add-title" class="cxcp-title">Add a contractor</h3>
            <p class="cxcp-sub" data-cp-add-sub></p>
            @include('deals-v2.suppliers._add-form', [
                'serviceTypes' => $cpServiceTypes, 'specialties' => \App\Http\Controllers\DealV2\SupplierDirectoryController::SPECIALTIES,
                'idp' => 'cpadd', 'popup' => true, 'formAttrs' => 'data-contractor-popup-form', 'cancelAttrs' => 'data-cp-cancel', 'saveLabel' => 'Save contractor',
            ])
        </div>
    </div>

    {{-- Add <type> to an existing contractor --}}
    <div class="cxcp-overlay" data-cp-modal="types" hidden role="dialog" aria-modal="true" aria-labelledby="cxcp-types-title">
        <div class="cxcp-box">
            <h3 id="cxcp-types-title" class="cxcp-title" data-cp-types-title>Add to an existing contractor</h3>
            <p class="cxcp-sub">Tick the contractors who do this kind of work, then press Save.</p>
            <div class="cxcp-gap" data-cp-gap hidden></div>
            <input type="search" class="cxcp-input" data-cp-search placeholder="Search contractors" aria-label="Search contractors">
            <div class="cxcp-list" data-cp-list></div>
            <div class="cxcp-error" data-cp-types-error role="alert" hidden></div>
            <div class="cxcp-actions">
                <button type="button" class="corex-btn-primary text-sm" data-cp-save disabled>Save</button>
                <button type="button" class="corex-btn-outline text-sm" data-cp-cancel>Cancel</button>
            </div>
        </div>
    </div>

    {{-- Add a contractor's email / mobile (so the job can be sent) --}}
    <div class="cxcp-overlay" data-cp-modal="details" hidden role="dialog" aria-modal="true" aria-labelledby="cxcp-details-title">
        <div class="cxcp-box" style="max-width: 460px;">
            <h3 id="cxcp-details-title" class="cxcp-title" data-cp-details-title>Add contact details</h3>
            <p class="cxcp-sub">An email address, a mobile number or both - then the job can be sent.</p>
            <form data-cp-details-form method="POST" action="">
                @csrf
                <input type="hidden" name="work_order_id" value="">
                <label class="cxcp-lbl">Contact person</label>
                <input class="cxcp-input" name="contact_person" maxlength="191" required data-cp-details-person>
                <label class="cxcp-lbl">Email</label>
                <input class="cxcp-input" name="email" type="email" maxlength="191">
                <label class="cxcp-lbl">Mobile number</label>
                <input class="cxcp-input" name="phone" maxlength="50">
                <div class="cxcp-error" data-cp-details-error role="alert" hidden></div>
                <div class="cxcp-actions">
                    <button type="submit" class="corex-btn-primary text-sm" data-cp-details-save>Save</button>
                    <button type="button" class="corex-btn-outline text-sm" data-cp-cancel>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="cxcp-toast" data-cp-toast role="status" aria-live="polite" hidden></div>
</div>
@verbatim
<style>
    .cxcp-overlay { position: fixed; inset: 0; z-index: 90000; display: flex; align-items: flex-start; justify-content: center; padding: 4vh 12px; background: rgba(15, 23, 42, .55); overflow-y: auto; }
    .cxcp-overlay[hidden], .cxcp-toast[hidden], .cxcp-gap[hidden], .cxcp-error[hidden] { display: none; }
    .cxcp-box { width: 100%; max-width: 760px; background: var(--surface, #fff); color: var(--text-primary, #141821); border: 1px solid var(--border, #ddd); border-radius: 10px; padding: 18px 20px; }
    .cxcp-title { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
    .cxcp-sub { font-size: 12px; color: var(--text-muted, #5a6472); margin: 0 0 12px; }
    .cxcp-input { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border, #ddd); background: var(--surface-2, #f4f6fb); font-size: 14px; margin-bottom: 8px; }
    .cxcp-list { max-height: 46vh; overflow: auto; display: flex; flex-direction: column; gap: 6px; }
    .cxcp-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 8px; font-size: 14px; cursor: pointer; }
    .cxcp-row.has { opacity: .65; cursor: default; }
    .cxcp-trades { font-size: 11px; color: var(--text-muted, #5a6472); }
    .cxcp-tag { font-size: 11px; padding: 1px 7px; border-radius: 999px; background: #eafaf0; color: #1f8a4c; }
    .cxcp-gap { font-size: 13px; padding: 8px 10px; border-radius: 8px; background: #fff4e5; border: 1px solid #f59e0b; margin-bottom: 8px; }
    .cxcp-error { color: #b3261e; font-size: 13px; margin-top: 8px; }
    .cxcp-actions { display: flex; gap: 8px; margin-top: 12px; }
    .cxcp-toast { position: fixed; right: 16px; bottom: 16px; z-index: 90001; max-width: 360px; padding: 10px 14px; border-radius: 10px; background: #1f8a4c; color: #fff; font-weight: 600; font-size: 14px; box-shadow: 0 6px 20px rgba(0,0,0,.25); }
    .cxcp-form-error { color: #b3261e; }
    .cxcp-lbl { display: block; font-size: 12px; font-weight: 600; margin: 8px 0 3px; }
</style>
<script>
(function () {
    'use strict';
    if (window.CorexContractorPopups) return;
    var root = document.getElementById('cx-contractor-popups');
    if (!root) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || root.querySelector('input[name="_token"]').value;
    var addModal = root.querySelector('[data-cp-modal="add"]'), typesModal = root.querySelector('[data-cp-modal="types"]'), detailsModal = root.querySelector('[data-cp-modal="details"]');
    var toast = root.querySelector('[data-cp-toast]');
    var ctx = null, opener = null, picked = {}, picker = null, toastTimer = null;

    function ids() { return (ctx && ctx.faultId) ? { fault_id: ctx.faultId } : { work_order_id: ctx.workOrderId }; }
    function qs() { return (ctx && ctx.faultId) ? 'fault=' + encodeURIComponent(ctx.faultId) : 'work_order=' + encodeURIComponent(ctx.workOrderId); }
    function show(m) { m.hidden = false; document.documentElement.setAttribute('data-cp-open', '1'); }
    function closeAll(restoreFocus) {
        addModal.hidden = true; typesModal.hidden = true; detailsModal.hidden = true;
        document.documentElement.removeAttribute('data-cp-open');
        if (restoreFocus !== false && opener && document.contains(opener) && opener.focus) { try { opener.focus(); } catch (e) { /* gone */ } }
    }
    function say(text) {
        toast.textContent = text; toast.hidden = false;
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { toast.hidden = true; }, 7000);
    }
    function headers() { return { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }; }

    /** Re-read the regions of the page that depend on the contractor list (pickers, the supplier card) without a reload and without touching anything typed elsewhere. */
    function refreshRegions() {
        var regions = document.querySelectorAll('[data-refresh-on-contractor-change]');
        if (!regions.length) return Promise.resolve();
        return fetch(location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                regions.forEach(function (old) {
                    var key = old.getAttribute('data-refresh-on-contractor-change');
                    var fresh = doc.querySelector('[data-refresh-on-contractor-change="' + key + '"]');
                    if (!fresh) return;
                    var imp = document.importNode(fresh, true);
                    old.replaceWith(imp);
                    if (window.Alpine && Alpine.initTree) { Alpine.initTree(imp); }
                });
            }).catch(function () { /* the next page load shows it */ });
    }

    function changed(message, covered, provider) {
        closeAll(true);
        say(message);
        // `provider` (id, name, kind...) is only set when a supplier was just added - "Who looks at faults first" picks it straight away.
        window.dispatchEvent(new CustomEvent('cx-contractor-changed', { detail: { covered: !!covered, message: message, provider: provider || null } }));
        refreshRegions();
    }

    // ── Add a contractor ──────────────────────────────────────────────────────────────────────────────────────────────────
    var form = addModal.querySelector('form[data-contractor-popup-form]');
    var formErrors = form.querySelector('[data-form-errors]');
    function openAdd(d) {
        form.reset();
        formErrors.classList.add('hidden'); formErrors.textContent = '';
        form.querySelectorAll('input[name="fault_id"],input[name="work_order_id"]').forEach(function (i) { i.remove(); });
        Object.keys(ids()).forEach(function (k) { var h = document.createElement('input'); h.type = 'hidden'; h.name = k; h.value = ids()[k]; form.appendChild(h); });
        form.querySelectorAll('input[name="service_types[]"]').forEach(function (c) { c.checked = !!d.tradeCode && c.getAttribute('data-trade-code') === d.tradeCode; });
        addModal.querySelector('#cxcp-add-title').textContent = d.title || 'Add a contractor';
        addModal.querySelector('[data-cp-add-sub]').textContent = d.type ? 'This contractor will be offered for "' + d.type + '" faults.' : 'Add the details of the new contractor.';
        show(addModal);
        var first = form.querySelector('input[name="name"]'); if (first) first.focus();
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = form.querySelector('[data-add-contractor-save]');
        btn.disabled = true;
        formErrors.classList.add('hidden');
        fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: headers(), body: new FormData(form) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); })
            .then(function (res) {
                btn.disabled = false;
                if (res.ok && res.d.ok) { changed((res.d.provider ? res.d.provider.name : 'The contractor') + ' was added to your contractors.', res.d.covered, res.d.provider); return; }
                var msgs = res.d && res.d.errors ? Object.keys(res.d.errors).map(function (k) { return res.d.errors[k][0]; }) : [(res.d && res.d.message) || 'That could not be saved. Please try again.'];
                formErrors.textContent = msgs.join(' '); formErrors.classList.remove('hidden');
            }).catch(function () { btn.disabled = false; formErrors.textContent = 'No connection - nothing was saved. Please try again.'; formErrors.classList.remove('hidden'); });
    });

    // ── Add <type> to an existing contractor ──────────────────────────────────────────────────────────────────────────────
    var list = typesModal.querySelector('[data-cp-list]'), search = typesModal.querySelector('[data-cp-search]'), saveBtn = typesModal.querySelector('[data-cp-save]');
    var gap = typesModal.querySelector('[data-cp-gap]'), typesErr = typesModal.querySelector('[data-cp-types-error]');
    var createTrade = false;

    function updateSave() {
        var n = Object.keys(picked).length;
        saveBtn.disabled = n === 0 || (picker && picker.code === null && !createTrade);
        saveBtn.textContent = n > 1 ? 'Save (' + n + ')' : 'Save';
    }
    function render() {
        var term = (search.value || '').toLowerCase();
        list.textContent = '';
        (picker.contractors || []).forEach(function (c) {
            if (term && (c.name + ' ' + c.trades.join(' ')).toLowerCase().indexOf(term) === -1) return;
            var row = document.createElement('label');
            row.className = 'cxcp-row' + (c.has_trade ? ' has' : '');
            row.setAttribute('data-cp-row', c.id);
            var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = c.id; cb.checked = !!picked[c.id] || c.has_trade; cb.disabled = c.has_trade;
            cb.addEventListener('change', function () { if (cb.checked) picked[c.id] = true; else delete picked[c.id]; updateSave(); });
            var name = document.createElement('span'); name.style.fontWeight = '600'; name.textContent = c.name;
            var tr = document.createElement('span'); tr.className = 'cxcp-trades'; tr.textContent = c.trades.length ? c.trades.join(', ') : 'no trades yet';
            row.appendChild(cb); row.appendChild(name); row.appendChild(tr);
            if (c.has_trade) { var t = document.createElement('span'); t.className = 'cxcp-tag'; t.textContent = 'already does this'; row.appendChild(t); }
            list.appendChild(row);
        });
        if (!list.children.length) { var p = document.createElement('p'); p.className = 'cxcp-sub'; p.textContent = 'No contractors match.'; list.appendChild(p); }
    }
    function openTypes(d) {
        picked = {}; picker = null; createTrade = false; search.value = ''; list.textContent = 'Loading…'; typesErr.hidden = true; gap.hidden = true; saveBtn.disabled = true;
        typesModal.querySelector('[data-cp-types-title]').textContent = 'Add ' + (d.type || 'this work') + ' to an existing contractor';
        show(typesModal); search.focus();
        fetch(document.querySelector('[data-cp-picker-url]').getAttribute('data-cp-picker-url') + '?' + qs(), { credentials: 'same-origin', headers: headers() })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                picker = j;
                if (j.code === null) {
                    gap.hidden = false;
                    gap.textContent = '';
                    var t = document.createElement('div'); t.textContent = 'Your trade list has no "' + (j.category || d.type) + '" yet.';
                    gap.appendChild(t);
                    if (j.can_create_trade) {
                        var l = document.createElement('label'); l.style.display = 'flex'; l.style.gap = '6px'; l.style.marginTop = '6px';
                        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.setAttribute('data-cp-create-trade', '1');
                        cb.addEventListener('change', function () { createTrade = cb.checked; updateSave(); });
                        l.appendChild(cb); l.appendChild(document.createTextNode('Add "' + (j.category || d.type) + '" to my trade list and use it for the contractors I tick'));
                        gap.appendChild(l);
                    } else {
                        var n = document.createElement('div'); n.textContent = 'Ask someone who manages the trade list to add it, then come back.'; gap.appendChild(n);
                    }
                }
                render(); updateSave();
            }).catch(function () { list.textContent = 'The contractor list could not be loaded. Please close this and try again.'; });
    }
    search.addEventListener('input', function () { if (picker) render(); });
    saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true; typesErr.hidden = true;
        var body = Object.assign({ provider_ids: Object.keys(picked).map(Number), create_trade: createTrade }, ids());
        fetch(document.querySelector('[data-cp-picker-url]').getAttribute('data-cp-save-url'), { method: 'POST', credentials: 'same-origin', headers: Object.assign({ 'Content-Type': 'application/json' }, headers()), body: JSON.stringify(body) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (res.ok && res.d.ok) { changed(res.d.message || 'Saved.', res.d.covered); return; }
                saveBtn.disabled = false; typesErr.hidden = false; typesErr.textContent = (res.d && res.d.message) || 'That could not be saved. Please try again.';
            }).catch(function () { saveBtn.disabled = false; typesErr.hidden = false; typesErr.textContent = 'No connection - nothing was saved. Please try again.'; });
    });

    // ── Add a contractor's contact details ───────────────────────────────────────────────────────────────────────────────────
    var dform = detailsModal.querySelector('[data-cp-details-form]'), derr = detailsModal.querySelector('[data-cp-details-error]');
    function openDetails(d) {
        dform.reset(); derr.hidden = true;
        dform.action = document.querySelector('[data-cp-picker-url]').getAttribute('data-cp-contact-url').replace('__ID__', d.providerId);
        dform.querySelector('input[name="work_order_id"]').value = d.workOrderId || '';
        dform.querySelector('[data-cp-details-person]').value = d.name || '';
        detailsModal.querySelector('[data-cp-details-title]').textContent = 'Add contact details for ' + (d.name || 'the contractor');
        show(detailsModal);
        dform.querySelector('input[name="email"]').focus();
    }
    dform.addEventListener('submit', function (e) {
        e.preventDefault();
        var email = dform.querySelector('input[name="email"]').value.trim(), phone = dform.querySelector('input[name="phone"]').value.trim();
        if (!email && !phone) { derr.textContent = 'Add an email address or a mobile number.'; derr.hidden = false; return; }
        var btn = dform.querySelector('[data-cp-details-save]'); btn.disabled = true; derr.hidden = true;
        fetch(dform.action, { method: 'POST', credentials: 'same-origin', headers: headers(), body: new FormData(dform) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                btn.disabled = false;
                if (res.ok && res.j.ok) { changed(res.j.message || 'Saved.', true); return; }
                derr.textContent = res.j && res.j.errors ? Object.keys(res.j.errors).map(function (k) { return res.j.errors[k][0]; }).join(' ') : ((res.j && res.j.message) || 'That could not be saved.'); derr.hidden = false;
            }).catch(function () { btn.disabled = false; derr.textContent = 'No connection - nothing was saved.'; derr.hidden = false; });
    });

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────────────────────────────────
    root.addEventListener('click', function (e) {
        if (e.target.closest('[data-cp-cancel]')) { e.preventDefault(); closeAll(true); return; }
        if (e.target === addModal || e.target === typesModal || e.target === detailsModal) { closeAll(true); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.documentElement.hasAttribute('data-cp-open')) { e.preventDefault(); e.stopPropagation(); closeAll(true); } }, true);
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-contractor-popup]') : null;
        if (!b) return;
        e.preventDefault();
        opener = b;
        ctx = { faultId: b.getAttribute('data-fault-id') || null, workOrderId: b.getAttribute('data-work-order-id') || null };
        var d = { title: b.getAttribute('data-title'), type: b.getAttribute('data-type'), tradeCode: b.getAttribute('data-trade-code') || null, providerId: b.getAttribute('data-provider-id'), name: b.getAttribute('data-provider-name'), workOrderId: b.getAttribute('data-work-order-id') };
        var mode = b.getAttribute('data-contractor-popup');
        if (mode === 'add') openAdd(d); else if (mode === 'details') openDetails(d); else openTypes(d);
    });
    window.CorexContractorPopups = { open: function (mode, d) { ctx = d; (mode === 'add' ? openAdd : openTypes)(d); }, close: function () { closeAll(true); } };
})();
</script>
@endverbatim
<span data-cp-picker-url="{{ route('deals-v2.suppliers.trade-picker') }}" data-cp-save-url="{{ route('deals-v2.suppliers.add-trade') }}" data-cp-contact-url="{{ route('deals-v2.suppliers.contacts.store', ['provider' => '__ID__']) }}" hidden></span>
