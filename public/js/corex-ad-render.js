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
        size_m2:          { w: 120, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '450 m²' },
        reference:        { w: 160, h: 28,  fontSize: 12, fontWeight: '600', color: 'rgba(255,255,255,0.55)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.08, padding: 4, preview: 'REF 12345' },
        address:          { w: 360, h: 32,  fontSize: 13, fontWeight: '500', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '12 Marine Drive' },
        status_badge:     { w: 200, h: 40,  fontSize: 16, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 8, bgColor: '#e63946', bgOpacity: 1, borderRadius: 6, preview: 'FOR SALE' },
        agent_name:       { w: 280, h: 40,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6 },
        agent_email:      { w: 300, h: 30,  fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6 },
        agent_phone:      { w: 220, h: 30,  fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
        agent_designation:{ w: 260, h: 28,  fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6 },
        agent_avatar:     { w: 80,  h: 80,  objectFit: 'cover', borderRadius: 50 },
        // Agent 2 — the co-listing agent, for building dual-agent templates.
        agent_2_name:        { w: 280, h: 40, fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6, preview: 'CO-AGENT NAME' },
        agent_2_email:       { w: 300, h: 30, fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'co.agent@agency.co.za' },
        agent_2_phone:       { w: 220, h: 30, fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
        agent_2_designation: { w: 260, h: 28, fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6, preview: 'PROPERTY PRACTITIONER' },
        agent_2_avatar:      { w: 80,  h: 80, objectFit: 'cover', borderRadius: 50 },
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
        { type: 'size_m2',       group: 'property', label: 'Size m²',      iconBg: '#065f46' },
        { type: 'reference',     group: 'property', label: 'Reference',    iconBg: '#475569' },
        { type: 'address',       group: 'property', label: 'Address',      iconBg: '#475569' },
        { type: 'status_badge',  group: 'property', label: 'Status Badge', iconBg: '#e63946' },
        { type: 'agent_name',        group: 'agent', label: 'Agent 1 · Name',        iconBg: '#7c3aed' },
        { type: 'agent_email',       group: 'agent', label: 'Agent 1 · Email',       iconBg: '#7c3aed' },
        { type: 'agent_phone',       group: 'agent', label: 'Agent 1 · Phone',       iconBg: '#7c3aed' },
        { type: 'agent_designation', group: 'agent', label: 'Agent 1 · Designation', iconBg: '#7c3aed' },
        { type: 'agent_avatar',      group: 'agent', label: 'Agent 1 · Avatar',      iconBg: '#7c3aed' },
        { type: 'agent_2_name',        group: 'agent', label: 'Agent 2 · Name',        iconBg: '#9333ea' },
        { type: 'agent_2_email',       group: 'agent', label: 'Agent 2 · Email',       iconBg: '#9333ea' },
        { type: 'agent_2_phone',       group: 'agent', label: 'Agent 2 · Phone',       iconBg: '#9333ea' },
        { type: 'agent_2_designation', group: 'agent', label: 'Agent 2 · Designation', iconBg: '#9333ea' },
        { type: 'agent_2_avatar',      group: 'agent', label: 'Agent 2 · Avatar',      iconBg: '#9333ea' },
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
        // A shape owns its geometry on the INNER node — the frame must stay square
        // or it rounds a rectangle's corners and draws a box around a clip shape.
        var radius = el.field === 'shape' ? 0 : (el.borderRadius || 0);
        var s = 'position:absolute;'
              + 'left:' + el.x + 'px;top:' + el.y + 'px;'
              + 'width:' + el.w + 'px;height:' + el.h + 'px;'
              + 'z-index:' + (el.zIndex || 1) + ';'
              + 'overflow:hidden;'
              + 'border-radius:' + radius + 'px;';

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

        var v = prop[f];
        if (v !== undefined && v !== null && v !== '') return v;

        if (isAgent2(f) && !opts.placeholders) return '';
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

        return '<img src="' + esc(src) + '" ' + attrs + '>';
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

        // Text field.
        return '<div style="' + textStyle(el) + '">'
             + '<span style="width:100%;">' + esc(textValue(el, prop, opts)) + '</span></div>';
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
            shadowOpacity: 0.45
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

        esc: esc,
        hexToRgba: hexToRgba,
        isImageField: isImageField,
        isTextField: isTextField,
        isAgent2: isAgent2,
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
        imageSrc: imageSrc,
        baseImageSrc: baseImageSrc,
        canvasBackground: canvasBackground,
        canvasBgSolid: canvasBgSolid,

        contentHtml: contentHtml,
        renderLayout: renderLayout,
        makeElement: makeElement
    };
})(window);
