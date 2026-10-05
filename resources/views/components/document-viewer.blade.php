{{--
    CoreX Document Viewer — the global in-page PDF / image viewer.
    Spec: .ai/specs/document-inline-view.md §5.1

    Included ONCE per layout (layouts/corex.blade.php + layouts/corex-app.blade.php) so every
    authenticated screen can open a document over the current page instead of downloading it.
    Any View affordance anywhere calls:

        window.CoreXDocViewer.open(url, name, isImage)   // returns true when it handled the click

    Deliberately written in plain JS, not Alpine: this markup sits at the end of the layout and
    must never depend on (or collide with) whatever x-data scope the page happens to define, and
    it has to work on the pages that load no Alpine component of their own.

    Progressive enhancement: every View link keeps a real href to the inline URL plus
    target="_blank", so if this script has not booted the click still opens the document in a new
    tab. See components/document-view-link.blade.php.
--}}
@auth
<div id="corex-doc-viewer" class="fixed inset-0 z-[9998] hidden items-center justify-center p-4"
     style="background: rgba(0,0,0,.8);" role="dialog" aria-modal="true" aria-label="Document viewer">
    <div class="w-full max-w-5xl rounded-md overflow-hidden flex flex-col"
         style="height: 88vh; background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0);">

        {{-- Header: name + open-in-tab + download + close --}}
        <div class="flex items-center gap-3 px-4 py-2.5 flex-shrink-0"
             style="border-bottom: 1px solid var(--border, #e2e8f0);">
            <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);" fill="none"
                 viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
            </svg>
            <span data-role="name" class="text-sm font-medium truncate flex-1"
                  style="color: var(--text-primary, #1a202c);"></span>

            <a data-role="newtab" href="#" target="_blank" rel="noopener"
               class="text-xs font-semibold no-underline px-2 py-1 rounded-md"
               style="color: var(--text-muted, #6b7280);" title="Open in a new tab">New tab</a>

            <a data-role="download" href="#" class="text-xs font-semibold no-underline px-2 py-1 rounded-md hidden"
               style="color: var(--brand-icon, #0ea5e9);" title="Download this document">Download</a>

            <button type="button" data-role="close" class="p-1.5 rounded-md"
                    style="color: var(--text-muted, #6b7280);" title="Close (Esc)" aria-label="Close">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Body: the browser's own PDF renderer, or the image --}}
        <div class="flex-1 min-h-0 flex items-center justify-center" style="background: var(--surface-2, #f1f5f9);">
            <iframe data-role="frame" class="w-full h-full hidden" frameborder="0" title="Document"></iframe>
            <img data-role="image" class="max-w-full max-h-full object-contain hidden" alt="">
        </div>
    </div>
</div>

<script>
(function () {
    // Guard against a double include (a page that pulls the component in itself).
    if (window.CoreXDocViewer) return;

    var root = document.getElementById('corex-doc-viewer');
    if (!root) return;

    var q        = function (role) { return root.querySelector('[data-role="' + role + '"]'); };
    var nameEl   = q('name'),  frameEl = q('frame'), imgEl = q('image');
    var newTabEl = q('newtab'), dlEl   = q('download');

    function close() {
        root.classList.add('hidden');
        root.classList.remove('flex');
        // Drop the src so the PDF plugin stops rendering / holding the file.
        frameEl.setAttribute('src', 'about:blank');
        frameEl.classList.add('hidden');
        imgEl.removeAttribute('src');
        imgEl.classList.add('hidden');
        document.documentElement.style.overflow = '';
    }

    function open(url, name, isImage, downloadUrl) {
        if (!url) return false;

        nameEl.textContent = name || 'Document';
        newTabEl.setAttribute('href', url);

        if (downloadUrl) {
            dlEl.setAttribute('href', downloadUrl);
            dlEl.classList.remove('hidden');
        } else {
            dlEl.classList.add('hidden');
        }

        if (isImage) {
            imgEl.setAttribute('src', url);
            imgEl.setAttribute('alt', name || '');
            imgEl.classList.remove('hidden');
            frameEl.classList.add('hidden');
        } else {
            frameEl.setAttribute('src', url);
            frameEl.classList.remove('hidden');
            imgEl.classList.add('hidden');
        }

        root.classList.remove('hidden');
        root.classList.add('flex');
        document.documentElement.style.overflow = 'hidden';
        return true;
    }

    q('close').addEventListener('click', close);
    // Click the backdrop (not the panel) to dismiss.
    root.addEventListener('click', function (e) { if (e.target === root) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !root.classList.contains('hidden')) close();
    });

    window.CoreXDocViewer = { open: open, close: close };
})();
</script>
@endauth
