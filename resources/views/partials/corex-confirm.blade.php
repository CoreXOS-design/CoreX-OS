{{--
    The app's OWN confirm / notice / ask-for-a-note dialog (Johan, 9 Oct 2026: no browser confirm(), alert() or prompt() in the rentals module -
    STANDARDS "Confirmations Before Destructive Actions"). One script, included ONCE by every layout / standalone page that needs it (it guards
    itself, so a double include is harmless). Same look as <x-confirm-submit>.

      Declarative (a form, a button or a link):   data-confirm="Archive this crew?"  [data-confirm-title] [data-confirm-label] [data-confirm-danger]
          <form method="POST" action="..." data-confirm="Archive this crew?" data-confirm-label="Archive" data-confirm-danger> ... </form>
          <a href="..." data-confirm="...">  /  <button type="submit" data-confirm="...">
      From script:                                 if (!(await window.corexConfirm({ title, message, confirmLabel, danger }))) return;
      Ask for a note:                              const note = await window.corexConfirm({ message, input: { label, required: true, maxlength: 500 } });   // string, or false when cancelled
      Tell the person something:                   await window.corexNotice('Could not save that.');
--}}
<script>
(function () {
    if (window.corexConfirm) { return; }

    function el(tag, css, text) {
        var n = document.createElement(tag);
        if (css) { n.style.cssText = css; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }

    function dialog(opts) {
        return new Promise(function (resolve) {
            var previous = document.activeElement;
            var wantsInput = !!opts.input;
            var back = el('div', 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.5);');
            back.setAttribute('data-confirm-modal', '');
            var box = el('div', 'width:100%;max-width:440px;border-radius:8px;padding:20px;background:var(--surface,#fff);color:var(--text-primary,#0f172a);border:1px solid var(--border,#e2e8f0);box-shadow:0 10px 30px rgba(0,0,0,.25);');
            box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
            var title = el('h3', 'margin:0 0 8px;font-size:15px;font-weight:600;', opts.title || (opts.notice ? 'Please note' : 'Please confirm'));
            box.setAttribute('aria-label', title.textContent);
            var msg = el('p', 'margin:0 0 12px;font-size:14px;line-height:1.45;white-space:pre-line;', opts.message || '');
            box.appendChild(title); box.appendChild(msg);

            var field = null, error = null;
            if (wantsInput) {
                var lab = el('label', 'display:block;font-size:12px;font-weight:600;margin-bottom:4px;', opts.input.label || '');
                field = document.createElement('textarea');
                field.rows = 3; field.maxLength = opts.input.maxlength || 2000; field.placeholder = opts.input.placeholder || '';
                field.style.cssText = 'width:100%;box-sizing:border-box;border:1px solid var(--border,#cbd5e1);border-radius:6px;padding:8px;font-size:14px;background:var(--surface,#fff);color:inherit;';
                error = el('p', 'margin:4px 0 0;font-size:12px;color:#b3261e;display:none;');
                box.appendChild(lab); box.appendChild(field); box.appendChild(error);
            }

            var row = el('div', 'display:flex;justify-content:flex-end;gap:8px;margin-top:14px;');
            var cancel = null;
            if (!opts.notice) {
                cancel = el('button', 'padding:7px 14px;font-size:13px;border-radius:6px;border:1px solid var(--border,#cbd5e1);background:transparent;color:inherit;cursor:pointer;', opts.cancelLabel || 'Cancel');
                cancel.type = 'button'; cancel.setAttribute('data-confirm-cancel', '');
                row.appendChild(cancel);
            }
            var ok = el('button', 'padding:7px 14px;font-size:13px;border-radius:6px;border:1px solid ' + (opts.danger ? '#b3261e' : 'var(--brand-button,#0f4c81)') + ';background:' + (opts.danger ? '#b3261e' : 'var(--brand-button,#0f4c81)') + ';color:#fff;cursor:pointer;', opts.notice ? 'OK' : (opts.confirmLabel || 'Yes, continue'));
            ok.type = 'button'; ok.setAttribute('data-confirm-go', '');
            row.appendChild(ok); box.appendChild(row); back.appendChild(box);

            function close(result) {
                document.removeEventListener('keydown', onKey, true);
                if (back.parentNode) { back.parentNode.removeChild(back); }
                if (previous && previous.focus) { try { previous.focus(); } catch (e) { /* gone */ } }
                resolve(result);
            }
            function accept() {
                if (wantsInput) {
                    var v = (field.value || '').trim();
                    if (opts.input.required && v === '') { error.textContent = opts.input.requiredMessage || 'Please write something here first.'; error.style.display = 'block'; field.focus(); return; }
                    close(v);
                    return;
                }
                close(true);
            }
            function onKey(e) {
                if (e.key === 'Escape') { e.preventDefault(); close(opts.notice ? undefined : false); }
                else if (e.key === 'Enter' && !wantsInput && document.activeElement !== cancel) { e.preventDefault(); accept(); }
            }
            ok.addEventListener('click', accept);
            if (cancel) { cancel.addEventListener('click', function () { close(false); }); }
            back.addEventListener('mousedown', function (e) { if (e.target === back) { close(opts.notice ? undefined : false); } });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(back);
            (wantsInput ? field : ok).focus();
        });
    }

    window.corexConfirm = function (opts) { return dialog(typeof opts === 'string' ? { message: opts } : (opts || {})); };
    window.corexNotice = function (message, title) { return dialog({ notice: true, message: String(message || ''), title: title || '' }); };

    function optionsFrom(node) {
        return {
            message: node.getAttribute('data-confirm'),
            title: node.getAttribute('data-confirm-title') || '',
            confirmLabel: node.getAttribute('data-confirm-label') || '',
            danger: node.hasAttribute('data-confirm-danger'),
        };
    }
    var bypass = new WeakSet();

    // A form (or the submit button that was pressed) carrying data-confirm: ask first, then submit it exactly as pressed.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute || bypass.has(form)) { return; }
        var submitter = e.submitter && e.submitter.hasAttribute && e.submitter.hasAttribute('data-confirm') ? e.submitter : null;
        var source = submitter || (form.hasAttribute('data-confirm') ? form : null);
        if (!source) { return; }
        e.preventDefault(); e.stopImmediatePropagation();
        window.corexConfirm(optionsFrom(source)).then(function (yes) {
            if (!yes) { return; }
            bypass.add(form);
            if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
            setTimeout(function () { bypass.delete(form); }, 0);
        });
    }, true);

    // A link or a plain button carrying data-confirm.
    document.addEventListener('click', function (e) {
        var node = e.target && e.target.closest ? e.target.closest('a[data-confirm], button[data-confirm]') : null;
        if (!node || (node.tagName === 'BUTTON' && node.type === 'submit' && node.form)) { return; }   // submit buttons are handled on submit
        if (node.__confirmed) { node.__confirmed = false; return; }
        e.preventDefault(); e.stopImmediatePropagation();
        window.corexConfirm(optionsFrom(node)).then(function (yes) {
            if (!yes) { return; }
            if (node.tagName === 'A') {
                if (node.target === '_blank') { window.open(node.href, '_blank', 'noopener'); } else { window.location.href = node.href; }
            } else { node.__confirmed = true; node.click(); }
        });
    }, true);
})();
</script>
