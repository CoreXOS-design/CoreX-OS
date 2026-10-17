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

    /**
     * AT-456 §B.9-2 — the same six polygons as SHAPE_CLIPS, as SVG `points` ("50,0 100,100 0,100") on a 0-100 grid.
     * html2canvas (the export) IGNORES clip-path, so a Shape element is drawn as an inline SVG polygon instead —
     * identical on the canvas and in the exported picture. Generated from SHAPE_CLIPS so there is one definition.
     */
    function clipToPoints(clip) {
        var m = /^polygon\((.*)\)$/.exec(String(clip || ''));
        if (!m) return '';
        return m[1].split(',').map(function (pair) {
            var xy = pair.trim().split(/\s+/);
            return parseFloat(xy[0]) + ',' + parseFloat(xy[1]);
        }).join(' ');
    }
    var SHAPE_POINTS = {};
    Object.keys(SHAPE_CLIPS).forEach(function (k) { SHAPE_POINTS[k] = clipToPoints(SHAPE_CLIPS[k]); });

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
        { name: 'Lora',            stack: "'Lora',Georgia,serif" },
        // AT-456 §B.9-1 — twelve more (Google Fonts, Open Font Licence). `lazy` families are NOT in the
        // always-loaded stylesheet: requestFont() adds their stylesheet the first time a template draws
        // text in them, so a template that does not use them costs nothing. The stylesheet parameters live
        // in _ad-fonts.blade.php (window.CoreXAdLazyFonts). `note` is a hint the builder shows next to the picker.
        { name: 'Cormorant Garamond', stack: "'Cormorant Garamond',Georgia,serif", lazy: true },
        { name: 'DM Serif Display',   stack: "'DM Serif Display',Georgia,serif", lazy: true },
        { name: 'Libre Baskerville',  stack: "'Libre Baskerville',Georgia,serif", lazy: true },
        { name: 'Great Vibes',        stack: "'Great Vibes','Brush Script MT',cursive", lazy: true, note: 'Script face: for short headlines only. Never use it for prices, phone numbers or body text.' },
        { name: 'Dancing Script',     stack: "'Dancing Script','Brush Script MT',cursive", lazy: true, note: 'Script face: for short headlines only. Never use it for prices, phone numbers or body text.' },
        { name: 'Barlow Condensed',   stack: "'Barlow Condensed','Arial Narrow',sans-serif", lazy: true },
        { name: 'Anton',              stack: "'Anton',Impact,sans-serif", lazy: true },
        { name: 'Nunito',             stack: "'Nunito',Arial,sans-serif", lazy: true },
        { name: 'Quicksand',          stack: "'Quicksand',Arial,sans-serif", lazy: true },
        { name: 'Raleway',            stack: "'Raleway',Arial,sans-serif", lazy: true },
        { name: 'Work Sans',          stack: "'Work Sans',Arial,sans-serif", lazy: true },
        { name: 'Space Grotesk',      stack: "'Space Grotesk',Arial,sans-serif", lazy: true }
    ];

    var IMAGE_FIELDS = [
        'image_1', 'image_2', 'image_3', 'image_4', 'image_5',
        'agent_avatar', 'agent_2_avatar', 'agency_logo'
    ];

    var NON_TEXT_FIELDS = IMAGE_FIELDS.concat([
        'logo', 'watermark', 'color_block', 'gradient', 'line', 'shape',
        'custom_image', 'custom_video',
        // AT-456 §B.9-1 — elements that draw something other than one run of text.
        'collage', 'ribbon', 'qr', 'feature_pills'
    ]);

    /* ── AT-456 §B.9-1 — building blocks ─────────────────────────────────────
     * Rule for everything below: it must look the same on the Ad Builder canvas, in the Template
     * Manager previews and in the PNG/JPEG the Ad Manager and the Post Scheduler export. The export is
     * html2canvas 1.4.1, which IGNORES clip-path, CSS filter, backdrop-filter and mask — so none of those
     * are used for a new block. Shapes/masks are border-radius or inline SVG (both exported faithfully);
     * photo effects are one colour matrix that the live view applies through an inline SVG filter and the
     * export applies to the pixels (prepareImagesForCapture) — one definition, two renderers. A soft
     * blur behind text was left out: html2canvas cannot draw it, so it could not match. */

    /** Decorative shapes (`shapeType` of a Shape element), drawn as inline SVG stretched to the box. */
    var DECOR_SHAPES = {
        wave:         'M0 40 C16 20 34 20 50 40 S84 60 100 40 V100 H0 Z',
        curve_top:    'M0 38 Q50 -14 100 38 V100 H0 Z',
        curve_bottom: 'M0 0 H100 V62 Q50 114 0 62 Z',
        arc:          'M0 100 A50 100 0 0 1 100 100 Z',
        blob_1:       'M50 3 C78 2 97 25 96 52 C95 80 72 98 47 96 C20 94 3 74 4 48 C5 22 24 4 50 3 Z',
        blob_2:       'M62 4 C85 10 98 34 92 58 C86 84 60 98 36 92 C12 86 3 62 8 40 C14 16 40 2 62 4 Z',
        blob_3:       'M30 8 C52 2 84 6 94 30 C100 54 88 80 64 92 C40 99 14 90 6 66 C2 42 10 18 30 8 Z'
    };
    var DECOR_SHAPE_LIST = [
        { type: 'wave',         label: 'Wave' },
        { type: 'curve_top',    label: 'Curve (top edge)' },
        { type: 'curve_bottom', label: 'Curve (bottom edge)' },
        { type: 'arc',          label: 'Arc (dome)' },
        { type: 'blob_1',       label: 'Blob 1' },
        { type: 'blob_2',       label: 'Blob 2' },
        { type: 'blob_3',       label: 'Blob 3' }
    ];

    var LINE_STYLES = ['solid', 'dashed', 'dotted'];
    var PHOTO_MASKS = [
        { key: 'none',    label: 'None' },
        { key: 'rounded', label: 'Rounded' },
        { key: 'circle',  label: 'Circle / oval' },
        { key: 'arch',    label: 'Arch' },
        { key: 'blob',    label: 'Blob' }
    ];
    var PHOTO_EFFECTS = [
        { key: 'none',     label: 'None' },
        { key: 'bw',       label: 'Black & white' },
        { key: 'duotone',  label: 'Duotone (two colours)' },
        { key: 'warm',     label: 'Warm' },
        { key: 'cool',     label: 'Cool' },
        { key: 'brighter', label: 'Brighter' }
    ];
    var RIBBON_CORNERS = [
        { key: 'tl', label: 'Top left' }, { key: 'tr', label: 'Top right' },
        { key: 'bl', label: 'Bottom left' }, { key: 'br', label: 'Bottom right' }
    ];
    var COLLAGE_PRESETS = [
        { key: '2_side',      label: '2 photos, side by side', count: 2 },
        { key: '2_stack',     label: '2 photos, stacked',      count: 2 },
        { key: '3_hero_left', label: '3 photos, big one on the left', count: 3 },
        { key: '3_hero_top',  label: '3 photos, big one on top',      count: 3 },
        { key: '4_grid',      label: '4 photos, grid',         count: 4 },
        { key: '5_hero',      label: '5 photos, one big and four small', count: 5 }
    ];

    /** Elements that can take a photo mask / photo effect. */
    var MASKABLE_FIELDS = ['image_1', 'image_2', 'image_3', 'image_4', 'image_5', 'custom_image'];
    var EFFECT_FIELDS   = MASKABLE_FIELDS.concat(['collage']);

    /**
     * Listing-fact text fields. The server (Property::adData → AdListingFacts) sends each as ready-to-print
     * text, or an empty string when the listing has no value — and an element with no value is left off a
     * real ad entirely (never "R 0", "null" or an empty pill). FACT_LABELS are the words put in front of a
     * value; `el.showLabel === false` prints the bare value (for use with an icon).
     */
    var FACT_FIELDS = ['erf_size', 'levy', 'rates_taxes', 'price_per_m2', 'pet_friendly', 'available_from',
                       'rent_per_month', 'show_day', 'auction_date', 'auction_venue'];
    var FACT_LABELS = {
        erf_size: 'Erf', levy: 'Levy', rates_taxes: 'Rates & taxes',
        show_day: 'Show day', auction_date: 'Auction', auction_venue: 'Venue'
    };

    /** A neutral, valid QR (encodes https://example.com) for the builder's design-time preview only. */
    var QR_SAMPLE = ["1111111010000110001111111","1000001000111111001000001","1011101011001110101011101","1011101001101100101011101","1011101000000101001011101","1000001010110110001000001","1111111010101010101111111","0000000001110101100000000","1010001101010001100100101","1100110110000010111111000","1101001001111000101100010","1001110101110101101001001","0111101001111101101001101","0001100100101010010111011","1101111001001111001100011","0011000011011001111001010","1101001000100111111111111","0000000010000101100011000","1111111010110001101010001","1000001001100101100010001","1011101000111100111111110","1011101000101000101011010","1011101010101110111100011","1000001001111011010110011","1111111010000111110110001"];

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
        watermark:        { w: 600, h: 120, fontSize: 60, color: '#ffffff', opacity: 0.06, text: '' },

        // ── AT-456 §B.9-1 — listing facts (plain text fields, hidden on a real ad when the listing has no value) ──
        erf_size:         { w: 220, h: 36, fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '800 m²' },
        levy:             { w: 220, h: 36, fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'R 1 250 pm' },
        rates_taxes:      { w: 260, h: 36, fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'R 900 pm' },
        price_per_m2:     { w: 240, h: 36, fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'R 7 656 per m²' },
        pet_friendly:     { w: 180, h: 36, fontSize: 14, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'Pet friendly' },
        available_from:   { w: 300, h: 36, fontSize: 14, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'Available from 1 Nov 2026' },
        rent_per_month:   { w: 300, h: 60, fontSize: 36, fontWeight: '800', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: -0.02, padding: 8, preview: 'R 12 500 per month' },
        show_day:         { w: 340, h: 36, fontSize: 14, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'Sat 25 Oct · 10:00–13:00' },
        auction_date:     { w: 340, h: 36, fontSize: 14, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '12 Nov 2026 · 11:00' },
        auction_venue:    { w: 340, h: 36, fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'Sample Venue, Sample Town' },

        // ── AT-456 §B.9-1 — new elements ──
        collage:          { w: 560, h: 380, objectFit: 'cover', borderRadius: 0, collagePreset: '3_hero_left', collageGap: 8, collageFirst: 1 },
        ribbon:           { w: 200, h: 200, fontSize: 18, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 6, bgColor: '#e63946', bgOpacity: 1, text: 'JUST LISTED', ribbonCorner: 'tl' },
        qr:               { w: 160, h: 160, qrDark: '#000000', qrLight: '#ffffff' },
        feature_pills:    { w: 520, h: 48, fontSize: 15, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 8, bgColor: '#00b4d8', bgOpacity: 1, borderRadius: 999, pillCount: 4, pillDirection: 'row', pillGap: 10 }
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
        { type: 'collage', group: 'image', label: 'Photo Collage', iconBg: '#1d4ed8' },
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
        { type: 'feature_pills', group: 'property', label: 'Feature Pills', iconBg: '#b45309' },
        { type: 'erf_size',      group: 'property', label: 'Erf Size',     iconBg: '#065f46' },
        { type: 'price_per_m2',  group: 'property', label: 'Price per m²', iconBg: '#e63946' },
        { type: 'rent_per_month', group: 'property', label: 'Rent per Month', iconBg: '#e63946' },
        { type: 'levy',          group: 'property', label: 'Levy per Month', iconBg: '#475569' },
        { type: 'rates_taxes',   group: 'property', label: 'Rates & Taxes per Month', iconBg: '#475569' },
        { type: 'pet_friendly',  group: 'property', label: 'Pet Friendly', iconBg: '#0369a1' },
        { type: 'available_from', group: 'property', label: 'Available From', iconBg: '#0369a1' },
        { type: 'show_day',      group: 'property', label: 'Show Day',     iconBg: '#b45309' },
        { type: 'auction_date',  group: 'property', label: 'Auction Date', iconBg: '#b45309' },
        { type: 'auction_venue', group: 'property', label: 'Auction Venue', iconBg: '#b45309' },
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
        { type: 'qr',          group: 'branding', label: 'QR Code to Listing',  iconBg: '#0b2a4a' },
        { type: 'custom_text', group: 'decorative', label: 'Custom Text',  iconBg: '#6d28d9' },
        { type: 'badge',       group: 'decorative', label: 'Badge / Pill', iconBg: '#00b4d8' },
        { type: 'line',        group: 'decorative', label: 'Divider Line', iconBg: '#334155' },
        { type: 'shape',       group: 'decorative', label: 'Shape',        iconBg: '#334155' },
        { type: 'color_block', group: 'decorative', label: 'Colour Block', iconBg: '#334155' },
        { type: 'gradient',    group: 'decorative', label: 'Gradient',     iconBg: '#334155' },
        { type: 'ribbon',      group: 'decorative', label: 'Corner Ribbon', iconBg: '#e63946' }
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
        tag:     '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M21.4 11.6 12.4 2.6A2 2 0 0 0 11 2H4a2 2 0 0 0-2 2v7c0 .5.2 1 .6 1.4l9 9c.8.8 2 .8 2.8 0l7-7a2 2 0 0 0 0-2.8zM7 8.5A1.5 1.5 0 1 1 7 5.5a1.5 1.5 0 0 1 0 3z"/></svg>',
        // AT-456 §B.9-1 — 35 more, drawn from Material Design Icons (Pictogrammers, Apache-2.0 licence,
        // https://pictogrammers.com/library/mdi/): the same single-path 24px filled style as the set above,
        // no brand marks. Keys are lower_snake_case.
        sea_view: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M20,12H22V14H20C18.62,14 17.26,13.65 16,13C13.5,14.3 10.5,14.3 8,13C6.74,13.65 5.37,14 4,14H2V12H4C5.39,12 6.78,11.53 8,10.67C10.44,12.38 13.56,12.38 16,10.67C17.22,11.53 18.61,12 20,12M20,6H22V8H20C18.62,8 17.26,7.65 16,7C13.5,8.3 10.5,8.3 8,7C6.74,7.65 5.37,8 4,8H2V6H4C5.39,6 6.78,5.53 8,4.67C10.44,6.38 13.56,6.38 16,4.67C17.22,5.53 18.61,6 20,6M20,18H22V20H20C18.62,20 17.26,19.65 16,19C13.5,20.3 10.5,20.3 8,19C6.74,19.65 5.37,20 4,20H2V18H4C5.39,18 6.78,17.53 8,16.67C10.44,18.38 13.56,18.38 16,16.67C17.22,17.53 18.61,18 20,18Z"/></svg>',
        mountain_view: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M14,6L10.25,11L13.1,14.8L11.5,16C9.81,13.75 7,10 7,10L1,18H23L14,6Z"/></svg>',
        pet_friendly: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M8.35,3C9.53,2.83 10.78,4.12 11.14,5.9C11.5,7.67 10.85,9.25 9.67,9.43C8.5,9.61 7.24,8.32 6.87,6.54C6.5,4.77 7.17,3.19 8.35,3M15.5,3C16.69,3.19 17.35,4.77 17,6.54C16.62,8.32 15.37,9.61 14.19,9.43C13,9.25 12.35,7.67 12.72,5.9C13.08,4.12 14.33,2.83 15.5,3M3,7.6C4.14,7.11 5.69,8 6.5,9.55C7.26,11.13 7,12.79 5.87,13.28C4.74,13.77 3.2,12.89 2.41,11.32C1.62,9.75 1.9,8.08 3,7.6M21,7.6C22.1,8.08 22.38,9.75 21.59,11.32C20.8,12.89 19.26,13.77 18.13,13.28C17,12.79 16.74,11.13 17.5,9.55C18.31,8 19.86,7.11 21,7.6M19.33,18.38C19.37,19.32 18.65,20.36 17.79,20.75C16,21.57 13.88,19.87 11.89,19.87C9.9,19.87 7.76,21.64 6,20.75C5,20.26 4.31,18.96 4.44,17.88C4.62,16.39 6.41,15.59 7.47,14.5C8.88,13.09 9.88,10.44 11.89,10.44C13.89,10.44 14.95,13.05 16.3,14.5C17.41,15.72 19.26,16.75 19.33,18.38Z"/></svg>',
        solar: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M11.45,2V5.55L15,3.77L11.45,2M10.45,8L8,10.46L11.75,11.71L10.45,8M2,11.45L3.77,15L5.55,11.45H2M10,2H2V10C2.57,10.17 3.17,10.25 3.77,10.25C7.35,10.26 10.26,7.35 10.27,3.75C10.26,3.16 10.17,2.57 10,2M17,22V16H14L19,7V13H22L17,22Z"/></svg>',
        security: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M11,13H13V16H16V11H18L12,6L6,11H8V16H11V13M12,1L21,5V11C21,16.55 17.16,21.74 12,23C6.84,21.74 3,16.55 3,11V5L12,1Z"/></svg>',
        cctv: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M6.03 12.03L8.03 15.5L5.5 18.68L2 12.62L6.03 12.03M17 18V15.29C17.88 14.9 18.5 14.03 18.5 13C18.5 12.43 18.3 11.9 17.97 11.5L19.94 10.35C20.95 9.76 21.3 8.47 20.71 7.46L19.33 5.06C18.74 4.05 17.45 3.7 16.44 4.28L8.31 9C7.36 9.53 7.03 10.75 7.58 11.71L9.08 14.31C9.63 15.26 10.86 15.59 11.81 15.04L13.69 13.96C13.94 14.55 14.41 15.03 15 15.29V18C15 19.1 15.9 20 17 20H22V18H17Z"/></svg>',
        alarm: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M21,19V20H3V19L5,17V11C5,7.9 7.03,5.17 10,4.29C10,4.19 10,4.1 10,4A2,2 0 0,1 12,2A2,2 0 0,1 14,4C14,4.1 14,4.19 14,4.29C16.97,5.17 19,7.9 19,11V17L21,19M14,21A2,2 0 0,1 12,23A2,2 0 0,1 10,21"/></svg>',
        braai: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M8.06,2C7.88,3.17 8.17,4.16 8.95,4.97C9.45,5.47 9.61,6.14 9.42,7H10.41C10.53,6.45 10.55,6 10.45,5.55C10.36,5.13 10.05,4.63 9.5,4.03C9.05,3.47 8.89,2.8 9.05,2H8.06M10.55,2C10.36,3.17 10.66,4.16 11.44,4.97C11.94,5.47 12.09,6.14 11.91,7H12.89C13,6.45 13.03,6 12.94,5.55C12.84,5.13 12.53,4.63 12,4.03C11.53,3.47 11.38,2.8 11.53,2H10.55M13.08,2C12.89,3.17 13.19,4.16 13.97,4.97C14.47,5.47 14.61,6.14 14.39,7H15.42C15.55,6.45 15.56,6 15.47,5.55C15.38,5.13 15.06,4.63 14.53,4.03C14.06,3.47 13.91,2.8 14.06,2H13.08M5,8C5,9.42 5.39,10.7 6.14,11.84C6.87,12.96 7.91,13.85 9.14,14.39L5.16,20.44C5.06,20.56 5,20.75 5,21C5,21.41 5.16,21.69 5.44,21.84C5.56,21.94 5.75,22 6,22C6.41,22 6.69,21.84 6.84,21.56L7.83,19.97H14.2C14.41,20.55 14.79,21.05 15.28,21.42C15.78,21.8 16.36,22 17,22C17.83,22 18.53,21.69 19.13,21.09C19.72,20.5 20,19.8 20,19C20,18.17 19.72,17.47 19.13,16.88C18.53,16.28 17.83,16 17,16C16.36,16 15.78,16.17 15.28,16.55C14.78,16.92 14.42,17.41 14.2,18H9.14L11.11,14.95C11.27,15 11.56,15 12,15C12.44,15 12.73,15 12.89,14.95L13.88,16.5C14.29,15.96 14.84,15.54 15.47,15.28L14.91,14.39C16.03,13.89 17,13 17.79,11.77C18.59,10.5 19,9.27 19,8H5M17,18C17.3,18 17.53,18.09 17.72,18.28C17.91,18.47 18,18.72 18,19C18,19.27 17.91,19.5 17.72,19.71C17.54,19.91 17.28,20 17,20C16.74,20 16.5,19.91 16.29,19.71C16.09,19.5 16,19.26 16,19C16,18.7 16.09,18.47 16.29,18.28C16.5,18.09 16.73,18 17,18Z"/></svg>',
        fireplace: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M22,22H2V20H22V22M22,6H2V3H22V6M20,7V19H17V11C17,11 14.5,10 12,10C9.5,10 7,11 7,11V19H4V7H20M14.5,14.67H14.47L14.81,15.22L14.87,15.34C15.29,16.35 15,17.5 14.21,18.24C13.5,18.9 12.5,19.07 11.58,18.95C10.71,18.84 9.9,18.29 9.45,17.53C9.3,17.3 9.19,17.03 9.13,16.77L9,16.11C8.96,15.15 9.34,14.14 10.06,13.54C9.73,14.26 9.81,15.16 10.3,15.79L10.36,15.87C10.44,15.94 10.55,15.97 10.64,15.92C10.73,15.89 10.8,15.8 10.8,15.7L10.76,15.56C10.23,14.17 10.68,12.55 11.79,11.63C12.1,11.38 12.5,11.15 12.87,11.05C12.46,11.87 12.61,12.93 13.25,13.57L14.14,14.3L14.5,14.67M13.11,17.44V17.44C13.37,17.2 13.53,16.8 13.5,16.44V16.25C13.38,15.65 12.85,15.46 12.5,15L12.26,14.55C12.13,14.85 12.12,15.13 12.17,15.46C12.23,15.8 12.37,16.09 12.29,16.44C12.2,16.83 11.9,17.22 11.37,17.35C11.67,17.64 12.15,17.87 12.64,17.71L13.11,17.44Z"/></svg>',
        air_con: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M6.59,0.66C8.93,-1.15 11.47,1.06 12.04,4.5C12.47,4.5 12.89,4.62 13.27,4.84C13.79,4.24 14.25,3.42 14.07,2.5C13.65,0.35 16.06,-1.39 18.35,1.58C20.16,3.92 17.95,6.46 14.5,7.03C14.5,7.46 14.39,7.89 14.16,8.27C14.76,8.78 15.58,9.24 16.5,9.06C18.63,8.64 20.38,11.04 17.41,13.34C15.07,15.15 12.53,12.94 11.96,9.5C11.53,9.5 11.11,9.37 10.74,9.15C10.22,9.75 9.75,10.58 9.93,11.5C10.35,13.64 7.94,15.39 5.65,12.42C3.83,10.07 6.05,7.53 9.5,6.97C9.5,6.54 9.63,6.12 9.85,5.74C9.25,5.23 8.43,4.76 7.5,4.94C5.37,5.36 3.62,2.96 6.59,0.66M5,16H7A2,2 0 0,1 9,18V24H7V22H5V24H3V18A2,2 0 0,1 5,16M5,18V20H7V18H5M12.93,16H15L12.07,24H10L12.93,16M18,16H21V18H18V22H21V24H18A2,2 0 0,1 16,22V18A2,2 0 0,1 18,16Z"/></svg>',
        garden: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M3,13A9,9 0 0,0 12,22C12,17 7.97,13 3,13M12,5.5A2.5,2.5 0 0,1 14.5,8A2.5,2.5 0 0,1 12,10.5A2.5,2.5 0 0,1 9.5,8A2.5,2.5 0 0,1 12,5.5M5.6,10.25A2.5,2.5 0 0,0 8.1,12.75C8.63,12.75 9.12,12.58 9.5,12.31C9.5,12.37 9.5,12.43 9.5,12.5A2.5,2.5 0 0,0 12,15A2.5,2.5 0 0,0 14.5,12.5C14.5,12.43 14.5,12.37 14.5,12.31C14.88,12.58 15.37,12.75 15.9,12.75C17.28,12.75 18.4,11.63 18.4,10.25C18.4,9.25 17.81,8.4 16.97,8C17.81,7.6 18.4,6.74 18.4,5.75C18.4,4.37 17.28,3.25 15.9,3.25C15.37,3.25 14.88,3.41 14.5,3.69C14.5,3.63 14.5,3.56 14.5,3.5A2.5,2.5 0 0,0 12,1A2.5,2.5 0 0,0 9.5,3.5C9.5,3.56 9.5,3.63 9.5,3.69C9.12,3.41 8.63,3.25 8.1,3.25A2.5,2.5 0 0,0 5.6,5.75C5.6,6.74 6.19,7.6 7.03,8C6.19,8.4 5.6,9.25 5.6,10.25M12,22A9,9 0 0,0 21,13C16,13 12,17 12,22Z"/></svg>',
        flatlet: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M17 14H19V17H22V19H19V22H17V19H14V17H17V14M12 3L22 12H18C14.69 12 12 14.69 12 18C12 18.7 12.12 19.37 12.34 20H5V12H2L12 3Z"/></svg>',
        wifi: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,21L15.6,16.2C14.6,15.45 13.35,15 12,15C10.65,15 9.4,15.45 8.4,16.2L12,21M12,3C7.95,3 4.21,4.34 1.2,6.6L3,9C5.5,7.12 8.62,6 12,6C15.38,6 18.5,7.12 21,9L22.8,6.6C19.79,4.34 16.05,3 12,3M12,9C9.3,9 6.81,9.89 4.8,11.4L6.6,13.8C8.1,12.67 9.97,12 12,12C14.03,12 15.9,12.67 17.4,13.8L19.2,11.4C17.19,9.89 14.7,9 12,9Z"/></svg>',
        golf_estate: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M19.5,18A1.5,1.5 0 0,1 21,19.5A1.5,1.5 0 0,1 19.5,21A1.5,1.5 0 0,1 18,19.5A1.5,1.5 0 0,1 19.5,18M17,5.92L11,9V18.03C13.84,18.19 16,19 16,20C16,21.1 13.31,22 10,22C6.69,22 4,21.1 4,20C4,19.26 5.21,18.62 7,18.27V20H9V2L17,5.92Z"/></svg>',
        school: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,3L1,9L12,15L21,10.09V17H23V9M5,13.18V17.18L12,21L19,17.18V13.18L12,17L5,13.18Z"/></svg>',
        shopping: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,13A5,5 0 0,1 7,8H9A3,3 0 0,0 12,11A3,3 0 0,0 15,8H17A5,5 0 0,1 12,13M12,3A3,3 0 0,1 15,6H9A3,3 0 0,1 12,3M19,6H17A5,5 0 0,0 12,1A5,5 0 0,0 7,6H5C3.89,6 3,6.89 3,8V20A2,2 0 0,0 5,22H19A2,2 0 0,0 21,20V8C21,6.89 20.1,6 19,6Z"/></svg>',
        beach: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M15,18.54C17.13,18.21 19.5,18 22,18V22H5C5,21.35 8.2,19.86 13,18.9V12.4C12.16,12.65 11.45,13.21 11,13.95C10.39,12.93 9.27,12.25 8,12.25C6.73,12.25 5.61,12.93 5,13.95C5.03,10.37 8.5,7.43 13,7.04V7A1,1 0 0,1 14,6A1,1 0 0,1 15,7V7.04C19.5,7.43 22.96,10.37 23,13.95C22.39,12.93 21.27,12.25 20,12.25C18.73,12.25 17.61,12.93 17,13.95C16.55,13.21 15.84,12.65 15,12.39V18.54M7,2A5,5 0 0,1 2,7V2H7Z"/></svg>',
        estate_gated: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M9 6V11H7V7H5V11H3V9H1V21H3V19H5V21H7V19H9V21H11V19H13V21H15V19H17V21H19V19H21V21H23V9H21V11H19V7H17V11H15V6H13V11H11V6H9M3 13H5V17H3V13M7 13H9V17H7V13M11 13H13V17H11V13M15 13H17V17H15V13M19 13H21V17H19V13Z"/></svg>',
        borehole: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M19,14.5C19,14.5 21,16.67 21,18A2,2 0 0,1 19,20A2,2 0 0,1 17,18C17,16.67 19,14.5 19,14.5M5,18V9A2,2 0 0,1 3,7A2,2 0 0,1 5,5V4A2,2 0 0,1 7,2H9A2,2 0 0,1 11,4V5H19A2,2 0 0,1 21,7V9L21,11A1,1 0 0,1 22,12A1,1 0 0,1 21,13H17A1,1 0 0,1 16,12A1,1 0 0,1 17,11V9H11V18H12A2,2 0 0,1 14,20V22H2V20A2,2 0 0,1 4,18H5Z"/></svg>',
        backup_power: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M16.67,4H15V2H9V4H7.33A1.33,1.33 0 0,0 6,5.33V20.66C6,21.4 6.6,22 7.33,22H16.66C17.4,22 18,21.4 18,20.67V5.33C18,4.6 17.4,4 16.67,4M11,20V14.5H9L13,7V12.5H15"/></svg>',
        lift: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M7,2L11,6H8V10H6V6H3L7,2M17,10L13,6H16V2H18V6H21L17,10M7,12H17A2,2 0 0,1 19,14V20A2,2 0 0,1 17,22H7A2,2 0 0,1 5,20V14A2,2 0 0,1 7,12M7,14V20H17V14H7Z"/></svg>',
        gym: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M20.57,14.86L22,13.43L20.57,12L17,15.57L8.43,7L12,3.43L10.57,2L9.14,3.43L7.71,2L5.57,4.14L4.14,2.71L2.71,4.14L4.14,5.57L2,7.71L3.43,9.14L2,10.57L3.43,12L7,8.43L15.57,17L12,20.57L13.43,22L14.86,20.57L16.29,22L18.43,19.86L19.86,21.29L21.29,19.86L19.86,18.43L22,16.29L20.57,14.86Z"/></svg>',
        study: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M3 6H21C21.55 6 22 6.45 22 7C22 7.55 21.55 8 21 8V19H19V17H15V19H13V8H5V19H3V8C2.45 8 2 7.55 2 7C2 6.45 2.45 6 3 6M16 10.5V11H18V10.5C18 10.22 17.78 10 17.5 10H16.5C16.22 10 16 10.22 16 10.5M16 14.5V15H18V14.5C18 14.22 17.78 14 17.5 14H16.5C16.22 14 16 14.22 16 14.5Z"/></svg>',
        kitchen: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M11,9H9V2H7V9H5V2H3V9C3,11.12 4.66,12.84 6.75,12.97V22H9.25V12.97C11.34,12.84 13,11.12 13,9V2H11V9M16,6V14H18.5V22H21V2C18.24,2 16,4.24 16,6Z"/></svg>',
        laundry: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M14.83,11.17C16.39,12.73 16.39,15.27 14.83,16.83C13.27,18.39 10.73,18.39 9.17,16.83L14.83,11.17M6,2H18A2,2 0 0,1 20,4V20A2,2 0 0,1 18,22H6A2,2 0 0,1 4,20V4A2,2 0 0,1 6,2M7,4A1,1 0 0,0 6,5A1,1 0 0,0 7,6A1,1 0 0,0 8,5A1,1 0 0,0 7,4M10,4A1,1 0 0,0 9,5A1,1 0 0,0 10,6A1,1 0 0,0 11,5A1,1 0 0,0 10,4M12,8A6,6 0 0,0 6,14A6,6 0 0,0 12,20A6,6 0 0,0 18,14A6,6 0 0,0 12,8Z"/></svg>',
        balcony: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M10 10V12H8V10H10M16 12V10H14V12H16M21 14V22H3V14H4V10C4 5.58 7.58 2 12 2S20 5.58 20 10V14H21M7 16H5V20H7V16M11 16H9V20H11V16M11 4.08C8.16 4.56 6 7.03 6 10V14H11V4.08M13 14H18V10C18 7.03 15.84 4.56 13 4.08V14M15 16H13V20H15V16M19 16H17V20H19V16Z"/></svg>',
        calendar: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M19,19H5V8H19M16,1V3H8V1H6V3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3H18V1M17,12H12V17H17V12Z"/></svg>',
        clock: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M16.2,16.2L11,13V7H12.5V12.2L17,14.9L16.2,16.2Z"/></svg>',
        phone: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M6.62,10.79C8.06,13.62 10.38,15.94 13.21,17.38L15.41,15.18C15.69,14.9 16.08,14.82 16.43,14.93C17.55,15.3 18.75,15.5 20,15.5A1,1 0 0,1 21,16.5V20A1,1 0 0,1 20,21A17,17 0 0,1 3,4A1,1 0 0,1 4,3H7.5A1,1 0 0,1 8.5,4C8.5,5.25 8.7,6.45 9.07,7.57C9.18,7.92 9.1,8.31 8.82,8.59L6.62,10.79Z"/></svg>',
        email: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M20,8L12,13L4,8V6L12,11L20,6M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4Z"/></svg>',
        chat: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,3C17.5,3 22,6.58 22,11C22,15.42 17.5,19 12,19C10.76,19 9.57,18.82 8.47,18.5C5.55,21 2,21 2,21C4.33,18.67 4.7,17.1 4.75,16.5C3.05,15.07 2,13.13 2,11C2,6.58 6.5,3 12,3Z"/></svg>',
        star: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>',
        heart: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M9,20.42L2.79,14.21L5.62,11.38L9,14.77L18.88,4.88L21.71,7.71L9,20.42Z"/></svg>',
        sparkle: '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="currentColor"><path d="M19,1L17.74,3.75L15,5L17.74,6.26L19,9L20.25,6.26L23,5L20.25,3.75M9,4L6.5,9.5L1,12L6.5,14.5L9,20L11.5,14.5L17,12L11.5,9.5M19,15L17.74,17.74L15,19L17.74,20.25L19,23L20.25,20.25L23,19L20.25,17.74"/></svg>'
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
        { key: 'tag',     label: 'Price Tag' },
        { key: 'sea_view', label: 'Sea view' },
        { key: 'mountain_view', label: 'Mountain view' },
        { key: 'pet_friendly', label: 'Pet friendly' },
        { key: 'solar', label: 'Solar' },
        { key: 'security', label: 'Security' },
        { key: 'cctv', label: 'CCTV' },
        { key: 'alarm', label: 'Alarm' },
        { key: 'braai', label: 'Braai' },
        { key: 'fireplace', label: 'Fireplace' },
        { key: 'air_con', label: 'Air-con' },
        { key: 'garden', label: 'Garden (flower)' },
        { key: 'flatlet', label: 'Flatlet' },
        { key: 'wifi', label: 'Wi-Fi / fibre' },
        { key: 'golf_estate', label: 'Golf estate' },
        { key: 'school', label: 'School nearby' },
        { key: 'shopping', label: 'Shopping' },
        { key: 'beach', label: 'Beach' },
        { key: 'estate_gated', label: 'Estate / gated' },
        { key: 'borehole', label: 'Borehole / water' },
        { key: 'backup_power', label: 'Backup power' },
        { key: 'lift', label: 'Lift' },
        { key: 'gym', label: 'Gym' },
        { key: 'study', label: 'Study' },
        { key: 'kitchen', label: 'Kitchen' },
        { key: 'laundry', label: 'Laundry' },
        { key: 'balcony', label: 'Balcony' },
        { key: 'calendar', label: 'Calendar' },
        { key: 'clock', label: 'Clock' },
        { key: 'phone', label: 'Phone' },
        { key: 'email', label: 'Email' },
        { key: 'chat', label: 'Chat' },
        { key: 'star', label: 'Star' },
        { key: 'heart', label: 'Heart' },
        { key: 'check', label: 'Tick' },
        { key: 'sparkle', label: 'Sparkle' }
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

    /** A clip-path cuts its own box-shadow away, so those shapes cannot carry one (nor can the SVG-drawn decorative ones). */
    function isClipShape(el) { return el.field === 'shape' && (!!SHAPE_CLIPS[el.shapeType] || !!DECOR_SHAPES[el.shapeType]); }
    function canShadow(el) {
        if (isClipShape(el)) return false;
        if (el.field === 'ribbon' || el.field === 'feature_pills') return false;   // the shadow would wrap the whole box, not the artwork
        if (el.field === 'line' && (el.lineStyle === 'dashed' || el.lineStyle === 'dotted')) return false;
        return true;
    }

    function fontStack(name) {
        for (var i = 0; i < FONTS.length; i++) {
            if (FONTS[i].name === name) {
                if (FONTS[i].lazy) requestFont(name);   // §B.9-1 — only a template that draws it pays for it
                return FONTS[i].stack;
            }
        }
        return FONTS[0].stack;
    }

    /* §B.9-1 — lazy Google Fonts. The stylesheet for a lazy family is added the first time any element
     * asks for its stack; fontsReady() lets a capture wait for it. Never throws, never blocks rendering. */
    var _fontLinks = {};
    function requestFont(name) {
        if (_fontLinks[name] || typeof document === 'undefined' || !document.head) return;
        var map = root.CoreXAdLazyFonts || {};
        if (!map[name]) return;
        var base = root.CoreXAdLazyFontsBase || 'https://fonts.bunny.net/css?display=swap&family=';
        _fontLinks[name] = new Promise(function (resolve) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = base + map[name];
            link.onload = function () { resolve(true); };
            link.onerror = function () { resolve(false); };
            document.head.appendChild(link);
            setTimeout(function () { resolve(false); }, 8000);
        });
    }
    function fontsReady() {
        if (typeof document === 'undefined') return Promise.resolve();
        var names = Object.keys(_fontLinks);
        if (!names.length) return Promise.resolve();
        return Promise.all(names.map(function (n) { return _fontLinks[n]; })).then(function () {
            if (!document.fonts || !document.fonts.load) return;
            var loads = [];
            names.forEach(function (n) {
                ['400', '600', '700', '800'].forEach(function (w) {
                    loads.push(document.fonts.load(w + ' 20px "' + n + '"').catch(function () {}));
                });
            });
            return Promise.all(loads);
        }).catch(function () {});
    }

    /**
     * Strict colour gate for every colour a B.9-1 block writes into a style="" attribute: #rgb, #rrggbb,
     * rgb() or rgba() with plain numbers — nothing else. A saved template is user data and the kernel
     * concatenates colours straight into markup, so anything that is not one of those four shapes is
     * replaced by the fallback instead of being passed through.
     */
    function safeColor(v, fallback) {
        var t = String(v === null || v === undefined ? '' : v).trim();
        if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(t)) return t;
        if (/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+|1\.0+)\s*)?\)$/i.test(t)) return t;
        return fallback;
    }
    /** "#abc" / "#aabbcc" / "rgb(1,2,3)" → [r,g,b] (0-255), or null. */
    function parseRgb(c) {
        var t = String(c || '').trim(), m;
        if ((m = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(t))) return [parseInt(m[1] + m[1], 16), parseInt(m[2] + m[2], 16), parseInt(m[3] + m[3], 16)];
        if ((m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(t))) return [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)];
        if ((m = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i.exec(t))) return [Math.min(255, +m[1]), Math.min(255, +m[2]), Math.min(255, +m[3])];
        return null;
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
        } else if (maskCss(el)) {
            // §B.9-1 — a property photo with a mask (rounded / circle / arch / blob). border-radius only:
            // the export (html2canvas) draws it exactly, and the frame border follows the curve.
            s += maskCss(el);
        } else if (el.field === 'collage') {
            s += 'border-radius:0;';     // borderRadius on a collage rounds each photo, not the box
        } else {
            // A shape owns its geometry on the INNER node — the frame must stay square
            // or it rounds a rectangle's corners and draws a box around a clip shape.
            var radius = el.field === 'shape' ? 0 : (el.borderRadius || 0);
            s += 'border-radius:' + radius + 'px;';
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
        if (el.shadowOn && !isTextField(el.field) && el.field !== 'shape' && el.field !== 'line' && canShadow(el)) {
            s += 'box-shadow:' + shadowValue(el) + ';';
        }
        return s;
    }

    /** §B.9-1 — the frame's corner geometry for a masked property photo; '' when there is no mask. */
    function maskCss(el) {
        var m = el.photoMask;
        if (!m || m === 'none' || MASKABLE_FIELDS.indexOf(el.field) === -1) return '';
        if (m === 'rounded') return 'border-radius:' + (el.borderRadius > 0 ? el.borderRadius : 24) + 'px;';
        if (m === 'circle')  return 'border-radius:50%;';
        if (m === 'arch') {
            var rx = Math.round((el.w || 0) / 2);
            var ry = Math.min(rx, Math.round(el.h || 0));
            return 'border-radius:' + rx + 'px ' + rx + 'px 0 0 / ' + ry + 'px ' + ry + 'px 0 0;';
        }
        if (m === 'blob') return 'border-radius:63% 37% 54% 46% / 55% 48% 52% 45%;';
        return '';
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

        // §B.9-1 listing facts: the server sends ready-to-print text, or '' when the listing has no value.
        // A real ad then prints nothing (and renderLayout leaves the element off); the builder shows the
        // preview copy. A label ("Levy: R 1 250 pm") goes in front unless showLabel is switched off.
        if (FACT_FIELDS.indexOf(f) !== -1) {
            var fv = prop[f];
            var shown = (fv === undefined || fv === null || fv === '') ? (opts.placeholders ? (el.preview || '') : '') : String(fv);
            if (shown === '') return '';
            return (FACT_LABELS[f] && el.showLabel !== false) ? FACT_LABELS[f] + ': ' + shown : shown;
        }

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
     *
     * §15.2 — an Agent Image element with `removeBackground` on now prefers the
     * SERVER-STORED AI cutout (`prop.agent_avatar_cutout`/`agent_2_avatar_cutout`,
     * populated by Property::adData() from User::profilePhotoCutoutUrl()) over the
     * plain photo. Falls back to the plain photo whenever no cutout is recorded
     * (never attempted, still processing, agency disabled, or the API call
     * failed) — this can only ever ADD a cutout, never remove the original as a
     * fallback, so an ad can never render blank. Supersedes the client-side
     * flood-fill cutout (stripBackground()/_processBackgroundRemoval()) in the
     * live render path — see imgTag().
     */
    function imageSrc(el, prop, opts) {
        prop = prop || {};
        opts = opts || {};
        var f = el.field;
        var ov = opts.overrides || {};

        if (f === 'custom_image' || f === 'custom_video') {
            return ov[el.id] || el.src || null;
        }
        if (isAgentAvatarField(f) && el.removeBackground && prop[f + '_cutout']) {
            return prop[f + '_cutout'];
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

    /* ── §B.9-1 photo effects ────────────────────────────────────────────────
     * One 4×5 colour matrix per effect. The live view runs it through an inline SVG <feColorMatrix>
     * (colour space forced to sRGB); the export runs the SAME numbers over the pixels
     * (applyColorMatrix, called by prepareImagesForCapture) — so canvas and exported picture agree. */
    function effectMatrix(el) {
        var k = el.photoEffect;
        if (!k || k === 'none') return null;
        var R = 0.2126, G = 0.7152, B = 0.0722;
        if (k === 'bw') return [R, G, B, 0, 0,  R, G, B, 0, 0,  R, G, B, 0, 0,  0, 0, 0, 1, 0];
        if (k === 'duotone') {
            var c1 = parseRgb(safeColor(el.duoColor1, '#0b2a4a')) || [11, 42, 74];
            var c2 = parseRgb(safeColor(el.duoColor2, '#f4d58d')) || [244, 213, 141];
            var row = function (i) { var d = (c2[i] - c1[i]) / 255; return [d * R, d * G, d * B, 0, c1[i] / 255]; };
            return [].concat(row(0), row(1), row(2), [0, 0, 0, 1, 0]);
        }
        if (k === 'warm')     return [1.08, 0, 0, 0, 0.03,  0, 1.02, 0, 0, 0.01,  0, 0, 0.88, 0, 0,  0, 0, 0, 1, 0];
        if (k === 'cool')     return [0.9, 0, 0, 0, 0,  0, 1, 0, 0, 0,  0, 0, 1.1, 0, 0.03,  0, 0, 0, 1, 0];
        if (k === 'brighter') return [1.15, 0, 0, 0, 0.05,  0, 1.15, 0, 0, 0.05,  0, 0, 1.15, 0, 0.05,  0, 0, 0, 1, 0];
        return null;
    }
    function matrixString(m) { return m.map(function (n) { return String(Math.round(n * 10000) / 10000); }).join(' '); }

    /** Applies a 4×5 matrix (as above) to canvas ImageData in place — the export half of a photo effect. */
    function applyColorMatrix(data, m) {
        for (var i = 0; i < data.length; i += 4) {
            var r = data[i] / 255, g = data[i + 1] / 255, b = data[i + 2] / 255;
            var nr = m[0] * r + m[1] * g + m[2] * b + m[4];
            var ng = m[5] * r + m[6] * g + m[7] * b + m[9];
            var nb = m[10] * r + m[11] * g + m[12] * b + m[14];
            data[i]     = Math.max(0, Math.min(255, Math.round(nr * 255)));
            data[i + 1] = Math.max(0, Math.min(255, Math.round(ng * 255)));
            data[i + 2] = Math.max(0, Math.min(255, Math.round(nb * 255)));
        }
        return data;
    }

    var _fxSeq = 0;
    /** {defs, style, attr} to put on the <img>(s) of an element with a photo effect; all '' without one. */
    function fxFor(el) {
        var m = EFFECT_FIELDS.indexOf(el.field) !== -1 ? effectMatrix(el) : null;
        if (!m) return { defs: '', style: '', attr: '' };
        var id = 'cxfx' + (++_fxSeq);
        var ms = matrixString(m);
        return {
            defs: '<svg width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">'
                + '<filter id="' + id + '" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%">'
                + '<feColorMatrix type="matrix" values="' + ms + '"/></filter></svg>',
            style: 'filter:url(#' + id + ');',
            attr: ' data-fxm="' + ms + '"'
        };
    }

    function imgTag(el, src, baseSrc, opts) {
        var f = el.field;
        var fx = fxFor(el);
        var attrs = 'style="width:100%;height:100%;object-fit:' + fit(el) + ';display:block;' + fx.style + '"' + fx.attr;

        // Tag so the generator's "change photo" overlay targets property photos only
        // — a logo or an agent avatar is never swapped from the gallery.
        if (f === 'agency_logo')      attrs += ' class="js-ad-logo"';
        else if (/avatar$/.test(f))   attrs += ' class="js-ad-avatar"';
        else if (opts.tagPhotos)      attrs += ' data-el-id="' + esc(el.id) + '" data-orig-src="' + esc(baseSrc) + '"';

        // §15.2 — background removal is now a SERVER-side AI cutout, resolved by
        // imageSrc() before this function ever runs (it just decides WHICH src to
        // put in the <img>, exactly like every other image field). No onload hook
        // needed here any more — the client-side flood-fill pipeline this used to
        // trigger (stripBackground()/_processBackgroundRemoval()/_floodFillTransparent()/
        // _fillEnclosedHoles()/_featherAlpha()) is superseded and left in place,
        // unused, pending removal in a later round once the AI path is proven live.

        return fx.defs + '<img src="' + esc(src) + '" ' + attrs + '>';
    }

    /* ── §B.9-1 photo collage ─────────────────────────────────────────────── */

    /** How many photos a preset needs. */
    function collageCount(preset) {
        for (var i = 0; i < COLLAGE_PRESETS.length; i++) if (COLLAGE_PRESETS[i].key === preset) return COLLAGE_PRESETS[i].count;
        return 3;
    }

    /**
     * The preset actually drawn: the agent's choice when the listing has enough photos, otherwise the
     * nearest layout for the number of photos it does have (so a listing with two photos never shows a
     * collage with empty holes). 'single' = one full-frame photo.
     */
    function collageEffectivePreset(preset, available) {
        var want = collageCount(preset);
        if (available >= want) return preset;
        if (available <= 1) return 'single';
        if (available === 2) return (preset === '2_stack' || preset === '3_hero_top') ? '2_stack' : '2_side';
        if (available === 3) return preset === '3_hero_top' ? '3_hero_top' : '3_hero_left';
        return '4_grid';
    }

    /** Pixel rectangles {x,y,w,h} for a preset inside a w×h box with `gap` px between photos. */
    function collageCells(preset, w, h, gap) {
        w = Math.max(1, Math.round(w)); h = Math.max(1, Math.round(h));
        gap = Math.max(0, Math.min(Math.round(gap || 0), Math.floor(Math.min(w, h) / 6)));
        var cells = [];
        // add a cell from fractional edges, snapped to whole pixels so neighbours never overlap or leave seams
        function box(x0, y0, x1, y1) {
            var a = Math.round(x0), b = Math.round(y0);
            cells.push({ x: a, y: b, w: Math.max(1, Math.round(x1) - a), h: Math.max(1, Math.round(y1) - b) });
        }
        var half = (w - gap) / 2, halfH = (h - gap) / 2;
        if (preset === 'single') {
            box(0, 0, w, h);
        } else if (preset === '2_side') {
            box(0, 0, half, h); box(half + gap, 0, w, h);
        } else if (preset === '2_stack') {
            box(0, 0, w, halfH); box(0, halfH + gap, w, h);
        } else if (preset === '3_hero_top') {
            var th = (h - gap) * 0.6;
            box(0, 0, w, th); box(0, th + gap, half, h); box(half + gap, th + gap, w, h);
        } else if (preset === '4_grid') {
            box(0, 0, half, halfH); box(half + gap, 0, w, halfH);
            box(0, halfH + gap, half, h); box(half + gap, halfH + gap, w, h);
        } else if (preset === '5_hero') {
            var hh = (h - gap) * 0.6, q = (w - 3 * gap) / 4;
            box(0, 0, w, hh);
            for (var i = 0; i < 4; i++) box(i * (q + gap), hh + gap, i * (q + gap) + q, h);
        } else {   // '3_hero_left' (also the fallback)
            var lw = (w - gap) * 0.6;
            box(0, 0, lw, h); box(lw + gap, 0, w, halfH); box(lw + gap, halfH + gap, w, h);
        }
        return cells;
    }

    /** The listing photos a collage can use: image_<first> … image_5, blanks dropped. */
    function collagePhotos(el, prop) {
        var first = Math.max(1, Math.min(5, parseInt(el.collageFirst, 10) || 1));
        var out = [];
        for (var i = first; i <= 5; i++) { if (prop['image_' + i]) out.push(prop['image_' + i]); }
        return out;
    }

    function collageHtml(el, prop, opts) {
        var preset = el.collagePreset || '3_hero_left';
        var photos = collagePhotos(el, prop);
        var n = collageCount(preset);
        if (!photos.length && !opts.placeholders) return '';
        var eff = photos.length ? collageEffectivePreset(preset, photos.length) : preset;   // builder with no photos: show the chosen preset empty
        var cells = collageCells(eff, el.w, el.h, def(el.collageGap, 8));
        var rad = Math.max(0, +el.borderRadius || 0);
        var fx = fxFor(el);
        var html = fx.defs + '<div style="position:relative;width:100%;height:100%;">';
        var ov = opts.overrides || {};
        cells.forEach(function (c, i) {
            var base = photos[i];
            // The generator's "change photo" works on a collage too: each cell is its own override slot (<element id>_c<n>).
            var key = el.id + '_c' + i;
            var src = ov[key] || base;
            var tag = (opts.tagPhotos && base) ? ' data-el-id="' + esc(key) + '" data-orig-src="' + esc(base) + '"' : '';
            html += '<div style="position:absolute;left:' + c.x + 'px;top:' + c.y + 'px;width:' + c.w + 'px;height:' + c.h + 'px;overflow:hidden;border-radius:' + rad + 'px;'
                  + 'background:linear-gradient(135deg,#0b2a4a,#143d6e);">'
                  + (src ? '<img src="' + esc(src) + '"' + tag + ' style="width:100%;height:100%;object-fit:cover;display:block;' + fx.style + '"' + fx.attr + '>' : '')
                  + '</div>';
        });
        return html + '</div>';
    }

    /* ── §B.9-1 corner ribbon / sash ──────────────────────────────────────── */

    function ribbonHtml(el) {
        var w = +el.w || 200, h = +el.h || 200, c = el.ribbonCorner || 'tl';
        var L = Math.min(w, h) * 0.75;                       // how far along each edge the band reaches
        var fs = +el.fontSize || 18;
        var t = Math.round(fs * 1.5 + 2 * def(el.padding, 6)); // band thickness
        var len = Math.round(L * Math.SQRT2 + t * 2);          // long enough to run off both edges (the frame clips it)
        var cx, cy, ang;
        if (c === 'tr')      { cx = w - L / 2; cy = L / 2;     ang = 45; }
        else if (c === 'bl') { cx = L / 2;     cy = h - L / 2; ang = 45; }
        else if (c === 'br') { cx = w - L / 2; cy = h - L / 2; ang = -45; }
        else                 { cx = L / 2;     cy = L / 2;     ang = -45; }
        var bg = hexToRgba(safeColor(el.bgColor, '#e63946'), def(el.bgOpacity, 1));
        return '<div style="position:absolute;left:' + Math.round(cx - len / 2) + 'px;top:' + Math.round(cy - t / 2) + 'px;width:' + len + 'px;height:' + t + 'px;'
             + 'display:flex;align-items:center;justify-content:center;overflow:hidden;white-space:nowrap;'
             + 'transform:rotate(' + ang + 'deg);background:' + bg + ';'
             + 'font-family:' + fontStack(el.fontFamily) + ';font-size:' + fs + 'px;font-weight:' + (el.fontWeight || '800') + ';'
             + 'color:' + safeColor(el.color, '#ffffff') + ';text-transform:' + (el.textTransform || 'uppercase') + ';'
             + 'letter-spacing:' + def(el.letterSpacing, 0.08) + 'em;line-height:1;">'
             + esc(el.text || '') + '</div>';
    }

    /* ── §B.9-1 QR code to the listing ────────────────────────────────────── */

    /** rows: ["1011…", …] (1 = dark module). Drawn as ONE inline SVG path so the export reproduces it exactly. */
    function qrHtml(el, prop, opts) {
        var rows = (prop && Array.isArray(prop.qr_matrix) && prop.qr_matrix.length) ? prop.qr_matrix : (opts.placeholders ? QR_SAMPLE : null);
        if (!rows) return '';
        var n = rows.length;
        if (n < 17 || n > 177) return '';
        for (var r = 0; r < n; r++) { if (typeof rows[r] !== 'string' || rows[r].length !== n || /[^01]/.test(rows[r])) return ''; }
        var quiet = 4, total = n + quiet * 2;
        var size = Math.max(8, Math.floor(Math.min(+el.w || 160, +el.h || 160)));
        var d = '';
        for (var y = 0; y < n; y++) {
            var x = 0;
            while (x < n) {
                if (rows[y].charAt(x) === '1') {
                    var run = 1;
                    while (x + run < n && rows[y].charAt(x + run) === '1') run++;
                    d += 'M' + (x + quiet) + ' ' + (y + quiet) + 'h' + run + 'v1h-' + run + 'z';
                    x += run;
                } else { x++; }
            }
        }
        return '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">'
             + '<svg viewBox="0 0 ' + total + ' ' + total + '" width="' + size + '" height="' + size + '" shape-rendering="crispEdges" style="display:block;">'
             + '<rect width="' + total + '" height="' + total + '" fill="' + safeColor(el.qrLight, '#ffffff') + '"/>'
             + '<path d="' + d + '" fill="' + safeColor(el.qrDark, '#000000') + '"/></svg></div>';
    }

    /* ── §B.9-1 key features as separate pills ────────────────────────────── */

    var PILL_SAMPLE = ['Pool', 'Garden', 'Security', 'Solar'];

    /** The pills to draw: up to pillCount (1-4) of the listing's own features — the agent's ticked ones, else the first N. */
    function pillsFor(el, prop, opts) {
        var all = (prop && Array.isArray(prop.features_list)) ? prop.features_list : [];
        var n = Math.max(1, Math.min(4, parseInt(el.pillCount, 10) || 4));
        var chosen = (el.selectedFeatures === null || el.selectedFeatures === undefined)
            ? all
            : all.filter(function (x) { return el.selectedFeatures.indexOf(x) !== -1; });
        if (!chosen.length && opts && opts.placeholders && !all.length) chosen = PILL_SAMPLE;
        return chosen.slice(0, n);
    }

    function pillsHtml(el, prop, opts) {
        var list = pillsFor(el, prop, opts);
        if (!list.length) return '';
        var col = el.pillDirection === 'column';
        var al = el.textAlign === 'center' ? 'center' : (el.textAlign === 'right' ? 'flex-end' : 'flex-start');
        var va = el.verticalAlign === 'bottom' ? 'flex-end' : (el.verticalAlign === 'top' ? 'flex-start' : 'center');
        var p = def(el.padding, 8);
        var box = 'width:100%;height:100%;display:flex;flex-direction:' + (col ? 'column' : 'row') + ';flex-wrap:' + (col ? 'nowrap' : 'wrap') + ';gap:' + def(el.pillGap, 10) + 'px;'
                + (col ? 'align-items:' + al + ';justify-content:' + va + ';' : 'justify-content:' + al + ';align-items:center;align-content:' + va + ';');
        var pill = 'display:inline-flex;align-items:center;white-space:nowrap;'
                 + 'padding:' + Math.round(p * 0.6) + 'px ' + Math.round(p * 1.6) + 'px;'
                 + 'background:' + hexToRgba(safeColor(el.bgColor, '#00b4d8'), def(el.bgOpacity, 1)) + ';'
                 + 'border-radius:' + def(el.borderRadius, 999) + 'px;'
                 + 'font-family:' + fontStack(el.fontFamily) + ';font-size:' + (el.fontSize || 15) + 'px;font-weight:' + (el.fontWeight || '700') + ';'
                 + 'color:' + safeColor(el.color, '#ffffff') + ';text-transform:' + (el.textTransform || 'none') + ';'
                 + 'letter-spacing:' + def(el.letterSpacing, 0) + 'em;line-height:1.2;';
        return '<div style="' + box + '">' + list.map(function (t) { return '<span style="' + pill + '">' + esc(t) + '</span>'; }).join('') + '</div>';
    }

    /* ── §B.9-1 decorative shapes + line styles ───────────────────────────── */

    /** triangle / diamond / pentagon / hexagon / star / chevron: a filled SVG polygon stretched to the box. */
    function clipShapeHtml(el) {
        return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" width="100%" height="100%" style="display:block;">'
             + '<polygon points="' + SHAPE_POINTS[el.shapeType] + '" fill="' + safeColor(el.bg, '#00b4d8') + '" fill-opacity="' + def(el.opacity, 1) + '"/></svg>';
    }

    /** A missing agent photo on a REAL ad inside a polygon mask: the tinted box, drawn as the polygon (clip-path would not export). */
    function emptyClipPhotoHtml(el) {
        var gid = 'cxe' + String(el.id).replace(/[^a-zA-Z0-9]/g, '');
        return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" width="100%" height="100%" style="display:block;">'
             + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0b2a4a"/><stop offset="1" stop-color="#143d6e"/></linearGradient></defs>'
             + '<polygon points="' + SHAPE_POINTS[el.shapeType] + '" fill="url(#' + gid + ')"/></svg>';
    }

    function decorShapeHtml(el) {
        return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" width="100%" height="100%" style="display:block;">'
             + '<path d="' + DECOR_SHAPES[el.shapeType] + '" fill="' + safeColor(el.bg, '#00b4d8') + '" fill-opacity="' + def(el.opacity, 1) + '"/></svg>';
    }

    /** A little picture of any shape type for the builder's picker (works for the plain and the decorative ones). */
    function shapeSwatchHtml(type) {
        if (DECOR_SHAPES[type]) {
            return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" width="100%" height="100%" style="display:block;"><path d="' + DECOR_SHAPES[type] + '" fill="#9fb4c9"/></svg>';
        }
        return '<span style="' + shapeCss({ shapeType: type, bg: '#9fb4c9', opacity: 1, borderRadius: 9 }) + '"></span>';
    }

    /** Dashed / dotted line as inline SVG (html2canvas draws dashed borders at a slightly different phase, SVG matches exactly). */
    function dashedLineHtml(el) {
        var bw = Math.max(1, +el.borderWidth || 3), w = Math.max(2, Math.round(+el.w || 300));
        var dash = el.lineStyle === 'dotted' ? '0 ' + (bw * 2) : (bw * 3) + ' ' + (bw * 2);
        return '<div style="width:100%;height:100%;display:flex;align-items:center;">'
             + '<svg viewBox="0 0 ' + w + ' ' + bw + '" width="' + w + '" height="' + bw + '" style="display:block;">'
             + '<line x1="' + (bw / 2) + '" y1="' + (bw / 2) + '" x2="' + (w - bw / 2) + '" y2="' + (bw / 2) + '" stroke="' + safeColor(el.color, '#00b4d8') + '" stroke-width="' + bw + '"'
             + ' stroke-dasharray="' + dash + '" stroke-linecap="' + (el.lineStyle === 'dotted' ? 'round' : 'butt') + '"/></svg></div>';
    }

    /**
     * True when a block has nothing to show on a REAL ad (placeholders off): a listing fact the listing
     * does not have, feature pills with no features, a QR with no link, a collage with no photos, a
     * ribbon with no words. renderLayout leaves such an element off the ad altogether — never an empty
     * pill, "R 0" or "null". Elements that existed before B.9-1 are never blank (their behaviour is unchanged).
     */
    function isBlankElement(el, prop, opts) {
        opts = opts || {};
        if (opts.placeholders) return false;
        prop = prop || {};
        var f = el.field;
        if (FACT_FIELDS.indexOf(f) !== -1) return textValue(el, prop, opts) === '';
        if (f === 'feature_pills') return pillsFor(el, prop, opts).length === 0;
        if (f === 'qr')            return qrHtml(el, prop, opts) === '';
        if (f === 'collage')       return collagePhotos(el, prop).length === 0;
        if (f === 'ribbon')        return String(el.text || '').trim() === '';
        return false;
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
        // §B.9-1 — a lazy font a template uses must have loaded before the capture draws text in it.
        return fontsReady().then(function () { return _prepareImages(root); }).then(function (restoreImages) {
            var restoreFrames = bakePolygonFrameBorders(root);
            return function () { restoreFrames(); restoreImages(); };
        });
    }

    /**
     * AT-456 §B.9 leftovers — a frame border (`frameBorderWidth`) on a polygon-masked Agent Image. On the canvas the frame's
     * `clip-path` cuts its own CSS border to the polygon; html2canvas ignores clip-path and drew the whole rectangle.
     * For the export only: the frame's own border colour is made transparent (so layout does not move) and the very same
     * ring — the border band of the box, clipped to the same polygon — is drawn as one inline SVG sibling right after the
     * frame (same box, rotation, opacity and stacking), which html2canvas renders faithfully. The canvas is never touched;
     * the returned function puts everything back.
     */
    function bakePolygonFrameBorders(root) {
        var undo = [];
        var frames = Array.prototype.slice.call(root.querySelectorAll('*')).filter(function (n) {
            return n.style && n.style.clipPath && n.style.clipPath.indexOf('polygon(') === 0 && n.parentNode;
        });
        frames.forEach(function (frame, idx) {
            try {
                var cs = window.getComputedStyle(frame);
                var bw = parseFloat(cs.borderTopWidth) || 0;
                var W = frame.offsetWidth, H = frame.offsetHeight;
                var pts = clipToPoints(frame.style.clipPath);
                if (!(bw > 0) || !W || !H || !pts) return;
                var color = cs.borderTopColor;
                var scaled = pts.split(' ').map(function (p) {
                    var xy = p.split(',');
                    return (parseFloat(xy[0]) * W / 100).toFixed(2) + ',' + (parseFloat(xy[1]) * H / 100).toFixed(2);
                }).join(' ');
                var cid = 'cxfb' + idx + '_' + Math.random().toString(36).slice(2, 8);
                var ring = 'M0 0H' + W + 'V' + H + 'H0Z M' + bw + ' ' + bw + 'V' + (H - bw) + 'H' + (W - bw) + 'V' + bw + 'Z';
                var holder = document.createElement('div');
                holder.setAttribute('data-corex-frame-ring', '1');
                holder.style.cssText = 'position:absolute;pointer-events:none;'
                    + 'left:' + frame.style.left + ';top:' + frame.style.top + ';'
                    + 'width:' + W + 'px;height:' + H + 'px;'
                    + 'z-index:' + (frame.style.zIndex || 1) + ';'
                    + (frame.style.transform ? 'transform:' + frame.style.transform + ';' : '')
                    + (frame.style.opacity ? 'opacity:' + frame.style.opacity + ';' : '');
                holder.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H
                    + '" style="display:block;"><defs><clipPath id="' + cid + '"><polygon points="' + scaled + '"/></clipPath></defs>'
                    + '<path d="' + ring + '" fill="' + esc(color) + '" fill-rule="evenodd" clip-path="url(#' + cid + ')"/></svg>';
                var prevColor = frame.style.borderColor;
                frame.style.borderColor = 'transparent';
                frame.parentNode.insertBefore(holder, frame.nextSibling);
                undo.push(function () {
                    frame.style.borderColor = prevColor;
                    if (holder.parentNode) holder.parentNode.removeChild(holder);
                });
            } catch (e) { /* leave this frame exactly as the browser draws it */ }
        });
        return function () { undo.forEach(function (fn) { fn(); }); };
    }

    /**
     * §B.9-2 — an Agent Image inside a polygon mask (triangle … chevron) is masked with `clip-path` on its frame, which
     * html2canvas ignores (the export came out as a plain rectangle). Returns that frame's polygon as fractions of the
     * frame box ([[x, y], …], 0-1) so the export can bake it into the pixels; null when there is no polygon clip.
     */
    function framePolygon(img, root) {
        for (var n = img.parentElement; n && n !== root; n = n.parentElement) {
            var cp = n.style && n.style.clipPath;
            if (!cp || cp.indexOf('polygon(') !== 0) continue;
            var pts = clipToPoints(cp);
            if (!pts) return null;
            return { frame: n, points: pts.split(' ').map(function (p) { var xy = p.split(','); return [parseFloat(xy[0]) / 100, parseFloat(xy[1]) / 100]; }) };
        }
        return null;
    }

    function _prepareImages(root) {
        var imgs = Array.prototype.slice.call(root.querySelectorAll('img'));
        var restores = [];
        var loadPromises = [];

        imgs.forEach(function (img) {
            var fitVal = (img.style.objectFit || '').toLowerCase();
            var poly = framePolygon(img, root);
            // §B.9-1 — a photo with an effect carries its colour matrix in data-fxm. html2canvas ignores CSS
            // filters, so the effect is baked into the same pre-cropped canvas the export already draws,
            // using the very numbers the live SVG filter uses.
            var fxm = img.getAttribute ? img.getAttribute('data-fxm') : null;
            var matrix = fxm ? fxm.split(' ').map(Number) : null;
            if (matrix && matrix.length !== 20) matrix = null;
            if (fitVal !== 'cover' && fitVal !== 'contain' && !matrix && !poly) return;

            var box = img.getBoundingClientRect();
            var w = Math.round(box.width), h = Math.round(box.height);
            var rect = (fitVal === 'cover' || fitVal === 'contain')
                ? objectFitRect(fitVal, w, h, img.naturalWidth, img.naturalHeight)
                : (w && h && img.naturalWidth ? { dx: 0, dy: 0, dw: w, dh: h } : null);   // 'fill' (or none) + effect: stretch, like the browser
            if (!rect) return;

            try {
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                var ctx = canvas.getContext('2d');
                if (poly) {
                    // The frame's clip-path polygon, in this image's own pixels (the image fills its frame).
                    var fb = poly.frame.getBoundingClientRect();
                    ctx.beginPath();
                    poly.points.forEach(function (pt, i) {
                        var px = pt[0] * fb.width - (box.left - fb.left), py = pt[1] * fb.height - (box.top - fb.top);
                        if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
                    });
                    ctx.closePath();
                    ctx.clip();
                }
                ctx.drawImage(img, rect.dx, rect.dy, rect.dw, rect.dh);
                if (matrix) {
                    var id = ctx.getImageData(0, 0, w, h);   // throws on a tainted canvas — caught below, image left as is
                    applyColorMatrix(id.data, matrix);
                    ctx.putImageData(id, 0, 0);
                }
                var dataUrl = canvas.toDataURL('image/png'); // throws on a tainted (cross-origin) canvas
                var originalSrc = img.src;
                var originalFilter = img.style.filter;
                img.src = dataUrl;
                if (matrix) img.style.filter = 'none';        // the effect is in the pixels now — never apply it twice
                restores.push(function () { img.src = originalSrc; if (matrix) img.style.filter = originalFilter; });
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
            if (!opts.placeholders && isAgentAvatarField(f) && SHAPE_POINTS[el.shapeType]) return emptyClipPhotoHtml(el);
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
        if (f === 'shape') {
            if (DECOR_SHAPES[el.shapeType]) return decorShapeHtml(el);
            if (SHAPE_POINTS[el.shapeType]) return clipShapeHtml(el);   // §B.9-2 — polygon as SVG, not clip-path (export-safe)
            return '<div style="' + shapeCss(el) + '"></div>';
        }
        if (f === 'gradient')    return '<div style="' + gradientCss(el) + '"></div>';

        if (f === 'line') {
            if (el.lineStyle === 'dashed' || el.lineStyle === 'dotted') return dashedLineHtml(el);
            return '<div style="width:100%;height:100%;display:flex;align-items:center;">'
                 + '<div style="' + lineCss(el) + '"></div></div>';
        }

        if (f === 'collage')       return collageHtml(el, prop, opts);
        if (f === 'ribbon')        return ribbonHtml(el);
        if (f === 'qr')            return qrHtml(el, prop, opts);
        if (f === 'feature_pills') return pillsHtml(el, prop, opts);

        if (f === 'logo') {
            var inner = prop.logo
                ? '<img src="' + esc(prop.logo) + '" class="js-ad-logo" style="max-height:100%;max-width:100%;object-fit:contain;object-position:left center;">'
                : '<div style="font-family:' + fontStack(el.fontFamily) + ';font-weight:900;line-height:1;'
                  + 'font-size:' + (el.fontSize || 28) + 'px;color:' + (el.color || '#ffffff') + ';">'
                  + esc(prop.agency_name || '') + '</div>';   // no logo: the agency's own name - never CoreX
            return '<div style="width:100%;height:100%;display:flex;align-items:center;padding:' + (el.padding || 0) + 'px;">'
                 + inner + '</div>';
        }

        if (f === 'watermark') {
            return '<div style="' + watermarkCss(el) + '">'
                 + esc(prop.watermark || el.text || prop.agency_name || '') + '</div>';
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
            if (isBlankElement(el, prop, opts)) return;  // §B.9-1 — a fact the listing does not have is absent, not blank
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

    /** Smallest an element may be drawn in the builder: 8px, but a QR code never below 120px (a phone must be able to scan it). */
    var QR_MIN_PX = 120;
    function minSize(el) { return el && el.field === 'qr' ? QR_MIN_PX : 8; }

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
            removeBackground: d.removeBackground || false,
            // ── AT-456 §B.9-1. Every default below equals "not set", so a saved template that lacks these
            // keys (all of them, before B.9-1) renders exactly as it always did. ──
            photoMask:     'none',          // image_1-5 / custom_image: none | rounded | circle | arch | blob
            photoEffect:   'none',          // images + collage: none | bw | duotone | warm | cool | brighter
            duoColor1:     '#0b2a4a',       // duotone shadows
            duoColor2:     '#f4d58d',       // duotone highlights
            lineStyle:     'solid',         // line: solid | dashed | dotted
            collagePreset: d.collagePreset || '3_hero_left',
            collageGap:    def(d.collageGap, 8),
            collageFirst:  d.collageFirst || 1,
            ribbonCorner:  d.ribbonCorner || 'tl',
            qrDark:        d.qrDark || '#000000',
            qrLight:       d.qrLight || '#ffffff',
            pillCount:     d.pillCount || 4,
            pillDirection: d.pillDirection || 'row',
            pillGap:       def(d.pillGap, 10),
            showLabel:     true             // listing facts: print the label ("Levy: …") before the value
        };
    }

    root.CoreXAd = {
        CANVAS_PRESETS: CANVAS_PRESETS,
        SHAPE_CLIPS: SHAPE_CLIPS,
        SHAPE_POINTS: SHAPE_POINTS,
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
        // AT-456 §B.9-1
        DECOR_SHAPES: DECOR_SHAPES,
        DECOR_SHAPE_LIST: DECOR_SHAPE_LIST,
        LINE_STYLES: LINE_STYLES,
        PHOTO_MASKS: PHOTO_MASKS,
        PHOTO_EFFECTS: PHOTO_EFFECTS,
        RIBBON_CORNERS: RIBBON_CORNERS,
        COLLAGE_PRESETS: COLLAGE_PRESETS,
        MASKABLE_FIELDS: MASKABLE_FIELDS,
        EFFECT_FIELDS: EFFECT_FIELDS,
        FACT_FIELDS: FACT_FIELDS,
        FACT_LABELS: FACT_LABELS,

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
        requestFont: requestFont,
        fontsReady: fontsReady,
        safeColor: safeColor,
        maskCss: maskCss,
        effectMatrix: effectMatrix,
        applyColorMatrix: applyColorMatrix,
        collageCount: collageCount,
        collageEffectivePreset: collageEffectivePreset,
        collageCells: collageCells,
        collagePhotos: collagePhotos,
        pillsFor: pillsFor,
        shapeSwatchHtml: shapeSwatchHtml,
        isBlankElement: isBlankElement,

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
        makeElement: makeElement,
        minSize: minSize
    };
})(window);
