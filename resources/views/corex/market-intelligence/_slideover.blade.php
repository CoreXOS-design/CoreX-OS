{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Detail slide-over wrapper.

    One instance per page. Listens for `open-slideover` events dispatched
    by the row partials, fetches the body HTML async from
    /corex/market-intelligence/{listing}/details, and renders it inside
    the slide-over container.

    Spec: build-f-market-intelligence-redesign-spec.md §8.5.
--}}

<div x-data="miSlideover()"
     x-show="isOpen"
     x-cloak
     @keydown.escape.window="close()"
     @open-slideover.window="open($event.detail.listingId, $event.detail.trigger)"
     style="position: fixed; top: 0; right: 0; bottom: 0; left: 0;
            background: rgba(0,0,0,0.40); z-index: 60;
            display: flex; justify-content: flex-end;">
    <aside class="mi-slideover-panel"
           @click.stop
           x-transition:enter="transition transform duration-200"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition transform duration-200"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="translate-x-full"
           role="dialog"
           aria-modal="true"
           aria-label="Listing details"
           style="width: 40vw; max-width: 720px; min-width: 480px;
                  background: var(--surface); height: 100%;
                  box-shadow: -2px 0 12px rgba(0,0,0,0.10);
                  display: flex; flex-direction: column; overflow: hidden;"
           x-trap.inert.noscroll="isOpen">

        {{-- Sticky top bar with close button --}}
        <div style="display: flex; align-items: center; justify-content: space-between;
                    padding: 10px 14px; border-bottom: 1px solid var(--border);
                    background: var(--brand-default, #0b2a4a); color: #fff; flex-shrink: 0;">
            <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">
                Listing details
            </span>
            <button type="button"
                    @click="close()"
                    x-ref="closeBtn"
                    aria-label="Close panel"
                    style="background: none; border: none; color: #fff; font-size: 1.5rem; line-height: 1; cursor: pointer; padding: 0 6px;">
                ×
            </button>
        </div>

        {{-- Loading state --}}
        <template x-if="loading">
            <div style="padding: 60px 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
                Loading…
            </div>
        </template>

        {{-- Error state --}}
        <template x-if="!loading && error">
            <div style="padding: 40px 24px; text-align: center; color: var(--ds-crimson, #dc2626);"
                 x-text="error"></div>
        </template>

        {{-- Body — async-fetched HTML --}}
        <div x-show="!loading && !error && content"
             x-html="content"
             style="flex: 1; overflow-y: auto; overscroll-behavior: contain;"></div>
    </aside>
</div>

<style>
    @media (max-width: 768px) {
        .mi-slideover-panel {
            width: 100vw !important;
            min-width: 0 !important;
            max-width: none !important;
        }
    }
</style>

<script>
function miSlideover() {
    return {
        isOpen: false,
        loading: false,
        error: null,
        content: '',
        currentListingId: null,
        triggerElement: null,
        bodyOverflowPrev: '',

        async open(listingId, trigger) {
            // Toggle: same row clicked twice → close.
            if (this.currentListingId === listingId && this.isOpen) {
                this.close();
                return;
            }
            this.currentListingId = listingId;
            this.triggerElement = trigger || null;
            this.isOpen = true;
            this.loading = true;
            this.error = null;
            this.content = '';

            // Suppress background page scroll while the slide-over is open.
            this.bodyOverflowPrev = document.body.style.overflow;
            document.body.style.overflow = 'hidden';

            // Move keyboard focus to the close button on open.
            this.$nextTick(() => this.$refs.closeBtn?.focus());

            try {
                const res = await fetch(`/corex/market-intelligence/${listingId}/details`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html'
                    },
                    credentials: 'same-origin'
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.content = await res.text();
                this.$nextTick(() => {
                    if (typeof window.initSlideoverTabs === 'function') {
                        window.initSlideoverTabs(this.$root);
                    }
                });
            } catch (e) {
                this.error = 'Failed to load: ' + (e.message || 'unknown');
            } finally {
                this.loading = false;
            }
        },

        close() {
            this.isOpen = false;
            // Restore background scroll.
            document.body.style.overflow = this.bodyOverflowPrev;
            // Return focus to the triggering row, if we have a reference.
            const trigger = this.triggerElement;
            this.triggerElement = null;
            this.currentListingId = null;
            if (trigger && typeof trigger.focus === 'function') {
                requestAnimationFrame(() => trigger.focus());
            }
        }
    };
}

// Vanilla JS tab switcher — bound once per slide-over content load.
window.initSlideoverTabs = function (root) {
    const tabBar = root.querySelector('.mi-tab-bar');
    if (!tabBar) return;
    const buttons = tabBar.querySelectorAll('button[data-tab]');
    const panels = root.querySelectorAll('[data-panel]');
    buttons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const key = btn.getAttribute('data-tab');
            buttons.forEach(b => {
                const isActive = b === btn;
                b.classList.toggle('active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            panels.forEach(p => {
                p.hidden = p.getAttribute('data-panel') !== key;
            });
        });
    });
};
</script>
