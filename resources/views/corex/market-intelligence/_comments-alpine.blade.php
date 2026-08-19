{{--
    MIC property row comments — registered Alpine.data() component.

    Lives in a real <script> block, NOT inline in an x-data HTML attribute —
    this is the AT-363 fix pattern (documented in .ai/CHAT_STARTER.md, and
    used by command-center/buyers/detail.blade.php's buyerWishlists
    component). A double quote inside any JS string here can never break an
    HTML attribute, because there is no attribute to break.

    Shared between the Work tab (_listings.blade.php) and the Opportunities
    tab (opportunities.blade.php) — included ONCE per page, wherever the
    page first needs `x-data="micRowComments()"`. Registration is global
    (document-level `alpine:init`), so any element on the page can reference
    the component by name; only include this partial once per page to avoid
    a harmless but pointless duplicate registration.

    Spec: .ai/specs/mic-property-row-comments.md
--}}
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('micRowComments', () => ({
        commentsModalOpen: false,
        commentsModalLoading: false,
        commentsModalHtml: '',
        async openCommentsModal(trackedPropertyId) {
            this.commentsModalOpen = true;
            this.commentsModalLoading = true;
            this.commentsModalHtml = '';
            try {
                const r = await fetch(`/corex/tracked-properties/${trackedPropertyId}/comments`, {
                    headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('Failed (' + r.status + ')');
                this.commentsModalHtml = await r.text();
            } catch (e) {
                this.commentsModalHtml = '<div style="padding:24px; color: var(--ds-crimson);">Failed to load comments: ' + (e.message || 'error') + '</div>';
            } finally {
                this.commentsModalLoading = false;
            }
        },
        // Called by the fetched fragment (add/edit/remove) after a mutation
        // returns the fresh count — patches every matching row badge on the
        // page (Work tab or Opportunities tab, whichever is current) without
        // a full row re-render.
        updateCommentBadge(trackedPropertyId, count) {
            document.querySelectorAll('[data-tp-comment-count="' + trackedPropertyId + '"]').forEach((el) => {
                el.textContent = count > 0 ? String(count) : '';
                el.style.marginLeft = count > 0 ? '3px' : '0';
            });
        },
        async removeComment(trackedPropertyId, commentId) {
            try {
                const res = await fetch(`/corex/tracked-properties/${trackedPropertyId}/comments/${commentId}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
                    },
                    credentials: 'same-origin',
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
                this.commentsModalHtml = data.comments_html;
                this.updateCommentBadge(trackedPropertyId, data.count);
            } catch (e) {
                alert('Could not remove comment: ' + (e.message || 'error'));
            }
        },
    }));
});
</script>
