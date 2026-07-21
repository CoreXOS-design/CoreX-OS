{{-- AT-267 — shared record read-only lock.

     Included on a record DETAIL page (property / contact / deal). When $canEdit is false the page
     is rendered VIEW-ONLY: a banner explains why, and a client-side enforcer neutralises every
     mutation control at once so an assistant is never shown edit affordances that would only 403 on
     save. This is a UX layer — the real guard is server-side (authorize*/canMutate* in the write
     controllers). Belt: even if a control leaks through, the POST still 403s.

     Coverage, page-agnostic (no per-control wiring needed for the bulk):
       1. EVERY non-GET <form> — its inputs/selects/textareas/buttons are disabled and its submit is
          blocked. This alone covers the master edit form, notes, files, mark-sold, archive, etc.
       2. Anything a page explicitly marks `data-edit-only` — used for the fetch/Alpine-driven
          editors (gallery, spaces, syndication toggles, link/unlink) that are not <form> submits.

     Params:
       $canEdit  (bool, required)  — false renders the lock.
       $readonlyMessage (string, optional) — the banner sentence.
--}}
@unless($canEdit)
    @php $roMsg = $readonlyMessage ?? 'Only the assigned agent can make changes to this record.'; @endphp

    <div class="rounded-md px-4 py-3 mb-4 flex items-center gap-3"
         style="background:var(--surface-2, #f0f2f8); border:1px solid var(--ds-amber, #d97706); color:var(--text-primary, #111827);">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
             style="width:20px; height:20px; flex:none; color:var(--ds-amber, #d97706);">
            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" />
        </svg>
        <div class="text-sm"><strong>View only.</strong> {{ $roMsg }}</div>
    </div>

    <style>
        /* Greyed-and-inert: the visual half of the lock. */
        .ro-locked { opacity: .55 !important; cursor: not-allowed !important; }
        [data-edit-only] { opacity: .55 !important; cursor: not-allowed !important; pointer-events: none !important; }
        [data-edit-only] * { pointer-events: none !important; }
    </style>

    <script>
    (function () {
        function disableEl(el) {
            try {
                if ('disabled' in el) { el.disabled = true; }
                el.setAttribute('aria-disabled', 'true');
                el.classList.add('ro-locked');
                if (el.tagName === 'A' || el.getAttribute('role') === 'button') {
                    el.addEventListener('click', function (e) { e.preventDefault(); e.stopImmediatePropagation(); }, true);
                }
            } catch (e) { /* never let the lock throw */ }
        }

        function lock() {
            // 1) Every non-GET form → disable its controls and block submit. Covers the bulk of
            //    mutations on every detail page without any per-control wiring.
            document.querySelectorAll('form').forEach(function (f) {
                var m = (f.querySelector('input[name="_method"]') || {}).value
                     || f.getAttribute('method') || 'get';
                if (String(m).toLowerCase() === 'get') { return; }
                f.querySelectorAll('input, select, textarea, button').forEach(disableEl);
                if (!f.dataset.roBound) {
                    f.addEventListener('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); }, true);
                    f.dataset.roBound = '1';
                }
            });

            // 2) Explicitly marked fetch/Alpine editors that are NOT form submits.
            document.querySelectorAll('[data-edit-only]').forEach(function (el) {
                disableEl(el);
                el.querySelectorAll('input, select, textarea, button, a').forEach(disableEl);
            });
        }

        document.addEventListener('DOMContentLoaded', lock);
        document.addEventListener('alpine:initialized', function () { setTimeout(lock, 60); });
        // Alpine renders x-if/x-show controls lazily (e.g. on tab switch) — re-apply after any click.
        document.addEventListener('click', function () { setTimeout(lock, 0); }, true);
        lock();
    })();
    </script>
@endunless
