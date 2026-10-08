/*
 * E-sign acknowledgement dialog — template setup only (admin, manage_templates).
 *
 * Shown when an admin switches e-signing ON for a template whose document type carries the
 * legal warning. It is a warning, not a block: ticking the box and pressing the button lets the
 * save carry on. It is never loaded on any screen an agent uses.
 *
 *   CoreXEsignAck.confirm(warning).then(function (ok) { ... })
 *
 * `warning` is { title, paragraphs[], confirm_label, enable_button, cancel_button } — the wording
 * lives in config/esign-acknowledgement.php and arrives from the server.
 */
(function () {
    'use strict';

    function el(tag, props, children) {
        var node = document.createElement(tag);
        Object.keys(props || {}).forEach(function (k) {
            if (k === 'style') { node.style.cssText = props[k]; }
            else if (k === 'text') { node.textContent = props[k]; }
            else { node.setAttribute(k, props[k]); }
        });
        (children || []).forEach(function (c) { node.appendChild(c); });
        return node;
    }

    function confirmEsign(warning) {
        return new Promise(function (resolve) {
            var w = warning || {};
            var previous = document.activeElement;

            var check = el('input', { type: 'checkbox', id: 'esignAckCheck', style: 'margin-top:3px;flex:none;' });
            var enable = el('button', {
                type: 'button',
                text: w.enable_button || 'Switch e-signing on',
                style: 'padding:8px 14px;border-radius:6px;border:0;background:#0f766e;color:#fff;font-weight:600;cursor:pointer;opacity:.45;'
            });
            enable.disabled = true;
            var cancel = el('button', {
                type: 'button',
                text: w.cancel_button || 'Cancel',
                style: 'padding:8px 14px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#334155;cursor:pointer;'
            });

            var paragraphs = (w.paragraphs || []).map(function (t) {
                return el('p', { text: t, style: 'margin:0 0 10px;font-size:14px;line-height:1.5;color:#1e293b;' });
            });

            var box = el('div', {
                role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'esignAckTitle',
                style: 'background:#fff;max-width:520px;width:calc(100% - 32px);border-radius:10px;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.3);border-top:4px solid #d97706;'
            }, [
                el('h2', { id: 'esignAckTitle', text: w.title || 'Before you switch e-signing on', style: 'margin:0 0 12px;font-size:17px;font-weight:700;color:#0f172a;' })
            ].concat(paragraphs).concat([
                el('label', { style: 'display:flex;gap:8px;align-items:flex-start;margin:14px 0 18px;font-size:14px;color:#0f172a;cursor:pointer;' }, [
                    check,
                    el('span', { text: w.confirm_label || 'I understand.' })
                ]),
                el('div', { style: 'display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;' }, [cancel, enable])
            ]));

            var overlay = el('div', {
                id: 'esignAckOverlay',
                style: 'position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:99999;'
            }, [box]);

            function close(result) {
                document.removeEventListener('keydown', onKey, true);
                overlay.remove();
                if (previous && previous.focus) { try { previous.focus(); } catch (e) { /* ignore */ } }
                resolve(result);
            }
            function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(false); } }

            check.addEventListener('change', function () {
                enable.disabled = !check.checked;
                enable.style.opacity = check.checked ? '1' : '.45';
            });
            enable.addEventListener('click', function () { if (check.checked) close(true); });
            cancel.addEventListener('click', function () { close(false); });
            document.addEventListener('keydown', onKey, true);

            document.body.appendChild(overlay);
            check.focus();
        });
    }

    window.CoreXEsignAck = { confirm: confirmEsign };
})();
