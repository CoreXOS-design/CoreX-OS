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

// Private Property Syndication Alpine component
function ppSyndication(config) {
    return {
        ...corexCopyLinkMixin(),
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
</script>
