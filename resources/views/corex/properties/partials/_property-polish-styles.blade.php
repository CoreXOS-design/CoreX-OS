{{--
    Property detail page polish (Johan, 2026-10-17): "keep how it looks now but just add some of the
    moderness we added like the buttons hovering and stuff like that."

    RESTYLE ONLY. Nothing here changes a size, position, order, route, permission, field name,
    data-tour hook or any Alpine behaviour. It adds the same feel the sidebar items and the new
    list look have: a soft lift + tint on hover, an accent edge that grows in, an icon nudge, a
    clear focus ring, an animated tab underline, and the dot + word status.

    Page-scoped on purpose (every rule starts with .corex-props-v2, the wrapper on show.blade.php),
    so no other screen is touched and no CSS build is needed. The hover/edge/lift recipe is copied
    from .corex-nav-item and .list-header-filter in resources/css/corex.css.

    Everything that moves is switched off under prefers-reduced-motion.
--}}
<style>
    .corex-props-v2 {
        --pp-accent: var(--brand-button, #0ea5e9);
        --pp-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    /* ── 1. Action buttons (left Actions column + header strip) ─────────────────────────────── */
    .corex-props-v2 .prop-action-btn {
        position: relative;
        transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease,
                    transform 220ms var(--pp-spring), box-shadow 200ms ease, opacity 150ms ease;
    }
    /* Accent edge that grows down the left side on hover (as on the sidebar items). */
    .corex-props-v2 .prop-action-btn::before {
        content: "";
        position: absolute;
        left: 0;
        top: 20%;
        bottom: 20%;
        width: 3px;
        border-radius: 3px;
        background: var(--pp-tone, var(--pp-accent));
        transform: scaleY(0);
        transition: transform 220ms ease;
        pointer-events: none;
    }
    .corex-props-v2 .prop-action-btn svg {
        transition: transform 220ms var(--pp-spring), color 150ms ease;
    }
    /* Tone: the destructive ones hover in their warning colour, Save / Approve in green. */
    .corex-props-v2 .prop-action-btn-success { --pp-tone: var(--ds-green, #059669); }
    .corex-props-v2 .prop-action-btn-danger { --pp-tone: var(--ds-crimson, #dc2626); }
    .corex-props-v2 .prop-action-btn[data-oas-action="report_non_compliance"] { --pp-tone: var(--ds-amber, #d97706); }

    .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible):not(:disabled):not(.cursor-not-allowed) {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px -14px color-mix(in srgb, var(--pp-tone, var(--pp-accent)) 55%, transparent);
    }
    .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible):not(:disabled):not(.cursor-not-allowed):not(.is-dirty) {
        background: color-mix(in srgb, var(--pp-tone, var(--pp-accent)) 10%, var(--surface-2));
        border-color: color-mix(in srgb, var(--pp-tone, var(--pp-accent)) 45%, var(--border));
    }
    .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible):not(:disabled):not(.cursor-not-allowed)::before {
        transform: scaleY(1);
    }
    .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible):not(:disabled):not(.cursor-not-allowed):not(.is-dirty) svg {
        color: var(--pp-tone, var(--pp-accent));
    }
    .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible):not(:disabled):not(.cursor-not-allowed) svg {
        transform: translateX(2px) scale(1.12);
    }
    .corex-props-v2 .prop-action-btn.prop-action-btn-danger:is(:hover, :focus-visible):not(:disabled),
    .corex-props-v2 .prop-action-btn[data-oas-action="report_non_compliance"]:is(:hover, :focus-visible) {
        color: var(--pp-tone);
    }
    .corex-props-v2 .prop-action-btn:active:not(:disabled):not(.cursor-not-allowed) {
        transform: translateY(0) scale(0.985);
        transition-duration: 80ms;
    }
    .corex-props-v2 .prop-action-btn:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--pp-tone, var(--pp-accent)) 65%, transparent);
        outline-offset: 2px;
    }

    /* The small coverage dot button beside "Generate Presentation" moves with it. */
    .corex-props-v2 .prop-cov-dot-btn {
        transition: background-color 150ms ease, border-color 150ms ease, transform 220ms var(--pp-spring);
    }
    .corex-props-v2 .prop-cov-dot-btn:hover {
        transform: translateY(-1px);
        border-color: color-mix(in srgb, var(--pp-accent) 45%, var(--border));
    }
    .corex-props-v2 .prop-cov-dot-btn:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--pp-accent) 65%, transparent);
        outline-offset: 2px;
    }

    /* Header-strip extras: the back arrow and the "?" help button get the same lift. */
    .corex-props-v2 .prop-identity-strip .corex-btn-outline,
    .corex-props-v2 .prop-identity-strip .corex-tour-launcher-btn {
        transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease,
                    transform 220ms var(--pp-spring), box-shadow 200ms ease;
    }
    .corex-props-v2 .prop-identity-strip .corex-btn-outline:hover,
    .corex-props-v2 .prop-identity-strip .corex-tour-launcher-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 20px -14px color-mix(in srgb, var(--pp-accent) 55%, transparent);
    }
    .corex-props-v2 .prop-identity-strip .corex-btn-outline:focus-visible,
    .corex-props-v2 .prop-identity-strip .corex-tour-launcher-btn:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--pp-accent) 65%, transparent);
        outline-offset: 2px;
    }

    /* ── 3. Header status: dot + word, so the mandate pill ("Open") reads like the two before it ── */
    .corex-props-v2 .prop-identity-strip [data-mandate-chip] {
        background: transparent !important;
        border: 0 !important;
        padding: 0 !important;
        height: auto !important;
        font-weight: 500 !important;
        color: var(--text-primary) !important;
        transition: color 150ms ease;
    }
    .corex-props-v2 .prop-identity-strip a[data-mandate-chip]:hover {
        color: var(--pp-accent) !important;
    }

    /* ── 2. Tabs: hover tint, animated underline, scroll instead of cut-off ────────────────── */
    .corex-props-v2 .prop-tabbar {
        scrollbar-width: thin;
        scrollbar-color: color-mix(in srgb, var(--text-muted) 40%, transparent) transparent;
        overscroll-behavior-x: contain;
    }
    .corex-props-v2 .prop-tabbar::-webkit-scrollbar { height: 4px; }
    .corex-props-v2 .prop-tabbar::-webkit-scrollbar-track { background: transparent; }
    .corex-props-v2 .prop-tabbar::-webkit-scrollbar-thumb {
        background: color-mix(in srgb, var(--text-muted) 40%, transparent);
        border-radius: 4px;
    }
    /* Soft fade at whichever edge still has tabs behind it. Driven by the row's own scroll position
       (CSS scroll-driven animation): no fade at all when every tab fits, and browsers without
       support simply scroll without the fade. */
    @property --pp-fade-l { syntax: '<length>'; inherits: false; initial-value: 0px; }
    @property --pp-fade-r { syntax: '<length>'; inherits: false; initial-value: 0px; }
    @keyframes pp-tab-fade {
        0%   { --pp-fade-l: 0px;  --pp-fade-r: 36px; }
        4%   { --pp-fade-l: 36px; --pp-fade-r: 36px; }
        96%  { --pp-fade-l: 36px; --pp-fade-r: 36px; }
        100% { --pp-fade-l: 36px; --pp-fade-r: 0px; }
    }
    @supports (animation-timeline: scroll()) {
        .corex-props-v2 .prop-tabbar {
            -webkit-mask-image: linear-gradient(to right, transparent 0, #000 var(--pp-fade-l), #000 calc(100% - var(--pp-fade-r)), transparent 100%);
            mask-image: linear-gradient(to right, transparent 0, #000 var(--pp-fade-l), #000 calc(100% - var(--pp-fade-r)), transparent 100%);
            animation: pp-tab-fade linear both;
            animation-timeline: scroll(self inline);
        }
    }

    .corex-props-v2 .prop-tabbar [data-prop-tab] {
        position: relative;
        /* The old hard 2px border is replaced by the animated bar below (same place, same thickness). */
        border-bottom-color: transparent !important;
        transition: color 150ms ease, background-color 200ms ease;
    }
    .corex-props-v2 .prop-tabbar [data-prop-tab]::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: -2px;
        height: 2px;
        border-radius: 2px 2px 0 0;
        background: var(--brand-icon, var(--pp-accent));
        opacity: 0;
        transform: scaleX(0);
        transform-origin: center;
        transition: transform 260ms var(--pp-spring), opacity 180ms ease;
        pointer-events: none;
    }
    .corex-props-v2 .prop-tabbar [data-prop-tab]:hover:not(.is-active) {
        color: var(--text-primary) !important;
        background: color-mix(in srgb, var(--brand-icon, var(--pp-accent)) 7%, transparent) !important;
    }
    .corex-props-v2 .prop-tabbar [data-prop-tab]:hover:not(.is-active)::after {
        opacity: 0.55;
        transform: scaleX(0.5);
    }
    .corex-props-v2 .prop-tabbar [data-prop-tab].is-active::after {
        opacity: 1;
        transform: scaleX(1);
    }
    .corex-props-v2 .prop-tabbar [data-prop-tab]:focus-visible {
        outline: 0;
        box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--pp-accent) 45%, transparent);
    }

    /* ── 4. Info tab: fields and accordion headers ──────────────────────────────────────────── */
    .corex-props-v2 :is(.prop-input, .prop-select, .prop-textarea) {
        transition: border-color 200ms ease, background-color 200ms ease, box-shadow 200ms ease;
    }
    /* Same hover as .list-header-filter: the edge tints toward the agency colour. */
    .corex-props-v2 :is(.prop-input, .prop-select, .prop-textarea):hover:not(:disabled):not(:focus) {
        border-color: color-mix(in srgb, var(--pp-accent) 55%, var(--border));
    }
    .corex-props-v2 :is(.prop-input, .prop-select, .prop-textarea):focus {
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--pp-accent) 18%, transparent);
    }
    .corex-props-v2 .prop-select option { background: var(--surface); color: var(--text-primary); }

    .corex-props-v2 .prop-section-toggle {
        position: relative;
        transition: background-color 150ms ease;
    }
    .corex-props-v2 .prop-section-toggle::before {
        content: "";
        position: absolute;
        left: 0;
        top: 20%;
        bottom: 20%;
        width: 3px;
        border-radius: 3px;
        background: color-mix(in srgb, var(--pp-accent) 60%, transparent);
        transform: scaleY(0);
        transition: transform 220ms ease;
        pointer-events: none;
    }
    .corex-props-v2 .prop-section-toggle:hover {
        background: color-mix(in srgb, var(--pp-accent) 8%, var(--surface-2));
    }
    .corex-props-v2 .prop-section-toggle:is(:hover, :focus-visible)::before { transform: scaleY(1); }
    .corex-props-v2 .prop-section-toggle .prop-section-chevron {
        transition: transform 220ms var(--pp-spring), color 150ms ease;
    }
    .corex-props-v2 .prop-section-toggle:hover .prop-section-chevron { color: var(--pp-accent); }
    .corex-props-v2 .prop-section-toggle:focus-visible {
        outline: 0;
        box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--pp-accent) 45%, transparent);
    }

    /* ── 5. Cards: only the ones you can click lift; static cards stay static ───────────────── */
    .corex-props-v2 .prop-card-link {
        transition: transform 220ms var(--pp-spring), border-color 200ms ease, box-shadow 200ms ease, background-color 150ms ease;
    }
    .corex-props-v2 .prop-card-link:is(:hover, :focus-visible) {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--pp-accent) 55%, var(--border)) !important;
        box-shadow: 0 12px 22px -16px color-mix(in srgb, var(--pp-accent) 55%, transparent);
    }
    .corex-props-v2 .prop-card-link:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--pp-accent) 65%, transparent);
        outline-offset: 2px;
    }
    /* Readiness: its header is the click target, so the card answers when the header is hovered. */
    .corex-props-v2 .prop-readiness-card { transition: border-color 200ms ease, box-shadow 200ms ease; }
    .corex-props-v2 .prop-readiness-card:has(> button:hover) {
        border-color: color-mix(in srgb, var(--pp-accent) 45%, var(--border)) !important;
        box-shadow: 0 10px 20px -16px color-mix(in srgb, var(--pp-accent) 55%, transparent);
    }
    .corex-props-v2 .prop-readiness-card > button svg { transition: color 150ms ease; }
    .corex-props-v2 .prop-readiness-card > button:hover svg { color: var(--pp-accent) !important; }
    .corex-props-v2 .prop-readiness-card > button:focus-visible {
        outline: 2px solid color-mix(in srgb, var(--pp-accent) 65%, transparent);
        outline-offset: 3px;
        border-radius: 4px;
    }
    .corex-props-v2 .prop-text-link { display: inline-block; transition: transform 200ms var(--pp-spring), opacity 150ms ease; }
    .corex-props-v2 .prop-text-link:hover { transform: translateX(3px); }

    /* ── 6. Rows built from divs inside the tabs (History, Contacts, Notes, Recent Activity, ...).
          Real tables already get the global row hover; these get the same tint. A background tint
          only: rows that carry their own coloured edge (e.g. a Purchaser) keep it. ─────────────── */
    .corex-props-v2 .prop-row-hover { transition: background-color 200ms ease, border-color 200ms ease; }
    .corex-props-v2 .prop-row-hover:hover {
        background-color: color-mix(in srgb, var(--pp-accent) 6%, var(--surface-2)) !important;
    }
    .corex-props-v2 .prop-row-hover:not([data-purchaser]):hover[style*="border:1px solid"] {
        border-color: color-mix(in srgb, var(--pp-accent) 35%, var(--border)) !important;
    }

    /* ── Respect reduced motion: no movement, instant state changes ─────────────────────────── */
    @media (prefers-reduced-motion: reduce) {
        .corex-props-v2 .prop-action-btn,
        .corex-props-v2 .prop-action-btn::before,
        .corex-props-v2 .prop-action-btn svg,
        .corex-props-v2 .prop-cov-dot-btn,
        .corex-props-v2 .prop-identity-strip .corex-btn-outline,
        .corex-props-v2 .prop-identity-strip .corex-tour-launcher-btn,
        .corex-props-v2 .prop-tabbar [data-prop-tab]::after,
        .corex-props-v2 .prop-section-toggle::before,
        .corex-props-v2 .prop-section-toggle .prop-section-chevron,
        .corex-props-v2 .prop-card-link,
        .corex-props-v2 .prop-row-hover,
        .corex-props-v2 .prop-text-link { transition: none; }
        .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible, :active),
        .corex-props-v2 .prop-action-btn:is(:hover, :focus-visible) svg,
        .corex-props-v2 .prop-identity-strip .corex-btn-outline:hover,
        .corex-props-v2 .prop-identity-strip .corex-tour-launcher-btn:hover,
        .corex-props-v2 .prop-card-link:is(:hover, :focus-visible),
        .corex-props-v2 .prop-cov-dot-btn:hover,
        .corex-props-v2 .prop-text-link:hover { transform: none; }
        .corex-props-v2 .prop-tabbar { scroll-behavior: auto; }
    }
</style>
