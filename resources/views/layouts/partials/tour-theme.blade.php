{{-- ════════════════════════════════════════════════════════════════════════
     COREX TOUR THEME — the driver.js stylesheet + the CoreX look of a tour popover / highlight (.driver-popover.corex-tour ...).

     Lived inline in tour-engine.blade.php; moved here, unchanged, so CoreX Desk (support's highlight on the agent's screen) can draw
     with exactly the Guided Tour's styling on EVERY page, not only pages that have a tour. tour-engine includes it where the block
     used to be; @once means a page that includes both still gets it once.
     ════════════════════════════════════════════════════════════════════════ --}}
@once
        <link rel="stylesheet" href="{{ asset('vendor/driverjs/driver.css') }}">
        <style>
            /* Theme driver.js to CoreX (brand-aware, dark-friendly). */
            .driver-popover.corex-tour {
                background: var(--surface, #fff);
                color: var(--text-primary, #111827);
                border: 1px solid var(--border, rgba(0,0,0,0.08));
                border-radius: 12px;
                box-shadow: 0 18px 50px rgba(0,0,0,0.35);
                min-width: 380px;
                max-width: 440px;
                padding: 18px 20px;
            }
            @media (max-width: 480px) {
                .driver-popover.corex-tour { min-width: 0; max-width: calc(100vw - 24px); }
            }
            .driver-popover.corex-tour .driver-popover-title {
                font-size: 1rem; font-weight: 700;
                color: var(--text-primary, #111827);
            }
            .driver-popover.corex-tour .driver-popover-description {
                font-size: 0.85rem; line-height: 1.5;
                color: var(--text-secondary, #4b5563);
            }
            /* ── Two-row footer ─────────────────────────────────────────────
               driver.js stuffs progress, prev/next AND our injected controls
               ("Don't show again", "Close tour") into one flex row, which
               overlaps at narrow widths. A 2-col / 2-row grid gives a clean,
               deterministic layout that never overlaps:
                 Row 1 (controls): [Don't show again]            [Close tour]
                 Row 2 (nav):      [progress]               [← Back] [Next →]
               "Don't show again" only exists on step 1; on later steps row 1
               holds just the top-right Close tour, which is fine. */
            .driver-popover.corex-tour .driver-popover-footer {
                display: grid;
                grid-template-columns: 1fr auto;
                align-items: center;
                gap: 12px 12px;
                margin-top: 16px;
                padding-top: 14px;
                border-top: 1px solid var(--border, rgba(0,0,0,0.08));
            }
            .driver-popover.corex-tour .corex-tour-dsa            { grid-column: 1; grid-row: 1; }
            .driver-popover.corex-tour .corex-tour-close          { grid-column: 2; grid-row: 1; justify-self: end; }
            .driver-popover.corex-tour .driver-popover-progress-text   { grid-column: 1; grid-row: 2; justify-self: start; }
            .driver-popover.corex-tour .driver-popover-navigation-btns { grid-column: 2; grid-row: 2; justify-self: end; }

            .driver-popover.corex-tour .driver-popover-progress-text {
                display: block !important;
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
            }
            /* Uniform footer buttons — same height/radius/font for nav + close. */
            .driver-popover.corex-tour .driver-popover-navigation-btns button,
            .driver-popover.corex-tour .corex-tour-close {
                box-sizing: border-box;
                height: 28px; padding: 0 12px;
                display: inline-flex; align-items: center; justify-content: center;
                font-size: 0.72rem; font-weight: 600; line-height: 1;
                border-radius: 6px; cursor: pointer;
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button {
                background: var(--brand-button, #0ea5e9);
                color: #fff; text-shadow: none; border: 0;
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button.driver-popover-prev-btn {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
            }
            .driver-popover.corex-tour .driver-popover-navigation-btns button + button { margin-left: 6px; }
            .driver-popover.corex-tour .driver-popover-navigation-btns button.corex-guide-skip {
                background: transparent;
                color: var(--text-muted, #6b7280);
                border: 1px solid var(--border, #e5e7eb);
            }
            .driver-popover.corex-tour .driver-popover-close-btn {
                color: var(--text-muted, #9ca3af);
            }
            .driver-popover.corex-tour .corex-tour-dsa {
                display: inline-flex; align-items: center; gap: 6px;
                font-size: 0.7rem; color: var(--text-muted, #9ca3af);
                cursor: pointer; user-select: none;
            }
            .driver-popover.corex-tour .corex-tour-dsa input { accent-color: var(--brand-button, #0ea5e9); }
            /* AT-41: explicit "Close tour" control (overlay/X/ESC close disabled).
               Styled identical to the Back button (surface-2 chip); resets
               driver.js's default text-shadow:1px 1px 0 #fff, which otherwise
               paints a white halo on the text in dark mode. Height/padding/radius/
               font come from the shared button rule above. */
            .driver-popover.corex-tour .corex-tour-close,
            .driver-popover.corex-tour .corex-tour-close:hover {
                background: var(--surface-2, #f1f5f9);
                color: var(--text-secondary, #475569);
                border: 0;
                text-shadow: none;
                text-decoration: none;
            }

            /* ── Advanced Guide / Spot Help ─────────────────────────────────
               The agent must be able to TYPE and CLICK on the real page while a
               step is highlighted — including dropdowns/pickers that render
               outside the highlighted box. driver.js blocks pointer events on
               everything but the active element; while a hands-on guide runs we
               lift that block and use a light veil + a brand ring instead. */
            body.corex-guide-live.driver-active *,
            body.corex-guide-live.driver-active .driver-overlay,
            body.corex-guide-live.driver-active .driver-overlay path { pointer-events: auto; }
            body.corex-guide-live.driver-active .driver-overlay,
            body.corex-guide-live.driver-active .driver-overlay path { pointer-events: none !important; }
            body.corex-guide-live .driver-active-element {
                outline: 3px solid var(--brand-button, #0ea5e9) !important;
                outline-offset: 3px;
                border-radius: 6px;
            }
            .driver-popover.corex-tour .corex-guide-do {
                display: flex; align-items: flex-start; gap: 8px;
                margin-top: 10px; padding: 9px 11px; border-radius: 8px;
                font-weight: 600; font-size: 0.82rem; line-height: 1.4;
                color: var(--text-primary, #111827);
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 12%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 35%, transparent);
            }
            .driver-popover.corex-tour .corex-guide-do::before {
                content: '→'; color: var(--brand-button, #0ea5e9); font-weight: 800;
            }
            .driver-popover.corex-tour .corex-guide-do.is-done {
                color: #047857;
                background: color-mix(in srgb, #10b981 14%, transparent);
                border-color: color-mix(in srgb, #10b981 40%, transparent);
            }
            .driver-popover.corex-tour .corex-guide-do.is-done::before { content: '✓'; color: #10b981; }
            html.dark .driver-popover.corex-tour .corex-guide-do.is-done { color: #6ee7b7; }
            .driver-popover.corex-tour .corex-guide-hint {
                margin-top: 8px; font-size: 0.75rem; line-height: 1.4;
                color: #b45309;
            }
            html.dark .driver-popover.corex-tour .corex-guide-hint { color: #fbbf24; }
            .driver-popover.corex-tour .corex-guide-section {
                display: inline-block; margin-bottom: 6px;
                font-size: 0.66rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
                color: var(--brand-button, #0ea5e9);
            }

            /* ── Header launcher button ─────────────────────────────────────
               The "?" lives ONLY in a page header's action group, dropped into
               #tour-launcher-slot by the engine. It never floats: it stays
               hidden until it is relocated into a slot, and sizes to match the
               other header icon/action buttons.

               Two header backdrops exist, so the slot carries a variant class
               and the button adapts:
                 • navy banner  (background:var(--brand-default)) → white-on-navy,
                   matching the translucent-white action buttons in those headers.
                 • surface bar  (x-page-header / x-list-header, var(--surface)) →
                   a muted outline icon button, matching the surface header chrome. */
            .corex-tour-launcher-btn {
                display: inline-flex; align-items: center; justify-content: center;
                width: 2rem; height: 2rem; padding: 0;
                border-radius: 6px; cursor: pointer;
                transition: background 0.3s ease, border-color 0.3s ease, color 0.3s ease;
            }
            .corex-tour-launcher-btn svg { width: 1.1rem; height: 1.1rem; }

            /* Navy banner (default) */
            .corex-tour-launcher-btn,
            .tour-slot-navy .corex-tour-launcher-btn {
                background: rgba(255,255,255,0.08); color: #fff;
                border: 1px solid rgba(255,255,255,0.18);
            }
            .corex-tour-launcher-btn:hover,
            .tour-slot-navy .corex-tour-launcher-btn:hover {
                background: rgba(255,255,255,0.18);
                border-color: rgba(255,255,255,0.30);
            }
            /* Surface bar */
            .tour-slot-surface .corex-tour-launcher-btn {
                background: transparent;
                color: var(--text-muted, #6b7280);
                border: 1px solid var(--border, #e5e7eb);
            }
            .tour-slot-surface .corex-tour-launcher-btn:hover {
                color: var(--brand-button, #0ea5e9);
                border-color: var(--brand-button, #0ea5e9);
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
            }

            /* ── "?" menu ───────────────────────────────────────────────────
               Fixed-positioned from the button's rect so no header's overflow
               can clip it. */
            .corex-tour-menu {
                position: fixed; z-index: 10050;
                width: 280px; max-width: calc(100vw - 24px);
                max-height: calc(100vh - 80px); overflow-y: auto;
                padding: 6px; border-radius: 10px;
                background: var(--surface, #fff);
                border: 1px solid var(--border, #e5e7eb);
                box-shadow: 0 16px 40px rgba(0,0,0,0.28);
                text-align: left;
            }
            .corex-tour-menu-item {
                display: block; width: 100%; padding: 8px 10px; border-radius: 7px;
                border: 0; background: transparent; cursor: pointer; text-align: left;
                transition: background 0.2s ease;
            }
            .corex-tour-menu-item:hover,
            .corex-tour-menu-item:focus-visible {
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
                outline: none;
            }
            .corex-tour-menu-item strong {
                display: block; font-size: 0.8rem; font-weight: 700; color: var(--text-primary, #111827);
            }
            .corex-tour-menu-item span {
                display: block; font-size: 0.7rem; line-height: 1.35; color: var(--text-muted, #6b7280);
            }
            .corex-tour-menu-head {
                padding: 8px 10px 4px; margin-top: 4px;
                border-top: 1px solid var(--border, #e5e7eb);
                font-size: 0.66rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase;
                color: var(--text-muted, #6b7280);
            }
            .corex-tour-menu-section {
                display: block; width: 100%; padding: 6px 10px 6px 18px; border-radius: 6px;
                border: 0; background: transparent; cursor: pointer; text-align: left;
                font-size: 0.78rem; color: var(--text-secondary, #4b5563);
            }
            .corex-tour-menu-section:hover,
            .corex-tour-menu-section:focus-visible {
                background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent);
                color: var(--text-primary, #111827); outline: none;
            }
        </style>
@endonce
