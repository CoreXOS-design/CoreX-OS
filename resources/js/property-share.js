// Shared "who's contact details" share chooser — extracted from the property
// live-preview chooser (resources/views/corex/properties/partials/syndication-panel.blade.php),
// which already asks my-details vs listing-agent-details and already produces
// a correctly-attributed link. This is that same behaviour, not a new design,
// made reusable across any screen: properties, core matches, buyers pipeline.
//
// Deliberately client-side only. The attribution RESOLUTION stays entirely in
// PropertyController::livePreview() (routes/web.php: corex.properties.preview)
// — the same route/controller live-preview, this chooser, AND the whole-wishlist
// share page's individual property cards (?agent=none, in shared/_match-group-body.blade.php)
// all already point at. This file only builds the query string; it never
// re-implements or duplicates that resolution, so nothing that already reads
// ?agent=<id> / ?agent=listing / ?agent=none is affected by this file existing.
//
// Usage: x-data="corexPropertyShareChooser({ previewUrl, myId, isAssistant, shareText, shareSubject })"

async function corexShareCopyToClipboard(text) {
    if (!text) return false;

    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) { /* fall through to the legacy path */ }
    }

    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) { /* ok stays false */ }
    document.body.removeChild(ta);
    return ok;
}

function corexPropertyShareChooser(config) {
    config = config || {};

    return {
        // 'choose' — my details / listing agent details. 'share' — copy / WhatsApp / email,
        // once a choice (or the only available choice, for an assistant) is made.
        step: config.isAssistant ? 'share' : 'choose',
        isAssistant: !!config.isAssistant,
        previewUrl: config.previewUrl || '',
        myId: config.myId,
        shareText: config.shareText || 'Check out this listing',
        shareSubject: config.shareSubject || 'Property listing',
        chosenUrl: config.isAssistant ? corexPropertyShareListingUrl(config.previewUrl) : '',
        copied: false,
        copyError: '',
        copyTimer: null,

        chooseMine() {
            this.chosenUrl = corexPropertyShareMineUrl(this.previewUrl, this.myId);
            this.step = 'share';
        },
        chooseListing() {
            this.chosenUrl = corexPropertyShareListingUrl(this.previewUrl);
            this.step = 'share';
        },
        back() {
            this.step = 'choose';
            this.copied = false;
            this.copyError = '';
        },

        wa() {
            return 'https://wa.me/?text=' + encodeURIComponent(this.shareText + ' ' + this.chosenUrl);
        },
        mail() {
            return 'mailto:?subject=' + encodeURIComponent(this.shareSubject)
                + '&body=' + encodeURIComponent(this.shareText + ' ' + this.chosenUrl);
        },
        async copy() {
            this.copyError = '';
            if (!this.chosenUrl) {
                this.copyError = 'No link to copy yet';
                return;
            }
            if (!await corexShareCopyToClipboard(this.chosenUrl)) {
                this.copyError = 'Could not copy the link';
                return;
            }
            this.copied = true;
            clearTimeout(this.copyTimer);
            this.copyTimer = setTimeout(() => { this.copied = false; }, 2000);
        },
    };
}

// Exposed separately (not just inside the Alpine factory) so a caller that
// already knows which way it wants to go — e.g. a card that pre-resolves for
// an assistant — can build the same URL without spinning up the whole chooser.
function corexPropertyShareMineUrl(previewUrl, myId) {
    return previewUrl + '?agent=' + myId;
}
function corexPropertyShareListingUrl(previewUrl) {
    return previewUrl + '?agent=listing';
}

window.corexPropertyShareChooser = corexPropertyShareChooser;
window.corexPropertyShareMineUrl = corexPropertyShareMineUrl;
window.corexPropertyShareListingUrl = corexPropertyShareListingUrl;
