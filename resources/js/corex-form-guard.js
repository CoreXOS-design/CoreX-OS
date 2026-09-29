// Global double-submit guard — CoreX-wide (duplicate-deal fix, 2026-09-29,
// after two full "Villa Cordoba" deals — #1826/#1827 — were created live from
// one agent click). No form anywhere in the app had any protection against a
// double-click, an Enter+click race, or a slow-network retry re-firing a
// submit before the first one had visibly done anything.
//
// This listens on `document` for every real POST <form> submit and disables
// that form's submit control(s) the instant the browser actually commits to
// submitting — including a button OUTSIDE the <form> element, associated
// only via the HTML5 form="id" attribute (deals-v2/create-form.blade.php's
// header "Create Deal" button is exactly this shape).
//
// Listening in the BUBBLE phase (the default — no `true` third argument) is
// what lets client-side validation still win: an inline @submit handler
// registered directly on the <form> (e.g. beforeSubmit() on the DR2/deals-v2
// create forms) runs during the "at target" phase, BEFORE the event bubbles
// up to this document-level listener. If that handler already called
// preventDefault() to block a genuinely invalid submission, event.defaultPrevented
// is already true by the time this code runs, so it does nothing and the
// controls stay exactly as they were — free to be corrected and resubmitted.
// Only a submit the browser is actually about to send gets locked.
(function () {
    var LOCKED_CLASS = 'corex-submit-locked';

    function lockButton(btn) {
        if (btn.disabled) return; // already disabled for some other reason — leave it
        btn.disabled = true;
        btn.classList.add(LOCKED_CLASS);
        if (btn.tagName === 'BUTTON' && btn.dataset.corexOriginalLabel === undefined) {
            btn.dataset.corexOriginalLabel = btn.innerHTML;
            btn.textContent = btn.dataset.corexSubmittingLabel || 'Saving…';
        }
    }

    function unlockButton(btn) {
        btn.disabled = false;
        btn.classList.remove(LOCKED_CLASS);
        if (btn.dataset.corexOriginalLabel !== undefined) {
            btn.innerHTML = btn.dataset.corexOriginalLabel;
            delete btn.dataset.corexOriginalLabel;
        }
    }

    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return; // client-side validation already blocked this submit

        var form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.method.toLowerCase() !== 'post') return; // scope: POST forms only (GET search/filter forms are untouched)
        if (form.dataset.corexNoSubmitGuard !== undefined) return; // explicit, documented opt-out

        var buttons = Array.prototype.slice.call(
            form.querySelectorAll('button[type="submit"], input[type="submit"]')
        );

        if (form.id) {
            var outside = document.querySelectorAll(
                'button[form="' + CSS.escape(form.id) + '"], input[form="' + CSS.escape(form.id) + '"]'
            );
            buttons = buttons.concat(Array.prototype.slice.call(outside));
        }

        buttons.forEach(lockButton);
    }, false);

    // Back/forward-cache restore: a browser can restore the exact pre-submit
    // DOM — disabled buttons included — when the user navigates back to a
    // page that never actually left (a submit the code above locked but that
    // then never reached a real navigation, e.g. an aborted request). Without
    // this they would be stuck looking at a dead button.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        document.querySelectorAll('.' + LOCKED_CLASS).forEach(unlockButton);
    });
})();
