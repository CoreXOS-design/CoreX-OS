/**
 * CoreX — Ad render kernel.
 *
 * THE single source of truth for how a custom ad template's `layout_json`
 * becomes pixels. Three surfaces consume it:
 *
 *   1. Ad Builder            resources/views/corex/properties/ad-builder.blade.php
 *   2. Single-property ad    resources/views/corex/properties/ad.blade.php
 *   3. Bulk Ad Manager       resources/views/tools/ad-manager.blade.php
 *
 * Before this file each surface carried its OWN copy of the geometry, the style
 * computation and the value resolution — and they drifted: the bulk manager did
 * not know about shapeType/clip-paths, custom media, the features chooser or the
 * agent-2 empty-slot rule, so those elements rendered wrong on a real ad. A new
 * element property now lands in all three at once because there is only one
 * place to put it.
 *
 * Spec: .ai/specs/ad-manager.md §12.
 *
 * Not bundled by Vite on purpose — the ad pages are standalone Blade documents
 * that do not load the app bundle (same reason public/js/corex-session-guard.js
 * and public/js/docuperfect-editor.js live here).
 */
(function (root) {
    'use strict';

    /* ── Canvas presets ─────────────────────────────────────────────────── */
    var CANVAS_PRESETS = {
        facebook:  { w: 1200, h: 628,  label: '1200×628 (Facebook)'  },
        instagram: { w: 1080, h: 1080, label: '1080×1080 (Instagram)' },
        story:     { w: 1080, h: 1920, label: '1080×1920 (Story)'     },
        whatsapp:  { w: 900,  h: 900,  label: '900×900 (WhatsApp)'    },
        linkedin:  { w: 1200, h: 627,  label: '1200×627 (LinkedIn)'   },
        pinterest: { w: 1000, h: 1500, label: '1000×1500 (Pinterest)' }
    };

    /* ── Shape geometry ─────────────────────────────────────────────────── */
    var SHAPE_CLIPS = {
        triangle: 'polygon(50% 0,100% 100%,0 100%)',
        diamond:  'polygon(50% 0,100% 50%,50% 100%,0 50%)',
        pentagon: 'polygon(50% 0,100% 38%,82% 100%,18% 100%,0 38%)',
        hexagon:  'polygon(25% 0,75% 0,100% 50%,75% 100%,25% 100%,0 50%)',
        star:     'polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)',
        chevron:  'polygon(0 0,75% 0,100% 50%,75% 100%,0 100%,25% 50%)'
    };

    var SHAPES = [
        { type: 'rectangle', label: 'Rectangle' },
        { type: 'rounded',   label: 'Rounded'   },
        { type: 'circle',    label: 'Circle'    },
        { type: 'pill',      label: 'Pill'      },
        { type: 'triangle',  label: 'Triangle'  },
        { type: 'diamond',   label: 'Diamond'   },
        { type: 'pentagon',  label: 'Pentagon'  },
        { type: 'hexagon',   label: 'Hexagon'   },
        { type: 'star',      label: 'Star'      },
        { type: 'chevron',   label: 'Chevron'   }
    ];

    /**
     * Font catalogue. Every family here MUST be in the stylesheet emitted by
     * resources/views/corex/properties/_ad-fonts.blade.php, which every ad
     * surface includes — a font the builder can pick but the generator has not
     * loaded would silently fall back to Figtree in the downloaded PNG.
     */
    var FONTS = [
        { name: 'Figtree',         stack: "'Figtree',Arial,sans-serif" },
        { name: 'Inter',           stack: "'Inter',Arial,sans-serif" },
        { name: 'Poppins',         stack: "'Poppins',Arial,sans-serif" },
        { name: 'Montserrat',      stack: "'Montserrat',Arial,sans-serif" },
        { name: 'Oswald',          stack: "'Oswald','Arial Narrow',sans-serif" },
        { name: 'Bebas Neue',      stack: "'Bebas Neue',Impact,sans-serif" },
        { name: 'Playfair Display', stack: "'Playfair Display',Georgia,serif" },
        { name: 'Lora',            stack: "'Lora',Georgia,serif" }
    ];

    var IMAGE_FIELDS = [
        'image_1', 'image_2', 'image_3', 'image_4', 'image_5',
        'agent_avatar', 'agent_2_avatar', 'agency_logo'
    ];

    var NON_TEXT_FIELDS = IMAGE_FIELDS.concat([
        'logo', 'watermark', 'color_block', 'gradient', 'line', 'shape',
        'custom_image', 'custom_video'
    ]);

    /* ── Per-field defaults (the shape a NEW element starts in) ──────────── */
    var FIELD_DEFAULTS = {
        image_1:          { w: 600, h: 314, objectFit: 'cover', borderRadius: 0 },
        image_2:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
        image_3:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
        image_4:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
        image_5:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
        price:            { w: 400, h: 70,  fontSize: 42, fontWeight: '800', color: '#e63946', textTransform: 'none', textAlign: 'left', letterSpacing: -0.02, padding: 8 },
        title:            { w: 500, h: 60,  fontSize: 22, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.04, padding: 8 },
        suburb:           { w: 400, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 8 },
        property_type:    { w: 200, h: 30,  fontSize: 12, fontWeight: '600', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6 },
        features:         { w: 320, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 8, preview: '4 Bed · 3 Bath · 2 Garage' },
        beds:             { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '4' },
        baths:            { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '3' },
        garages:          { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '2' },
        parking:          { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '2' },
        garages_or_parking: { w: 80, h: 36, fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '2' },
        size_m2:          { w: 120, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '450 m²' },
        size_or_land:     { w: 150, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '450 M²' },
        reference:        { w: 160, h: 28,  fontSize: 12, fontWeight: '600', color: 'rgba(255,255,255,0.55)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.08, padding: 4, preview: 'REF 12345' },
        address:          { w: 360, h: 32,  fontSize: 13, fontWeight: '500', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '12 Marine Drive' },
        status_badge:     { w: 200, h: 40,  fontSize: 16, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 8, bgColor: '#e63946', bgOpacity: 1, borderRadius: 6, preview: 'FOR SALE' },
        agent_name:       { w: 280, h: 40,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6 },
        agent_email:      { w: 300, h: 30,  fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6 },
        agent_phone:      { w: 220, h: 30,  fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
        agent_designation:{ w: 260, h: 28,  fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6 },
        agent_avatar:     { w: 80,  h: 80,  objectFit: 'cover', borderRadius: 16, shapeType: 'circle' },
        // Agent 2 — the co-listing agent, for building dual-agent templates.
        agent_2_name:        { w: 280, h: 40, fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6, preview: 'CO-AGENT NAME' },
        agent_2_email:       { w: 300, h: 30, fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'co.agent@agency.co.za' },
        agent_2_phone:       { w: 220, h: 30, fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
        agent_2_designation: { w: 260, h: 28, fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6, preview: 'PROPERTY PRACTITIONER' },
        agent_2_avatar:      { w: 80,  h: 80, objectFit: 'cover', borderRadius: 16, shapeType: 'circle' },
        agency_name:      { w: 280, h: 32,  fontSize: 15, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6 },
        website:          { w: 260, h: 26,  fontSize: 11, fontWeight: '700', color: 'rgba(255,255,255,0.4)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.12, padding: 4, preview: 'WWW.AGENCY.CO.ZA' },
        logo:             { w: 180, h: 56,  fontSize: 28, color: '#ffffff', padding: 0 },
        agency_logo:      { w: 200, h: 70,  objectFit: 'contain', borderRadius: 0 },
        custom_text:      { w: 300, h: 50,  fontSize: 20, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 8, text: 'Your text' },
        badge:            { w: 180, h: 44,  fontSize: 16, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 8, bgColor: '#00b4d8', bgOpacity: 1, borderRadius: 22, text: 'JUST LISTED' },
        line:             { w: 300, h: 12,  color: '#00b4d8', borderWidth: 3 },
        shape:            { w: 160, h: 160, bg: '#00b4d8', opacity: 1, shapeType: 'rounded', borderRadius: 24 },
        color_block:      { w: 400, h: 100, bg: '#07111e', opacity: 1, borderRadius: 0 },
        gradient:         { w: 600, h: 300, gradFrom: '#071325', gradTo: 'rgba(7,19,37,0)', gradAngle: 0, opacity: 1 },
        custom_image:     { w: 400, h: 300, objectFit: 'cover', borderRadius: 0, src: '' },
        custom_video:     { w: 480, h: 270, objectFit: 'cover', borderRadius: 0, src: '' },
        watermark:        { w: 600, h: 120, fontSize: 60, color: '#ffffff', opacity: 0.06, text: '' }
    };

    /* ── The draggable field catalogue (left rail of the builder) ────────── */
    var FIELD_GROUPS = [
        { key: 'image',      label: 'Images'     },
        { key: 'property',   label: 'Property'   },
        { key: 'agent',      label: 'Agent'      },
        { key: 'branding',   label: 'Branding'   },
        { key: 'decorative', label: 'Decorative' }
    ];

    var FIELDS = [
        { type: 'image_1', group: 'image', label: 'Image 1', iconBg: '#1d4ed8' },
        { type: 'image_2', group: 'image', label: 'Image 2', iconBg: '#1d4ed8' },
        { type: 'image_3', group: 'image', label: 'Image 3', iconBg: '#1d4ed8' },
        { type: 'image_4', group: 'image', label: 'Image 4', iconBg: '#1d4ed8' },
        { type: 'image_5', group: 'image', label: 'Image 5', iconBg: '#1d4ed8' },
        { type: 'custom_image', group: 'image', label: 'Custom Image', iconBg: '#2563eb' },
        { type: 'custom_video', group: 'image', label: 'Custom Video', iconBg: '#2563eb' },
        { type: 'price',         group: 'property', label: 'Price',        iconBg: '#e63946' },
        { type: 'title',         group: 'property', label: 'Title',        iconBg: '#6d28d9' },
        { type: 'suburb',        group: 'property', label: 'Suburb',       iconBg: '#047857' },
        { type: 'property_type', group: 'property', label: 'Type',         iconBg: '#0369a1' },
        { type: 'features',      group: 'property', label: 'Features',     iconBg: '#b45309' },
        { type: 'beds',          group: 'property', label: 'Beds',         iconBg: '#0369a1' },
        { type: 'baths',         group: 'property', label: 'Baths',        iconBg: '#0369a1' },
        { type: 'garages',       group: 'property', label: 'Garages',      iconBg: '#0369a1' },
        { type: 'parking',       group: 'property', label: 'Parking',      iconBg: '#0369a1' },
        { type: 'garages_or_parking', group: 'property', label: 'Garages / Parking', iconBg: '#0369a1' },
        { type: 'size_m2',       group: 'property', label: 'Size m²',      iconBg: '#065f46' },
        { type: 'size_or_land',  group: 'property', label: 'Size / Land Size', iconBg: '#065f46' },
        { type: 'reference',     group: 'property', label: 'Reference',    iconBg: '#475569' },
        { type: 'address',       group: 'property', label: 'Address',      iconBg: '#475569' },
        { type: 'status_badge',  group: 'property', label: 'Status Badge', iconBg: '#e63946' },
        { type: 'agent_name',        group: 'agent', label: 'Agent 1 · Name',        iconBg: '#7c3aed' },
        { type: 'agent_email',       group: 'agent', label: 'Agent 1 · Email',       iconBg: '#7c3aed' },
        { type: 'agent_phone',       group: 'agent', label: 'Agent 1 · Phone',       iconBg: '#7c3aed' },
        { type: 'agent_designation', group: 'agent', label: 'Agent 1 · Designation', iconBg: '#7c3aed' },
        { type: 'agent_avatar',      group: 'agent', label: 'Agent 1 · Image',      iconBg: '#7c3aed' },
        { type: 'agent_2_name',        group: 'agent', label: 'Agent 2 · Name',        iconBg: '#9333ea' },
        { type: 'agent_2_email',       group: 'agent', label: 'Agent 2 · Email',       iconBg: '#9333ea' },
        { type: 'agent_2_phone',       group: 'agent', label: 'Agent 2 · Phone',       iconBg: '#9333ea' },
        { type: 'agent_2_designation', group: 'agent', label: 'Agent 2 · Designation', iconBg: '#9333ea' },
        { type: 'agent_2_avatar',      group: 'agent', label: 'Agent 2 · Image',      iconBg: '#9333ea' },
        { type: 'logo',        group: 'branding', label: 'CoreX / Agency Logo', iconBg: '#00b4d8' },
        { type: 'agency_logo', group: 'branding', label: 'Agency Logo (image)', iconBg: '#00b4d8' },
        { type: 'agency_name', group: 'branding', label: 'Agency Name',         iconBg: '#0b2a4a' },
        { type: 'website',     group: 'branding', label: 'Website',             iconBg: '#0b2a4a' },
        { type: 'watermark',   group: 'branding', label: 'Watermark',           iconBg: '#334155' },
        { type: 'custom_text', group: 'decorative', label: 'Custom Text',  iconBg: '#6d28d9' },
        { type: 'badge',       group: 'decorative', label: 'Badge / Pill', iconBg: '#00b4d8' },
        { type: 'line',        group: 'decorative', label: 'Divider Line', iconBg: '#334155' },
        { type: 'shape',       group: 'decorative', label: 'Shape',        iconBg: '#334155' },
        { type: 'gradient',    group: 'decorative', label: 'Gradient',     iconBg: '#334155' }
    ];

    /**
     * Numeric feature fields — Beds/Baths/Garages/Parking. Each element of these
     * types can display as a bare number (legacy/default) or as "Number + Label"
     * with correct singular/plural (e.g. "1 Bedroom" vs "3 Bedrooms"), and can
     * carry an icon from ICONS. `el.numberFormat` ('number'|'label'), `el.icon`
     * (a key into ICONS, or null/undefined) and `el.iconSize` (px) are the new
     * per-element properties this powers. Spec: .ai/specs/ad-manager.md §14.
     */
    var NUMERIC_FEATURE_FIELDS = ['beds', 'baths', 'garages', 'parking', 'garages_or_parking'];

    // "Parking" does not pluralise in CoreX copy — matches the printable brochure.
    var FEATURE_LABELS = {
        beds:    { singular: 'Bedroom',  plural: 'Bedrooms' },
        baths:   { singular: 'Bathroom', plural: 'Bathrooms' },
        garages: { singular: 'Garage',   plural: 'Garages' },
        parking: { singular: 'Parking',  plural: 'Parking' }
    };

    /**
     * Curated real-estate icon set. Single-path/simple-shape, viewBox 0 0 24 24,
     * `fill="currentColor"` (except where a hole needs fill-rule) so every icon
     * always follows the element's own text colour — no separate icon-colour
     * control needed. width/height are set to 100% so the icon fills whatever
     * box the caller sizes it to (see iconHtml()).
     */
    var ICONS = {
        bed:     '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M2 10h9V6H4a2 2 0 0 0-2 2v2zm11 0h9V8a2 2 0 0 0-2-2h-7v4zM2 12v6h2v-2h16v2h2v-6H2z"/></svg>',
        bath:    '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M4 5a2 2 0 0 1 4 0v1H6.8A1.8 1.8 0 0 0 5 7.8V11H3a1 1 0 0 0-1 1 5 5 0 0 0 3 4.6V19h2v-1.2h10V19h2v-2.4A5 5 0 0 0 22 12a1 1 0 0 0-1-1H7V7.8c0-.1.1-.2.2-.2H10V6H6V5z"/></svg>',
        garage:  '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M5 11l1.6-4.2A2 2 0 0 1 8.5 5.5h7a2 2 0 0 1 1.9 1.3L19 11v6h-2.5v-2h-9v2H5v-6zm2.2-.5h9.6l-1-2.6a.6.6 0 0 0-.6-.4H8.8a.6.6 0 0 0-.6.4l-1 2.6zM7 12.5a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4zm10 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4z"/></svg>',
        parking: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path fill-rule="evenodd" d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zm5 4v10h2.3v-3.1h2.1a3.45 3.45 0 0 0 0-6.9H9zm2.3 2h1.9a1.45 1.45 0 0 1 0 2.9h-1.9V9z"/></svg>',
        ruler:   '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M3 3h8v2H5v6H3V3zm18 18h-8v-2h6v-6h2v8z"/></svg>',
        house:   '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 2 2 11h3v10h6v-6h2v6h6V11h3L12 2z"/></svg>',
        pin:     '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>',
        key:     '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M14.5 2a5.5 5.5 0 1 0 3.9 9.4L20 13l-1.5 1.5L20 16l-2 2-1.5-1.5L15 18l-2-2 1.4-1.4A5.5 5.5 0 0 0 14.5 2zm0 2.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3z"/></svg>',
        pool:    '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M2 15c1.5-1.5 3-1.5 4.5 0s3 1.5 4.5 0 3-1.5 4.5 0 3 1.5 4.5 0v3c-1.5 1.5-3 1.5-4.5 0s-3-1.5-4.5 0-3 1.5-4.5 0-3-1.5-4.5 0v-3z"/></svg>',
        tree:    '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12 2 8 9h2l-3 6h4v5h2v-5h4l-3-6h2L12 2z"/></svg>',
        door:    '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M6 22V8a6 6 0 0 1 12 0v14h-2V8a4 4 0 0 0-8 0v14H6z"/></svg>',
        tag:     '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M21.4 11.6 12.4 2.6A2 2 0 0 0 11 2H4a2 2 0 0 0-2 2v7c0 .5.2 1 .6 1.4l9 9c.8.8 2 .8 2.8 0l7-7a2 2 0 0 0 0-2.8zM7 8.5A1.5 1.5 0 1 1 7 5.5a1.5 1.5 0 0 1 0 3z"/></svg>'
    };

    /** Picker order + human labels — the "list of icons" the builder panel offers. */
    var ICON_LIST = [
        { key: 'bed',     label: 'Bed' },
        { key: 'bath',    label: 'Bath' },
        { key: 'garage',  label: 'Garage / Car' },
        { key: 'parking', label: 'Parking' },
        { key: 'ruler',   label: 'Size' },
        { key: 'house',   label: 'House' },
        { key: 'pin',     label: 'Location' },
        { key: 'key',     label: 'Key' },
        { key: 'pool',    label: 'Pool' },
        { key: 'tree',    label: 'Garden' },
        { key: 'door',    label: 'Door' },
        { key: 'tag',     label: 'Price Tag' }
    ];

    /* ── Primitives ─────────────────────────────────────────────────────── */

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function def(v, fallback) {
        return (v === null || v === undefined) ? fallback : v;
    }

    function hexToRgba(hex, a) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        if (!m) return hex || 'rgba(0,0,0,0)';
        return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')';
    }

    function isImageField(f) { return IMAGE_FIELDS.indexOf(f) !== -1; }
    function isTextField(f)  { return NON_TEXT_FIELDS.indexOf(f) === -1; }
    function isAgent2(f)     { return String(f).indexOf('agent_2') === 0; }
    function isAgentAvatarField(f) { return f === 'agent_avatar' || f === 'agent_2_avatar'; }

    /**
     * Shape mask for an Agent Image element — mirrors the decorative `shape`
     * element's own SHAPES/SHAPE_CLIPS list (reuses `el.shapeType`, same property
     * name, applied here to a PHOTO instead of a fill colour). Legacy elements
     * (no `shapeType` at all — every avatar saved before this feature) fall
     * through to the caller's existing `el.borderRadius` handling, so nothing
     * about an already-saved template changes.
     */
    function avatarShapeCss(shapeType, borderRadius) {
        if (SHAPE_CLIPS[shapeType]) return 'border-radius:0;clip-path:' + SHAPE_CLIPS[shapeType] + ';';
        if (shapeType === 'circle') return 'border-radius:50%;';
        if (shapeType === 'pill')   return 'border-radius:9999px;';
        if (shapeType === 'rounded') return 'border-radius:' + def(borderRadius, 16) + 'px;';
        return 'border-radius:0;'; // 'rectangle'
    }

    /** A clip-path cuts its own box-shadow away, so those shapes cannot carry one. */
    function isClipShape(el) { return el.field === 'shape' && !!SHAPE_CLIPS[el.shapeType]; }
    function canShadow(el)   { return !isClipShape(el); }

    function fontStack(name) {
        for (var i = 0; i < FONTS.length; i++) {
            if (FONTS[i].name === name) return FONTS[i].stack;
        }
        return FONTS[0].stack;
    }

    function shadowValue(el) {
        var c = hexToRgba(el.shadowColor || '#000000', def(el.shadowOpacity, 0.45));
        return def(el.shadowX, 0) + 'px ' + def(el.shadowY, 4) + 'px ' + def(el.shadowBlur, 12) + 'px ' + c;
    }

    function fit(el) { return el.objectFit || 'cover'; }

    /* ── Style computation ──────────────────────────────────────────────── */

    /**
     * The absolutely-positioned frame every element sits in. Shared verbatim by
     * the builder (as an Alpine :style binding) and both DOM renderers.
     */
    function frameStyle(el, opts) {
        opts = opts || {};
        var s = 'position:absolute;'
              + 'left:' + el.x + 'px;top:' + el.y + 'px;'
              + 'width:' + el.w + 'px;height:' + el.h + 'px;'
              + 'z-index:' + (el.zIndex || 1) + ';'
              + 'overflow:hidden;';

        if (isAgentAvatarField(el.field) && el.shapeType) {
            // An Agent Image with a chosen shape mask (circle/rounded/pill/hexagon/…).
            s += avatarShapeCss(el.shapeType, el.borderRadius);
        } else {
            // A shape owns its geometry on the INNER node — the frame must stay square
            // or it rounds a rectangle's corners and draws a box around a clip shape.
            var radius = el.field === 'shape' ? 0 : (el.borderRadius || 0);
            s += 'border-radius:' + radius + 'px;';
        }

        /**
         * §15.1 round 6 — a real template exposed a compositing gap, not an
         * algorithm bug: "Remove background" correctly makes the studio
         * backdrop transparent, but the FRAME behind an Agent Image has never
         * painted any fill of its own (unlike shapeCss(), which always applies
         * el.bg) — so a transparent cutout pixel reveals whatever DECORATIVE
         * SHAPE happens to sit behind it in the z-stack. A real template
         * positioned its Agent Image element partially outside its own white
         * card background (over a dark shape instead) — every transparent
         * pixel on that side showed dark, creating a hard vertical seam
         * exactly at the card's edge that read as a crop/mask boundary, not a
         * removal defect. Confirmed empirically: compositing the SAME
         * processed cutout onto a uniform matching colour shows no edge at
         * all, whatever the template's own decorative shapes are doing
         * underneath.
         *
         * `el.cutoutMatteColor` (unset by default — every existing template's
         * appearance is completely unchanged) lets the designer paint an
         * explicit, KNOWN fill behind the cutout — bounded to the frame's own
         * box by the existing `overflow:hidden` above, so it can never bleed
         * outside the element like the decorative shapes it replaces
         * reliance on. Scoped to removeBackground — a normal (non-cutout)
         * Agent Image already shows its full rectangular photo with no
         * transparent pixels, so a matte fill would never be visible there.
         */
        if (isAgentAvatarField(el.field) && el.removeBackground && el.cutoutMatteColor) {
            s += 'background-color:' + el.cutoutMatteColor + ';';
        }

        if (el.hidden && !opts.showHidden) s += 'display:none;';
        if (el.rotation) s += 'transform:rotate(' + el.rotation + 'deg);';
        if (el.frameBorderWidth) {
            s += 'border:' + el.frameBorderWidth + 'px solid ' + (el.frameBorderColor || '#ffffff') + ';';
        }

        var o = def(el.elOpacity, 1);
        if (o < 1) s += 'opacity:' + o + ';';

        // Where the shadow is painted depends on what carries the geometry:
        //   text  → text-shadow on the text node   (textStyle)
        //   shape → box-shadow on the shape node   (shapeCss)
        //   line  → box-shadow on the bar          (lineCss)
        //   else  → box-shadow on the frame        (here)
        if (el.shadowOn && !isTextField(el.field) && el.field !== 'shape' && el.field !== 'line') {
            s += 'box-shadow:' + shadowValue(el) + ';';
        }
        return s;
    }

    function shapeCss(el) {
        var t = el.shapeType;
        var s = 'width:100%;height:100%;'
              + 'background:' + (el.bg || '#00b4d8') + ';'
              + 'opacity:' + def(el.opacity, 1) + ';';

        if (!t) {
            // Legacy shapes (saved before the shape list) used borderRadius as a %.
            s += 'border-radius:' + def(el.borderRadius, 50) + '%;';
        } else if (SHAPE_CLIPS[t]) {
            s += 'clip-path:' + SHAPE_CLIPS[t] + ';border-radius:0;';
        } else if (t === 'circle') {
            s += 'border-radius:50%;';
        } else if (t === 'pill') {
            s += 'border-radius:9999px;';
        } else if (t === 'rounded') {
            s += 'border-radius:' + def(el.borderRadius, 24) + 'px;';
        } else {
            s += 'border-radius:0;';   // rectangle
        }

        if (el.shadowOn && !SHAPE_CLIPS[t]) s += 'box-shadow:' + shadowValue(el) + ';';
        return s;
    }

    function gradientCss(el) {
        return 'width:100%;height:100%;'
             + 'background:linear-gradient(' + def(el.gradAngle, 180) + 'deg,'
             + (el.gradFrom || '#071325') + ',' + (el.gradTo || 'rgba(7,19,37,0)') + ');'
             + 'opacity:' + def(el.opacity, 1) + ';';
    }

    function lineCss(el) {
        var s = 'width:100%;height:' + (el.borderWidth || 3) + 'px;'
              + 'background:' + (el.color || '#00b4d8') + ';border-radius:2px;';
        if (el.shadowOn) s += 'box-shadow:' + shadowValue(el) + ';';
        return s;
    }

    function colorBlockCss(el) {
        var s = 'width:100%;height:100%;'
              + 'background:' + (el.bg || '#07111e') + ';'
              + 'opacity:' + def(el.opacity, 1) + ';';
        if (el.borderRadius) s += 'border-radius:' + el.borderRadius + 'px;';
        return s;
    }

    function watermarkCss(el) {
        return 'width:100%;height:100%;display:flex;align-items:center;justify-content:center;'
             + 'font-family:' + fontStack(el.fontFamily) + ';'
             + 'font-weight:900;letter-spacing:0.06em;text-transform:uppercase;'
             + 'font-size:' + (el.fontSize || 60) + 'px;'
             + 'color:' + (el.color || '#ffffff') + ';'
             + 'opacity:' + def(el.opacity, 0.06) + ';';
    }

    function textStyle(el) {
        var va = el.verticalAlign || 'middle';
        var ai = va === 'top' ? 'flex-start' : (va === 'bottom' ? 'flex-end' : 'center');

        var s = 'width:100%;height:100%;display:flex;overflow:hidden;'
              + 'align-items:' + ai + ';'
              + 'font-family:' + fontStack(el.fontFamily) + ';'
              + 'font-size:' + (el.fontSize || 18) + 'px;'
              + 'font-weight:' + (el.fontWeight || '600') + ';'
              + 'color:' + (el.color || '#ffffff') + ';'
              + 'text-align:' + (el.textAlign || 'left') + ';'
              + 'text-transform:' + (el.textTransform || 'none') + ';'
              + 'letter-spacing:' + def(el.letterSpacing, 0) + 'em;'
              + 'line-height:' + def(el.lineHeight, 1.2) + ';'
              + 'padding:' + def(el.padding, 8) + 'px;';

        var op = def(el.bgOpacity, 0);
        if (op > 0) {
            s += 'background:' + hexToRgba(el.bgColor || '#000000', op) + ';'
               + 'border-radius:' + (el.borderRadius || 0) + 'px;';
        }
        if (el.textAlign === 'center')     s += 'justify-content:center;';
        else if (el.textAlign === 'right') s += 'justify-content:flex-end;';

        if (el.shadowOn) s += 'text-shadow:' + shadowValue(el) + ';';
        return s;
    }

    /**
     * "3" / "1" / "1.5" → a clean number string. Whole numbers drop the decimal
     * (Property::adData() sends baths as a decimal:1 string, e.g. "3.0"); a real
     * half (e.g. baths "1.5") is kept. Never throws on garbage input.
     */
    function formatFeatureNumber(num) {
        if (Math.abs(num - Math.round(num)) < 0.001) return String(Math.round(num));
        return String(Math.round(num * 10) / 10);
    }

    /** Singular/plural word for a numeric feature field at a given count. */
    function featureWord(field, num) {
        var meta = FEATURE_LABELS[field];
        if (!meta) return '';
        return (field !== 'parking' && num === 1) ? meta.singular : meta.plural;
    }

    /**
     * "garages_or_parking" — a single combined field for bulk ad runs, where one
     * template covers many properties and a fixed "Garages" field prints blank on
     * a listing that only has parking bays (and vice versa). Prefers garages;
     * falls back to parking ONLY when garages is absent/zero. Never both at once
     * — this is a single-value field, not a second "features" list.
     */
    function resolveGaragesOrParking(prop) {
        var g = parseFloat(prop.garages);
        if (!isNaN(g) && g > 0) return { field: 'garages', num: g };
        var p = parseFloat(prop.parking);
        if (!isNaN(p) && p > 0) return { field: 'parking', num: p };
        return null;
    }

    /**
     * "size_or_land" — same combined-field idea as garages_or_parking, for the
     * same reason: a fixed "Size m²" field prints blank (or, before the
     * placeholder-leak fix above, a FABRICATED "450 m²") on vacant land, which
     * has no floor size at all — only a stand/erf size. Prefers the floor size;
     * falls back to the land/erf size ONLY when the floor size is absent/zero.
     */
    function resolveSizeOrLand(prop) {
        var floor = parseFloat(prop.floor_size_m2);
        if (!isNaN(floor) && floor > 0) return { kind: 'floor', num: floor };
        var land = parseFloat(prop.land_size_m2);
        if (!isNaN(land) && land > 0) return { kind: 'land', num: land };
        return null;
    }

    /** "1250" → "1,250" — matches PHP's number_format() thousands separator
     *  already used for the plain Size m² field, so the two never disagree. */
    function formatSizeNumber(num) {
        return Math.round(num).toLocaleString('en-US');
    }

    /* ── Value resolution ───────────────────────────────────────────────── */

    /**
     * The text an element displays.
     *
     * opts.placeholders — the BUILDER designs against a property that may not have
     * every value, so it falls back to the field's preview copy. The GENERATOR must
     * not: an Agent-2 slot on a single-agent listing renders EMPTY, never the words
     * "Agent 2 · Name" printed onto a real ad.
     */
    function textValue(el, prop, opts) {
        prop = prop || {};
        opts = opts || {};
        var f = el.field;

        if (f === 'custom_text' || f === 'badge') return el.text || el.label || '';

        if (f === 'features') {
            var all = Array.isArray(prop.features_list) ? prop.features_list : [];
            var chosen = (el.selectedFeatures === null || el.selectedFeatures === undefined)
                ? all
                : all.filter(function (x) { return el.selectedFeatures.indexOf(x) !== -1; });
            if (chosen.length) return chosen.join('  ·  ');
            return prop.features || el.preview || el.label || '';
        }

        // Garages / Parking (combined) — resolves to whichever the property
        // actually has (garages preferred), in EITHER display mode, since the
        // value itself (not just its formatting) depends on the property.
        if (f === 'garages_or_parking') {
            var gp = resolveGaragesOrParking(prop);
            if (!gp) {
                if (!opts.placeholders) return '';
                // Builder preview with no real data: "defaults to garages" per spec.
                gp = { field: 'garages', num: parseFloat(el.preview) || 0 };
            }
            if (el.numberFormat === 'label') return formatFeatureNumber(gp.num) + ' ' + featureWord(gp.field, gp.num);
            return formatFeatureNumber(gp.num);
        }

        // Size / Land Size (combined) — floor size if the property has one,
        // else the erf/land size, else hidden. "Erf" suffix disambiguates when
        // what's shown is land, not floor, so a buyer never mistakes one for
        // the other.
        if (f === 'size_or_land') {
            var sl = resolveSizeOrLand(prop);
            if (!sl) return opts.placeholders ? (el.preview || '450 M²') : '';
            var sizeStr = formatSizeNumber(sl.num) + ' M²';
            return sl.kind === 'land' ? sizeStr + ' Erf' : sizeStr;
        }

        // Beds/Baths/Garages/Parking in "Number + Label" mode — e.g. "1 Bedroom" /
        // "3 Bedrooms". Default ('number' or unset — legacy elements have no
        // numberFormat at all) falls straight through to the raw-number path below.
        if (NUMERIC_FEATURE_FIELDS.indexOf(f) !== -1 && el.numberFormat === 'label') {
            var raw = prop[f];
            if (raw === undefined || raw === null || raw === '') {
                if (!opts.placeholders) return '';
                raw = el.preview || '0';
            }
            var num = parseFloat(raw);
            if (isNaN(num)) return String(raw);
            return formatFeatureNumber(num) + ' ' + featureWord(f, num);
        }

        var v = prop[f];
        if (v !== undefined && v !== null && v !== '') return v;

        // No real value. The BUILDER designs against a property that may lack
        // this field, so it falls back to the preview/label copy for a nicer
        // design-time look — the GENERATOR must never fabricate data onto a
        // REAL ad. Before this, ANY field with no data (a missing phone number,
        // reference, address, size…) rendered its design-time PREVIEW TEXT on
        // the actual generated ad as if it were real — e.g. a property with no
        // floor size (vacant land) showed a fabricated "450 m²" that was never
        // this property's size. Generalises the rule already applied to Agent 2
        // and every numeric feature field above to every text field.
        if (!opts.placeholders) return '';
        return el.preview || el.label || '';
    }

    /**
     * The image an element displays. opts.overrides is the generator's per-element
     * "change photo" map, keyed by element id — it wins over the slot's default and
     * survives a re-render.
     */
    function imageSrc(el, prop, opts) {
        prop = prop || {};
        opts = opts || {};
        var f = el.field;
        var ov = opts.overrides || {};

        if (f === 'custom_image' || f === 'custom_video') {
            return ov[el.id] || el.src || null;
        }
        var base = (f === 'agency_logo') ? (prop.logo || null) : (prop[f] || null);
        if (/^image_[1-5]$/.test(f) && ov[el.id]) return ov[el.id];
        return base;
    }

    /** The pre-override src, so the generator's "reset to original" can restore it. */
    function baseImageSrc(el, prop) {
        prop = prop || {};
        var f = el.field;
        if (f === 'custom_image' || f === 'custom_video') return el.src || '';
        if (f === 'agency_logo') return prop.logo || '';
        return prop[f] || '';
    }

    function canvasBackground(l) {
        if (!l) return '#071325';
        if (l.canvasBgMode === 'gradient') {
            return 'linear-gradient(' + def(l.canvasBgAngle, 160) + 'deg,'
                 + (l.canvasBgFrom || '#071325') + ',' + (l.canvasBgTo || '#0b2a4a') + ')';
        }
        return l.canvasBg || '#071325';
    }

    /** html2canvas takes a flat colour, not a gradient — give it the "from" stop. */
    function canvasBgSolid(l) {
        if (!l) return '#071325';
        return (l.canvasBgMode === 'gradient') ? (l.canvasBgFrom || '#071325') : (l.canvasBg || '#071325');
    }

    /* ── Content rendering ──────────────────────────────────────────────── */

    function imgTag(el, src, baseSrc, opts) {
        var f = el.field;
        var attrs = 'style="width:100%;height:100%;object-fit:' + fit(el) + ';display:block;"';

        // Tag so the generator's "change photo" overlay targets property photos only
        // — a logo or an agent avatar is never swapped from the gallery.
        if (f === 'agency_logo')      attrs += ' class="js-ad-logo"';
        else if (/avatar$/.test(f))   attrs += ' class="js-ad-avatar"';
        else if (opts.tagPhotos)      attrs += ' data-el-id="' + esc(el.id) + '" data-orig-src="' + esc(baseSrc) + '"';

        // Remove Background — an onload hook works identically whether this <img>
        // was inserted via Alpine's x-html (Builder) or renderLayout()'s innerHTML
        // (generator/bulk manager), so this is the ONLY per-surface change needed.
        if (isAgentAvatarField(f) && el.removeBackground) {
            attrs += ' onload="window.CoreXAd.stripBackground(this)"';
        }

        return '<img src="' + esc(src) + '" ' + attrs + '>';
    }

    /**
     * "Remove background" — a client-side, no-ML cutout for the common case the
     * feature was built for: a headshot on a plain/solid studio backdrop (the
     * request's own example: a white background). NOT general arbitrary-scene
     * person segmentation — that needs a real model; this is a flood fill.
     *
     * Algorithm: sample the backdrop colour from the four corners, then flood-fill
     * transparency inward from every border pixel that matches it within a colour
     * tolerance, stopping at any edge where the colour changes sharply. A pixel
     * only turns transparent if it is colour-matched AND reachable from the
     * border without crossing that edge — so a white shirt collar in the MIDDLE
     * of the photo survives (not connected to the border), while an actual
     * solid backdrop is removed. Downscaled to a 500px cap first so a full-
     * resolution profile photo never makes this slow.
     *
     * Same-origin only: relies on the SAME image-URL resolution already built for
     * html2canvas (Property::adSafeImageUrl()/§10e) — a genuinely cross-origin,
     * CORS-blocked photo fails silently (getImageData throws) and is left
     * showing its original background rather than breaking the ad.
     */
    var _bgRemovalCache = {}; // original <img>.src → Promise<processed dataURL | null>

    function stripBackground(img) {
        if (img.dataset.bgStripped === '1') return; // guards the re-load our own src-swap causes
        var src = img.src;
        if (!_bgRemovalCache[src]) _bgRemovalCache[src] = _processBackgroundRemoval(img);
        _bgRemovalCache[src].then(function (dataUrl) {
            if (dataUrl) {
                img.dataset.bgStripped = '1';
                img.src = dataUrl;
            }
        });
    }

    function _processBackgroundRemoval(img) {
        return new Promise(function (resolve) {
            try {
                var nw = img.naturalWidth, nh = img.naturalHeight;
                if (!nw || !nh) { resolve(null); return; }
                var MAX = 500;
                var scale = Math.min(1, MAX / Math.max(nw, nh));
                var w = Math.max(1, Math.round(nw * scale));
                var h = Math.max(1, Math.round(nh * scale));
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);
                var imageData = ctx.getImageData(0, 0, w, h); // throws on a tainted (cross-origin) canvas
                _floodFillTransparent(imageData, w, h);
                _fillEnclosedHoles(imageData, w, h);
                _featherAlpha(imageData, w, h);
                ctx.putImageData(imageData, 0, 0);
                resolve(canvas.toDataURL('image/png'));
            } catch (e) {
                resolve(null);
            }
        });
    }

    /**
     * TOP corners ONLY. A headshot crop very commonly has the subject's
     * shoulders/clothing reaching the BOTTOM corners — averaging those in pulls
     * the sampled "backdrop colour" toward the clothing colour, which is exactly
     * what let a white shirt get swept in as if it were a white background.
     */
    function _cornerColor(px, w, h) {
        var pts = [[0, 0], [w - 1, 0]];
        var r = 0, g = 0, b = 0;
        pts.forEach(function (p) {
            var i = (p[1] * w + p[0]) * 4;
            r += px[i]; g += px[i + 1]; b += px[i + 2];
        });
        return [r / pts.length, g / pts.length, b / pts.length];
    }

    /**
     * Seeding from the BOTTOM edge was the actual bug a real photo hit: a
     * headshot's shoulders/shirt routinely touch the bottom (and sometimes
     * lower-side) frame edge, and a shirt similar in tone to the backdrop got
     * swept into the same connected "background" region and erased along with
     * it. Seeds are top row + side columns in the upper half ONLY — never the
     * bottom row. A first attempt ALSO added a hard floor that refused to
     * erase anything in the bottom ~18% of the frame no matter what — that
     * overcorrected: a real photo with genuine backdrop visible below the
     * shoulders (not every crop has the garment touching the very bottom
     * pixel) got left with a visible strip of un-removed background, because
     * the floor is blind to whether that band is actually backdrop or
     * clothing. Removed. Propagation is UNRESTRICTED once seeded — a
     * background pixel connects to more background below it regardless of y
     * position — so genuine backdrop low in the frame is still correctly
     * cleared; only the SEED set and the colour reference changed. The
     * remaining defence against eating a garment is what a colour algorithm
     * can actually reason about: not starting from the bottom edge, sampling
     * the backdrop colour from a clean spot, and a tolerance tight enough to
     * stop at a real (even subtle) edge. It cannot help a garment that is
     * truly colour-identical to the backdrop with zero edge between them —
     * that is a real, inherent limit of colour-based cutout, not a bug to
     * patch around with a blind position rule.
     *
     * *Round 5 — a real photo hit a THIRD failure shape: a smooth lighting
     * gradient.* A well-lit collar's highlight can sit only a MODEST colour
     * distance from the backdrop (measured on a real photo: median ~18,
     * up to ~26 — i.e. individually within tolerance, never "identical")
     * while being 4-connected, pixel by pixel, all the way back to a real
     * seed through a gradual, unbroken chain. Each step alone passes the
     * flat tolerance check, so the fill marches out of genuine backdrop and
     * deep into lit fabric. Measured directly on the failing photo: distance
     * from the fixed backdrop reference sits at ~0 for ~600 hops of the
     * fill's actual path (real backdrop, correctly flat), then jumps to
     * ~23 in a single hop at the exact point the fill crosses from
     * background into the lit collar edge — and stays elevated (17–25) for
     * the rest of the path deeper into the fabric. A per-pixel tolerance
     * check alone cannot see this: it only ever asks "is THIS pixel close
     * enough to backdrop", never "how far has the fill already drifted to
     * get here."
     *
     * Fix: track `pathMax2` — the HIGHEST single-pixel squared colour
     * distance seen ANYWHERE along the chain of already-accepted pixels
     * connecting a given pixel back to its seed (each pixel inherits the max
     * of its own distance and whichever neighbour first reached it). Once
     * `pathMax2` exceeds `floodFillDriftCapPx` (squared), stop — do not
     * remove this pixel, and do not propagate past it either, so everything
     * beyond it is protected too. Real backdrop keeps `pathMax2` at ~0 for
     * its entire (often very long) path, since it's genuinely flat —
     * measured across 5 real photos, the 90th percentile of path-max-distance
     * across every removed pixel is 0 in EVERY photo tested, so this cap is
     * invisible to ordinary backdrop removal. It only engages at the exact
     * point a path's drift first exceeds the cap — which on the failing
     * photo is right where genuine backdrop ends and the lit collar begins.
     * `floodFillDriftCapPx` (default 20) sits below where that photo's leak
     * sits (~22–25) and above where four OTHER real photos' entire removed
     * area sat (their own 95th-98th percentiles), so it engages ONLY on the
     * one failure case tested, not as a blanket tightening of every photo's
     * cutout.
     */
    function _floodFillTransparent(imageData, w, h) {
        var px = imageData.data;
        var bg = _cornerColor(px, w, h);
        var tol2 = 26 * 26; // Euclidean colour-distance tolerance, squared (avoid a sqrt per pixel)
        var driftCapPx = _bgRemovalConfig.floodFillDriftCapPx;
        var driftCap2 = driftCapPx * driftCapPx;
        var visited = new Uint8Array(w * h);
        var stack = []; // (x, y, pathMax2-inherited-from-whichever-neighbour-reached-it-first) triples
        var sideSeedLimit = Math.floor(h * 0.5);

        for (var x = 0; x < w; x++) { stack.push(x, 0, 0); }
        for (var y = 0; y < sideSeedLimit; y++) { stack.push(0, y, 0, w - 1, y, 0); }

        while (stack.length) {
            var inheritedPathMax2 = stack.pop(), yy = stack.pop(), xx = stack.pop();
            if (xx < 0 || yy < 0 || xx >= w || yy >= h) continue;
            var idx = yy * w + xx;
            if (visited[idx]) continue;
            visited[idx] = 1;
            var o = idx * 4;
            var dr = px[o] - bg[0], dg = px[o + 1] - bg[1], db = px[o + 2] - bg[2];
            var d2 = dr * dr + dg * dg + db * db;
            if (d2 > tol2) continue; // not backdrop-like — stop here
            var pathMax2 = d2 > inheritedPathMax2 ? d2 : inheritedPathMax2;
            if (pathMax2 > driftCap2) continue; // drifted too far from true backdrop along this path — stop, protect this pixel and everything beyond it
            px[o + 3] = 0;
            stack.push(xx + 1, yy, pathMax2, xx - 1, yy, pathMax2, xx, yy + 1, pathMax2, xx, yy - 1, pathMax2);
        }
    }

    /**
     * Agency-configurable thresholds for the background-removal pipeline —
     * see `_floodFillTransparent()`'s and `_fillEnclosedHoles()`'s docblocks
     * for what each one protects against and the evidence behind its
     * default. Never hardcode a NEW threshold here without going through
     * `configureBgRemoval()`; these are read from `agencies.ad_bg_removal_*`
     * (nullable — null means "use this file's default"), passed in once per
     * page by each of the three render surfaces (ad.blade.php,
     * ad-builder.blade.php, tools/ad-manager.blade.php). Spec: ad-manager.md
     * §15.1 rounds 4–5.
     */
    var _bgRemovalConfig = {
        holeMinPx: 30,          // below this, treat it as a catch-light/noise — never fill
        holeMaxPx: 1200,        // above this AREA, treat it as clothing/fabric — never fill
        holeMaxDimensionPx: 45, // above this LONGEST bbox side, treat it as clothing/fabric — never fill
        floodFillDriftCapPx: 20, // round 5 — see _floodFillTransparent()'s docblock
    };

    /**
     * Called once per page (ad.blade.php / ad-builder.blade.php /
     * tools/ad-manager.blade.php) with the current agency's settings. Any
     * key that is null/undefined/omitted keeps this file's default — a
     * property with no agency (shouldn't happen) or an agency that has never
     * touched these settings gets identical behaviour to before this
     * existed.
     */
    function configureBgRemoval(cfg) {
        cfg = cfg || {};
        ['holeMinPx', 'holeMaxPx', 'holeMaxDimensionPx', 'floodFillDriftCapPx'].forEach(function (key) {
            if (cfg[key] !== null && cfg[key] !== undefined && !isNaN(cfg[key])) {
                _bgRemovalConfig[key] = +cfg[key];
            }
        });
    }

    /**
     * Fills small ENCLOSED pockets of backdrop colour the border-seeded flood
     * fill above can never reach — e.g. the white background visible through a
     * hoop earring, or a gap between a jacket lapel and collar. A real photo
     * hit both: white discs at both ear positions, and a pale block near the
     * collar, both read as "pasted on" because they were never touched at all.
     *
     * A pocket is filled ONLY if it (a) touches NO edge of the frame anywhere
     * along its connected boundary, (b) is at least `holeMinPx` pixels, (c) is
     * at most `holeMaxPx` pixels in area, AND (d) its bounding box's LONGEST
     * side is at most `holeMaxDimensionPx`.
     *
     * (a) is what keeps this from ever regressing the "ate a light shirt" fix
     * (§15.1 round 1/2) — anything connected to a border, even a backdrop-
     * coloured pocket that snakes all the way to one, is left alone, exactly
     * like the main fill already does.
     *
     * (b) is what keeps this from removing a catch-light: a specular
     * highlight in an eye is ALSO within colour tolerance of a white backdrop
     * and ALSO fully enclosed, but on a real photo measured 19px and under.
     *
     * (c) and (d) exist because (b) alone is NOT enough — round 3 shipped
     * with only a floor, and a DIFFERENT pose (arms crossed, a wide V-neck
     * collar fully enclosed by a dark jacket on both sides) hit the opposite
     * failure: a large genuine patch of shirt, not an earring, got treated as
     * a "hole" and erased, because nothing capped how big an enclosed pocket
     * was allowed to be. Measured on real photos, both defaults come from the
     * SAME dataset a floor-only fix was checked against, extended with the
     * failing case:
     *   - genuine holes (earrings, small gaps): 46–851px area, bounding box
     *     never wider/taller than 41px.
     *   - the failing case (an open collar's visible V, fully enclosed by a
     *     dark jacket): one component alone was 2059px, bbox 60×131.
     *   - a second, smaller failing case on the SAME photo: 602px, bbox
     *     30×51 — notably, its AREA (602) is SMALLER than the largest
     *     genuine hole (851px), so an area cap ALONE cannot separate every
     *     case correctly; a cap that excludes 602 would also wrongly exclude
     *     725 and 851. The bounding-box DIMENSION is what actually separates
     *     every measured case cleanly: every genuine hole's longest side is
     *     ≤41px; every failing case's longest side is ≥51px. That gap is why
     *     (d) exists as its own guard, not folded into (c).
     * `holeMaxPx` (default 1200) sits with margin above the largest genuine
     * hole (851) and below the large failing case (2059) — a second,
     * independent line of defence for a large pocket even if some future
     * photo's proportions happened to keep its longest side under the
     * dimension cap.
     *
     * All three numbers are read from `_bgRemovalConfig` (agency-
     * configurable — see `configureBgRemoval()`), never hardcoded per-call —
     * a photo/agent/pose-specific fix would only mask the next pose that
     * exposes the same class of bug.
     */
    function _fillEnclosedHoles(imageData, w, h) {
        var px = imageData.data;
        var bg = _cornerColor(px, w, h);
        var tol2 = 26 * 26;
        var visited = new Uint8Array(w * h);
        var holeMinPx = _bgRemovalConfig.holeMinPx;
        var holeMaxPx = _bgRemovalConfig.holeMaxPx;
        var holeMaxDimensionPx = _bgRemovalConfig.holeMaxDimensionPx;

        for (var y0 = 0; y0 < h; y0++) {
            for (var x0 = 0; x0 < w; x0++) {
                var idx0 = y0 * w + x0;
                if (visited[idx0]) continue;
                var o0 = idx0 * 4;
                if (px[o0 + 3] === 0) { visited[idx0] = 1; continue; } // already removed
                var dr0 = px[o0] - bg[0], dg0 = px[o0 + 1] - bg[1], db0 = px[o0 + 2] - bg[2];
                if ((dr0 * dr0 + dg0 * dg0 + db0 * db0) > tol2) { visited[idx0] = 1; continue; } // not backdrop-coloured

                // Flood this connected backdrop-coloured island; track border
                // contact and the bounding box alongside it.
                var stack = [x0, y0];
                var component = [idx0];
                var touchesBorder = (x0 === 0 || y0 === 0 || x0 === w - 1 || y0 === h - 1);
                var minX = x0, maxX = x0, minY = y0, maxY = y0;
                visited[idx0] = 1;

                while (stack.length) {
                    var cy = stack.pop(), cx = stack.pop();
                    var neighbours = [[cx + 1, cy], [cx - 1, cy], [cx, cy + 1], [cx, cy - 1]];
                    for (var n = 0; n < neighbours.length; n++) {
                        var nx = neighbours[n][0], ny = neighbours[n][1];
                        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                        var nidx = ny * w + nx;
                        if (visited[nidx]) continue;
                        visited[nidx] = 1;
                        var no = nidx * 4;
                        if (px[no + 3] === 0) continue; // already removed — a boundary, not part of this island
                        var ndr = px[no] - bg[0], ndg = px[no + 1] - bg[1], ndb = px[no + 2] - bg[2];
                        if ((ndr * ndr + ndg * ndg + ndb * ndb) > tol2) continue; // not backdrop-coloured — a boundary
                        if (nx === 0 || ny === 0 || nx === w - 1 || ny === h - 1) touchesBorder = true;
                        if (nx < minX) minX = nx; if (nx > maxX) maxX = nx;
                        if (ny < minY) minY = ny; if (ny > maxY) maxY = ny;
                        component.push(nidx);
                        stack.push(nx, ny);
                    }
                }

                var size = component.length;
                var longestSide = Math.max(maxX - minX + 1, maxY - minY + 1);
                if (!touchesBorder && size >= holeMinPx && size <= holeMaxPx && longestSide <= holeMaxDimensionPx) {
                    for (var c = 0; c < component.length; c++) px[component[c] * 4 + 3] = 0;
                }
            }
        }
    }

    /**
     * Softens the flood fill's hard binary alpha edge (0 or 255, nothing
     * between) into a soft anti-aliased line — a small box blur on the ALPHA
     * CHANNEL ONLY, never the colour channels, so it cannot bleed backdrop
     * colour into the subject's edge pixels. Radius 1 (a 3×3 box) — enough to
     * visibly soften fine hair strands without a visible halo.
     */
    function _featherAlpha(imageData, w, h) {
        var px = imageData.data;
        var srcAlpha = new Uint8ClampedArray(w * h);
        for (var i = 0; i < w * h; i++) srcAlpha[i] = px[i * 4 + 3];

        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var sum = 0, count = 0;
                for (var dy = -1; dy <= 1; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        var nx = x + dx, ny = y + dy;
                        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                        sum += srcAlpha[ny * w + nx];
                        count++;
                    }
                }
                px[(y * w + x) * 4 + 3] = Math.round(sum / count);
            }
        }
    }

    /**
     * Resolves once every in-flight background-removal job has settled. The
     * capture paths (single-property + bulk) await this before html2canvas runs,
     * so a fast bulk run can never rasterise the UN-stripped original because
     * processing simply hadn't finished yet.
     */
    function backgroundRemovalsSettled() {
        var jobs = Object.keys(_bgRemovalCache).map(function (k) {
            return _bgRemovalCache[k].catch(function () {});
        });
        return Promise.all(jobs);
    }

    /**
     * Pure geometry for CSS object-fit cover/contain — the destination rect an
     * image draws into a box of (boxW, boxH). No DOM, easily unit-testable.
     */
    function objectFitRect(fitVal, boxW, boxH, natW, natH) {
        if (!natW || !natH || !boxW || !boxH) return null;
        var scale = fitVal === 'contain' ? Math.min(boxW / natW, boxH / natH) : Math.max(boxW / natW, boxH / natH);
        var dw = natW * scale, dh = natH * scale;
        return { dw: dw, dh: dh, dx: (boxW - dw) / 2, dy: (boxH - dh) / 2 };
    }

    /**
     * html2canvas has long-documented gaps in its CSS object-fit support — it
     * can rasterise an <img> at its raw intrinsic aspect ratio stretched to
     * fill the element's box instead of correctly cropping/letterboxing it the
     * way the live browser (correctly) shows it. A real ad hit this: an Agent
     * Image sitting at the very edge of the canvas rendered visibly stretched
     * in the downloaded PNG while the on-screen preview was correct.
     *
     * Rather than fight html2canvas's object-fit implementation, this pre-bakes
     * the SAME crop onto an offscreen <canvas> sized to the element's own box
     * (same technique already proven for "Remove background") — by the time
     * html2canvas captures it, the image genuinely IS the right shape, so there
     * is nothing left for its object-fit handling to get wrong. Every capture
     * path (single-property generator, Ad Builder export, bulk Ad Manager)
     * calls this immediately before its html2canvas() call.
     *
     * Swaps each qualifying <img>'s `src` to the pre-cropped data URL and
     * resolves to a restore function — the caller MUST call it (in a
     * try/finally) once the capture is done, so the live preview / "change
     * photo" overlay / gallery picker keep reading the ORIGINAL src exactly as
     * before. This never touches the live DOM permanently.
     *
     * 'fill' is left alone (it already stretches on purpose — nothing to
     * correct). A cross-origin/tainted image is left alone too (object-fit
     * still applies correctly live in the browser; only the html2canvas
     * pre-bake is skipped for that one image, never a thrown error).
     */
    function prepareImagesForCapture(root) {
        if (!root) return Promise.resolve(function () {});
        var imgs = Array.prototype.slice.call(root.querySelectorAll('img'));
        var restores = [];
        var loadPromises = [];

        imgs.forEach(function (img) {
            var fitVal = (img.style.objectFit || '').toLowerCase();
            if (fitVal !== 'cover' && fitVal !== 'contain') return;

            var box = img.getBoundingClientRect();
            var w = Math.round(box.width), h = Math.round(box.height);
            var rect = objectFitRect(fitVal, w, h, img.naturalWidth, img.naturalHeight);
            if (!rect) return;

            try {
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, rect.dx, rect.dy, rect.dw, rect.dh);
                var dataUrl = canvas.toDataURL('image/png'); // throws on a tainted (cross-origin) canvas
                var originalSrc = img.src;
                img.src = dataUrl;
                restores.push(function () { img.src = originalSrc; });
                loadPromises.push(img.decode ? img.decode().catch(function () {}) : Promise.resolve());
            } catch (e) {
                // Tainted canvas or any processing error — leave this image
                // exactly as the browser already renders it.
            }
        });

        return Promise.all(loadPromises).then(function () {
            return function restoreImages() {
                restores.forEach(function (fn) { fn(); });
            };
        });
    }

    function placeholderHtml(label) {
        return '<div style="width:100%;height:100%;background:linear-gradient(135deg,#0b2a4a,#143d6e);'
             + 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;'
             + 'color:rgba(255,255,255,0.3);font-size:12px;pointer-events:none;">'
             + '<svg style="width:28px;height:28px;opacity:0.35;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">'
             + '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>'
             + '<span>' + esc(label) + '</span></div>';
    }

    /** A missing photo on a REAL ad: a quiet tinted box, never an icon + label. */
    function emptyPhotoHtml() {
        return '<div style="width:100%;height:100%;background:linear-gradient(135deg,#0b2a4a,#143d6e);"></div>';
    }

    /**
     * The inner HTML of one element. This is the whole reason the kernel exists:
     * one function decides what every field type looks like, on every surface.
     */
    function contentHtml(el, prop, opts) {
        prop = prop || {};
        opts = opts || {};
        var f = el.field;

        if (isImageField(f)) {
            var src = imageSrc(el, prop, opts);
            if (src) return imgTag(el, src, baseImageSrc(el, prop), opts);
            // A co-agent avatar on a single-agent listing leaves the slot EMPTY.
            if (isAgent2(f) && !opts.placeholders) return '';
            return opts.placeholders ? placeholderHtml(el.label) : emptyPhotoHtml();
        }

        if (f === 'custom_image') {
            var isrc = imageSrc(el, prop, opts);
            if (isrc) return imgTag(el, isrc, baseImageSrc(el, prop), opts);
            return opts.placeholders ? placeholderHtml('Upload an image →') : '';
        }

        if (f === 'custom_video') {
            if (el.src) {
                return '<video src="' + esc(el.src) + '" autoplay muted loop playsinline '
                     + 'style="width:100%;height:100%;object-fit:' + fit(el) + ';display:block;"></video>';
            }
            return opts.placeholders ? placeholderHtml('Upload a video →') : '';
        }

        if (f === 'color_block') return '<div style="' + colorBlockCss(el) + '"></div>';
        if (f === 'shape')       return '<div style="' + shapeCss(el) + '"></div>';
        if (f === 'gradient')    return '<div style="' + gradientCss(el) + '"></div>';

        if (f === 'line') {
            return '<div style="width:100%;height:100%;display:flex;align-items:center;">'
                 + '<div style="' + lineCss(el) + '"></div></div>';
        }

        if (f === 'logo') {
            var inner = prop.logo
                ? '<img src="' + esc(prop.logo) + '" class="js-ad-logo" style="max-height:100%;max-width:100%;object-fit:contain;object-position:left center;">'
                : '<div style="font-family:' + fontStack(el.fontFamily) + ';font-weight:900;line-height:1;'
                  + 'font-size:' + (el.fontSize || 28) + 'px;color:' + (el.color || '#ffffff') + ';">'
                  + 'corex<span style="color:#33c4e0">os</span></div>';
            return '<div style="width:100%;height:100%;display:flex;align-items:center;padding:' + (el.padding || 0) + 'px;">'
                 + inner + '</div>';
        }

        if (f === 'watermark') {
            return '<div style="' + watermarkCss(el) + '">'
                 + esc(prop.watermark || el.text || 'COREX') + '</div>';
        }

        // Text field. An icon (Beds/Baths/Garages/Parking) sits inline before the
        // value — it is NOT a separate element, so it always aligns and re-flows
        // with the text as one unit under textAlign/verticalAlign.
        var iconEl = el.icon && ICONS[el.icon] ? iconHtml(el) : '';
        return '<div style="' + textStyle(el) + '">'
             + iconEl
             + '<span style="' + (iconEl ? '' : 'width:100%;') + '">' + esc(textValue(el, prop, opts)) + '</span></div>';
    }

    /** The inline icon swatch for a text element carrying `el.icon`. */
    function iconHtml(el) {
        var size = el.iconSize || el.fontSize || 18;
        var gap = Math.max(4, Math.round(size * 0.4));
        return '<span style="flex:none;display:inline-flex;width:' + size + 'px;height:' + size + 'px;'
             + 'margin-right:' + gap + 'px;">' + ICONS[el.icon] + '</span>';
    }

    /**
     * §18 — Property-type template variants. A saved template's layout_json
     * can carry `variants`: { [exact property_setting_items name]: { canvasW,
     * canvasH, canvasBg, canvasBgMode, canvasBgFrom, canvasBgTo,
     * canvasBgAngle, canvasPreset, elements } } — a FULL alternate design
     * (its own canvas + its own element set), not per-element toggles. The
     * fields at the top level of layout_json (outside `variants`) ARE the
     * Default design, used for any property type with no variant of its own.
     *
     * This resolves ONE concrete layout to actually render, given a real
     * property's raw type string — the one place that decision gets made, so
     * the three render surfaces (generator, bulk manager, Ad Builder picker
     * previews) never each re-implement the matching rule. Matching is
     * case-insensitive/trimmed against the exact configured name (the same
     * string the property's own "Property Type" dropdown shows) — not a
     * hardcoded taxonomy, so it stays correct for any agency's own
     * customised property-type list.
     *
     * No match (blank property type, no variants at all, or a type nobody
     * made custom) → the Default design. This is also what a property with
     * no type classified gets — never a broken/empty render over missing
     * classification data.
     */
    function resolveTemplateLayout(layoutJson, propertyTypeRaw) {
        var base = layoutJson || {};
        var variants = base.variants;
        var t = (propertyTypeRaw || '').trim().toLowerCase();

        if (variants && t) {
            for (var key in variants) {
                if (Object.prototype.hasOwnProperty.call(variants, key) && key.trim().toLowerCase() === t) {
                    var v = variants[key];
                    return {
                        canvasW: v.canvasW, canvasH: v.canvasH,
                        canvasBg: v.canvasBg, canvasBgMode: v.canvasBgMode,
                        canvasBgFrom: v.canvasBgFrom, canvasBgTo: v.canvasBgTo, canvasBgAngle: v.canvasBgAngle,
                        canvasPreset: v.canvasPreset,
                        elements: v.elements || [],
                    };
                }
            }
        }

        return {
            canvasW: base.canvasW, canvasH: base.canvasH,
            canvasBg: base.canvasBg, canvasBgMode: base.canvasBgMode,
            canvasBgFrom: base.canvasBgFrom, canvasBgTo: base.canvasBgTo, canvasBgAngle: base.canvasBgAngle,
            canvasPreset: base.canvasPreset,
            elements: base.elements || [],
        };
    }

    /**
     * Render a whole layout_json into `root`. Used by the two DOM surfaces; the
     * builder renders reactively through Alpine but calls the same frameStyle()
     * and contentHtml(), so all three stay in lock-step.
     */
    function renderLayout(layout, prop, root, opts) {
        if (!root || !layout) return;
        opts = opts || {};
        prop = prop || {};

        root.innerHTML = '';
        if (opts.paintBackground) root.style.background = canvasBackground(layout);

        (layout.elements || []).forEach(function (el) {
            if (el.hidden) return;                       // hidden in the builder = absent from the ad
            var div = document.createElement('div');
            div.style.cssText = frameStyle(el, opts);
            div.innerHTML = contentHtml(el, prop, opts);
            root.appendChild(div);
        });

        // innerHTML-inserted <video> does not always honour the autoplay attribute.
        root.querySelectorAll('video').forEach(function (v) {
            var p = v.play();
            if (p && p.catch) p.catch(function () {});
        });
    }

    /** A brand-new element of `fieldType`, seeded from FIELD_DEFAULTS. */
    function makeElement(fieldType, x, y, zIndex) {
        var d = FIELD_DEFAULTS[fieldType] || {};
        var meta = null;
        for (var i = 0; i < FIELDS.length; i++) {
            if (FIELDS[i].type === fieldType) { meta = FIELDS[i]; break; }
        }
        return {
            id:    Date.now() + Math.random(),
            field: fieldType,
            label: (meta && meta.label) || fieldType,
            x: x, y: y,
            w: d.w || 200,
            h: d.h || 60,
            zIndex: zIndex || 1,
            rotation: 0,
            hidden: false,
            locked: false,
            elOpacity: 1,
            fontFamily:    'Figtree',
            fontSize:      d.fontSize || 18,
            fontWeight:    d.fontWeight || '600',
            color:         d.color || '#ffffff',
            textAlign:     d.textAlign || 'left',
            verticalAlign: 'middle',
            textTransform: d.textTransform || 'none',
            letterSpacing: def(d.letterSpacing, 0),
            lineHeight:    def(d.lineHeight, 1.2),
            padding:       def(d.padding, 8),
            preview:       d.preview || '',
            text:          d.text || '',
            bgColor:       d.bgColor || '#000000',
            bgOpacity:     def(d.bgOpacity, 0),
            objectFit:     d.objectFit || 'cover',
            borderRadius:  def(d.borderRadius, 0),
            bg:            d.bg || '#07111e',
            opacity:       def(d.opacity, 1),
            shapeType:     d.shapeType || 'rounded',
            src:           d.src || '',
            mediaKind:     '',
            selectedFeatures: null,
            // Beds/Baths/Garages/Parking — display format + optional icon. Default
            // 'number' + no icon matches the pre-existing raw-number behaviour, so
            // a legacy template (these keys absent entirely) renders identically.
            numberFormat:  d.numberFormat || 'number',
            icon:          d.icon || null,
            iconSize:      d.iconSize || null,
            gradFrom:      d.gradFrom || '#071325',
            gradTo:        d.gradTo || 'rgba(7,19,37,0)',
            gradAngle:     def(d.gradAngle, 180),
            borderWidth:   def(d.borderWidth, 3),
            frameBorderWidth: 0,
            frameBorderColor: '#ffffff',
            shadowOn:      false,
            shadowX:       0,
            shadowY:       4,
            shadowBlur:    12,
            shadowColor:   '#000000',
            shadowOpacity: 0.45,
            // Builder-only (like `locked`) — elements sharing a groupId select and
            // move together in the Ad Builder. Ignored by the generator/bulk
            // manager; does not affect frameStyle()/contentHtml() output at all.
            groupId:       null,
            // Agent Image only — see imgTag()/stripBackground().
            removeBackground: d.removeBackground || false
        };
    }

    root.CoreXAd = {
        CANVAS_PRESETS: CANVAS_PRESETS,
        SHAPE_CLIPS: SHAPE_CLIPS,
        SHAPES: SHAPES,
        FONTS: FONTS,
        IMAGE_FIELDS: IMAGE_FIELDS,
        NON_TEXT_FIELDS: NON_TEXT_FIELDS,
        FIELD_DEFAULTS: FIELD_DEFAULTS,
        FIELD_GROUPS: FIELD_GROUPS,
        FIELDS: FIELDS,
        NUMERIC_FEATURE_FIELDS: NUMERIC_FEATURE_FIELDS,
        FEATURE_LABELS: FEATURE_LABELS,
        ICONS: ICONS,
        ICON_LIST: ICON_LIST,

        esc: esc,
        hexToRgba: hexToRgba,
        isImageField: isImageField,
        isTextField: isTextField,
        isAgent2: isAgent2,
        isAgentAvatarField: isAgentAvatarField,
        avatarShapeCss: avatarShapeCss,
        stripBackground: stripBackground,
        backgroundRemovalsSettled: backgroundRemovalsSettled,
        objectFitRect: objectFitRect,
        prepareImagesForCapture: prepareImagesForCapture,
        floodFillTransparent: _floodFillTransparent,
        cornerColor: _cornerColor,
        fillEnclosedHoles: _fillEnclosedHoles,
        featherAlpha: _featherAlpha,
        configureBgRemoval: configureBgRemoval,
        resolveTemplateLayout: resolveTemplateLayout,
        isClipShape: isClipShape,
        canShadow: canShadow,
        fontStack: fontStack,
        shadowValue: shadowValue,

        frameStyle: frameStyle,
        shapeCss: shapeCss,
        gradientCss: gradientCss,
        lineCss: lineCss,
        colorBlockCss: colorBlockCss,
        watermarkCss: watermarkCss,
        textStyle: textStyle,

        textValue: textValue,
        formatFeatureNumber: formatFeatureNumber,
        featureWord: featureWord,
        resolveGaragesOrParking: resolveGaragesOrParking,
        resolveSizeOrLand: resolveSizeOrLand,
        formatSizeNumber: formatSizeNumber,
        iconHtml: iconHtml,
        imageSrc: imageSrc,
        baseImageSrc: baseImageSrc,
        canvasBackground: canvasBackground,
        canvasBgSolid: canvasBgSolid,

        contentHtml: contentHtml,
        renderLayout: renderLayout,
        makeElement: makeElement
    };
})(window);
