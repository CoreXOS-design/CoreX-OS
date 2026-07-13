{{--
    Alpine components backing the shared syndication panel
    (partials/syndication-panel.blade.php).

    Plain globals in a <script> tag, NOT a Vite module: the panel is also
    injected into the DOM at runtime on the Properties index, and
    x-data="ppSyndication(...)" resolves against window at init time.

    Included by every page that renders the panel. Include it ONCE per page.
--}}
<script>
// Copy a listing URL to the clipboard.
//
// navigator.clipboard exists only in a secure context (https, or localhost).
// CoreX runs over plain http on some internal hosts, where it is undefined —
// hence the execCommand fallback. Returns true only when the text really landed.
async function corexCopyListingUrl(text) {
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
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
}

// Mixin: the copy-link button on every portal panel. `copied` flips the icon to
// a tick for two seconds; `copyError` renders inline on failure.
//
// It does NOT route failure through showMessage(): every panel renders that slot
// with `messageType === 'success'`, so an error there would be invisible.
function corexCopyLinkMixin() {
    return {
        copied: false,
        copyError: '',
        copyTimer: null,
        async copyLink(url) {
            this.copyError = '';
            if (!url || url === '#') {
                this.copyError = 'No public link for this listing yet';
                return;
            }
            if (!await corexCopyListingUrl(url)) {
                this.copyError = 'Could not copy the link';
                return;
            }
            this.copied = true;
            clearTimeout(this.copyTimer);
            this.copyTimer = setTimeout(() => { this.copied = false; }, 2000);
        },
    };
}

// Mixin: makes a portal panel participate in "Refresh all portals".
//
// The three portal panels are Alpine SIBLINGS, so a $dispatch from the Refresh All
// button bubbles UP past them and never arrives. They talk over a window bus instead.
//
// EVERY message on that bus carries propertyId, and both ends drop anything that
// isn't theirs. That guard is not decoration. On the Properties index one modal is
// reused for every listing: openSyn() blanks synBody, awaits a fetch, then injects
// the next property's panel. Today the old panel's components are destroyed during
// that await (Alpine's MutationObserver removes their .window listeners), so a stale
// listener cannot survive into the next property — but that is an IMPLICIT timing
// guarantee, and if it ever failed the failure mode is pushing the WRONG listing to
// Property24 / Private Property: a real, public, wrong-price advert. Correctness here
// must not depend on observer scheduling, so the propertyId check makes cross-property
// contamination structurally impossible. Prevent, don't hope (BUILD_STANDARD §3).
//
// Requires on the host component: propertyId, portalKey, portalLabel,
// isRefreshable(), refreshListing(), loading.
function corexSyndicationBus() {
    return {
        // Tell the Refresh All button whether this portal is currently refreshable.
        // Driven by x-effect on the panel root, so a toggle made in the panel
        // re-announces without a page reload.
        announceSyndicationState() {
            // Read the state SYNCHRONOUSLY (inside the calling x-effect) so the effect
            // subscribes to enabled/status/ref and re-announces when they change...
            const detail = {
                propertyId: this.propertyId,
                key: this.portalKey,
                label: this.portalLabel,
                live: this.isRefreshable(),
            };

            // ...but DEFER the dispatch. window.dispatchEvent runs listeners
            // synchronously, so syndicationRefreshAll.onPortalState() would read its own
            // `portals` object while THIS portal's x-effect is still the active reactive
            // effect — silently subscribing every portal to that object. It cannot loop
            // today only because onPortalState mutates a key rather than reassigning
            // `portals`; the day anyone prunes or reassigns it, all three portal effects
            // re-run. A microtask takes the read out of the effect, so the loop can never
            // be introduced by accident. (Still lands before paint — nothing is delayed.)
            queueMicrotask(() => {
                window.dispatchEvent(new CustomEvent('corex-syndication-portal-state', { detail }));
            });
        },

        // A Refresh All button asked for a census. Answer only its own panel's ask.
        onSyndicationCensusRequest(event) {
            if (!this.isSameProperty(event)) return;
            this.announceSyndicationState();
        },

        // "Refresh all portals" was pressed. A portal that isn't refreshable is none of
        // that button's business — it must never enable, first-submit or revive a portal
        // the agent deliberately left off. One already mid-push is skipped, not pushed
        // twice. `acked` is the button's promise about what it actually sent, so a portal
        // only signs it AFTER every reason it might no-op has been ruled out.
        onSyndicationRefreshAll(event) {
            if (!this.isSameProperty(event)) return;
            if (!this.isRefreshable() || this.loading) return;
            if (!this.canDispatchRefresh()) return;

            event.detail.acked.push(this.portalLabel);
            this.refreshListing();
        },

        // Overridden where a portal can be refreshable yet still unable to send (the
        // website panel needs a refresh URL). Default: nothing else can stop it.
        canDispatchRefresh() {
            return true;
        },

        // Numeric-vs-string ids must not decide whether a portal push happens.
        isSameProperty(event) {
            return Number(event.detail?.propertyId) === Number(this.propertyId);
        },
    };
}

// Private Property Syndication Alpine component
function ppSyndication(config) {
    return {
        ...corexCopyLinkMixin(),
        ...corexSyndicationBus(),
        portalKey: 'private_property',
        portalLabel: 'Private Property',
        propertyId: config.propertyId,
        enabled: config.enabled,
        status: config.status || '',
        ppRef: config.ppRef || '',
        lastSubmitted: config.lastSubmitted || '',
        lastError: config.lastError || '',
        exclusiveDays: config.exclusiveDays || 0,
        mandateType: config.mandateType || '',
        activatedAt: config.activatedAt || '',
        csrfToken: config.csrfToken,
        missingFields: config.missingFields || [],
        // Public PP listing URL — single source of truth is
        // Property::publicListingUrls()['pp'] (seeded server-side). Without
        // this init, this.publicUrl was undefined and ppListingUrl() always
        // fell through to the dead /search?q= hop. (The P24 panel inits its
        // own publicUrl; the PP panel was missing it.)
        publicUrl: config.publicUrl || '',
        loading: false,
        message: '',
        messageType: 'success',
        debugErrors: [],
        showDebug: false,
        // Address visibility
        hideStreetName: config.hideStreetName || false,
        hideStreetNumber: config.hideStreetNumber || false,
        hideComplexName: config.hideComplexName || false,
        hideUnitNumber: config.hideUnitNumber || false,
        // Video / Matterport
        youtubeVideoId: config.youtubeVideoId || '',
        matterportId: config.matterportId || '',
        videoLoading: false, videoMsg: '', videoOk: null,
        // Listing ownership
        ppListingId: '',
        listingIdLoading: false, listingIdMsg: '', listingIdOk: null,
        // Exclusive delay
        ppDelayUntil: config.ppDelayUntil || '',
        ppDelayUntilRaw: config.ppDelayUntilRaw || '',
        // Showday
        showShowdayForm: false,
        showdayStart: '',
        showdayEnd: '',
        showdayDescription: '',

        statusLabel() {
            const labels = {
                '': 'Disabled',
                'pending': 'Pending',
                'submitted': 'Submitted',
                'active': 'Active',
                'error': 'Error',
                'rejected': 'Rejected',
                'deactivated': 'Deactivated',
            };
            if (!this.enabled) return 'Disabled';
            return labels[this.status] || 'Disabled';
        },

        statusBadgeStyle() {
            const styles = {
                '': 'background:var(--surface-2); color:var(--text-muted);',
                'pending': 'background:color-mix(in srgb, var(--ds-amber) 14%, transparent); color:var(--ds-amber);',
                'submitted': 'background:color-mix(in srgb, var(--ds-amber) 14%, transparent); color:var(--ds-amber);',
                'active': 'background:color-mix(in srgb, var(--brand-button) 14%, transparent);color:var(--brand-button);',
                'error': 'background:color-mix(in srgb, var(--ds-crimson) 14%, transparent); color:var(--ds-crimson);',
                'rejected': 'background:color-mix(in srgb, var(--ds-crimson) 14%, transparent); color:var(--ds-crimson);',
                'deactivated': 'background:var(--surface-2); color:var(--text-muted);',
            };
            if (!this.enabled) return styles[''];
            return styles[this.status] || styles[''];
        },

        ppListingUrl() {
            // A.2.1 — sourced from Property::publicListingUrls()['pp'] server-side.
            // Never fall back to the legacy /search?q= hop — PP retired it and
            // it 404s ("This space is no longer occupied"). When there's no
            // resolvable URL yet (e.g. status 'submitted', not live), the link
            // is a no-op rather than a dead search.
            return this.publicUrl || '#';
        },

        // Is a re-push to Private Property meaningful right now? Single source of truth:
        // it gates the View · Refresh · Deactivate row AND the "Refresh all portals"
        // census, so the visible Refresh button and the bulk press can never disagree.
        //
        // Deliberately NOT named isLiveOnPortal(): "refreshable" and "publicly visible"
        // are not the same question (see p24Syndication, where sold/rented listings stay
        // on the portal but must not be re-pushed), and a predicate that claims to answer
        // both would be lying about one of them.
        isRefreshable() {
            return !!this.enabled && !!this.ppRef && ['active', 'submitted'].includes(this.status);
        },

        showMessage(msg, type = 'success') {
            this.message = msg;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 5000);
        },

        async toggleEnabled() {
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/toggle`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.enabled = data.pp_syndication_enabled;
                    this.status = data.pp_syndication_status || '';
                    this.showMessage(this.enabled ? 'PP syndication enabled' : 'PP syndication disabled');
                    // Refresh readiness when enabling so warnings show immediately
                    if (this.enabled) {
                        await this.refreshReadiness();
                    }
                } else {
                    this.showMessage(data.message || 'Toggle failed', 'error');
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async refreshReadiness() {
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/readiness`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.missingFields = data.missing_fields || [];
            } catch (e) { /* silent */ }
        },

        async submitListing() {
            // Double-check readiness before submitting
            await this.refreshReadiness();
            if (this.missingFields.length > 0) {
                this.showMessage('Cannot submit — fill in the required fields first', 'error');
                return;
            }

            this.loading = true;
            this.debugErrors = [];
            this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({}),
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'submitted';
                    this.ppRef = data.pp_ref || this.ppRef;
                    this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    this.lastError = '';
                    this.debugErrors = [];
                    this.showDebug = false;
                    this.showMessage(data.message || 'Submitted to PP');
                } else {
                    if (data.missing_fields && data.missing_fields.length > 0) {
                        this.missingFields = data.missing_fields;
                    }
                    this.status = data.pp_syndication_status || 'error';
                    this.lastError = data.message || 'Submission failed';

                    // Build debug info from all available error data
                    this.debugErrors = [];
                    if (data.errors && data.errors.length > 0) {
                        data.errors.forEach(e => this.debugErrors.push(typeof e === 'string' ? e : e.label || JSON.stringify(e)));
                    }
                    if (data.message) {
                        this.debugErrors.push(data.message);
                    }
                    this.showDebug = true;
                }
            } catch (e) {
                this.debugErrors = ['Network error: ' + e.message];
                this.showDebug = true;
            } finally {
                this.loading = false;
            }
        },

        async refreshListing() {
            this.loading = true;
            this.debugErrors = [];
            this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/submit`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({}),
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'active';
                    this.ppRef = data.pp_ref || this.ppRef;
                    this.lastSubmitted = new Date().toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    this.lastError = '';
                    this.showMessage('Listing synced to PP');
                } else {
                    this.lastError = data.message || 'Sync failed';
                    this.debugErrors = data.errors || [data.message];
                    this.showDebug = true;
                }
            } catch (e) {
                this.debugErrors = ['Network error: ' + e.message];
                this.showDebug = true;
            } finally {
                this.loading = false;
            }
        },

        async deactivateListing() {
            if (!confirm('Deactivate this listing on Private Property?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/deactivate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'deactivated';
                    this.showMessage('Listing deactivated on PP');
                } else {
                    this.showMessage(data.message || 'Deactivation failed', 'error');
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async reactivateListing() {
            if (!confirm('Reactivate this listing on Private Property?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/reactivate`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (data.success) {
                    this.status = data.pp_syndication_status || 'submitted';
                    this.showMessage('Listing reactivated on PP');
                } else {
                    this.debugErrors = [data.message || 'Reactivation failed'];
                    this.showDebug = true;
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async submitShowday() {
            if (!this.showdayStart || !this.showdayEnd) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/showday`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        start_date: this.showdayStart,
                        end_date: this.showdayEnd,
                        description: this.showdayDescription || 'Open Showday',
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.showMessage('Showday event submitted to PP');
                    this.showShowdayForm = false;
                    this.showdayStart = '';
                    this.showdayEnd = '';
                    this.showdayDescription = '';
                } else {
                    this.debugErrors = [data.message || 'Showday submission failed'];
                    this.showDebug = true;
                }
            } catch (e) {
                this.showMessage('Network error', 'error');
            } finally {
                this.loading = false;
            }
        },

        async pushVideo() {
            if (!this.youtubeVideoId && !this.matterportId) { this.videoOk = false; this.videoMsg = 'Enter a YouTube ID or Matterport ID'; return; }
            if (this.youtubeVideoId && this.youtubeVideoId.length !== 11) { this.videoOk = false; this.videoMsg = 'YouTube ID must be exactly 11 characters'; return; }
            this.videoLoading = true; this.videoMsg = '';
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/video`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ youtube_video_id: this.youtubeVideoId || null, matterport_id: this.matterportId || null }),
                });
                const data = await res.json();
                this.videoOk = data.success;
                this.videoMsg = data.message;
            } catch (e) { this.videoOk = false; this.videoMsg = 'Network error'; }
            this.videoLoading = false;
        },

        async claimListingOwnership() {
            if (!this.ppListingId.trim()) { this.listingIdOk = false; this.listingIdMsg = 'Enter PP Encrypted Listing ID'; return; }
            if (!confirm('This will permanently claim PP ownership of this listing. Continue?')) return;
            this.listingIdLoading = true; this.listingIdMsg = '';
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/syndication/update-id`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ pp_listing_id: this.ppListingId }),
                });
                const data = await res.json();
                this.listingIdOk = data.success;
                this.listingIdMsg = data.message;
                if (data.success) this.ppListingId = '';
            } catch (e) { this.listingIdOk = false; this.listingIdMsg = 'Network error'; }
            this.listingIdLoading = false;
        },

        ppDelayDaysRemaining() {
            if (!this.ppDelayUntilRaw) return 0;
            const diff = new Date(this.ppDelayUntilRaw) - new Date();
            return Math.max(0, Math.ceil(diff / 86400000));
        },

        isPpExclusiveActive() {
            return this.ppDelayDaysRemaining() > 0;
        },

        async saveVisibility() {
            try {
                await fetch(`/corex/properties/${this.propertyId}/syndication/visibility`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        hide_street_name: this.hideStreetName,
                        hide_street_number: this.hideStreetNumber,
                        hide_complex_name: this.hideComplexName,
                        hide_unit_number: this.hideUnitNumber,
                    }),
                });
            } catch (e) { /* silent save */ }
        },
    };
}

function p24Syndication(config) {
    return {
        ...corexCopyLinkMixin(),
        ...corexSyndicationBus(),
        portalKey: 'property24',
        portalLabel: 'Property24',
        propertyId: config.propertyId, enabled: config.enabled, status: config.status || '',
        p24Ref: config.p24Ref || '', lastSubmitted: config.lastSubmitted || '',
        lastError: config.lastError || '', csrfToken: config.csrfToken, isSandbox: config.isSandbox ?? true,
        suburb: config.suburb || '', city: config.city || '', province: config.province || '', suburbId: config.suburbId || '', listingType: config.listingType || 'sale',
        missingFields: config.missingFields || [],
        ppDelayUntilRaw: config.ppDelayUntilRaw || '', ppDelayUntil: config.ppDelayUntil || '',
        resolvedP24AgencyId: config.resolvedP24AgencyId || '', resolvedP24AgencyLabel: config.resolvedP24AgencyLabel || '',
        loading: false, message: '', messageType: 'success', debugErrors: [], showDebug: false,
        isPpExclusiveLocked() {
            if (!this.ppDelayUntilRaw) return false;
            return new Date(this.ppDelayUntilRaw) > new Date();
        },
        statusLabel() {
            // 'sold'/'rented' are terminal market states that P24 KEEPS on the
            // portal — the listing is still publicly visible, so never label them
            // 'Deactivated'. Only 'deactivated' means the portal dropped it.
            const labels = {'':'Disabled','pending':'Pending','submitting':'Syncing…','submitted':'Submitted','active':'Active','sold':'Sold — still on P24','rented':'Rented — still on P24','error':'Error','rejected':'Rejected','deactivated':'Deactivated'};
            if (!this.enabled) return 'Disabled';
            return labels[this.status] || 'Disabled';
        },
        statusBadgeStyle() {
            const styles = {'':'background:var(--surface-2);color:var(--text-muted);','pending':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','submitting':'background:color-mix(in srgb, var(--brand-button) 14%, transparent);color:var(--brand-button);','submitted':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','active':'background:color-mix(in srgb, var(--brand-button) 14%, transparent);color:var(--brand-button);','sold':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','rented':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','error':'background:color-mix(in srgb, var(--ds-crimson) 14%, transparent);color:var(--ds-crimson);','rejected':'background:color-mix(in srgb, var(--ds-crimson) 14%, transparent);color:var(--ds-crimson);','deactivated':'background:var(--surface-2);color:var(--text-muted);'};
            if (!this.enabled) return styles[''];
            return styles[this.status] || styles[''];
        },
        p24ListingUrl() {
            // A.2.1 — single source of truth lives on Property::publicListingUrls()['p24'].
            // Server-rendered URL when status is 'active'. Sandbox testing still
            // computes locally because the sandbox domain isn't in the accessor.
            if (this.publicUrl) return this.publicUrl;
            const domain = this.isSandbox ? 'www.exdev.property24-test.com' : 'www.property24.com';
            const slug = (s) => (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'property';
            const section = this.listingType === 'rental' ? 'to-rent' : 'for-sale';
            return `https://${domain}/${section}/${slug(this.suburb)}/${slug(this.city)}/${slug(this.province)}/${this.suburbId || '0'}/${this.p24Ref}`;
        },
        // Is a re-push to Property24 meaningful right now? Single source of truth — it
        // gates the action row below AND the "Refresh all portals" census.
        //
        // 'submitting' COUNTS: the listing is on the portal and a queued push is merely
        // in flight. Excluding it would make the Refresh All button vanish from under the
        // agent mid-sync; the !loading guard in onSyndicationRefreshAll() is what prevents
        // the double-push (and SubmitListingToProperty24 is ShouldBeUnique besides).
        //
        // 'sold'/'rented' DO NOT count, and that is why this is not called
        // isLiveOnPortal(): P24 KEEPS those listings publicly visible (see statusLabel),
        // so they ARE live — but re-pushing a sold listing is not something either button
        // should offer. This predicate answers "should Refresh appear", not "is it on the
        // portal". Same exclusion the action row has always had — unchanged behaviour.
        isRefreshable() {
            return !!this.enabled && !!this.p24Ref && ['active', 'submitted', 'submitting'].includes(this.status);
        },
        showMessage(msg, type = 'success') { this.message = msg; this.messageType = type; setTimeout(() => { this.message = ''; }, 5000); },
        async toggleEnabled() {
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/toggle`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.enabled = data.p24_syndication_enabled; this.status = data.p24_syndication_status || ''; this.showMessage(this.enabled ? 'P24 syndication enabled' : 'P24 syndication disabled'); }
                else { this.showMessage(data.message || 'Toggle failed', 'error'); }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
        init() {
            // If the page loads mid-sync (status persisted as 'submitting' from a
            // queued push that hasn't finished), resume polling so the panel
            // resolves to active/error without a manual reload.
            if (this.status === 'submitting') { this.loading = true; this._pollP24SyncState('Listing synced to P24'); }
        },
        // Poll the lightweight DB-only sync-state endpoint until the queued
        // SubmitListingToProperty24 job flips the status off 'submitting'. P24's
        // saveListing takes 1-2 min for photo-heavy listings, so the UI never
        // blocks on it — it shows "Syncing…" and resolves here.
        _pollP24SyncState(successMsg) {
            const startedAt = Date.now();
            const maxMs = 180000; // ~2min P24 worst case + buffer
            const tick = async () => {
                if (Date.now() - startedAt > maxMs) {
                    this.loading = false;
                    this.showMessage('Still syncing in the background — reload in a moment to see the result.');
                    return;
                }
                try {
                    const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/sync-state`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    const st = data.p24_syndication_status || '';
                    if (st === 'submitting') { setTimeout(tick, 3000); return; }
                    this.status = st;
                    this.p24Ref = data.p24_ref || this.p24Ref;
                    if (st === 'active' || st === 'submitted') {
                        this.lastSubmitted = data.p24_last_submitted_at || this.lastSubmitted;
                        if (data.p24_last_error) {
                            // Live on P24, but the last push deferred (transient P24
                            // outage). Surface the note honestly rather than a false
                            // "success" toast — the listing is live with its previous
                            // content; the latest changes re-sync on the next Refresh.
                            this.lastError = data.p24_last_error;
                            this.showMessage(data.p24_last_error);
                        } else {
                            this.lastError = ''; this.debugErrors = []; this.showDebug = false;
                            this.showMessage(successMsg);
                        }
                    } else if (st === 'error' || st === 'rejected') {
                        this.lastError = data.p24_last_error || 'Sync failed';
                        this.debugErrors = [this.lastError]; this.showDebug = true;
                    } else {
                        this.showMessage('Sync finished');
                    }
                    this.loading = false;
                } catch (e) {
                    setTimeout(tick, 3000); // transient network error — retry until maxMs
                }
            };
            setTimeout(tick, 1500);
        },
        async submitListing() {
            this.loading = true; this.debugErrors = []; this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/submit`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({}) });
                const data = await res.json();
                if (data.success && data.queued) {
                    this.status = 'submitting';
                    this.lastError = ''; this.debugErrors = []; this.showDebug = false;
                    this.showMessage(data.message || 'Syncing to Property24…');
                    this._pollP24SyncState('Listing synced to P24');
                    return; // keep loading=true; the poll clears it
                }
                // Not queued — a synchronous rejection (e.g. 422 missing fields)
                this.status = data.p24_syndication_status || 'error'; this.lastError = data.message || 'Submission failed'; this.debugErrors = [];
                if (data.errors && data.errors.length > 0) { data.errors.forEach(e => this.debugErrors.push(typeof e === 'string' ? e : e.label || JSON.stringify(e))); }
                if (data.message) { this.debugErrors.push(data.message); }
                this.showDebug = true; this.loading = false;
            } catch (e) { this.debugErrors = ['Network error: ' + e.message]; this.showDebug = true; this.loading = false; }
        },
        async refreshListing() {
            this.loading = true; this.debugErrors = []; this.showDebug = false;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/submit`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({}) });
                const data = await res.json();
                if (data.success && data.queued) {
                    this.status = 'submitting';
                    this.lastError = ''; this.debugErrors = []; this.showDebug = false;
                    this.showMessage(data.message || 'Refreshing on Property24…');
                    this._pollP24SyncState('Listing refreshed on P24');
                    return;
                }
                this.lastError = data.message || 'Sync failed';
                this.debugErrors = (data.errors && data.errors.length) ? data.errors.map(e => typeof e === 'string' ? e : (e.label || JSON.stringify(e))) : [this.lastError];
                this.showDebug = true; this.loading = false;
            } catch (e) { this.debugErrors = ['Network error: ' + e.message]; this.showDebug = true; this.loading = false; }
        },
        async deactivateListing() {
            if (!confirm('Deactivate this listing on Property24?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/deactivate`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'deactivated'; this.showMessage('Listing deactivated on P24'); }
                else { this.showMessage(data.message || 'Deactivation failed', 'error'); }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
        async reactivateListing() {
            if (!confirm('Reactivate this listing on Property24?')) return;
            this.loading = true;
            try {
                const res = await fetch(`/corex/properties/${this.propertyId}/p24-syndication/reactivate`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (data.success) { this.status = data.p24_syndication_status || 'submitted'; this.showMessage('Listing reactivated on P24'); }
                else { this.debugErrors = [data.message || 'Reactivation failed']; this.showDebug = true; }
            } catch (e) { this.showMessage('Network error', 'error'); } finally { this.loading = false; }
        },
    };
}

// Website Syndication Alpine component — mirrors p24Syndication() so the
// website portal panel looks and behaves exactly like the Property24 one.
// The website model is enable = active (no async submit round-trip), so the
// header toggle activates/deactivates directly.
function websiteSyndication(config) {
    return {
        ...corexCopyLinkMixin(),
        ...corexSyndicationBus(),
        // An agency can have several websites, so the census key must be unique
        // per API key — not the shared literal the single-instance panels use.
        portalKey: config.key,
        portalLabel: config.name || 'Website',
        propertyId: config.propertyId,
        name: config.name || 'Website',
        enabled: config.enabled,
        status: config.status || '',
        lastSynced: config.lastSynced || '',
        lastError: config.lastError || '',
        publicUrl: config.publicUrl || '',
        csrfToken: config.csrfToken,
        urls: config.urls || {},
        loading: false, message: '', messageType: 'success', errorMsg: '',
        statusLabel() {
            const labels = {'':'Off','pending':'Pending','submitted':'Submitted','active':'Active','error':'Error','deactivated':'Off'};
            if (!this.enabled) return 'Off';
            return labels[this.status] || 'Off';
        },
        statusBadgeStyle() {
            const styles = {'':'background:var(--surface-2);color:var(--text-muted);','pending':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','submitted':'background:color-mix(in srgb, var(--ds-amber) 14%, transparent);color:var(--ds-amber);','active':'background:color-mix(in srgb, var(--brand-button) 14%, transparent);color:var(--brand-button);','error':'background:color-mix(in srgb, var(--ds-crimson) 14%, transparent);color:var(--ds-crimson);','deactivated':'background:var(--surface-2);color:var(--text-muted);'};
            if (!this.enabled) return styles[''];
            return styles[this.status] || styles[''];
        },
        showMessage(msg, type = 'success') { this.message = msg; this.messageType = type; setTimeout(() => { this.message = ''; }, 5000); },
        // Is a re-push to this website meaningful right now? Single source of truth —
        // gates the action row below AND the "Refresh all portals" census.
        isRefreshable() {
            return !!this.enabled && ['active', 'submitted'].includes(this.status);
        },
        // post() silently returns when handed no URL, so without this the bulk button
        // could sign `acked` for a website it never actually sent to and report
        // "Refreshing on HFC Website…" having done nothing. The Refresh All button's
        // message is a promise about what left the browser; keep it true.
        canDispatchRefresh() {
            return !!this.urls?.refresh;
        },
        // Name-parity with ppSyndication/p24Syndication so corexSyndicationBus()
        // can fire every portal through one method.
        async refreshListing() {
            await this.post(this.urls.refresh);
        },
        async toggleEnabled() {
            // Header click toggles the active/deactivated state (enable = active).
            await this.post(this.enabled ? this.urls.deactivate : this.urls.activate);
        },
        async post(url) {
            if (this.loading || !url) return;
            this.loading = true; this.errorMsg = '';
            const fd = new FormData(); fd.append('_token', this.csrfToken);
            try {
                const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
                const j = await r.json().catch(() => ({}));
                if (r.ok && j.success) {
                    this.enabled = j.enabled;
                    this.status = j.status || (j.enabled ? 'active' : 'deactivated');
                    this.lastSynced = j.last_synced || this.lastSynced;
                    if (j.message) this.showMessage(j.message);
                } else {
                    this.errorMsg = j.message || ('Request failed (HTTP ' + r.status + ')');
                }
            } catch (e) { this.errorMsg = e.message || 'Network error'; }
            finally { this.loading = false; }
        },
    };
}

// "Refresh all portals" — re-push this listing to every portal that currently
// carries it, in one press, instead of hunting three separate Refresh buttons.
//
// A DISPATCHER, never a second implementation: it fires each portal's own
// refreshListing(), so every guard, spinner, error panel and (for P24) sync-state
// poll that protects a single Refresh protects the bulk press for free. There is
// no endpoint, no service and no readiness logic behind this component.
function syndicationRefreshAll(propertyId) {
    return {
        propertyId: propertyId,
        portals: {},   // key -> { key, label, live }, kept current by the census
        fired: '',
        note: '',

        init() {
            // The portal panels sit ABOVE this block in the DOM, so Alpine has
            // already initialised them and their opening announce fired before
            // this component's listener existed — that first census is lost. Ask
            // for a fresh one now that we ARE listening, or the button renders
            // hidden on a listing that is live on all three portals. Later
            // announces (a toggle, a status flip) land normally.
            this.$nextTick(() => window.dispatchEvent(new CustomEvent('corex-syndication-census-request', {
                detail: { propertyId: this.propertyId },
            })));
        },

        // Only ever count portals belonging to the listing this panel is showing.
        // See corexSyndicationBus() for why the id travels on every message.
        onPortalState(detail) {
            if (Number(detail?.propertyId) !== Number(this.propertyId)) return;
            this.portals[detail.key] = detail;
        },

        livePortals() {
            return Object.values(this.portals).filter(p => p.live);
        },
        liveCount() {
            return this.livePortals().length;
        },
        liveLabels() {
            return this.livePortals().map(p => p.label).join(' · ');
        },

        refreshAll() {
            this.fired = '';
            this.note = '';

            const detail = { propertyId: this.propertyId, acked: [] };
            window.dispatchEvent(new CustomEvent('corex-syndication-refresh-all', { detail }));

            // DOM listeners run synchronously, so `acked` is complete by now — the
            // button reports exactly which portals it fired rather than guessing.
            if (detail.acked.length === 0) {
                // Nothing went out. Say so — a bulk button that silently does nothing
                // reads as broken. Deliberately does NOT claim WHY: a portal declines for
                // more than one reason (already mid-push, or no longer live because its
                // state changed since the last census), and this component cannot tell
                // them apart from here. Each portal above shows its own true state, so
                // point there rather than assert a reason that might be wrong.
                this.note = 'Nothing to send — check each portal above.';
                setTimeout(() => { this.note = ''; }, 6000);
                return;
            }

            this.fired = 'Refreshing on ' + detail.acked.join(', ') + '…';
            setTimeout(() => { this.fired = ''; }, 6000);
        },
    };
}
</script>
