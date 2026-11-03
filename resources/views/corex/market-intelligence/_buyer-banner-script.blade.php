{{-- 13.14: the Core Match / Edit criteria pop-up behaviour for the buyer banner. Kept OUT of the banner partial because the list is swapped in by the tick refresh and scripts inside a swapped fragment do not run. Same pop-up pattern as Road to Sold's rtsStepModal. --}}
<script>
function micBuyerPop() {
    return {
        isOpen: false, label: '', opener: null, edited: false,
        // the real CoreX screen in an iframe (embed mode, same origin): Core Match = the wishlist's results, Edit criteria = its edit form
        open(url, label, edits, opener) {
            var u = new URL(url, window.location.href);
            if (u.origin !== window.location.origin) { return; }
            u.searchParams.set('embed', '1');
            this.label = label; this.edited = !!edits; this.opener = opener || document.activeElement; this.isOpen = true;
            this.$nextTick(() => { this.$refs.frame.src = u.toString(); this.$refs.closeBtn.focus(); });
        },
        onMessage(e) {
            if (e.origin !== window.location.origin || !e.data || e.data.rts !== 'done') { return; }
            this.close();
        },
        close() {
            if (!this.isOpen) { return; }
            this.isOpen = false;
            this.$refs.frame.src = 'about:blank';
            if (this.opener && this.opener.focus) { this.opener.focus(); }
            // the criteria may have changed: the list is scored on them, so reload it
            if (this.edited) { window.location.reload(); }
        },
    };
}
</script>
